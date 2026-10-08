<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

final class QuoteBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'quote';
    }

    public function getIcon(): string
    {
        return '❝';
    }

    public function getPosition(): int
    {
        return 50;
    }

    public function getFields(): array
    {
        return [
            Field::textarea('text', 'block.quote.text'),
            Field::text('author', 'block.quote.author'),
        ];
    }
}
