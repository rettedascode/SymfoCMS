<?php

namespace App\Tests\Browser;

use App\Entity\Media;
use App\Entity\Page;
use Facebook\WebDriver\WebDriverKeys;

final class GalleryDragDropTest extends BrowserTestCase
{
    public function testGalleryImagesAreArrangedByDraggingAndWithTheKeyboard(): void
    {
        $this->logIn();
        $ids = [];
        foreach (['Harbour', 'Forest', 'Desk'] as $name) {
            // Entries without a file are enough: the order is what counts here.
            $media = (new Media())->setFilename(strtolower($name).'.png')->setOriginalName(strtolower($name).'.png')->setMimeType('image/png')->setSize(1000)->setDimensions(1200, 800)->setAlt($name);
            $this->em()->persist($media);
            $this->em()->flush();
            $ids[] = (int) $media->getId();
        }
        $page = $this->createPage('Pictures', [['type' => 'gallery', 'data' => ['images' => $ids, 'columns' => '3']]]);

        $this->browser->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->browser->waitFor('.images-field__list [data-dnd-handle]');
        self::assertSame(['Harbour', 'Forest', 'Desk'], $this->images());

        // Drag the first image onto the last one.
        $this->drag($this->find('.images-field__list li:nth-child(1) [data-dnd-handle]'), $this->find('.images-field__list li:nth-child(3)'));
        self::assertSame(['Forest', 'Desk', 'Harbour'], $this->images());

        // Ctrl+Z puts it back.
        $this->js('document.activeElement.blur()');
        $this->shortcut(WebDriverKeys::CONTROL, 'z');
        self::assertSame(['Harbour', 'Forest', 'Desk'], $this->images());

        // Keyboard: pick the last image up, carry it two places forward, drop it.
        $this->press($this->find('.images-field__list li:nth-child(3) [data-dnd-handle]'), WebDriverKeys::SPACE);
        self::assertStringContainsString('Desk picked up. Position 3 of 3 in Images', $this->announcement());
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::ARROW_UP);
        $this->browser->getKeyboard()->pressKey(WebDriverKeys::SPACE);
        usleep(400_000);
        self::assertSame(['Desk', 'Harbour', 'Forest'], $this->images());
        self::assertTrue($this->js('return document.activeElement.matches(".images-field__list li:nth-child(1) [data-dnd-handle]")'), 'The focus stays on the handle of the moved image.');

        // The menu of the handle works as well.
        $this->press($this->find('.images-field__list li:nth-child(1) [data-dnd-handle]'), WebDriverKeys::ENTER);
        $this->browser->waitFor('.dnd-menu');
        $this->js('arguments[0].click()', $this->find('.dnd-menu__item:nth-child(2)'));
        usleep(400_000);
        self::assertSame(['Harbour', 'Desk', 'Forest'], $this->images());

        $this->js('arguments[0].click()', $this->find('.editor__bar button[type="submit"]'));
        $this->browser->waitFor('.flash--success');
        $this->em()->clear();
        $stored = $this->em()->find(Page::class, $page->getId());
        self::assertInstanceOf(Page::class, $stored);
        self::assertSame([$ids[0], $ids[2], $ids[1]], $stored->getBlocks()[0]['data']['images']);
    }

    /**
     * @return list<string>
     */
    private function images(): array
    {
        $names = (array) $this->js('return [...document.querySelectorAll(".images-field__list li")].map((item) => item.dataset.dndLabel)');

        return array_values(array_map(strval(...), $names));
    }
}
