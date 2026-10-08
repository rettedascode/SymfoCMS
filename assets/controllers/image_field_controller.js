import { Controller } from '@hotwired/stimulus';
import { el } from '../lib/dom.js';
import { openMediaPicker } from '../lib/media_picker.js';

// A form field that holds the id of one library image, e.g. the cover image of a post.
export default class extends Controller {
    static targets = ['input', 'view'];
    static values = { config: Object };

    connect() {
        this.config = this.configValue;
        this.media = this.config.media;
        this.render();

        // The editor may put back an autosaved value: look up the image that belongs to it.
        this.onRestored = () => this.sync();
        this.element.closest('form')?.addEventListener('editor:restored', this.onRestored);
    }

    disconnect() {
        this.element.closest('form')?.removeEventListener('editor:restored', this.onRestored);
    }

    async choose() {
        const picked = await openMediaPicker(this.config);
        if (picked) {
            this.set(picked);
        }
    }

    remove() {
        this.set(null);
    }

    set(media) {
        this.media = media;
        this.inputTarget.value = media ? String(media.id) : '';
        // Lets the editor know that something changed (unsaved changes, autosave).
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.render();
    }

    async sync() {
        const id = this.inputTarget.value;
        if (id === String(this.media?.id ?? '')) {
            return;
        }

        this.media = null;
        if (id !== '') {
            try {
                const response = await fetch(`${this.config.listUrl}?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
                this.media = (await response.json()).items[0] ?? null;
            } catch {
                this.media = null;
            }
        }
        this.render();
    }

    render() {
        const { i18n } = this.config;
        const button = (label, onClick) => el('button', { type: 'button', class: 'button button--small', onclick: onClick }, label);

        if (!this.media) {
            this.viewTarget.replaceChildren(el('button', { type: 'button', class: 'button', onclick: () => this.choose() }, i18n.choose_image));

            return;
        }

        this.viewTarget.replaceChildren(el('div', { class: 'image-field' },
            el('img', { src: this.media.thumb, alt: this.media.alt || this.media.name }),
            el('div', { class: 'image-field__actions' },
                button(i18n.change_image, () => this.choose()),
                button(i18n.remove_image, () => this.remove()),
            ),
        ));
    }
}
