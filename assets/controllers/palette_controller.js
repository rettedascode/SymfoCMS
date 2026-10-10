import { Controller } from '@hotwired/stimulus';
import { createDragList } from '../lib/dnd.js';

/*
 * A palette: a fixed set of things that are dragged into a list of the same
 * group to create something there - block types into a page, field types
 * into a form.
 *
 *     <ul data-controller="palette" data-palette-group-value="blocks">
 *         <li data-dnd-item data-palette-type="heading" data-dnd-label="Heading">
 *             <button type="button" data-dnd-handle data-action="palette#add">Heading</button>
 *         </li>
 *     </ul>
 *
 * Dragging copies: the palette itself never changes. The list an entry is
 * dropped into receives a "dnd:insert" event with the entry and the position.
 * Clicking an entry (or pressing Enter on it) dispatches "palette:add", which
 * screens use to add the thing at the end.
 */
export default class extends Controller {
    static values = { group: { type: String, default: 'default' } };

    connect() {
        this.list = createDragList(this.element, { group: this.groupValue, clone: true, sort: false, put: false });

        // In a palette a click means "add this", not "open the move menu".
        this.onClick = (event) => {
            const item = event.target.closest('[data-dnd-item]');
            if (item && this.element.contains(item)) {
                event.stopImmediatePropagation();
                this.dispatch('add', { detail: { source: item, type: item.dataset.paletteType } });
            }
        };
        // Space would start carrying, which makes no sense for something that is copied: it adds, like Enter.
        this.onKeydown = (event) => {
            if ((event.key === ' ' || event.key === 'Spacebar') && event.target.closest('[data-dnd-handle]')) {
                event.stopImmediatePropagation();
            }
        };
        this.element.addEventListener('click', this.onClick, true);
        this.element.addEventListener('keydown', this.onKeydown, true);
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick, true);
        this.element.removeEventListener('keydown', this.onKeydown, true);
        this.list.destroy();
    }
}
