# Activity log

The activity log answers "who changed this, and when?". Administrators find it at the bottom of the *Settings* page.

## What is recorded

| | |
| --- | --- |
| Pages and posts | Created, changed, published, taken offline, submitted for review, returned to draft, deleted |
| Images, categories, forms, shared blocks, page templates, redirects | Created, changed, deleted |
| Menus | Changed (one entry per saved menu) |
| Users | Created, changed, deleted – including changes of role, password and two-step login |
| Settings | Which setting was changed |
| Logins | Successful logins and failed attempts with the email address that was tried |
| Imports and exports | Each run |

Every entry shows the time, the person and the title of the thing at that moment, so the log stays readable after a page or a user has been deleted. For changes it lists **which fields** were changed – never the old or new values. The log shows *that* a password was changed, not what it is.

Changes made without a logged-in person, for example by a console command, appear as *System*. Saving while typing (autosave) is not recorded.

## Finding something

- **Who** – everything one person did.
- **What** – one kind of thing, for example only pages.
- **Title or name contains** – entries about a certain page, image or user.
- The **↺** next to a title shows the whole history of that one entry.

## Retention and privacy

Entries are kept for one year and then removed automatically. Set `APP_AUDIT_DAYS` in the environment to keep them for another period; `0` keeps them forever.

The log contains names of users and the email addresses of failed login attempts. It is visible to administrators only and is not part of the website export.

## For developers

- `App\EventSubscriber\AuditSubscriber` listens to Doctrine and records every stored change of the entities above, wherever it comes from. Entities of an extension are not recorded automatically.
- To record something else, call the logger:

  ```php
  public function __construct(private readonly AuditLogger $audit) {}

  $this->audit->log('updated', 'newsletter', $newsletter->getId(), $newsletter->getSubject());
  ```

  Add the translations `audit.action.<action>` and `audit.type.<type>` for new words.
- Entries are rows of the `audit_log` table (`App\Entity\AuditEntry`). They are written with plain SQL after Doctrine has finished, and a failure to write never blocks the change itself.
