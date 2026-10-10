import { Controller } from '@hotwired/stimulus';
import { sendConfirmed } from '../lib/confirm.js';
import { announce, createDragList, t, toast } from '../lib/dnd.js';

/*
 * The editorial board: posts as cards in one column per status.
 *
 *     <div data-controller="board" data-board-url-value="…" data-board-csrf-value="…">
 *         <ul data-board-target="column" data-status="draft" aria-label="Draft">
 *             <li data-dnd-item data-dnd-id="7" data-allowed="draft review">…</li>
 *         </ul>
 *     </div>
 *
 * Dragging a card into another column gives its post that status. A column
 * only takes cards whose "data-allowed" names its status - the steps the
 * workflow allows this person - and shows "not allowed" for the others.
 * Every move is stored at once; the server checks it again and asks before
 * a published post is taken off the website.
 */
export default class extends Controller {
    static targets = ['column', 'count'];
    static values = { url: String, csrf: String, i18n: Object };

    connect() {
        this.element.setAttribute('data-dnd-scope', '');
        // The order inside a column is not ours to change: it follows the dates of the posts.
        this.lists = this.columnTargets.map((column) => createDragList(column, {
            group: 'board',
            sort: false,
            accept: (item, list) => (item.dataset.allowed ?? '').split(' ').includes(list.dataset.status),
        }));
        this.onMove = (event) => this.moved(event.detail);
        this.element.addEventListener('dnd:move', this.onMove);
    }

    disconnect() {
        this.element.removeEventListener('dnd:move', this.onMove);
        this.lists.forEach((list) => list.destroy());
    }

    async moved(detail) {
        if (detail.from === detail.to) {
            return;
        }

        const previous = detail.from.dataset.status;
        const stored = await this.store(detail.item, detail.to.dataset.status, false);
        if (stored === null) {
            detail.revert();
            this.count();

            return;
        }

        this.show(detail.item, stored);
        const message = this.i18nValue.moved.replace('%status%', stored.label);
        toast(message, { actionLabel: t('undo'), onAction: () => this.undo(detail, previous) });
        announce(message);
    }

    /** "Undo" in the message: the card goes back, on screen and on the server. */
    async undo(detail, previous) {
        const landed = { list: detail.to, next: detail.item.nextElementSibling };
        detail.revert();

        // Asked for explicitly, so no question about taking the post offline.
        const stored = await this.store(detail.item, previous, true);
        if (stored === null) {
            landed.list.insertBefore(detail.item, landed.next);
        } else {
            this.show(detail.item, stored);
            announce(t('undone'));
        }
        this.count();
    }

    store(card, status, confirmed) {
        return sendConfirmed(this.urlValue, this.csrfValue, { id: Number(card.dataset.dndId), status }, this.i18nValue, confirmed);
    }

    /** The card follows what the server says about the post: its badge and where it may go next. */
    show(card, post) {
        card.dataset.allowed = post.allowed.join(' ');
        const badge = card.querySelector('[data-board-badge]');
        if (badge) {
            badge.textContent = post.label;
            badge.className = `badge badge--${post.state}`;
        }
        this.count();
    }

    count() {
        this.countTargets.forEach((counter) => {
            const column = this.columnTargets.find((candidate) => candidate.dataset.status === counter.dataset.status);
            counter.textContent = String(column.querySelectorAll(':scope > [data-dnd-item]').length);
        });
    }
}
