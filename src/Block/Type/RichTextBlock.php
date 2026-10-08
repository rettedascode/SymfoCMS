<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class RichTextBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'text';
    }

    public function getIcon(): string
    {
        return '¶';
    }

    public function getPosition(): int
    {
        return 20;
    }

    public function getFields(): array
    {
        return [
            Field::richText('content', 'block.text.content'),
        ];
    }
}
