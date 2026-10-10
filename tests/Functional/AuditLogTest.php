<?php

namespace App\Tests\Functional;

use App\Audit\AuditLogger;
use App\Entity\AuditEntry;
use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AuditLogTest extends AppWebTestCase
{
    public function testChangesAreRecordedWithWhoDidThem(): void
    {
        $editor = $this->createUser(UserRole::Editor);
        $this->client->loginUser($editor);
        $before = $this->entryCount();

        // Create a page, change it, publish it, take it offline, delete it.
        $this->client->request('GET', '/admin/pages/new');
        $this->client->submitForm('Continue', ['content[title]' => 'Opening hours']);
        $page = $this->em()->getRepository(Page::class)->findOneBy(['slug' => 'opening-hours']);
        self::assertInstanceOf(Page::class, $page);
        $url = '/admin/pages/'.$page->getId().'/edit';

        $this->client->request('GET', $url);
        $this->client->submitForm('Save draft', ['page[title]' => 'Our opening hours', 'page[blocks]' => $this->json([['type' => 'text', 'data' => ['content' => '<p>Open daily</p>']]])]);
        $this->client->request('GET', $url);
        // Without JavaScript the test has to send the blocks itself, as the editor would.
        $blocks = $this->json([['type' => 'text', 'data' => ['content' => '<p>Open daily</p>']]]);
        $this->client->submitForm('Publish', ['page[blocks]' => $blocks]);
        $this->client->request('GET', $url);
        $this->client->submitForm('Save changes', ['page[status]' => 'draft', 'page[blocks]' => $blocks]);
        $token = $this->csrfToken('/admin/pages');
        $this->client->request('POST', '/admin/pages/'.$page->getId().'/delete', ['_token' => $token]);

        $entries = $this->entries(['subjectType' => 'page']);
        self::assertSame(['created', 'updated', 'published', 'unpublished', 'deleted'], array_map(static fn (AuditEntry $entry): string => $entry->getAction(), $entries));
        foreach ($entries as $entry) {
            self::assertSame($editor->getId(), $entry->getUserId());
            self::assertSame('Editor User', $entry->getUserName());
            self::assertSame($page->getId(), $entry->getSubjectId());
        }
        self::assertSame('Opening hours', $entries[0]->getSubjectLabel());
        self::assertSame('Our opening hours', $entries[4]->getSubjectLabel(), 'The title is kept, so the entry stays readable after the page is gone.');
        self::assertEqualsCanonicalizing(['title', 'blocks'], $entries[1]->getDetails()['fields']);
        self::assertSame(['status'], $entries[2]->getDetails()['fields']);

        // Typing in the editor (autosave) is not a change of the page.
        $other = $this->createPage('Draft page', ContentStatus::Draft);
        $count = $this->entryCount();
        $this->client->request('POST', '/admin/pages/'.$other->getId().'/autosave', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json(['fields' => ['title' => 'Typing …'], 'blocks' => []]));
        self::assertResponseIsSuccessful();
        self::assertSame($count, $this->entryCount());

        self::assertGreaterThan($before, $this->entryCount());
    }

    public function testUsersSettingsAndLoginsAreRecordedWithoutSecrets(): void
    {
        $admin = $this->createUser(UserRole::Admin);

        // A failed and a successful login through the real form.
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['_username' => 'admin@example.com', '_password' => 'wrong-password']);
        $this->client->followRedirect();
        $this->client->submitForm('Log in', ['_username' => 'admin@example.com', '_password' => self::PASSWORD]);

        // A new user and a changed setting.
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Save', ['user[name]' => 'Nora New', 'user[email]' => 'nora@example.com', 'user[role]' => 'ROLE_AUTHOR', 'user[plainPassword]' => 'nora-secret-password']);
        self::assertResponseRedirects('/admin/users');
        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[siteName]' => 'Renamed Site']);

        $actions = array_map(static fn (AuditEntry $entry): string => $entry->getAction().' '.$entry->getSubjectType().' '.$entry->getSubjectLabel(), $this->entries([]));
        self::assertContains('login_failed user admin@example.com', $actions);
        self::assertContains('login user Admin User', $actions);
        self::assertContains('created user Nora New', $actions);
        self::assertContains('created settings site.name', $actions);

        $failed = $this->entries(['action' => 'login_failed'])[0];
        self::assertNull($failed->getUserId());
        self::assertSame($admin->getId(), $this->entries(['action' => 'login'])[0]->getUserId());

        // Neither passwords nor their hashes end up in the log.
        $everything = json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('wrong-password', $everything);
        self::assertStringNotContainsString('nora-secret-password', $everything);
        self::assertStringNotContainsString(self::PASSWORD, $everything);
        self::assertStringNotContainsString($admin->getPassword(), $everything);
    }

    public function testAdminReadsAndFiltersTheLog(): void
    {
        // Before anybody is logged in, entries belong to the system - like those of a console command.
        $logger = static::getContainer()->get(AuditLogger::class);
        $logger->log('imported', 'site', null, 'add', ['counts' => ['pages' => 3]]);
        $admin = $this->loginAs(UserRole::Admin);
        $editor = $this->createUser(UserRole::Editor);
        $logger->log('created', 'page', 900, 'About us', [], $editor);
        $logger->log('updated', 'page', 900, 'About us', ['fields' => ['title', 'seoTitle', 'customField']], $editor);
        $logger->log('published', 'post', 7, 'Summer <b>party</b>', [], $admin);
        $page = $this->createPage('Still here', ContentStatus::Draft);

        $crawler = $this->client->request('GET', '/admin/activity');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('.audit-table tbody tr');
        self::assertGreaterThanOrEqual(5, $rows->count());
        self::assertSame('created', $rows->first()->attr('data-action'), 'Newest first.');
        self::assertStringContainsString('Still here', $rows->first()->text());
        self::assertSame('/admin/pages/'.$page->getId().'/edit', $rows->first()->filter('td:last-child a')->first()->attr('href'), 'Things that still exist are linked.');
        self::assertSelectorTextContains('.audit-table', 'Summer <b>party</b>');
        self::assertSelectorTextContains('.audit-table', 'Changed: title, title for search engines, custom field');
        self::assertSelectorTextContains('.audit-table', 'System');

        // By person.
        $crawler = $this->client->request('GET', '/admin/activity?user='.$editor->getId());
        self::assertSame(['Changed', 'Created'], $this->badges($crawler));
        $crawler = $this->client->request('GET', '/admin/activity?user=system');
        self::assertSame(['Created', 'Imported'], $this->badges($crawler), 'The import, and the administrator account that was created before anybody was logged in.');

        // By kind of content, by one entry, and by title.
        $crawler = $this->client->request('GET', '/admin/activity?type=post');
        self::assertSame(['Published'], $this->badges($crawler));
        $crawler = $this->client->request('GET', '/admin/activity?type=page&id=900');
        self::assertSame(['Changed', 'Created'], $this->badges($crawler));
        $crawler = $this->client->request('GET', '/admin/activity?q=ABOUT');
        self::assertCount(2, $crawler->filter('.audit-table tbody tr'));
        $this->client->request('GET', '/admin/activity?q=100%25');
        self::assertSelectorTextContains('.empty', 'Nothing to show');

        // The filter form offers the users and the kinds that occur.
        $crawler = $this->client->request('GET', '/admin/activity');
        self::assertContains('Editor User', $crawler->filter('#audit-user option')->each(static fn (Crawler $option): string => $option->text()));
        self::assertContains('Website', $crawler->filter('#audit-type option')->each(static fn (Crawler $option): string => $option->text()));
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('a[href="/admin/activity"]');
    }

    public function testLogIsPaginatedAndOldEntriesExpire(): void
    {
        $this->loginAs(UserRole::Admin);
        $connection = $this->em()->getConnection();
        $connection->executeStatement('DELETE FROM audit_log');
        for ($i = 1; $i <= 55; ++$i) {
            $connection->insert('audit_log', ['created_at' => (new \DateTimeImmutable('-'.$i.' minutes'))->format('Y-m-d H:i:s'), 'user_name' => '', 'action' => 'updated', 'subject_type' => 'page', 'subject_label' => 'Entry '.$i]);
        }
        $connection->insert('audit_log', ['created_at' => (new \DateTimeImmutable('-2 years'))->format('Y-m-d H:i:s'), 'user_name' => '', 'action' => 'updated', 'subject_type' => 'page', 'subject_label' => 'Ancient']);

        $crawler = $this->client->request('GET', '/admin/activity?type=page');
        self::assertCount(50, $crawler->filter('.audit-table tbody tr'));
        self::assertSame('/admin/activity?type=page&page=2', $crawler->filter('.pager a')->attr('href'));

        $crawler = $this->client->request('GET', '/admin/activity?type=page&page=2');
        self::assertCount(5, $crawler->filter('.audit-table tbody tr'));
        self::assertStringNotContainsString('Ancient', $crawler->filter('.audit-table')->text(), 'Entries older than a year are removed.');
        self::assertSame(55, (int) $connection->fetchOne("SELECT COUNT(*) FROM audit_log WHERE subject_type = 'page'"));
    }

    public function testOnlyAdminsSeeTheLog(): void
    {
        $this->loginAs(UserRole::Editor);
        $this->client->request('GET', '/admin/activity');
        self::assertResponseStatusCodeSame(403);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/admin/activity');
        self::assertResponseRedirects('/login');
    }

    private function entryCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log');
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<AuditEntry> oldest first
     */
    private function entries(array $criteria): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(AuditEntry::class)->findBy($criteria, ['id' => 'ASC']);
    }

    /**
     * @return list<string>
     */
    private function badges(Crawler $crawler): array
    {
        return $crawler->filter('.audit-table tbody .badge')->each(static fn (Crawler $badge): string => $badge->text());
    }
}
