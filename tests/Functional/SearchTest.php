<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\SharedBlock;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchTest extends AppWebTestCase
{
    public function testVisitorsFindPublishedContentWithHighlightedMatches(): void
    {
        $this->pageWithText('Our bakery', 'Die Bäckerei bakes fresh <strong>bread</strong> every morning. 100% organic.');
        $this->pageWithText('Secret plans', 'We will sell bread rolls soon.', ContentStatus::Draft);
        $this->createPost('Baking at home', excerpt: 'How to bake bread without an oven.');
        $this->createPost('Next week', publishedAt: new \DateTimeImmutable('+1 week'), excerpt: 'Scheduled bread news.');
        $this->createPost('Unfinished', status: ContentStatus::Draft, excerpt: 'Draft about bread.');

        $crawler = $this->client->request('GET', '/search?q=BREAD');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertSelectorTextContains('.search-summary', '2 results');
        $titles = $crawler->filter('.search-results h2')->each(static fn ($node): string => $node->text());
        sort($titles);
        self::assertSame(['Baking at home', 'Our bakery'], $titles, 'Drafts and scheduled posts are not found.');
        self::assertSame('bread', $crawler->filter('.search-results mark')->first()->text());
        self::assertSame('/our-bakery', $crawler->filter('.search-results a[href="/our-bakery"]')->attr('href'));
        self::assertStringNotContainsString('<strong>', $crawler->filter('.search-results')->html(), 'The index holds plain text.');

        // Capital letters do not matter, for umlauts as little as for other letters.
        $this->client->request('GET', '/search?q=B%C3%84CKEREI');
        self::assertSelectorTextContains('.search-results', 'Our bakery');
        self::assertSelectorTextSame('.search-results mark', 'Bäckerei');

        // A word in the title is found as well, and every word has to match.
        $this->client->request('GET', '/search?q=bakery+morning');
        self::assertSelectorTextContains('.search-summary', 'One result');
        $this->client->request('GET', '/search?q=bread+zebra');
        self::assertSelectorTextContains('.search-summary', 'Nothing found');
        self::assertSelectorNotExists('.search-results');

        // The wildcards of the database are ordinary characters for visitors.
        $this->client->request('GET', '/search?q=0%25');
        self::assertSelectorTextContains('.search-summary', 'One result');
        $this->client->request('GET', '/search?q=br_ad');
        self::assertSelectorTextContains('.search-summary', 'Nothing found');

        $this->client->request('GET', '/search?q=b');
        self::assertSelectorTextContains('.notice', 'at least two letters');

        // Without a query there is just the form, which every page offers in its header too.
        $crawler = $this->client->request('GET', '/search');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.search-summary');
        $crawler = $this->client->request('GET', '/our-bakery');
        self::assertSame('/search', $crawler->filter('form.site-search')->attr('action'));
    }

    public function testMarkupInQueriesAndContentIsEscaped(): void
    {
        $this->pageWithText('Tags', 'Write &lt;b&gt;bold&lt;/b&gt; text with the b element.');

        $crawler = $this->client->request('GET', '/search?q='.urlencode('<b>bold'));

        self::assertSelectorTextContains('.search-summary', 'One result');
        self::assertSame('<b>bold', $crawler->filter('.search-results mark')->text());
        self::assertCount(0, $crawler->filter('.search-results b'));
        self::assertSame('<b>bold', $crawler->filter('#search-query')->attr('value'));
    }

    public function testEveryLanguageSearchesItsOwnContent(): void
    {
        $this->enableLocales('en', 'de');
        $this->pageWithText('Garden', 'Flowers and trees.');
        $this->pageWithText('Garten', 'Blumen und Bäume, a garden in German.', locale: 'de');

        $crawler = $this->client->request('GET', '/search?q=garden');
        self::assertSame(['Garden'], $crawler->filter('.search-results h2')->each(static fn ($node): string => $node->text()));

        $crawler = $this->client->request('GET', '/de/search?q=garden');
        self::assertSame(['Garten'], $crawler->filter('.search-results h2')->each(static fn ($node): string => $node->text()));
        self::assertSame('/de/garten', $crawler->filter('.search-results a')->attr('href'));
        self::assertSame('/de/search', $crawler->filter('form.search-form')->attr('action'));
    }

    public function testIndexFollowsEditsAndSharedBlocks(): void
    {
        $this->loginAs(UserRole::Editor);
        $shared = (new SharedBlock())->setName('Hours')->setBlocks([['type' => 'text', 'data' => ['content' => '<p>Open on weekdays</p>']]]);
        $this->em()->persist($shared);
        $this->em()->flush();
        $page = $this->pageWithText('Shop', 'We sell apples.');

        // Saving in the editor replaces the indexed text.
        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'accordion', 'data' => ['items' => [['question' => 'Do you sell pears?', 'answer' => '<p>Yes, in autumn.</p>']]]],
            ['type' => 'columns', 'data' => ['left' => [['type' => 'heading', 'data' => ['text' => 'Quinces too', 'level' => '3']]], 'right' => []]],
            ['type' => 'shared', 'data' => ['block' => $shared->getId()]],
        ])]);

        self::assertSame(0, $this->resultCount('apples'));
        self::assertSame(1, $this->resultCount('pears'), 'Questions of an accordion are searchable.');
        self::assertSame(1, $this->resultCount('autumn'));
        self::assertSame(1, $this->resultCount('quinces'), 'Blocks inside columns are searchable.');
        self::assertSame(1, $this->resultCount('weekdays'), 'The text of a shared block belongs to the page showing it.');

        // Changing the shared block changes what its pages are found for.
        $this->entity(SharedBlock::class, $shared->getId())->setBlocks([['type' => 'text', 'data' => ['content' => '<p>Open on Sundays</p>']]]);
        $this->em()->flush();
        self::assertSame(0, $this->resultCount('weekdays'));
        self::assertSame(1, $this->resultCount('sundays'));

        // Unpublished and deleted pages disappear from the results.
        $this->entity(Page::class, $page->getId())->setStatus(ContentStatus::Draft);
        $this->em()->flush();
        self::assertSame(0, $this->resultCount('pears'));

        $this->entity(Page::class, $page->getId())->setStatus(ContentStatus::Published);
        $this->em()->flush();
        self::assertSame(1, $this->resultCount('pears'));

        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/delete', ['_token' => $token]);
        self::assertSame(0, $this->resultCount('pears'));
    }

    public function testResultsArePaginated(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->createPage('Orchard '.$i, ContentStatus::Published);
        }

        $crawler = $this->client->request('GET', '/search?q=orchard');
        self::assertSelectorTextContains('.search-summary', '12 results');
        self::assertCount(10, $crawler->filter('.search-results > li'));
        self::assertSame('/search?q=orchard&page=2', $crawler->filter('.pagination a[rel=next]')->attr('href'));

        $crawler = $this->client->request('GET', '/search?q=orchard&page=2');
        self::assertCount(2, $crawler->filter('.search-results > li'));
        self::assertSame('/search?q=orchard', $crawler->filter('.pagination a[rel=prev]')->attr('href'));

        $this->client->request('GET', '/search?q=orchard&page=3');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSearchFindsDraftsAndRespectsRoles(): void
    {
        $this->client->request('GET', '/admin/search?q=harvest');
        self::assertResponseRedirects('/login');

        $author = $this->loginAs(UserRole::Author);
        $other = $this->createUser(UserRole::Author, 'other@example.com');
        $this->pageWithText('Harvest festival', 'Draft of the programme.', ContentStatus::Draft);
        $this->createPost('My harvest notes', $author, status: ContentStatus::Draft);
        $this->createPost('Their harvest notes', $other);
        $this->createMedia('field.png', 'Harvest on the field');
        $this->createMedia('unrelated.png', 'Something else');

        // Authors work on their own posts and may browse all images.
        $crawler = $this->client->request('GET', '/admin/search?q=Harvest');
        self::assertResponseIsSuccessful();
        self::assertSame(['My harvest notes'], $crawler->filter('.table tbody th a')->each(static fn ($node): string => $node->text()));
        self::assertSame('harvest', $crawler->filter('.table mark')->text());
        self::assertCount(1, $crawler->filter('.media-grid img'));
        self::assertSame('Harvest on the field', $crawler->filter('.media-grid img')->attr('alt'));
        self::assertSame('/admin/search', $crawler->filter('.sidebar__search')->attr('action'));

        // Editors see everything, including pages and other people's posts.
        $this->loginAs(UserRole::Editor);
        $crawler = $this->client->request('GET', '/admin/search?q=harvest');
        $titles = $crawler->filter('.table tbody th a')->each(static fn ($node): string => $node->text());
        sort($titles);
        self::assertSame(['Harvest festival', 'My harvest notes', 'Their harvest notes'], $titles);
        self::assertSelectorTextContains('.search-excerpt', 'Draft of the programme.');

        // File names are searched as well.
        $crawler = $this->client->request('GET', '/admin/search?q=unrelated');
        self::assertCount(1, $crawler->filter('.media-grid img'));
        self::assertSelectorNotExists('.table');

        $this->client->request('GET', '/admin/search?q=nothingmatches');
        self::assertSelectorTextContains('.empty', 'Nothing found');
    }

    public function testReindexCommandRebuildsTheIndex(): void
    {
        $this->pageWithText('Lighthouse', 'A tall tower by the sea.');
        $this->em()->getConnection()->executeStatement('DELETE FROM search_index');

        $tester = new CommandTester((new Application(static::$kernel ?? throw new \LogicException('No kernel.')))->find('app:search:reindex'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Indexed 1 item(s).', $tester->getDisplay());
        self::assertSame(1, $this->resultCount('tower'));
    }

    private function pageWithText(string $title, string $html, ContentStatus $status = ContentStatus::Published, string $locale = 'en'): Page
    {
        $page = $this->createPage($title, $status, locale: $locale);
        $page->setBlocks([['type' => 'text', 'data' => ['content' => '<p>'.$html.'</p>']]]);
        $this->em()->flush();

        return $page;
    }

    private function resultCount(string $query): int
    {
        $crawler = $this->client->request('GET', '/search?q='.urlencode($query));
        self::assertResponseIsSuccessful();

        return $crawler->filter('.search-results > li')->count();
    }
}
