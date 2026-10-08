<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class ImageBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'image';
    }

    public function getIcon(): string
    {
        return '▣';
    }

    public function getPosition(): int
    {
        return 30;
    }

    public function getFields(): array
    {
        return [
            Field::image('image', 'block.image.image'),
            Field::text('alt', 'block.image.alt')->help('block.image.alt_help'),
            Field::text('caption', 'block.image.caption'),
        ];
    }
}
