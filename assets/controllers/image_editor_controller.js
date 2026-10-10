import { Controller } from '@hotwired/stimulus';

/*
 * Image editing on the media page: choosing the part to keep (crop) and the
 * focal point. The browser only collects where the person clicked and
 * dragged, as fractions of the image; the server does the actual work.
 * Rotating is a plain form button and needs no JavaScript.
 */
export default class extends Controller {
    static targets = [
        'stage', 'image', 'box', 'point', 'modeButton', 'cropPanel', 'focusPanel',
        'x', 'y', 'w', 'h', 'cropButton', 'aspect', 'focusX', 'focusY', 'preview', 'status',
    ];

    static values = {
        focusUrl: String,
        csrf: String,
        focalX: Number,
        focalY: Number,
        saved: String,
        failed: String,
    };

    connect() {
        this.mode = 'crop';
        this.crop = null;
        this.drag = null;
        this.focus = { x: this.focalXValue, y: this.focalYValue };
        this.renderFocus();
        this.renderCrop();
    }

    // ----- switching between the two tools -----

    showMode(event) {
        this.mode = event.currentTarget.dataset.mode;
        this.modeButtonTargets.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.mode === this.mode)));
        this.cropPanelTarget.hidden = this.mode !== 'crop';
        this.focusPanelTarget.hidden = this.mode !== 'focus';
        this.stageTarget.dataset.mode = this.mode;
        this.renderCrop();
    }

    // ----- pointer handling on the image -----

    pointerDown(event) {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }
        event.preventDefault();
        const position = this.position(event);

        if (this.mode === 'focus') {
            this.setFocus(position.x, position.y);
            return;
        }

        const handle = event.target.dataset.handle;
        if (handle && this.crop) {
            // Resize from a corner: the opposite corner stays where it is.
            this.drag = { type: 'resize', anchor: {
                x: handle.includes('w') ? this.crop.x + this.crop.w : this.crop.x,
                y: handle.includes('n') ? this.crop.y + this.crop.h : this.crop.y,
            } };
        } else if (this.crop && this.inside(position)) {
            this.drag = { type: 'move', dx: position.x - this.crop.x, dy: position.y - this.crop.y };
        } else {
            this.drag = { type: 'resize', anchor: position };
            this.crop = { x: position.x, y: position.y, w: 0, h: 0 };
        }
        this.stageTarget.setPointerCapture?.(event.pointerId);
    }

    pointerMove(event) {
        if (!this.drag) {
            return;
        }
        const position = this.position(event);

        if (this.drag.type === 'move') {
            this.crop.x = this.clamp(position.x - this.drag.dx, 0, 1 - this.crop.w);
            this.crop.y = this.clamp(position.y - this.drag.dy, 0, 1 - this.crop.h);
        } else {
            this.crop = this.rectangle(this.drag.anchor, position);
        }
        this.renderCrop();
    }

    pointerUp() {
        this.drag = null;
        // A click without dragging leaves a box too small to be meant.
        if (this.crop && (this.crop.w < 0.02 || this.crop.h < 0.02)) {
            this.crop = null;
        }
        this.renderCrop();
    }

    // Arrow keys move the box, with Shift they change its size.
    keyDown(event) {
        const steps = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
        const step = steps[event.key];
        if (!step || this.mode !== 'crop') {
            return;
        }
        event.preventDefault();
        this.crop ??= { x: 0.1, y: 0.1, w: 0.8, h: 0.8 };
        const [dx, dy] = step.map((value) => value * 0.01);

        if (event.shiftKey) {
            this.crop.w = this.clamp(this.crop.w + dx, 0.02, 1 - this.crop.x);
            this.crop.h = this.clamp(this.crop.h + dy, 0.02, 1 - this.crop.y);
        } else {
            this.crop.x = this.clamp(this.crop.x + dx, 0, 1 - this.crop.w);
            this.crop.y = this.clamp(this.crop.y + dy, 0, 1 - this.crop.h);
        }
        this.renderCrop();
    }

    aspectChanged() {
        if (!this.crop) {
            return;
        }
        this.crop = this.rectangle({ x: this.crop.x, y: this.crop.y }, { x: this.crop.x + this.crop.w, y: this.crop.y + this.crop.h });
        this.renderCrop();
    }

    clearCrop() {
        this.crop = null;
        this.renderCrop();
    }

    // ----- crop -----

    /**
     * The box between two corners, kept inside the image and, if a shape is chosen, in that shape.
     */
    rectangle(anchor, position) {
        let w = Math.abs(position.x - anchor.x);
        let h = Math.abs(position.y - anchor.y);
        const ratio = this.ratio();

        if (ratio) {
            // "ratio" is width / height in pixels; the box is measured in fractions of the image.
            const imageRatio = this.imageTarget.naturalWidth / this.imageTarget.naturalHeight;
            const boxRatio = ratio / imageRatio;
            if (w / boxRatio > h) {
                h = w / boxRatio;
            } else {
                w = h * boxRatio;
            }
            const maxW = position.x < anchor.x ? anchor.x : 1 - anchor.x;
            const maxH = position.y < anchor.y ? anchor.y : 1 - anchor.y;
            const shrink = Math.min(1, maxW / w, maxH / h);
            w *= shrink;
            h *= shrink;
        }

        const x = position.x < anchor.x ? anchor.x - w : anchor.x;
        const y = position.y < anchor.y ? anchor.y - h : anchor.y;

        return {
            x: this.clamp(x, 0, 1),
            y: this.clamp(y, 0, 1),
            w: Math.min(w, 1 - this.clamp(x, 0, 1)),
            h: Math.min(h, 1 - this.clamp(y, 0, 1)),
        };
    }

    ratio() {
        const value = this.hasAspectTarget ? this.aspectTarget.value : '';
        if (value === 'original') {
            return this.imageTarget.naturalWidth / this.imageTarget.naturalHeight;
        }
        const [width, height] = value.split(':').map(Number);

        return width > 0 && height > 0 ? width / height : null;
    }

    renderCrop() {
        const visible = this.mode === 'crop' && this.crop !== null;
        this.boxTarget.hidden = !visible;
        this.cropButtonTarget.disabled = !visible || this.crop.w < 0.02 || this.crop.h < 0.02;

        if (!visible) {
            return;
        }

        const { x, y, w, h } = this.crop;
        Object.assign(this.boxTarget.style, { left: `${x * 100}%`, top: `${y * 100}%`, width: `${w * 100}%`, height: `${h * 100}%` });
        this.xTarget.value = x.toFixed(4);
        this.yTarget.value = y.toFixed(4);
        this.wTarget.value = w.toFixed(4);
        this.hTarget.value = h.toFixed(4);
    }

    // ----- focal point -----

    setFocus(x, y) {
        this.focus = { x: this.clamp(x, 0, 1), y: this.clamp(y, 0, 1) };
        this.renderFocus();
        this.saveFocus();
    }

    renderFocus() {
        const position = `${(this.focus.x * 100).toFixed(1)}% ${(this.focus.y * 100).toFixed(1)}%`;
        Object.assign(this.pointTarget.style, { left: `${this.focus.x * 100}%`, top: `${this.focus.y * 100}%` });
        this.previewTargets.forEach((preview) => { preview.style.objectPosition = position; });
        this.focusXTarget.value = this.focus.x.toFixed(4);
        this.focusYTarget.value = this.focus.y.toFixed(4);
    }

    async saveFocus() {
        try {
            const response = await fetch(this.focusUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
                body: JSON.stringify(this.focus),
            });
            this.statusTarget.textContent = response.ok ? this.savedValue : this.failedValue;
        } catch {
            this.statusTarget.textContent = this.failedValue;
        }
    }

    // ----- helpers -----

    /**
     * Where the pointer is, as fractions of the displayed image.
     */
    position(event) {
        const bounds = this.imageTarget.getBoundingClientRect();

        return {
            x: this.clamp((event.clientX - bounds.left) / bounds.width, 0, 1),
            y: this.clamp((event.clientY - bounds.top) / bounds.height, 0, 1),
        };
    }

    inside(position) {
        return position.x >= this.crop.x && position.x <= this.crop.x + this.crop.w
            && position.y >= this.crop.y && position.y <= this.crop.y + this.crop.h;
    }

    clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }
}
