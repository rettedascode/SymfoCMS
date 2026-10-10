import { Controller } from '@hotwired/stimulus';
import { announce, createDragList, focusHandle, handle, t } from '../lib/dnd.js';
import { el, format } from '../lib/dom.js';
import { History, moveStep } from '../lib/history.js';
import { icon } from '../lib/icons.js';
import { normalizeUrl } from '../lib/richtext.js';

/*
 * Menu editor.
 *
 * The menu is a list of items ({ label, type, page, post, url, children }).
 * Items are arranged with the shared drag-and-drop layer (lib/dnd.js): drag
 * them, carry them with the keyboard or use the menu on the handle. Where the
 * menu allows it, an item dropped into the list of another item becomes its
 * sub-item (one level deep). Ctrl+Z takes a change back.
 */
export default class extends Controller {
    static targets = ['list', 'status', 'saveButton', 'pageSelect', 'postSelect', 'postField', 'urlLabel', 'urlAddress'];
    static values = { config: Object };

    connect() {
        this.config = this.configValue;
        this.i18n = this.config.i18n;
        this.items = structuredClone(this.config.items);
        this.sortables = [];
        this.dirty = false;
        this.history = new History();
        this.onDndMove = (event) => this.itemMoved(event.detail);
        this.element.addEventListener('dnd:move', this.onDndMove);
        this.unbindHistoryKeys = this.history.bindKeys(document, {
            onUndo: () => announce(t('undone')),
            onRedo: () => announce(t('redone')),
        });

        this.fillSelect(this.pageSelectTarget, this.config.pages);
        this.fillSelect(this.postSelectTarget, this.config.posts);
        this.postFieldTarget.hidden = this.config.posts.length === 0;
        this.render();

        this.onBeforeUnload = (event) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.element.removeEventListener('dnd:move', this.onDndMove);
        this.unbindHistoryKeys();
        this.sortables.forEach((sortable) => sortable.destroy());
    }

    fillSelect(select, choices) {
        select.replaceChildren(...choices.map((choice) => el('option', { value: String(choice.id), text: choice.title })));
    }

    // ----- Adding items ---------------------------------------------------

    addPage() {
        const page = this.config.pages.find((choice) => String(choice.id) === this.pageSelectTarget.value);
        if (page) {
            this.add({ label: page.title, type: 'page', page: page.id, post: null, url: '' });
        }
    }

    addPost() {
        const post = this.config.posts.find((choice) => String(choice.id) === this.postSelectTarget.value);
        if (post) {
            this.add({ label: post.title, type: 'post', page: null, post: post.id, url: '' });
        }
    }

    addBlog() {
        this.add({ label: this.i18n.blog_label, type: 'url', page: null, post: null, url: this.config.blogUrl });
    }

    addUrl() {
        const label = this.urlLabelTarget.value.trim();
        const url = normalizeUrl(this.urlAddressTarget.value);
        if (label === '' || url === '') {
            (label === '' ? this.urlLabelTarget : this.urlAddressTarget).focus();

            return;
        }

        this.add({ label, type: 'url', page: null, post: null, url });
        this.urlLabelTarget.value = '';
        this.urlAddressTarget.value = '';
    }

    add(item) {
        const added = { ...item, children: [] };
        this.items.push(added);
        this.record({
            undo: () => this.items.splice(this.items.indexOf(added), 1),
            redo: () => this.items.push(added),
        });
        this.changed();
        this.render();
    }

    /** Remembers a change of the structure for Ctrl+Z and Ctrl+Shift+Z. */
    record(step) {
        const redraw = () => {
            this.changed();
            this.render();
        };
        this.history.record({
            undo: () => { step.undo(); redraw(); },
            redo: () => { step.redo(); redraw(); },
        });
    }

