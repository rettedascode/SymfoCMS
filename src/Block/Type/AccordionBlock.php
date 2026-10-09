<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * Questions that open to reveal their answers, e.g. for an FAQ.
 */
final class AccordionBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'accordion';
    }

    public function getIcon(): string
    {
        return '☰';
    }

    public function getPosition(): int
    {
        return 80;
    }

    public function getFields(): array
    {
        return [
            Field::items('items', 'block.accordion.items', [
                Field::text('question', 'block.accordion.question'),
                Field::richText('answer', 'block.accordion.answer'),
            ])->required(),
        ];
    }
}
