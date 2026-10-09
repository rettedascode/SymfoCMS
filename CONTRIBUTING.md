# Contributing to SymfoCMS

Thank you for considering a contribution! Bug reports, ideas, translations, documentation fixes and code are all welcome.

By taking part in this project you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## What this project is (and is not)

SymfoCMS wants to stay **small and simple to use**. The yardstick for every change is: can a non-technical person still create and edit a page without training?

Good fits: fixing bugs, improving accessibility, making the editor clearer, new translations, better documentation, tests.

Features that add settings, concepts or dependencies need a strong reason. Please open an issue to discuss larger ideas before writing code – it saves everybody time.

## Reporting bugs and suggesting features

- Use the issue templates for [bug reports and feature requests](../../issues/new/choose).
- Search existing issues first.
- **Never report security problems in a public issue.** Follow [SECURITY.md](SECURITY.md).

## Setting up a development environment

You need PHP 8.3+ and Composer (or just Docker).

```bash
git clone <URL of your fork> symfocms
cd symfocms
composer install
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load -n
php -S localhost:8000 -t public bin/dev-router.php
```

Or, with Docker: `docker compose up`.

Log in at <http://localhost:8000/login> with `admin@example.com` / `demo-password`.

## Making a change

1. Create a branch from `main`: `git checkout -b fix/short-description`.
2. Make your change. Keep pull requests focused on one topic.
3. Add or update tests. Behaviour without a test is likely to break later.
4. Run the checks – the same ones the CI runs:

   ```bash
   composer check
   ```

5. Add a line to the "Unreleased" section of [CHANGELOG.md](CHANGELOG.md) if users will notice the change.
6. Open a pull request and fill in the template.

### Coding guidelines

- **PHP** follows the [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html). `composer cs-fix` formats the code for you; PHPStan runs at level 8.
- **Permissions** are checked on the server with voters (`src/Security/Voter`). Hiding a button is never enough.
- **Anything a user typed** is validated or sanitized before it is stored and escaped when it is shown. Do not use `|raw` in templates except for rich text that went through `BlockNormalizer`.
- **JavaScript** is plain ES modules loaded through AssetMapper – no build step, no npm. Keep dependencies to a minimum.
- **Interface texts** go into `translations/messages.en.yaml` **and** `translations/messages.de.yaml`. Write them for people who are not technical: short, friendly, no jargon.
- **Database changes** need a Doctrine migration that works on SQLite, MySQL and PostgreSQL. Prefer the schema API (`$schema->getTable(...)`) over raw SQL, as in the existing migration.
- **Accessibility** matters in the admin area and in the public theme: every control needs a label, everything must work with a keyboard, and drag and drop always has a button alternative.
- Code, comments, commit messages and documentation are written in English.

### Commit messages

Use the imperative mood and say what the change does, for example `Fix slug validation for pages with umlauts`. Explain the "why" in the body when it is not obvious.

### Adding a block type

See [Adding a custom block type](README.md#adding-a-custom-block-type) in the README. New built-in block types should be useful for most websites; very specific blocks are better kept in your own project.

### Adding a translation

See [Adding a language](README.md#adding-a-language) in the README. Translations of all three files (`messages`, `validators`, `security`) are very welcome.

## Releases

The project follows [Semantic Versioning](https://semver.org). Maintainers move the "Unreleased" entries of the changelog into a new version section and tag the release.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
