import { el, uid } from './dom.js';
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

function richtext({ editor, labelId, value, update }) {
    return createRichText({ value, labelledBy: labelId, i18n: editor.i18n, onChange: update });
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
    return openMediaPicker({
        listUrl: editor.config.urls.mediaList,
        uploadUrl: editor.config.urls.mediaUpload,
        csrf: editor.config.csrf,
        i18n: editor.i18n,
    }).then((picked) => {
        if (picked) {
            editor.media[picked.id] = picked;
        }

        return picked;
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

const RENDERERS = { text, textarea, richtext, select, checkbox, image, blocks, url: text };

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
