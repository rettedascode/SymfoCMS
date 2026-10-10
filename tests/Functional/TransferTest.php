<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Form;
use App\Entity\Media;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\Redirect;
use App\Entity\SharedBlock;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use App\Service\MediaManager;
use App\Service\Settings;
use App\Service\SiteInfo;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TransferTest extends AppWebTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/symfocms-transfer-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);

        parent::tearDown();
    }

    public function testWebsiteIsExportedAndReplacesAnotherOne(): void
    {
        $admin = $this->loginAs(UserRole::Admin);
        $this->buildWebsite($admin);
        $archive = $this->export();

        // The archive holds the manifest and the original images, nothing else.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        self::assertCount(2, $names);
        self::assertContains('site.json', $names);
        $manifest = (string) $zip->getFromName('site.json');
        $zip->close();
        self::assertStringNotContainsString($admin->getPassword(), $manifest, 'Passwords are never exported.');
        self::assertStringNotContainsString('admin@example.com', str_replace('"author_email": "admin@example.com"', '', $manifest));

        // "Another installation": different content, and one page that has to go.
        $this->wipe();
        $admin = $this->loginAs(UserRole::Admin);
        $this->createPage('Old page', ContentStatus::Published);
        $oldImage = $this->uploadImage('old.png');
        $oldFile = $this->mediaDir().'/'.$oldImage->getFilename();
        self::assertFileExists($oldFile);

        // Replacing has to be confirmed.
        $this->import($archive, 'replace', confirm: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'Please confirm');
        self::assertSame(1, $this->em()->getRepository(Page::class)->count([]));

        $crawler = $this->import($archive, 'replace');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Import finished');
        self::assertSame('3', $crawler->filter('[data-count="pages"] strong')->text());
        self::assertSame('1', $crawler->filter('[data-count="posts"] strong')->text());
        self::assertSame('1', $crawler->filter('[data-count="media"] strong')->text());
        self::assertSelectorNotExists('.report-issues');

        // The old content and its files are gone, users are kept.
        self::assertNull($this->em()->getRepository(Page::class)->findOneBy(['slug' => 'old-page']));
        self::assertFileDoesNotExist($oldFile);
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));

        // Everything is back and points to the right things, although all ids are new.
        $image = $this->em()->getRepository(Media::class)->findOneBy([]);
        self::assertInstanceOf(Media::class, $image);
        self::assertSame('Our shop', $image->getAlt());
        self::assertSame('shop.png', $image->getOriginalName());
        self::assertFileExists($this->mediaDir().'/'.$image->getFilename());
        $shared = $this->em()->getRepository(SharedBlock::class)->findOneBy(['name' => 'Opening hours']);
        $form = $this->em()->getRepository(Form::class)->findOneBy(['name' => 'Contact']);
        self::assertInstanceOf(SharedBlock::class, $shared);
        self::assertInstanceOf(Form::class, $form);
        self::assertSame('owner@example.com', $form->getNotifyEmail());

        $home = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'home', 'locale' => 'en']);
        self::assertInstanceOf(Page::class, $home);
        self::assertTrue($home->isHomepage());
        self::assertSame($image->getId(), $home->getShareImage()?->getId());
        $blocks = $home->getBlocks();
        self::assertSame(['image', 'columns', 'shared', 'form'], array_column($blocks, 'type'));
        self::assertSame($image->getId(), $blocks[0]['data']['image']);
        self::assertSame([$image->getId()], $blocks[1]['data']['left'][0]['data']['images']);
        self::assertSame($shared->getId(), $blocks[2]['data']['block']);
        self::assertSame($form->getId(), $blocks[3]['data']['form']);

        $german = $this->em()->getRepository(Page::class)->findOneBy(['locale' => 'de']);
        self::assertInstanceOf(Page::class, $german);
        self::assertSame($home->getTranslationGroup(), $german->getTranslationGroup(), 'Translations stay linked.');

        $post = $this->em()->getRepository(Post::class)->findOneBy(['slug' => 'first-post']);
        self::assertInstanceOf(Post::class, $post);
        self::assertSame('News', $post->getCategory()?->getName());
        self::assertSame($image->getId(), $post->getCoverImage()?->getId());
        self::assertSame($admin->getId(), $post->getAuthor()?->getId(), 'The author is found by email address.');
        self::assertSame('2026-03-01', $post->getPublishedAt()?->format('Y-m-d'));

        $items = $this->em()->getRepository(MenuItem::class)->findBy(['parent' => null]);
        self::assertCount(1, $items);
        self::assertSame($home->getId(), $items[0]->getPage()?->getId());
        self::assertSame('https://example.com/docs', $items[0]->getChildren()->first() instanceof MenuItem ? $items[0]->getChildren()->first()->getUrl() : null);
        self::assertSame('/shop', $this->em()->getRepository(Redirect::class)->findOneBy(['source' => '/store'])?->getTarget());

        // Settings came along, and the website shows the imported content.
        self::assertSame('Corner Shop', static::getContainer()->get(SiteInfo::class)->name());
        self::assertSame(['en', 'de'], static::getContainer()->get(ContentLocales::class)->all());
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.site-brand', 'Corner Shop');
        self::assertStringContainsString($image->getFile('large'), (string) $crawler->filter('main img')->attr('src'));
        self::assertSelectorTextContains('main', 'Open on weekdays');
        self::assertSelectorExists('main form.site-form');
        self::assertStringContainsString('--color-accent:#0a7d5a', $crawler->filter('head style')->text());
        $this->client->request('GET', '/search?q=weekdays');
        self::assertSelectorTextContains('.search-results', 'Home');
    }

    public function testImportCanAddToExistingContent(): void
    {
        $admin = $this->loginAs(UserRole::Admin);
        $this->buildWebsite($admin);
        $archive = $this->export();

        $this->wipe();
        $this->loginAs(UserRole::Admin);
        $existingHome = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $this->createCategory('News');
        $this->em()->persist((new MenuItem())->setLocation(MenuLocation::Main)->setLocale('en')->setType(MenuItemType::Url)->setLabel('Existing')->setUrl('/existing'));
        $this->em()->flush();

        $this->import($archive, 'add');
        self::assertResponseIsSuccessful();

        $pages = $this->em()->getRepository(Page::class)->findBy(['locale' => 'en'], ['id' => 'ASC']);
        self::assertSame(['home', 'home-2', 'shop'], array_map(static fn (Page $page): string => $page->getSlug(), $pages));
        self::assertTrue($this->entity(Page::class, $existingHome->getId())->isHomepage(), 'The homepage that was there stays the homepage.');
        self::assertFalse($pages[1]->isHomepage());
        self::assertSame(1, $this->em()->getRepository(Category::class)->count(['locale' => 'en']), 'An existing category is reused.');
        self::assertSame(['Existing', 'Start'], array_map(static fn (MenuItem $item): string => $item->getLabel(), $this->em()->getRepository(MenuItem::class)->findBy(['parent' => null], ['position' => 'ASC'])));
        self::assertSame('Test Site', static::getContainer()->get(SiteInfo::class)->name(), 'Settings are only taken over when replacing.');
        self::assertSame(['en', 'de'], static::getContainer()->get(ContentLocales::class)->all(), 'Languages of the imported content are switched on.');
    }

    public function testImportDistrustsTheArchive(): void
    {
        $this->loginAs(UserRole::Admin);
        (new Filesystem())->dumpFile(\dirname($this->mediaDir()).'/secret.txt', 'secret');

        // Not a ZIP file at all.
        file_put_contents($this->dir.'/text.zip', 'hello');
        $this->import($this->dir.'/text.zip', 'add');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'not a ZIP file');

        // A ZIP file from somewhere else.
        $this->archive('foreign.zip', ['format' => 'something-else']);
        $this->import($this->dir.'/foreign.zip', 'add');
        self::assertSelectorTextContains('.flash--error', 'not an export');

        // An export of a newer version.
        $this->archive('newer.zip', ['format' => 'symfocms-site', 'version' => 99]);
        $this->import($this->dir.'/newer.zip', 'add');
        self::assertSelectorTextContains('.flash--error', 'newer version');

        // A manipulated export.
        $this->archive('evil.zip', [
            'format' => 'symfocms-site',
            'version' => 1,
            'media' => [
                ['id' => 1, 'file' => '../../secret.txt', 'original_name' => 'traversal.png'],
                ['id' => 2, 'file' => 'media/script.png', 'original_name' => 'script.png'],
            ],
            'pages' => [
                ['id' => 1, 'locale' => 'en', 'title' => 'Evil', 'slug' => '../admin', 'status' => 'published', 'blocks' => [
                    ['type' => 'text', 'data' => ['content' => '<p onclick="x()">Hello<script>alert(1)</script></p>']],
                    ['type' => 'button', 'data' => ['label' => 'Click', 'url' => 'javascript:alert(1)', 'style' => 'primary']],
                    ['type' => 'image', 'data' => ['image' => 999]],
                    ['type' => 'unknown', 'data' => []],
                ]],
                ['id' => 2, 'locale' => 'not a locale', 'title' => 'Nowhere'],
                ['id' => 3, 'locale' => 'en', 'title' => 'Reserved', 'slug' => 'admin', 'status' => 'bogus'],
            ],
            'menus' => [['location' => 'main', 'locale' => 'en', 'items' => [['label' => 'Bad', 'type' => 'url', 'url' => 'javascript:alert(1)']]]],
            'settings' => ['api.enabled' => true, 'site.name' => 'Hacked'],
        ], ['media/script.png' => '<?php echo "not an image";']);

        $crawler = $this->import($this->dir.'/evil.zip', 'add');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.report-issues li'));

        self::assertSame(0, $this->em()->getRepository(Media::class)->count([]));
        self::assertSame([], glob($this->mediaDir().'/*.php') ?: []);
        $evil = $this->em()->getRepository(Page::class)->findOneBy(['title' => 'Evil']);
        self::assertInstanceOf(Page::class, $evil);
        self::assertSame('admin-2', $evil->getSlug(), 'Addresses the software needs itself are never given to a page.');
        $blocks = $evil->getBlocks();
        self::assertSame(['text', 'button', 'image'], array_column($blocks, 'type'));
        self::assertSame('<p>Hello</p>', $blocks[0]['data']['content']);
        self::assertSame('', $blocks[1]['data']['url']);
        self::assertNull($blocks[2]['data']['image']);
        $reserved = $this->em()->getRepository(Page::class)->findOneBy(['title' => 'Reserved']);
        self::assertInstanceOf(Page::class, $reserved);
        self::assertNotSame('admin', $reserved->getSlug());
        self::assertSame(ContentStatus::Draft, $reserved->getStatus());
        self::assertSame(0, $this->em()->getRepository(MenuItem::class)->count([]));
        self::assertSame('Test Site', static::getContainer()->get(SiteInfo::class)->name());
        $this->client->request('GET', '/api/v1');
        self::assertResponseStatusCodeSame(404, 'An import never switches the API on.');
    }

    public function testOnlyAdminsExportAndImport(): void
    {
        $this->loginAs(UserRole::Editor);
        $token = (string) $this->client->request('GET', '/admin/pages')->filter('input[name="_csrf_token"]')->attr('value');
        $this->archive('site.zip', ['format' => 'symfocms-site', 'version' => 1, 'pages' => [['id' => 1, 'locale' => 'en', 'title' => 'Imported']]]);

        $this->client->request('GET', '/admin/transfer');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/transfer/export', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/transfer/import', ['_token' => $token, 'mode' => 'add'], ['archive' => new UploadedFile($this->dir.'/site.zip', 'site.zip', 'application/zip', null, true)]);
        self::assertResponseStatusCodeSame(403);

        // Admins need the form token.
        $this->client->loginUser($this->createUser(UserRole::Admin));
        $this->client->request('POST', '/admin/transfer/export', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/transfer/import', ['_token' => 'wrong', 'mode' => 'add'], ['archive' => new UploadedFile($this->dir.'/site.zip', 'site.zip', 'application/zip', null, true)]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->em()->getRepository(Page::class)->count([]));
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('a[href="/admin/transfer"]');
    }

    public function testCommandsExportAndImport(): void
    {
        $admin = $this->createUser(UserRole::Admin);
        $this->buildWebsite($admin);
        $application = new Application(static::$kernel ?? throw new \LogicException('No kernel.'));

        $export = new CommandTester($application->find('app:site:export'));
        $export->execute(['file' => $this->dir.'/cli.zip']);
        $export->assertCommandIsSuccessful();
        self::assertStringContainsString('3 pages, 1 posts', (string) preg_replace('/\s+/', ' ', $export->getDisplay()));

        $import = new CommandTester($application->find('app:site:import'));
        $import->execute(['file' => $this->dir.'/cli.zip', '--replace' => true], ['interactive' => false]);
        $import->assertCommandIsSuccessful();
        self::assertStringContainsString('3 pages', $import->getDisplay());
        self::assertSame(3, $this->em()->getRepository(Page::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(Media::class)->count([]));

        $import->execute(['file' => $this->dir.'/missing.zip']);
        self::assertSame(1, $import->getStatusCode());
    }

    /**
     * A small website that uses every kind of reference an export has to carry.
     */
    private function buildWebsite(User $admin): void
    {
        $this->enableLocales('en', 'de');
        static::getContainer()->get(SiteInfo::class)->save('Corner Shop', 'Fresh every day');
        static::getContainer()->get(Settings::class)->set('theme.settings.default', ['colors' => ['accent' => '#0a7d5a']]);

        $image = $this->uploadImage('shop.png');
        $image->setAlt('Our shop');
        $form = (new Form())->setName('Contact')->setNotifyEmail('owner@example.com')->setFields([['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => []]]);
        $shared = (new SharedBlock())->setName('Opening hours')->setBlocks([['type' => 'text', 'data' => ['content' => '<p>Open on weekdays</p>']]]);
        $this->em()->persist($form);
        $this->em()->persist($shared);
        $this->em()->flush();

        $home = $this->createPage('Home', ContentStatus::Published, homepage: true);
        $home->setShareImage($image)->setBlocks([
            ['type' => 'image', 'data' => ['image' => $image->getId(), 'alt' => '', 'caption' => '']],
            ['type' => 'columns', 'data' => ['left' => [['type' => 'gallery', 'data' => ['images' => [$image->getId()]]]], 'right' => []]],
            ['type' => 'shared', 'data' => ['block' => $shared->getId()]],
            ['type' => 'form', 'data' => ['form' => $form->getId()]],
        ]);
        $this->createPage('Startseite', ContentStatus::Published, homepage: true, locale: 'de', translationOf: $home);
        $this->createPage('Shop', ContentStatus::Draft);

        $admin = $this->entity(User::class, $admin->getId());
        $post = $this->createPost('First post', $admin, $this->createCategory('News'), publishedAt: new \DateTimeImmutable('2026-03-01 10:00'));
        $post->setCoverImage($image);

        $item = (new MenuItem())->setLocation(MenuLocation::Main)->setLocale('en')->setType(MenuItemType::Page)->setLabel('Start')->setPage($home);
        $child = (new MenuItem())->setLocation(MenuLocation::Main)->setLocale('en')->setType(MenuItemType::Url)->setLabel('Docs')->setUrl('https://example.com/docs')->setParent($item);
        $this->em()->persist($item);
        $this->em()->persist($child);
        $this->em()->persist((new Redirect())->setSource('/store')->setTarget('/shop'));
        $this->em()->flush();
    }

    /**
     * Empties the database, as if the export was taken to another installation.
     */
    private function wipe(): void
    {
        static::ensureKernelShutdown();
        parent::setUp();
    }

    private function uploadImage(string $name): Media
    {
        $path = $this->dir.'/'.bin2hex(random_bytes(4)).'.png';
        $image = imagecreatetruecolor(1200, 800);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 90));
        imagepng($image, $path);

        return static::getContainer()->get(MediaManager::class)->upload(new UploadedFile($path, $name, 'image/png', null, true));
    }

    private function export(): string
    {
        $token = $this->csrfToken('/admin/transfer');
        $this->client->request('POST', '/admin/transfer/export', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename=corner-shop-', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $path = sys_get_temp_dir().'/symfocms-export-'.bin2hex(random_bytes(4)).'.zip';
        file_put_contents($path, $this->client->getInternalResponse()->getContent());

        return $path;
    }

    private function import(string $archive, string $mode, bool $confirm = true): Crawler
    {
        $token = $this->csrfToken('/admin/transfer');
        $copy = $this->dir.'/upload-'.bin2hex(random_bytes(4)).'.zip';
        copy($archive, $copy);

        return $this->client->request('POST', '/admin/transfer/import', ['_token' => $token, 'mode' => $mode] + ($confirm ? ['confirm' => '1'] : []), [
            'archive' => new UploadedFile($copy, 'site.zip', 'application/zip', null, true),
        ]);
    }

    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, string> $files
     */
    private function archive(string $name, array $manifest, array $files = []): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->dir.'/'.$name, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('site.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();
    }
}
