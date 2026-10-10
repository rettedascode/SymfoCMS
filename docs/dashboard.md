# The start page

After logging in – and under **Start** in the sidebar – everybody lands on a page of small boxes, the widgets:

| Widget | What it shows |
| --- | --- |
| Recent changes | The pages and posts that were changed last. Authors see their own posts, editors and administrators everything. |
| My drafts | Posts you wrote that are not published yet, including those waiting for a review |
| Scheduled posts | Posts that go out by themselves, the next one first |
| New form submissions | Submissions nobody has read yet. Only for people who manage forms. |

Each widget leads on: to the [board](editorial-planning.md), the calendar, the posts or the forms.

## Arranging

The arrangement is yours alone: every user has their own, and it is kept until you change it.

- **Move:** drag a widget by its handle – the six dots next to its title – to another place. With the keyboard: focus the handle, press Space, move with the arrow keys, press Space again. Enter on the handle opens the menu with "Move up", "Move down" and "Move to …".
- **Resize:** drag the right edge of a widget. It snaps to a third, half, two thirds or the full width of the page. With the keyboard: move to the edge with Tab and use the left and right arrow keys; Home and End go to the smallest and largest width.
- Every change is stored at once. The message at the bottom offers **Undo**.

On a narrow screen every widget takes the full width; the order stays the one you chose.

## For developers

Widgets, their default order and their widths are defined in `App\Dashboard\Dashboard`. The arrangement is stored with the user (`users.dashboard_layout`) as a list of `{id, size}`; widgets a user may no longer see are left out when it is read, and new widgets are added at the end.

```
POST /admin/dashboard/layout
{"widgets": [{"id": "drafts", "size": 3}, {"id": "recent", "size": 6}, {"id": "scheduled", "size": 3}]}
```

The request names every widget of the user exactly once, with a width of 2, 3, 4 or 6 columns of the six-column grid. Anything else is answered with `400` and changes nothing.
