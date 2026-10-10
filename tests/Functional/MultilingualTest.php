<?php

namespace App\Tests\Functional;

use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use App\Tests\AppWebTestCase;

final class MultilingualTest extends AppWebTestCase
{
    public function testSingleLanguageSiteShowsNoLanguageInterface(): void
    {
        $this->loginAs(UserRole::Admin);
        $page = $this->createPage('About', ContentStatus::Published);
        $post = $this->createPost('Hello');

        foreach (['/admin/pages', '/admin/posts', '/admin/categories', '/admin/menus', '/admin/pages/new', '/admin/pages/'.$page->getId().'/edit', '/admin/posts/'.$post->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorNotExists('.language-tabs', $url);
            self::assertSelectorNotExists('.locale-chip', $url);
            self::assertSelectorNotExists('.translation-list', $url);
        }

        $this->client->request('GET', '/about');
        self::assertSelectorNotExists('.language-switcher');
        self::assertSelectorNotExists('link[hreflang]');

        // A prefix that is not a language of the site does not exist.
        $this->client->request('GET', '/de/about');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminConfiguresLanguages(): void
    {
        $this->loginAs(UserRole::Admin);

        $this->client->request('GET', '/admin/settings');
        self::assertInputValueSame('settings[locales]', 'en');

        $this->client->submitForm('Save', ['settings[locales]' => 'EN, de  fr']);
        self::assertResponseRedirects('/admin/settings');
        self::assertSame(['en', 'de', 'fr'], static::getContainer()->get(ContentLocales::class)->all());

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[locales]' => 'en, german']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'is not a language code');
    }

    public function testLanguageWithContentCannotBeRemoved(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Admin);
        $this->createPage('Kontakt', ContentStatus::Draft, locale: 'de');

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[locales]' => 'en']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'cannot be removed');
    }

    public function testOnlyAdminsChangeSettings(): void
    {
        $this->loginAs(UserRole::Editor);

        $this->client->request('GET', '/admin/settings');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTranslatingAPageCopiesItIntoANewDraft(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('About Us', ContentStatus::Published);
        $page->setBlocks([['type' => 'heading', 'data' => ['text' => 'Our story', 'level' => '2']]])->setMetaDescription('Who we are.');
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertSelectorTextSame('.editor__bar .locale-chip', 'English');
        self::assertSelectorTextContains('.translation-list', 'Not translated yet');
        $button = $crawler->filter('.translation-list button');
        self::assertSame('Translate into Deutsch', $button->text());

        $this->client->request('POST', (string) $button->attr('formaction'), ['_token' => $button->attr('value')]);

        $translation = $this->em()->getRepository(Page::class)->findOneBy(['locale' => 'de']);
        self::assertInstanceOf(Page::class, $translation);
        self::assertResponseRedirects('/admin/pages/'.$translation->getId().'/edit');
        self::assertSame($page->getTranslationGroup(), $translation->getTranslationGroup());
        self::assertSame(ContentStatus::Draft, $translation->getStatus(), 'A translation starts as a draft.');
        self::assertSame('About Us', $translation->getTitle());
        self::assertSame('about-us', $translation->getSlug(), 'The same address may be used once per language.');
        // assertEquals: MySQL stores JSON objects with their keys in a different order.
        self::assertEquals($page->getBlocks(), $translation->getBlocks());
        self::assertSame('Who we are.', $translation->getMetaDescription());

        // Both versions now link to each other, and asking again does not create a second copy.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.translation-list', 'About Us');
        self::assertSelectorExists('.translation-list a[href="/admin/pages/'.$page->getId().'/edit"]');
        $this->client->request('POST', (string) $button->attr('formaction'), ['_token' => $button->attr('value')]);
        self::assertSame(2, $this->em()->getRepository(Page::class)->count([]));
    }

    public function testTranslateRequiresTokenAndPermission(): void
    {
        $this->enableLocales('en', 'de');
        $editor = $this->createUser(UserRole::Editor);
        $post = $this->createPost('Editors Post', author: $editor);
        $page = $this->createPage('About', ContentStatus::Published);

        $this->loginAs(UserRole::Author);
        $token = $this->csrfToken('/admin/media', '[data-dropzone-csrf-value]', 'data-dropzone-csrf-value');

        $this->client->request('POST', '/admin/posts/'.$post->getId().'/translate/de', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403, 'Authors cannot translate posts of other people.');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/translate/de', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403, 'Authors cannot translate pages.');

        $this->client->loginUser($editor);
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/translate/de', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/translate/fr', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404, 'French is not a language of this site.');

        self::assertSame(1, $this->em()->getRepository(Page::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(Post::class)->count([]));
    }

    public function testPublicUrlsCarryThePrefixOfNonDefaultLanguages(): void
    {
        $this->enableLocales('en', 'de');
        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $about = $this->createPage('About', ContentStatus::Published);
        $this->createPage('Startseite', ContentStatus::Published, homepage: true, locale: 'de', translationOf: $home);
        $this->createPage('Ueber uns', ContentStatus::Published, locale: 'de', translationOf: $about);
        $this->createPage('Nur Deutsch', ContentStatus::Published, locale: 'de');

        // Default language: no prefix.
        $crawler = $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/about"]');
        self::assertSelectorExists('link[hreflang="en"][href="http://localhost/about"]');
        self::assertSelectorExists('link[hreflang="de"][href="http://localhost/de/ueber-uns"]');
        self::assertSelectorExists('link[hreflang="x-default"][href="http://localhost/about"]');
        self::assertSame(['/about', '/de/ueber-uns'], $crawler->filter('.language-switcher a')->each(static fn ($a) => $a->attr('href')));
        self::assertSame('true', $crawler->filter('.language-switcher a[href="/about"]')->attr('aria-current'));

        // Other language: prefixed, rendered in that language.
        $crawler = $this->client->request('GET', '/de/ueber-uns');
        self::assertResponseIsSuccessful();
        self::assertSame('de', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Ueber uns');
        self::assertSelectorTextContains('.skip-link', 'Zum Inhalt springen');
        self::assertSame('/de', $crawler->filter('.site-brand')->attr('href'));

        // Homepages of both languages.
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Home');
        $this->client->request('GET', '/de');
        self::assertSelectorTextSame('h1', 'Startseite');
        $this->client->request('GET', '/de/startseite');
        self::assertResponseRedirects('/de', 301);

        // A page without translation: the switcher falls back to the other language's homepage, no hreflang.
        $crawler = $this->client->request('GET', '/de/nur-deutsch');
        self::assertSame(['/', '/de/nur-deutsch'], $crawler->filter('.language-switcher a')->each(static fn ($a) => $a->attr('href')));
        self::assertSelectorNotExists('link[hreflang]');

        // Content is only reachable in its own language.
        $this->client->request('GET', '/ueber-uns');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/de/about');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/fr/about');
        self::assertResponseStatusCodeSame(404);

        // The prefix of the default language is redirected away.
        $this->client->request('GET', '/en/about?x=1');
        self::assertResponseRedirects('/about?x=1', 301);
        $this->client->request('GET', '/en');
        self::assertResponseRedirects('/', 301);
    }

    public function testDraftTranslationsAreNotAdvertised(): void
    {
        $this->enableLocales('en', 'de');
        $about = $this->createPage('About', ContentStatus::Published);
        $this->createPage('Ueber uns', ContentStatus::Draft, locale: 'de', translationOf: $about);

        $crawler = $this->client->request('GET', '/about');

        self::assertSelectorNotExists('link[hreflang]');
        self::assertSame(['/about', '/de'], $crawler->filter('.language-switcher a')->each(static fn ($a) => $a->attr('href')));
    }

    public function testBlogFeedAndSitemapAreSeparatedByLanguage(): void
    {
        $this->enableLocales('en', 'de');
        $news = $this->createCategory('News');
        $neues = $this->createCategory('Neues', 'de');
        $english = $this->createPost('English Post', category: $news);
        $this->createPost('Deutscher Beitrag', category: $neues, locale: 'de', translationOf: $english);

        $crawler = $this->client->request('GET', '/blog');
        self::assertSame(['English Post'], $crawler->filter('.post-card__title')->each(static fn ($node) => $node->text()));
        self::assertSame('/blog/english-post', $crawler->filter('.post-card__title a')->attr('href'));
        self::assertSame(['/blog', '/de/blog'], $crawler->filter('.language-switcher a')->each(static fn ($a) => $a->attr('href')));

        $crawler = $this->client->request('GET', '/de/blog');
        self::assertSelectorTextSame('h1', 'Blog');
        self::assertSame(['Deutscher Beitrag'], $crawler->filter('.post-card__title')->each(static fn ($node) => $node->text()));
        self::assertSame('/de/blog/deutscher-beitrag', $crawler->filter('.post-card__title a')->attr('href'));
        self::assertSame(['/de/blog', '/de/blog/category/neues'], $crawler->filter('.category-nav a')->each(static fn ($a) => $a->attr('href')));

        $this->client->request('GET', '/de/blog/category/neues');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/de/blog/category/news');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/de/blog/deutscher-beitrag');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[hreflang="en"][href="http://localhost/blog/english-post"]');
        self::assertSame(['/blog/english-post', '/de/blog/deutscher-beitrag'], $crawler->filter('.language-switcher a')->each(static fn ($a) => $a->attr('href')));
        $this->client->request('GET', '/blog/deutscher-beitrag');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/de/feed.xml');
        $feed = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<language>de</language>', $feed);
        self::assertStringContainsString('http://localhost/de/blog/deutscher-beitrag', $feed);
        self::assertStringNotContainsString('English Post', $feed);

        $this->client->request('GET', '/sitemap.xml');
        $sitemap = (string) $this->client->getResponse()->getContent();
        foreach (['http://localhost/</loc>', 'http://localhost/de</loc>', '/blog/english-post', '/de/blog/deutscher-beitrag', '/de/blog/category/neues', '/blog/category/news'] as $expected) {
            self::assertStringContainsString($expected, $sitemap);
        }
    }

    public function testEachLanguageHasItsOwnMenus(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Admin);
        $about = $this->createPage('About', ContentStatus::Published);
        $ueber = $this->createPage('Ueber uns', ContentStatus::Published, locale: 'de', translationOf: $about);

        // The German menu editor only offers German pages and stores a German menu.
        $crawler = $this->client->request('GET', '/admin/menus/main?locale=de');
        self::assertSelectorTextSame('.language-tabs a[aria-current="page"]', 'Deutsch');
        $config = json_decode((string) $crawler->filter('[data-controller="menu"]')->attr('data-menu-config-value'), true);
        self::assertSame(['Ueber uns'], array_column($config['pages'], 'title'));
        self::assertSame('/de/blog', $config['blogUrl']);

        $this->client->request('POST', $config['saveUrl'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $config['csrf']], content: $this->json(['items' => [
            ['type' => 'page', 'page' => $ueber->getId(), 'label' => 'Über uns'],
            ['type' => 'page', 'page' => $about->getId(), 'label' => 'Wrong language'],
        ]]));
        self::assertResponseIsSuccessful();

        $config = $this->menuConfig('/admin/menus/main');
        $this->client->request('POST', $config['saveUrl'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $config['csrf']], content: $this->json(['items' => [
            ['type' => 'page', 'page' => $about->getId(), 'label' => 'About'],
        ]]));

        $items = $this->em()->getRepository(MenuItem::class)->findBy([], ['locale' => 'ASC']);
        self::assertSame(['de' => 'Über uns', 'en' => 'About'], array_combine(array_map(static fn (MenuItem $item) => $item->getLocale(), $items), array_map(static fn (MenuItem $item) => $item->getLabel(), $items)));

        $crawler = $this->client->request('GET', '/about');
        self::assertSame(['About'], $crawler->filter('.site-nav__list > li > a')->each(static fn ($a) => $a->text()));
        $crawler = $this->client->request('GET', '/de/ueber-uns');
        self::assertSame(['Über uns'], $crawler->filter('.site-nav__list > li > a')->each(static fn ($a) => $a->text()));
        self::assertSame('/de/ueber-uns', $crawler->filter('.site-nav__list a')->attr('href'));
    }

