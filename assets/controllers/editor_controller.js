import { Controller } from '@hotwired/stimulus';
import { renderField } from '../lib/block_fields.js';
import { announce, createDragList, focusHandle, handle, hideToast, t, toast } from '../lib/dnd.js';
import { el, format } from '../lib/dom.js';
import { History, moveStep } from '../lib/history.js';
import { icon } from '../lib/icons.js';

const PREVIEW_DELAY = 350;
const AUTOSAVE_DELAY = 2500;

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
 */
export default class extends Controller {
    static targets = ['form', 'title', 'input', 'list', 'preview', 'status', 'restore', 'restoreText', 'tab', 'panel', 'viewButton'];
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
        this.sortables = [];
        this.history = new History();
        this.dirty = this.unsavedValue;
        this.submitting = false;
        this.previewRequest = 0;

        if (this.config.autosave) {
            this.restoreAutosave(this.config.autosave);
        }

        this.render();
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
        this.previewTimer = setTimeout(() => this.refreshPreview(), PREVIEW_DELAY);

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

    /** The drag-and-drop layer has moved a block on screen: the data follows. */
    blockMoved(detail) {
        const from = this.lists.get(detail.from);
        const to = this.lists.get(detail.to);
        if (!from || !to) {
            return;
        }

        const [moved] = from.splice(detail.oldIndex, 1);
        to.splice(detail.newIndex, 0, moved);
        this.record(moveStep(from, detail.oldIndex, to, detail.newIndex));
        if (detail.cause !== 'pointer') {
            this.keepFocusOnHandle(moved);
        }
        // Let the layer finish its own cleanup before the list is rebuilt.
        setTimeout(() => this.structureChanged());
    }

    /** After the next render, focus the handle of the block that was moved. */
    keepFocusOnHandle(block) {
        this.focusRequest = () => focusHandle([...this.listTarget.querySelectorAll('.block')].find((candidate) => candidate.block === block));
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

        const body = el('div', { class: type.layout ? 'block__body block__body--layout' : 'block__body' });
        for (const field of type.fields) {
            body.append(renderField(this, block, field, path));
        }
        if (type.fields.length === 0) {
            body.hidden = true;
        }

        node.append(header, body);
        node.block = block;

        return node;
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

