<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Enum\ContentStatus;
use App\Tests\AppWebTestCase;

final class PublicSiteTest extends AppWebTestCase
{
    public function testHomepageWithoutContentShowsWelcomeNotice(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Test Site');
    }

    public function testPageHasSemanticStructureAndMetaTags(): void
    {
        $page = $this->createPage('Services', ContentStatus::Published);
        $page->setSeoTitle('Our Services')->setMetaDescription('Everything we offer.');
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/services');

        self::assertResponseIsSuccessful();
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertPageTitleSame('Our Services – Test Site');
        self::assertSelectorExists('meta[name="viewport"]');
        self::assertSelectorExists('meta[name="description"][content="Everything we offer."]');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/services"]');
        self::assertSelectorExists('meta[property="og:title"][content="Our Services"]');
        self::assertSelectorExists('link[rel="alternate"][type="application/rss+xml"]');
        self::assertSelectorNotExists('meta[name="robots"]');
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSelectorExists('header.site-header');
        self::assertSelectorExists('main#content');
        self::assertSelectorExists('footer.site-footer');
        self::assertSelectorExists('a.skip-link[href="#content"]');
        self::assertSelectorNotExists('.edit-bar', 'Visitors never see the editor bar.');
    }

    public function testUnknownAddressShowsFriendlyNotFoundPage(): void
    {
        // Custom error pages are only rendered when debug mode is off, as in production.
        self::ensureKernelShutdown();
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/this-page-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Page not found');
        self::assertSelectorExists('a[href="/"]');
        self::assertSelectorExists('header.site-header');
    }

    public function testSitemapListsOnlyPublicContent(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->createPage('About', ContentStatus::Published);
        $this->createPage('Secret', ContentStatus::Draft);
        $this->createPost('Public Post', category: $this->createCategory('News'));
        $this->createPost('Draft Post', status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Future Post', publishedAt: new \DateTimeImmutable('+1 week'));

        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $locations = [];
        foreach ($xml->url as $url) {
            $locations[] = (string) $url->loc;
        }
        sort($locations);

        self::assertSame([
            'http://localhost/',
            'http://localhost/about',
            'http://localhost/blog',
            'http://localhost/blog/category/news',
            'http://localhost/blog/public-post',
        ], $locations);
    }

    public function testRobotsTxtPointsToSitemapAndHidesAdmin(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /admin', $content);
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $content);
    }

    public function testDemoFixturesProduceAWorkingWebsite(): void
    {
        static::getContainer()->get(AppFixtures::class)->load($this->em());

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Welcome to SymfoCMS');
        self::assertSame(['Home', 'About', 'Blog'], $crawler->filter('.site-nav__list > li > a')->each(static fn ($a) => $a->text()));
        self::assertCount(2, $crawler->filter('.site-nav__sub a'));
        self::assertSelectorExists('.block-image img[srcset]');
        self::assertSelectorExists('.block-columns');
        self::assertCount(3, $crawler->filter('.footer-nav a'));

        $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/blog');
        self::assertCount(3, $crawler->filter('.post-card'));
        self::assertCount(3, $crawler->filter('.post-card__image'));

        $this->client->request('GET', '/blog/hello-world');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.post-cover img');

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['_username' => 'admin@example.com', '_password' => AppFixtures::DEMO_PASSWORD]);
        self::assertResponseRedirects('/admin');
    }
}
