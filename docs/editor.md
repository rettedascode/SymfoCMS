# The editor

Pages and blog posts are built from **blocks**. This page describes what the editor offers beyond typing text. Nothing here has to be learned to create a simple page: every feature stays out of the way until it is needed.

## Blocks

| Block | What it is for |
| --- | --- |
| Heading | A large or small heading inside the content |
| Text | Paragraphs with bold, italic, links and lists |
| Image | One image from the media library, with description and caption |
| Gallery | Several images in a grid of two, three or four per row; each opens in its large version |
| Video | A video from YouTube or Vimeo, with an optional preview image |
| Button | A link that looks like a button |
| Quote | A highlighted quote with its author |
| Divider | A horizontal line |
| Columns | Two or three columns that each hold their own blocks, with adjustable widths |
| Questions and answers | Questions that open to reveal their answers (an FAQ) |
| Form | A form from the [form builder](forms.md) |
| Map | A place on OpenStreetMap, found by typing its address |
| Code | Source code, shown exactly as typed and with syntax highlighting |
| Shared block | Content that is maintained in one place and shown on many pages |

### Adding blocks

- **Add block** at the end of the page, or the **+** symbol of a block to add one right below it.
- The list of blocks can be **searched**: start typing and press Enter to take the first match.
- **Slash command:** type `/` in an empty text block to turn it into another block without leaving the keyboard.

### Privacy of videos and maps

A video or map is **not loaded when the page opens**. Visitors first see a button with a short notice; only after they click is the player or map requested from YouTube, Vimeo or OpenStreetMap. Until then no data reaches these services. Each block also links directly to the video or map for those who prefer to open it there.

Videos are embedded through `youtube-nocookie.com` and with Vimeo's "do not track" option. For the preview image of a video, an image from your own media library is used, never one from the video platform.

In the editor, the address search of the map block sends the typed address to OpenStreetMap when *Find on the map* is pressed.

## History

Every time a page or post is saved, the previous version is kept. As soon as there is more than one version, the editor shows a **History** tab:

- **Preview** opens an earlier version as visitors would see it.
- **Restore** brings it back with one click. Restoring is itself a new version, so nothing is ever lost by it.

Restoring changes the title, the texts and the blocks. It never changes the web address or whether the content is published.

The last 50 versions are kept per page or post; set `APP_MAX_REVISIONS` to change that.

## Shared blocks

A shared block is content you write once and show in many places: opening hours, a call to action, a legal notice.

- **Create one from any block:** click the bookmark symbol of a block and give it a name. The block is replaced by a reference to the new shared block.
- **Insert one:** *Add block* → *Shared block*, then choose it from the list.
- **Edit it** under **Shared blocks** in the sidebar. The change appears on every page that shows the block. The edit screen lists these pages before you change anything, and the delete confirmation says how many there are.

Deleting a shared block does not break pages; they simply no longer show it. A shared block cannot contain other shared blocks.

Editors and admins create and edit shared blocks. Authors can insert existing ones into their posts.

## Page templates

*New page* offers a few starting points next to the blank page: **Landing page**, **About** and **Contact**. A template fills the new page with example blocks in the language of the page; from then on the page is edited freely and has no connection to the template.

Admins can turn any page into a template: open the page, switch to the **Settings** tab and choose **Save as template**. Saved templates are listed (and deleted) under *Pages* → *Page templates*.

## For developers

- New block types and their fields: [Extending SymfoCMS](extensions.md).
- The click-to-load behaviour lives in `assets/site.js`: any element with a `data-embed-src` attribute inside a `data-embed` container is replaced by an `<iframe>` when clicked. Block templates of your own can use the same markup (see `templates/blocks/video.html.twig`).
- Syntax highlighting is done on the server by `App\Service\CodeHighlighter`, so the website needs no JavaScript for it.

## Arranging blocks by dragging

Next to the page is the **palette** with all block types.

- **Drag** a block type into the page: a marked gap shows where the block will be created. It can also be dropped into a column.
- **Click** a block type (or press Enter on it) to add it at the end of the page.
- Blocks that are already on the page are moved by their handle – the six dots – with the mouse, a finger or the keyboard. See [drag and drop](drag-and-drop.md) for all the ways, including undo with Ctrl+Z.

On a phone the palette is a strip above the page that scrolls sideways.

### Columns

Blocks are put next to each other by dragging, without adding a "Columns" block first.

- **Drag a block onto the left or right edge of another block.** While you drag, every block on the page shows a coloured strip at both edges; over a strip the block opens a space for the new column. Drop it there and the two blocks stand side by side.
- **A third column** is made the same way: drop a block on the edge of existing columns. Three is the most.
- **Block types from the palette** can be dropped on an edge as well.
- **Drag a block out again** and its column goes away when it was the last block in it. With a single column left, the columns dissolve and the remaining blocks are ordinary blocks of the page again. Moving blocks between the columns of the same block changes nothing.
- **Widths:** drag the line between two columns. It snaps to twelfths of the width, and no column gets narrower than a quarter. A double click on the line makes all columns equally wide.

With the keyboard: pick a block up with Space; the right and left arrow keys lead to the right and left edge of the block above it (or below it, for the first block), and Space drops it there. "Move to …" in the menu of the handle lists the edges of all blocks. The line between columns takes the focus with Tab and is moved with the left and right arrow keys; Home and End go as far as possible.

Everything can be undone with Ctrl+Z. On the website – and in the editor on a narrow screen – columns stand below each other on small screens.

### Outline

**Outline** above the blocks opens a short list of all blocks of the page, columns with their blocks indented below them. A click on a line jumps to that block. The lines have the same handles as the blocks, so a long page can be rearranged without scrolling: by dragging, with the keyboard or through the menu of the handle. Whether the outline is open is remembered in your browser.
### Dropping images from the desktop

Drag image files from a folder of your computer into the editor. A line shows where they will go.

- **One image** becomes an image block at that place.
- **Several images** dropped together become a gallery.

The upload progress is shown right where the block will appear. Files that are too large or not an image (JPEG, PNG, GIF, WebP) are named in a message and left out; the others are still added.
