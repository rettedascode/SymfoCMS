import Sortable from 'sortablejs';
import { el, format } from './dom.js';
import { icon } from './icons.js';

/*
 * The one drag-and-drop layer of the admin area.
 *
 * Everything that can be arranged by dragging - blocks, menu entries, form
 * fields, pages, folders - is a "drag list" made with createDragList(). Lists
 * with the same group name exchange items; a list inside an item makes a tree.
 *
 * Each list gets, without any code of its own:
 *   - dragging with mouse, pen and touch (SortableJS) with a preview, a marked
 *     landing spot, a "not allowed" state and automatic scrolling,
 *   - the same moves with the keyboard: Space picks an item up, the arrow keys
 *     carry it, Space drops it, Escape puts it back,
 *   - a small menu on the handle: move up, move down, move to ...,
 *   - announcements for screen readers.
 *
 * A finished move is reported once, as a "dnd:move" event on the list the item
 * ended up in. The event detail carries everything a screen needs to store the
 * change, and revert() to put the item back should the server refuse it.
 * Items dragged out of a palette arrive as "dnd:insert" instead.
 *
 * Markup contract:
 *   - items are direct children of the list and carry [data-dnd-item],
 *   - the handle inside an item is a <button data-dnd-handle>, see handle(),
 *   - [data-dnd-label] on an item is its name for announcements,
 *   - aria-label on a list names it in "Move to ...".
 *
 * The Stimulus controllers sortable, tree, palette and dropzone are thin
 * wrappers around this file for use from templates.
 */

const ITEM = '[data-dnd-item]';
const HANDLE = '[data-dnd-handle]';

/** @type {Map<Element, DragList>} */
const registry = new Map();
let carrying = null;
let openMenu = null;
let texts = null;
let liveRegion = null;
let activeToast = null;
let toastTimer = null;
let suppressClickUntil = 0;
// True while the pointer is over a list that refuses the dragged item: letting go there cancels the drag.
let overRejectingList = false;

export const prefersReducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

/** Interface text of the layer; the admin layout prints it as JSON (templates/admin/_dnd.html.twig). */
export function t(key, parameters = {}) {
    if (texts === null) {
        try {
            texts = JSON.parse(document.getElementById('dnd-i18n')?.textContent ?? '{}');
        } catch {
            texts = {};
        }
    }

    return format(texts[key] ?? key, parameters);
}

/** Says something to screen reader users without moving the focus. */
export function announce(message) {
    if (!liveRegion?.isConnected) {
        liveRegion = el('div', { class: 'visually-hidden', role: 'status', 'aria-live': 'assertive', 'aria-atomic': 'true', 'data-dnd-live': true });
        document.body.append(liveRegion);
    }
    // The same text twice in a row would not be read again.
    liveRegion.textContent = '';
    setTimeout(() => { liveRegion.textContent = message; }, 30);
}

/**
 * A short message at the bottom of the screen, optionally with one button such as "Undo".
 */
export function toast(message, { actionLabel = null, onAction = null, type = 'info', duration = 8000 } = {}) {
    hideToast();
    activeToast = el('div', { class: `toast toast--${type}`, role: type === 'error' ? 'alert' : 'status' },
        el('span', { text: message }),
        actionLabel ? el('button', {
            type: 'button',
            class: 'link-button',
            text: actionLabel,
            'data-toast-action': true,
            onclick: () => {
                hideToast();
                onAction?.();
            },
        }) : null,
    );
    document.body.append(activeToast);
    toastTimer = setTimeout(hideToast, duration);

    return activeToast;
}

export function hideToast() {
    clearTimeout(toastTimer);
    activeToast?.remove();
    activeToast = null;
}

/**
 * The handle of a draggable item: drag it, press Space to carry the item with the arrow keys,
 * or click it (Enter) for the move menu.
 */
export function handle(label = '') {
    return el('button', {
        type: 'button',
        class: 'dnd-handle',
        'data-dnd-handle': true,
        'aria-label': t('handle', { item: label }).trim(),
        'aria-haspopup': 'menu',
        title: t('handle_hint'),
    }, icon('grip'));
}

