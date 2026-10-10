<?php

namespace App\Tests\Browser;

use App\Entity\Media;
use App\Entity\Page;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

final class PageEditorDragDropTest extends BrowserTestCase
{
    private const LIST = '[data-editor-target="list"] > .blocks__items';

    public function testBlocksAreDraggedFromThePaletteAndReordered(): void
    {
        $this->logIn();
        $page = $this->createPage('Drag me', [
            ['type' => 'heading', 'data' => ['text' => 'First', 'level' => '2']],
            ['type' => 'text', 'data' => ['content' => '<p>Second</p>']],
            ['type' => 'divider', 'data' => []],
        ]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.palette__button');
        self::assertSame(['Heading', 'Text', 'Divider'], $this->blockTypes());
        self::assertGreaterThan(8, \count($this->findAll('.palette__button')), 'The palette offers the block types.');

        // Drag "Quote" out of the palette, onto the upper half of the second block.
        $this->drag($this->paletteEntry('Quote'), $this->find(self::LIST.' > .block:nth-child(2)'), 0.15);
        self::assertSame(['Heading', 'Quote', 'Text', 'Divider'], $this->blockTypes());
        self::assertCount(\count($this->findAll('.palette__button')), $this->findAll('.palette__item'), 'The palette keeps its entries.');

        // A click adds at the end.
        $this->js('arguments[0].click()', $this->paletteEntry('Button'));
        usleep(300_000);
        self::assertSame(['Heading', 'Quote', 'Text', 'Divider', 'Button'], $this->blockTypes());

        // Drag the first block below the third by its handle.
        $this->drag($this->find(self::LIST.' > .block:nth-child(1) [data-dnd-handle]'), $this->find(self::LIST.' > .block:nth-child(3)'), 0.9);
        // Where exactly it lands depends on how the list shifts under the pointer; it has moved down, and nothing got lost.
        $order = $this->blockTypes();
        self::assertSame('Quote', $order[0]);
        self::assertGreaterThan(1, array_search('Heading', $order, true));
        self::assertEqualsCanonicalizing(['Heading', 'Quote', 'Text', 'Divider', 'Button'], $order);

        // Saving stores exactly what is on screen.
        $this->js('arguments[0].click()', $this->browser->getCrawler()->selectButton('Save draft')->getElement(0));
        $this->browser->waitFor('.flash--success');
        $this->em()->clear();
        $stored = $this->em()->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $stored);
        self::assertSame(array_map(strtolower(...), $order), array_column($stored->getBlocks(), 'type'));
        self::assertSame('First', $stored->getBlocks()[(int) array_search('Heading', $order, true)]['data']['text'], 'A moved block keeps its content.');
        $this->assertNoJavaScriptErrors();
    }

    public function testEverythingWorksWithTheKeyboardAndIsAnnounced(): void
    {
        $this->logIn();
        $page = $this->createPage('Keyboard', [
            ['type' => 'heading', 'data' => ['text' => 'One', 'level' => '2']],
            ['type' => 'divider', 'data' => []],
            ['type' => 'columns', 'data' => ['left' => [], 'right' => []]],
        ]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.palette__button');

        // Enter on a palette entry adds the block at the end.
        $this->press($this->paletteEntry('Quote'), WebDriverKeys::ENTER);
        self::assertSame(['Heading', 'Divider', 'Columns', 'Quote'], $this->blockTypes());

        // Space picks the heading up, the arrow keys carry it, Space drops it.
        $handle = $this->find(self::LIST.' > .block:nth-child(1) [data-dnd-handle]');
        self::assertSame('button', $handle->getTagName());
        $this->press($handle, WebDriverKeys::SPACE);
        self::assertStringContainsString('Heading picked up. Position 1 of 4', $this->announcement());
        self::assertSame('true', $handle->getAttribute('aria-pressed'));

        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_DOWN);
        self::assertStringContainsString('Position 2 of 4 in Blocks of the page', $this->announcement());

        // The next steps lead through the columns: nested lists are part of the way.
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_DOWN);
        self::assertStringContainsString('Position 1 of 1 in Blocks of the column', $this->announcement());

        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(350_000);
        self::assertStringContainsString('Heading dropped', $this->announcement());
        self::assertSame(['Divider', 'Columns', 'Quote'], $this->blockTypes());
        self::assertSame('Heading', $this->js('return document.querySelector(".columns .block .block__type").textContent'));
        self::assertTrue($this->js('return document.activeElement.matches(".columns .block [data-dnd-handle]")'), 'The focus stays on the handle of the moved block.');

        // Escape puts a carried block back.
        $handle = $this->find(self::LIST.' > .block:nth-child(1) [data-dnd-handle]');
        $this->press($handle, WebDriverKeys::SPACE, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ESCAPE);
        self::assertStringContainsString('Move cancelled', $this->announcement());
        self::assertSame(['Divider', 'Columns', 'Quote'], $this->blockTypes());

        // The menu on the handle: "Move down".
        $this->press($this->find(self::LIST.' > .block:nth-child(1) [data-dnd-handle]'), WebDriverKeys::ENTER);
        $this->browser->waitFor('.dnd-menu');
        $entries = array_map(static fn ($entry): string => trim($entry->getText()), $this->findAll('.dnd-menu__item'));
        self::assertSame(['Move up', 'Move down', 'Move to …'], $entries);
        self::assertFalse($this->find('.dnd-menu__item:nth-child(1)')->isEnabled(), 'The first block cannot move up.');
        $this->js('arguments[0].click()', $this->find('.dnd-menu__item:nth-child(2)'));
        usleep(350_000);
        // Below the divider comes the first column, so the divider lands inside it.
        self::assertSame(['Columns', 'Quote'], $this->blockTypes());

