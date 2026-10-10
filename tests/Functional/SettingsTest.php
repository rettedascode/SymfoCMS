<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

final class SettingsTest extends AppWebTestCase
{
    public function testAdminRenamesTheWebsite(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->createPage('About', ContentStatus::Published);
        $this->loginAs(UserRole::Admin);

        // Until a name is entered, the one from the configuration is used.
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertSame('Test Site', $crawler->filter('#settings_siteName')->attr('value'));

        $this->client->submitForm('Save', [
            'settings[siteName]' => 'Café <Luna>',
            'settings[siteTagline]' => 'Coffee & cake since 1990',
        ]);
        self::assertResponseRedirects('/admin/settings');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.sidebar__brand', 'Café <Luna>');

        $crawler = $this->client->request('GET', '/about');
        self::assertSelectorTextSame('title', 'About – Café <Luna>');
        self::assertSelectorTextSame('.site-brand', 'Café <Luna>');
        self::assertSame('Café <Luna>', $crawler->filter('meta[property="og:site_name"]')->attr('content'));
        self::assertSame('Coffee & cake since 1990', $crawler->filter('meta[name="description"]')->attr('content'));

        $this->client->request('GET', '/feed.xml');
        self::assertStringContainsString('Café &lt;Luna&gt;', (string) $this->client->getResponse()->getContent());

        // The name is required, the description is not.
        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[siteName]' => '', 'settings[siteTagline]' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[siteName]' => 'Luna', 'settings[siteTagline]' => '']);
        self::assertResponseRedirects('/admin/settings');
        $crawler = $this->client->request('GET', '/about');
        self::assertSelectorTextSame('.site-brand', 'Luna');
        self::assertCount(0, $crawler->filter('meta[name="description"]'));
    }

    public function testEditorsCannotChangeSettings(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(403);
    }
}
