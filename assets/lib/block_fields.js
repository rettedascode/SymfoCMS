import { el, uid } from './dom.js';
import { icon } from './icons.js';
import { openMediaPicker } from './media_picker.js';
import { createRichText } from './richtext.js';

/*
 * Form controls for the fields of a block (see App\Block\Field).
 *
 * Every field type has one function below. It receives a context object
 *
 *     { editor, block, field, path, id, labelId, value, update }
 *
 * and returns the control element. `update(newValue)` stores a value and marks
 * the content as changed; `editor.render()` rebuilds the block list after a
 * structural change. Label, help text and validation message are added around
 * the control by renderField(), unless the function returns { element, bare: true }.
 */

function text({ id, field, value, update }) {
    const input = el('input', { id, type: 'text', placeholder: field.placeholder, oninput: (event) => update(event.target.value) });
    input.value = value ?? '';

    return input;
}

function textarea({ id, field, value, update }) {
    const input = el('textarea', { id, rows: 3, placeholder: field.placeholder, oninput: (event) => update(event.target.value) });
    input.value = value ?? '';

    return input;
}

function richtext({ editor, block, labelId, value, update }) {
    // The slash command belongs to plain text blocks: blocks whose only field is this text.
    const isTextBlock = editor.types.get(block.type)?.fields.length === 1;

    return createRichText({
        value,
        labelledBy: labelId,
        i18n: editor.i18n,
        onChange: update,
        onSlash: isTextBlock ? () => editor.openSlashMenu(block) : null,
    });
}

function select({ id, field, value, update }) {
    return el('select', { id, onchange: (event) => update(event.target.value) },
        ...field.options.map((option) => el('option', { value: option.value, text: option.label, selected: option.value === value })),
    );
}

function checkbox({ id, field, value, update, help, error }) {
    const input = el('input', { id, type: 'checkbox', checked: Boolean(value), onchange: (event) => update(event.target.checked) });

    return {
        bare: true,
        element: el('div', { class: 'field field--check' },
            el('label', { class: 'check' }, input, el('span', { text: field.label })),
            help(),
            error(),
        ),
    };
}

/** Opens the media library and resolves with the chosen image (or null). */
export function pickImage(editor) {
    return pickImages(editor, false).then((picked) => picked[0] ?? null);
}

/** Opens the media library and resolves with the list of chosen images (possibly empty). */
export function pickImages(editor, multiple = true) {
    return openMediaPicker({
        listUrl: editor.config.urls.mediaList,
        uploadUrl: editor.config.urls.mediaUpload,
        csrf: editor.config.csrf,
        i18n: editor.i18n,
        multiple,
    }).then((picked) => {
        const list = Array.isArray(picked) ? picked : (picked ? [picked] : []);
        list.forEach((media) => { editor.media[media.id] = media; });

        return list;
    });
}

function image({ editor, block, field }) {
    const media = editor.media[block.data[field.name]];
    const choose = async () => {
        const picked = await pickImage(editor);
        if (!picked) {
            return;
        }

        block.data[field.name] = picked.id;
        // Start with the description from the media library; it can be adjusted per block.
        if ('alt' in block.data && !block.data.alt) {
            block.data.alt = picked.alt;
        }
        editor.render();
        editor.changed();
    };
    const remove = () => {
        block.data[field.name] = null;
        editor.render();
        editor.changed();
    };

    if (!media) {
        return el('div', { class: 'image-field image-field--empty' },
            block.data[field.name] ? el('p', { class: 'field__help', text: editor.i18n.image_missing }) : null,
            el('button', { type: 'button', class: 'button', onclick: choose }, editor.i18n.choose_image),
        );
    }

    return el('div', { class: 'image-field' },
        el('img', { src: media.thumb, alt: media.alt || media.name }),
        el('div', { class: 'image-field__actions' },
            el('button', { type: 'button', class: 'button button--small', onclick: choose }, editor.i18n.change_image),
            el('button', { type: 'button', class: 'button button--small', onclick: remove }, editor.i18n.remove_image),
        ),
    );
}

function blocks({ editor, block, field, path, error }) {
    if (!Array.isArray(block.data[field.name])) {
        block.data[field.name] = [];
    }
    const container = el('div', { class: 'blocks blocks--nested' });
    editor.renderList(container, block.data[field.name], true, `${path}.${field.name}.`);

    return {
        bare: true,
        element: el('div', { class: 'field field--blocks' }, el('span', { class: 'field__label', text: field.label }), container, error()),
    };
}

