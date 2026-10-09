<?php

namespace App\Tests\Functional;

use App\Entity\MenuItem;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class MenuTest extends AppWebTestCase
{
    public function testAdminBuildsMainNavigationWithDropdown(): void
    {
        $this->loginAs(UserRole::Admin);
        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $about = $this->createPage('About', ContentStatus::Published);
        $team = $this->createPage('Team', ContentStatus::Published);
        $draft = $this->createPage('Upcoming', ContentStatus::Draft);

        $this->saveMenu('main', [
            ['type' => 'page', 'page' => $home->getId(), 'label' => 'Start'],
            ['type' => 'page', 'page' => $about->getId(), 'label' => '', 'children' => [
                ['type' => 'page', 'page' => $team->getId(), 'label' => 'Our team'],
                ['type' => 'url', 'url' => 'https://example.com/jobs', 'label' => 'Jobs'],
            ]],
            ['type' => 'page', 'page' => $draft->getId(), 'label' => 'Upcoming'],
            ['type' => 'url', 'url' => 'javascript:alert(1)', 'label' => 'Evil'],
            ['type' => 'page', 'page' => 99999, 'label' => 'Missing page'],
        ]);

        $stored = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];
        self::assertSame(['Start', 'About', 'Upcoming'], array_column($stored, 'label'), 'Invalid items are dropped and empty labels default to the page title.');
        self::assertSame(['Our team', 'Jobs'], array_column($stored[1]['children'], 'label'));

        // Public website: the draft is hidden for visitors, the dropdown is rendered as a nested list.
        $this->client->request('GET', '/logout-not-needed');
        $this->client->restart();
        $crawler = $this->client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSame(['Start', 'About'], $crawler->filter('.site-nav__list > li > a')->each(static fn ($a) => $a->text()));
        self::assertSame('/', $crawler->filter('.site-nav__list > li > a')->first()->attr('href'));
        self::assertSame('page', $crawler->filter('.site-nav__list a[href="/about"]')->attr('aria-current'));
        self::assertSame(['/team', 'https://example.com/jobs'], $crawler->filter('.site-nav__sub a')->each(static fn ($a) => $a->attr('href')));
        self::assertSelectorExists('.has-children > button[aria-expanded="false"]');
    }

    public function testFooterNavigationIsFlat(): void
    {
        $this->loginAs(UserRole::Admin);
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $imprint = $this->createPage('Imprint', ContentStatus::Published);
        $privacy = $this->createPage('Privacy', ContentStatus::Published);

        $this->saveMenu('footer', [
            ['type' => 'page', 'page' => $imprint->getId(), 'label' => 'Imprint', 'children' => [
                ['type' => 'page', 'page' => $privacy->getId(), 'label' => 'Privacy'],
            ]],
        ]);

        self::assertSame(0, $this->em()->getRepository(MenuItem::class)->count(['location' => 'main']));
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Imprint', 'Privacy'], $crawler->filter('.footer-nav > li > a')->each(static fn ($a) => $a->text()));
    }

    public function testSavingReplacesThePreviousMenu(): void
    {
        $this->loginAs(UserRole::Admin);
        $page = $this->createPage('Home', ContentStatus::Published);

        $this->saveMenu('main', [['type' => 'page', 'page' => $page->getId(), 'label' => 'One', 'children' => [['type' => 'url', 'url' => '/x', 'label' => 'Child']]]]);
        $this->saveMenu('main', [['type' => 'url', 'url' => '/blog', 'label' => 'Blog']]);

        $items = $this->em()->getRepository(MenuItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('Blog', $items[0]->getLabel());
    }

    public function testDeletingAPageRemovesItsMenuItems(): void
    {
        $this->loginAs(UserRole::Admin);
        $page = $this->createPage('Temporary', ContentStatus::Published);
        $this->saveMenu('main', [['type' => 'page', 'page' => $page->getId(), 'label' => 'Temporary']]);

        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/delete', ['_token' => $token]);

        self::assertSame(0, $this->em()->getRepository(MenuItem::class)->count([]));
    }

    public function testOnlyAdminsManageMenus(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/menus');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/menus/main', content: '{"items":[]}');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownMenuLocationIsNotFound(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', '/admin/menus/sidebar');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function saveMenu(string $location, array $items): void
    {
        $crawler = $this->client->request('GET', '/admin/menus/'.$location);
        self::assertResponseIsSuccessful();
        $config = json_decode((string) $crawler->filter('[data-controller="menu"]')->attr('data-menu-config-value'), true);

        $this->client->request('POST', '/admin/menus/'.$location, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $config['csrf'],
        ], content: $this->json(['items' => $items]));
        self::assertResponseIsSuccessful();
    }
}
