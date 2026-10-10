<?php

namespace App\Tests\Browser;

use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

final class PageTreeDragDropTest extends BrowserTestCase
{
    public function testAPublishedPageIsDraggedBelowAnotherOneAfterConfirming(): void
    {
        $this->logIn(UserRole::Editor);
        [$about, $team, $contact] = $this->createPages();

        $this->browser->request('GET', '/admin/pages');
        $this->browser->waitFor('.page-tree [data-dnd-handle]');
        self::assertSame('About(Team),Contact,Notes', $this->tree());

        // Drag "Contact" into the child pages of "About".
        $this->drag($this->handleOf($contact), $this->childListOf($about));

        // Its address would change, so nothing is stored before the person agrees.
        $this->browser->waitFor('dialog.confirm-dialog[open]');
        $dialog = $this->find('dialog.confirm-dialog')->getText();
        self::assertStringContainsString('/contact', $dialog);
        self::assertStringContainsString('/about/contact', $dialog);
        self::assertNull($this->stored($contact)->getParent());

        $this->js('arguments[0].click()', $this->find('dialog.confirm-dialog [data-confirm="yes"]'));
        $this->browser->waitFor('.toast');
        self::assertStringContainsString('redirects', $this->find('.toast')->getText());
        self::assertSame($about->getId(), $this->stored($contact)->getParent()?->getId());
        self::assertSame('/about/contact', $this->find('[data-page-path="'.$contact->getId().'"]')->getText());
        self::assertStringContainsString('Contact', $this->tree());
        self::assertMatchesRegularExpression('/^About\((Contact,Team|Team,Contact)\),Notes$/', $this->tree());

        // "Undo" in the toast puts it back on the top level.
        $this->js('arguments[0].click()', $this->find('.toast [data-toast-action]'));
        $this->browser->waitForElementToNotContain('[data-page-path="'.$contact->getId().'"]', '/about');
        self::assertSame('About(Team),Contact,Notes', $this->tree());
        self::assertNull($this->stored($contact)->getParent());
        self::assertSame('/contact', $this->find('[data-page-path="'.$contact->getId().'"]')->getText());
        self::assertSame($about->getId(), $this->stored($team)->getParent()?->getId());
        $this->assertNoJavaScriptErrors();
    }

    public function testPagesMoveWithTheKeyboardAndCollapsedBranchesAreRemembered(): void
    {
        $this->logIn(UserRole::Editor);
        [$about, , $contact, $notes] = $this->createPages();

        $this->browser->request('GET', '/admin/pages');
        $this->browser->waitFor('.page-tree [data-dnd-handle]');

        // Space picks "Contact" up, the arrow leads into the child pages of "About", Space drops it there.
        $this->press($this->handleOf($contact), WebDriverKeys::SPACE);
        self::assertStringContainsString('Contact picked up', $this->announcement());
        $this->carryInto('Child pages of “About”');
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);

        // A published page: the question comes, and "Leave it where it was" changes nothing.
        $this->browser->waitFor('dialog.confirm-dialog[open]');
        $this->js('arguments[0].click()', $this->find('dialog.confirm-dialog [data-confirm="no"]'));
        usleep(350_000);
        self::assertSame('About(Team),Contact,Notes', $this->tree());
        self::assertNull($this->stored($contact)->getParent());

        // Escape gives up a move that was started.
        $this->press($this->handleOf($notes), WebDriverKeys::SPACE, WebDriverKeys::ARROW_UP, WebDriverKeys::ESCAPE);
        self::assertSame('About(Team),Contact,Notes', $this->tree());

        // A draft has no public address, so it moves without a question.
        $this->press($this->handleOf($notes), WebDriverKeys::SPACE);
        $this->carryInto('Child pages of “About”');
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        $this->browser->waitForElementToContain('[data-page-path="'.$notes->getId().'"]', '/about/notes');
        self::assertSame('The page was moved.', trim(str_replace('Undo', '', $this->find('.toast')->getText())));
        self::assertSame($about->getId(), $this->stored($notes)->getParent()?->getId());
        self::assertSame('/about/notes', $this->find('[data-page-path="'.$notes->getId().'"]')->getText());

        // Collapsing a branch survives a reload.
        $toggle = '[data-dnd-id="'.$about->getId().'"] > .page-tree__row .page-tree__toggle';
        $this->js('arguments[0].click()', $this->find($toggle));
        self::assertSame('false', $this->find($toggle)->getAttribute('aria-expanded'));
        $this->browser->request('GET', '/admin/pages');
        $this->browser->waitFor('.page-tree [data-dnd-handle]');
        usleep(200_000);
        self::assertStringContainsString('is-collapsed', (string) $this->find('[data-dnd-id="'.$about->getId().'"]')->getAttribute('class'));
        self::assertSame('false', $this->find($toggle)->getAttribute('aria-expanded'));
        $this->assertNoJavaScriptErrors();
    }

    /**
     * "About" with the child page "Team", next to it "Contact" (published) and "Notes" (a draft).
     *
     * @return array{Page, Page, Page, Page}
     */
    private function createPages(): array
    {
        $about = $this->createPage('About', status: ContentStatus::Published)->setPosition(0);
        $team = $this->createPage('Team', status: ContentStatus::Published);
        $team->setParent($about);
        $contact = $this->createPage('Contact', status: ContentStatus::Published)->setPosition(1);
        $notes = $this->createPage('Notes')->setPosition(2);
        $this->em()->flush();

        return [$about, $team, $contact, $notes];
    }

    /**
     * Presses the up arrow until the picked-up page is offered a place in the named list.
     */
    private function carryInto(string $list): void
    {
        for ($step = 0; $step < 8 && !str_contains($this->announcement(), $list); ++$step) {
            $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
            usleep(120_000);
        }

        self::assertStringContainsString($list, $this->announcement());
    }

    private function stored(Page $page): Page
    {
        $this->em()->clear();
        $stored = $this->em()->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $stored);

        return $stored;
    }

    private function handleOf(Page $page): WebDriverElement
    {
        return $this->find('[data-dnd-id="'.$page->getId().'"] > .page-tree__row [data-dnd-handle]');
    }

    private function childListOf(Page $page): WebDriverElement
    {
        return $this->find('ul[data-tree-parent="'.$page->getId().'"]');
    }

    /**
     * The tree as text, e.g. "About(Team),Contact".
     */
    private function tree(): string
    {
        return (string) $this->js(<<<'JS'
            const read = (list) => [...list.children].filter((child) => child.matches('[data-dnd-item]')).map((item) => {
                const children = [...item.children].find((child) => child.matches('ul'));
                const below = children ? read(children) : [];
                const title = item.querySelector('.page-tree__title').textContent;
                return below.length ? `${title}(${below.join(',')})` : title;
            });
            return read(document.querySelector('.page-tree')).join(',');
            JS);
    }
}