    public function testAdminListsAreFilteredByLanguage(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Editor);
        $this->createPage('About', ContentStatus::Published);
        $this->createPage('Kontakt', ContentStatus::Published, locale: 'de');
        $this->createCategory('News');
        $this->createCategory('Neues', 'de');

        $crawler = $this->client->request('GET', '/admin/pages');
        self::assertSame(['About'], $crawler->filter('tbody th a')->each(static fn ($a) => $a->text()));
        self::assertSame(['English', 'Deutsch'], $crawler->filter('.language-tabs a')->each(static fn ($a) => $a->text()));

        $crawler = $this->client->request('GET', '/admin/pages?locale=de');
        self::assertSame(['Kontakt'], $crawler->filter('tbody th a')->each(static fn ($a) => $a->text()));

        // New content is created in the language of the tab it was started from.
        $this->client->click($crawler->selectLink('+ New page')->link());
        self::assertSelectorTextSame('.locale-chip', 'Deutsch');
        $this->client->submitForm('Continue', ['content[title]' => 'About']);
        $created = $this->em()->getRepository(Page::class)->findOneBy(['locale' => 'de', 'slug' => 'about']);
        self::assertInstanceOf(Page::class, $created, 'The same address can exist once per language.');

        // The post form only offers categories of the post's language.
        $post = $this->createPost('Deutscher Beitrag', locale: 'de');
        $crawler = $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        self::assertSame(['No category', 'Neues'], $crawler->filter('#post_category option')->each(static fn ($option) => $option->text()));

        $crawler = $this->client->request('GET', '/admin/categories?locale=de');
        self::assertSame(['Neues'], $crawler->filter('tbody th a')->each(static fn ($a) => $a->text()));
        $this->client->submitForm('Add category', ['category[name]' => 'Tipps']);
        self::assertResponseRedirects('/admin/categories?locale=de');
    }

    public function testLanguageCodesAreReservedPageAddresses(): void
    {
        $this->enableLocales('en', 'de');
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Germany', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[slug]' => 'de', 'page[blocks]' => '[]']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__errors', 'reserved');
    }

    public function testTwoLetterPageAddressStillWorksWhenItIsNotALanguage(): void
    {
        $this->createPage('Me', ContentStatus::Published);

        $this->client->request('GET', '/me');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Me');
    }

    /**
     * @return array<string, mixed>
     */
    private function menuConfig(string $url): array
    {
        $crawler = $this->client->request('GET', $url);

        return json_decode((string) $crawler->filter('[data-controller="menu"]')->attr('data-menu-config-value'), true);
    }
}
