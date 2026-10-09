# Multilingual websites

SymfoCMS can offer pages and posts in several languages. As long as only one language is configured, nothing about languages is visible anywhere: not in the editor, not in the lists, not on the website.

> Content languages and the language of the admin interface are two separate things. The admin interface is available in English and German and each user picks their own in *My account*. This page is about the languages your **visitors** read.

## Adding a language

1. Log in as an admin and open **Settings**.
2. Enter the language codes under *Languages of the website*, separated by commas, for example `en, de`.

The first code is the **main language**. Its addresses have no prefix (`/about`). All other languages are prefixed with their code (`/de/ueber-uns`).

Codes are two letters (`en`, `de`, `fr`) or a regional form such as `pt-br`.

A language can only be removed again once no pages, posts, categories or menus exist in it.

## How content is organised

- Every page and post **belongs to one language**.
- Language versions of the same page or post are **linked** to each other. There is at most one version per language.
- The web address only has to be unique within a language: `/about` and `/de/about` can both exist.
- Every language has its **own homepage**, its **own menus** and its **own blog categories**.
- Images in the media library are shared by all languages.

## Translating

Open a page or post, switch to the **Settings** tab and scroll to *Other languages*:

- **Translate into …** creates the version in that language as a draft. It starts as a copy of the current content – texts, images and layout – so you can translate block by block and publish when you are ready.
- **Edit** opens a version that already exists.

In the lists of pages, posts and categories and in the menu editor, tabs switch between the languages. *New page* and *New post* create content in the language of the tab you are on.

## What visitors see

- A **language switcher** in the header. It links to the same page in the other language, or to that language's homepage when the page has not been translated (or its translation is still a draft).
- The page is rendered in its language: the `lang` attribute, dates and the texts of the theme follow it.
- Search engines get `hreflang` links between published language versions, with the main language as `x-default`.
- The blog, the RSS feed and the menus exist once per language: `/blog` and `/de/blog`, `/feed.xml` and `/de/feed.xml`.
- `sitemap.xml` lists the pages of all languages.

Theme texts such as "Read more" or "Page not found" are translated into English and German. For other languages they fall back to English until a translation file is added (see [Adding a language](../README.md#adding-an-interface-language) in the README).

## Upgrading an existing site

The database migration assigns all existing pages, posts, categories and menus to the language set in `APP_LOCALE` (default `en`). Addresses do not change. After the upgrade the site behaves exactly as before until a second language is added.

## For developers

| Need | Use |
| --- | --- |
| The configured languages | `App\Service\ContentLocales` (`all()`, `default()`, `isMultilingual()`) |
| A link to a public route in the right language | Twig: `site_path('app_blog')`, `site_url(...)`; PHP: `App\Service\SiteUrls::generate()` |
| The public address of a page or post | Twig: `content_path(page)`, `content_url(page)`; PHP: `SiteUrls::content()` |
| The language versions of a page or post | `App\Service\ContentTranslator::versions()` |
| A menu in a specific language | Twig: `cms_menu('main', 'de')` |
| The entries of the language switcher | Twig: `language_switcher(content)` |

Every public route exists twice: `app_blog` (`/blog`) for the main language and `app_blog.localized` (`/{_locale}/blog`) for the others. `site_path()` picks the right one, so themes and extensions should always use it instead of `path()` for public links.
