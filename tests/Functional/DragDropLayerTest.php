<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

/**
 * What the server contributes to the shared drag-and-drop layer on every admin page.
 * The behaviour in the browser is covered by the browser tests.
 */
final class DragDropLayerTest extends AppWebTestCase
{
    public function testAdminPagesCarryTheTextsOfTheLayerInTheLanguageOfTheUser(): void
    {
        $user = $this->loginAs(UserRole::Author);

        $crawler = $this->client->request('GET', '/admin/posts');
        self::assertResponseIsSuccessful();
        $texts = json_decode($crawler->filter('script#dnd-i18n')->text(), true, 8, \JSON_THROW_ON_ERROR);
        foreach (['handle', 'handle_hint', 'move_up', 'move_down', 'move_to', 'grabbed', 'dropped', 'cancelled', 'instructions', 'undo', 'save_failed', 'file_too_large', 'file_wrong_type'] as $key) {
            self::assertArrayHasKey($key, $texts);
        }
        self::assertSame('Move up', $texts['move_up']);
        self::assertStringContainsString('dnd', (string) $crawler->filter('link[rel="stylesheet"][href*="dnd"]')->attr('href'));

        $this->entity($user::class, $user->getId())->setLocale('de');
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/admin/posts');
        $texts = json_decode($crawler->filter('script#dnd-i18n')->text(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame('Nach oben', $texts['move_up']);
        self::assertSame('Rückgängig', $texts['undo']);
    }

    public function testTextsCannotBreakOutOfTheScriptElement(): void
    {
        $this->loginAs(UserRole::Author);

        $html = (string) $this->client->request('GET', '/admin/posts')->filter('script#dnd-i18n')->html();

        self::assertStringNotContainsString('<', $html);
    }

    public function testMediaLibraryAcceptsDroppedFilesWithTheLimitsOfTheServer(): void
    {
        $this->loginAs(UserRole::Author);

        $crawler = $this->client->request('GET', '/admin/media');
        $zone = $crawler->filter('[data-controller~="dropzone"]');

        self::assertCount(1, $zone);
        self::assertSame('/admin/media/upload', $zone->attr('data-dropzone-url-value'));
        self::assertSame(['image/jpeg', 'image/png', 'image/gif', 'image/webp'], json_decode((string) $zone->attr('data-dropzone-types-value'), true));
        self::assertGreaterThan(0, (int) $zone->attr('data-dropzone-max-bytes-value'));
        self::assertNotSame('', $zone->attr('data-dropzone-csrf-value'));
        self::assertCount(1, $zone->filter('input[type="file"][multiple]'));
    }

    public function testEditorsGetTheLabelsTheirListsAnnounce(): void
    {
        $this->loginAs(UserRole::Admin);
        $page = $this->createPage('About', ContentStatus::Draft);

        $config = json_decode((string) $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit')->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);
        self::assertSame('Blocks of the page', $config['i18n']['list_page']);
        self::assertSame('Blocks of the column', $config['i18n']['list_column']);

        $config = json_decode((string) $this->client->request('GET', '/admin/menus')->filter('[data-controller="menu"]')->attr('data-menu-config-value'), true);
        self::assertSame('Top level of the menu', $config['i18n']['list_top']);
        self::assertStringContainsString('%title%', $config['i18n']['list_inside']);
    }
}
