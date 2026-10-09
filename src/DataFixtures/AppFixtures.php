<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Media;
use App\Entity\MenuItem;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\MenuItemType;
use App\Enum\MenuLocation;
use App\Enum\UserRole;
use App\Service\ContentLocales;
use App\Service\MediaManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo content for local development: three users, two pages and three blog
 * posts with generated cover images, both menus, and German versions of the
 * pages and of one post to show how translations work.
 *
 *     php bin/console doctrine:fixtures:load
 */
final class AppFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'demo-password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly MediaManager $mediaManager,
        #[Autowire(param: 'app.media_dir')]
        private readonly string $mediaDir,
        private readonly ContentLocales $locales,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->removeOldUploads();

        // The demo site is bilingual: English is the main language, German lives under /de.
        $this->locales->save(['en', 'de']);

        $admin = $this->createUser($manager, 'admin@example.com', 'Ada Admin', UserRole::Admin);
        $editor = $this->createUser($manager, 'editor@example.com', 'Eric Editor', UserRole::Editor);
        $author = $this->createUser($manager, 'author@example.com', 'Aylin Author', UserRole::Author);
        $manager->flush();

        $harbour = $this->createImage($admin, 'harbour.jpg', 'Sunrise over a calm harbour', [38, 70, 160], [255, 196, 120]);
        $forest = $this->createImage($admin, 'forest.jpg', 'Green hills under a bright sky', [24, 110, 90], [190, 230, 160]);
        $desk = $this->createImage($admin, 'desk.jpg', 'Warm evening light', [120, 50, 110], [255, 170, 130]);

        $home = (new Page())
            ->setTitle('Welcome to SymfoCMS')
            ->setSlug('home')
            ->setStatus(ContentStatus::Published)
            ->setHomepage(true)
            ->setMetaDescription('SymfoCMS is a small, friendly content management system with a simple block editor.')
            ->setBlocks([
                ['type' => 'text', 'data' => ['content' => '<p>This is your new website. Everything you see here is built from <strong>blocks</strong> – headings, text, images, buttons and more – that you can add, move and remove with a few clicks.</p>']],
                ['type' => 'image', 'data' => ['image' => $harbour->getId(), 'alt' => '', 'caption' => 'Images come from the media library and are resized automatically.']],
                ['type' => 'heading', 'data' => ['text' => 'Made for people, not for programmers', 'level' => '2']],
                ['type' => 'columns', 'data' => [
                    'left' => [
                        ['type' => 'heading', 'data' => ['text' => 'Write', 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>Type your text, make it <strong>bold</strong> or <em>italic</em>, add links and lists. You never have to look at code.</p>']],
                    ],
                    'right' => [
                        ['type' => 'heading', 'data' => ['text' => 'Preview', 'level' => '3']],
                        ['type' => 'text', 'data' => ['content' => '<p>The live preview next to the editor shows the page exactly as visitors will see it, while you type.</p>']],
                    ],
                ]],
                ['type' => 'quote', 'data' => ['text' => 'Simple things should be simple, complex things should be possible.', 'author' => 'Alan Kay']],
                ['type' => 'button', 'data' => ['label' => 'Read the blog', 'url' => '/blog', 'style' => 'primary']],
            ]);
        $manager->persist($home);

        $about = (new Page())
            ->setTitle('About')
            ->setSlug('about')
            ->setStatus(ContentStatus::Published)
            ->setMetaDescription('What this demo website is about and how to make it your own.')
            ->setBlocks([
                ['type' => 'text', 'data' => ['content' => '<p>This page is part of the demo content. Log in, open <strong>Pages</strong> and edit it to tell visitors who you are.</p>']],
                ['type' => 'heading', 'data' => ['text' => 'What you can do next', 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<ul><li>Change the texts and images on this page</li><li>Write your first blog post</li><li>Rearrange the navigation under <em>Menus</em></li></ul>']],
                ['type' => 'divider', 'data' => []],
                ['type' => 'button', 'data' => ['label' => 'Log in', 'url' => '/login', 'style' => 'secondary']],
            ]);
        $manager->persist($about);

        $news = (new Category())->setName('News')->setSlug('news');
        $guides = (new Category())->setName('Guides')->setSlug('guides');
        $manager->persist($news);
        $manager->persist($guides);

        $firstPost = $this->createPost($manager, 'Hello World', 'hello-world', $news, $admin, $harbour, '-9 days',
            'The first post on this website – a short hello and a look at what is to come.', [
                ['type' => 'text', 'data' => ['content' => '<p>Every blog needs a first post, and this is ours. Posts are written with the same block editor as pages.</p>']],
                ['type' => 'quote', 'data' => ['text' => 'The secret of getting ahead is getting started.', 'author' => 'Mark Twain']],
                ['type' => 'text', 'data' => ['content' => '<p>Delete this post or rewrite it – it is yours now.</p>']],
            ]);
        $this->createPost($manager, 'Five Tips for Writing Good Web Pages', 'five-tips-for-writing-good-web-pages', $guides, $editor, $forest, '-5 days',
            'Short paragraphs, clear headings and helpful images: small habits that make every page easier to read.', [
                ['type' => 'text', 'data' => ['content' => '<p>People rarely read web pages word for word. They scan. These habits help them find what they need:</p><ol><li>Start with the most important point</li><li>Use a heading for every new topic</li><li>Keep paragraphs short</li><li>Describe every image</li><li>End with a clear next step</li></ol>']],
                ['type' => 'image', 'data' => ['image' => $forest->getId(), 'alt' => '', 'caption' => 'A calm page is easier to read.']],
                ['type' => 'button', 'data' => ['label' => 'More guides', 'url' => '/blog/category/guides', 'style' => 'secondary']],
            ]);
        $this->createPost($manager, 'How to Schedule a Post', 'how-to-schedule-a-post', $guides, $author, $desk, '-1 day',
            'Write today, publish next week: set a publish date in the future and the post appears on its own.', [
                ['type' => 'heading', 'data' => ['text' => 'Pick a date', 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>Open the <strong>Settings</strong> tab of a post and choose a publish date. If that date lies in the future, the post is <em>scheduled</em>: visitors will not see it before then.</p>']],
                ['type' => 'heading', 'data' => ['text' => 'Check it first', 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>While you are logged in, you can open drafts and scheduled posts to see how they will look.</p>']],
            ]);

        // German versions. Sharing the translation group is what links them to the English originals.
        $startseite = (new Page())
            ->setLocale('de')
            ->setTranslationGroup($home->getTranslationGroup())
            ->setTitle('Willkommen bei SymfoCMS')
            ->setSlug('startseite')
            ->setStatus(ContentStatus::Published)
            ->setHomepage(true)
            ->setMetaDescription('SymfoCMS ist ein kleines, freundliches Content-Management-System mit einem einfachen Block-Editor.')
            ->setBlocks([
                ['type' => 'text', 'data' => ['content' => '<p>Das ist deine neue Website. Alles, was du hier siehst, besteht aus <strong>Blöcken</strong> – Überschriften, Texten, Bildern, Buttons und mehr –, die du mit wenigen Klicks hinzufügst, verschiebst und entfernst.</p>']],
                ['type' => 'image', 'data' => ['image' => $harbour->getId(), 'alt' => 'Sonnenaufgang über einem ruhigen Hafen', 'caption' => 'Bilder kommen aus der Mediathek und werden automatisch verkleinert.']],
                ['type' => 'heading', 'data' => ['text' => 'Mehrsprachig von Anfang an', 'level' => '2']],
                ['type' => 'text', 'data' => ['content' => '<p>Diese Seite ist die deutsche Version der englischen Startseite. Über den Sprachumschalter oben gelangst du zur jeweils anderen Fassung.</p>']],
                ['type' => 'button', 'data' => ['label' => 'Zum Blog', 'url' => '/de/blog', 'style' => 'primary']],
            ]);
        $manager->persist($startseite);

        $ueberUns = (new Page())
            ->setLocale('de')
            ->setTranslationGroup($about->getTranslationGroup())
            ->setTitle('Über uns')
            ->setSlug('ueber-uns')
            ->setStatus(ContentStatus::Published)
            ->setMetaDescription('Worum es auf dieser Demo-Website geht und wie du sie zu deiner eigenen machst.')
            ->setBlocks([
                ['type' => 'text', 'data' => ['content' => '<p>Diese Seite gehört zu den Demo-Inhalten. Melde dich an, öffne <strong>Seiten</strong> und bearbeite sie, um Besuchern zu erzählen, wer du bist.</p>']],
                ['type' => 'button', 'data' => ['label' => 'Anmelden', 'url' => '/login', 'style' => 'secondary']],
            ]);
        $manager->persist($ueberUns);

        $neuigkeiten = (new Category())->setLocale('de')->setName('Neuigkeiten')->setSlug('neuigkeiten');
        $manager->persist($neuigkeiten);

        $hallo = $this->createPost($manager, 'Hallo Welt', 'hallo-welt', $neuigkeiten, $admin, $harbour, '-9 days',
            'Der erste Beitrag auf dieser Website – ein kurzes Hallo und ein Blick auf das, was kommt.', [
                ['type' => 'text', 'data' => ['content' => '<p>Jeder Blog braucht einen ersten Beitrag, und das ist unserer. Beiträge werden mit demselben Block-Editor geschrieben wie Seiten.</p>']],
                ['type' => 'quote', 'data' => ['text' => 'Das Geheimnis des Vorwärtskommens besteht darin, den ersten Schritt zu tun.', 'author' => 'Mark Twain']],
            ]);
        $hallo->setLocale('de')->setTranslationGroup($firstPost->getTranslationGroup());

        $manager->flush();

        // German menus: every language has its own navigation.
        $this->createMenuItem($manager, MenuLocation::Main, 0, 'Startseite', page: $startseite)->setLocale('de');
        $this->createMenuItem($manager, MenuLocation::Main, 1, 'Über uns', page: $ueberUns)->setLocale('de');
        $this->createMenuItem($manager, MenuLocation::Main, 2, 'Blog', url: '/de/blog')->setLocale('de');
        $this->createMenuItem($manager, MenuLocation::Footer, 0, 'Über uns', page: $ueberUns)->setLocale('de');
        $this->createMenuItem($manager, MenuLocation::Footer, 1, 'RSS-Feed', url: '/de/feed.xml')->setLocale('de');

        // Main navigation: Home, About and a "Blog" dropdown with the two categories.
        $this->createMenuItem($manager, MenuLocation::Main, 0, 'Home', page: $home);
        $this->createMenuItem($manager, MenuLocation::Main, 1, 'About', page: $about);
        $blog = $this->createMenuItem($manager, MenuLocation::Main, 2, 'Blog', url: '/blog');
        $this->createMenuItem($manager, MenuLocation::Main, 0, 'News', url: '/blog/category/news', parent: $blog);
        $this->createMenuItem($manager, MenuLocation::Main, 1, 'Guides', url: '/blog/category/guides', parent: $blog);

        $this->createMenuItem($manager, MenuLocation::Footer, 0, 'About', page: $about);
        $this->createMenuItem($manager, MenuLocation::Footer, 1, 'First post', post: $firstPost);
        $this->createMenuItem($manager, MenuLocation::Footer, 2, 'RSS feed', url: '/feed.xml');

        $manager->flush();
    }

    private function createUser(ObjectManager $manager, string $email, string $name, UserRole $role): User
    {
        $user = (new User())->setEmail($email)->setName($name)->setRole($role);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
        $manager->persist($user);

        return $user;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    private function createPost(ObjectManager $manager, string $title, string $slug, Category $category, User $author, Media $cover, string $publishedAt, string $excerpt, array $blocks): Post
    {
        $post = (new Post())
            ->setTitle($title)
            ->setSlug($slug)
            ->setStatus(ContentStatus::Published)
            ->setPublishedAt(new \DateTimeImmutable($publishedAt))
            ->setCategory($category)
            ->setAuthor($author)
            ->setCoverImage($cover)
            ->setExcerpt($excerpt)
            ->setBlocks($blocks);
        $manager->persist($post);

        return $post;
    }

    private function createMenuItem(ObjectManager $manager, MenuLocation $location, int $position, string $label, ?Page $page = null, ?Post $post = null, ?string $url = null, ?MenuItem $parent = null): MenuItem
    {
        $item = (new MenuItem())
            ->setLocation($location)
            ->setPosition($position)
            ->setLabel($label)
            ->setType(match (true) {
                null !== $page => MenuItemType::Page,
                null !== $post => MenuItemType::Post,
                default => MenuItemType::Url,
            })
            ->setPage($page)
            ->setPost($post)
            ->setUrl($url)
            ->setParent($parent);
        $manager->persist($item);

        return $item;
    }

    /**
     * Draws a simple landscape so the demo has images without shipping binary files in the repository.
     *
     * @param array{int, int, int} $top    RGB colour of the sky at the top
     * @param array{int, int, int} $bottom RGB colour of the sky at the horizon
     */
    private function createImage(User $uploadedBy, string $name, string $alt, array $top, array $bottom): Media
    {
        $width = 1920;
        $height = 1080;
        $image = imagecreatetruecolor($width, $height);
        \assert(false !== $image);

        for ($y = 0; $y < $height; ++$y) {
            $ratio = $y / $height;
            $color = imagecolorallocate(
                $image,
                $this->channel($top[0] + ($bottom[0] - $top[0]) * $ratio),
                $this->channel($top[1] + ($bottom[1] - $top[1]) * $ratio),
                $this->channel($top[2] + ($bottom[2] - $top[2]) * $ratio),
            );
            imageline($image, 0, $y, $width, $y, (int) $color);
        }

        // A sun and three layers of hills, each a little darker than the one behind it.
        imagefilledellipse($image, (int) ($width * 0.72), (int) ($height * 0.42), 220, 220, (int) imagecolorallocatealpha($image, 255, 255, 255, 40));
        foreach ([[0.62, 90, 0.55], [0.72, 60, 0.4], [0.84, 40, 0.25]] as [$base, $amplitude, $brightness]) {
            $points = [0, $height];
            for ($x = 0; $x <= $width; $x += 40) {
                $points[] = $x;
                $points[] = (int) ($height * $base + sin($x / 260 + $base * 9) * $amplitude);
            }
            $points[] = $width;
            $points[] = $height;
            imagefilledpolygon($image, $points, (int) imagecolorallocate($image, $this->channel($top[0] * $brightness), $this->channel($top[1] * $brightness), $this->channel($top[2] * $brightness)));
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'cms');
        imagejpeg($image, $path, 88);

        $media = $this->mediaManager->upload(new UploadedFile($path, $name, 'image/jpeg', null, true), $uploadedBy);
        $media->setAlt($alt);

        return $media;
    }

    /**
     * @return int<0, 255>
     */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /**
     * Loading fixtures empties the database, so files of earlier uploads would be left behind as orphans.
     */
    private function removeOldUploads(): void
    {
        if (!is_dir($this->mediaDir)) {
            return;
        }

        (new Filesystem())->remove((new Finder())->files()->in($this->mediaDir)->depth(0)->name('/^[a-f0-9]{24}(-[a-z]+)?\.(jpg|png|gif|webp)$/'));
    }
}
