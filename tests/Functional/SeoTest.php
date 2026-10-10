<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SeoTest extends AppWebTestCase
{
    public function testShareImageIsChosenInTheEditorAndUsedForSocialTags(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Offer', ContentStatus::Published);
        $image = $this->createMedia('offer.png', 'A special offer');

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertSelectorExists('[data-controller="seo-preview"][data-seo-preview-base-url-value="http://localhost/"]');
        self::assertCount(1, $crawler->filter('input[name="page[shareImage]"]'));

        $this->client->submitForm('Save changes', [
            'page[shareImage]' => (string) $image->getId(),
            'page[metaDescription]' => 'Only this week.',
            'page[blocks]' => '[]',
        ]);
        self::assertSame($image->getId(), $this->entity(Page::class, $page->getId())->getShareImage()?->getId());

        $this->client->request('GET', '/offer');
        self::assertSelectorExists('meta[property="og:image"][content="http://localhost/uploads/media/offer.png"]');
        self::assertSelectorExists('meta[property="og:image:alt"][content="A special offer"]');
        self::assertSelectorExists('meta[name="twitter:card"][content="summary_large_image"]');
        self::assertSelectorExists('meta[name="twitter:image"][content="http://localhost/uploads/media/offer.png"]');
        self::assertSelectorExists('meta[name="twitter:title"][content="Offer"]');
        self::assertSelectorExists('meta[name="twitter:description"][content="Only this week."]');
        self::assertSelectorExists('meta[property="og:locale"][content="en"]');
    }

    public function testPagesWithoutImageUseTheSmallCard(): void
    {
        $this->createPage('Plain', ContentStatus::Published);

        $this->client->request('GET', '/plain');

        self::assertSelectorNotExists('meta[property="og:image"]');
        self::assertSelectorExists('meta[name="twitter:card"][content="summary"]');
    }

    public function testPostFallsBackToItsCoverImage(): void
    {
        $post = $this->createPost('Illustrated');
        $post->setCoverImage($this->createMedia('cover.png'));
        $this->em()->flush();

        $this->client->request('GET', '/blog/illustrated');
        self::assertSelectorExists('meta[property="og:image"][content="http://localhost/uploads/media/cover.png"]');

        $post->setShareImage($this->createMedia('share.png'));
        $this->em()->flush();
        $this->client->request('GET', '/blog/illustrated');
        self::assertSelectorExists('meta[property="og:image"][content="http://localhost/uploads/media/share.png"]');
    }

    public function testBlogPostHasArticleAndBreadcrumbData(): void
    {
        $author = $this->createUser(UserRole::Author);
        $post = $this->createPost('Structured </script><b>Post', author: $author, excerpt: 'A summary.');
        $post->setSlug('structured-post')->setCoverImage($this->createMedia('cover.png'));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/blog/structured-post');
        $data = $this->structuredData($crawler);

        self::assertSame(['BlogPosting', 'BreadcrumbList'], array_column($data, '@type'));
        $article = $data[0];
        self::assertSame('Structured </script><b>Post', $article['headline'], 'Titles survive encoding unchanged.');
        self::assertSame('A summary.', $article['description']);
        self::assertSame(['http://localhost/uploads/media/cover.png'], $article['image']);
        self::assertSame('Author User', $article['author']['name']);
        self::assertSame('http://localhost/blog/structured-post', $article['mainEntityOfPage']);
        self::assertArrayHasKey('datePublished', $article);
        self::assertSame(
            [['Test Site', 'http://localhost/'], ['Blog', 'http://localhost/blog'], ['Structured </script><b>Post', 'http://localhost/blog/structured-post']],
            array_map(static fn (array $item): array => [$item['name'], $item['item']], $data[1]['itemListElement']),
        );

        // The markup of the page stays intact although the title contains "</script>".
        self::assertStringNotContainsString('</script><b>Post', (string) $crawler->filter('head')->html());
        self::assertSelectorTextSame('h1', 'Structured </script><b>Post');
    }

    public function testAccordionBecomesFaqDataIncludingNestedOnes(): void
    {
        $page = $this->createPage('Help', ContentStatus::Published);
        $page->setBlocks([
            ['type' => 'accordion', 'data' => ['items' => [
                ['question' => 'Is it free?', 'answer' => '<p>Yes.</p>'],
                ['question' => '', 'answer' => '<p>Skipped.</p>'],
            ]]],
            ['type' => 'columns', 'data' => ['left' => [
                ['type' => 'accordion', 'data' => ['items' => [['question' => 'Is it open source?', 'answer' => '<p>Yes, MIT.</p>']]]],
            ], 'right' => []]],
        ]);
        $this->em()->flush();

        $data = $this->structuredData($this->client->request('GET', '/help'));

        self::assertSame(['FAQPage', 'BreadcrumbList'], array_column($data, '@type'));
        self::assertSame(['Is it free?', 'Is it open source?'], array_column($data[0]['mainEntity'], 'name'));
        self::assertSame('<p>Yes.</p>', $data[0]['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testHomepageAndDraftsHaveNoBreadcrumbOrStructuredData(): void
    {
        $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->createPage('Hidden', ContentStatus::Draft);

        self::assertSame([], $this->structuredData($this->client->request('GET', '/')));

        $this->loginAs(UserRole::Editor);
        self::assertSame([], $this->structuredData($this->client->request('GET', '/hidden')), 'Drafts are not described to search engines.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredData(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();

        return $crawler->filter('script[type="application/ld+json"]')->each(
            static fn (Crawler $script): array => json_decode($script->text(null, false), true, 32, \JSON_THROW_ON_ERROR),
        );
    }
}
