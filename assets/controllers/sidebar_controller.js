import { Controller } from '@hotwired/stimulus';

// Opens and closes the admin sidebar on tablets and phones.
export default class extends Controller {
    static targets = ['panel', 'button'];

    toggle() {
        const open = this.panelTarget.classList.toggle('is-open');
        this.buttonTarget.setAttribute('aria-expanded', String(open));
    }
}
