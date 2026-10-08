<?php

namespace App\Block\Type;

use App\Block\AbstractBlockType;

final class DividerBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'divider';
    }

    public function getIcon(): string
    {
        return '—';
    }

    public function getPosition(): int
    {
        return 60;
    }
}
