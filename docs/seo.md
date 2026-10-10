# Search engines and sharing

Everything on this page happens on its own. The only thing an editor may want to do is fill in two optional fields and pick an image.

## In the editor

The **Settings** tab of every page and post has a section *Search engines*:

- **Title for search engines** – used in search results and in the browser tab. Empty means: the title of the page.
- **Description for search engines** – one or two sentences below the title in search results. For posts without a description, the summary is used.
- A **preview** shows roughly how the result will look and updates while you type. It tells you when a title (about 60 characters) or description (about 155 characters) is likely to be cut off.

Under *Sharing* you choose the **image for sharing**: the picture shown when somebody posts the link on a social network or in a messenger. A post without one is shared with its cover image.

## What the website outputs

| | |
| --- | --- |
| Title and meta description | From the fields above, with sensible fallbacks |
| Canonical address | Every page names its one official address |
| Open Graph and Twitter card tags | Title, description, type, language and the share image (large card when there is an image) |
| `hreflang` links | Between the published language versions of a page ([multilingual websites](multilingual.md)) |
| Structured data (JSON-LD) | `BlogPosting` for blog posts, `FAQPage` for *Questions and answers* blocks, `BreadcrumbList` for everything below the homepage |
| `sitemap.xml` and `robots.txt` | All public pages of all languages; the admin area is excluded |
| RSS feed | `/feed.xml`, one per language |
| Redirects | Old addresses lead to new ones ([redirects](redirects.md)) |

Drafts and scheduled posts are marked `noindex` and carry no structured data, even when a logged-in user previews them.

## For developers

- Meta tags live in `themes/default/templates/site/base.html.twig`. A page template sets `meta_description`, `canonical_url`, `share_image` and `translatable` before the layout renders.
- `App\Service\StructuredData` builds the JSON-LD objects; the Twig function `structured_data(content, blocks)` prints them.
- Any block type whose data contains an item list with the fields `question` and `answer` contributes to the FAQ data.
