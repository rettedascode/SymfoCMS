import { Controller } from '@hotwired/stimulus';
import { announce, createDragList, saveOptimistically, sendMove, t } from '../lib/dnd.js';

/*
 * The start page: widgets that are arranged by dragging and resized at their right edge.
 *
 *     <div data-controller="dashboard" data-dashboard-url-value="…" data-dashboard-sizes-value="[2,3,4,6]">
 *         <section data-dnd-item data-dnd-id="drafts" data-size="3" style="--span: 3">
 *             <button data-dnd-handle>…</button> …
 *             <div role="separator" tabindex="0" data-dashboard-target="resize"></div>
 *         </section>
 *     </div>
 *
 * The page is a grid; "--span" is the number of its columns a widget takes. Dragging the edge
 * snaps to the widths the server allows, and the left and right arrow keys step through them.
 * Every change is stored for the user at once, with "Undo" in the message.
 */
export default class extends Controller {
    static targets = ['resize'];
    static values = { url: String, csrf: String, sizes: Array, i18n: Object };

    connect() {
        this.element.setAttribute('data-dnd-scope', '');
        this.list = createDragList(this.element, { group: 'dashboard' });
        this.onMove = (event) => this.save(event.detail.revert);
        this.element.addEventListener('dnd:move', this.onMove);

        this.onPointerDown = (event) => this.startResize(event);
        this.onKeydown = (event) => this.resizeWithKeys(event);
        this.resizeTargets.forEach((edge) => {
            edge.addEventListener('pointerdown', this.onPointerDown);
            edge.addEventListener('keydown', this.onKeydown);
        });
    }

    disconnect() {
        this.element.removeEventListener('dnd:move', this.onMove);
        this.resizeTargets.forEach((edge) => {
            edge.removeEventListener('pointerdown', this.onPointerDown);
            edge.removeEventListener('keydown', this.onKeydown);
        });
        this.list.destroy();
    }

    // ----- width ------------------------------------------------------------

    startResize(event) {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        event.preventDefault();
        const edge = event.currentTarget;
        const widget = edge.closest('[data-dnd-item]');
        const before = Number(widget.dataset.size);
        // One column of the grid, including the gap next to it.
        const grid = this.element.getBoundingClientRect();
        const gap = parseFloat(getComputedStyle(this.element).columnGap) || 0;
        const column = (grid.width + gap) / this.columns();
        const left = widget.getBoundingClientRect().left;

        const move = (moveEvent) => this.show(widget, this.nearest((moveEvent.clientX - left + gap) / column));
        const finish = () => {
            edge.removeEventListener('pointermove', move);
            edge.removeEventListener('pointerup', finish);
            edge.removeEventListener('pointercancel', finish);
            widget.classList.remove('is-resizing');
            this.resized(widget, before);
        };

        edge.setPointerCapture?.(event.pointerId);
        edge.focus({ preventScroll: true });
        widget.classList.add('is-resizing');
        edge.addEventListener('pointermove', move);
        edge.addEventListener('pointerup', finish);
        edge.addEventListener('pointercancel', finish);
    }

    resizeWithKeys(event) {
        const step = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
        const end = { Home: 0, End: this.sizesValue.length - 1 }[event.key];
        if (step === undefined && end === undefined) {
            return;
        }

        event.preventDefault();
        const widget = event.currentTarget.closest('[data-dnd-item]');
        const before = Number(widget.dataset.size);
        const index = end ?? Math.max(0, Math.min(this.sizesValue.length - 1, this.sizesValue.indexOf(before) + step));
        this.show(widget, this.sizesValue[index]);
        this.resized(widget, before);
    }

    /** The grid has as many columns as the widest widget takes. */
    columns() {
        return Math.max(...this.sizesValue);
    }

    /** The allowed width that is closest to the given number of columns. */
    nearest(columns) {
        return this.sizesValue.reduce((best, size) => (Math.abs(size - columns) < Math.abs(best - columns) ? size : best));
    }

    show(widget, size) {
        widget.dataset.size = String(size);
        widget.style.setProperty('--span', String(size));
        const edge = widget.querySelector('[data-dashboard-target~="resize"]');
        edge.setAttribute('aria-valuenow', String(size));
        edge.setAttribute('aria-valuetext', this.i18nValue[`size_${size}`] ?? String(size));
        edge.dataset.label = edge.getAttribute('aria-valuetext');
    }

    resized(widget, before) {
        const after = Number(widget.dataset.size);
        if (after === before) {
            return;
        }

        announce(this.i18nValue[`size_${after}`] ?? String(after));
        this.save(() => this.show(widget, before));
    }

    // ----- saving -----------------------------------------------------------

    layout() {
        return [...this.element.querySelectorAll(':scope > [data-dnd-item]')].map((widget) => ({ id: widget.dataset.dndId, size: Number(widget.dataset.size) }));
    }

    /**
     * Stores the arrangement as it is on screen.
     *
     * @param {function(): void} revert puts the screen back to what it was before the change
     */
    save(revert) {
        const send = () => sendMove(this.urlValue, this.csrfValue, { widgets: this.layout() });

        saveOptimistically({
            save: send,
            revert,
            message: this.i18nValue.saved,
            undo: () => {
                revert();
                saveOptimistically({ save: send, revert: () => {}, message: t('undone') });
            },
        });
    }
}
