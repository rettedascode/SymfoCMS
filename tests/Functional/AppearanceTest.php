<?php

namespace App\Tests\Functional;

use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class AppearanceTest extends AppWebTestCase
{
    public function testAdminAreaOffersTheAppearanceSwitchToEverybody(): void
    {
        foreach ([UserRole::Author, UserRole::Admin] as $role) {
            $this->client->loginUser($this->createUser($role));
            $crawler = $this->client->request('GET', '/admin/posts');

            self::assertResponseIsSuccessful();
            $switch = $crawler->filter('.sidebar__footer button[data-controller="theme"]');
            self::assertCount(1, $switch, $role->name);
            self::assertStringContainsString('Appearance: Like the device', $switch->text());
            self::assertSame(['Like the device', 'Light', 'Dark'], [$switch->attr('data-theme-auto-value'), $switch->attr('data-theme-light-value'), $switch->attr('data-theme-dark-value')]);
            // The stored choice is applied in the head, before anything is drawn.
            self::assertStringContainsString('symfocms-admin-theme', $crawler->filter('head script')->first()->text());
        }
    }

    public function testPagesWithoutSidebarFollowTheChoiceToo(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertStringContainsString('symfocms-admin-theme', $crawler->filter('head script')->text());
    }

    public function testStylesheetCarriesTheDarkPaletteForSystemAndSwitch(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->request('GET', (string) $crawler->filter('link[rel="stylesheet"]')->attr('href'));
        self::assertResponseIsSuccessful();
        $css = (string) $this->client->getInternalResponse()->getContent();

        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString(':root:not([data-theme="light"])', $css);
        self::assertStringContainsString(':root[data-theme="dark"]', $css);

        // Both rules carry the same palette, so the switch and the system setting look alike.
        preg_match('/:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css, $system);
        preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $manual);
        $normalize = static fn (string $block): string => (string) preg_replace('/\s+/', ' ', trim($block));
        self::assertSame($normalize($system[1] ?? 'system'), $normalize($manual[1] ?? 'manual'));
        self::assertStringContainsString('color-scheme: dark', $manual[1] ?? '');
    }
}
