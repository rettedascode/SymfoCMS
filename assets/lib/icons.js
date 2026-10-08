// Small inline SVG icons (24×24, stroke based) so buttons look the same on every device.
const PATHS = {
    grip: '<circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/>',
    up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
    down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    bullets: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2" fill="currentColor"/><circle cx="4.5" cy="12" r="1.2" fill="currentColor"/><circle cx="4.5" cy="18" r="1.2" fill="currentColor"/>',
    numbers: '<path d="M10 6h10M10 12h10M10 18h10M4 4.5l1.5-.5v4M3.8 14.2c.4-.9 2.4-1 2.4.2 0 .8-1 1.3-2.4 3.1h2.6"/>',
    indent: '<path d="M4 6h16M10 12h10M4 18h16M4 10l3 2-3 2"/>',
    outdent: '<path d="M4 6h16M10 12h10M4 18h16M7 10l-3 2 3 2"/>',
};

/** Returns an <svg> element for the given icon name. */
export function icon(name) {
    const template = document.createElement('template');
    const filled = name === 'grip' ? ' fill="currentColor" stroke="none"' : ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    template.innerHTML = `<svg class="icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"${filled}>${PATHS[name] ?? ''}</svg>`;

    return template.content.firstChild;
}
