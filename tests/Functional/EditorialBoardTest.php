<?php

namespace App\Tests\Functional;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use App\Workflow\ReviewWorkflow;

/**
 * The editorial board: which cards it shows to whom, and what the server does when a card is
 * dropped into another column. Dragging itself is covered by tests/Browser/EditorialBoardDragDropTest.
 */
final class EditorialBoardTest extends AppWebTestCase
{
    private ?string $token = null;

    public function testBoardShowsPostsAsCardsInTheColumnOfTheirStatus(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $draft = $this->createPost('Idea', $editor, status: ContentStatus::Draft, publishedAt: null);
        $review = $this->createPost('Waiting', $editor, status: ContentStatus::Review, publishedAt: null);
        $this->createPost('Out', $editor);
        $this->createPost('Next week', $editor, publishedAt: new \DateTimeImmutable('+7 days'));
        $this->createPost('Deutsch', $editor, locale: 'de');

        $crawler = $this->client->request('GET', '/admin/posts/board');
        self::assertResponseIsSuccessful();
        $titles = static fn (string $status): array => $crawler->filter('.board__list[data-status="'.$status.'"] .board-card__title')->each(static fn ($link): string => $link->text());
        self::assertSame(['Idea'], $titles('draft'));
        self::assertSame(['Waiting'], $titles('review'));
        self::assertSame(['Next week', 'Out'], $titles('published'), 'Scheduled and published posts share a column, the latest date first.');
        self::assertSame(['1', '1', '2'], $crawler->filter('.board__count')->each(static fn ($count): string => $count->text()));
        self::assertSame('Scheduled', trim($crawler->filter('.board-card')->reduce(static fn ($card): bool => 'Next week' === $card->filter('.board-card__title')->text())->filter('[data-board-badge]')->text()));

        // Every card names the columns it may be dropped into.
        self::assertSame('draft review published', $crawler->filter('[data-dnd-id="'.$draft->getId().'"]')->attr('data-allowed'));
        self::assertSame('draft review published', $crawler->filter('[data-dnd-id="'.$review->getId().'"]')->attr('data-allowed'));

        $board = $crawler->filter('[data-controller="board"]');
        self::assertSame('/admin/posts/board/move', $board->attr('data-board-url-value'));
        self::assertNotSame('', $board->attr('data-board-csrf-value'));
    }

    public function testAuthorsSeeTheirOwnPostsAndOnlyTheStepsTheyMayTake(): void
    {
        static::getContainer()->get(ReviewWorkflow::class)->requireReview(true);
        $other = $this->createUser(UserRole::Editor);
        $author = $this->loginAs(UserRole::Author);
        $mine = $this->createPost('Mine', $author, status: ContentStatus::Draft, publishedAt: null);
        $this->createPost('Not mine', $other, status: ContentStatus::Draft, publishedAt: null);

        $crawler = $this->client->request('GET', '/admin/posts/board');
        self::assertResponseIsSuccessful();
        self::assertSame(['Mine'], $crawler->filter('.board-card__title')->each(static fn ($link): string => $link->text()));
        self::assertSame('draft review', $crawler->filter('[data-dnd-id="'.$mine->getId().'"]')->attr('data-allowed'), 'With a required review an author cannot publish.');
    }

    public function testDroppingADraftIntoPublishedPublishesIt(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Idea', $editor, status: ContentStatus::Draft, publishedAt: null);

        $this->move($post, 'published');
        self::assertResponseIsSuccessful();
        $answer = $this->answer();
        self::assertSame(['published', 'published', 'Published'], [$answer['status'], $answer['state'], $answer['label']]);
        self::assertSame(['draft', 'published'], $answer['allowed']);

        $this->em()->clear();
        $stored = $this->entity(Post::class, $post->getId());
        self::assertSame(ContentStatus::Published, $stored->getStatus());
        self::assertNotNull($stored->getPublishedAt(), 'Publishing without a date means now.');
        $this->client->request('GET', '/blog/idea');
        self::assertResponseIsSuccessful();
    }

