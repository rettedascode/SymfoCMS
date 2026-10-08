/**
 * Creates a DOM element.
 *
 *     el('button', { class: 'button', type: 'button', onclick: save }, 'Save')
 *
 * Attributes starting with "on" are added as event listeners, "text" sets the
 * text content, and null/false attributes are skipped.
 */
export function el(tag, attributes = {}, ...children) {
    const node = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }
        if (name.startsWith('on') && typeof value === 'function') {
            node.addEventListener(name.slice(2), value);
        } else if (name === 'text') {
            node.textContent = value;
        } else if (name === 'class') {
            node.className = value;
        } else {
            node.setAttribute(name, value === true ? '' : value);
        }
    }

    node.append(...children.filter((child) => child !== null && child !== undefined && child !== false));

    return node;
}

let counter = 0;

/** Returns a unique id for linking labels and inputs. */
export function uid(prefix = 'f') {
    counter += 1;

    return `${prefix}-${counter}`;
}

/** Replaces %name% placeholders in a translated message. */
export function format(message, parameters = {}) {
    return Object.entries(parameters).reduce(
        (text, [name, value]) => text.replaceAll(`%${name}%`, value),
        message ?? '',
    );
}
