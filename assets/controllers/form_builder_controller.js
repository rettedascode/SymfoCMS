import { Controller } from '@hotwired/stimulus';
import { announce, createDragList, focusHandle, handle, t } from '../lib/dnd.js';
import { el, uid } from '../lib/dom.js';
import { History, moveStep } from '../lib/history.js';
import { icon } from '../lib/icons.js';

const TYPES = ['text', 'email', 'textarea', 'select', 'checkbox', 'consent'];

/*
 * Form builder: the list of fields a form consists of.
 *
 * The fields are kept as an array of { id, type, label, required, options }
 * and written into a hidden input as JSON when the form is saved. The server
 * cleans the list up again (see App\Service\FormDefinition).
 */
export default class extends Controller {
    static targets = ['list', 'input', 'add'];
    static values = { config: Object };

    connect() {
        this.i18n = this.configValue.i18n;
        this.fields = structuredClone(this.configValue.fields);
        this.dirty = false;
        this.history = new History();
        this.onDndMove = (event) => this.fieldMoved(event.detail);
        this.element.addEventListener('dnd:move', this.onDndMove);
        this.unbindHistoryKeys = this.history.bindKeys(document, {
            onUndo: () => announce(t('undone')),
            onRedo: () => announce(t('redone')),
        });

        this.addTarget.replaceChildren(...TYPES.map((type) => el('button', {
            type: 'button',
            class: 'button button--small',
            onclick: () => this.add(type),
        }, `+ ${this.i18n[`type_${type}`]}`)));

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
        this.unbindHistoryKeys();
        this.sortable?.destroy();
    }

    /** Wired to the submit event of the surrounding form. */
    submit() {
        this.inputTarget.value = JSON.stringify(this.fields);
        this.submitting = true;
    }

    /** Any change to the other inputs of the form (name, texts, email). */
    changed() {
        this.dirty = true;
    }

    add(type) {
        const field = { id: '', type, label: '', required: type === 'consent', options: type === 'select' ? [''] : [] };
        this.fields.push(field);
        this.record({
            undo: () => this.fields.splice(this.fields.indexOf(field), 1),
            redo: () => this.fields.push(field),
        });
        this.changed();
        this.render();
        // Put the cursor where the question is typed.
        this.listTarget.querySelector('.form-field:last-child .form-field__label')?.focus();
    }

    /** Remembers a change of the structure for Ctrl+Z and Ctrl+Shift+Z. */
    record(step) {
        const redraw = () => {
            this.changed();
            this.render();
        };
        this.history.record({
            undo: () => { step.undo(); redraw(); },
            redo: () => { step.redo(); redraw(); },
        });
    }

    /** The drag-and-drop layer has moved a field on screen: the data follows. */
    fieldMoved(detail) {
        const [moved] = this.fields.splice(detail.oldIndex, 1);
        this.fields.splice(detail.newIndex, 0, moved);
        this.record(moveStep(this.fields, detail.oldIndex, this.fields, detail.newIndex));
        this.changed();
        setTimeout(() => {
            this.render();
            if (detail.cause !== 'pointer') {
                focusHandle([...this.listTarget.children].find((node) => node.field === moved));
            }
        });
    }

    render() {
        this.sortable?.destroy();

        if (this.fields.length === 0) {
            this.listTarget.replaceChildren(el('p', { class: 'blocks__empty', text: this.i18n.empty }));

            return;
        }

        this.listTarget.replaceChildren(...this.fields.map((field, index) => this.renderField(field, index)));
        this.listTarget.setAttribute('aria-label', this.i18n.list);
        this.sortable = createDragList(this.listTarget, { group: 'form-fields' });

    }

    renderField(field, index) {
        const labelId = uid('form-field');
        const button = (label, iconName, onClick, disabled = false) => el('button', {
            type: 'button',
            class: iconName === 'trash' ? 'icon-button icon-button--danger' : 'icon-button',
            title: label,
            'aria-label': label,
            disabled,
            onclick: onClick,
        }, icon(iconName));

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

        const node = el('div', { class: 'form-field', 'data-dnd-item': true, 'data-dnd-label': field.label || this.i18n[`type_${field.type}`] },
            el('div', { class: 'form-field__header' },
                handle(field.label || this.i18n[`type_${field.type}`]),
                el('span', { class: 'form-field__type', text: this.i18n[`type_${field.type}`] }),
                button(this.i18n.delete, 'trash', () => {
                    this.fields.splice(index, 1);
                    this.record({
                        undo: () => this.fields.splice(Math.min(index, this.fields.length), 0, field),
                        redo: () => this.fields.splice(this.fields.indexOf(field), 1),
                    });
                    this.changed();
                    this.render();
                }),
            ),
            el('div', { class: 'field' }, el('label', { for: labelId, text: this.i18n[field.type === 'consent' || field.type === 'checkbox' ? 'label_checkbox' : 'label'] }), label),
            field.type === 'select' ? this.renderOptions(field) : null,
            el('label', { class: 'check' }, required, el('span', { text: this.i18n.required })),
        );
        node.field = field;

        return node;
    }

    /** The choices of a selection field: one per line. */
    renderOptions(field) {
        const id = uid('form-options');
        const input = el('textarea', {
            id,
            rows: 4,
            placeholder: this.i18n.options_placeholder,
            oninput: (event) => {
                field.options = event.target.value.split('\n').map((option) => option.trim()).filter((option) => option !== '');
                this.changed();
            },
        });
        input.value = field.options.join('\n');

        return el('div', { class: 'field' }, el('label', { for: id, text: this.i18n.options }), input, el('p', { class: 'field__help', text: this.i18n.options_help }));
    }
}
