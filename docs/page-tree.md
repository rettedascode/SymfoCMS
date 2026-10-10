# Page tree and child pages

**Pages** in the admin area shows all pages of a language as a tree. A page can have child pages, and the address of a child page starts with the address of its parent:

| Page | Address |
| --- | --- |
| About | `/about` |
| Our team (child page of About) | `/about/team` |

## Arranging pages

Every page has a handle on its left.

- **Drag** a page onto the area below another page to make it a child page. Drag it between two pages to change the order among siblings, or back to the left edge to bring it to the top level.
- **Keyboard:** focus the handle, press Space to pick the page up, move it with the arrow keys (Right moves it into the page above it, Left moves it out), press Space to drop it or Escape to give up.
- **Menu:** Enter or a click on the handle opens "Move up", "Move down" and "Move to …" with a list of all places the page can go.

A move is saved at once. The message at the bottom offers **Undo**.

The little arrow next to a page with child pages collapses or expands the branch. Which branches are collapsed is remembered in your browser, separately for every user and language.

## What happens to addresses

Moving a page below another one changes its address and the addresses of all its child pages. If one of these pages is **published**, you are asked first; the question lists every old and new address. After "Move and redirect" the old addresses lead to the new ones with a permanent [redirect](redirects.md) (301), so links and search results keep working. Drafts move without a question, because their address was never public.

The same redirects are created when you change the web address of a page that has child pages, and when you delete a page: its child pages move up one level instead of being deleted with it.

Changing only the order of siblings never changes an address.

## Rules

- Pages can be nested up to four levels deep.
- The homepage stays on the top level and has no child pages, because its address is `/`.
- Every language has its own tree. A page can only belong to a page of the same language.
- The last part of an address is still unique per language: two pages cannot both be called `team`, even below different parents.
- A page cannot be moved into itself or into one of its own child pages.

The server checks all of this again for every move, together with the permission to manage pages (editors and administrators).

## Elsewhere

- The [read API](api.md) reports `parent` (the id of the parent page or `null`) and `path` (`about/team`) for every page.
- [Export and import](import-export.md) keep the tree.
- Breadcrumbs in the structured data of a page ([SEO](seo.md)) list its parent pages.

## For developers

The tree is the `tree` controller of the shared [drag-and-drop layer](drag-and-drop.md) together with `page_tree_controller.js`, which stores every move and asks before addresses change:

```
POST /admin/pages/move
{"id": 7, "parent": 3, "position": 0}
```

| Answer | Meaning |
| --- | --- |
| `200 {"paths": {"7": "/about/team"}, "redirects": 1}` | Stored. `paths` are the new addresses of the page and everything below it. |
| `409 {"confirm": "…", "changes": [{"title", "from", "to"}]}` | Addresses of published pages would change. Nothing was stored; send the move again with `"confirmed": true`. |
| `422 {"error": "…"}` | The tree does not allow the move. |

The rules live in `App\Service\PageTree`.
