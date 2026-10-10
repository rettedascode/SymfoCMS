# Drag and drop

Arranging things in the admin area works the same way everywhere: blocks in the editor, entries of a menu, fields of a form.

## Three ways to move something

Every item that can be moved has a **handle** – the six dots at its left edge.

| | |
| --- | --- |
| **Mouse or finger** | Drag the handle. The item follows the pointer, and a marked gap shows where it will land. A list that cannot take the item is striped red and the cursor shows "not allowed". Near the top and bottom of the screen the page scrolls along. On touch screens, press the handle for a moment before dragging, so that swiping still scrolls. |
| **Keyboard** | Move to the handle with Tab. **Space** picks the item up, the **arrow keys** carry it, **Space** drops it, **Escape** puts it back. Where items can be nested – sub-items of a menu, blocks in columns – the up and down keys walk through every possible place, and **Right** and **Left** move an item into the one above it and out again. Every step is read out by screen readers. |
| **Menu** | Click the handle (or press Enter on it): **Move up**, **Move down** and **Move to …**, which lists every possible destination. |

## Undo

In editors – pages, posts, menus, forms – **Ctrl+Z** takes back the last change of the structure (adding, moving, deleting) and **Ctrl+Shift+Z** makes it again. Inside a text field the same keys undo typing, as usual. Nothing is stored before you save.

Screens that save every move at once show a short message with an **Undo** button instead.

## Dropping files

Image files can be dragged from the desktop into the media library and into the image picker. Files that are too large or not an image are refused with a message before anything is uploaded; the others show their progress.

## For developers

All of this is one layer, `assets/lib/dnd.js`, wrapped by four Stimulus controllers. New screens should use it instead of talking to SortableJS themselves, so that dragging stays identical everywhere.

### Markup

```html
<ul data-controller="sortable" data-sortable-group-value="gallery" aria-label="Images of the gallery">
    <li data-dnd-item data-dnd-id="7" data-dnd-label="Harbour">
        <button type="button" class="dnd-handle" data-dnd-handle aria-label="Move Harbour">…</button>
        …
    </li>
</ul>
```

- Items are direct children of the list and carry `data-dnd-item`.
- The handle is a `<button data-dnd-handle>`; in JavaScript, `handle(label)` from `lib/dnd.js` creates one.
- `data-dnd-label` names the item in announcements, `aria-label` on the list names the list in "Move to …".
- Lists with the same group exchange items. A list inside an item makes a tree.
- `data-dnd-empty-hint="Drop here"` gives an empty list a visible drop area while something is being moved.

### Controllers

| Controller | Use |
| --- | --- |
| `sortable` | One list. `accept` is a selector that items must match to be dropped there. |
| `tree` | Nested lists (`data-tree-target="list"`), with a maximum depth and collapsible branches whose state is remembered. An item never moves into itself or its children. |
| `palette` | A fixed set of things to drag into a list to create something there. Dragging copies; clicking dispatches `palette:add`. |
| `dropzone` | Files from the desktop: checks type and size, uploads with progress, dispatches `dropzone:uploaded`. |

### Events

| Event | When | Detail |
| --- | --- | --- |
| `dnd:move` | An item arrived at a new place – by pointer, keyboard or menu. Dispatched once, on the list it landed in. | `item`, `from`, `to`, `oldIndex`, `newIndex`, `cause`, `revert()` |
| `dnd:insert` | Something from a palette was dropped into a list. | `source`, `to`, `index` |

Editors that keep their content in memory until "Save" update their data in a `dnd:move` listener and record the step in a `History` (`assets/lib/history.js`) for undo and redo.

### Saving at once

Give `sortable` or `tree` a `url` and a `csrf` value and every move is sent to the server as JSON:

```json
{"id": 7, "parent": null, "position": 2}
```

The interface changes at once. If the server answers with an error, the item goes back and the message from `{"error": "…"}` is shown; after success a toast offers **Undo**.

On the server:

```php
#[Route('/admin/things/move', methods: ['POST'])]
public function move(Request $request, TreeMover $mover): JsonResponse
{
    $this->requireCsrfToken($request);                 // the token is sent in the X-CSRF-Token header
    $move = MoveRequest::fromRequest($request);        // 400 for anything malformed
    $thing = $this->things->find($move->id) ?? throw $this->createNotFoundException();
    $this->denyAccessUnlessGranted('THING_EDIT', $thing);   // the usual voters decide

    try {
        $mover->move($thing, $parent, $move->position, $siblings, $oldSiblings, maxDepth: 3);
    } catch (InvalidMoveException $exception) {
        return $this->json(['error' => $translator->trans($exception->getMessage())], 422);
    }
    …
}
```

`App\DragDrop\TreeMover` works on anything that implements `TreeNode` (parent and position). It refuses to move a node into itself or its descendants, enforces the maximum depth and renumbers the siblings without gaps. What the browser shows is never trusted.

### Looks and motion

The classes are in `assets/styles/dnd.css`: `dnd-handle`, `dnd-placeholder` (the landing spot), `dnd-preview` (the copy at the pointer), `dnd-rejects` and `dnd-not-allowed`, `dnd-carried` (picked up with the keyboard). With the system setting "reduce motion", lists do not animate and the preview is not tilted.

Interface text lives under `dnd.` in the translation files and reaches the browser through the `<script id="dnd-i18n">` element of the admin layout.

### Testing

What happens in the browser – dragging, the keyboard path, announcements, file drops – is tested with Symfony Panther in `tests/Browser/`:

```bash
vendor/bin/bdi detect drivers   # once: downloads the driver for the installed Chrome into ./drivers
composer test:browser
```

`App\Tests\Browser\BrowserTestCase` offers `drag()`, `press()` and `announcement()` for new tests. Without a browser the tests are skipped.
