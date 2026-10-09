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
| Two columns | Two columns that each hold their own blocks |
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
