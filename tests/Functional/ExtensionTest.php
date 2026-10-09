<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Event\ContentDeletedEvent;
use App\Event\ContentPublishedEvent;
use App\Event\ContentRenderEvent;
use App\Event\ContentSavedEvent;
use App\Event\ContentSavingEvent;
use App\Tests\AppWebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Covers what third-party packages rely on: block types from a bundle
 * (examples/callout-block-bundle is loaded in the test environment) and the content events.
 */
final class ExtensionTest extends AppWebTestCase
{
    public function testBlockTypeFromABundleIsOfferedSavedAndRendered(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Opening Hours', ContentStatus::Published);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        $callout = array_values(array_filter($config['types'], static fn (array $type): bool => 'acme_callout' === $type['type']));
        self::assertCount(1, $callout, 'The block type of the example bundle must be discovered.');
        self::assertSame('Callout', $callout[0]['label']);
        self::assertTrue($callout[0]['fields'][0]['required']);

        $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'acme_callout', 'data' => ['title' => 'Closed on Monday', 'text' => 'See you on Tuesday.', 'tone' => 'warning']],
        ])]);
        self::assertResponseRedirects();

        $this->client->request('GET', '/opening-hours');
        self::assertSelectorTextContains('.block-callout--warning .block-callout__title', 'Closed on Monday');
    }

    public function testInvalidBlocksAreReportedAndNotSaved(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Opening Hours', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $crawler = $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'heading', 'data' => ['text' => 'Fine', 'level' => '2']],
            ['type' => 'acme_callout', 'data' => ['title' => '', 'text' => 'No title']],
            ['type' => 'columns', 'data' => ['left' => [['type' => 'acme_callout', 'data' => ['title' => str_repeat('x', 90)]]], 'right' => []]],
        ])]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'need your attention');

        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame([
            '1:title' => 'This field is required.',
            '2.left.0:title' => 'Please keep the title shorter than 80 characters.',
        ], $config['errors']);
        self::assertCount(3, $config['blocks'], 'The editor keeps what was typed.');

        self::assertSame([], $this->entity(Page::class, $page->getId())->getBlocks(), 'Nothing is stored while there are errors.');
    }

    public function testContentEventsAreDispatched(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Events', ContentStatus::Draft);
        $id = $page->getId();

        // Keep one kernel for all requests so the listeners registered here stay in place.
        $this->client->disableReboot();
        $log = [];
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ContentSavingEvent::class, static function (ContentSavingEvent $event) use (&$log): void {
            $log[] = 'saving';
            $event->setBlocks([...$event->getBlocks(), ['type' => 'divider', 'data' => []]]);
        });
        $dispatcher->addListener(ContentSavedEvent::class, static function () use (&$log): void { $log[] = 'saved'; });
        $dispatcher->addListener(ContentPublishedEvent::class, static function () use (&$log): void { $log[] = 'published'; });
        $dispatcher->addListener(ContentDeletedEvent::class, static function () use (&$log): void { $log[] = 'deleted'; });
        $dispatcher->addListener(ContentRenderEvent::class, static function (ContentRenderEvent $event) use (&$log): void {
            $log[] = 'render';
            $event->setBlocks([['type' => 'heading', 'data' => ['text' => 'Added by a listener', 'level' => '2']], ...$event->getBlocks()]);
        });

        $this->client->request('GET', '/admin/pages/'.$id.'/edit');
        $this->client->submitForm('Publish', ['page[blocks]' => $this->json([['type' => 'quote', 'data' => ['text' => 'Hello', 'author' => '']]])]);
        self::assertSame(['saving', 'saved', 'published'], $log);
        self::assertSame(['quote', 'divider'], array_column($this->entity(Page::class, $id)->getBlocks(), 'type'), 'A listener may change the blocks before they are stored.');

        // Saving again does not publish again.
        $log = [];
        $this->client->request('GET', '/admin/pages/'.$id.'/edit');
        $this->client->submitForm('Save changes', ['page[blocks]' => '[]']);
        self::assertSame(['saving', 'saved'], $log);

        $log = [];
        $this->client->request('GET', '/events');
        self::assertSame(['render'], $log);
        self::assertSelectorTextSame('h2.block-heading', 'Added by a listener');
        self::assertSame(['divider'], array_column($this->entity(Page::class, $id)->getBlocks(), 'type'), 'Rendering never changes what is stored.');

        $log = [];
        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$id.'/delete', ['_token' => $token]);
        self::assertSame(['deleted'], $log);
    }
}