/** Chooses which shared block to show. Its content is edited in the "Shared blocks" section. */
function sharedBlock({ editor, id, value, update }) {
    const available = editor.config.sharedBlocks ?? [];
    if (available.length === 0) {
        return el('p', { class: 'field__help', text: editor.i18n.shared_none });
    }

    const current = available.find((candidate) => candidate.id === value);
    const choice = el('select', {
        id,
        onchange: (event) => {
            update(event.target.value === '' ? null : Number(event.target.value));
            editor.render();
        },
    },
    el('option', { value: '', text: editor.i18n.shared_choose }),
    ...available.map((candidate) => el('option', { value: String(candidate.id), text: candidate.name, selected: candidate.id === value })),
    );

    return el('div', { class: 'shared-field' },
        choice,
        current ? el('a', { class: 'button button--small', href: current.editUrl, target: '_blank', rel: 'noopener' }, `${editor.i18n.shared_edit} ↗`) : null,
        el('p', { class: 'field__help', text: editor.i18n.shared_hint }),
    );
}

/** A small icon button used by the list-like fields below. */
function smallButton(label, iconName, onClick, disabled = false) {
    return el('button', { type: 'button', class: 'icon-button', title: label, 'aria-label': label, disabled, onclick: onClick }, icon(iconName));
}

/** Moves an entry of a list field and redraws the editor. */
function moveEntry(editor, list, index, delta) {
    const [entry] = list.splice(index, 1);
    list.splice(index + delta, 0, entry);
    editor.render();
    editor.changed();
}

/** Several images in a chosen order, e.g. for a gallery. */
function images({ editor, block, field }) {
    if (!Array.isArray(block.data[field.name])) {
        block.data[field.name] = [];
    }
    const ids = block.data[field.name];
    const add = async () => {
        const picked = await pickImages(editor);
        if (picked.length === 0) {
            return;
        }
        ids.push(...picked.map((media) => media.id));
        editor.render();
        editor.changed();
    };

    return el('div', { class: 'images-field' },
        el('ul', { class: 'images-field__list' },
            ...ids.map((id, index) => {
                const media = editor.media[id];

                return el('li', {},
                    media ? el('img', { src: media.thumb, alt: media.alt || media.name }) : el('span', { class: 'images-field__missing', text: '?' }),
                    el('span', { class: 'images-field__actions' },
                        smallButton(editor.i18n.move_left, 'left', () => moveEntry(editor, ids, index, -1), index === 0),
                        smallButton(editor.i18n.move_right, 'right', () => moveEntry(editor, ids, index, 1), index === ids.length - 1),
                        smallButton(editor.i18n.remove_image, 'close', () => {
                            ids.splice(index, 1);
                            editor.render();
                            editor.changed();
                        }),
                    ),
                );
            }),
        ),
        el('button', { type: 'button', class: 'button', onclick: add }, editor.i18n.add_images),
    );
}

/** A list of entries, each with its own small form (e.g. question and answer). */
function items({ editor, block, field, path, help, error }) {
    if (!Array.isArray(block.data[field.name])) {
        block.data[field.name] = [];
    }
    const entries = block.data[field.name];
    const emptyEntry = () => Object.fromEntries(field.fields.map((sub) => [sub.name, structuredClone(sub.default)]));

    return {
        bare: true,
        element: el('div', { class: 'field items-field' },
            ...entries.map((entry, index) => el('div', { class: 'items-field__entry' },
                el('div', { class: 'items-field__header' },
                    el('span', { class: 'items-field__number', text: `${field.label} ${index + 1}` }),
                    smallButton(editor.i18n.move_up, 'up', () => moveEntry(editor, entries, index, -1), index === 0),
                    smallButton(editor.i18n.move_down, 'down', () => moveEntry(editor, entries, index, 1), index === entries.length - 1),
                    smallButton(editor.i18n.delete, 'trash', () => {
                        entries.splice(index, 1);
                        editor.render();
                        editor.changed();
                    }),
                ),
                // An entry behaves like a tiny block of its own: { data } is all a field control needs.
                ...field.fields.map((sub) => renderField(editor, { type: '', data: entry }, sub, `${path}.${field.name}.${index}`)),
            )),
            el('button', {
                type: 'button',
                class: 'blocks__add blocks__add--nested',
                onclick: () => {
                    entries.push(emptyEntry());
                    editor.render();
                    editor.changed();
                },
            }, `+ ${editor.i18n.add_item}`),
            help(),
            error(),
        ),
    };
}

