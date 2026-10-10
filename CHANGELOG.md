# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Image editing in the media library: crop (with fixed shapes), rotate and restore the upload. A focal point per image keeps the important spot visible in galleries, post cards and the new 1200 x 630 version used when a link is shared (see `docs/images.md`).
- Activity log for administrators: who created, changed, published or deleted what and when, plus logins, filterable by person and content. Only the names of changed fields are stored, never values (see `docs/activity-log.md`).
- Review workflow built on the Symfony Workflow component: authors submit posts for review, editors approve and publish them or send them back with a note. Off by default; switched on in the settings (see `docs/review-workflow.md`).
- Two-step login with an authenticator app (TOTP) and one-time backup codes. Optional for every user, can be required for administrators in the settings, and administrators can reset it for people who lost their phone (see `docs/two-step-login.md`).
- WordPress import: posts, pages, categories and images from a WordPress export file (WXR). Content is converted to blocks, old addresses get redirects, and a report lists what could not be converted. Also available as `app:wordpress:import`.
- Export of the whole website as a ZIP file (content, menus, settings, images) and import into another installation, adding to or replacing the existing content. Also available as `app:site:export` and `app:site:import` (see `docs/import-export.md`).
- Read API: read-only JSON under `/api/v1` for published pages, posts, categories, menus and images, with pagination and language filter, blocks as structured data and as rendered HTML, and an OpenAPI description. Disabled by default; administrators switch it on in the settings (see `docs/api.md`).
- Setup wizard in the browser for new websites: requirements check, database connection (SQLite, MySQL/MariaDB, PostgreSQL), name of the website and the first administrator. It closes itself permanently once the website has a user (see `docs/installation.md`).
- Themes: a theme is a folder with a manifest, stylesheets and Twig templates; administrators choose the active theme under "Design" (see `docs/themes.md`).
- Theme settings for logo, colours and fonts, applied through CSS custom properties. Fonts (Inter, Lora, Playfair Display) are served by the website itself.
- Second theme "Editorial" next to the default theme.
- Search on the website: full-text search over published pages and posts with highlighted matches, per language, working on SQLite, MySQL and PostgreSQL (see `docs/search.md`).
- Search in the admin area across pages, posts and images, limited to what the user may work on.
- Command `app:search:reindex` to rebuild the search index.
- Live preview of the search result in the editor, with hints when title or description are too long.
- Selectable image for sharing per page and post; Open Graph and Twitter card tags including image, image description and language.
- JSON-LD structured data: `BlogPosting` for posts, `FAQPage` for questions-and-answers blocks and `BreadcrumbList` (see `docs/seo.md`).
- Redirects: a 301 redirect is created automatically when the address of a published page or post changes; admins can add, change and delete redirects. Loops are refused and chains are flattened (see `docs/redirects.md`).
- Form builder: forms made of text, email, long text, selection, checkbox and consent fields, placed on pages with the new form block (see `docs/forms.md`).
- Inbox for form submissions with read/unread state, CSV export and deletion; optional email notification per form through Symfony Mailer (`MAILER_DSN`, `APP_MAIL_FROM`).
- Spam protection without third-party services: honeypot, minimum fill time and rate limiting.
- Setting to delete form submissions automatically after a number of days, and the `app:forms:purge` command.
- New block types: gallery, video (YouTube and Vimeo), questions and answers, map (OpenStreetMap) and code with server-side syntax highlighting.
- Videos and maps are click-to-load: nothing is requested from third parties before the visitor clicks.
- New field types for block authors: `Field::images()`, `Field::items()`, `Field::code()` and `Field::location()`; the image picker can select several images at once.
- Editor guide in `docs/editor.md`.
- Slash command: typing "/" in an empty text block opens the list of block types and turns the block into the chosen one.
- The "Add block" list can be searched by typing; Enter picks the first match.
- Page templates: a new page can start from a built-in template (landing page, about, contact) or from a template an admin saved from an existing page.
- Shared blocks: any block can be saved under a name, inserted on many pages and edited in one place. The admin section shows where each shared block is used before it is changed or deleted.
- `Field::sharedBlock()` and the `render_shared_block()` Twig function for block types that show a shared block.
- Revision history: every save of a page or post keeps a revision with author and time; earlier versions can be previewed and restored from the new "History" tab of the editor. The number of revisions kept is set with `APP_MAX_REVISIONS` (default 50).
- Multilingual content: pages, posts, categories and menus belong to a language; pages and posts can be linked to their versions in other languages (see `docs/multilingual.md`).
- "Translate into …" in the editor creates the version in another language as a draft copy.
- Settings screen for admins to choose the languages of the website. With a single language, nothing about languages is shown.
- Language prefix in public URLs for all languages except the main one, a language switcher, `hreflang` tags, and a blog, feed and menus per language.
- `site_path()`, `site_url()`, `content_path()` and `content_url()` Twig functions for language-aware links.
- Extension API: block types can be added by third-party Composer packages, with required fields and custom validation per block type (see `docs/extensions.md` and the example in `examples/callout-block-bundle`).
- Events for extensions: `ContentSavingEvent`, `ContentSavedEvent`, `ContentPublishedEvent`, `ContentDeletedEvent` and `ContentRenderEvent`.
- Block editor for pages and blog posts with the block types heading, rich text, image, button, quote, divider and two columns.
- Adding, duplicating, deleting (with undo) and reordering blocks by drag and drop or with buttons.
- Live preview of unsaved content in the public theme.
- Automatic backup of unsaved work, restore on return, and a warning before leaving with unsaved changes.
- Server-side validation of all block content and sanitizing of rich text.
- Pages with editable web address, draft and published status, SEO title, meta description and a homepage setting.
- Blog with excerpt, cover image, categories, authors, scheduled publishing, paginated overview, category pages and RSS feed.
- Menu management for the main and the footer navigation with drag and drop and one level of nesting.
- Media library with multi-file drag and drop upload, type and size validation, generated file names, resized versions, alt texts and an image picker.
- Users with the roles Admin, Editor and Author, enforced by voters; login throttling, CSRF protection and account deactivation.
- Responsive default theme with accessible navigation, meta tags, `sitemap.xml`, `robots.txt` and a friendly 404 page.
- Admin interface in English and German.
- Console command `app:create-admin`, demo fixtures and a Docker Compose setup for development.
- Database migration that runs on SQLite, MySQL and PostgreSQL.

### Changed

- Name and tagline of the website are edited under "Settings". `APP_SITE_NAME` and `APP_SITE_TAGLINE` remain the defaults.
- The public templates and stylesheet moved from `templates/site/` and `assets/styles/site.css` to `themes/default/`. Template names such as `site/page.html.twig` are unchanged.
- Web addresses of pages, posts and categories only have to be unique within one language.
- Existing content is assigned to the language set in `APP_LOCALE` by the migration; addresses do not change.
- The demo content is bilingual (English and German).

### Fixed

- Migrations that rebuild tables on SQLite no longer risk deleting related rows through cascading foreign keys.

[Unreleased]: https://github.com/rettedascode/SymfoCMS/commits/main