    /** The drag-and-drop layer has moved an item on screen: the data follows. */
    itemMoved(detail) {
        const from = detail.from.items;
        const to = detail.to.items;
        if (!from || !to) {
            return;
        }

        const [moved] = from.splice(detail.oldIndex, 1);
        to.splice(detail.newIndex, 0, moved);
        this.record(moveStep(from, detail.oldIndex, to, detail.newIndex));
        if (detail.cause !== 'pointer') {
            this.focusRequest = () => focusHandle([...this.listTarget.querySelectorAll('.menu-item')].find((candidate) => candidate.item === moved));
        }
        setTimeout(() => {
            this.changed();
            this.render();
        });
    }
    // ----- State ----------------------------------------------------------

    changed() {
        this.dirty = true;
        this.statusTarget.textContent = this.i18n.unsaved;
    }

    async save() {
        this.saveButtonTarget.disabled = true;
        this.statusTarget.textContent = this.i18n.saving;

        try {
            const response = await fetch(this.config.saveUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.config.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ items: this.items }),
            });
            if (!response.ok) {
                throw new Error(`Saving failed with status ${response.status}`);
            }

            this.items = (await response.json()).items;
            this.dirty = false;
            this.statusTarget.textContent = this.i18n.saved;
            this.render();
        } catch {
            this.statusTarget.textContent = this.i18n.error;
        } finally {
            this.saveButtonTarget.disabled = false;
        }
    }

    // ----- Rendering ------------------------------------------------------

    render() {
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];

        if (this.items.length === 0) {
            this.listTarget.replaceChildren(el('p', { class: 'blocks__empty', text: this.i18n.empty }));

            return;
        }

        this.listTarget.replaceChildren(this.renderLevel(this.items, null));

        if (this.focusRequest) {
            this.focusRequest();
            this.focusRequest = null;
        }
    }

    renderLevel(items, parent) {
        const list = el('ul', {
            class: parent ? 'menu-list menu-list--children' : 'menu-list',
            'aria-label': parent ? format(this.i18n.list_inside, { title: parent.label }) : this.i18n.list_top,
            'data-dnd-empty-hint': parent ? this.i18n.drop_sub_item : null,
        });
        list.items = items;
        items.forEach((item, index) => list.append(this.renderItem(item, items, index, parent)));

        this.sortables.push(createDragList(list, {
            group: 'menu',
            // Only one level of nesting: an item that has sub-items cannot become a sub-item itself.
            accept: parent ? (dragged) => (dragged.item?.children ?? []).length === 0 : null,
        }));

        return list;
    }

    renderItem(item, siblings, index, parent) {
        const button = (action, label, iconName, onClick, disabled = false) => el('button', {
            type: 'button',
            class: action === 'delete' ? 'icon-button icon-button--danger' : 'icon-button',
            title: label,
            'aria-label': label,
            'data-item-action': action,
            disabled,
            onclick: onClick,
        }, icon(iconName));

        const labelInput = el('input', {
            type: 'text',
            class: 'menu-item__label',
            'aria-label': this.i18n.label,
            maxlength: '120',
            oninput: (event) => {
                item.label = event.target.value;
                this.changed();
            },
        });
        labelInput.value = item.label;

        const canNest = this.config.allowNesting;
        item.children ??= [];
        const row = el('div', { class: 'menu-item__row' },
            handle(item.label),
            labelInput,
            el('span', { class: 'menu-item__target', text: this.describe(item) }),
            el('span', { class: 'menu-item__actions' },
                button('delete', this.i18n.delete, 'trash', () => {
                    siblings.splice(index, 1);
                    this.record({
                        undo: () => siblings.splice(Math.min(index, siblings.length), 0, item),
                        redo: () => siblings.splice(siblings.indexOf(item), 1),
                    });
                    this.changed();
                    this.render();
                }),
            ),
        );

        const node = el('li', { class: 'menu-item', 'data-dnd-item': true, 'data-dnd-label': item.label }, row);
        node.item = item;

        if (canNest && !parent) {
            node.append(this.renderLevel(item.children, item));
        }

        return node;
    }

    describe(item) {
        if (item.type === 'page') {
            return format(this.i18n.type_page, { title: this.config.pages.find((page) => page.id === item.page)?.title ?? '?' });
        }
        if (item.type === 'post') {
            return format(this.i18n.type_post, { title: this.config.posts.find((post) => post.id === item.post)?.title ?? '?' });
        }

        return item.url;
    }
}
