<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Install\DatabaseSetup;
use App\Service\ContentLocales;
use App\Service\SiteInfo;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Filesystem\Filesystem;

final class InstallerTest extends AppWebTestCase
{
    protected function setUp(): void
    {
        // The setup wizard is switched off for all other tests (see .env.test).
        $_SERVER['APP_INSTALLER'] = $_ENV['APP_INSTALLER'] = '1';
        (new Filesystem())->remove($this->installDir());

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_SERVER['APP_INSTALLER'] = $_ENV['APP_INSTALLER'] = '0';
        (new Filesystem())->remove($this->installDir());

        parent::tearDown();
    }

    public function testNewWebsiteIsSetUpThroughTheWizard(): void
    {
        // A server that has never seen the software: no tables at all.
        (new SchemaTool($this->em()))->dropSchema($this->em()->getMetadataFactory()->getAllMetadata());
        $this->em()->getConnection()->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');

        foreach (['/', '/about', '/blog', '/login', '/admin', '/does/not/exist'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/install', null, $path.' leads to the wizard.');
        }

        // Step 1: the server is checked.
        $crawler = $this->client->request('GET', '/install');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Is the server ready?');
        self::assertGreaterThan(5, $crawler->filter('.install__checks li.is-ok')->count());
        self::assertCount(0, $crawler->filter('.install__checks li.is-failed'));
        $this->client->clickLink('Continue');

        // Step 2: the database. On CI the connection comes from the server environment and is only confirmed.
        self::assertResponseIsSuccessful();
        if (static::getContainer()->get(DatabaseSetup::class)->isManagedByServer()) {
            self::assertSelectorTextContains('.flash--success', 'The connection works.');
            $this->client->clickLink('Continue');
        } else {
            self::assertSelectorExists('input[name="install_database[driver]"][value="sqlite"][checked]');
            $this->client->submitForm('Continue');
            self::assertResponseRedirects('/install/site');
            $written = (string) file_get_contents($this->installDir().'/.env.local');
            self::assertStringContainsString('DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"', $written);
            $this->client->followRedirect();
        }

        // Step 3: the website and its administrator.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your website');
        $this->client->submitForm('Set up the website', [
            'install_site[siteName]' => 'Bäckerei Sonne',
            'install_site[locale]' => 'de',
            'install_site[adminName]' => 'Grace',
            'install_site[email]' => 'not-an-email',
            'install_site[password]' => 'short',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.field--error');

        $this->client->submitForm('Set up the website', [
            'install_site[siteName]' => 'Bäckerei Sonne',
            'install_site[locale]' => 'de',
            'install_site[adminName]' => 'Grace',
            'install_site[email]' => 'grace@example.com',
            'install_site[password]' => 'a-long-password',
        ]);
        self::assertResponseRedirects('/admin');

        // The administrator is logged in and sees the admin area in the chosen language.
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash--success', 'Willkommen');
        self::assertSelectorTextSame('.sidebar__brand', 'Bäckerei Sonne');
        self::assertSelectorTextContains('.page-tree', 'Willkommen');

        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'grace@example.com']);
        self::assertInstanceOf(User::class, $admin);
        self::assertSame(UserRole::Admin, $admin->getRole());
        self::assertSame('de', $admin->getLocale());
        self::assertNotSame('a-long-password', $admin->getPassword());
        self::assertSame('Bäckerei Sonne', static::getContainer()->get(SiteInfo::class)->name());
        self::assertSame(['de'], static::getContainer()->get(ContentLocales::class)->all());
        self::assertFileExists($this->installDir().'/installed.lock');

        // The website is online with a first page ...
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Willkommen');
        self::assertSelectorTextSame('.site-brand', 'Bäckerei Sonne');
        self::assertSelectorTextContains('.site-nav__list', 'Startseite');

        // ... and the wizard is gone for good, even when its marker file is deleted.
        $this->assertInstallerIsClosed();
        (new Filesystem())->remove($this->installDir().'/installed.lock');
        $this->assertInstallerIsClosed();
        self::assertFileExists($this->installDir().'/installed.lock', 'An installed website is recognised by its users.');
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
    }

    public function testExistingWebsiteNeverSeesTheWizard(): void
    {
        $this->createUser(UserRole::Admin);
        $this->createPage('Home', ContentStatus::Published, homepage: true);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Home');

        $this->assertInstallerIsClosed();
        self::assertSame(1, $this->em()->getRepository(Page::class)->count([]));
    }

    public function testDatabaseServerNeedsNameAndUserAndAWorkingConnection(): void
    {
        $setup = static::getContainer()->get(DatabaseSetup::class);
        if ($setup->isManagedByServer()) {
            self::markTestSkipped('The database connection is set by the environment, so the form is not shown.');
        }
        if (!\extension_loaded('pdo_mysql')) {
            self::markTestSkipped('The MySQL driver is not installed.');
        }

        $this->client->request('GET', '/install/database');
        $this->client->submitForm('Continue', ['install_database[driver]' => 'mysql', 'install_database[host]' => '127.0.0.1']);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, $this->client->getCrawler()->filter('.field--error'));

        // Nobody listens on this port: the error of the database is shown and nothing is written.
        $this->client->submitForm('Continue', [
            'install_database[driver]' => 'mysql',
            'install_database[host]' => '127.0.0.1',
            'install_database[port]' => '1',
            'install_database[name]' => 'cms',
            'install_database[user]' => 'cms',
            'install_database[password]' => 'secret',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.flash--error', 'The database could not be reached.');
        self::assertFileDoesNotExist($this->installDir().'/.env.local');
    }

    public function testConnectionAddressIsBuiltAndStoredSafely(): void
    {
        $setup = static::getContainer()->get(DatabaseSetup::class);

        $url = $setup->url(['driver' => 'mysql', 'host' => 'db.example.com', 'port' => null, 'name' => 'my shop', 'user' => 'web@user', 'password' => 'p%ss:w/rd"$x']);
        self::assertSame('mysql://web%40user:p%25ss%3Aw%2Frd%22%24x@db.example.com:3306/my%20shop?charset=utf8mb4', $url);
        self::assertSame('postgresql://u:p@[::1]:5433/cms?charset=utf8', $setup->url(['driver' => 'pgsql', 'host' => '::1', 'port' => 5433, 'name' => 'cms', 'user' => 'u', 'password' => 'p']));
        self::assertSame('sqlite:///%kernel.project_dir%/var/data.db', $setup->url(['driver' => 'sqlite', 'host' => 'ignored']));

        // Existing lines of the file are kept, an older connection is replaced, percent signs are escaped.
        (new Filesystem())->dumpFile($this->installDir().'/.env.local', "APP_ENV=prod\nDATABASE_URL=\"old\"\n");
        $setup->write($url);
        $written = (string) file_get_contents($this->installDir().'/.env.local');
        self::assertSame("APP_ENV=prod\nDATABASE_URL=\"mysql://web%%40user:p%%25ss%%3Aw%%2Frd%%22%%24x@db.example.com:3306/my%%20shop?charset=utf8mb4\"\n", $written);
    }

    private function assertInstallerIsClosed(): void
    {
        foreach (['/install', '/install/database', '/install/site'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->request('POST', '/install/site', ['install_site' => ['siteName' => 'Taken over', 'locale' => 'en', 'adminName' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a-long-password']]);
        self::assertResponseStatusCodeSame(404);
    }

    private function installDir(): string
    {
        return \dirname(__DIR__, 2).'/var/test-install';
    }
}
