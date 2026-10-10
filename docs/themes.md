# Themes

A theme decides how the public website looks. Content is never touched by a theme: switching it changes the look, not the pages.

## Choosing and adjusting a theme

Administrators open **Design** in the admin area.

- **Use this theme** activates another theme at once.
- **Logo** – shown in the header instead of the name of the website. The logo belongs to the website and stays when the theme changes.
- **Colours and fonts** – the settings the active theme offers. Every theme remembers its own choices.
- **Back to the theme's defaults** removes the chosen colours and fonts again.

Two themes are included: **Default** and **Editorial**, a magazine-like look with a centred masthead and serif type.

### Fonts and privacy

All fonts are files of this website (`assets/fonts/`). Nothing is loaded from a font service, so no visitor data leaves your server. Available are the fonts of the visitor's device (with and without serifs) and Inter, Lora and Playfair Display, all under the SIL Open Font License (`assets/fonts/licenses/`). Font files are only downloaded when a chosen font needs them.

## Making a theme

A theme is a folder in `themes/`. The folder name is its id (lower-case letters, digits and hyphens).

```text
themes/my-theme/
    theme.json              the manifest (required)
    assets/theme.css        stylesheets, images, fonts
    templates/site/…        Twig templates that replace those of the parent theme
```

### theme.json

```json
{
    "name": "My theme",
    "description": "One sentence shown in the admin area.",
    "author": "Your name",
    "version": "1.0.0",
    "parent": "default",
    "stylesheets": ["theme.css"],
    "settings": {
        "colors": [
            {"name": "accent", "label": "Accent colour", "variable": "--color-accent", "default": "#0a7d5a"}
        ],
        "fonts": [
            {"name": "heading", "label": "Headings", "variable": "--font-heading", "default": "lora"}
        ]
    }
}
```

| Key | Meaning |
| --- | --- |
| `name` | Required. A folder without a readable manifest and name is ignored. |
| `parent` | Theme to build on. Its stylesheets are loaded first and its templates and settings are inherited. |
| `stylesheets` | Files in `assets/`, linked in this order after those of the parent. |
| `settings.colors` | Colours an administrator can change. `default` is a six-digit hex colour. |
| `settings.fonts` | Font choices. `default` is one of `system-sans`, `system-serif`, `inter`, `lora`, `playfair-display`. |

Every setting is written into the page as a CSS custom property (`variable`), so the stylesheet just uses `var(--color-accent)`. `label` is plain text or a translation key such as `theme.setting.accent`. A child theme can repeat a setting of its parent to give it another default.

### Stylesheets

With `"parent": "default"` the stylesheet of the default theme is already loaded; yours only contains what is different. Without a parent you style everything yourself. Files referenced with `url(...)` in the stylesheet (fonts, images) are found relative to it.

In production, run `php bin/console asset-map:compile` after adding or changing a theme.

### Templates

Templates are looked up in the active theme first, then in its parent, then in `themes/default/templates/` and `templates/`. A theme therefore only contains the templates it changes, under the same name:

| Template | What it is |
| --- | --- |
| `site/base.html.twig` | The layout: head, header, navigation, footer |
| `site/page.html.twig` | A page |
| `site/blog/index.html.twig`, `site/blog/post.html.twig`, `site/blog/_card.html.twig` | The blog |
| `site/search.html.twig` | Search results |
| `site/_form.html.twig` | A form built in the admin area |
| `blocks/<type>.html.twig` | The markup of one block type |

To change a part of a template, extend the original and override a block. This is the whole layout of the Editorial theme:

```twig
{% extends '@themes/default/templates/site/base.html.twig' %}

{% block header %}
    <header class="site-header masthead">
        {{ block('brand') }}
        {{ block('navigation') }}
    </header>
{% endblock %}
```

The layout of the default theme offers the blocks `title`, `head`, `stylesheets`, `edit_bar`, `header`, `brand`, `navigation`, `search`, `language_switcher`, `main`, `content`, `footer`, `footer_navigation` and `javascripts`.

### Twig functions for layouts

| Function | Result |
| --- | --- |
| `theme_stylesheets()` | Addresses of the stylesheets to link, fonts included |
| `theme_style()` | The `<style>` element with the chosen colours and fonts |
| `theme_logo()` | The logo from the media library, or nothing |
| `theme_asset('images/pattern.svg')` | Address of a file in the theme's `assets/` folder |

A layout that does not extend the default one must call `theme_stylesheets()` and `theme_style()` itself and should keep the SEO tags, the skip link and `assets/site.js` (navigation, click-to-load for videos and maps).

## Safety

A theme contains Twig templates, which are code: only install themes you trust. Values from the manifest and from the design settings are checked before they are used, so colours and fonts cannot inject anything into the page. A broken or removed theme never takes the website down – the default theme is used instead.
