# Security Policy

## Supported versions

Security fixes are provided for the latest released version. Please update to the newest release before reporting a problem.

| Version | Supported |
| --- | --- |
| Latest release | ✔ |
| Older releases | – |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report vulnerabilities privately through GitHub: open the **Security** tab of this repository and choose **Report a vulnerability** ([how private vulnerability reporting works](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)).

Please include:

- what the problem is and what an attacker could do with it,
- the steps to reproduce it (a minimal example is ideal),
- the version or commit you tested, your PHP version and database.

## What to expect

- You will get a confirmation that the report was received, normally within a few days.
- The report is investigated and you are kept informed about the progress.
- Once a fix is released, the vulnerability is disclosed in the changelog and in a GitHub security advisory. You are credited if you wish.

Please give maintainers a reasonable amount of time to release a fix before you publish any details.

## Scope

In scope are vulnerabilities in the code of this repository, for example:

- bypassing permissions (acting as a role you do not have, reading drafts as a visitor),
- cross-site scripting through content, file names or uploads,
- cross-site request forgery, SQL injection, unsafe file uploads.

Out of scope are problems in third-party dependencies (please report them to the respective project) and issues that require an attacker to already be an administrator of the site.

## Notes for site owners

- Set a long, random `APP_SECRET` and run with `APP_ENV=prod` on a live site.
- Serve the site over HTTPS.
- Keep PHP and the dependencies up to date (`composer update`, `composer audit`).
- Never load the demo fixtures on a live site: their passwords are public.
