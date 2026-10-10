# Redirects

A redirect sends visitors who open an old address on to a new one (with the HTTP status 301, "moved permanently"). Links from other websites, bookmarks and search results keep working.

## Automatic redirects

When you change the web address of a **published** page or post, SymfoCMS creates the redirect from the old address for you. This also covers a page that becomes the homepage (`/landing` → `/`).

Nothing is created for drafts: their address was never public.

The same happens when a page gets another address because it was moved in the [page tree](page-tree.md) or because the address of its parent page changed: the page and all its child pages are redirected.

Redirects never form chains. If a page moves from `/a` to `/b` and later to `/c`, both `/a` and `/b` lead straight to `/c`. If it moves back to `/a`, that address simply works again.

## Managing redirects by hand

Admins find the list under **Settings → Manage redirects**. There you can add a redirect, change it or delete it.

- The **old address** is a path on this website, such as `/old-page`.
- The **new address** is a path as well, or a complete web address starting with `https://`.

The form refuses redirects that would send visitors in a circle. A redirect to an address that is itself redirected is stored with the final destination.

## How they work

Redirects are only looked up when a request would otherwise end in "Page not found". Two consequences:

- A page that exists is never hidden by a redirect.
- Regular page views do not cost an extra database query.

## For developers

`App\Service\RedirectManager` creates, validates and looks up redirects; `App\EventSubscriber\RedirectSubscriber` answers 404 errors with a matching redirect. Automatic redirects are created in `App\Service\ContentManager::save()`.
