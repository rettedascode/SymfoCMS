<?php

namespace App\Tests\Browser;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverKeys;

final class EditorialBoardDragDropTest extends BrowserTestCase
{
    public function testCardsChangeTheStatusOfTheirPostWhenTheyAreMoved(): void
    {
        $editor = $this->logIn(UserRole::Editor);
        $idea = (new Post())->setTitle('Idea')->setSlug('idea')->setStatus(ContentStatus::Draft)->setAuthor($editor);
        $out = (new Post())->setTitle('Out')->setSlug('out')->setStatus(ContentStatus::Published)->setPublishedAt(new \DateTimeImmutable('-1 day'))->setAuthor($editor);
        $this->em()->persist($idea);
        $this->em()->persist($out);
        $this->em()->flush();

        $this->browser->request('GET', '/admin/posts/board');
        $this->browser->waitFor('.board-card [data-dnd-handle]');
        self::assertEquals(['draft' => ['Idea'], 'review' => [], 'published' => ['Out']], $this->board());

        // Drag the draft into "Published": it is published at once.
        $this->drag($this->find('[data-dnd-id="'.$idea->getId().'"] [data-dnd-handle]'), $this->find('.board__list[data-status="published"]'), 0.2);
        $this->browser->waitFor('.toast');
        self::assertStringContainsString('The post is now: Published.', $this->find('.toast')->getText());
        self::assertSame(ContentStatus::Published, $this->storedStatus($idea));
        self::assertSame(['0', '0', '2'], array_map(static fn ($count): string => $count->getText(), $this->findAll('.board__count')));

        // "Undo" makes it a draft again, without a question.
        $this->js('arguments[0].click()', $this->find('.toast [data-toast-action]'));
        usleep(700_000);
        self::assertSame(ContentStatus::Draft, $this->storedStatus($idea));
        self::assertEquals(['draft' => ['Idea'], 'review' => [], 'published' => ['Out']], $this->board());

        // Keyboard: the published post can only go to "Draft" - the arrow key passes "In review" by.
        $this->press($this->find('[data-dnd-id="'.$out->getId().'"] [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_UP);
        self::assertStringContainsString('in Draft', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);

        // Visitors can read it, so the board asks first.
        $this->browser->waitFor('dialog.confirm-dialog[open]');
        self::assertStringContainsString('“Out”', $this->find('dialog.confirm-dialog')->getText());
        self::assertSame(ContentStatus::Published, $this->storedStatus($out));
        $this->js('arguments[0].click()', $this->find('dialog.confirm-dialog [data-confirm="no"]'));
        usleep(400_000);
        self::assertEquals(['draft' => ['Idea'], 'review' => [], 'published' => ['Out']], $this->board());

        // Again, and this time agreed.
        $this->press($this->find('[data-dnd-id="'.$out->getId().'"] [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_UP, WebDriverKeys::SPACE);
        $this->browser->waitFor('dialog.confirm-dialog[open]');
        $this->js('arguments[0].click()', $this->find('dialog.confirm-dialog [data-confirm="yes"]'));
        $this->browser->waitFor('.toast');
        self::assertSame(ContentStatus::Draft, $this->storedStatus($out));
        self::assertSame('Draft', $this->find('[data-dnd-id="'.$out->getId().'"] [data-board-badge]')->getText());
    }

    /**
     * @return array<string, list<string>> the titles of the cards per column
     */
    private function board(): array
    {
        /** @var array<string, list<string>> $board */
        $board = $this->js('return Object.fromEntries([...document.querySelectorAll(".board__list")].map((list) => [list.dataset.status, [...list.querySelectorAll(".board-card__title")].map((title) => title.textContent)]))');

        return $board;
    }

    private function storedStatus(Post $post): ContentStatus
    {
        $this->em()->clear();
        $stored = $this->em()->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        return $stored->getStatus();
    }
}