    public function testTakingAPublicPostOfflineNeedsConfirmation(): void
    {
        $editor = $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Out', $editor);
        $scheduled = $this->createPost('Later', $editor, publishedAt: new \DateTimeImmutable('+3 days'));

        $this->move($post, 'draft');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('“Out”', $this->answer()['confirm']);
        $this->em()->clear();
        self::assertSame(ContentStatus::Published, $this->entity(Post::class, $post->getId())->getStatus());

        $this->move($post, 'draft', confirmed: true);
        self::assertResponseIsSuccessful();
        self::assertSame('draft', $this->answer()['status']);
        $this->em()->clear();
        self::assertFalse($this->entity(Post::class, $post->getId())->isPublic());

        // Nobody can read a scheduled post yet, so there is nothing to ask.
        $this->move($scheduled, 'draft');
        self::assertResponseIsSuccessful();
        self::assertSame('draft', $this->answer()['status']);
    }

    public function testStepsTheWorkflowDoesNotAllowAreRefused(): void
    {
        static::getContainer()->get(ReviewWorkflow::class)->requireReview(true);
        $editor = $this->createUser(UserRole::Editor);
        $author = $this->loginAs(UserRole::Author);
        $mine = $this->createPost('Mine', $author, status: ContentStatus::Draft, publishedAt: null);
        $out = $this->createPost('Out', $author);
        $theirs = $this->createPost('Theirs', $editor, status: ContentStatus::Draft, publishedAt: null);

        // An author cannot publish while a review is required ...
        $this->move($mine, 'published');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('You cannot give this post the status “Published”.', $this->answer()['error']);

        // ... nor take a published post back, not even with the confirmation: what is out belongs to the editors ...
        $this->move($out, 'draft', confirmed: true);
        self::assertResponseStatusCodeSame(403);

        // ... nor touch the posts of others.
        $this->move($theirs, 'review');
        self::assertResponseStatusCodeSame(403);

        // Handing in is their step.
        $this->move($mine, 'review');
        self::assertResponseIsSuccessful();
        self::assertSame(['draft', 'review'], $this->answer()['allowed']);

        // A published post never goes straight to "in review", for nobody.
        $this->client->loginUser($this->entity(User::class, $editor->getId()));
        $this->token = null;
        $this->move($out, 'review', confirmed: true);
        self::assertResponseStatusCodeSame(422);

        // The editor approves what was handed in.
        $this->move($mine, 'published');
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame(ContentStatus::Published, $this->entity(Post::class, $mine->getId())->getStatus());
        self::assertSame(ContentStatus::Published, $this->entity(Post::class, $out->getId())->getStatus());
        self::assertSame(ContentStatus::Draft, $this->entity(Post::class, $theirs->getId())->getStatus());
    }

    public function testMalformedMovesAndMissingTokensAreRefused(): void
    {
        $post = $this->createPost('Idea', status: ContentStatus::Draft, publishedAt: null);
        $send = fn (array $body, string $token) => $this->client->request('POST', '/admin/posts/board/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $this->json($body));

        $send(['id' => $post->getId(), 'status' => 'published'], 'none');
        self::assertResponseRedirects('/login');

        $this->loginAs(UserRole::Editor);
        $send(['id' => $post->getId(), 'status' => 'published'], 'forged');
        self::assertResponseStatusCodeSame(403);

        $token = $this->csrfToken('/admin/posts/board', '[data-board-csrf-value]', 'data-board-csrf-value');
        $send(['id' => $post->getId(), 'status' => 'archived'], $token);
        self::assertResponseStatusCodeSame(400);
        $send(['id' => 'idea', 'status' => 'published'], $token);
        self::assertResponseStatusCodeSame(400);
        $send(['id' => 9999, 'status' => 'published'], $token);
        self::assertResponseStatusCodeSame(404);

        // The same status again changes nothing and is no error.
        $send(['id' => $post->getId(), 'status' => 'draft'], $token);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame(ContentStatus::Draft, $this->entity(Post::class, $post->getId())->getStatus());
    }

    private function move(Post $post, string $status, bool $confirmed = false): void
    {
        $this->token ??= $this->csrfToken('/admin/posts/board', '[data-board-csrf-value]', 'data-board-csrf-value');
        $this->client->request('POST', '/admin/posts/board/move', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->token], content: $this->json(['id' => $post->getId(), 'status' => $status, 'confirmed' => $confirmed]));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }
}
