<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;
use App\Block\Field;

/**
 * Shows a shared block. The page only stores which one; the content lives in
 * the "Shared blocks" section of the admin area and is edited there.
 */
final class SharedBlockReference extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'shared';
    }

    public function getIcon(): string
    {
        return '∞';
    }

    public function getPosition(): int
    {
        return 200;
    }

    public function getFields(): array
    {
        return [
            Field::sharedBlock('block', 'block.shared.block')->required(),
        ];
    }
}
