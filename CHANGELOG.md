# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

[Unreleased]: https://github.com/rettedascode/SymfoCMS/commits/main
