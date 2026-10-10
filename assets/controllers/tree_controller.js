import { Controller } from '@hotwired/stimulus';
import { createDragList, saveOptimistically, sendMove } from '../lib/dnd.js';

/*
 * A tree arranged by dragging: every item can hold a list of child items.
 *
 *     <div data-controller="tree" data-tree-max-depth-value="3">
 *         <ul data-tree-target="list" aria-label="Pages">
 *             <li data-dnd-item data-dnd-id="1" data-dnd-label="About">
 *                 <button data-dnd-handle …>…</button> About
 *                 <ul data-tree-target="list" data-tree-parent="1" aria-label="Inside “About”"></ul>
 *             </li>
 *         </ul>
 *     </div>
 *
 * Dropping an item into the list of another item makes it a child; with the
 * keyboard, Right moves an item into the one above it and Left moves it out.
 * An item can never be moved into itself or its own children, and not deeper
 * than the maximum depth. An item with data-tree-top-only stays on the top level.
 *
 * Branches can be collapsed with a button that carries
 * data-action="tree#toggle"; with a "storage" value the state is remembered
 * in the browser.
 *
 * With a URL every move is sent at once as { id, parent, position }.
 */
export default class extends Controller {
    static targets = ['list'];

    static values = {
        group: { type: String, default: 'tree' },
        maxDepth: { type: Number, default: 0 },
        url: String,
        csrf: String,
        // Key under which collapsed branches are remembered, e.g. "pages-12" for user 12.
        storage: String,
    };

    // Stimulus announces the list targets before connect() runs, so the map has to exist earlier.
    initialize() {
        this.lists = new Map();
    }

    connect() {
        this.element.setAttribute('data-dnd-scope', '');
        this.restoreCollapsed();
        // The buttons were set up before the remembered state was known.
        this.markParents();

        this.onMove = (event) => {
            this.markParents();
            if (this.urlValue !== '') {
                this.save(event.detail);
            }
        };
        this.element.addEventListener('dnd:move', this.onMove);
    }

    disconnect() {
        this.element.removeEventListener('dnd:move', this.onMove);
        this.lists.forEach((list) => list.destroy());
        this.lists.clear();
    }

    listTargetConnected(element) {
        // An item that is dragged takes its own child list along: the list leaves the document and
        // comes back at once. It must keep its drag list, or the drag in progress would be cut off.
        if (this.lists.has(element)) {
            return;
        }

        this.lists.set(element, createDragList(element, {
            group: this.groupValue,
            accept: (item, list) => this.fits(item, list),
        }));
        this.markParents();
    }

    listTargetDisconnected(element) {
        // Only a list that is really gone is given up; see listTargetConnected().
        setTimeout(() => {
            if (!element.isConnected) {
                this.lists.get(element)?.destroy();
                this.lists.delete(element);
            }
        });
    }

    /** Would the item, together with everything below it, still be within the allowed depth in that list? */
    fits(item, list) {
        // Items marked with data-tree-top-only never become a child of another item.
        if (item.hasAttribute('data-tree-top-only') && list.closest('[data-dnd-item]')) {
            return false;
        }
        if (this.maxDepthValue < 1) {
            return true;
        }

        let depth = 1;
        for (let parent = list.closest('[data-dnd-item]'); parent && this.element.contains(parent); parent = parent.parentElement.closest('[data-dnd-item]')) {
            depth += 1;
        }

        return depth + this.height(item) - 1 <= this.maxDepthValue;
    }

    /** Number of levels below and including the item. */
    height(item) {
        let height = 1;
        for (const child of item.querySelectorAll('[data-dnd-item]')) {
            let level = 1;
            for (let parent = child.parentElement.closest('[data-dnd-item]'); parent && parent !== item; parent = parent.parentElement.closest('[data-dnd-item]')) {
                level += 1;
            }
            height = Math.max(height, level + 1);
        }

        return height;
    }

    /** Items that have children get a class and a working collapse button. */
    markParents() {
        this.element.querySelectorAll('[data-dnd-item]').forEach((item) => {
            const list = [...item.querySelectorAll('[data-tree-target~="list"]')].find((candidate) => candidate.closest('[data-dnd-item]') === item);
            const hasChildren = Boolean(list && [...list.children].some((child) => child.matches('[data-dnd-item]')));
            item.classList.toggle('has-children', hasChildren);
            item.querySelectorAll(':scope > * [data-action~="tree#toggle"], :scope > [data-action~="tree#toggle"]').forEach((button) => {
                if (button.closest('[data-dnd-item]') === item) {
                    button.hidden = !hasChildren;
                    button.setAttribute('aria-expanded', String(!item.classList.contains('is-collapsed')));
                }
            });
        });
    }

    // ----- collapsing branches ---------------------------------------------

    toggle(event) {
        const item = event.currentTarget.closest('[data-dnd-item]');
        item.classList.toggle('is-collapsed');
        event.currentTarget.setAttribute('aria-expanded', String(!item.classList.contains('is-collapsed')));
        this.rememberCollapsed();
    }

    rememberCollapsed() {
        if (this.storageValue === '') {
            return;
        }
        const ids = [...this.element.querySelectorAll('[data-dnd-item].is-collapsed')].map((item) => item.dataset.dndId);
        try {
            localStorage.setItem(`symfocms-tree-${this.storageValue}`, JSON.stringify(ids));
        } catch {
            // Without storage the state simply lasts until the page is left.
        }
    }

    restoreCollapsed() {
        if (this.storageValue === '') {
            return;
        }
        try {
            const ids = JSON.parse(localStorage.getItem(`symfocms-tree-${this.storageValue}`) ?? '[]');
            this.element.querySelectorAll('[data-dnd-item]').forEach((item) => item.classList.toggle('is-collapsed', ids.includes(item.dataset.dndId)));
        } catch {
            // A broken entry is ignored.
        }
    }

    // ----- saving ------------------------------------------------------------

    save(detail) {
        const payload = (list, index) => ({
            id: Number(detail.item.dataset.dndId),
            parent: list.dataset.treeParent ? Number(list.dataset.treeParent) : null,
            position: index,
        });
        const send = (list, index) => sendMove(this.urlValue, this.csrfValue, payload(list, index));

        // A screen may want a say before the move is stored, e.g. to ask for confirmation.
        const proceed = this.dispatch('before-save', { detail, cancelable: true });
        if (proceed.defaultPrevented) {
            return;
        }

        saveOptimistically({
            save: async () => {
                const result = await send(detail.to, detail.newIndex);
                this.dispatch('saved', { detail: { ...detail, result } });

                return result;
            },
            revert: () => {
                detail.revert();
                this.markParents();
            },
            undo: () => {
                detail.revert();
                this.markParents();
                saveOptimistically({
                    save: async () => {
                        const result = await send(detail.from, detail.oldIndex);
                        this.dispatch('saved', { detail: { ...detail, result } });

                        return result;
                    },
                    revert: () => {
                        detail.to.insertBefore(detail.item, [...detail.to.children].filter((child) => child !== detail.item)[detail.newIndex] ?? null);
                        this.markParents();
                    },
                });
            },
        });
    }
}
