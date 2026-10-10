import { Controller } from '@hotwired/stimulus';
import { el } from '../lib/dom.js';
import { announce, t, watchFileDrops } from '../lib/dnd.js';
import { checkFiles, uploadFiles } from '../lib/upload.js';

/*
 * Files dropped from the desktop are uploaded to the media library.
 *
 *     <div data-controller="dropzone"
 *          data-dropzone-url-value="/admin/media/upload" data-dropzone-csrf-value="…"
 *          data-dropzone-max-bytes-value="8388608" data-dropzone-types-value='["image/jpeg","image/png"]'>
 *         <input type="file" multiple data-dropzone-target="input" data-action="dropzone#pick">
 *         <ul data-dropzone-target="messages" role="status"></ul>
 *     </div>
 *
 * While files hover over the element it gets the class "is-dragover". Files
 * of the wrong type or size are refused with a message before anything is
 * sent. Every other file shows its progress in the messages list.
 *
 * Events for the screen around it:
 *   dropzone:files     before uploading, cancelable; detail: { files, event }
 *   dropzone:uploaded  after all uploads; detail: { media: [...], errors: [...], event }
 *
 * With data-dropzone-window-value="true" a drop anywhere in the window
 * counts, so a slightly missed drop does not open the image in the browser.
 */
export default class extends Controller {
    static targets = ['input', 'messages'];

    static values = {
        url: String,
        csrf: String,
        maxBytes: { type: Number, default: 8 * 1024 * 1024 },
        types: { type: Array, default: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] },
        window: Boolean,
        // Extra form fields sent with every file, e.g. { folder: 3 }.
        fields: { type: Object, default: {} },
    };

    connect() {
        this.stop = watchFileDrops(this.windowValue ? window : this.element, {
            onOver: (over) => this.element.classList.toggle('is-dragover', over),
            onFiles: (files, event) => this.receive(files, event),
        });
    }

    disconnect() {
        this.stop();
    }

    /** Files chosen with the file dialog take the same way as dropped ones. */
    pick() {
        this.receive([...this.inputTarget.files], null);
        this.inputTarget.value = '';
    }

    async receive(files, event) {
        if (files.length === 0 || this.dispatch('files', { detail: { files, event }, cancelable: true }).defaultPrevented) {
            return;
        }

        const { accepted, rejected } = checkFiles(files, {
            maxBytes: this.maxBytesValue,
            types: this.typesValue,
            messages: { too_large: t('file_too_large'), wrong_type: t('file_wrong_type') },
        });
        const errors = rejected.map(({ file, error }) => ({ file, error }));
        this.showMessages(errors, null);

        const media = await uploadFiles(accepted, {
            url: this.uploadUrl(),
            csrf: this.csrfValue,
            fallbackError: t('upload_failed'),
            onProgress: (progress) => this.showMessages(errors, progress),
            onResult: ({ file, error }) => {
                if (error) {
                    errors.push({ file, error: `${file.name}: ${error}` });
                }
            },
        });

        this.showMessages(errors, null);
        if (errors.length > 0) {
            announce(errors.map(({ error }) => error).join(' '));
        }
        this.dispatch('uploaded', { detail: { media, errors, event } });
    }

    uploadUrl() {
        const url = new URL(this.urlValue, window.location.href);
        Object.entries(this.fieldsValue).forEach(([name, value]) => url.searchParams.set(name, String(value)));

        return url.toString();
    }

    showMessages(errors, progress) {
        if (!this.hasMessagesTarget) {
            return;
        }

        const lines = errors.map(({ error }) => el('li', { class: 'upload-messages__error', text: error }));
        if (progress) {
            const percent = Math.round(progress.fraction * 100);
            lines.unshift(el('li', { class: 'upload-messages__progress' },
                el('span', { text: t('uploading', { name: progress.file.name, current: String(progress.index + 1), total: String(progress.total) }) }),
                el('progress', { max: '100', value: String(percent), 'aria-label': progress.file.name }),
            ));
        }
        this.messagesTarget.replaceChildren(...lines);
    }
}
