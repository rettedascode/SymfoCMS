# Read API

The read API offers the public content of the website as JSON, for example for a mobile app, a screen in a shop window or a static site generator.

- **Read-only.** Nothing can be created, changed or deleted through it.
- **Public content only.** Published pages and posts, categories, menus and images. Drafts and scheduled posts are never included, not even for logged-in users.
- **Off by default.** An administrator switches it on under *Settings → For developers*. While it is off, every address below `/api/` answers `404`.
- **No key needed.** The API returns what any visitor of the website can see anyway. Responses may be fetched from any website (`Access-Control-Allow-Origin: *`) and cached for a minute.

The demo content has the API switched on.

## Addresses

Everything lives below `/api/v1`.

| Address | Returns |
| --- | --- |
| `/api/v1` | Languages of the website and links to all lists |
| `/api/v1/pages` | Published pages, ordered by title |
| `/api/v1/pages/{id}` | One page with its content. Pages carry `parent` (id of the parent page or `null`) and `path` (all address parts, e.g. `about/team`). |
| `/api/v1/posts` | Published posts, newest first |
| `/api/v1/posts/{id}` | One post with its content |
| `/api/v1/categories` | Blog categories, per language in the order they were arranged in |
| `/api/v1/menus` | Main and footer menu of one language |
| `/api/v1/media` | Images of the media library, newest first |
| `/api/v1/media/{id}` | One image |
| `/api/v1/openapi.json` | Machine-readable description (OpenAPI 3.1) |

Load `/api/v1/openapi.json` into a tool such as Swagger UI, Insomnia or an OpenAPI code generator to explore the API or to generate a client.

## Parameters

| Parameter | For | Meaning |
| --- | --- | --- |
| `locale` | pages, posts, categories, menus | Only content of this language, e.g. `de`. Lists return all languages without it; menus use the default language. |
| `slug` | pages, posts, categories | Only the entry with this address part. Combine it with `locale` to look up what a URL shows. |
| `category` | posts | Only posts of the category with this slug. |
| `page`, `per_page` | all lists | Pagination. `per_page` is 20 by default and at most 100. |

## Responses

A list:

```json
{
    "data": [
        {
            "id": 2,
            "type": "page",
            "homepage": false,
            "locale": "en",
            "title": "About",
            "slug": "about",
            "url": "https://example.com/about",
            "seo_title": null,
            "meta_description": "What this website is about.",
            "share_image": null,
            "created_at": "2026-10-01T09:00:00+00:00",
            "updated_at": "2026-10-03T14:21:07+00:00",
            "translations": [
                {"locale": "de", "id": 7, "url": "https://example.com/de/ueber-uns"}
            ]
        }
    ],
    "meta": {"page": 1, "per_page": 20, "total": 1, "pages": 1},
    "links": {"self": "https://example.com/api/v1/pages?page=1", "prev": null, "next": null}
}
```

A single page or post additionally carries its content in two forms:

```json
{
    "data": {
        "id": 2,
        "title": "About",
        "blocks": [
            {"type": "heading", "data": {"text": "Who we are", "level": "2"}},
            {"type": "text", "data": {"content": "<p>We bake bread.</p>"}},
            {"type": "image", "data": {"image": 5, "alt": "", "caption": "Our shop"}}
        ],
        "html": "<h2 id=\"who-we-are\">Who we are</h2><p>We bake bread.</p><figure>…</figure>"
    }
}
```

- Images carry a `focal_point` (`x` and `y` from 0 to 1): the spot that should stay visible when your app cuts the image to another shape.
- `blocks` is the structured content, in the order it is shown. Images are referenced by the id of a media entry (`/api/v1/media/{id}`); a block of the type `shared` references a shared block whose content is part of `html`.
- `html` is the same content rendered with the block templates of the active theme. Addresses inside it start at the root of the website (`/uploads/…`).

Errors have a status code and this shape:

```json
{"error": {"status": 400, "message": "\"fr\" is not a language of this website. Available: en, de."}}
```

## For developers

- `App\Controller\Api\ApiController` holds the routes, `App\Api\ApiSerializer` the shape of the data and `App\Api\OpenApi` its description. A test checks that every route is described.
- The content of a page goes through the same `ContentRenderEvent` as on the website, so extensions that change what is rendered also apply to the API.
- New fields may be added to responses at any time. Changes that break clients would get a new address (`/api/v2`).
