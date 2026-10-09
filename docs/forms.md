# Forms

Editors and admins build forms under **Forms** in the sidebar and place them on any page or post with the **Form** block. What visitors send is collected in an inbox.

## Building a form

1. Open **Forms** and choose **New form**.
2. Give the form a name (only you see it) and add fields:

   | Field | For |
   | --- | --- |
   | Short text | Names, subjects and other one-line answers |
   | Email address | An address that is checked for a valid format |
   | Long text | Messages of several lines |
   | Selection | One choice from a list you define |
   | Checkbox | An optional yes/no, e.g. "Send me the newsletter" |
   | Consent | A checkbox that must be ticked, e.g. to accept that the details are stored |

3. Mark the fields that have to be filled in, and drag them into the order you want.
4. On the right, set the text of the send button, the message visitors see after sending, and – optionally – an email address that is notified about every submission.

To show the form, open a page and add the block **Form**. The same form can be used on several pages.

Field labels are stored with every submission. If you rename or remove a field later, earlier submissions stay readable.

## The inbox

**Forms** shows how many new messages each form has. In the inbox of a form you can

- open a message (which marks it as read) and mark it as new again,
- delete messages,
- download everything as a **CSV file** for spreadsheet programs.

## Email notifications

Notifications are sent with Symfony Mailer. Configure how in `.env.local`:

```dotenv
MAILER_DSN=smtp://user:password@smtp.example.com:587
APP_MAIL_FROM=website@example.com
```

With the default `MAILER_DSN=null://null` nothing is sent; submissions are stored in the inbox either way. If sending fails, the visitor still sees the confirmation, the submission is stored and the problem is written to the log.

The notification's reply address is the first email field of the form, so you can answer a message by simply replying.

## Spam protection

Forms are protected without third-party services, cookies banners or puzzles for the visitor:

- **Honeypot:** a field that people cannot see but bots fill in. Such submissions are silently discarded.
- **Minimum fill time:** a form sent less than three seconds after the page was loaded is rejected with a request to try again. The time is carried in a signed field, so it cannot be faked.
- **Rate limiting:** at most five accepted submissions per form and visitor within ten minutes (`config/packages/rate_limiter.yaml`).

## Privacy

- Only the answers and the address of the page the form was sent from are stored. No IP address, no browser details.
- Under **Settings**, an admin can set after how many days messages are **deleted automatically**. Old messages are removed whenever a form is sent or the forms section is opened; `php bin/console app:forms:purge` does the same from a cron job.
- Deleting a form deletes its messages.

## For developers

- A form's fields are a JSON list of `{id, type, label, required, options}`; `App\Service\FormDefinition` validates what the builder sends.
- `App\Service\FormSubmitter` checks spam, validates, stores and notifies. `App\Service\SpamGuard` holds the honeypot and timing checks.
- The public markup is `templates/site/_form.html.twig`; a theme can restyle it through the `.site-form` classes.
- Block types of your own can show a form with `Field::form()` and `render_form(data.name)`.
