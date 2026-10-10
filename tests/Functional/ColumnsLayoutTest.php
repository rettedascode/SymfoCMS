<?php

namespace App\Tests\Functional;

use App\Block\Type\ColumnsBlock;
use App\Entity\Page;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;

/**
 * Columns as the server stores and shows them: two or three, with widths.
 * Making them by dragging is covered by tests/Browser/EditorColumnsTest.
 */
final class ColumnsLayoutTest extends AppWebTestCase
{
    public function testThreeColumnsWithWidthsAreSavedAndShown(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Layout', ContentStatus::Published);

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([
            ['type' => 'columns', 'data' => [
                'left' => [['type' => 'heading', 'data' => ['text' => 'Left', 'level' => '3']]],
                'middle' => [
                    ['type' => 'heading', 'data' => ['text' => 'Middle', 'level' => '3']],
                    // Columns do not hold other columns, in the middle as little as left and right.
                    ['type' => 'columns', 'data' => ['left' => [], 'right' => []]],
                ],
                'right' => [['type' => 'heading', 'data' => ['text' => 'Right', 'level' => '3']]],
                'widths' => '3-4-5',
            ]],
        ])]);
        self::assertResponseRedirects();

        $this->em()->clear();
        $data = $this->entity(Page::class, $page->getId())->getBlocks()[0]['data'];
        self::assertSame('3-4-5', $data['widths']);
        self::assertSame(['heading'], array_column($data['middle'], 'type'));

        $crawler = $this->client->request('GET', '/layout');
        self::assertResponseIsSuccessful();
        $columns = $crawler->filter('.block-columns');
        self::assertSame('block block-columns block-columns--3', $columns->attr('class'));
        self::assertSame('--columns: minmax(0, 3fr) minmax(0, 4fr) minmax(0, 5fr)', $columns->attr('style'));
        self::assertSame(['Left', 'Middle', 'Right'], $columns->filter('.block-columns__column')->each(static fn ($column): string => trim($column->text())));
    }

    public function testColumnsSavedBeforeThereWereThreeLookAsBefore(): void
    {
        $page = $this->createPage('Old', ContentStatus::Published);
        $page->setBlocks([['type' => 'columns', 'data' => [
            'left' => [['type' => 'heading', 'data' => ['text' => 'Left', 'level' => '3']]],
            'right' => [['type' => 'heading', 'data' => ['text' => 'Right', 'level' => '3']]],
        ]]]);
        $this->em()->flush();

        $columns = $this->client->request('GET', '/old')->filter('.block-columns');
        self::assertResponseIsSuccessful();
        self::assertSame('block block-columns block-columns--2', $columns->attr('class'));
        self::assertNull($columns->attr('style'), 'Without stored widths the columns are equally wide.');
        self::assertCount(2, $columns->filter('.block-columns__column'));
    }

    public function testWidthsThatDoNotFitAreIgnored(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Widths', ContentStatus::Published);
        $heading = static fn (string $text): array => ['type' => 'heading', 'data' => ['text' => $text, 'level' => '3']];
        $save = function (array $data) use ($page): array {
            $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
            $this->client->submitForm('Save changes', ['page[blocks]' => $this->json([['type' => 'columns', 'data' => $data]])]);
            self::assertResponseRedirects();
            $this->em()->clear();

            return $this->entity(Page::class, $page->getId())->getBlocks()[0]['data'];
        };

        // Widths the editor never offers are not stored: too narrow, not twelve parts, or no numbers at all.
        foreach (['1-11', '2-10', '6-7', '4-4', '12', 'wide', '6-6; color: red', '3-3-3-3'] as $widths) {
            self::assertSame('', $save(['left' => [$heading('A')], 'right' => [$heading('B')], 'widths' => $widths])['widths'], $widths);
        }

        // Widths for two columns on a block with three (and the other way round) are stored but not used.
        self::assertSame('4-8', $save(['left' => [$heading('A')], 'middle' => [$heading('B')], 'right' => [$heading('C')], 'widths' => '4-8'])['widths']);
        $columns = $this->client->request('GET', '/widths')->filter('.block-columns');
        self::assertSame('block block-columns block-columns--3', $columns->attr('class'));
        self::assertNull($columns->attr('style'));

        // The usual case.
        $save(['left' => [$heading('A')], 'right' => [$heading('B')], 'widths' => '4-8']);
        self::assertSame('--columns: minmax(0, 4fr) minmax(0, 8fr)', $this->client->request('GET', '/widths')->filter('.block-columns')->attr('style'));
    }

    public function testEveryWidthChoiceFillsTheRowAndKeepsColumnsUsable(): void
    {
        $choices = ColumnsBlock::widthChoices();

        self::assertContains('6-6', $choices);
        self::assertContains('4-4-4', $choices);
        self::assertSame($choices, array_values(array_unique($choices)));
        foreach ($choices as $choice) {
            $parts = array_map(intval(...), explode('-', $choice));
            self::assertContains(\count($parts), [2, 3], $choice);
            self::assertSame(ColumnsBlock::GRID, array_sum($parts), $choice);
            self::assertGreaterThanOrEqual(ColumnsBlock::MIN_WIDTH, min($parts), $choice);
        }
    }

    public function testEditorGetsWhatItNeedsForColumnsAndTheOutline(): void
    {
        $this->loginAs(UserRole::Editor);
        $page = $this->createPage('Editor', ContentStatus::Draft);

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertResponseIsSuccessful();
        $config = json_decode((string) $crawler->filter('[data-controller="editor"]')->attr('data-editor-config-value'), true);

        $columns = array_values(array_filter($config['types'], static fn (array $type): bool => 'columns' === $type['type']))[0];
        self::assertTrue($columns['layout']);
        self::assertSame('Columns', $columns['label']);
        self::assertSame(['left', 'middle', 'right', 'widths'], array_column($columns['fields'], 'name'));
        self::assertSame(ColumnsBlock::widthChoices(), array_column($columns['fields'][3]['options'], 'value'));
        foreach (['edge_left', 'edge_right', 'edge_hint', 'column_divider', 'column_divider_hint', 'column_widths', 'outline_list', 'outline_jump'] as $key) {
            self::assertArrayHasKey($key, $config['i18n']);
        }
        self::assertStringContainsString('%block%', $config['i18n']['edge_left']);

        self::assertCount(1, $crawler->filter('details.outline[data-editor-target="outline"] [data-editor-target="outlineBody"]'));
        self::assertSelectorTextContains('.outline__summary', 'Outline');
    }
}
