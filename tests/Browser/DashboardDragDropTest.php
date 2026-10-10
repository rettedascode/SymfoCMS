<?php

namespace App\Tests\Browser;

use App\Entity\User;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverKeys;

final class DashboardDragDropTest extends BrowserTestCase
{
    public function testWidgetsAreArrangedAndResizedAndTheLayoutIsKept(): void
    {
        $user = $this->logIn(UserRole::Editor);

        $this->browser->request('GET', '/admin');
        $this->browser->waitFor('.widget [data-dnd-handle]');
        self::assertSame(['recent:4', 'drafts:2', 'scheduled:3', 'submissions:3'], $this->layout());

        // Drag the last widget onto the first one: it takes its place.
        $this->drag($this->find('.widget:last-child [data-dnd-handle]'), $this->find('.widget:first-child'), 0.2);
        $this->browser->waitFor('.toast');
        self::assertSame('submissions', explode(':', $this->layout()[0])[0]);
        self::assertSame($this->layout(), $this->stored($user));

        // Keyboard: Space picks the first widget up, one step down, Space drops it.
        $this->press($this->find('.widget:first-child [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN);
        self::assertStringContainsString('Position 2 of 4 in Start page', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(700_000);
        self::assertSame(['recent:4', 'submissions:3', 'drafts:2', 'scheduled:3'], $this->layout());
        self::assertSame($this->layout(), $this->stored($user));

        // The right edge of a widget: the arrow keys step through the widths and say them.
        $edge = $this->find('.widget:first-child [role="separator"]');
        $this->press($edge, WebDriverKeys::ARROW_RIGHT);
        usleep(500_000);
        self::assertSame('Full width', $this->find('.widget:first-child [role="separator"]')->getAttribute('aria-valuetext'));
        self::assertStringContainsString('Full width', $this->announcement());
        self::assertSame('recent:6', $this->layout()[0]);
        self::assertSame($this->layout(), $this->stored($user));

        // It does not get wider than the page.
        $this->press($this->find('.widget:first-child [role="separator"]'), WebDriverKeys::ARROW_RIGHT);
        self::assertSame('recent:6', $this->layout()[0]);

        // "Undo" in the message takes the change of width back.
        $this->js('arguments[0].click()', $this->find('.toast [data-toast-action]'));
        usleep(700_000);
        self::assertSame('recent:4', $this->layout()[0]);
        self::assertSame($this->layout(), $this->stored($user));

        // The arrangement is still there after a reload.
        $this->browser->request('GET', '/admin');
        $this->browser->waitFor('.widget [data-dnd-handle]');
        self::assertSame(['recent:4', 'submissions:3', 'drafts:2', 'scheduled:3'], $this->layout());
        $this->assertNoJavaScriptErrors();
    }

    /**
     * @return list<string> the widgets in their order, each with its width: "drafts:3"
     */
    private function layout(): array
    {
        $layout = (array) $this->js('return [...document.querySelectorAll(".widget")].map((widget) => widget.dataset.dndId + ":" + widget.dataset.size)');

        return array_values(array_map(strval(...), $layout));
    }

    /**
     * @return list<string>
     */
    private function stored(User $user): array
    {
        $this->em()->clear();
        $stored = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $stored);

        return array_values(array_map(static fn (array $widget): string => $widget['id'].':'.$widget['size'], $stored->getDashboardLayout() ?? []));
    }
}
