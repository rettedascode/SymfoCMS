<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * Shows a form from the "Forms" section of the admin area.
 */
final class FormBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'form';
    }

    public function getIcon(): string
    {
        return '✉';
    }

    public function getPosition(): int
    {
        return 85;
    }

    public function getFields(): array
    {
        return [
            Field::form('form', 'block.form.form')->required(),
        ];
    }
}
