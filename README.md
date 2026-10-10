# SymfoCMS

[![CI](https://github.com/rettedascode/SymfoCMS/actions/workflows/ci.yml/badge.svg)](https://github.com/rettedascode/SymfoCMS/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A small, production-ready content management system built on [Symfony](https://symfony.com).
Its one goal is an editing experience so simple that somebody who has never used a CMS can create and edit a page without any training.

Pages and blog posts are built from blocks – headings, text, images, buttons, quotes, dividers and columns – in an editor that never shows HTML or code and previews the result live, next to what you type.

![The block editor with the live preview next to it](docs/screenshots/editor.png)

| Adding a block | Media library | Menu editor |
| --- | --- | --- |
| ![The "add block" menu](docs/screenshots/editor-add-block.png) | ![The media library](docs/screenshots/media-library.png) | ![The menu editor](docs/screenshots/menus.png) |

| Public website | Blog |
| --- | --- |
| ![The default theme](docs/screenshots/website.png) | ![The blog overview](docs/screenshots/website-blog.png) |

## Features

**Block editor**

- Fourteen block types: heading, rich text, image, gallery, video, button, quote, divider, columns, questions and answers, form, map, code and shared block ([overview](docs/editor.md))
- Privacy by default: videos (YouTube, Vimeo) and maps (OpenStreetMap, no API key) load nothing from third parties until the visitor clicks
- Add, duplicate and delete blocks; reorder them by drag and drop or with the arrow buttons; undo an accidental delete
- Slash command: type `/` in an empty text block to pick another block type; the block list is searchable
- Live preview that uses the real public theme
- Automatic backup while you type, restored when you come back, and a warning before leaving with unsaved changes
- Page templates: new pages start blank or from a template (landing page, about, contact); admins can save any page as a new template
- Shared blocks: write a block once, show it on many pages, edit it in one place – with a list of where it is used
- Revision history: every save keeps the previous version, which can be previewed and restored with one click
- All content is validated and sanitized on the server ([symfony/html-sanitizer](https://symfony.com/doc/current/html_sanitizer.html))
- A block type is one PHP class plus one Twig template – [adding your own](#adding-a-custom-block-type) needs no JavaScript
- Third-party packages can add block types and listen to content events ([extension guide](docs/extensions.md))

**Content**

- Pages with editable web address, draft/published status, SEO title and meta description; one page is the homepage
- [Page tree](docs/page-tree.md): drag a page below another one to make it a child page with a nested address (`/about/team`); old addresses of published pages are redirected
- Blog posts with excerpt, cover image, category, author and publish date (a date in the future schedules the post)
- [Editorial board](docs/editorial-planning.md): posts as cards by status, moved between the columns by dragging
- Blog overview with pagination, category pages and an RSS feed; categories are dragged into the order they have on the blog
- Drafts and scheduled posts can be previewed by logged-in users only
- [Redirects](docs/redirects.md): changing the address of a published page creates a 301 redirect automatically; admins manage redirects by hand, loops and chains are prevented
- Menu editor for the main and the footer navigation: drag pages in from the page tree, link posts or any URL, reorder by drag and drop, one level of dropdowns
- [Form builder](docs/forms.md): contact and sign-up forms with an inbox, email notification, CSV export and spam protection that needs no third-party service
- Media library: upload several images by drag and drop, automatic resized versions, alt text per image

**Users and security**

- Three roles: **Admin** (everything, including users and menus), **Editor** (all content and media), **Author** (own blog posts only)
- Permissions are enforced by Symfony voters on the server, not just by hiding buttons
- Hashed passwords, CSRF protection, login throttling, accounts can be deactivated

**Website**

- Clean, responsive default theme in plain CSS – no build step, no Node.js
- Semantic HTML, accessible navigation, `sitemap.xml`, `robots.txt`, friendly 404 page
- [Drag and drop](docs/drag-and-drop.md) as the way to arrange things, the same on every screen: mouse, touch and keyboard, a move menu on every handle, undo and redo, screen reader announcements
- Dark mode in the admin area: follows the setting of the device and can be switched by hand with *Appearance* at the bottom of the sidebar (like the device, light, dark). The choice is remembered in the browser.
- [Image editing](docs/images.md) in the browser: crop, rotate and a focal point that stays visible wherever an image is cut to another shape
- [Activity log](docs/activity-log.md) for administrators: who changed what and when, filterable by person and content
- [Review workflow](docs/review-workflow.md): authors submit posts, editors approve and publish them or send them back with a note
- [Two-step login](docs/two-step-login.md) with an authenticator app (TOTP) and backup codes, optional for every user and enforceable for administrators
- [Setup wizard](docs/installation.md) in the browser: requirements check, database connection, name of the website and administrator account
- [Export and import](docs/import-export.md): the whole website as one ZIP file with content, menus, settings and images, importable into another installation
- [WordPress import](docs/import-export.md#moving-from-wordpress): posts, pages, categories and images from a WordPress export file, converted to blocks, with a report of what could not be converted
- [Read API](docs/api.md): read-only JSON for published pages, posts, categories, menus and images with pagination and language filter, blocks as structured data and as HTML, described with OpenAPI; off by default
- [Themes](docs/themes.md): selectable in the admin area, with logo, colours and fonts as theme settings and locally served fonts; two themes included
- [Search](docs/search.md): full-text search over published pages and posts with highlighted matches, per language, on every supported database; one search field in the admin area for pages, posts and images
- [SEO](docs/seo.md): live preview of the search result in the editor, Open Graph and Twitter cards with a selectable share image, JSON-LD for posts, FAQs and breadcrumbs
- Admin interface in English and German; [other languages](#adding-an-interface-language) are one translation file away
- [Multilingual content](docs/multilingual.md): language versions of pages and posts, menus per language, a language switcher and `hreflang` tags – completely invisible on single-language sites

## Requirements

- PHP 8.3 or newer with the extensions `ctype`, `iconv`, `gd`, `fileinfo`, `pdo_sqlite` (recommended: `intl`, `exif`)
- [Composer](https://getcomposer.org) 2
- SQLite (default, no setup needed), or MySQL / MariaDB, or PostgreSQL. The test suite runs against SQLite, MySQL 8.4 and PostgreSQL 16.

No Node.js is required: the frontend uses Symfony AssetMapper and Stimulus.

## Quick start

This gets a site with demo content running locally in a few minutes.

```bash
git clone https://github.com/rettedascode/SymfoCMS.git symfocms
cd symfocms

composer install                                    # PHP dependencies and frontend libraries
php bin/console doctrine:migrations:migrate -n      # creates the SQLite database in var/data.db
php bin/console doctrine:fixtures:load -n           # optional: demo site in English and German

php -S localhost:8000 -t public bin/dev-router.php  # or: symfony serve
```

Open <http://localhost:8000>. The admin area is at <http://localhost:8000/admin>.

With the demo content you can log in as:

| Email | Password | Role |
| --- | --- | --- |
| `admin@example.com` | `demo-password` | Admin |
| `editor@example.com` | `demo-password` | Editor |
| `author@example.com` | `demo-password` | Author |

To start with an empty site instead, skip the fixtures and create your own admin:

```bash
php bin/console app:create-admin you@example.com
```

The command asks for the password without showing it. Running it again for an existing email resets that account's password and makes it an admin – handy if you lock yourself out.

### With Docker

```bash
docker compose up
```

That one command builds a development container, installs the dependencies, creates the database, loads the demo content on the first start and serves the site at <http://localhost:8000>. Use `APP_PORT=8080 docker compose up` for another port.

## Configuration

Defaults live in `.env`, which is committed and contains no secrets. Put your own values in `.env.local` – that file is ignored by git.

| Variable | Default | Purpose |
| --- | --- | --- |
| `APP_ENV` | `dev` | `dev` for development, `prod` for a live site |
| `APP_SECRET` | *(empty)* | Random secret used for security tokens. **Required in production.** |
| `DATABASE_URL` | SQLite in `var/data.db` | Database connection, see below |
| `DEFAULT_URI` | `http://localhost` | Base URL for links generated outside of web requests |
| `APP_SITE_NAME` | `SymfoCMS` | Name shown in the header, the browser tab and the feed, until one is entered under *Settings* |
| `APP_SITE_TAGLINE` | `A small, friendly CMS` | Default meta description, until one is entered under *Settings* |
| `APP_LOCALE` | `en` | Language of the website until languages are chosen under *Settings* in the admin area, and default admin language |
| `APP_TIMEZONE` | `UTC` | Time zone for publish dates, e.g. `Europe/Berlin`. Choose it before creating content. |
| `APP_MAX_REVISIONS` | `50` | Number of earlier versions kept for every page and post |
| `MAILER_DSN` | `null://null` | How emails are sent ([forms guide](docs/forms.md#email-notifications)); the default sends nothing |
| `APP_MAIL_FROM` | `noreply@example.com` | Sender address of emails the website sends |
| `APP_MAX_UPLOAD_MB` | `8` | Largest accepted image. PHP's `upload_max_filesize` and `post_max_size` must allow it too. |
| `APP_AUDIT_DAYS` | `365` | How long entries of the [activity log](docs/activity-log.md) are kept; `0` keeps them forever |
| `APP_INSTALLER` | `1` | `0` switches the [setup wizard](docs/installation.md) off |

### Using MySQL or PostgreSQL

Set `DATABASE_URL` in `.env.local` and run the migrations. The same migration works on all three databases.

```dotenv
# .env.local
DATABASE_URL="mysql://user:password@127.0.0.1:3306/symfocms?serverVersion=8.4.0&charset=utf8mb4"
# DATABASE_URL="postgresql://user:password@127.0.0.1:5432/symfocms?serverVersion=16&charset=utf8"
```

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate -n
```

### Going live

1. Point the web server's document root to the `public/` directory and send all requests for files that do not exist to `public/index.php` ([Symfony web server configuration](https://symfony.com/doc/current/setup/web_server_configuration.html)).
2. In `.env.local` (or as real environment variables) set `APP_ENV=prod`, a long random `APP_SECRET`, your `DATABASE_URL` and `DEFAULT_URI`.
3. Install and build:

   ```bash
   composer install --no-dev --optimize-autoloader
   php bin/console doctrine:migrations:migrate -n
   php bin/console asset-map:compile
   php bin/console app:create-admin you@example.com
   ```

   Instead of the two commands for the database and the administrator you can open the website and follow the [setup wizard](docs/installation.md). It appears on a website without users, asks for the database, the name of the website and the first administrator, and closes itself for good afterwards.

4. Make sure the web server can write to `var/` and `public/uploads/`, and include `public/uploads/` and the database in your backups.

## Roles

| | Admin | Editor | Author |
| --- | :---: | :---: | :---: |
| Pages | ✔ | ✔ | – |
| Blog posts | all | all | own posts only; with the [review workflow](docs/review-workflow.md) switched on, authors submit their posts and editors publish them |
| Blog categories | ✔ | ✔ | – |
| Media library | ✔ | ✔ | upload, and manage own uploads |
| Shared blocks | ✔ | ✔ | use only |
| Forms and their inbox | ✔ | ✔ | use only |
| Page templates | use, save, delete | use | – |
| Menus | ✔ | – | – |
| Design, settings, redirects, export and import | ✔ | – | – |
| Users | ✔ | – | – |

Every user can change their own name, language and password under their name at the bottom of the sidebar.

## Adding a custom block type

A block type is one PHP class and one Twig template. As an example, here is a "Notice" block: a highlighted box of text.

**1. The class** – `src/Block/Type/NoticeBlock.php`

```php
<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class NoticeBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'notice'; // stored with the content: do not change it later
    }

    public function getLabel(): string
    {
        return 'Notice'; // plain text or a translation key
    }

    public function getIcon(): string
    {
        return '!';
    }

    public function getFields(): array
    {
        return [
            Field::textarea('text', 'Text'),
            Field::select('tone', 'Colour', ['info' => 'Blue', 'warning' => 'Yellow'], 'info'),
        ];
    }
}
```

**2. The template** – `templates/blocks/notice.html.twig`

```twig
{% if data.text %}
<aside class="block block-notice block-notice--{{ data.tone }}">
    {{ data.text|nl2br }}
</aside>
{% endif %}
```

That is all. The class is discovered automatically, the block appears in the "Add block" menu, the editor builds its form from `getFields()`, and the live preview renders the template. Add the CSS for `.block-notice` to `assets/styles/site.css`.

Available field types:

| Field | Editor shows | Value in the template |
| --- | --- | --- |
| `Field::text()` | single-line input | string |
| `Field::textarea()` | multi-line input | string (use `\|nl2br`) |
| `Field::richText()` | text with bold, italic, links, lists | sanitized HTML (use `\|raw`) |
| `Field::select()` | dropdown | one of the option keys |
| `Field::url()` | link input | safe URL or empty string |
| `Field::image()` | image picker | media id – use `cms_media(data.image)` |
| `Field::checkbox()` | checkbox | `true` / `false` |
| `Field::blocks()` | nested list of blocks | list – use `render_blocks(data.name)` |

More field types (image lists, repeatable items, code, map location, shared block) are listed in [docs/extensions.md](docs/extensions.md). Every field accepts `->help('...')`, `->placeholder('...')` and `->required()`. Values are validated against these definitions when content is saved, so a template can trust its `data`. To translate labels, use translation keys (for example `block.notice.label`) and add them to the files in `translations/`.

To ship block types as a reusable Composer package, to validate block data, or to react to events such as "content was published", see [docs/extensions.md](docs/extensions.md).

## Adding an interface language

This is about the language of the admin area and of theme texts such as "Read more". To offer your *content* in several languages, see [Multilingual websites](docs/multilingual.md).

1. Copy `translations/messages.en.yaml` to `translations/messages.<locale>.yaml` and translate the values (do the same for `validators.en.yaml` and `security.en.yaml`).
2. Add the locale to `enabled_locales` in `config/packages/translation.yaml`.

The language then appears in the user settings.

## Changing the design

Administrators choose a theme under **Design** and set the logo, colours and fonts there. Two themes are included (`themes/default/` and `themes/editorial/`); fonts are served by the website itself. A theme is a folder with a manifest, stylesheets and the Twig templates it wants to change – see [docs/themes.md](docs/themes.md). The markup of the blocks is in `templates/blocks/`. In development changes show up on reload; in production run `php bin/console asset-map:compile` after changing assets.

## Development

```bash
composer check       # everything the CI runs: composer validate, PHP-CS-Fixer, PHPStan, PHPUnit
composer test        # PHPUnit only
composer test:browser  # tests in a real browser (drag and drop, keyboard); needs Chrome and "vendor/bin/bdi detect drivers" once
composer stan        # PHPStan only
composer cs-fix      # fix coding standard violations
```

Where things live:

```text
assets/             Stimulus controllers, the editor and the admin stylesheets
src/Block/          Block system: field definitions, normalizer, renderer and the block types
src/Controller/     Admin/ for the admin area, Site/ for the public website
src/Security/       Voters and the user checker
src/Service/        Media handling, menus, slugs, editor configuration
templates/blocks/   One template per block type
themes/             Public themes: manifest, templates and stylesheets
translations/       Interface texts (English and German)
tests/Functional/   Functional tests
tests/Browser/      Tests that drive a real browser with Symfony Panther
examples/           Example extension (a block type in its own bundle)
docs/               Guides: installation, two-step login, the review workflow, the activity log, image editing, drag and drop, the page tree, the editor, forms, redirects, SEO, search, themes, the read API, export and import, extensions, multilingual websites
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to report bugs and propose changes, and [SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

SymfoCMS is open source software released under the [MIT License](LICENSE).
