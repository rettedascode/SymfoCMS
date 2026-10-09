# Extending SymfoCMS

SymfoCMS can be extended without changing its own files. An extension is an ordinary Composer package containing a Symfony bundle. It can

- add **block types** to the editor, and
- react to **events** when content is saved, published, deleted or rendered.

A complete working example lives in [`examples/callout-block-bundle`](../examples/callout-block-bundle). The test suite loads it, so it always matches the current API.

## Block types

### The interface

A block type is a service that implements `App\Block\BlockTypeInterface`. Every such service is found automatically (the interface carries the `app.block_type` service tag) and offered in the "Add block" menu.

| Method | Purpose |
| --- | --- |
| `static getType(): string` | Unique identifier stored with the content, e.g. `acme_callout`. Prefix it with your vendor name and never change it once content exists. |
| `getLabel(): string` | Name shown in the editor: a translation key or plain text. |
| `getIcon(): string` | One or two characters shown next to the label. |
| `getFields(): list<Field>` | The inputs of the block. The editor builds its form from them – no JavaScript needed. |
| `validate(array $data): array` | Checks the data before content is saved. Returns `field name => message`; empty when valid. |
| `getTemplate(): string` | Twig template that renders the block on the website. It receives the variable `data`. |
| `getPosition(): int` | Sort order in the "Add block" menu, lowest first. |

Extend `App\Block\AbstractBlockType` to get sensible defaults for everything except `getType()`.

### Fields

| Field | Editor shows | Value in the template |
| --- | --- | --- |
| `Field::text()` | single-line input | string |
| `Field::textarea()` | multi-line input | string (use `\|nl2br`) |
| `Field::richText()` | text with bold, italic, links, lists | sanitized HTML (use `\|raw`) |
| `Field::select()` | dropdown | one of the option keys |
| `Field::url()` | link input | safe URL or empty string |
| `Field::image()` | image picker | media id – use `cms_media(data.image)` |
| `Field::checkbox()` | checkbox | `true` / `false` |
| `Field::blocks()` | nested list of blocks | list – use `render_blocks(data.name)` |
| `Field::sharedBlock()` | choice of a shared block | id – use `render_shared_block(data.name)` |

Every field accepts `->help('…')`, `->placeholder('…')` and `->required()`.

Before your block is validated, stored or rendered, its data is cleaned up against these definitions: unknown keys are dropped, values are cast to the type of their field, rich text is sanitized and links are checked. A template can therefore trust `data`.

### Validation

`AbstractBlockType::validate()` reports required fields that were left empty. Override it for your own rules:

```php
public function validate(array $data): array
{
    $errors = parent::validate($data);

    if (mb_strlen((string) $data['title']) > 80) {
        $errors['title'] = 'acme_callout.title_too_long'; // translation key or plain text
    }

    return $errors;
}
```

When a block is invalid, the content is not saved and the editor shows the message below the field. Nothing the user typed is lost.

### A block type in a package

```php
// src/AcmeCalloutBlockBundle.php
final class AcmeCalloutBlockBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()->set(CalloutBlock::class)->autowire()->autoconfigure();
    }
}
```

```php
// src/CalloutBlock.php
final class CalloutBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'acme_callout';
    }

    public function getFields(): array
    {
        return [
            Field::text('title', 'acme_callout.title')->required(),
            Field::textarea('text', 'acme_callout.text'),
        ];
    }

    public function getTemplate(): string
    {
        return '@AcmeCalloutBlock/blocks/callout.html.twig';
    }
}
```

Put the template into the bundle's `templates/` directory and the labels into `translations/messages.<locale>.yaml`; Symfony finds both automatically. Without autoconfiguration, tag the service with `app.block_type` yourself.

A block type that lives inside a project (not in a package) is even simpler: see [Adding a custom block type](../README.md#adding-a-custom-block-type) in the README.

## Events

All events are in the `App\Event` namespace and are dispatched for pages and posts alike. `getContent()` returns the `App\Entity\Page` or `App\Entity\Post`.

| Event | When | What a listener can do |
| --- | --- | --- |
| `ContentSavingEvent` | Right before content is written to the database | Change the entity, replace the blocks with `setBlocks()`. `isNew()` tells whether it is stored for the first time. |
| `ContentSavedEvent` | After content was written | React to the change, e.g. clear a cache or notify somebody. |
| `ContentPublishedEvent` | After a save that changed the status to "published" | React to publishing. A post scheduled for the future is published in this sense too – check `Post::isScheduled()` if that matters. |
| `ContentDeletedEvent` | Right before content is removed, while its id is still known | Clean up related data. |
| `ContentRenderEvent` | When a page or post is about to be shown on the public website | Replace the blocks that are shown with `setBlocks()` (the stored content stays untouched) and pass extra variables to the template with `setTemplateVariable()`. |

Autosaves do not trigger these events: they only store a backup of unsaved work.

```php
use App\Entity\Post;
use App\Event\ContentPublishedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class AnnounceNewPosts
{
    #[AsEventListener]
    public function onPublish(ContentPublishedEvent $event): void
    {
        $content = $event->getContent();
        if ($content instanceof Post && !$content->isScheduled()) {
            // e.g. send a message to your team chat
        }
    }
}
```

## Stability

The interfaces and classes described on this page – `App\Block\BlockTypeInterface`, `AbstractBlockType`, `Field` and the events in `App\Event` – are the extension API. Changes to them follow [Semantic Versioning](https://semver.org) and are listed in the [changelog](../CHANGELOG.md). Everything else is internal and may change in any release.
