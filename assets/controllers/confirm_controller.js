import { Controller } from '@hotwired/stimulus';

// Asks for confirmation before a form is submitted, e.g. before deleting something.
export default class extends Controller {
    static values = { message: String };

    connect() {
        this.element.addEventListener('submit', (event) => {
            if (!window.confirm(this.messageValue)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    }
}
