import { Controller } from '@hotwired/stimulus';
import { renderField } from '../lib/block_fields.js';
import { announce, createDragList, focusHandle, handle, hideToast, t, toast, watchFileDrops } from '../lib/dnd.js';
import { el, format } from '../lib/dom.js';
import { History, moveStep } from '../lib/history.js';
import { checkFiles, uploadFiles } from '../lib/upload.js';
import { icon } from '../lib/icons.js';

const PREVIEW_DELAY = 350;
const AUTOSAVE_DELAY = 2500;
/** Column widths are counted in parts of this many (see App\Block\Type\ColumnsBlock). */
const GRID = 12;

/** Several steps that are taken back and made again as one. */
function compose(steps) {
    const taken = steps.filter(Boolean);

    return {
        undo: () => [...taken].reverse().forEach((step) => step.undo()),
        redo: () => taken.forEach((step) => step.redo()),
    };
}

/*
 * The block editor.
 *
 * The content is a plain array of blocks ({ type, data }) kept in this.blocks.
 * Forms are generated from the field definitions the server sends for every
 * block type (see App\Block\Field and lib/block_fields.js), so new block
 * types need no JavaScript.
 *
 * Structural changes (add, move, duplicate, delete) re-render the list.
 * Typing into a field only updates the data, which keeps the focus in place.
 *
 * Blocks are arranged with the shared drag-and-drop layer (lib/dnd.js): every
 * list of blocks is a drag list of the group "blocks", so blocks travel
 * between the page and columns. Structural changes can be undone with Ctrl+Z.
 *
 * Columns are made by dragging: a block dropped on the left or right edge of
 * a block on the page lands next to it, and a column that loses its last
 * block goes away again. The divider between two columns changes their widths.
 */
export default class extends Controller {
    static targets = ['form', 'title', 'input', 'list', 'palette', 'outline', 'outlineBody', 'preview', 'status', 'restore', 'restoreText', 'tab', 'panel', 'viewButton'];
    static values = { config: Object, unsaved: Boolean };

