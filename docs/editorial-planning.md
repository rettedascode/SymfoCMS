# Planning the blog: the board

Under **Blog** there is, next to the list of posts, a view for planning: the **board**.

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

The question-and-answer protocol is shared with the page tree; in the browser it is `sendConfirmed()` in `assets/lib/confirm.js`.
