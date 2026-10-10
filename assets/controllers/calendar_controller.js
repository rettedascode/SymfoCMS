import { Controller } from '@hotwired/stimulus';
import { sendConfirmed } from '../lib/confirm.js';
import { announce, createDragList, t, toast } from '../lib/dnd.js';

/*
 * The editorial calendar: a month with one list per day.
 *
 *     <div data-controller="calendar" data-calendar-url-value="…" data-calendar-csrf-value="…">
 *         <ul data-calendar-target="day" data-date="2026-10-14" aria-label="14 October 2026">
 *             <li data-dnd-item data-dnd-id="7">…</li>
 *         </ul>
 *     </div>
 *
 * Dragging a post to another day changes its publish date; the time of day
 * stays. Every move is stored at once. The server asks before a post that
 * visitors can read is moved into the future, because it would disappear
 * from the website until then.
 */
export default class extends Controller {
    static targets = ['day'];
    static values = { url: String, csrf: String, i18n: Object };

    connect() {
        this.element.setAttribute('data-dnd-scope', '');
        // Inside a day the posts follow their time; only the day is chosen by dragging.
        this.lists = this.dayTargets.map((day) => createDragList(day, { group: 'calendar', sort: false }));
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

        const previous = detail.from.dataset.date;
        const stored = await this.store(detail.item, detail.to.dataset.date, false);
        if (stored === null) {
            detail.revert();

            return;
        }

        this.show(detail.item, stored);
        toast(stored.message, { actionLabel: t('undo'), onAction: () => this.undo(detail, previous) });
        announce(stored.message);
    }

    /** "Undo" in the message: the post goes back to its day, on screen and on the server. */
    async undo(detail, previous) {
        const landed = { list: detail.to, next: detail.item.nextElementSibling };
        detail.revert();

        const stored = await this.store(detail.item, previous, true);
        if (stored === null) {
            landed.list.insertBefore(detail.item, landed.next);
        } else {
            this.show(detail.item, stored);
            announce(t('undone'));
        }
    }

    store(post, date, confirmed) {
        return sendConfirmed(this.urlValue, this.csrfValue, { id: Number(post.dataset.dndId), date }, this.i18nValue, confirmed);
    }

    /** The entry follows what the server says about the post: scheduled, published, ... */
    show(entry, post) {
        const badge = entry.querySelector('[data-calendar-badge]');
        if (badge) {
            badge.textContent = post.label;
            badge.className = `badge badge--${post.state}`;
        }
        entry.className = `calendar-post calendar-post--${post.state}`;

        // Within the day the entries stand in the order of their time.
        const time = entry.querySelector('[data-calendar-time]');
        if (time) {
            time.textContent = post.time;
        }
        const later = [...entry.parentElement.children].find((other) => other !== entry && (other.querySelector('[data-calendar-time]')?.textContent ?? '') > post.time);
        entry.parentElement.insertBefore(entry, later ?? null);
    }
}
