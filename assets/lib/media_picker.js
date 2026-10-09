import { el, format } from './dom.js';
import { icon } from './icons.js';
import { imageFiles, uploadFiles } from './upload.js';

/**
 * Opens the image picker dialog.
 *
 * Resolves with the chosen media item ({ id, thumb, url, alt, name }) or null
 * when the dialog is closed without a choice. With `multiple`, images are
 * ticked one by one and the promise resolves with a list (empty when cancelled).
 */
export function openMediaPicker({ listUrl, uploadUrl, csrf, i18n, multiple = false }) {
    return new Promise((resolve) => {
        let page = 1;
        let chosen = multiple ? [] : null;
        const selected = new Map();

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

        const useButton = el('button', { type: 'button', class: 'button button--primary', hidden: !multiple, disabled: true, onclick: () => {
            chosen = [...selected.values()];
            dialog.close();
        } }, i18n.picker_use);
        dialog.append(el('div', { class: 'dialog__footer', hidden: !multiple }, useButton));

        const choose = (media, button = null) => {
            if (!multiple) {
                chosen = media;
                dialog.close();

                return;
            }

            // Several images: clicking ticks or unticks, the button at the bottom confirms.
            if (selected.has(media.id)) {
                selected.delete(media.id);
            } else {
                selected.set(media.id, media);
            }
            button?.setAttribute('aria-pressed', String(selected.has(media.id)));
            useButton.disabled = selected.size === 0;
            useButton.textContent = selected.size === 0 ? i18n.picker_use : `${i18n.picker_use} (${selected.size})`;
        };

        const tile = (media) => {
            const button = el('button', { type: 'button', class: 'media-tile', title: media.name, 'aria-pressed': multiple ? 'false' : null, onclick: () => choose(media, button) },
                el('img', { src: media.thumb, alt: media.alt || media.name, loading: 'lazy' }),
            );

            return button;
        };

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
                        const added = tile(media);
                        grid.prepend(added);
                        if (multiple) {
                            // Freshly uploaded images are what the person wants to use.
                            choose(media, added);
                        }
                    } else {
                        errors.push(`${file.name}: ${error}`);
                    }
                },
            });
            status.textContent = errors.join(' ');

            // Uploading a single image almost always means "use this one".
            if (!multiple && files.length === 1 && uploaded.length === 1) {
                choose(uploaded[0]);
            } else if (!multiple && uploaded.length > 0 && errors.length === 0) {
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