    connect() {
        this.config = this.configValue;
        this.i18n = this.config.i18n;
        this.types = new Map(this.config.types.map((type) => [type.type, type]));
        // PHP encodes an empty map as [], so normalize to an object.
        this.media = Array.isArray(this.config.media) ? {} : { ...this.config.media };
        this.blocks = structuredClone(this.config.blocks);
        // Problems reported by the server, keyed by "<block path>:<field>". PHP encodes an empty map as [].
        this.errors = Array.isArray(this.config.errors) ? {} : { ...this.config.errors };
        this.lists = new WeakMap();
        // The drop areas at the edges of blocks: element => { block, side }.
        this.edges = new WeakMap();
        // The block type that puts blocks next to each other, if the website has one.
        this.columnsType = this.config.types.find((type) => type.layout && this.columnFields(type).length > 1) ?? null;
        this.sortables = [];
        this.history = new History();
        this.dirty = this.unsavedValue;
        this.submitting = false;
        this.previewRequest = 0;

        if (this.config.autosave) {
            this.restoreAutosave(this.config.autosave);
        }

        this.restoreOutline();
        this.render();
        this.renderPalette();
        this.refreshPreview();
        this.setStatus(this.dirty ? 'unsaved' : 'saved');
        this.openPanelWithErrors();

        this.onBeforeUnload = (event) => {
            if (this.dirty && !this.submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.onDocumentClick = (event) => {
            if (this.menu && !this.menu.contains(event.target) && !event.target.closest('[data-opens-menu], [data-block-action="share"]')) {
                this.closeMenu();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.menu) {
                const cancel = this.menuCancel;
                this.closeMenu();
                cancel?.();
            }
        };
        // A block that was dragged, carried with the keyboard or moved through the menu.
        this.onDndMove = (event) => this.blockMoved(event.detail);
        this.element.addEventListener('dnd:move', this.onDndMove);
        // A block type dragged out of the palette.
        this.onDndInsert = (event) => this.paletteDropped(event.detail);
        this.element.addEventListener('dnd:insert', this.onDndInsert);
        // Image files dragged in from the desktop.
        this.stopWatchingFiles = watchFileDrops(this.element, {
            onOver: (over, event) => this.filesHover(over, event),
            onFiles: (files, event) => this.filesDropped(files, event),
        });
        this.unbindHistoryKeys = this.history.bindKeys(document, {
            onUndo: () => announce(t('undone')),
            onRedo: () => announce(t('redone')),
        });

        window.addEventListener('beforeunload', this.onBeforeUnload);
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        this.element.removeEventListener('dnd:move', this.onDndMove);
        this.element.removeEventListener('dnd:insert', this.onDndInsert);
        this.stopWatchingFiles();
        this.unbindHistoryKeys();
        hideToast();
        clearTimeout(this.previewTimer);
        clearTimeout(this.autosaveTimer);
        this.destroySortables();
    }

    // ----- Actions wired up in the template -------------------------------

    /** Any change to a regular form field (title, address, status, ...). */
    fieldChanged(event) {
        if (!this.listTarget.contains(event.target) && !event.target.hasAttribute('data-editor-ignore')) {
            this.changed();
        }
    }

    /** Pressing Enter in a text field must not submit the whole form by accident. */
    preventImplicitSubmit(event) {
        if (event.key === 'Enter' && event.target.matches('input:not([type="submit"]):not([type="button"])')) {
            event.preventDefault();
        }
    }

    submit() {
        this.inputTarget.value = JSON.stringify(this.blocks);
        this.submitting = true;
        clearTimeout(this.autosaveTimer);
    }

    showTab(event) {
        this.selectTab(event.currentTarget.dataset.tab);
    }

    showView(event) {
        const view = event.currentTarget.dataset.view;
        this.element.classList.toggle('editor--show-preview', view === 'preview');
        this.viewButtonTargets.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.view === view)));
    }

    async discardAutosave() {
        await this.post(this.config.urls.discard, {});
        this.dirty = false;
        window.location.reload();
    }

    // ----- State ----------------------------------------------------------

    changed() {
        this.dirty = true;
        this.setStatus('unsaved');

        clearTimeout(this.previewTimer);
        this.previewTimer = setTimeout(() => {
            this.refreshPreview();
            this.refreshOutlineTexts();
        }, PREVIEW_DELAY);

        clearTimeout(this.autosaveTimer);
        this.autosaveTimer = setTimeout(() => this.autosave(), AUTOSAVE_DELAY);
    }

    setStatus(state, parameters = {}) {
        this.statusTarget.textContent = format(this.i18n[`status_${state}`], parameters);
        this.statusTarget.dataset.state = state;
    }

    selectTab(name) {
        this.tabTargets.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.tab === name)));
        this.panelTargets.forEach((panel) => { panel.hidden = panel.dataset.panel !== name; });
    }

    /** A validation error in a hidden panel would be invisible: switch to that panel. */
    openPanelWithErrors() {
        const panel = this.panelTargets.find((candidate) => candidate.querySelector('.field--error'));
        if (panel) {
            this.selectTab(panel.dataset.panel);
        }
    }

    newBlock(typeName) {
        const data = {};
        for (const field of this.types.get(typeName).fields) {
            data[field.name] = structuredClone(field.default);
        }

        return { type: typeName, data };
    }

    // ----- Autosave -------------------------------------------------------

    formFields() {
        const fields = {};
        for (const [name, value] of new FormData(this.formTarget).entries()) {
            const ignored = this.formTarget.elements[name]?.hasAttribute?.('data-editor-ignore');
            if (typeof value === 'string' && !name.includes('_token') && name !== this.inputTarget.name && !ignored) {
                fields[name] = value;
            }
        }

        return fields;
    }

    async autosave() {
        // Some uses of the editor (shared blocks) have no autosave.
        if (this.submitting || !this.dirty || !this.config.urls.autosave) {
            return;
        }

        this.setStatus('saving');
        try {
            const response = await this.post(this.config.urls.autosave, { fields: this.formFields(), blocks: this.blocks });
            if (!response.ok) {
                throw new Error(`Autosave failed with status ${response.status}`);
            }
            const { savedAt } = await response.json();
            this.setStatus('autosaved', { time: this.formatTime(savedAt) });
        } catch {
            this.setStatus('error');
        }
    }

    /** Puts back what was typed before the browser was closed without saving. */
    restoreAutosave(autosave) {
        const fields = autosave.fields ?? {};

        for (const input of this.formTarget.elements) {
            if (!input.name || input.name.includes('_token') || input === this.inputTarget || input.type === 'submit' || input.type === 'button') {
                continue;
            }
            if (input.type === 'checkbox') {
                input.checked = input.name in fields;
            } else if (input.type === 'radio') {
                if (input.name in fields) {
                    input.checked = input.value === fields[input.name];
                }
            } else if (input.name in fields) {
                input.value = fields[input.name];
            }
        }

        this.blocks = structuredClone(autosave.blocks ?? []);
        this.dirty = true;
        this.restoreTextTarget.textContent = format(this.i18n.restore_message, { time: this.formatTime(autosave.savedAt, true) });
        this.restoreTarget.hidden = false;
        // Custom fields (such as the cover image) refresh themselves when they hear this event.
        this.formTarget.dispatchEvent(new CustomEvent('editor:restored', { bubbles: true }));
    }

    formatTime(isoDate, withDate = false) {
        const options = withDate ? { dateStyle: 'medium', timeStyle: 'short' } : { timeStyle: 'short' };

        return new Intl.DateTimeFormat(this.config.locale, options).format(new Date(isoDate));
    }

    post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
    }

    // ----- Live preview ---------------------------------------------------

    async refreshPreview() {
        const request = ++this.previewRequest;

        try {
            const response = await this.post(this.config.urls.preview, {
                kind: this.config.kind,
                title: this.titleTarget.value,
                blocks: this.blocks,
            });
            const html = await response.text();
            if (request !== this.previewRequest || !response.ok) {
                return;
            }

            const frame = this.previewTarget;
            const frameDocument = this.previewLoaded ? frame.contentDocument : null;
            if (frameDocument?.body) {
                // Swap only the body: no flicker, and the scroll position stays where it is.
                frameDocument.body.innerHTML = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
            } else {
                frame.srcdoc = html;
                frame.addEventListener('load', () => { this.previewLoaded = true; }, { once: true });
            }
        } catch {
            // The preview is a convenience: a failed refresh must never get in the way of editing.
        }
    }

    // ----- Rendering ------------------------------------------------------

    render() {
        this.closeMenu();
        this.destroySortables();
        this.renderList(this.listTarget, this.blocks, false, '');
        this.renderOutline();

        if (this.focusRequest) {
            this.focusRequest();
            this.focusRequest = null;
        }
    }

    destroySortables() {
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];
    }

    renderList(container, blocks, nested, pathPrefix) {
        const items = el('div', {
            class: 'blocks__items',
            'aria-label': nested ? this.i18n.list_column : this.i18n.list_page,
            'data-dnd-empty-hint': this.i18n.drop_here,
        });
        this.lists.set(items, blocks);
        blocks.forEach((block, index) => items.append(this.renderBlock(block, blocks, index, nested, `${pathPrefix}${index}`)));

        const addButton = el('button', {
            type: 'button',
            class: nested ? 'blocks__add blocks__add--nested' : 'blocks__add',
            'data-opens-menu': true,
            'aria-haspopup': 'true',
            onclick: () => this.toggleMenu(addRow, blocks, blocks.length, nested),
        }, `+ ${this.i18n.add_block}`);
        const addRow = el('div', { class: 'blocks__add-row' }, addButton);

        container.replaceChildren(items, addRow);
        if (blocks.length === 0) {
            container.prepend(el('p', { class: 'blocks__empty', text: nested ? this.i18n.empty_column : this.i18n.empty }));
        }

        this.sortables.push(createDragList(items, {
            // One shared group lets blocks travel between the page and columns.
            // Layout blocks (columns) are not allowed inside another column.
            group: 'blocks',
            accept: nested ? ':not([data-layout="true"])' : null,
        }));
    }

    // ----- Outline --------------------------------------------------------

    /**
     * The outline: every block as one line, columns with their blocks below them. A click jumps to
     * the block; dragging a line moves the block, exactly like dragging the block itself.
     */
    renderOutline() {
        if (!this.hasOutlineBodyTarget) {
            return;
        }

        const build = (blocks, nested, label) => {
            const list = el('ul', { class: 'outline__list', 'aria-label': label, 'data-dnd-empty-hint': this.i18n.drop_here });
            // The lines stand for the same lists of blocks as the page does, so a move in the outline is a move of the block.
            this.lists.set(list, blocks);
            for (const block of blocks) {
                const type = this.types.get(block.type);
                const item = el('li', { class: 'outline__item', 'data-dnd-item': true, 'data-dnd-label': type.label, 'data-layout': String(type.layout) },
                    el('div', { class: 'outline__row' },
                        handle(type.label),
                        el('button', { type: 'button', class: 'outline__jump', title: this.i18n.outline_jump, onclick: () => this.jumpTo(block) },
                            el('span', { class: 'block-menu__icon', 'aria-hidden': 'true', text: type.icon }),
                            el('span', { class: 'outline__name', text: type.label }),
                            el('span', { class: 'outline__excerpt', text: this.excerpt(block, type) }),
                        ),
                    ),
                );
                item.block = block;
                if (type.layout) {
                    for (const field of this.activeColumns(block, type)) {
                        item.append(el('div', { class: 'outline__column' },
                            el('span', { class: 'outline__column-label', text: field.label }),
                            build(block.data[field.name], true, field.label),
                        ));
                    }
                }
                list.append(item);
            }
            this.sortables.push(createDragList(list, { group: 'outline', accept: nested ? ':not([data-layout="true"])' : null }));

            return list;
        };

        this.outlineBodyTarget.replaceChildren(build(this.blocks, false, this.i18n.outline_list));
    }

    /** A few words of what a block says, so that ten text blocks can be told apart. */
    excerpt(block, type) {
        for (const field of type.fields) {
            const value = block.data[field.name];
            if (['text', 'textarea', 'richtext'].includes(field.type) && typeof value === 'string' && value.trim() !== '') {
                const text = field.type === 'richtext' ? new DOMParser().parseFromString(value, 'text/html').body.textContent : value;

                return text.replace(/\s+/g, ' ').trim().slice(0, 60);
            }
        }

        return '';
    }

    /** Typing changes what a block says; the outline follows without being rebuilt. */
    refreshOutlineTexts() {
        if (!this.hasOutlineBodyTarget) {
            return;
        }

        this.outlineBodyTarget.querySelectorAll('.outline__item').forEach((item) => {
            const text = item.querySelector(':scope > .outline__row .outline__excerpt');
            const excerpt = this.excerpt(item.block, this.types.get(item.block.type));
            if (text.textContent !== excerpt) {
                text.textContent = excerpt;
            }
        });
    }

    /** Scrolls to a block of the page and puts the focus on its handle. */
    jumpTo(block) {
        const node = [...this.listTarget.querySelectorAll('.block')].find((candidate) => candidate.block === block);
        if (!node) {
            return;
        }

        node.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        node.querySelector('[data-dnd-handle]')?.focus({ preventScroll: true });
        node.classList.add('block--found');
        setTimeout(() => node.classList.remove('block--found'), 1200);
    }

    /** Whether the outline is open is remembered in the browser. */
    outlineToggled() {
        try {
            localStorage.setItem('symfocms-outline', this.outlineTarget.open ? 'open' : 'closed');
        } catch {
            // Without storage the outline simply starts closed next time.
        }
    }

    restoreOutline() {
        if (!this.hasOutlineTarget) {
            return;
        }
        try {
            this.outlineTarget.open = localStorage.getItem('symfocms-outline') === 'open';
        } catch {
            // See outlineToggled().
        }
    }

    // ----- Palette -------------------------------------------------------

    /** The block types as things to drag into the page (see palette_controller.js). */
    renderPalette() {
        if (!this.hasPaletteTarget) {
            return;
        }

        this.paletteTarget.replaceChildren(...this.config.types.map((type) => el('li', {
            class: 'palette__item',
            'data-dnd-item': true,
            'data-dnd-label': type.label,
            'data-palette-type': type.type,
            // Lets columns refuse layout blocks, exactly like for blocks that are moved.
            'data-layout': String(type.layout),
        }, el('button', {
            type: 'button',
            class: 'palette__button',
            'data-dnd-handle': true,
            title: format(this.i18n.palette_add, { type: type.label }),
        }, el('span', { class: 'block-menu__icon', 'aria-hidden': 'true', text: type.icon }), el('span', { text: type.label })))));
    }

    /** Clicking a block type (or pressing Enter on it) adds it at the end of the page. */
    paletteAdd(event) {
        this.insert(this.blocks, this.blocks.length, event.detail.type);
        announce(format(this.i18n.palette_added, { type: this.types.get(event.detail.type).label }));
    }

    /** A block type was dropped somewhere in the page or in a column. */
    paletteDropped(detail) {
        const list = this.lists.get(detail.to);
        const edge = this.edges.get(detail.to);
        const typeName = detail.source.dataset.paletteType;
        if (edge && this.types.has(typeName)) {
            const block = this.newBlock(typeName);
            this.record(this.putBeside(edge.block, block, edge.side));
            this.focusNewBlock(block);
            this.structureChanged();

            return;
        }
        if (!list || !this.types.has(typeName)) {
            return;
        }

        this.insert(list, Math.min(detail.index, list.length), typeName);
    }

    // ----- Image files from the desktop -----------------------------------

    /**
     * Where dropped files would go: the list of blocks under the pointer and the position in it.
     */
    dropTarget(event) {
        const under = document.elementFromPoint(event.clientX, event.clientY);
        const items = under?.closest?.('.blocks__items') ?? this.listTarget.querySelector(':scope > .blocks__items');
        if (!items || !this.element.contains(items)) {
            return null;
        }

        const blocks = [...items.children].filter((child) => child.matches('.block'));
        const before = blocks.find((block) => {
            const bounds = block.getBoundingClientRect();

            return event.clientY < bounds.top + bounds.height / 2;
        }) ?? null;

        return { items, before, index: before ? blocks.indexOf(before) : blocks.length };
    }

    /** While files hover, a line shows where the new block will appear. */
    filesHover(over, event) {
        this.element.classList.toggle('editor--files-over', over);
        const target = over ? this.dropTarget(event) : null;
        if (!target) {
            this.dropLine?.remove();

            return;
        }

        this.dropLine ??= el('div', { class: 'blocks__drop-line', 'aria-hidden': 'true' }, el('span', { text: this.i18n.drop_images }));
        if (this.dropLine.parentElement !== target.items || this.dropLine.nextElementSibling !== target.before) {
            target.items.insertBefore(this.dropLine, target.before);
        }
    }

    /**
     * One image becomes an image block, several become a gallery - at the place they were dropped.
     */
    async filesDropped(files, event) {
        const target = this.dropTarget(event);
        this.dropLine?.remove();
        this.element.classList.remove('editor--files-over');
        const list = target ? this.lists.get(target.items) : null;
        if (!list) {
            return;
        }

        const { accepted, rejected } = checkFiles(files, {
            maxBytes: this.config.upload.maxBytes,
            types: this.config.upload.types,
            messages: { too_large: t('file_too_large'), wrong_type: t('file_wrong_type') },
        });
        const errors = rejected.map(({ error }) => error);

        // The progress is shown right where the block will be.
        const label = el('span');
        const bar = el('progress', { max: '100', value: '0' });
        const placeholder = el('div', { class: 'block block--uploading', role: 'status' }, label, bar);
        if (accepted.length > 0) {
            target.items.insertBefore(placeholder, target.before);
        }

        const uploaded = await uploadFiles(accepted, {
            url: this.config.urls.mediaUpload,
            csrf: this.config.csrf,
            fallbackError: t('upload_failed'),
            onProgress: ({ file, index, total, fraction }) => {
                label.textContent = t('uploading', { name: file.name, current: String(index + 1), total: String(total) });
                bar.value = Math.round(((index + fraction) / total) * 100);
            },
            onResult: ({ file, error }) => {
                if (error) {
                    errors.push(`${file.name}: ${error}`);
                }
            },
        });
        placeholder.remove();

        if (uploaded.length > 0) {
            uploaded.forEach((media) => { this.media[media.id] = media; });
            const block = uploaded.length === 1 ? this.newBlock('image') : this.newBlock('gallery');
            if (uploaded.length === 1) {
                block.data.image = uploaded[0].id;
            } else {
                block.data.images = uploaded.map((media) => media.id);
            }

            // The list may have changed while the files travelled: stay inside it.
            this.addBlock(list, Math.min(target.index, list.length), block);
            this.focusNewBlock(block);
            this.structureChanged();
            announce(this.i18n[uploaded.length === 1 ? 'dropped_image' : 'dropped_gallery']);
        }

        if (errors.length > 0) {
            toast(errors.join(' '), { type: 'error', duration: 12000 });
            announce(errors.join(' '));
        }
    }

    /** The drag-and-drop layer has moved a block on screen: the data follows. */
    blockMoved(detail) {
        const from = this.lists.get(detail.from);
        const to = this.lists.get(detail.to);
        const edge = this.edges.get(detail.to);
        if (!from || (!to && !edge)) {
            return;
        }

        const steps = [];
        let moved;
        if (edge) {
            // Dropped on the edge of a block: it becomes a column next to that block.
            const taken = this.takeOut(from, detail.oldIndex);
            moved = taken.block;
            steps.push(taken, this.putBeside(edge.block, moved, edge.side));
        } else {
            [moved] = from.splice(detail.oldIndex, 1);
            to.splice(detail.newIndex, 0, moved);
            steps.push(moveStep(from, detail.oldIndex, to, detail.newIndex));
        }
        steps.push(this.leaveColumn(from, to ?? null));
        this.record(compose(steps));
        if (detail.cause !== 'pointer') {
            // The focus stays where the block was moved: in the outline or in the page.
            this.keepFocusOnHandle(moved, this.hasOutlineBodyTarget && this.outlineBodyTarget.contains(detail.to) ? this.outlineBodyTarget : this.listTarget);
        }
        // Let the layer finish its own cleanup before the list is rebuilt.
        setTimeout(() => this.structureChanged());
    }

    /** After the next render, focus the handle of the block that was moved. */
    keepFocusOnHandle(block, root = this.listTarget) {
        this.focusRequest = () => focusHandle([...root.querySelectorAll('[data-dnd-item]')].find((candidate) => candidate.block === block));
    }

    structureChanged() {
        this.errors = {};
        this.render();
        this.changed();
    }

    /** Remembers a structural change so that Ctrl+Z takes it back and Ctrl+Shift+Z makes it again. */
    record(step) {
        this.history.record({
            undo: () => {
                step.undo();
                this.structureChanged();
            },
            redo: () => {
                step.redo();
                this.structureChanged();
            },
        });
    }

    renderBlock(block, list, index, nested, path) {
        const type = this.types.get(block.type);
        const actionButton = (action, label, symbol, onClick, disabled = false) => el('button', {
            type: 'button',
            class: action === 'delete' ? 'icon-button icon-button--danger' : 'icon-button',
            title: label,
            'aria-label': label,
            'data-block-action': action,
            disabled,
            onclick: onClick,
        }, symbol);

        const node = el('section', { class: 'block', 'data-layout': String(type.layout), 'aria-label': type.label, 'data-dnd-item': true, 'data-dnd-label': type.label });

        const header = el('header', { class: 'block__header' },
            handle(type.label),
            el('span', { class: 'block__icon', 'aria-hidden': 'true', text: type.icon }),
            el('span', { class: 'block__type', text: type.label }),
            el('span', { class: 'block__actions' },
                el('button', {
                    type: 'button',
                    class: 'icon-button',
                    title: this.i18n.add_block_below,
                    'aria-label': this.i18n.add_block_below,
                    'aria-haspopup': 'true',
                    'data-opens-menu': true,
                    onclick: () => this.toggleMenu(node, list, index + 1, nested),
                }, icon('plus')),
                actionButton('duplicate', this.i18n.duplicate, icon('copy'), () => this.duplicate(list, index)),
                // Only for people who may manage shared blocks, and not for a block that already is one.
                this.config.urls.sharedCreate && !type.reference
                    ? actionButton('share', this.i18n.share, icon('share'), () => this.askSharedBlockName(node, list, index))
                    : null,
                actionButton('delete', this.i18n.delete, icon('trash'), () => this.remove(list, index)),
            ),
        );

        const body = el('div', { class: 'block__body' });
        if (type.layout) {
            this.renderColumns(body, block, type, path);
        } else {
            for (const field of type.fields) {
                body.append(renderField(this, block, field, path));
            }
        }
        if (type.fields.length === 0) {
            body.hidden = true;
        }

        node.append(header, body);
        node.block = block;
        if (!nested) {
            this.addEdges(node, block, type);
        }

        return node;
    }

    // ----- Columns --------------------------------------------------------

    /** The fields of a layout block that hold blocks: its columns, from left to right. */
    columnFields(type) {
        return type.fields.filter((field) => field.type === 'blocks');
    }

    /** The columns that are shown: the outer two always, the ones in between only while they hold blocks. */
    activeColumns(block, type) {
        const fields = this.columnFields(type);
        fields.forEach((field) => {
            if (!Array.isArray(block.data[field.name])) {
                block.data[field.name] = [];
            }
        });

        return fields.filter((field, index) => index === 0 || index === fields.length - 1 || block.data[field.name].length > 0);
    }

    /** The ways the width can be shared, as the server accepts them ("4-8"); empty for layouts without widths. */
    widthChoices(type) {
        return new Set((type.fields.find((field) => field.name === 'widths')?.options ?? []).map((option) => option.value));
    }

    /** The share of every column in parts of GRID. Without a stored choice that fits, all are equally wide. */
    columnWidths(block, type, count) {
        const stored = String(block.data.widths ?? '');
        if (stored.split('-').length === count && this.widthChoices(type).has(stored)) {
            return stored.split('-').map(Number);
        }

        return Array(count).fill(GRID / count);
    }

    widthsText(widths, index) {
        const percent = (parts) => String(Math.round((parts / GRID) * 100));

        return format(this.i18n.column_widths, { left: percent(widths[index]), right: percent(widths[index + 1]) });
    }

    /** Shows the widths in the editor and tells the dividers what they stand at. */
    applyWidths(grid, widths) {
        grid.style.setProperty('--columns', widths.map((parts) => `minmax(0, ${parts}fr)`).join(' auto '));
        grid.querySelectorAll(':scope > .columns__divider').forEach((divider, index) => {
            divider.setAttribute('aria-valuenow', String(Math.round((widths[index] / GRID) * 100)));
            divider.setAttribute('aria-valuetext', this.widthsText(widths, index));
            divider.dataset.widths = this.widthsText(widths, index);
        });
    }

    renderColumns(body, block, type, path) {
        for (const field of type.fields) {
            if (field.type !== 'blocks' && field.name !== 'widths') {
                body.append(renderField(this, block, field, path));
            }
        }

        const active = this.activeColumns(block, type);
        const resizable = this.widthChoices(type).size > 0;
        const grid = el('div', { class: 'columns' });
        active.forEach((field, index) => {
            if (index > 0) {
                grid.append(resizable ? this.renderDivider(grid, block, type, index - 1, active.length) : el('div', { class: 'columns__gap' }));
            }
            grid.append(renderField(this, block, field, path));
        });
        this.applyWidths(grid, this.columnWidths(block, type, active.length));
        body.append(grid);
    }

    /**
     * The line between two columns. Dragging it (or the left and right arrow keys) moves width from
     * one column to the other, in steps of a twelfth; a double click makes all columns equally wide.
     */
    renderDivider(grid, block, type, index, count) {
        const choices = this.widthChoices(type);
        const divider = el('div', {
            class: 'columns__divider',
            role: 'separator',
            tabindex: '0',
            'aria-orientation': 'vertical',
            'aria-label': this.i18n.column_divider,
            'aria-valuemin': '0',
            'aria-valuemax': '100',
            title: this.i18n.column_divider_hint,
        });

        // Moves "delta" parts from the column on the right to the one on the left, as far as the choices allow.
        const shifted = (base, delta) => {
            for (let step = delta; step !== 0; step -= Math.sign(step)) {
                const next = [...base];
                next[index] += step;
                next[index + 1] -= step;
                if (choices.has(next.join('-'))) {
                    return next;
                }
            }

            return base;
        };
        const store = (widths) => {
            const before = block.data.widths ?? '';
            const after = widths.join('-');
            if (after === (before === '' ? this.columnWidths(block, type, count).join('-') : before)) {
                return;
            }

            block.data.widths = after;
            this.record({
                undo: () => { block.data.widths = before; },
                redo: () => { block.data.widths = after; },
            });
            this.changed();
            announce(this.widthsText(widths, index));
        };

        divider.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'mouse' && event.button !== 0) {
                return;
            }

            event.preventDefault();
            divider.focus({ preventScroll: true });
            const start = this.columnWidths(block, type, count);
            const unit = grid.getBoundingClientRect().width / GRID;
            let widths = start;

            const move = (moveEvent) => {
                widths = shifted(start, Math.round((moveEvent.clientX - event.clientX) / unit));
                this.applyWidths(grid, widths);
            };
            const finish = () => {
                divider.removeEventListener('pointermove', move);
                divider.removeEventListener('pointerup', finish);
                divider.removeEventListener('pointercancel', finish);
                divider.classList.remove('is-dragging');
                store(widths);
            };

            divider.setPointerCapture?.(event.pointerId);
            divider.classList.add('is-dragging');
            divider.addEventListener('pointermove', move);
            divider.addEventListener('pointerup', finish);
            divider.addEventListener('pointercancel', finish);
        });

        divider.addEventListener('keydown', (event) => {
            const delta = { ArrowLeft: -1, ArrowRight: 1, Home: -GRID, End: GRID }[event.key];
            if (delta === undefined) {
                return;
            }

            event.preventDefault();
            const widths = shifted(this.columnWidths(block, type, count), delta);
            this.applyWidths(grid, widths);
            store(widths);
        });

        divider.addEventListener('dblclick', () => {
            const widths = Array(count).fill(GRID / count);
            this.applyWidths(grid, widths);
            store(widths);
        });

        return divider;
    }

    /**
     * Drop areas at the left and right edge of a block on the page. What is dropped there
     * becomes a column next to the block - as long as there is room for another column.
     */
    addEdges(node, block, type) {
        if (!this.columnsType || (type.layout && type !== this.columnsType)) {
            return;
        }
        if (type.layout && this.activeColumns(block, type).length >= this.columnFields(type).length) {
            return;
        }

        for (const side of ['left', 'right']) {
            const edge = el('div', {
                class: `block__edge block__edge--${side}`,
                'aria-label': format(this.i18n[`edge_${side}`], { block: type.label }),
                'data-dnd-empty-hint': this.i18n.edge_hint,
            });
            this.edges.set(edge, { block, side });
            node.append(edge);
            // Columns do not hold other columns.
            this.sortables.push(createDragList(edge, { group: 'blocks', accept: ':not([data-layout="true"])', side, emptyReach: 6 }));
        }
    }

    /** The layout block on the page that holds the given list as one of its columns. */
    ownerOf(list) {
        return this.blocks.find((block) => {
            const type = this.types.get(block.type);

            return type?.layout && this.columnFields(type).some((field) => block.data[field.name] === list);
        }) ?? null;
    }

    /** Takes a block out of its list. */
    takeOut(list, index) {
        const [block] = list.splice(index, 1);

        return {
            block,
            undo: () => list.splice(Math.min(index, list.length), 0, block),
            redo: () => list.splice(list.indexOf(block), 1),
        };
    }

    /**
     * Gives a layout block the given columns, from left to right, with equal widths.
     * The lists themselves are kept, so earlier steps in the history still find them.
     */
    arrangeColumns(block, columns) {
        const fields = this.columnFields(this.types.get(block.type));
        const apply = (state) => {
            fields.forEach((field, index) => { block.data[field.name] = state.columns[index]; });
            if ('widths' in block.data) {
                block.data.widths = state.widths;
            }
        };
        const before = { columns: fields.map((field) => block.data[field.name]), widths: block.data.widths };
        const after = { columns: fields.map(() => []), widths: '' };
        after.columns[0] = columns[0];
        after.columns[fields.length - 1] = columns[columns.length - 1];
        columns.slice(1, -1).forEach((column, index) => { after.columns[index + 1] = column; });
        apply(after);

        return { undo: () => apply(before), redo: () => apply(after) };
    }

    /** Puts a block next to a block on the page: as a further column, or by wrapping both in new columns. */
    putBeside(target, block, side) {
        const type = this.types.get(target.type);
        if (type === this.columnsType) {
            const columns = this.activeColumns(target, type).map((field) => target.data[field.name]);

            return this.arrangeColumns(target, side === 'left' ? [[block], ...columns] : [...columns, [block]]);
        }

        const wrapper = this.newBlock(this.columnsType.type);
        this.arrangeColumns(wrapper, side === 'left' ? [[block], [target]] : [[target], [block]]);
        this.blocks[this.blocks.indexOf(target)] = wrapper;

        return {
            undo: () => { this.blocks[this.blocks.indexOf(wrapper)] = target; },
            redo: () => { this.blocks[this.blocks.indexOf(target)] = wrapper; },
        };
    }

    /**
     * A block was dragged out of a column. If that emptied the column, the column goes away;
     * with a single column left, the layout block dissolves into the blocks it still holds.
     * Moving blocks between the columns of the same layout block changes nothing.
     */
    leaveColumn(from, to) {
        const owner = this.ownerOf(from);
        if (!owner || from.length > 0 || this.types.get(owner.type) !== this.columnsType) {
            return null;
        }

        const type = this.columnsType;
        if (to && this.columnFields(type).some((field) => owner.data[field.name] === to)) {
            return null;
        }

        const remaining = this.activeColumns(owner, type).map((field) => owner.data[field.name]).filter((column) => column !== from);
        if (remaining.length > 1) {
            return this.arrangeColumns(owner, remaining);
        }

        const index = this.blocks.indexOf(owner);
        const rest = [...(remaining[0] ?? [])];
        this.blocks.splice(index, 1, ...rest);

        return {
            undo: () => this.blocks.splice(rest.length > 0 ? this.blocks.indexOf(rest[0]) : Math.min(index, this.blocks.length), rest.length, owner),
            redo: () => this.blocks.splice(this.blocks.indexOf(owner), 1, ...rest),
        };
    }

    // ----- Block operations ----------------------------------------------

    /** After the next render, focus the same action button of the block that was moved. */
    keepFocusOn(block, action) {
        this.focusRequest = () => {
            const node = [...this.listTarget.querySelectorAll('.block')].find((candidate) => candidate.block === block);
            const button = node?.querySelector(`:scope > .block__header [data-block-action="${action}"]`);
            (button && !button.disabled ? button : node?.querySelector('.icon-button:not([disabled])'))?.focus();
        };
    }

    duplicate(list, index) {
        const copy = structuredClone(list[index]);
        this.addBlock(list, index + 1, copy);
        this.keepFocusOn(copy, 'duplicate');
        this.structureChanged();
    }

    remove(list, index) {
        const [block] = list.splice(index, 1);
        this.record({
            undo: () => list.splice(Math.min(index, list.length), 0, block),
            redo: () => list.splice(list.indexOf(block), 1),
        });
        this.structureChanged();
        toast(this.i18n.deleted, { actionLabel: this.i18n.undo, onAction: () => this.history.undo() });
    }

    insert(list, index, typeName) {
        const block = this.newBlock(typeName);
        this.addBlock(list, index, block);
        this.focusNewBlock(block);
        this.structureChanged();

        return block;
    }

    /** Swaps one block for another and remembers the step for undo. */
    replaceBlock(list, index, replacement) {
        const previous = list[index];
        list[index] = replacement;
        this.record({
            undo: () => { list[list.indexOf(replacement)] = previous; },
            redo: () => { list[list.indexOf(previous)] = replacement; },
        });
    }

    /** Puts a block into a list and remembers the step for undo. */
    addBlock(list, index, block) {
        list.splice(index, 0, block);
        this.record({
            undo: () => list.splice(list.indexOf(block), 1),
            redo: () => list.splice(Math.min(index, list.length), 0, block),
        });
    }

    /** After the next render, scroll to the given block and put the cursor into its first field. */
    focusNewBlock(block) {
        this.focusRequest = () => {
            const node = [...this.listTarget.querySelectorAll('.block')].find((candidate) => candidate.block === block);
            node?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            // Prefer a field to type into; fall back to the first button (e.g. "Choose image").
            const target = node?.querySelector('.block__body :is(input, textarea, select, [contenteditable]):not(.richtext__link-input)')
                ?? node?.querySelector('.block__body button')
                ?? node?.querySelector('button');
            target?.focus({ preventScroll: true });
        };
    }

    // ----- Shared blocks --------------------------------------------------

    /** Asks for a name right inside the block, then turns the block into a shared block. */
    askSharedBlockName(node, list, index) {
        this.closeMenu();

        const input = el('input', { type: 'text', maxlength: '120', placeholder: this.i18n.share_name, 'aria-label': this.i18n.share_name });
        const message = el('p', { class: 'field__errors', role: 'alert', hidden: true });
        const save = async () => {
            const name = input.value.trim();
            if (name === '') {
                input.focus();

                return;
            }

            try {
                const response = await this.post(this.config.urls.sharedCreate, { name, block: list[index] });
                const result = await response.json();
                if (!response.ok) {
                    throw new Error(result.error ?? this.i18n.picker_error);
                }

                this.config.sharedBlocks.push(result);
                this.replaceBlock(list, index, { type: 'shared', data: { block: result.id } });
                this.structureChanged();
            } catch (error) {
                message.textContent = error.message || this.i18n.picker_error;
                message.hidden = false;
            }
        };
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                event.stopPropagation();
                save();
            }
        });

        this.menu = el('div', { class: 'block-menu block-menu--form' },
            el('p', { class: 'block-menu__intro', text: this.i18n.share_intro }),
            input,
            el('div', { class: 'form-actions' },
                el('button', { type: 'button', class: 'button button--primary button--small', onclick: save }, this.i18n.share_save),
                el('button', { type: 'button', class: 'button button--small', onclick: () => this.closeMenu() }, this.i18n.rt_link_cancel),
            ),
            message,
        );
        this.menuAnchor = node;
        node.querySelector('.block__header').after(this.menu);
        input.focus();
    }

    // ----- "Add block" menu ----------------------------------------------

    toggleMenu(anchor, list, index, nested) {
        if (this.menu && this.menuAnchor === anchor) {
            this.closeMenu();

            return;
        }

        this.showMenu(anchor, nested, (typeName) => this.insert(list, index, typeName));
    }

    /**
     * Shows the list of block types below the anchor. Typing filters the list,
     * Enter picks the first match, Escape closes it.
     */
    showMenu(anchor, nested, onChoose, onCancel = () => {}) {
        this.closeMenu();

        const choices = this.config.types.filter((type) => !nested || !type.layout);
        const items = choices.map((type) => {
            const item = el('button', {
                type: 'button',
                class: 'block-menu__item',
                role: 'menuitem',
                onclick: () => onChoose(type.type),
            }, el('span', { class: 'block-menu__icon', 'aria-hidden': 'true', text: type.icon }), type.label);
            item.typeName = type.type;
            item.searchText = type.label.toLowerCase();

            return item;
        });
        const noMatch = el('p', { class: 'block-menu__empty muted', text: this.i18n.menu_no_match, hidden: true });
        const search = el('input', {
            type: 'search',
            class: 'block-menu__search',
            placeholder: this.i18n.menu_search,
            'aria-label': this.i18n.menu_search,
            autocomplete: 'off',
        });
        const visible = () => items.filter((item) => !item.hidden);

        search.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();
            items.forEach((item) => { item.hidden = query !== '' && !item.searchText.includes(query); });
            noMatch.hidden = visible().length > 0;
        });
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                // Always handled here, so Enter never submits the page form.
                event.preventDefault();
                event.stopPropagation();
                const [first] = visible();
                if (first) {
                    onChoose(first.typeName);
                }
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                visible()[0]?.focus();
            }
        });

        this.menu = el('div', { class: 'block-menu', role: 'menu', 'aria-label': this.i18n.add_block }, search, ...items, noMatch);
        this.menuAnchor = anchor;
        this.menuCancel = onCancel;
        anchor.after(this.menu);
        search.focus();
    }

    /**
     * Slash command: "/" typed into an empty text block offers to turn that
     * block into another type.
     */
    openSlashMenu(block) {
        const place = this.locate(block, this.blocks, false);
        const node = [...this.listTarget.querySelectorAll('.block')].find((candidate) => candidate.block === block);
        if (!place || !node) {
            return;
        }

        const backToText = () => node.querySelector('[contenteditable]')?.focus();
        this.showMenu(node, place.nested, (typeName) => {
            if (typeName === block.type) {
                this.closeMenu();
                backToText();

                return;
            }

            const replacement = this.newBlock(typeName);
            this.replaceBlock(place.list, place.index, replacement);
            this.focusNewBlock(replacement);
            this.structureChanged();
        }, backToText);
    }

    /** Finds the list a block lives in, looking into nested lists too. */
    locate(block, list, nested) {
        const index = list.indexOf(block);
        if (index !== -1) {
            return { list, index, nested };
        }

        for (const candidate of list) {
            for (const field of this.types.get(candidate.type)?.fields ?? []) {
                if (field.type === 'blocks' && Array.isArray(candidate.data[field.name])) {
                    const found = this.locate(block, candidate.data[field.name], true);
                    if (found) {
                        return found;
                    }
                }
            }
        }

        return null;
    }

    closeMenu() {
        this.menu?.remove();
        this.menu = null;
        this.menuAnchor = null;
        this.menuCancel = null;
    }

}

