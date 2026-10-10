<?php

namespace App\Tests\Functional;

use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\Settings;
use App\Tests\AppWebTestCase;

final class ThemeTest extends AppWebTestCase
{
    public function testDefaultThemeIsActiveWithoutAnySetting(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $stylesheets = $crawler->filter('link[rel="stylesheet"]')->each(static fn ($node): string => (string) $node->attr('href'));
        self::assertCount(1, $stylesheets, 'System fonts need no font stylesheet.');
        self::assertStringContainsString('/assets/themes/default/assets/theme', $stylesheets[0]);
        self::assertStringContainsString('--color-accent:#2f55d4', $crawler->filter('head style')->text());
        self::assertSelectorNotExists('.masthead');
        self::assertSelectorTextSame('.site-brand', 'Test Site');
    }

    public function testAdminActivatesAnotherTheme(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->loginAs(UserRole::Admin);

        $crawler = $this->client->request('GET', '/admin/design');
        self::assertResponseIsSuccessful();
        self::assertSame(['default', 'editorial'], $crawler->filter('.theme-card')->each(static fn ($node): string => (string) $node->attr('data-theme')));
        self::assertSame('default', $crawler->filter('.theme-card.is-active')->attr('data-theme'));
        self::assertSelectorExists('.sidebar__nav a[href="/admin/design"]');

        $this->client->submit($crawler->filter('.theme-card[data-theme="editorial"] form')->form());
        self::assertResponseRedirects('/admin/design');
        $crawler = $this->client->followRedirect();
        self::assertSame('editorial', $crawler->filter('.theme-card.is-active')->attr('data-theme'));
        self::assertSame('lora', $crawler->filter('#theme_settings_font_body option[selected]')->attr('value'), 'The form shows the defaults of the new theme.');

        // The website now uses the theme's own layout, its stylesheet after the parent's, and its fonts.
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorExists('header.masthead .site-brand');
        self::assertSelectorExists('header.masthead .site-nav, header.masthead .masthead__title');
        $stylesheets = $crawler->filter('link[rel="stylesheet"]')->each(static fn ($node): string => (string) $node->attr('href'));
        self::assertCount(3, $stylesheets);
        self::assertStringContainsString('/assets/styles/fonts', $stylesheets[0]);
        self::assertStringContainsString('/assets/themes/default/assets/theme', $stylesheets[1]);
        self::assertStringContainsString('/assets/themes/editorial/assets/theme', $stylesheets[2]);
        $style = $crawler->filter('head style')->text();
        self::assertStringContainsString('--color-accent:#a4361f', $style);
        self::assertStringContainsString('--font-heading:"Playfair Display"', $style);

        // The fonts come from this website.
        $this->client->request('GET', $stylesheets[0]);
        self::assertResponseIsSuccessful();
        $css = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('../fonts/lora-latin-wght-normal', $css);
        self::assertStringNotContainsString('http', $css);

        // Pages the theme does not bring along still work, also the error page.
        $this->client->request('GET', '/search?q=home');
        self::assertSelectorExists('header.masthead');
        self::assertSelectorTextContains('.search-results', 'Home');
    }

    public function testColorsFontsAndLogoAreCustomized(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $logo = $this->createMedia('logo.png', 'Company logo');
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', '/admin/design');
        $this->client->submitForm('Save', [
            'theme_settings[logo]' => (string) $logo->getId(),
            'theme_settings[color_accent]' => '#C2185B',
            'theme_settings[font_heading]' => 'playfair-display',
        ]);
        self::assertResponseRedirects('/admin/design');

        $crawler = $this->client->request('GET', '/');
        $style = $crawler->filter('head style')->text();
        self::assertStringContainsString('--color-accent:#c2185b', $style);
        self::assertStringContainsString('--color-bg:#ffffff', $style);
        self::assertStringContainsString('--font-heading:"Playfair Display"', $style);
        self::assertStringContainsString('--font-body:system-ui', $style);
        self::assertStringContainsString('/assets/styles/fonts', (string) $crawler->filter('link[rel="stylesheet"]')->first()->attr('href'));
        self::assertSame('Test Site', $crawler->filter('.site-brand img.site-brand__logo')->attr('alt'));
        self::assertStringContainsString('logo', (string) $crawler->filter('.site-brand img')->attr('src'));

        // Only what differs from the theme's defaults is stored.
        self::assertEquals(['colors' => ['accent' => '#c2185b'], 'fonts' => ['heading' => 'playfair-display']], static::getContainer()->get(Settings::class)->get('theme.settings.default'));

        // Anything that is not a colour or a known font is refused.
        $crawler = $this->client->request('GET', '/admin/design');
        $form = $crawler->selectButton('Save')->form();
        $this->client->request('POST', '/admin/design', array_replace_recursive($form->getPhpValues(), ['theme_settings' => [
            'color_accent' => 'red;}body{display:none',
            'font_body' => 'comic-sans',
        ]]));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('--color-accent:#c2185b', $this->client->request('GET', '/')->filter('head style')->text());

        // "Back to the defaults" keeps the logo and drops colours and fonts.
        $crawler = $this->client->request('GET', '/admin/design');
        $this->client->submit($crawler->filter('form[action="/admin/design/reset"]')->form());
        self::assertResponseRedirects('/admin/design');
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('--color-accent:#2f55d4', $crawler->filter('head style')->text());
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"]'));
        self::assertSelectorExists('.site-brand img');
    }

    public function testOnlyAdminsChangeTheDesign(): void
    {
        $this->loginAs(UserRole::Editor);

        $crawler = $this->client->request('GET', '/admin/pages');
        self::assertSelectorNotExists('.sidebar__nav a[href="/admin/design"]');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('GET', '/admin/design');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/design/activate/editorial', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/design/reset', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        self::assertNull(static::getContainer()->get(Settings::class)->get('theme.active'));
    }

    public function testActivationNeedsATokenAndAnExistingTheme(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('POST', '/admin/design/activate/editorial', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/admin/design');
        $token = (string) $crawler->filter('.theme-card form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/design/activate/missing', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWebsiteFallsBackToDefaultWhenTheActiveThemeWasRemoved(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        static::getContainer()->get(Settings::class)->set('theme.active', 'deleted-theme');

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/assets/themes/default/assets/theme', (string) $crawler->filter('link[rel="stylesheet"]')->attr('href'));
    }
}
