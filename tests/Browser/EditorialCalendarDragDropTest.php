<?php

namespace App\Tests\Browser;

use App\Entity\Post;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverKeys;

final class EditorialCalendarDragDropTest extends BrowserTestCase
{
    public function testPostsMoveToAnotherDayByDraggingAndWithTheKeyboard(): void
    {
        $editor = $this->logIn(UserRole::Editor);
        $post = (new Post())->setTitle('Out')->setSlug('out')->setStatus(ContentStatus::Published)->setPublishedAt(new \DateTimeImmutable('2026-03-10 08:30'))->setAuthor($editor);
        $this->em()->persist($post);
        $this->em()->flush();

        $this->browser->request('GET', '/admin/posts/calendar?month=2026-03');
        $this->browser->waitFor('.calendar-post [data-dnd-handle]');
        self::assertSame('2026-03-10', $this->shownOn());

        // Drag the post two days on: the date changes at once, the time stays.
        $this->drag($this->find('.calendar-post [data-dnd-handle]'), $this->find('.calendar__list[data-date="2026-03-12"]'));
        $this->browser->waitFor('.toast');
        self::assertStringContainsString('Moved to March 12, 2026.', $this->find('.toast')->getText());
        self::assertSame('2026-03-12', $this->shownOn());
        self::assertSame('2026-03-12 08:30', $this->storedDate($post));

        // "Undo" puts it back on its day.
        $this->js('arguments[0].click()', $this->find('.toast [data-toast-action]'));
        usleep(700_000);
        self::assertSame('2026-03-10', $this->shownOn());
        self::assertSame('2026-03-10 08:30', $this->storedDate($post));

        // Keyboard: Space picks the post up, every step of the arrow key is one day, Space drops it.
        $this->press($this->find('.calendar-post [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN);
        self::assertStringContainsString('in March 11, 2026', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        usleep(200_000);
        self::assertStringContainsString('in March 9, 2026', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        $this->browser->waitFor('.toast');
        usleep(300_000);
        self::assertSame('2026-03-09', $this->shownOn());
        self::assertSame('2026-03-09 08:30', $this->storedDate($post));

        // Escape gives up a move that was started.
        $this->press($this->find('.calendar-post [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ESCAPE);
        self::assertSame('2026-03-09', $this->shownOn());
        self::assertSame('2026-03-09 08:30', $this->storedDate($post));
        $this->assertNoJavaScriptErrors();
    }

    private function shownOn(): string
    {
        return (string) $this->js('return document.querySelector(".calendar-post").closest(".calendar__list").dataset.date');
    }

    private function storedDate(Post $post): string
    {
        $this->em()->clear();
        $stored = $this->em()->find(Post::class, $post->getId());
        self::assertInstanceOf(Post::class, $stored);

        return (string) $stored->getPublishedAt()?->format('Y-m-d H:i');
    }
}
