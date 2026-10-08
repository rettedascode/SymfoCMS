import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';
import { el, format } from '../lib/dom.js';
import { icon } from '../lib/icons.js';
import { normalizeUrl } from '../lib/richtext.js';

/*
 * Menu editor.
 *
 * The menu is a list of items ({ label, type, page, post, url, children }).
 * Items can be dragged to reorder them and, where the menu allows it, dropped
 * onto the area below another item to become its sub-item (one level deep).
 * Every drag has an equivalent button, so the editor also works by keyboard.
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
        this.items.push({ ...item, children: [] });
        this.changed();
        this.render();
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
        const list = el('ul', { class: parent ? 'menu-list menu-list--children' : 'menu-list' });
        list.items = items;
        items.forEach((item, index) => list.append(this.renderItem(item, items, index, parent)));

        this.sortables.push(Sortable.create(list, {
            group: {
                name: 'menu',
                // Only one level of nesting: an item that has sub-items cannot become a sub-item itself.
                put: (to, from, dragged) => !parent || (dragged.item?.children ?? []).length === 0,
            },
            handle: '.menu-item__handle',
            animation: 150,
            ghostClass: 'menu-item--ghost',
            onEnd: (event) => {
                if (event.from === event.to && event.oldIndex === event.newIndex) {
                    return;
                }
                const [moved] = event.from.items.splice(event.oldIndex, 1);
                event.to.items.splice(event.newIndex, 0, moved);
                setTimeout(() => {
                    this.changed();
                    this.render();
                });
            },
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
            el('span', { class: 'menu-item__handle', title: this.i18n.drag, 'aria-hidden': 'true' }, icon('grip')),
            labelInput,
            el('span', { class: 'menu-item__target', text: this.describe(item) }),
            el('span', { class: 'menu-item__actions' },
                button('up', this.i18n.move_up, 'up', () => this.move(item, siblings, index, -1), index === 0),
                button('down', this.i18n.move_down, 'down', () => this.move(item, siblings, index, 1), index === siblings.length - 1),
                canNest && !parent ? button('indent', this.i18n.indent, 'indent', () => this.indent(item, index), index === 0 || item.children.length > 0) : null,
                canNest && parent ? button('outdent', this.i18n.outdent, 'outdent', () => this.outdent(item, parent, index)) : null,
                button('delete', this.i18n.delete, 'trash', () => {
                    siblings.splice(index, 1);
                    this.changed();
                    this.render();
                }),
            ),
        );

        const node = el('li', { class: 'menu-item' }, row);
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

    // ----- Keyboard-friendly alternatives to dragging ---------------------

    keepFocusOn(item, action) {
        this.focusRequest = () => {
            const node = [...this.listTarget.querySelectorAll('.menu-item')].find((candidate) => candidate.item === item);
            const preferred = node?.querySelector(`:scope > .menu-item__row [data-item-action="${action}"]`);
            (preferred && !preferred.disabled ? preferred : node?.querySelector('.icon-button:not([disabled])'))?.focus();
        };
    }

    move(item, siblings, index, delta) {
        siblings.splice(index, 1);
        siblings.splice(index + delta, 0, item);
        this.keepFocusOn(item, delta < 0 ? 'up' : 'down');
        this.changed();
        this.render();
    }

    /** Makes the item a sub-item of the item above it. */
    indent(item, index) {
        this.items.splice(index, 1);
        this.items[index - 1].children.push({ ...item, children: [] });
        this.keepFocusOn(this.items[index - 1].children.at(-1), 'outdent');
        this.changed();
        this.render();
    }

    /** Moves a sub-item back to the top level, right after its former parent. */
    outdent(item, parent, index) {
        parent.children.splice(index, 1);
        const moved = { ...item, children: [] };
        this.items.splice(this.items.indexOf(parent) + 1, 0, moved);
        this.keepFocusOn(moved, 'indent');
        this.changed();
        this.render();
    }
}
