import { announce, t, toast } from './dnd.js';
import { el } from './dom.js';

/*
 * Questions that have to be answered before a move is stored, e.g. "this
 * takes a published post off the website".
 *
 * The server decides when to ask: it answers a move with the status 409 and
 * {"confirm": "the question"}. The person answers in a dialog, and the move
 * is sent again with "confirmed": true - or called off.
 */

let counter = 0;

/**
 * Shows a question in a dialog and waits for the answer.
 *
 * @param {object}   options
 * @param {string}   options.title
 * @param {string}   options.text
 * @param {Node[]}   [options.details] shown as a list between the question and the buttons
 * @param {string}   options.ok        label of the button that goes ahead
 * @param {string}   options.cancel    label of the button that calls it off
 *
 * @returns {Promise<boolean>}
 */
export function ask({ title, text, details = [], ok, cancel }) {
    return new Promise((resolve) => {
        const titleId = `confirm-dialog-title-${++counter}`;
        const dialog = el('dialog', { class: 'confirm-dialog', 'aria-labelledby': titleId },
            el('h2', { id: titleId, text: title }),
            el('p', { text }),
            details.length > 0 ? el('ul', {}, ...details.map((detail) => el('li', {}, detail))) : null,
            el('div', { class: 'form-actions' },
                el('button', { type: 'button', class: 'button button--primary', 'data-confirm': 'yes', text: ok, onclick: () => dialog.close('yes') }),
                el('button', { type: 'button', class: 'button', 'data-confirm': 'no', text: cancel, onclick: () => dialog.close('no') }),
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

/**
 * Sends a move and, if the server asks, the question that goes with it.
 *
 * @param {string} url
 * @param {string} csrf
 * @param {object} payload
 * @param {{confirm_title: string, confirm_ok: string, confirm_cancel: string}} texts
 * @param {boolean} [confirmed] true when the question was answered already (or must not be asked, as for "Undo")
 *
 * @returns {Promise<object|null>} the answer of the server, or null when the move was refused or called off
 */
export async function sendConfirmed(url, csrf, payload, texts, confirmed = false) {
    let response;
    let result = {};
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: JSON.stringify({ ...payload, confirmed }),
        });
        result = await response.json().catch(() => ({}));
    } catch {
        toast(t('save_failed'), { type: 'error' });

        return null;
    }

    if (response.status === 409 && result.confirm) {
        const agreed = await ask({ title: texts.confirm_title, text: result.confirm, ok: texts.confirm_ok, cancel: texts.confirm_cancel });

        return agreed ? sendConfirmed(url, csrf, payload, texts, true) : null;
    }

    if (!response.ok) {
        const message = result.error ?? t('save_failed');
        toast(message, { type: 'error' });
        announce(message);

        return null;
    }

    return result;
}
