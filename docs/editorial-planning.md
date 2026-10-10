# Planning the blog: board and calendar

Under **Blog** there are, next to the list of posts, two views for planning: the **board** shows the posts by status, the **calendar** by date.

## Board

The board shows every post as a card in the column of its status: **Draft**, **In review** and **Published**. Scheduled posts stand in the last column with the badge "Scheduled"; of the posts that are out, the twenty newest are shown.

- **Drag a card into another column** to give the post that status. A draft dropped into "Published" is published at once (or scheduled, if it has a date in the future); a post dropped into "In review" is handed to the editors.
- **Keyboard:** focus the handle of a card, press Space to pick it up, move it with the arrow keys and press Space to drop it. The arrow keys only stop in columns the card may go to. Enter on the handle opens the menu with "Move to …".
- Every move is stored at once. The message at the bottom offers **Undo**.

### What is allowed

A column only takes cards that may get its status. The steps are those of the [review workflow](review-workflow.md), and they depend on who you are:

| Step | Who |
| --- | --- |
| Draft → In review, In review → Draft | Everybody who may edit the post |
| In review → Published, Draft → Published, Published → Draft | Editors and administrators; authors for their own posts as long as the website does not require a review |

A published post never goes straight to "In review". While a card is dragged, columns it cannot go to are dimmed and show "not allowed".

Authors see their own posts on the board, editors and administrators all posts.

### Taking a post offline

Dropping a post that visitors can read right now into "Draft" removes it from the website. The board asks first; "Leave it as it is" puts the card back.

## Calendar

The calendar shows one month, Monday to Sunday. Every post that has a publish date stands on its day, with its time and status: published, scheduled, and also drafts and posts in review that were given a date. **Earlier** and **Later** turn the pages.

- **Drag a post to another day** to change its publish date. The time of day stays.
- **Keyboard:** focus the handle of a post, press Space, and move it with the arrow keys – every step is one day, and the day is read out. Space drops it, Escape puts it back. "Move to …" in the menu of the handle lists all days of the sheet.
- Every move is stored at once. The message at the bottom offers **Undo** and tells what the move means: a scheduled post moved into the past is public at once, and the message says so.

On a narrow screen the month is a list of days.

### Taking a post offline

Moving a post that visitors can read right now to a day in the future takes it off the website until that day. The calendar asks first; "Leave it where it was" puts the post back.

### Who may move what

Drafts and posts in review can be planned by everybody who may edit them. The date of a published or scheduled post decides when visitors see it, so it can only be moved by those who may publish: editors and administrators, and authors for their own posts as long as the website does not require a review. Posts you cannot move have no handle.

## For developers

```
POST /admin/posts/board/move
{"id": 7, "status": "published"}
```

| Answer | Meaning |
| --- | --- |
| `200 {"status", "state", "label", "allowed", "date"}` | Stored. `allowed` are the statuses the post may get next for this user. |
| `409 {"confirm": "…"}` | The post is public and would go offline. Nothing was stored; send the move again with `"confirmed": true`. |
| `422 {"error": "…"}` | The workflow does not allow this step for this user. |
| `403` | The user may not edit this post, or the CSRF token is missing. |

```
POST /admin/posts/calendar/move
{"id": 7, "date": "2026-10-14"}
```

The answers follow the same pattern: `200` with `date`, `time`, `state`, `label` and a `message` for the person, `409 {"confirm"}` when a public post would go offline, `422 {"error"}` when the user may not change the date of this post, `400` for anything that is not a date.

The question-and-answer protocol is shared with the page tree; in the browser it is `sendConfirmed()` in `assets/lib/confirm.js`.
