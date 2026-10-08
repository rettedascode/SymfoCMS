import { Controller } from '@hotwired/stimulus';
import { el } from '../lib/dom.js';
import { imageFiles, uploadFiles } from '../lib/upload.js';

// Media library: upload several images by picking them or dropping them anywhere on the drop zone.
export default class extends Controller {
    static targets = ['dropzone', 'input', 'messages'];
    static values = { url: String, csrf: String, uploading: String, error: String };

    connect() {
        this.onDragOver = (event) => {
            event.preventDefault();
            this.dropzoneTarget.classList.add('is-dragover');
        };
        this.onDragLeave = () => this.dropzoneTarget.classList.remove('is-dragover');
        this.onDrop = (event) => {
            event.preventDefault();
            this.dropzoneTarget.classList.remove('is-dragover');
            this.upload(imageFiles(event.dataTransfer.files));
        };

        // Listen on the whole window so a slightly missed drop does not open the image in the browser.
        window.addEventListener('dragover', this.onDragOver);
        window.addEventListener('dragleave', this.onDragLeave);
        window.addEventListener('drop', this.onDrop);
    }

    disconnect() {
        window.removeEventListener('dragover', this.onDragOver);
        window.removeEventListener('dragleave', this.onDragLeave);
        window.removeEventListener('drop', this.onDrop);
    }

    pick() {
        this.upload(imageFiles(this.inputTarget.files));
        this.inputTarget.value = '';
    }

    async upload(files) {
        if (files.length === 0) {
            return;
        }

        this.messagesTarget.replaceChildren(el('li', { text: this.uploadingValue }));
        const errors = [];
        const uploaded = await uploadFiles(files, {
            url: this.urlValue,
            csrf: this.csrfValue,
            fallbackError: this.errorValue,
            onResult: ({ file, error }) => {
                if (error) {
                    errors.push(el('li', { class: 'upload-messages__error', text: `${file.name}: ${error}` }));
                }
            },
        });

        if (errors.length === 0) {
            window.location.reload();

            return;
        }

        // Keep the error messages visible; the new images appear after the next reload.
        this.messagesTarget.replaceChildren(...errors);
        if (uploaded.length > 0) {
            this.messagesTarget.append(el('li', {}, el('a', { href: window.location.href, text: '↻' })));
        }
    }
}
