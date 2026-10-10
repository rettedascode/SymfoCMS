<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Media;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Redirect;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use App\Tests\Support\FakeWeb;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WordPressImportTest extends AppWebTestCase
{
    /** A public address that needs no name lookup, so the tests never touch the network. */
    private const OLD_SITE = 'http://93.184.216.34';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        FakeWeb::$requests = [];
        $this->dir = sys_get_temp_dir().'/symfocms-wordpress-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);

        parent::tearDown();
    }

    public function testPostsPagesCategoriesAndImagesAreImported(): void
    {
        $admin = $this->loginAs(UserRole::Admin);
        $this->createPage('About', ContentStatus::Published);

        $crawler = $this->upload($this->wxr());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'WordPress import finished');
        self::assertSame('2', $crawler->filter('[data-count="posts"] strong')->text());
        self::assertSame('2', $crawler->filter('[data-count="pages"] strong')->text());
        self::assertSame('1', $crawler->filter('[data-count="categories"] strong')->text());
        self::assertSame('3', $crawler->filter('[data-count="media"] strong')->text());

        // The report names what could not be converted, per entry.
        $issues = $crawler->filter('.report-issues li')->each(static fn (Crawler $node): string => $node->text());
        self::assertCount(7, $issues);
        self::assertContains('Hello world – An element of the type “script” was left out.', $issues);
        self::assertContains('Hello world – The shortcode [contact-form-7] belongs to a WordPress plugin and was removed.', $issues);
        self::assertContains('Hello world – An image could not be fetched and was left out: '.self::OLD_SITE.'/wp-content/uploads/missing.jpg', $issues);
        self::assertContains('Hello world – A table was turned into plain text, one line per row.', $issues);
        self::assertContains('Members only – The entry was protected by a password in WordPress and was imported as a draft.', $issues);
        self::assertContains('product – 1 entries of this WordPress content type were not imported.', $issues);
        self::assertContains('2 – Comments are not supported and were not imported.', $issues);

        // The post: blocks, date, category, author, cover image and excerpt.
        $post = $this->em()->getRepository(Post::class)->findOneBy(['slug' => 'hello-world']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('Hello world', $post->getTitle());
        self::assertSame(ContentStatus::Published, $post->getStatus());
        self::assertSame('2024-05-17 08:30', $post->getPublishedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
        self::assertSame('News & Events', $post->getCategory()?->getName());
        self::assertSame('news', $post->getCategory()->getSlug());
        self::assertSame($admin->getId(), $post->getAuthor()?->getId());
        self::assertSame('A short summary.', $post->getExcerpt());
        self::assertInstanceOf(Media::class, $post->getCoverImage());
        self::assertSame('cover.png', $post->getCoverImage()->getOriginalName());

        $blocks = $post->getBlocks();
        self::assertSame(['text', 'heading', 'image', 'gallery', 'text', 'video', 'text'], array_column($blocks, 'type'));
        self::assertSame('<p>Welcome to <strong>WordPress</strong>. Read <a href="https://example.org/more" rel="noopener">more</a>.</p>', $blocks[0]['data']['content']);
        self::assertSame('Our team', $blocks[1]['data']['text']);
        $photo = $this->entity(Media::class, $blocks[2]['data']['image']);
        self::assertSame('photo.png', $photo->getOriginalName(), 'The original is fetched, not the resized copy.');
        self::assertSame('The team', $photo->getAlt());
        self::assertSame('Team photo', $blocks[2]['data']['caption']);
        self::assertFileExists($this->mediaDir().'/'.$photo->getFilename());
        self::assertSame([$photo->getId(), $post->getCoverImage()->getId()], $blocks[3]['data']['images'], 'Images that are used twice are fetched once.');
        self::assertSame('<p>Name | Price</p><p>Bread | 2 €</p>', $blocks[4]['data']['content']);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $blocks[5]['data']['url']);
        self::assertSame('<p>Goodbye.</p>', $blocks[6]['data']['content']);
        self::assertStringNotContainsString('alert', json_encode($blocks, \JSON_THROW_ON_ERROR));

        // Every image was requested once, from the old website only.
        sort(FakeWeb::$requests);
        self::assertSame([
            self::OLD_SITE.'/wp-content/uploads/cover.png',
            self::OLD_SITE.'/wp-content/uploads/logo.png',
            self::OLD_SITE.'/wp-content/uploads/missing.jpg',
            self::OLD_SITE.'/wp-content/uploads/photo.png',
        ], FakeWeb::$requests);

        // Drafts stay drafts; scheduled posts are not public yet.
        $draft = $this->em()->getRepository(Post::class)->findOneBy(['title' => 'Unfinished thoughts']);
        self::assertInstanceOf(Post::class, $draft);
        self::assertSame(ContentStatus::Draft, $draft->getStatus());
        self::assertNull($draft->getCategory(), '"Uncategorized" is not a category worth keeping.');

        // Pages: an address that is taken gets a number, a protected page becomes a draft.
        $about = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'about-2']);
        self::assertInstanceOf(Page::class, $about);
        self::assertSame(ContentStatus::Published, $about->getStatus());
        self::assertSame(['text', 'image'], array_column($about->getBlocks(), 'type'));
        self::assertSame(ContentStatus::Draft, $this->em()->getRepository(Page::class)->findOneBy(['title' => 'Members only'])?->getStatus());
        self::assertSame(1, $this->em()->getRepository(Category::class)->count([]));
        self::assertSame(3, $this->em()->getRepository(Media::class)->count([]));

        // The old addresses lead to the new ones, and the content is online and searchable.
        self::assertSame('/blog/hello-world', $this->em()->getRepository(Redirect::class)->findOneBy(['source' => '/2024/05/17/hello-world'])?->getTarget());
        self::assertSame('/about-2', $this->em()->getRepository(Redirect::class)->findOneBy(['source' => '/about'])?->getTarget());
        $this->client->request('GET', '/blog/hello-world');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Welcome to WordPress');
        self::assertSelectorExists('main img');
        $this->client->request('GET', '/search?q=goodbye');
        self::assertSelectorTextContains('.search-results', 'Hello world');
    }

    public function testImagesAreOnlyFetchedFromPublicAddresses(): void
    {
        $this->loginAs(UserRole::Admin);

        $crawler = $this->upload($this->document(<<<'XML'
            <item>
                <title>Sneaky</title>
                <link>http://93.184.216.34/sneaky/</link>
                <wp:post_id>1</wp:post_id><wp:post_name>sneaky</wp:post_name><wp:status>publish</wp:status><wp:post_type>post</wp:post_type>
                <content:encoded><![CDATA[
                    <p>Text</p>
                    <img src="http://127.0.0.1/internal/secret.png" alt="">
                    <img src="http://10.0.0.5/router.png" alt="">
                    <img src="file:///etc/passwd.png" alt="">
                    <img src="http://93.184.216.34/wp-content/uploads/broken.png" alt="">
                ]]></content:encoded>
            </item>
            XML));

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.report-issues li'));
        self::assertSame(0, $this->em()->getRepository(Media::class)->count([]));
        self::assertSame(['http://93.184.216.34/wp-content/uploads/broken.png'], FakeWeb::$requests, 'Addresses inside the own network are never requested.');
        self::assertSame(['text'], array_column($this->em()->getRepository(Post::class)->findOneBy(['slug' => 'sneaky'])?->getBlocks() ?? [], 'type'));
    }

    public function testOtherFilesAreRefused(): void
    {
        $this->loginAs(UserRole::Admin);
        (new Filesystem())->dumpFile($this->dir.'/secret.txt', 'TOP-SECRET');

        $files = [
            'not xml' => 'Hello',
            'another feed' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Feed</title><item><title>x</title></item></channel></rss>',
            // An entity that would read a local file must not be loaded.
            'entities' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///'.str_replace('\\', '/', $this->dir).'/secret.txt">]>'
                .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item><title>&xxe;</title><wp:post_type>post</wp:post_type><wp:status>publish</wp:status></item></channel></rss>',
        ];

        foreach ($files as $name => $content) {
            $this->upload($content);
            self::assertResponseStatusCodeSame(422, $name);
            self::assertSelectorTextContains('.flash--error', 'not a WordPress export');
        }

        self::assertSame(0, $this->em()->getRepository(Post::class)->count([]));
        self::assertStringNotContainsString('TOP-SECRET', (string) $this->client->getResponse()->getContent());
    }

    public function testOnlyAdminsImportAndTheLanguageMustExist(): void
    {
        $this->loginAs(UserRole::Editor);
        $token = (string) $this->client->request('GET', '/admin/pages')->filter('input[name="_csrf_token"]')->attr('value');
        file_put_contents($this->dir.'/wp.xml', $this->wxr());

        $this->client->request('POST', '/admin/transfer/wordpress', ['_token' => $token], ['export' => new UploadedFile($this->dir.'/wp.xml', 'wp.xml', 'text/xml', null, true)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser(UserRole::Admin));
        $this->client->request('POST', '/admin/transfer/wordpress', ['_token' => 'wrong'], ['export' => new UploadedFile($this->dir.'/wp.xml', 'wp.xml', 'text/xml', null, true)]);
        self::assertResponseStatusCodeSame(403);

        $this->upload($this->wxr(), 'fr');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(Post::class)->count([]));
        self::assertSame([], FakeWeb::$requests);

        // On a multilingual website the language of the import is chosen.
        $this->enableLocales('en', 'de');
        self::assertCount(2, $this->client->request('GET', '/admin/transfer')->filter('#transfer-wordpress-locale option'));
        $this->upload($this->wxr(), 'de');
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->em()->getRepository(Post::class)->count(['locale' => 'de']));
        $this->client->request('GET', '/de/blog/hello-world');
        self::assertResponseIsSuccessful();
    }

    public function testCommandImportsAFile(): void
    {
        $this->createUser(UserRole::Admin);
        file_put_contents($this->dir.'/wp.xml', $this->wxr());

        $tester = new CommandTester((new Application(static::$kernel ?? throw new \LogicException('No kernel.')))->find('app:wordpress:import'));
        $tester->execute(['file' => $this->dir.'/wp.xml']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 posts', $tester->getDisplay());
        self::assertStringContainsString('contact-form-7', $tester->getDisplay());
        self::assertSame(2, $this->em()->getRepository(Post::class)->count([]));

        $tester->execute(['file' => $this->dir.'/wp.xml', '--locale' => 'xx']);
        self::assertSame(1, $tester->getStatusCode());
    }

    private function upload(string $xml, ?string $locale = null): Crawler
    {
        $token = $this->csrfToken('/admin/transfer');
        $path = $this->dir.'/upload-'.bin2hex(random_bytes(4)).'.xml';
        file_put_contents($path, $xml);

        return $this->client->request('POST', '/admin/transfer/wordpress', ['_token' => $token] + (null !== $locale ? ['locale' => $locale] : []), [
            'export' => new UploadedFile($path, 'wordpress.xml', 'text/xml', null, true),
        ]);
    }

    private function document(string $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">'
            .'<channel><title>Old site</title><link>'.self::OLD_SITE.'</link><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>'.self::OLD_SITE.'</wp:base_site_url>'
            .'<wp:category><wp:term_id>3</wp:term_id><wp:category_nicename>news</wp:category_nicename><wp:cat_name><![CDATA[News &amp; Events]]></wp:cat_name></wp:category>'
            .'<wp:category><wp:term_id>1</wp:term_id><wp:category_nicename>uncategorized</wp:category_nicename><wp:cat_name><![CDATA[Uncategorized]]></wp:cat_name></wp:category>'
            .$items.'</channel></rss>';
    }

    /**
     * A small WordPress website with the things real exports contain.
     */
    private function wxr(): string
    {
        $site = self::OLD_SITE;

        return $this->document(<<<XML
            <item>
                <title>Hello world</title>
                <link>{$site}/2024/05/17/hello-world/</link>
                <dc:creator><![CDATA[wpadmin]]></dc:creator>
                <content:encoded><![CDATA[<!-- wp:paragraph -->
            <p>Welcome to <strong>WordPress</strong>. Read <a href="https://example.org/more" onclick="alert(1)">more</a>.</p>
            <!-- /wp:paragraph -->

            <!-- wp:heading -->
            <h2>Our team</h2>
            <!-- /wp:heading -->

            <!-- wp:image {"id":11} -->
            <figure class="wp-block-image"><img src="{$site}/wp-content/uploads/photo-300x200.png" alt="The team" class="wp-image-11"/><figcaption>Team photo</figcaption></figure>
            <!-- /wp:image -->

            [gallery ids="11,12"]

            <script>alert(document.cookie)</script>
            <img src="{$site}/wp-content/uploads/missing.jpg" alt="">

            <table><tr><th>Name</th><th>Price</th></tr><tr><td>Bread</td><td>2 €</td></tr></table>

            https://www.youtube.com/watch?v=dQw4w9WgXcQ

            [contact-form-7 id="5" title="Contact"]

            Goodbye.]]></content:encoded>
                <excerpt:encoded><![CDATA[A <em>short</em> summary.]]></excerpt:encoded>
                <wp:post_id>2</wp:post_id>
                <wp:post_date><![CDATA[2024-05-17 10:30:00]]></wp:post_date>
                <wp:post_date_gmt><![CDATA[2024-05-17 08:30:00]]></wp:post_date_gmt>
                <wp:post_name><![CDATA[hello-world]]></wp:post_name>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:post_type><![CDATA[post]]></wp:post_type>
                <wp:post_password><![CDATA[]]></wp:post_password>
                <category domain="post_tag" nicename="misc"><![CDATA[Misc]]></category>
                <category domain="category" nicename="news"><![CDATA[News &amp; Events]]></category>
                <wp:postmeta><wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key><wp:meta_value><![CDATA[12]]></wp:meta_value></wp:postmeta>
                <wp:comment><wp:comment_id>1</wp:comment_id><wp:comment_content><![CDATA[Nice!]]></wp:comment_content></wp:comment>
                <wp:comment><wp:comment_id>2</wp:comment_id><wp:comment_content><![CDATA[Thanks]]></wp:comment_content></wp:comment>
            </item>
            <item>
                <title>Unfinished thoughts</title>
                <link>{$site}/?p=9</link>
                <content:encoded><![CDATA[Just a line.]]></content:encoded>
                <wp:post_id>9</wp:post_id>
                <wp:post_date_gmt><![CDATA[0000-00-00 00:00:00]]></wp:post_date_gmt>
                <wp:post_name><![CDATA[]]></wp:post_name>
                <wp:status><![CDATA[draft]]></wp:status>
                <wp:post_type><![CDATA[post]]></wp:post_type>
                <category domain="category" nicename="uncategorized"><![CDATA[Uncategorized]]></category>
            </item>
            <item>
                <title>About</title>
                <link>{$site}/about/</link>
                <content:encoded><![CDATA[<p>We are a small company.</p><p><a href="{$site}/wp-content/uploads/logo.png"><img src="{$site}/wp-content/uploads/logo-150x150.png" alt="Logo"></a></p>]]></content:encoded>
                <wp:post_id>4</wp:post_id>
                <wp:post_name><![CDATA[about]]></wp:post_name>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:post_type><![CDATA[page]]></wp:post_type>
            </item>
            <item>
                <title>Members only</title>
                <link>{$site}/members/</link>
                <content:encoded><![CDATA[<p>Secret handshake.</p>]]></content:encoded>
                <wp:post_id>5</wp:post_id>
                <wp:post_name><![CDATA[members]]></wp:post_name>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:post_type><![CDATA[page]]></wp:post_type>
                <wp:post_password><![CDATA[letmein]]></wp:post_password>
            </item>
            <item>
                <title>Old revision</title>
                <wp:post_id>6</wp:post_id><wp:status><![CDATA[trash]]></wp:status><wp:post_type><![CDATA[post]]></wp:post_type>
                <content:encoded><![CDATA[<p>Deleted.</p>]]></content:encoded>
            </item>
            <item>
                <title>photo</title>
                <wp:post_id>11</wp:post_id><wp:status><![CDATA[inherit]]></wp:status><wp:post_type><![CDATA[attachment]]></wp:post_type>
                <wp:attachment_url><![CDATA[{$site}/wp-content/uploads/photo.png]]></wp:attachment_url>
            </item>
            <item>
                <title>cover</title>
                <wp:post_id>12</wp:post_id><wp:status><![CDATA[inherit]]></wp:status><wp:post_type><![CDATA[attachment]]></wp:post_type>
                <wp:attachment_url><![CDATA[{$site}/wp-content/uploads/cover.png]]></wp:attachment_url>
            </item>
            <item>
                <title>unused</title>
                <wp:post_id>13</wp:post_id><wp:status><![CDATA[inherit]]></wp:status><wp:post_type><![CDATA[attachment]]></wp:post_type>
                <wp:attachment_url><![CDATA[{$site}/wp-content/uploads/unused.png]]></wp:attachment_url>
            </item>
            <item>
                <title>A product</title>
                <wp:post_id>20</wp:post_id><wp:status><![CDATA[publish]]></wp:status><wp:post_type><![CDATA[product]]></wp:post_type>
            </item>
            <item>
                <title>Menu entry</title>
                <wp:post_id>21</wp:post_id><wp:status><![CDATA[publish]]></wp:status><wp:post_type><![CDATA[nav_menu_item]]></wp:post_type>
            </item>
            XML);
    }
}
