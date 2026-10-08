import { el, format } from './dom.js';
import { icon } from './icons.js';
import { imageFiles, uploadFiles } from './upload.js';

/**
 * Opens the image picker dialog.
 *
 * Resolves with the chosen media item ({ id, thumb, url, alt, name }) or null
 * when the dialog is closed without a choice.
 */
export function openMediaPicker({ listUrl, uploadUrl, csrf, i18n }) {
    return new Promise((resolve) => {
        let page = 1;
        let chosen = null;

        const grid = el('div', { class: 'media-grid media-grid--picker' });
        const status = el('p', { class: 'picker__status muted', role: 'status' });
        const moreButton = el('button', { type: 'button', class: 'button', text: i18n.picker_more, hidden: true, onclick: () => load() });
        const fileInput = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/gif,image/webp', multiple: true, class: 'visually-hidden' });
        const dropzone = el('label', { class: 'dropzone dropzone--compact' },
            fileInput,
            el('strong', { text: i18n.picker_upload }),
            el('span', { class: 'muted', text: i18n.picker_drop }),
        );

        const dialog = el('dialog', { class: 'dialog', 'aria-label': i18n.picker_title },
            el('div', { class: 'dialog__header' },
                el('h2', { text: i18n.picker_title }),
                el('button', { type: 'button', class: 'icon-button', 'aria-label': i18n.picker_close, onclick: () => dialog.close() }, icon('close')),
            ),
            el('div', { class: 'dialog__body' }, dropzone, status, grid, el('p', { class: 'picker__more' }, moreButton)),
        );

        const choose = (media) => {
            chosen = media;
            dialog.close();
        };

        const tile = (media) => el('button', { type: 'button', class: 'media-tile', title: media.name, onclick: () => choose(media) },
            el('img', { src: media.thumb, alt: media.alt || media.name, loading: 'lazy' }),
        );

        async function load() {
            status.textContent = i18n.picker_loading;
            try {
                const response = await fetch(`${listUrl}?page=${page}`, { headers: { Accept: 'application/json' } });
                const result = await response.json();
                grid.append(...result.items.map(tile));
                moreButton.hidden = !result.hasMore;
                page += 1;
                status.textContent = grid.children.length === 0 ? i18n.picker_empty : '';
            } catch {
                status.textContent = i18n.picker_error;
            }
        }

        async function upload(files) {
            if (files.length === 0) {
                return;
            }

            const errors = [];
            status.textContent = i18n.picker_uploading;
            const uploaded = await uploadFiles(files, {
                url: uploadUrl,
                csrf,
                fallbackError: i18n.picker_error,
                onResult: ({ file, media, error }) => {
                    if (media) {
                        grid.prepend(tile(media));
                    } else {
                        errors.push(`${file.name}: ${error}`);
                    }
                },
            });
            status.textContent = errors.join(' ');

            // Uploading a single image almost always means "use this one".
            if (files.length === 1 && uploaded.length === 1) {
                choose(uploaded[0]);
            } else if (uploaded.length > 0 && errors.length === 0) {
                status.textContent = format(i18n.picker_uploaded, { count: String(uploaded.length) });
            }
        }

        fileInput.addEventListener('change', () => {
            upload(imageFiles(fileInput.files));
            fileInput.value = '';
        });
        dialog.addEventListener('dragover', (event) => {
            event.preventDefault();
            dropzone.classList.add('is-dragover');
        });
        dialog.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
        dialog.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');
            upload(imageFiles(event.dataTransfer.files));
        });
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(chosen);
        });

        document.body.append(dialog);
        dialog.showModal();
        load();
    });
}
