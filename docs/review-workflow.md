# Review workflow

On a website with several writers, an editor may want to read a post before it goes public. The review workflow does that: **authors submit, editors approve**.

It is off until an administrator ticks *Settings → Blog → Posts by authors have to be approved by an editor*. Websites with one or two trusted writers can simply leave it off; authors then publish their own posts as before.

## How it works

A post is in one of three states: **Draft**, **In review** or **Published**.

| Who | Does what |
| --- | --- |
| Author | Writes a draft and chooses **Submit for review**. While the post waits, the author can still improve it or **take it back**. |
| Editor or administrator | Sees a note above the list of posts when something is waiting. Opens the post and either chooses **Approve and publish**, or writes a short note and chooses **Send back**. |
| Author | Finds the note at the top of the post, makes the changes and submits again. |

A post that is in review is not visible to visitors and is not found by the search.

Once a post is public it is out of the author's hands: authors cannot change or delete published posts while reviews are required. An editor can set it back to *Draft* in the settings of the post, which returns it to its author.

Editors and administrators are not slowed down: they publish their own posts – and all pages – directly.

## For developers

- The steps are defined with the Symfony Workflow component in `config/packages/workflow.yaml` (state machine `content_review`): `submit`, `withdraw`, `approve`, `reject`, `publish`, `unpublish`.
- `App\Workflow\ReviewGuard` decides who may take a step; `App\Workflow\ReviewWorkflow` applies them. Every change of status made in the editor goes through the workflow, so a crafted request cannot skip the review.
- Code without a logged-in user – console commands, fixtures, imports – may take every defined step.
- Listen to the usual workflow events to react, for example to tell editors by email that something is waiting:

  ```php
  #[AsEventListener('workflow.content_review.completed.submit')]
  public function onSubmitted(CompletedEvent $event): void
  {
      $post = $event->getSubject();
      // ...
  }
  ```

- `ContentPublishedEvent` is still dispatched when content becomes public, whichever step led there.
