import { Controller } from '@hotwired/stimulus';
import { createDragList, saveOptimistically, sendMove } from '../lib/dnd.js';

/*
 * A list whose items are arranged by dragging. Put it on the element that
 * contains the items:
 *
 *     <ul data-controller="sortable" data-sortable-group-value="gallery" aria-label="Images">
 *         <li data-dnd-item data-dnd-id="7" data-dnd-label="Harbour">
 *             <button type="button" data-dnd-handle class="dnd-handle">…</button> …
 *         </li>
 *     </ul>
 *
 * Lists with the same group exchange items. All behaviour - mouse, touch,
 * keyboard, move menu, announcements - comes from lib/dnd.js.
 *
 * Without a URL the controller only reports "dnd:move" events and the screen
 * around it stores the new order (for example on "Save"). With a URL every
 * move is sent to the server at once as { id, parent, position }; if the
 * server refuses it, the item goes back and the reason is shown.
 */
export default class extends Controller {
    static values = {
        group: { type: String, default: 'default' },
        // Selector an item has to match to be dropped here, e.g. ':not([data-layout="true"])'.
        accept: String,
        url: String,
        csrf: String,
        // Id of the thing this list belongs to (a folder, a parent page); sent as "parent".
        parent: String,
    };

    connect() {
        this.list = createDragList(this.element, { group: this.groupValue, accept: this.acceptValue || null });

        this.onMove = (event) => {
            // The event bubbles: only the list the item landed in reacts.
            if (event.detail.to === this.element && this.hasUrlValue && this.urlValue !== '') {
                this.save(event.detail);
            }
        };
        this.element.addEventListener('dnd:move', this.onMove);
    }

    disconnect() {
        this.element.removeEventListener('dnd:move', this.onMove);
        this.list.destroy();
    }

    save(detail) {
        const payload = (list, index) => ({
            id: Number(detail.item.dataset.dndId),
            parent: list.dataset.sortableParentValue ? Number(list.dataset.sortableParentValue) : null,
            position: index,
        });

        saveOptimistically({
            save: () => sendMove(this.urlValue, this.csrfValue, payload(detail.to, detail.newIndex)),
            revert: detail.revert,
            undo: () => {
                detail.revert();
                saveOptimistically({
                    save: () => sendMove(this.urlValue, this.csrfValue, payload(detail.from, detail.oldIndex)),
                    revert: () => detail.to.insertBefore(detail.item, [...detail.to.children].filter((child) => child !== detail.item)[detail.newIndex] ?? null),
                });
            },
        });
    }
}
