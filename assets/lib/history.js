/*
 * Undo and redo for editors that keep their content in memory until "Save".
 *
 * Every structural change (add, move, delete ...) is recorded as a pair of
 * functions: one that takes the change back and one that makes it again.
 * Typing is left to the browser, which has its own undo inside text fields.
 */
export class History {
    constructor({ limit = 100, onChange = () => {} } = {}) {
        this.limit = limit;
        this.onChange = onChange;
        this.done = [];
        this.undone = [];
    }

    /**
     * @param {{undo: function(): void, redo: function(): void}} step
     */
    record(step) {
        this.done.push(step);
        if (this.done.length > this.limit) {
            this.done.shift();
        }
        this.undone = [];
        this.onChange();
    }

    canUndo() {
        return this.done.length > 0;
    }

    canRedo() {
        return this.undone.length > 0;
    }

    undo() {
        const step = this.done.pop();
        if (!step) {
            return false;
        }
        step.undo();
        this.undone.push(step);
        this.onChange();

        return true;
    }

    redo() {
        const step = this.undone.pop();
        if (!step) {
            return false;
        }
        step.redo();
        this.done.push(step);
        this.onChange();

        return true;
    }

    /**
     * Ctrl+Z / Ctrl+Shift+Z (Cmd on a Mac, also Ctrl+Y) anywhere inside the element.
     * Inside text fields the shortcut belongs to the text.
     *
     * @returns {function(): void} removes the shortcut again
     */
    bindKeys(element, { onUndo = () => {}, onRedo = () => {} } = {}) {
        const listener = (event) => {
            const key = event.key.toLowerCase();
            if (!(event.ctrlKey || event.metaKey) || event.altKey || (key !== 'z' && key !== 'y')) {
                return;
            }
            if (event.target.closest?.('input:not([type="checkbox"]):not([type="radio"]), textarea, select, [contenteditable="true"], [contenteditable=""]')) {
                return;
            }

            const redo = key === 'y' || event.shiftKey;
            if (redo ? this.redo() : this.undo()) {
                event.preventDefault();
                (redo ? onRedo : onUndo)();
            }
        };
        element.addEventListener('keydown', listener);

        return () => element.removeEventListener('keydown', listener);
    }
}

/**
 * Records moving something between two arrays (or inside one) and returns the step for History.record().
 */
export function moveStep(from, fromIndex, to, toIndex, afterwards = () => {}) {
    return {
        undo: () => {
            const [moved] = to.splice(toIndex, 1);
            from.splice(fromIndex, 0, moved);
            afterwards(moved);
        },
        redo: () => {
            const [moved] = from.splice(fromIndex, 1);
            to.splice(toIndex, 0, moved);
            afterwards(moved);
        },
    };
}
