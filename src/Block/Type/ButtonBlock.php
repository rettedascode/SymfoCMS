<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class ButtonBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'button';
    }

    public function getIcon(): string
    {
        return '⬭';
    }

    public function getPosition(): int
    {
        return 40;
    }

    public function getFields(): array
    {
        return [
            Field::text('label', 'block.button.text'),
            Field::url('url', 'block.button.url')->placeholder('https://… / /contact')->help('block.button.url_help'),
            Field::select('style', 'block.button.style', [
                'primary' => 'block.button.style_primary',
                'secondary' => 'block.button.style_secondary',
            ], 'primary'),
        ];
    }
}
