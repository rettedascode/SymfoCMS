<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class GalleryBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'gallery';
    }

    public function getIcon(): string
    {
        return '▦';
    }

    public function getPosition(): int
    {
        return 35;
    }

    public function getFields(): array
    {
        return [
            Field::images('images', 'block.gallery.images')->required()->help('block.gallery.images_help'),
            Field::select('columns', 'block.gallery.columns', [
                '2' => 'block.gallery.columns_2',
                '3' => 'block.gallery.columns_3',
                '4' => 'block.gallery.columns_4',
            ], '3'),
        ];
    }
}
