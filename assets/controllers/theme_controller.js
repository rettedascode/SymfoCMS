import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'symfocms-admin-theme';
const ORDER = ['auto', 'light', 'dark'];

/*
 * The appearance switch of the admin area. Every click moves on: like the
 * device -> light -> dark. The choice is remembered in this browser; "like
 * the device" removes it again, and the stylesheet follows the system.
 */
export default class extends Controller {
    static targets = ['label'];

    static values = { auto: String, light: String, dark: String };

    connect() {
        this.render(this.current());
    }

    next() {
        const theme = ORDER[(ORDER.indexOf(this.current()) + 1) % ORDER.length];

        try {
            if (theme === 'auto') {
                localStorage.removeItem(STORAGE_KEY);
            } else {
                localStorage.setItem(STORAGE_KEY, theme);
            }
        } catch {
            // Without storage the choice lasts until the page is left.
        }

        if (theme === 'auto') {
            delete document.documentElement.dataset.theme;
        } else {
            document.documentElement.dataset.theme = theme;
        }
        this.render(theme);
    }

    current() {
        const theme = document.documentElement.dataset.theme;

        return theme === 'light' || theme === 'dark' ? theme : 'auto';
    }

    render(theme) {
        this.labelTarget.textContent = this[`${theme}Value`];
    }
}
