<?php

namespace App\Tests\Functional;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use App\Workflow\ReviewWorkflow;

/**
 * The editorial calendar: which posts stand on which day, and what the server does when a post
 * is dropped on another day. Dragging itself is covered by tests/Browser/EditorialCalendarDragDropTest.
 */
final class EditorialCalendarTest extends AppWebTestCase
{
    private ?string $token = null;

    public function testCalendarShowsAMonthWithEveryPostOnItsDay(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $this->createPost('Out', $editor, publishedAt: new \DateTimeImmutable('2026-03-10 08:30'));
        $this->createPost('Planned draft', $editor, status: ContentStatus::Draft, publishedAt: new \DateTimeImmutable('2026-03-10 14:00'));
        $this->createPost('Leading days', $editor, publishedAt: new \DateTimeImmutable('2026-02-24 10:00'));
        $this->createPost('Another month', $editor, publishedAt: new \DateTimeImmutable('2026-05-02 10:00'));
        $this->createPost('No date', $editor, status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Deutsch', $editor, publishedAt: new \DateTimeImmutable('2026-03-11 10:00'), locale: 'de');

        $crawler = $this->client->request('GET', '/admin/posts/calendar?month=2026-03');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.calendar__month', 'March 2026');

        // Whole weeks from Monday to Sunday: 23 February to 5 April.
        $days = $crawler->filter('.calendar__list')->each(static fn ($day): string => (string) $day->attr('data-date'));
        self::assertCount(42, $days);
        self::assertSame(['2026-02-23', '2026-04-05'], [$days[0], $days[41]]);
        self::assertSame('March 10, 2026', $crawler->filter('.calendar__list[data-date="2026-03-10"]')->attr('aria-label'));

        $on = static fn (string $date): array => $crawler->filter('.calendar__list[data-date="'.$date.'"] .calendar-post__title')->each(static fn ($link): string => $link->text());
        self::assertSame(['Out', 'Planned draft'], $on('2026-03-10'), 'The posts of a day stand in the order of their time.');
        self::assertSame(['Leading days'], $on('2026-02-24'));
        self::assertCount(3, $crawler->filter('.calendar-post'), 'Posts of other months, other languages and without a date are not on the sheet.');
        self::assertCount(3, $crawler->filter('.calendar-post[data-dnd-item] > [data-dnd-handle]'));

        // The neighbouring months are one click away, and the language tabs keep the month.
        self::assertSame('/admin/posts/calendar?month=2026-02', $crawler->filter('.calendar__nav a[rel="prev"]')->attr('href'));
        self::assertSame('/admin/posts/calendar?month=2026-04', $crawler->filter('.calendar__nav a[rel="next"]')->attr('href'));

        // Without a month, or with nonsense, the calendar shows the current one.
        $this->client->request('GET', '/admin/posts/calendar?month=soon');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.calendar__day--today');
    }

    public function testDroppingAPostOnAnotherDayChangesTheDateAndKeepsTheTime(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Out', $editor, publishedAt: new \DateTimeImmutable('-10 days 08:30'));
        $target = (new \DateTimeImmutable('-3 days'))->format('Y-m-d');

        $this->move($post, $target);
        self::assertResponseIsSuccessful();
        $answer = $this->answer();
        self::assertSame([$target, '08:30', 'published'], [$answer['date'], $answer['time'], $answer['state']]);
        self::assertStringStartsWith('Moved to ', $answer['message']);

        $this->em()->clear();
        self::assertSame($target.' 08:30', $this->entity(Post::class, $post->getId())->getPublishedAt()?->format('Y-m-d H:i'));
    }

    public function testMovingAPublicPostIntoTheFutureNeedsConfirmation(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Out', $editor, publishedAt: new \DateTimeImmutable('-2 days 08:30'));
        $future = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

        $this->move($post, $future);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('“Out”', $this->answer()['confirm']);
        $this->em()->clear();
        self::assertTrue($this->entity(Post::class, $post->getId())->isPublic(), 'Nothing happens before the question is answered.');

        $this->move($post, $future, confirmed: true);
        self::assertResponseIsSuccessful();
        $answer = $this->answer();
        self::assertSame(['scheduled', 'Scheduled'], [$answer['state'], $answer['label']]);
        self::assertStringContainsString('off the website until then', $answer['message']);
        $this->em()->clear();
        self::assertTrue($this->entity(Post::class, $post->getId())->isScheduled());

        // Back into the past it is public again, and the message says so. There is nothing to ask.
        $this->move($post, (new \DateTimeImmutable('-1 day'))->format('Y-m-d'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Visitors can read the post now.', $this->answer()['message']);
    }

    public function testDraftsArePlannedWithoutBecomingPublic(): void
    {
        $author = $this->loginAs(UserRole::Author);
        $draft = $this->createPost('Idea', $author, status: ContentStatus::Draft, publishedAt: new \DateTimeImmutable('+2 days 10:00'));

        $this->move($draft, (new \DateTimeImmutable('-1 day'))->format('Y-m-d'));
        self::assertResponseIsSuccessful();
        self::assertSame('draft', $this->answer()['state']);
        $this->em()->clear();
        self::assertFalse($this->entity(Post::class, $draft->getId())->isPublic());
    }

    public function testOnlyThoseWhoMayPublishMovePublishedPosts(): void
    {
        static::getContainer()->get(ReviewWorkflow::class)->requireReview(true);
        $editor = $this->createUser(UserRole::Editor);
        $author = $this->loginAs(UserRole::Author);
        $mine = $this->createPost('Mine', $author, publishedAt: new \DateTimeImmutable('2026-03-10 08:30'));
        $draft = $this->createPost('My draft', $author, status: ContentStatus::Draft, publishedAt: new \DateTimeImmutable('2026-03-12 08:30'));
        $theirs = $this->createPost('Theirs', $editor, status: ContentStatus::Draft, publishedAt: new \DateTimeImmutable('2026-03-11 08:30'));

        // The author sees their own posts; the published one has no handle.
        $crawler = $this->client->request('GET', '/admin/posts/calendar?month=2026-03');
        self::assertSame(['Mine', 'My draft'], $crawler->filter('.calendar-post__title')->each(static fn ($link): string => $link->text()));
        self::assertCount(0, $crawler->filter('[data-dnd-id="'.$mine->getId().'"] [data-dnd-handle]'));
        self::assertCount(1, $crawler->filter('[data-dnd-id="'.$draft->getId().'"][data-dnd-item] [data-dnd-handle]'));

        $this->move($mine, '2026-03-20');
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 422]);
        $this->move($theirs, '2026-03-20');
        self::assertResponseStatusCodeSame(403);
        $this->move($draft, '2026-03-20');
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame('2026-03-10', $this->entity(Post::class, $mine->getId())->getPublishedAt()?->format('Y-m-d'));
        self::assertSame('2026-03-11', $this->entity(Post::class, $theirs->getId())->getPublishedAt()?->format('Y-m-d'));
        self::assertSame('2026-03-20', $this->entity(Post::class, $draft->getId())->getPublishedAt()?->format('Y-m-d'));
    }

    public function testMalformedMovesAndMissingTokensAreRefused(): void
    {
        $post = $this->createPost('Out', publishedAt: new \DateTimeImmutable('2026-03-10 08:30'));
        $send = fn (array $body, string $token) => $this->client->request('POST', '/admin/posts/calendar/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json($body));

        $send(['id' => $post->getId(), 'date' => '2026-03-12'], 'none');
        self::assertResponseRedirects('/login');

        $this->loginAs(UserRole::Editor);
        $send(['id' => $post->getId(), 'date' => '2026-03-12'], 'forged');
        self::assertResponseStatusCodeSame(403);

        $token = $this->csrfToken('/admin/posts/calendar', '[data-calendar-csrf-value]', 'data-calendar-csrf-value');
        foreach (['2026-13-40', '2026-02-30', '12.03.2026', 'tomorrow', '', 20260312] as $date) {
            $send(['id' => $post->getId(), 'date' => $date], $token);
            self::assertResponseStatusCodeSame(400, (string) $date);
        }
        $send(['date' => '2026-03-12'], $token);
        self::assertResponseStatusCodeSame(400);
        $send(['id' => 9999, 'date' => '2026-03-12'], $token);
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        self::assertSame('2026-03-10 08:30', $this->entity(Post::class, $post->getId())->getPublishedAt()?->format('Y-m-d H:i'));
    }

    private function move(Post $post, string $date, bool $confirmed = false): void
    {
        $this->token ??= $this->csrfToken('/admin/posts/calendar', '[data-calendar-csrf-value]', 'data-calendar-csrf-value');
        $this->client->request('POST', '/admin/posts/calendar/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], content: $this->json(['id' => $post->getId(), 'date' => $date, 'confirmed' => $confirmed]));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }
}
