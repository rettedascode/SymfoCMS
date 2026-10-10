# Search

SymfoCMS has two searches. Both work out of the box, on SQLite, MySQL/MariaDB and PostgreSQL alike, and need no extra service.

## On the website

Every page has a small search field in its header. It leads to `/search` (or `/de/search` for other languages), which lists the matching pages and blog posts with the words that were searched for highlighted.

- Only **published** content is found. Drafts and posts scheduled for the future stay hidden.
- Every language searches its own content.
- A result has to contain **all** words of the query. Results with a word in the title come first.
- Capital letters do not matter. Parts of words match too: "garden" finds "gardening".
- Everything a visitor can read is searched: text, headings, quotes, questions and answers, captions, the text inside columns and the text of shared blocks.

Result pages are marked `noindex`, so they do not show up in search engines themselves.

## In the admin area

The field at the top of the sidebar searches pages, posts and images at once: titles, text, file names and image descriptions, in all languages, drafts included.

People only see what they may work on: authors find their own posts and all images, editors and administrators find everything.

## The index

The text of every page, post and image is kept in the `search_index` table. It is updated whenever something is saved or deleted and builds itself the first time it is needed, for example after an update from a version without search.

If content was changed directly in the database, rebuild the index by hand:

```bash
php bin/console app:search:reindex
```

## For developers

- `App\Service\SearchIndexer` turns blocks into plain text using the field definitions of each block type. Text, textarea, rich text and code fields are indexed, also inside item lists and nested blocks. A custom block type is searchable without any extra code.
- `App\EventSubscriber\SearchIndexSubscriber` is a Doctrine listener, so content written by your own code, fixtures or imports is indexed as well.
- `App\Service\Search` runs the queries. Matching uses `LIKE` on lower-cased text; there is no stemming or typo tolerance. For large sites, replace this service with one that talks to a search engine.
- The header form is the Twig block `search` in `templates/site/base.html.twig`; override it with an empty block to remove the field.
- The address `/search` is reserved, so a page cannot use the slug `search`.