        // Ctrl+Z takes the last move back, Ctrl+Shift+Z makes it again.
        $this->js('document.activeElement.blur()');
        $this->shortcut(WebDriverKeys::CONTROL, 'z');
        self::assertSame(['Divider', 'Columns', 'Quote'], $this->blockTypes());
        $this->shortcut(WebDriverKeys::CONTROL, WebDriverKeys::SHIFT, 'z');
        self::assertSame(['Columns', 'Quote'], $this->blockTypes());
        $this->assertNoJavaScriptErrors();
    }

    public function testImageFilesDroppedFromTheDesktopBecomeBlocks(): void
    {
        $this->logIn();
        $page = $this->createPage('Photos', [
            ['type' => 'heading', 'data' => ['text' => 'Top', 'level' => '2']],
            ['type' => 'divider', 'data' => []],
        ]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.palette__button');

        // One image, dropped on the upper half of the second block.
        self::assertTrue($this->dropFiles([['harbour.png', 'image/png']]), 'A line shows where the image will go.');
        $this->waitForBlocks(3);
        self::assertSame(['Heading', 'Image', 'Divider'], $this->blockTypes());
        $this->browser->waitFor(self::LIST.' > .block:nth-child(2) .image-field img');

        // Several images at once make a gallery.
        $this->dropFiles([['a.png', 'image/png'], ['b.png', 'image/png'], ['c.png', 'image/png']]);
        $this->waitForBlocks(4);
        self::assertSame(['Heading', 'Gallery', 'Image', 'Divider'], $this->blockTypes());
        self::assertCount(3, $this->findAll(self::LIST.' > .block:nth-child(2) .images-field__list img'));

        // A text file and an image that is too large are refused with their names.
        $this->dropFiles([['notes.txt', 'text/plain'], ['huge.png', 'image/png', 40 * 1024 * 1024]]);
        $this->browser->waitFor('.toast--error');
        $message = $this->find('.toast--error')->getText();
        self::assertStringContainsString('notes.txt is not an image', $message);
        self::assertStringContainsString('huge.png is too large', $message);
        self::assertSame(['Heading', 'Gallery', 'Image', 'Divider'], $this->blockTypes());

        // The images are in the media library and the blocks are saved with them.
        $this->js('arguments[0].click()', $this->browser->getCrawler()->selectButton('Save draft')->getElement(0));
        $this->browser->waitFor('.flash--success');
        $this->em()->clear();
        self::assertSame(4, $this->em()->getRepository(Media::class)->count([]));
        $stored = $this->em()->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $stored);
        self::assertSame(['heading', 'gallery', 'image', 'divider'], array_column($stored->getBlocks(), 'type'));
        self::assertCount(3, $stored->getBlocks()[1]['data']['images']);
        self::assertIsInt($stored->getBlocks()[2]['data']['image']);
        $this->assertNoJavaScriptErrors();
    }

    /**
     * @return list<string>
     */
    private function blockTypes(): array
    {
        $types = (array) $this->js('return [...document.querySelectorAll(arguments[0] + " > .block:not(.block--uploading)")].map((block) => block.querySelector(".block__type").textContent)', self::LIST);

        return array_values(array_map(strval(...), $types));
    }

    /**
     * Waits until uploads have finished and the page has the given number of blocks.
     */
    private function waitForBlocks(int $count): void
    {
        for ($i = 0; $i < 100 && \count($this->blockTypes()) !== $count; ++$i) {
            usleep(100_000);
        }
        usleep(200_000);
    }

    private function paletteEntry(string $label): WebDriverElement
    {
        // Mark the entry in the page, then fetch it like any other element.
        $this->js('document.querySelectorAll("[data-test-entry]").forEach((button) => button.removeAttribute("data-test-entry")); [...document.querySelectorAll(".palette__button")].find((button) => button.lastElementChild.textContent === arguments[0])?.setAttribute("data-test-entry", "")', $label);

        return $this->find('.palette__button[data-test-entry]');
    }

    /**
     * Drops files onto the upper edge of the second block, the way the browser reports a drop from the desktop.
     *
     * @param list<array{0: string, 1: string, 2?: int}> $files name, type and optionally a size in bytes
     *
     * @return bool whether the line that marks the landing spot was shown
     */
    private function dropFiles(array $files): bool
    {
        return (bool) $this->browser->executeAsyncScript(<<<'JS'
            const [files, listSelector, done] = arguments;
            const png = Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FAP5FDvcfRYWgAAAAAElFTkSuQmCC'), (c) => c.charCodeAt(0));
            const data = new DataTransfer();
            for (const [name, type, size] of files) {
                data.items.add(new File([size ? new Uint8Array(size) : png], name, { type }));
            }
            const list = document.querySelector(listSelector);
            window.scrollTo(0, 0);
            const bounds = list.children[1].getBoundingClientRect();
            const init = { dataTransfer: data, bubbles: true, cancelable: true, clientX: bounds.left + 60, clientY: bounds.top + 4 };
            list.dispatchEvent(new DragEvent('dragenter', init));
            list.dispatchEvent(new DragEvent('dragover', init));
            setTimeout(() => {
                const line = document.querySelector('.blocks__drop-line') !== null;
                list.dispatchEvent(new DragEvent('drop', init));
                done(line);
            }, 100);
            JS, [$files, self::LIST]);
    }
}
