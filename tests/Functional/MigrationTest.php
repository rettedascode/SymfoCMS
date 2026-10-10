<?php

namespace App\Tests\Functional;

use App\Tests\AppWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Upgrading an existing site must never lose content. This test builds the
 * database of the first release, fills it, runs every later migration and
 * checks that everything is still there and still connected.
 */
final class MigrationTest extends AppWebTestCase
{
    private const FIRST_RELEASE = 'DoctrineMigrations\Version20261009000000';

    public function testMigrationsKeepExistingContent(): void
    {
        $connection = $this->em()->getConnection();

        // Start from nothing: no tables, no record of executed migrations.
        (new SchemaTool($this->em()))->dropSchema($this->em()->getMetadataFactory()->getAllMetadata());
        $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');

        $this->migrate(self::FIRST_RELEASE);
        $this->insertFirstReleaseContent($connection);

        $this->migrate('latest');

        // Rows and their relations survived, including those that SQLite could have cascaded away.
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM pages'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM posts'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM posts WHERE category_id IS NOT NULL AND author_id IS NOT NULL AND cover_image_id IS NOT NULL'));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM menu_items'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM menu_items WHERE parent_id IS NOT NULL'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM menu_items WHERE page_id IS NOT NULL OR post_id IS NOT NULL'));

        // Existing content was assigned to the site language, each item in its own translation group.
        foreach (['pages', 'posts', 'categories', 'menu_items'] as $table) {
            self::assertSame(['en'], $connection->fetchFirstColumn('SELECT DISTINCT locale FROM '.$table), $table);
        }
        $groups = $connection->fetchFirstColumn('SELECT translation_group FROM pages');
        self::assertCount(2, array_unique($groups));
        self::assertSame([32, 32], array_map(\strlen(...), $groups));

        // Pages that existed before there was a page tree are all on the top level.
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM pages WHERE parent_id IS NULL AND position = 0'));

        // Categories that existed before they could be arranged share the first place.
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM categories WHERE position <> 0'));

        // Tables added by later migrations exist and start empty.
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM revisions'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM shared_blocks'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM page_templates'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM forms'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM redirects'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM form_submissions'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM search_index'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));

        // The result is exactly the schema the application expects (the command fails otherwise) ...
        $this->runCommand(['command' => 'doctrine:schema:validate']);

        // ... and the upgraded site still serves its pages at their old addresses.
        $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'About');
        self::assertSelectorTextContains('.site-nav__list', 'About');

        // The search index fills itself the first time somebody searches.
        $this->client->request('GET', '/search?q=about');
        self::assertSelectorTextContains('.search-results', 'About');
    }

    private function insertFirstReleaseContent(Connection $connection): void
    {
        $now = '2026-01-01 10:00:00';

        $connection->insert('users', ['email' => 'a@example.com', 'name' => 'Ada', 'password' => 'x', 'role' => 'ROLE_ADMIN', 'active' => 1, 'locale' => 'en', 'created_at' => $now]);
        $userId = (int) $connection->fetchOne('SELECT id FROM users');
        $connection->insert('categories', ['name' => 'News', 'slug' => 'news']);
        $categoryId = (int) $connection->fetchOne('SELECT id FROM categories');
        $connection->insert('media', ['filename' => 'abc.png', 'original_name' => 'abc.png', 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'alt' => '', 'variants' => '{}', 'created_at' => $now, 'uploaded_by_id' => $userId]);
        $mediaId = (int) $connection->fetchOne('SELECT id FROM media');

        $content = ['status' => 'published', 'blocks' => '[]', 'created_at' => $now, 'updated_at' => $now];
        $connection->insert('pages', ['title' => 'Home', 'slug' => 'home', 'homepage' => 1] + $content);
        $connection->insert('pages', ['title' => 'About', 'slug' => 'about', 'homepage' => 0] + $content);
        $aboutId = (int) $connection->fetchOne("SELECT id FROM pages WHERE slug = 'about'");
        $connection->insert('posts', ['title' => 'Hello', 'slug' => 'hello', 'published_at' => $now, 'category_id' => $categoryId, 'author_id' => $userId, 'cover_image_id' => $mediaId] + $content);
        $postId = (int) $connection->fetchOne('SELECT id FROM posts');

        $item = ['location' => 'main', 'type' => 'page', 'position' => 0];
        $connection->insert('menu_items', ['label' => 'About', 'page_id' => $aboutId] + $item);
        $parentId = (int) $connection->fetchOne('SELECT id FROM menu_items');
        $connection->insert('menu_items', ['label' => 'Hello', 'type' => 'post', 'post_id' => $postId, 'parent_id' => $parentId] + $item);
        $connection->insert('menu_items', ['label' => 'Docs', 'type' => 'url', 'url' => 'https://example.com', 'position' => 1] + $item);
    }

    private function migrate(string $version): void
    {
        $this->runCommand(['command' => 'doctrine:migrations:migrate', 'version' => $version, '--no-interaction' => true]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): void
    {
        $application = new Application(static::$kernel ?? throw new \LogicException('The kernel is not booted.'));
        $application->setAutoExit(false);

        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($input + ['--no-ansi' => true]), $output);
        self::assertSame(0, $exitCode, \sprintf('"%s" failed: %s', $input['command'], $output->fetch()));
    }
}
