import { el } from './dom.js';
import { icon } from './icons.js';

/*
 * A deliberately small rich text field: bold, italic, links and lists.
 *
 * It relies on contenteditable and document.execCommand, which every browser
 * supports, so no editor library is needed. Whatever HTML it produces is
 * sanitized again on the server (see config/packages/html_sanitizer.yaml).
 */

const BLOCK_TAGS = new Set(['P', 'UL', 'OL']);

/** Turns what the user typed into a URL the server accepts. */
export function normalizeUrl(input) {
    const url = input.trim();
    if (url === '' || /^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url)) {
        return url;
    }
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(url)) {
        return `mailto:${url}`;
    }

    return `https://${url}`;
}

/** Wraps loose text in paragraphs so the stored HTML is consistent across browsers. */
function serialize(area) {
    const clone = area.cloneNode(true);
    const output = document.createElement('div');
    let paragraph = null;

    for (const node of [...clone.childNodes]) {
        if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'DIV') {
            const p = document.createElement('p');
            p.append(...node.childNodes);
            output.append(p);
            paragraph = null;
        } else if (node.nodeType === Node.ELEMENT_NODE && BLOCK_TAGS.has(node.tagName)) {
            output.append(node);
            paragraph = null;
        } else {
            if (!paragraph) {
                paragraph = document.createElement('p');
                output.append(paragraph);
            }
            paragraph.append(node);
        }
    }

    return output.textContent.trim() === '' ? '' : output.innerHTML;
}

export function createRichText({ value, labelledBy, i18n, onChange, onSlash = null }) {
    const area = el('div', {
        class: 'richtext__area',
        contenteditable: 'true',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-labelledby': labelledBy,
    });
    area.innerHTML = value || '';
    if (onSlash) {
        // Shown by CSS while the field is empty; this is how people discover the slash command.
        area.dataset.placeholder = i18n.rt_slash_placeholder;
    }

    let savedRange = null;
    const emit = () => onChange(serialize(area));

    const run = (command, argument = null) => {
        area.focus();
        document.execCommand(command, false, argument);
        emit();
        refreshState();
    };

    const currentLink = () => {
        const selection = window.getSelection();
        if (!selection.rangeCount || !area.contains(selection.anchorNode)) {
            return null;
        }
        const node = selection.anchorNode.nodeType === Node.TEXT_NODE ? selection.anchorNode.parentElement : selection.anchorNode;

        return node.closest('a');
    };

    // --- Toolbar -----------------------------------------------------------

    const tool = (label, content, command, onClick) => el('button', {
        type: 'button',
        class: 'richtext__tool',
        title: label,
        'aria-label': label,
        'aria-pressed': 'false',
        'data-command': command,
        // Keep the text selection when a toolbar button is pressed.
        onmousedown: (event) => event.preventDefault(),
        onclick: onClick ?? (() => run(command)),
    }, content);

    const boldIcon = el('strong', { text: 'B' });
    const italicIcon = el('em', { text: 'I' });

    const toolbar = el('div', { class: 'richtext__toolbar', role: 'toolbar', 'aria-label': i18n.rt_toolbar },
        tool(i18n.rt_bold, boldIcon, 'bold'),
        tool(i18n.rt_italic, italicIcon, 'italic'),
        tool(i18n.rt_link, icon('link'), 'link', () => openLinkBar()),
        tool(i18n.rt_bullets, icon('bullets'), 'insertUnorderedList'),
        tool(i18n.rt_numbers, icon('numbers'), 'insertOrderedList'),
    );

    // --- Link bar ----------------------------------------------------------

    const linkInput = el('input', { type: 'text', class: 'richtext__link-input', placeholder: i18n.rt_link_placeholder, 'aria-label': i18n.rt_link_placeholder });
    const removeButton = el('button', { type: 'button', class: 'button button--small', text: i18n.rt_unlink, onclick: () => applyLink('') });
    const linkBar = el('div', { class: 'richtext__linkbar', hidden: true },
        linkInput,
        el('button', { type: 'button', class: 'button button--small button--primary', text: i18n.rt_link_apply, onclick: () => applyLink(linkInput.value) }),
        removeButton,
        el('button', { type: 'button', class: 'button button--small', text: i18n.rt_link_cancel, onclick: () => closeLinkBar() }),
    );

    function openLinkBar() {
        const selection = window.getSelection();
        if (!selection.rangeCount || !area.contains(selection.anchorNode)) {
            area.focus();
        }
        savedRange = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0).cloneRange() : null;

        const link = currentLink();
        linkInput.value = link ? link.getAttribute('href') : '';
        removeButton.hidden = !link;
        linkBar.hidden = false;
        linkInput.focus();
    }

    function closeLinkBar() {
        linkBar.hidden = true;
        area.focus();
    }

    function applyLink(rawUrl) {
        const url = normalizeUrl(rawUrl);
        linkBar.hidden = true;
        area.focus();

        const selection = window.getSelection();
        if (savedRange) {
            selection.removeAllRanges();
            selection.addRange(savedRange);
        }

        const link = currentLink();
        if (link) {
            // Edit or remove the existing link as a whole, whatever part of it was selected.
            const range = document.createRange();
            range.selectNodeContents(link);
            selection.removeAllRanges();
            selection.addRange(range);
        }

        if (url === '') {
            document.execCommand('unlink');
        } else if (selection.isCollapsed) {
            const anchor = el('a', { href: url, text: url });
            selection.getRangeAt(0).insertNode(anchor);
            selection.collapse(anchor.nextSibling ?? area, anchor.nextSibling ? 0 : area.childNodes.length);
        } else {
            document.execCommand('createLink', false, url);
        }

        emit();
    }

    linkInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            applyLink(linkInput.value);
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            closeLinkBar();
        }
    });

    // --- Editing area ------------------------------------------------------

    function refreshState() {
        for (const button of toolbar.querySelectorAll('[data-command]')) {
            const command = button.dataset.command;
            let active = false;
            if (document.activeElement === area) {
                active = command === 'link' ? currentLink() !== null : document.queryCommandState(command);
            }
            button.setAttribute('aria-pressed', String(active));
        }
    }

    area.addEventListener('focus', () => {
        document.execCommand('defaultParagraphSeparator', false, 'p');
        refreshState();
    });
    area.addEventListener('blur', refreshState);
    area.addEventListener('keyup', refreshState);
    area.addEventListener('mouseup', refreshState);
    area.addEventListener('input', emit);

    // Paste as plain text: formatting copied from Word or websites never gets in.
    area.addEventListener('paste', (event) => {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    });

    area.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openLinkBar();
        } else if (event.key === '/' && onSlash && area.textContent.trim() === '') {
            // Slash command: only in an empty field, so a "/" inside a sentence stays a "/".
            event.preventDefault();
            onSlash();
        }
    });

    return el('div', { class: 'richtext' }, toolbar, linkBar, area);
}
