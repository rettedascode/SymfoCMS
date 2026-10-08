// Public website: mobile menu and dropdown toggles. Plain script, no dependencies.
(function () {
    'use strict';

    const toggle = document.querySelector('.site-nav__toggle');
    const list = document.querySelector('.site-nav__list');

    if (toggle && list) {
        toggle.addEventListener('click', function () {
            const open = list.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', String(open));
        });
    }

    const closeAll = function (except) {
        document.querySelectorAll('.has-children.is-open').forEach(function (item) {
            if (item !== except) {
                item.classList.remove('is-open');
                item.querySelector('.site-nav__sub-toggle').setAttribute('aria-expanded', 'false');
            }
        });
    };

    document.querySelectorAll('.site-nav__sub-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const item = button.closest('.has-children');
            closeAll(item);
            const open = item.classList.toggle('is-open');
            button.setAttribute('aria-expanded', String(open));
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.has-children')) {
            closeAll(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const open = document.querySelector('.has-children.is-open .site-nav__sub-toggle');
            closeAll(null);
            if (open) {
                open.focus();
            }
        }
    });
})();
