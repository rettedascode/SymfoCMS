# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

- Web addresses of pages, posts and categories only have to be unique within one language.
- Existing content is assigned to the language set in `APP_LOCALE` by the migration; addresses do not change.
- The demo content is bilingual (English and German).

### Fixed

- Migrations that rebuild tables on SQLite no longer risk deleting related rows through cascading foreign keys.

[Unreleased]: https://github.com/rettedascode/SymfoCMS/commits/main
