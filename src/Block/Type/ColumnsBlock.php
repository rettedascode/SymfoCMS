<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class ColumnsBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'columns';
    }

    public function getIcon(): string
    {
        return '▥';
    }

    public function getPosition(): int
    {
        return 70;
    }

    public function getFields(): array
    {
        return [
            Field::blocks('left', 'block.columns.left'),
            Field::blocks('right', 'block.columns.right'),
        ];
    }
}
