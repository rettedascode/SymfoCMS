import { Controller } from '@hotwired/stimulus';
import { announce, createDragList, focusHandle, handle, t } from '../lib/dnd.js';
import { el, format, uid } from '../lib/dom.js';
import { History } from '../lib/history.js';
import { icon } from '../lib/icons.js';

const TYPES = ['text', 'email', 'textarea', 'select', 'checkbox', 'consent'];
const MAX_PER_ROW = 3;

/*
 * Form builder.
 *
 * On the left is a palette of field types, on the right the form. The form
 * is a list of rows, and a row holds one to three fields side by side:
 *
 *     this.rows = [[name, email], [message], [consent]]
 *
 * Everything is arranged with the shared drag-and-drop layer (lib/dnd.js):
 * every row is a drag list, and between the rows are empty lists that turn a
 * dropped field into a row of its own. The choices of a selection field are
 * a drag list as well.
 *
 * On save the rows are written into a hidden input as a flat list of fields
 * ({ id, type, label, required, options, join }), where "join" says that a
 * field shares the row with the one before it. The server cleans the list up
 * again (see App\Service\FormDefinition).
 */
export default class extends Controller {
    static targets = ['list', 'input', 'palette'];
    static values = { config: Object };

    connect() {
        this.i18n = this.configValue.i18n;
        this.rows = [];
        for (const field of structuredClone(this.configValue.fields)) {
            if (field.join && this.rows.length > 0 && this.rows.at(-1).length < MAX_PER_ROW) {
                this.rows.at(-1).push(field);
            } else {
                this.rows.push([field]);
            }
        }
        this.dirty = false;
        this.lists = [];
        this.history = new History();

        this.onDndMove = (event) => this.moved(event.detail);
        this.onDndInsert = (event) => this.inserted(event.detail);
        this.element.addEventListener('dnd:move', this.onDndMove);
        this.element.addEventListener('dnd:insert', this.onDndInsert);
        this.unbindHistoryKeys = this.history.bindKeys(document, {
            onUndo: () => announce(t('undone')),
            onRedo: () => announce(t('redone')),
        });

        this.renderPalette();
        this.render();

        this.onBeforeUnload = (event) => {
            if (this.dirty && !this.submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.element.removeEventListener('dnd:move', this.onDndMove);
        this.element.removeEventListener('dnd:insert', this.onDndInsert);
        this.unbindHistoryKeys();
        this.lists.forEach((list) => list.destroy());
    }

    /** Wired to the submit event of the surrounding form. */
    submit() {
        const fields = this.rows.flatMap((row) => row.map((field, index) => ({ ...field, join: index > 0 })));
        this.inputTarget.value = JSON.stringify(fields);
        this.submitting = true;
    }

    /** Any change to the other inputs of the form (name, texts, email). */
    changed() {
        this.dirty = true;
    }

    // ----- Changing the structure, with undo -------------------------------

    /**
     * Runs a change of rows or choices and remembers how things were before, for Ctrl+Z.
     * Fields stay the same objects, so text typed in the meantime is never lost by undoing.
     */
    change(mutate) {
        const snapshot = () => ({ rows: this.rows.map((row) => [...row]), options: new Map(this.rows.flat().map((field) => [field, [...field.options]])) });
        const restore = (state) => {
            this.rows = state.rows.map((row) => [...row]);
            state.options.forEach((options, field) => { field.options = [...options]; });
            this.changed();
            this.render();
        };

        const before = snapshot();
        mutate();
        this.rows = this.rows.filter((row) => row.length > 0);
        const after = snapshot();
        this.history.record({ undo: () => restore(before), redo: () => restore(after) });
        this.changed();
    }

    newField(type) {
        return { id: '', type, label: '', required: type === 'consent', options: type === 'select' ? ['', ''] : [] };
    }

    /** A field type was clicked in the palette: it becomes a new row at the end. */
    paletteAdd(event) {
        const field = this.newField(event.detail.type);
        this.change(() => this.rows.push([field]));
        this.render();
        this.focusLabel(field);
        announce(format(this.i18n.added, { type: this.i18n[`type_${field.type}`] }));
    }

    /** A field type was dragged out of the palette into a row or between two rows. */
    inserted(detail) {
        const type = detail.source.dataset.paletteType;
        if (!TYPES.includes(type)) {
            return;
        }

        const field = this.newField(type);
        this.change(() => this.place(field, detail.to, detail.index));
        this.render();
        this.focusLabel(field);
    }

    /** The drag-and-drop layer has moved a field or a choice on screen: the data follows. */
    moved(detail) {
        if (detail.to.optionsOf) {
            const field = detail.to.optionsOf;
            this.change(() => {
                const [option] = field.options.splice(detail.oldIndex, 1);
                field.options.splice(detail.newIndex, 0, option);
            });
            setTimeout(() => {
                this.render();
                if (detail.cause !== 'pointer') {
                    focusHandle(this.listTarget.querySelector(`[data-options-of="${field.uid}"] > :nth-child(${detail.newIndex + 1})`));
                }
            });

            return;
        }

        const field = detail.item.field;
        if (!field) {
            return;
        }

        this.change(() => {
            const from = this.rows.find((row) => row.includes(field));
            from.splice(from.indexOf(field), 1);
            this.place(field, detail.to, detail.newIndex);
        });
        setTimeout(() => {
            this.render();
            if (detail.cause !== 'pointer') {
                focusHandle([...this.listTarget.querySelectorAll('.form-field')].find((node) => node.field === field));
            }
        });
    }

    /**
     * Puts a field where it was dropped: into a row, or - for the gaps between rows - into a new row.
     * Rows that end up empty are removed afterwards by change().
     */
    place(field, target, index) {
        if (target.row) {
            target.row.splice(Math.min(index, target.row.length), 0, field);
        } else {
            // "before" is the row the gap sits in front of; null for the gap at the end.
            const position = target.before ? this.rows.indexOf(target.before) : this.rows.length;
            this.rows.splice(position === -1 ? this.rows.length : position, 0, [field]);
        }
    }

    focusLabel(field) {
        [...this.listTarget.querySelectorAll('.form-field')].find((node) => node.field === field)?.querySelector('.form-field__label')?.focus();
    }

    // ----- Rendering ---------------------------------------------------------

    renderPalette() {
        this.paletteTarget.replaceChildren(...TYPES.map((type) => el('li', {
            class: 'palette__item',
            'data-dnd-item': true,
            'data-dnd-label': this.i18n[`type_${type}`],
            'data-palette-type': type,
        }, el('button', {
            type: 'button',
            class: 'palette__button',
            'data-dnd-handle': true,
            title: format(this.i18n.palette_add, { type: this.i18n[`type_${type}`] }),
        }, el('span', { class: 'block-menu__icon', 'aria-hidden': 'true' }, icon('plus')), el('span', { text: this.i18n[`type_${type}`] })))));
    }

    render() {
        this.lists.forEach((list) => list.destroy());
        this.lists = [];

        const nodes = [];
        this.rows.forEach((row, index) => {
            nodes.push(this.renderGap(row), this.renderRow(row, index));
        });
        nodes.push(this.renderGap(null));
        if (this.rows.length === 0) {
            nodes.unshift(el('p', { class: 'blocks__empty', text: this.i18n.empty }));
        }

        this.listTarget.replaceChildren(...nodes);
    }

    /** The space between two rows: a field dropped here gets a row of its own. */
    renderGap(before) {
        const gap = el('div', {
            class: 'form-gap',
            'aria-label': this.i18n.list_new_row,
            'data-dnd-empty-hint': this.i18n.drop_new_row,
        });
        gap.before = before;
        this.lists.push(createDragList(gap, { group: 'form-fields' }));

        return gap;
    }

    renderRow(row, index) {
        const node = el('div', {
            class: `form-row form-row--${row.length}`,
            'aria-label': format(this.i18n.list_row, { number: String(index + 1) }),
        });
        node.row = row;
        row.forEach((field) => node.append(this.renderField(field, row)));

        this.lists.push(createDragList(node, {
            group: 'form-fields',
            // At most three fields side by side. Moving inside the row is always fine.
            accept: (item) => row.length < MAX_PER_ROW || row.includes(item.field),
        }));

        return node;
    }

    renderField(field, row) {
        field.uid ??= uid('field');
        const labelId = uid('form-field');
        const name = () => field.label || this.i18n[`type_${field.type}`];

        const label = el('input', {
            type: 'text',
            id: labelId,
            class: 'form-field__label',
            maxlength: '300',
            placeholder: this.i18n[`placeholder_${field.type}`] ?? this.i18n.placeholder_text,
            oninput: (event) => {
                field.label = event.target.value;
                this.changed();
            },
        });
        label.value = field.label;

        const required = el('input', {
            type: 'checkbox',
            checked: field.required,
            // Consent that could be skipped would be pointless, so it is always required.
            disabled: field.type === 'consent',
            onchange: (event) => {
                field.required = event.target.checked;
                this.changed();
            },
        });

        const node = el('div', { class: 'form-field', 'data-dnd-item': true, 'data-dnd-label': name(), 'data-field-type': field.type },
            el('div', { class: 'form-field__header' },
                handle(name()),
                el('span', { class: 'form-field__type', text: this.i18n[`type_${field.type}`] }),
                el('button', {
                    type: 'button',
                    class: 'icon-button icon-button--danger',
                    title: this.i18n.delete,
                    'aria-label': this.i18n.delete,
                    onclick: () => {
                        this.change(() => row.splice(row.indexOf(field), 1));
                        this.render();
                    },
                }, icon('trash')),
            ),
            el('div', { class: 'field' }, el('label', { for: labelId, text: this.i18n[field.type === 'consent' || field.type === 'checkbox' ? 'label_checkbox' : 'label'] }), label),
            field.type === 'select' ? this.renderOptions(field) : null,
            el('label', { class: 'check' }, required, el('span', { text: this.i18n.required })),
        );
        node.field = field;

        return node;
    }

    /** The choices of a selection field: one input per choice, arranged by dragging. */
    renderOptions(field) {
        const list = el('ul', {
            class: 'form-options',
            'aria-label': format(this.i18n.list_options, { field: field.label || this.i18n.type_select }),
            'data-options-of': field.uid,
        });
        list.optionsOf = field;

        field.options.forEach((option, index) => {
            const input = el('input', {
                type: 'text',
                maxlength: '150',
                'aria-label': format(this.i18n.option_number, { number: String(index + 1) }),
                placeholder: format(this.i18n.option_number, { number: String(index + 1) }),
                oninput: (event) => {
                    field.options[index] = event.target.value;
                    item.setAttribute('data-dnd-label', event.target.value || format(this.i18n.option_number, { number: String(index + 1) }));
                    this.changed();
                },
                onkeydown: (event) => {
                    // Enter in the last choice starts the next one, like in a list.
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        this.addOption(field);
                    }
                },
            });
            input.value = option;

            const item = el('li', { class: 'form-options__item', 'data-dnd-item': true, 'data-dnd-label': option || format(this.i18n.option_number, { number: String(index + 1) }) },
                handle(option || format(this.i18n.option_number, { number: String(index + 1) })),
                input,
                el('button', {
                    type: 'button',
                    class: 'icon-button icon-button--danger',
                    title: this.i18n.option_delete,
                    'aria-label': this.i18n.option_delete,
                    disabled: field.options.length <= 1,
                    onclick: () => {
                        this.change(() => field.options.splice(index, 1));
                        this.render();
                    },
                }, icon('trash')),
            );
            list.append(item);
        });

        // Each field has its own group: choices do not travel between fields.
        this.lists.push(createDragList(list, { group: `options-${field.uid}` }));

        return el('div', { class: 'field' },
            el('span', { class: 'form-field__options-label', text: this.i18n.options }),
            list,
            el('button', { type: 'button', class: 'button button--small', onclick: () => this.addOption(field) }, `+ ${this.i18n.option_add}`),
        );
    }

    addOption(field) {
        this.change(() => field.options.push(''));
        this.render();
        this.listTarget.querySelector(`[data-options-of="${field.uid}"] > :last-child input`)?.focus();
    }
}
