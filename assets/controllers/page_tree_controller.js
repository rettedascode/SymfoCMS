import { Controller } from '@hotwired/stimulus';
import { announce, t, toast } from '../lib/dnd.js';
import { el, format } from '../lib/dom.js';

/*
 * The page tree. Dragging, keyboard and collapsing come from the tree
 * controller on the same element (tree_controller.js); this controller
 * stores every move on the server.
 *
 * Moving a page below another one changes its address. When that concerns
 * published pages, the server first answers with the list of addresses
 * (status 409) and the person is asked. Only after "Move and redirect" is
 * the move stored, and the old addresses lead to the new ones.
 */
export default class extends Controller {
    static values = { url: String, csrf: String, i18n: Object };

    connect() {
        this.onMove = (event) => this.moved(event.detail);
        this.element.addEventListener('dnd:move', this.onMove);
    }

    disconnect() {
        this.element.removeEventListener('dnd:move', this.onMove);
    }

    async moved(detail) {
        const stored = await this.store(detail, detail.to, detail.newIndex, false);
        if (stored === null) {
            this.putBack(detail);

            return;
        }

        this.showPaths(stored.paths);
        const message = stored.redirects > 0 ? this.plural(this.i18nValue.moved_redirects, stored.redirects) : this.i18nValue.moved;
        toast(message, { actionLabel: t('undo'), onAction: () => this.undo(detail) });
        announce(message);
    }

    /** "Undo" in the toast: the page goes back, on screen and on the server. */
    async undo(detail) {
        const landed = { list: detail.to, next: detail.item.nextElementSibling };
        this.putBack(detail);

        // Asked for explicitly, so no second question about addresses.
        const stored = await this.store(detail, detail.from, detail.oldIndex, true);
        if (stored === null) {
            landed.list.insertBefore(detail.item, landed.next);
            this.refreshTree();

            return;
        }

        this.showPaths(stored.paths);
        announce(t('undone'));
    }

    /**
     * Tells the server where the page is now.
     *
     * @returns {Promise<{paths: object, redirects: number}|null>} null when the move was refused or called off
     */
    async store(detail, list, index, confirmed) {
        let response;
        let result = {};
        try {
            response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
                body: JSON.stringify({
                    id: Number(detail.item.dataset.dndId),
                    parent: list.dataset.treeParent ? Number(list.dataset.treeParent) : null,
                    position: index,
                    confirmed,
                }),
            });
            result = await response.json().catch(() => ({}));
        } catch {
            toast(t('save_failed'), { type: 'error' });

            return null;
        }

        if (response.status === 409 && result.confirm) {
            return (await this.ask(result)) ? this.store(detail, list, index, true) : null;
        }

        if (!response.ok) {
            const message = result.error ?? t('save_failed');
            toast(message, { type: 'error' });
            announce(message);

            return null;
        }

        return result;
    }

    /**
     * Shows which addresses would change and waits for the decision.
     *
     * @returns {Promise<boolean>}
     */
    ask({ confirm, changes }) {
        return new Promise((resolve) => {
            const dialog = el('dialog', { class: 'confirm-dialog', 'aria-labelledby': 'page-tree-confirm-title' },
                el('h2', { id: 'page-tree-confirm-title', text: this.i18nValue.confirm_title }),
                el('p', { text: confirm }),
                el('ul', {}, ...changes.map((change) => el('li', {},
                    el('strong', { text: change.title }),
                    el('br'),
                    el('code', { text: change.from }),
                    ' → ',
                    el('code', { text: change.to }),
                ))),
                el('div', { class: 'form-actions' },
                    el('button', { type: 'button', class: 'button button--primary', 'data-confirm': 'yes', text: this.i18nValue.confirm_ok, onclick: () => dialog.close('yes') }),
                    el('button', { type: 'button', class: 'button', 'data-confirm': 'no', text: this.i18nValue.confirm_cancel, onclick: () => dialog.close('no') }),
                ),
            );
            dialog.addEventListener('close', () => {
                dialog.remove();
                resolve(dialog.returnValue === 'yes');
            });
            document.body.append(dialog);
            dialog.showModal();
        });
    }

    putBack(detail) {
        detail.revert();
        this.refreshTree();
    }

    /** Lets the tree controller update which pages have children. */
    refreshTree() {
        this.application.getControllerForElementAndIdentifier(this.element, 'tree')?.markParents();
    }

    /** The addresses shown next to the moved page and its child pages. */
    showPaths(paths = {}) {
        Object.entries(paths).forEach(([id, path]) => {
            this.element.querySelectorAll(`[data-page-path="${id}"]`).forEach((node) => { node.textContent = path; });
            this.element.querySelectorAll(`[data-page-link="${id}"]`).forEach((link) => { link.href = path; });
        });
    }

    /** Picks the singular or plural part of a "{1}one|]1,Inf[many" message. */
    plural(message, count) {
        const parts = message.split('|');
        const part = count === 1 || parts.length === 1 ? parts[0] : parts[1];

        return format(part.replace(/^\{1\}|^\]1,Inf\[/, ''), { count: String(count) });
    }
}