function code({ id, value, update }) {
    const input = el('textarea', { id, class: 'code-input', rows: 8, spellcheck: 'false', autocapitalize: 'off', autocomplete: 'off', oninput: (event) => update(event.target.value) });
    input.value = value ?? '';

    return input;
}

/**
 * A place on the map, found by typing an address. The search is answered by
 * OpenStreetMap (Nominatim) and only runs when "Find" is pressed.
 */
function location({ editor, block, field, id, value, update }) {
    const search = el('input', { id, type: 'search', placeholder: editor.i18n.map_search_placeholder, autocomplete: 'off' });
    const status = el('p', { class: 'field__help', role: 'status' });
    const describe = (place) => (place ? `${editor.i18n.map_found} ${place.lat}, ${place.lon}` : editor.i18n.map_none);
    status.textContent = describe(value);

    const find = async () => {
        const query = search.value.trim();
        if (query === '') {
            search.focus();

            return;
        }

        status.textContent = editor.i18n.map_searching;
        try {
            const response = await fetch(`https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            const [place] = await response.json();
            if (!place) {
                status.textContent = editor.i18n.map_not_found;

                return;
            }

            const found = { lat: Number(Number(place.lat).toFixed(6)), lon: Number(Number(place.lon).toFixed(6)) };
            update(found);
            // Offer the address as the text shown with the map, unless something was typed there already.
            if ('label' in block.data && !block.data.label) {
                block.data.label = query;
                editor.render();
            } else {
                status.textContent = describe(found);
            }
        } catch {
            status.textContent = editor.i18n.map_error;
        }
    };
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            find();
        }
    });

    return el('div', { class: 'location-field' },
        el('div', { class: 'inline-form' }, el('div', { class: 'field' }, search), el('button', { type: 'button', class: 'button', onclick: find }, editor.i18n.map_find)),
        status,
        el('p', { class: 'field__help', text: editor.i18n.map_privacy }),
    );
}

/** Chooses which form to show. Forms are built in the "Forms" section. */
function form({ editor, id, value, update }) {
    const available = editor.config.forms ?? [];
    const manage = editor.config.formsUrl
        ? el('a', { class: 'button button--small', href: editor.config.formsUrl, target: '_blank', rel: 'noopener' }, `${editor.i18n.forms_manage} ↗`)
        : null;

    if (available.length === 0) {
        return el('div', { class: 'shared-field' }, el('p', { class: 'field__help', text: editor.i18n.forms_none }), manage);
    }

    return el('div', { class: 'shared-field' },
        el('select', { id, onchange: (event) => update(event.target.value === '' ? null : Number(event.target.value)) },
            el('option', { value: '', text: editor.i18n.forms_choose }),
            ...available.map((candidate) => el('option', { value: String(candidate.id), text: candidate.name, selected: candidate.id === value })),
        ),
        manage,
    );
}

const RENDERERS = {
    form,    text, textarea, richtext, select, checkbox, image, images, items, code, location, blocks,
    url: text,
    shared_block: sharedBlock,
};

export function renderField(editor, block, field, path) {
    const id = uid('block-field');
    const labelId = `${id}-label`;
    const message = editor.errors[`${path}:${field.name}`];
    const error = () => (message ? el('ul', { class: 'field__errors', role: 'alert' }, el('li', { text: message })) : null);
    const help = () => (field.help ? el('p', { class: 'field__help', text: field.help }) : null);
    const update = (newValue) => {
        block.data[field.name] = newValue;
        editor.changed();
    };

    const render = RENDERERS[field.type] ?? text;
    const result = render({ editor, block, field, path, id, labelId, value: block.data[field.name], update, help, error });
    if (result.bare) {
        return result.element;
    }

    return el('div', { class: message ? 'field field--error' : 'field' },
        el('label', { id: labelId, for: id, text: field.required ? `${field.label} *` : field.label }),
        result,
        help(),
        error(),
    );
}
