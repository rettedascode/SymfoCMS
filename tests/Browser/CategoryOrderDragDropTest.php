<?php

namespace App\Tests\Browser;

use App\Entity\Category;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverKeys;

final class CategoryOrderDragDropTest extends BrowserTestCase
{
    public function testCategoriesAreArrangedByDraggingAndWithTheKeyboard(): void
    {
        $this->logIn(UserRole::Editor);
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->em()->persist((new Category())->setLocale('en')->setName($name)->setSlug(strtolower($name)));
        }
        $this->em()->flush();

        $this->browser->request('GET', '/admin/categories');
        $this->browser->waitFor('.sort-list [data-dnd-handle]');
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->shown());

        // Drag the first category onto the last one: it is stored at once.
        $this->drag($this->find('.sort-list__item:nth-child(1) [data-dnd-handle]'), $this->find('.sort-list__item:nth-child(3)'), 0.8);
        $this->browser->waitFor('.toast');
        self::assertSame(['Beta', 'Gamma', 'Alpha'], $this->shown());
        self::assertSame(['Beta', 'Gamma', 'Alpha'], $this->stored());

        // "Undo" in the message puts it back, on the server too.
        $this->js('arguments[0].click()', $this->find('.toast [data-toast-action]'));
        usleep(600_000);
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->shown());
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->stored());

        // Keyboard: Space picks the last one up, two steps up, Space drops it.
        $this->press($this->find('.sort-list__item:nth-child(3) [data-dnd-handle]'), WebDriverKeys::SPACE);
        self::assertStringContainsString('Gamma picked up. Position 3 of 3 in Categories', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(600_000);
        self::assertSame(['Gamma', 'Alpha', 'Beta'], $this->shown());
        self::assertSame(['Gamma', 'Alpha', 'Beta'], $this->stored());

        // Escape gives up a move that was started.
        $this->press($this->find('.sort-list__item:nth-child(1) [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ESCAPE);
        self::assertSame(['Gamma', 'Alpha', 'Beta'], $this->shown());
        $this->assertNoJavaScriptErrors();
    }

    /**
     * @return list<string>
     */
    private function shown(): array
    {
        $names = (array) $this->js('return [...document.querySelectorAll(".sort-list__title")].map((link) => link.textContent)');

        return array_values(array_map(strval(...), $names));
    }

    /**
     * @return list<string>
     */
    private function stored(): array
    {
        $this->em()->clear();

        return array_map(static fn (Category $category): string => $category->getName(), $this->em()->getRepository(Category::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']));
    }
}
