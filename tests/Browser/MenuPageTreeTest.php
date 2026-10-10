<?php

namespace App\Tests\Browser;

use App\Entity\MenuItem;
use App\Enum\ContentStatus;
use App\Enum\MenuLocation;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

final class MenuPageTreeTest extends BrowserTestCase
{
    public function testPagesAreDraggedFromThePageTreeIntoTheMenu(): void
    {
        $this->logIn();
        $about = $this->createPage('About', status: ContentStatus::Published);
        $team = $this->createPage('Team', status: ContentStatus::Published);
        $team->setParent($about);
        $this->createPage('Contact', status: ContentStatus::Published);
        $this->em()->flush();

        $this->browser->request('GET', '/admin/menus');
        $this->browser->waitFor('.menu-pages__button');
        self::assertSame(['About', '>Team', 'Contact'], $this->pageTree());
        self::assertSame('', $this->menu());

        // The first page is dragged into the empty menu.
        $this->drag($this->entry('About'), $this->find('[data-menu-target="list"] > .menu-list'));
        self::assertSame('About', $this->menu());
        self::assertCount(3, $this->findAll('.menu-pages__item'), 'The page tree keeps its pages.');

        // A child page is dropped into the sub-items of that entry.
        $this->drag($this->entry('Team'), $this->find('.menu-item > .menu-list'));
        self::assertSame('About(Team)', $this->menu());

        // Enter on a page adds it at the end; that is announced.
        $this->press($this->entry('Contact'), WebDriverKeys::ENTER);
        self::assertSame('About(Team),Contact', $this->menu());
        self::assertStringContainsString('“Contact” was added at the end of the menu.', $this->announcement());

        // Ctrl+Z takes the last page out again, Ctrl+Shift+Z puts it back.
        $this->js('document.activeElement.blur()');
        $this->shortcut(WebDriverKeys::CONTROL, 'z');
        self::assertSame('About(Team)', $this->menu());
        $this->shortcut(WebDriverKeys::CONTROL, WebDriverKeys::SHIFT, 'z');
        self::assertSame('About(Team),Contact', $this->menu());

        $this->js('arguments[0].click()', $this->find('[data-menu-target="saveButton"]'));
        $this->browser->waitForElementToContain('[data-menu-target="status"]', 'Menu saved.');

        $this->em()->clear();
        $items = $this->em()->getRepository(MenuItem::class)->findBy(['location' => MenuLocation::Main, 'parent' => null], ['position' => 'ASC']);
        self::assertSame(['About', 'Contact'], array_map(static fn (MenuItem $item): string => $item->getLabel(), $items));
        self::assertSame($about->getId(), $items[0]->getPage()?->getId());
        $child = $items[0]->getChildren()->first();
        self::assertInstanceOf(MenuItem::class, $child);
        self::assertSame($team->getId(), $child->getPage()?->getId());
        $this->assertNoJavaScriptErrors();
    }

    private function entry(string $title): WebDriverElement
    {
        $this->js('document.querySelectorAll("[data-test-entry]").forEach((button) => button.removeAttribute("data-test-entry")); [...document.querySelectorAll(".menu-pages__button")].find((button) => button.textContent.trim() === arguments[0])?.setAttribute("data-test-entry", "")', $title);

        return $this->find('.menu-pages__button[data-test-entry]');
    }

    /**
     * @return list<string> the pages of the tree, child pages marked with ">"
     */
    private function pageTree(): array
    {
        $pages = (array) $this->js('return [...document.querySelectorAll(".menu-pages__item")].map((item) => ">".repeat(Number(item.style.getPropertyValue("--depth"))) + item.textContent.trim())');

        return array_values(array_map(strval(...), $pages));
    }

    /**
     * The menu as text, e.g. "About(Team),Contact".
     */
    private function menu(): string
    {
        return (string) $this->js(<<<'JS'
            const read = (list) => [...list.children].filter((child) => child.matches('.menu-item')).map((item) => {
                const label = item.querySelector(':scope > .menu-item__row .menu-item__label').value;
                const children = item.querySelector(':scope > .menu-list');
                const below = children ? read(children) : [];
                return below.length ? `${label}(${below.join(',')})` : label;
            });
            return read(document.querySelector('[data-menu-target=list] > .menu-list')).join(',');
            JS);
    }
}
