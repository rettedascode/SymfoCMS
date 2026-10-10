<?php

namespace App\Tests\Functional;

use App\Dashboard\Dashboard;
use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The start page: which widgets it shows to whom, and how the arrangement of a user is stored.
 * Dragging itself is covered by tests/Browser/DashboardDragDropTest.
 */
final class DashboardTest extends AppWebTestCase
{
    public function testStartPageShowsTheWidgetsWithWhatTheUserWorksOn(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $other = $this->createUser(UserRole::Author);
        $this->createPage('About', ContentStatus::Published);
        $this->createPost('My idea', $editor, status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Waiting', $editor, status: ContentStatus::Review, publishedAt: null);
        $this->createPost('Their idea', $other, status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Out', $editor);
        $this->createPost('Next week', $other, publishedAt: new \DateTimeImmutable('+7 days'));
        $this->createPost('Tomorrow', $editor, publishedAt: new \DateTimeImmutable('+1 day'));
        $form = (new Form())->setName('Contact')->setFields([['id' => 'f1', 'type' => 'text', 'label' => 'Name', 'required' => true, 'options' => []]]);
        $this->em()->persist($form);
        $this->em()->persist(new FormSubmission($form, [['label' => 'Name', 'value' => 'Ada']]));
        $this->em()->persist((new FormSubmission($form, [['label' => 'Name', 'value' => 'Grace']]))->setRead(true));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello Editor User');
        self::assertSame(['recent:4', 'drafts:2', 'scheduled:3', 'submissions:3'], $this->layout($crawler));

        $titles = static fn (string $widget): array => $crawler->filter('[data-dnd-id="'.$widget.'"] .widget__list a')->each(static fn ($link): string => $link->text());
        self::assertSame(['My idea', 'Waiting'], array_values(array_intersect(['My idea', 'Waiting'], $titles('drafts'))));
        self::assertCount(2, $titles('drafts'), 'Only what the user wrote and has not published.');
        self::assertSame(['Tomorrow', 'Next week'], $titles('scheduled'), 'The next post first.');
        self::assertSame(['Contact'], $titles('submissions'), 'Only submissions nobody has read yet.');
        self::assertContains('Their idea', $titles('recent'), 'Editors see what everybody changed.');
        self::assertCount(6, $titles('recent'), 'A widget is a glance, not a list of everything.');

        // Every widget can be dragged and resized.
        self::assertCount(4, $crawler->filter('.widget[data-dnd-item] > .widget__header > [data-dnd-handle]'));
        self::assertCount(4, $crawler->filter('.widget > [role="separator"][tabindex="0"]'));
        self::assertSame('Two thirds', $crawler->filter('[data-dnd-id="recent"] [role="separator"]')->attr('aria-valuetext'));
        self::assertSame($this->json(Dashboard::SIZES), $crawler->filter('[data-controller="dashboard"]')->attr('data-dashboard-sizes-value'));
    }

    public function testRecentChangesNameWhatKindOfContentItIs(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $this->createPage('About', ContentStatus::Published);
        $this->createPost('News', $editor);

        $entries = $this->client->request('GET', '/admin')->filter('[data-dnd-id="recent"] .widget__list li')->each(static fn ($entry): string => trim((string) preg_replace('/\s+/', ' ', $entry->text())));
        sort($entries);
        self::assertCount(2, $entries);
        self::assertStringStartsWith('About Page ·', $entries[0]);
        self::assertStringStartsWith('News Post ·', $entries[1]);
    }

    public function testAuthorsGetTheirOwnPostsAndNoFormSubmissions(): void
    {
        $author = $this->loginAs(UserRole::Author);
        $editor = $this->createUser(UserRole::Editor);
        $this->createPage('About', ContentStatus::Published);
        $this->createPost('Mine', $author, status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Theirs', $editor, publishedAt: new \DateTimeImmutable('+2 days'));

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSame(['recent:4', 'drafts:2', 'scheduled:3'], $this->layout($crawler));
        self::assertSame(['Mine'], $crawler->filter('[data-dnd-id="recent"] .widget__list a')->each(static fn ($link): string => $link->text()));
        self::assertSelectorTextContains('[data-dnd-id="scheduled"] .widget__body', 'No post is scheduled.');
    }

    public function testEveryUserHasAnArrangementOfTheirOwn(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $token = $this->token();

        $this->arrange($token, [['id' => 'scheduled', 'size' => 6], ['id' => 'submissions', 'size' => 2], ['id' => 'recent', 'size' => 3], ['id' => 'drafts', 'size' => 3]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['scheduled:6', 'submissions:2', 'recent:3', 'drafts:3'], $this->layout($this->client->request('GET', '/admin')));

        // Somebody else still sees the start page as it comes.
        $this->client->loginUser($this->createUser(UserRole::Admin));
        self::assertSame(['recent:4', 'drafts:2', 'scheduled:3', 'submissions:3'], $this->layout($this->client->request('GET', '/admin')));

        // A user who loses access to a widget keeps the rest of their arrangement.
        $stored = $this->entity(User::class, $editor->getId());
        $stored->setRole(UserRole::Author);
        $this->em()->flush();
        $this->client->loginUser($stored);
        self::assertSame(['scheduled:6', 'recent:3', 'drafts:3'], $this->layout($this->client->request('GET', '/admin')));
    }

    public function testArrangementsThatMakeNoSenseAreRefused(): void
    {
        $author = $this->loginAs(UserRole::Author);
        $token = $this->token();
        $good = [['id' => 'drafts', 'size' => 3], ['id' => 'recent', 'size' => 3], ['id' => 'scheduled', 'size' => 6]];

        foreach ([
            'a widget is missing' => \array_slice($good, 0, 2),
            'a widget twice' => [$good[0], $good[0], $good[2]],
            'a widget that does not exist' => [$good[0], $good[1], ['id' => 'weather', 'size' => 3]],
            'a widget the user may not see' => [$good[0], $good[1], ['id' => 'submissions', 'size' => 3]],
            'a width that does not exist' => [$good[0], $good[1], ['id' => 'scheduled', 'size' => 5]],
            'a width as text' => [$good[0], $good[1], ['id' => 'scheduled', 'size' => '6']],
            'not a list' => ['drafts' => 3],
            'nothing' => null,
        ] as $case => $widgets) {
            $this->arrange($token, $widgets);
            self::assertResponseStatusCodeSame(400, $case);
        }
        $this->client->request('POST', '/admin/dashboard/layout', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{broken');
        self::assertResponseStatusCodeSame(400);

        $this->em()->clear();
        self::assertNull($this->entity(User::class, $author->getId())->getDashboardLayout());

        $this->arrange($token, $good);
        self::assertResponseIsSuccessful();
        self::assertSame($good, json_decode((string) $this->client->getResponse()->getContent(), true)['widgets']);
    }

    public function testTheArrangementNeedsALoginAndAToken(): void
    {
        $widgets = [['id' => 'drafts', 'size' => 3], ['id' => 'recent', 'size' => 3], ['id' => 'scheduled', 'size' => 6]];

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
        $this->arrange('none', $widgets);
        self::assertResponseRedirects('/login');

        $author = $this->loginAs(UserRole::Author);
        $this->arrange('forged', $widgets);
        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->entity(User::class, $author->getId())->getDashboardLayout());
    }

    private function token(): string
    {
        return $this->csrfToken('/admin', '[data-dashboard-csrf-value]', 'data-dashboard-csrf-value');
    }

    private function arrange(string $token, mixed $widgets): void
    {
        $this->client->request('POST', '/admin/dashboard/layout', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json(['widgets' => $widgets]));
    }

    /**
     * @return list<string> the widgets in their order, each with its width: "drafts:3"
     */
    private function layout(Crawler $crawler): array
    {
        return $crawler->filter('.widget')->each(static fn ($widget): string => $widget->attr('data-dnd-id').':'.$widget->attr('data-size'));
    }
}
