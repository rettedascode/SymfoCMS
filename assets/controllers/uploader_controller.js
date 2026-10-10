import { Controller } from '@hotwired/stimulus';
import { el } from '../lib/dom.js';

/*
 * Media library: after images were dropped or picked (see dropzone_controller.js),
 * the page is loaded again to show them. If some files were refused, their
 * messages stay on screen and a link reloads the page instead.
 */
export default class extends Controller {
    static targets = ['messages'];

    uploaded(event) {
        const { media, errors } = event.detail;

        if (errors.length === 0 && media.length > 0) {
            window.location.reload();
        } else if (media.length > 0 && this.hasMessagesTarget) {
            this.messagesTarget.append(el('li', {}, el('a', { href: window.location.href, text: '↻' })));
        }
    }
}
