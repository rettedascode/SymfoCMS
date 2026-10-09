# Callout block – example extension

A complete, minimal SymfoCMS extension. It adds one block type, "Callout", without changing any file of the CMS itself.

```text
composer.json                          package definition, type "symfony-bundle"
src/AcmeCalloutBlockBundle.php         registers the block type as a service
src/CalloutBlock.php                   the block type: fields and validation
templates/blocks/callout.html.twig     how the block looks on the website
translations/messages.*.yaml           labels shown in the editor
```

The test suite of SymfoCMS loads this bundle, so it is guaranteed to keep working.

To try it in your own installation, add the bundle to `config/bundles.php`:

```php
Acme\CalloutBlock\AcmeCalloutBlockBundle::class => ['all' => true],
```

and make its classes loadable in `composer.json` (a published package would do this through its own `autoload` section):

```json
"autoload": {
    "psr-4": {
        "App\\": "src/",
        "Acme\\CalloutBlock\\": "examples/callout-block-bundle/src/"
    }
}
```

Then run `composer dump-autoload`. See [docs/extensions.md](../../docs/extensions.md) for the full guide.