/** After a screen has drawn its list again: put the focus back on the handle of the moved item. */
export function focusHandle(item) {
    item?.querySelector(`:scope ${HANDLE}`)?.focus({ preventScroll: true });
    item?.scrollIntoView?.({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
}

/**
 * Turns an element into a drag list.
 *
 * @param {Element} element
 * @param {object}  options
 * @param {string}  options.group    lists with the same group exchange items
 * @param {string|function(Element, Element): boolean} [options.accept] which items may be dropped here:
 *                                    a selector the item has to match, or a function (item, list)
 * @param {boolean} [options.sort]   false for a palette: its own order is fixed
 * @param {boolean} [options.clone]  true for a palette: dragging copies instead of moving
 * @param {boolean} [options.put]    false when nothing may be dropped here
 *
 * @returns {{destroy: function(): void}}
 */
export function createDragList(element, options = {}) {
    registry.get(element)?.destroy();
    const list = new DragList(element, options);
    registry.set(element, list);

    return list;
}

class DragList {
    constructor(element, { group = 'default', accept = null, sort = true, clone = false, put = true } = {}) {
        this.el = element;
        this.group = group;
        this.accept = accept;
        this.clone = clone;
        this.put = put;

        this.sortable = Sortable.create(element, {
            group: { name: group, pull: clone ? 'clone' : true, put: true },
            sort,
            draggable: ITEM,
            handle: HANDLE,
            animation: prefersReducedMotion() ? 0 : 150,
            // The item being dragged follows the pointer as a preview; its slot in the list shows where it will land.
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 4,
            ghostClass: 'dnd-placeholder',
            chosenClass: 'dnd-chosen',
            dragClass: 'dnd-preview',
            // A short press on touch screens, so swiping over a handle still scrolls the page.
            delay: 200,
            delayOnTouchOnly: true,
            touchStartThreshold: 6,
            // Near the top and bottom edge the page (or the scrolling container) moves along.
            scroll: true,
            bubbleScroll: true,
            scrollSensitivity: 90,
            scrollSpeed: 18,
            onStart: () => {
                closeMenu();
                overRejectingList = false;
                document.documentElement.classList.add('dnd-dragging');
            },
            onMove: (event) => this.onPointerMove(event),
            onEnd: (event) => this.onPointerEnd(event),
        });

        this.onKeydown = (event) => this.keydown(event);
        this.onClick = (event) => this.click(event);
        this.onFocusOut = (event) => {
            // Leaving the handle while carrying puts the item back: nothing moves by accident.
            if (carrying && carrying.item.parentElement === this.el && event.target.matches?.(HANDLE) && !carrying.moving) {
                cancelCarry();
            }
        };
        element.addEventListener('keydown', this.onKeydown);
        element.addEventListener('click', this.onClick);
        element.addEventListener('focusout', this.onFocusOut);
    }

    destroy() {
        this.sortable.destroy();
        this.el.removeEventListener('keydown', this.onKeydown);
        this.el.removeEventListener('click', this.onClick);
        this.el.removeEventListener('focusout', this.onFocusOut);
        if (registry.get(this.el) === this) {
            registry.delete(this.el);
        }
    }

    accepts(item) {
        if (!this.put || this.clone || item.contains(this.el)) {
            return false;
        }
        if (typeof this.accept === 'function') {
            return this.accept(item, this.el) !== false;
        }

        return !this.accept || item.matches(this.accept);
    }

    // ----- pointer ---------------------------------------------------------

    onPointerMove(event) {
        const target = registry.get(event.to);
        clearRejection();

        if (target && !target.accepts(event.dragged)) {
            // Shows "not here": the list is marked and the cursor changes.
            event.to.classList.add('dnd-rejects');
            document.documentElement.classList.add('dnd-not-allowed');
            overRejectingList = true;

            return false;
        }

        overRejectingList = false;

        return true;
    }

    onPointerEnd(event) {
        document.documentElement.classList.remove('dnd-dragging');
        clearRejection();
        // The browser sends a click to the handle after a drag; that must not open the menu.
        suppressClickUntil = Date.now() + 300;
        const cancelled = overRejectingList;
        overRejectingList = false;

        if (cancelled) {
            // Dropped on "not allowed": everything stays as it was, wherever the item passed on its way.
            if (event.clone?.isConnected) {
                event.clone.replaceWith(event.item);
            } else {
                const siblings = items(event.from).filter((sibling) => sibling !== event.item);
                event.from.insertBefore(event.item, siblings[event.oldDraggableIndex] ?? null);
            }
            announce(t('not_allowed'));

            return;
        }

        if (event.pullMode === 'clone') {
            // The palette keeps its own item; the target only learns where something was dropped.
            const source = event.item;
            const index = event.newDraggableIndex;
            if (event.clone?.isConnected) {
                event.clone.replaceWith(source);
            } else {
                event.from.append(source);
            }
            dispatch(event.to, 'dnd:insert', { source, to: event.to, index, cause: 'pointer' });

            return;
        }

        if (event.from === event.to && event.oldDraggableIndex === event.newDraggableIndex) {
            return;
        }

        const origin = { list: event.from, index: event.oldDraggableIndex };
        report(event.item, origin, 'pointer');
    }

    // ----- keyboard --------------------------------------------------------

    keydown(event) {
        const handleElement = event.target.closest?.(HANDLE);
        const item = handleElement?.closest(ITEM);
        if (!item || item.parentElement !== this.el) {
            return;
        }

        if (!carrying) {
            if (event.key === ' ' || event.key === 'Spacebar') {
                event.preventDefault();
                startCarry(item, handleElement);
            }

            return;
        }

        const steps = { ArrowUp: -1, ArrowDown: 1 };
        if (event.key in steps) {
            event.preventDefault();
            carry(steps[event.key]);
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            carrySideways(event.key === 'ArrowRight');
        } else if (event.key === ' ' || event.key === 'Spacebar' || event.key === 'Enter') {
            event.preventDefault();
            dropCarry();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            cancelCarry();
        } else if (event.key === 'Tab') {
            dropCarry();
        }
    }

    click(event) {
        const handleElement = event.target.closest?.(HANDLE);
        const item = handleElement?.closest(ITEM);
        if (!item || item.parentElement !== this.el || carrying || Date.now() < suppressClickUntil) {
            return;
        }

        event.preventDefault();
        if (openMenu?.item === item) {
            closeMenu();
        } else {
            showMenu(item, handleElement);
        }
    }
}

function clearRejection() {
    document.documentElement.classList.remove('dnd-not-allowed');
    document.querySelectorAll('.dnd-rejects').forEach((element) => element.classList.remove('dnd-rejects'));
}

function dispatch(target, name, detail) {
    return target.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
}

/** Position of an item among the draggable items of its list. */
function indexOf(item) {
    return items(item.parentElement).indexOf(item);
}

function items(listElement) {
    return [...listElement.children].filter((child) => child.matches(ITEM));
}

function labelOf(item) {
    const own = item.getAttribute('data-dnd-label');
    if (own) {
        return own;
    }

    return (item.querySelector('[data-dnd-label]')?.textContent ?? item.getAttribute('aria-label') ?? '').trim();
}

function listLabel(listElement) {
    return listElement.getAttribute('aria-label') ?? '';
}

/**
 * Tells the screen that an item has arrived somewhere else.
 */
function report(item, origin, cause) {
    const to = item.parentElement;
    const detail = {
        item,
        from: origin.list,
        to,
        oldIndex: origin.index,
        newIndex: indexOf(item),
        cause,
        // Puts the item back where it came from, for screens that save at once and are refused by the server.
        revert: () => {
            const siblings = items(origin.list).filter((sibling) => sibling !== item);
            origin.list.insertBefore(item, siblings[origin.index] ?? null);
        },
    };

    dispatch(to, 'dnd:move', detail);
}

// ----- every place an item could go, in the order of the page ---------------

function scopeOf(element) {
    return element.closest('[data-dnd-scope]') ?? document.body;
}

function groupLists(list) {
    const scope = scopeOf(list.el);

    return [...registry.values()].filter((other) => other.group === list.group && other.el.isConnected && scope.contains(other.el));
}

/**
 * All slots the item may be moved to: "before this item of that list" or "at the end of that list".
 * Lists inside items are entered and left on the way, which is what makes trees work.
 */
function slotsFor(item) {
    const own = registry.get(item.parentElement);
    if (!own) {
        return [];
    }

    const lists = groupLists(own);
    const top = lists.filter((list) => !lists.some((other) => other !== list && other.el.contains(list.el)));
    const slots = [];

    const walk = (list, depth) => {
        const accepted = list.accepts(item) || list === own;
        for (const child of items(list.el)) {
            if (child === item) {
                continue;
            }
            if (accepted) {
                slots.push({ list, before: child, depth });
            }
            for (const nested of lists) {
                if (nested !== list && child.contains(nested.el) && nested.el.closest(ITEM) === child) {
                    walk(nested, depth + 1);
                }
            }
        }
        if (accepted) {
            slots.push({ list, before: null, depth });
        }
    };
    top.forEach((list) => walk(list, 0));

    return slots.filter((slot) => slot.list === own ? own.sortable.option('sort') !== false : true);
}

function currentSlotIndex(item, slots) {
    const next = items(item.parentElement)[indexOf(item) + 1] ?? null;

    return slots.findIndex((slot) => slot.list.el === item.parentElement && slot.before === next);
}

function place(item, slot) {
    slot.list.el.insertBefore(item, slot.before);
}

function describePosition(item) {
    const list = item.parentElement;
    const parameters = { position: String(indexOf(item) + 1), total: String(items(list).length), list: listLabel(list) };

    return t(parameters.list ? 'position_in' : 'position', parameters);
}

// ----- carrying an item with the keyboard -----------------------------------

function startCarry(item, handleElement) {
    closeMenu();
    carrying = { item, handle: handleElement, origin: { list: item.parentElement, index: indexOf(item) }, moving: false };
    item.classList.add('dnd-carried');
    handleElement.setAttribute('aria-pressed', 'true');
    document.documentElement.classList.add('dnd-carrying');
    announce(`${t('grabbed', { item: labelOf(item) })} ${describePosition(item)} ${t('instructions')}`);
}

function carry(step) {
    const { item } = carrying;
    const slots = slotsFor(item);
    const target = slots[currentSlotIndex(item, slots) + step];
    if (!target) {
        announce(t('edge'));

        return;
    }

    moveCarried(target);
}

/** Right moves the item into the item above it, left moves it out of its parent - where the lists allow it. */
function carrySideways(inwards) {
    const { item } = carrying;
    const slots = slotsFor(item);
    let target = null;

    if (inwards) {
        const previous = items(item.parentElement)[indexOf(item) - 1];
        target = previous ? slots.find((slot) => slot.before === null && slot.list.el !== item.parentElement && slot.list.el.closest(ITEM) === previous) : null;
    } else {
        const parent = item.parentElement.closest(ITEM);
        const after = parent ? items(parent.parentElement)[indexOf(parent) + 1] ?? null : undefined;
        target = parent ? slots.find((slot) => slot.list.el === parent.parentElement && slot.before === after) : null;
    }

    if (!target) {
        announce(t('edge'));

        return;
    }

    moveCarried(target);
}

function moveCarried(slot) {
    const { item, handle: handleElement } = carrying;
    // Moving the element makes the browser drop the focus for a moment; that is not "leaving the handle".
    carrying.moving = true;
    place(item, slot);
    handleElement.focus({ preventScroll: true });
    carrying.moving = false;
    item.scrollIntoView({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    announce(describePosition(item));
}

function endCarry() {
    const state = carrying;
    carrying = null;
    state.item.classList.remove('dnd-carried');
    state.handle.setAttribute('aria-pressed', 'false');
    document.documentElement.classList.remove('dnd-carrying');

    return state;
}

function dropCarry() {
    const { item, origin } = endCarry();
    const moved = item.parentElement !== origin.list || indexOf(item) !== origin.index;
    announce(`${t('dropped', { item: labelOf(item) })} ${describePosition(item)}`);
    if (moved) {
        report(item, origin, 'keyboard');
    }
}

function cancelCarry() {
    const { item, origin, handle: handleElement } = endCarry();
    const siblings = items(origin.list).filter((sibling) => sibling !== item);
    origin.list.insertBefore(item, siblings[origin.index] ?? null);
    handleElement.focus({ preventScroll: true });
    announce(t('cancelled', { item: labelOf(item) }));
}

// ----- the move menu ---------------------------------------------------------

function closeMenu() {
    openMenu?.element.remove();
    openMenu?.handle.setAttribute('aria-expanded', 'false');
    openMenu = null;
}

function showMenu(item, handleElement) {
    closeMenu();

    const slots = slotsFor(item);
    const current = currentSlotIndex(item, slots);
    const moveTo = (slot) => {
        const origin = { list: item.parentElement, index: indexOf(item) };
        closeMenu();
        place(item, slot);
        handleElement.focus({ preventScroll: true });
        item.scrollIntoView({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        announce(`${t('moved', { item: labelOf(item) })} ${describePosition(item)}`);
        report(item, origin, 'menu');
    };
    const entry = (label, iconName, slot, onClick = null) => el('button', {
        type: 'button',
        role: 'menuitem',
        class: 'dnd-menu__item',
        disabled: !slot && !onClick,
        onclick: () => (onClick ? onClick() : moveTo(slot)),
    }, icon(iconName), label);

    const element = el('div', { class: 'dnd-menu', role: 'menu', 'aria-label': t('menu', { item: labelOf(item) }) },
        entry(t('move_up'), 'up', slots[current - 1]),
        entry(t('move_down'), 'down', slots[current + 1]),
        entry(t('move_to'), 'right', null, slots.length > 1 ? () => showDestinations(item, handleElement, slots, current, moveTo) : null),
    );

    mountMenu(element, item, handleElement);
    element.querySelector('button:not([disabled])')?.focus();
}

/** "Move to ...": every possible destination in one list to choose from. */
function showDestinations(item, handleElement, slots, current, moveTo) {
    const select = el('select', { 'aria-label': t('destination') });
    slots.forEach((slot, index) => {
        const position = slot.before ? items(slot.list.el).filter((sibling) => sibling !== item).indexOf(slot.before) + 1 : items(slot.list.el).filter((sibling) => sibling !== item).length + 1;
        const name = listLabel(slot.list.el);
        const text = `${'  '.repeat(slot.depth)}${name ? `${name} – ` : ''}${t('position_number', { position: String(position) })}`;
        const option = el('option', { value: String(index), text: index === current ? `${text} ${t('current')}` : text });
        select.append(option);
    });
    select.value = String(Math.max(0, current));

    const element = el('form', {
        class: 'dnd-menu dnd-menu--destinations',
        'aria-label': t('move_to'),
        onsubmit: (event) => {
            event.preventDefault();
            const index = Number(select.value);
            index === current ? closeMenu() : moveTo(slots[index]);
        },
    },
        el('label', { class: 'dnd-menu__label' }, t('destination'), select),
        el('div', { class: 'dnd-menu__buttons' },
            el('button', { type: 'submit', class: 'button button--primary button--small', text: t('move') }),
            el('button', { type: 'button', class: 'button button--small', text: t('cancel'), onclick: () => { closeMenu(); handleElement.focus(); } }),
        ),
    );

    closeMenu();
    mountMenu(element, item, handleElement);
    select.focus();
}

function mountMenu(element, item, handleElement) {
    document.body.append(element);
    const bounds = handleElement.getBoundingClientRect();
    const width = element.offsetWidth;
    const left = Math.max(8, Math.min(bounds.left, window.innerWidth - width - 8));
    const below = bounds.bottom + element.offsetHeight + 8 < window.innerHeight;
    Object.assign(element.style, {
        left: `${left + window.scrollX}px`,
        top: `${(below ? bounds.bottom + 4 : bounds.top - element.offsetHeight - 4) + window.scrollY}px`,
    });
    handleElement.setAttribute('aria-expanded', 'true');
    openMenu = { element, item, handle: handleElement };
}

document.addEventListener('click', (event) => {
    // A click on an entry may have replaced the menu already ("Move to ..."): that click is not "outside".
    if (openMenu && !event.target.closest?.('.dnd-menu') && !event.target.closest?.(HANDLE)) {
        closeMenu();
    }
});

document.addEventListener('keydown', (event) => {
    if (!openMenu) {
        return;
    }

    if (event.key === 'Escape') {
        const { handle: handleElement } = openMenu;
        event.stopPropagation();
        closeMenu();
        handleElement.focus();
    } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && openMenu.element.getAttribute('role') === 'menu') {
        // Arrow keys walk through the entries of the menu.
        const entries = [...openMenu.element.querySelectorAll('button:not([disabled])')];
        const next = entries[(entries.indexOf(document.activeElement) + (event.key === 'ArrowDown' ? 1 : -1) + entries.length) % entries.length];
        event.preventDefault();
        next?.focus();
    }
}, true);

// ----- files dragged in from the desktop -------------------------------------

/**
 * Watches an element for files being dragged over and dropped onto it.
 *
 * @param {Element|Window} element
 * @param {object}         callbacks
 * @param {function(boolean, DragEvent): void} [callbacks.onOver]  called with true while files hover, false when they leave
 * @param {function(File[], DragEvent): void}  callbacks.onFiles   called with the dropped files
 *
 * @returns {function(): void} stops watching
 */
export function watchFileDrops(element, { onOver = () => {}, onFiles }) {
    const hasFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');
    let depth = 0;

    const enter = (event) => {
        if (hasFiles(event)) {
            depth += 1;
            onOver(true, event);
        }
    };
    const over = (event) => {
        if (hasFiles(event)) {
            // Without this the browser would open the file instead of handing it over.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
            onOver(true, event);
        }
    };
    const leave = (event) => {
        if (hasFiles(event)) {
            depth = Math.max(0, depth - 1);
            if (depth === 0) {
                onOver(false, event);
            }
        }
    };
    const drop = (event) => {
        if (!hasFiles(event)) {
            return;
        }
        event.preventDefault();
        depth = 0;
        onOver(false, event);
        onFiles([...event.dataTransfer.files], event);
    };

    element.addEventListener('dragenter', enter);
    element.addEventListener('dragover', over);
    element.addEventListener('dragleave', leave);
    element.addEventListener('drop', drop);

    return () => {
        element.removeEventListener('dragenter', enter);
        element.removeEventListener('dragover', over);
        element.removeEventListener('dragleave', leave);
        element.removeEventListener('drop', drop);
    };
}

// ----- saving at once ---------------------------------------------------------

/**
 * For screens without a save button: the interface has changed already, now the server is told.
 * Resolves with the answer of the server; rejects with an Error whose message can be shown to the person.
 */
export async function sendMove(url, csrf, payload) {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: JSON.stringify(payload),
        });
    } catch {
        throw new Error(t('save_failed'));
    }

    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(result.error ?? t('save_failed'));
    }

    return result;
}

/**
 * Sends a move that is visible already. If the server refuses it, the item goes back and the reason is shown.
 * After success a toast offers to undo the move.
 *
 * @param {object}   options
 * @param {function(): Promise<any>} options.save    sends the change
 * @param {function(): void}         options.revert  puts the interface back
 * @param {function(): void}         [options.undo]  called when the person chooses "Undo"
 * @param {string}                   [options.message]
 */
export async function saveOptimistically({ save, revert, undo = null, message = null }) {
    try {
        const result = await save();
        toast(message ?? t('saved'), undo ? { actionLabel: t('undo'), onAction: undo } : {});

        return result;
    } catch (error) {
        revert();
        toast(error.message, { type: 'error' });
        announce(error.message);

        return null;
    }
}
