<?php

namespace App\Tests\Browser;

use App\Entity\Page;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

final class EditorColumnsTest extends BrowserTestCase
{
    private const LIST = '[data-editor-target="list"] > .blocks__items';

    public function testBlocksDroppedOnAnEdgeBecomeColumnsWithAdjustableWidths(): void
    {
        $this->logIn();
        $page = $this->createPage('Side by side', [
            ['type' => 'heading', 'data' => ['text' => 'One', 'level' => '2']],
            ['type' => 'quote', 'data' => ['text' => 'Two', 'author' => '']],
            ['type' => 'divider', 'data' => []],
        ]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.palette__button');
        self::assertSame('Heading,Quote,Divider', $this->structure());

        // The quote goes to the right edge of the heading: two columns.
        $this->dragToEdge($this->handleOf(2), $this->block(1), 'right');
        self::assertSame('Columns[Heading|Quote],Divider', $this->structure());

        // The divider goes to the left edge of the columns: a third one. More are not offered.
        $this->dragToEdge($this->handleOf(2), $this->block(1), 'left');
        self::assertSame('Columns[Divider|Heading|Quote]', $this->structure());
        self::assertCount(0, $this->findAll(self::LIST.' > .block > .block__edge'));

        // The divider between the first two columns: one step to the right widens the left one.
        $dividers = $this->findAll('.columns__divider');
        self::assertCount(2, $dividers);
        self::assertSame('33 % and 33 %', $dividers[0]->getAttribute('aria-valuetext'));
        $this->press($dividers[0], WebDriverKeys::ARROW_RIGHT);
        self::assertSame('42 % and 25 %', $this->find('.columns__divider')->getAttribute('aria-valuetext'));
        self::assertStringContainsString('42 % and 25 %', $this->announcement());

        // No column gets narrower than a quarter, however often the key is pressed.
        $this->press($this->find('.columns__divider'), WebDriverKeys::ARROW_RIGHT, WebDriverKeys::ARROW_RIGHT);
        self::assertSame('42 % and 25 %', $this->find('.columns__divider')->getAttribute('aria-valuetext'));

        $this->js('arguments[0].click()', $this->find('.editor__bar button[type="submit"]'));
        $this->browser->waitFor('.flash--success');
        $this->em()->clear();
        $stored = $this->em()->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $stored);
        $data = $stored->getBlocks()[0]['data'];
        self::assertSame(['divider', 'heading', 'quote'], [$data['left'][0]['type'], $data['middle'][0]['type'], $data['right'][0]['type']]);
        self::assertSame('5-3-4', $data['widths']);
        $this->assertNoJavaScriptErrors();
    }

    public function testColumnsAreMadeAndDissolvedWithTheKeyboardAndTheOutlineMovesBlocks(): void
    {
        $this->logIn();
        $page = $this->createPage('Keyboard columns', [
            ['type' => 'heading', 'data' => ['text' => 'One', 'level' => '2']],
            ['type' => 'quote', 'data' => ['text' => 'Two', 'author' => '']],
        ]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.palette__button');

        // Space picks the quote up, the right arrow leads to the right edge of the heading above it.
        $this->press($this->handleOf(2), WebDriverKeys::SPACE, WebDriverKeys::ARROW_RIGHT);
        self::assertStringContainsString('Right of “Heading”: new column', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_LEFT);
        usleep(150_000);
        self::assertStringContainsString('Left of “Heading”: new column', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(400_000);
        self::assertSame('Columns[Quote|Heading]', $this->structure());
        self::assertTrue($this->js('return document.activeElement.matches(".columns .block [data-dnd-handle]")'), 'The focus stays on the handle of the moved block.');

        // Ctrl+Z takes the columns apart, Ctrl+Shift+Z makes them again.
        $this->js('document.activeElement.blur()');
        $this->shortcut(WebDriverKeys::CONTROL, 'z');
        self::assertSame('Heading,Quote', $this->structure());
        $this->shortcut(WebDriverKeys::CONTROL, WebDriverKeys::SHIFT, 'z');
        self::assertSame('Columns[Quote|Heading]', $this->structure());

        // Carrying the quote out of its column leaves a single column: the columns dissolve.
        $this->press($this->find('.columns .block [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_UP, WebDriverKeys::SPACE);
        usleep(300_000);
        self::assertSame('Quote,Heading', $this->structure());

        // The outline lists the blocks; moving a line there moves the block.
        $this->js('arguments[0].click()', $this->find('.outline__summary'));
        self::assertSame(['Quote', 'Heading'], $this->outline());
        self::assertSame('Two', $this->find('.outline__item:first-child .outline__excerpt')->getText());
        $this->press($this->find('.outline__item:nth-child(2) [data-dnd-handle]'), WebDriverKeys::SPACE, WebDriverKeys::ARROW_UP, WebDriverKeys::SPACE);
        usleep(300_000);
        self::assertSame(['Heading', 'Quote'], $this->outline());
        self::assertSame('Heading,Quote', $this->structure());
        self::assertTrue($this->js('return document.activeElement.closest(".outline") !== null'), 'The focus stays in the outline.');

        // A click on a line leads to the block.
        $this->js('arguments[0].click()', $this->find('.outline__item:nth-child(2) .outline__jump'));
        self::assertTrue($this->js('return document.activeElement.closest(".block")?.querySelector(".block__type").textContent === "Quote"'));
        $this->assertNoJavaScriptErrors();
    }

    private function block(int $number): WebDriverElement
    {
        return $this->find(self::LIST.' > .block:nth-child('.$number.')');
    }

    private function handleOf(int $number): WebDriverElement
    {
        return $this->find(self::LIST.' > .block:nth-child('.$number.') > .block__header [data-dnd-handle]');
    }

    /**
     * The blocks of the page as text, e.g. "Columns[Heading|Quote],Divider".
     */
    private function structure(): string
    {
        return (string) $this->js(<<<'JS'
            return [...document.querySelectorAll(arguments[0] + ' > .block')].map((node) => {
                const name = node.querySelector(':scope > .block__header .block__type').textContent;
                const columns = [...node.querySelectorAll(':scope > .block__body > .columns > .field--blocks')];

                return columns.length ? `${name}[${columns.map((column) => [...column.querySelectorAll('.block .block__type')].map((type) => type.textContent).join('+')).join('|')}]` : name;
            }).join(',');
            JS, self::LIST);
    }

    /**
     * @return list<string>
     */
    private function outline(): array
    {
        $names = (array) $this->js('return [...document.querySelectorAll("[data-editor-target=outlineBody] > .outline__list > .outline__item > .outline__row .outline__name")].map((name) => name.textContent)');

        return array_values(array_map(strval(...), $names));
    }
}
