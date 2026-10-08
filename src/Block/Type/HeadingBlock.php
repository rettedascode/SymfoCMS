<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class HeadingBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'heading';
    }

    public function getIcon(): string
    {
        return 'H';
    }

    public function getPosition(): int
    {
        return 10;
    }

    public function getFields(): array
    {
        return [
            Field::text('text', 'block.heading.text'),
            // The page title is the only <h1>, so headings inside the content start at level 2.
            Field::select('level', 'block.heading.level', [
                '2' => 'block.heading.level_large',
                '3' => 'block.heading.level_small',
            ], '2'),
        ];
    }
}
