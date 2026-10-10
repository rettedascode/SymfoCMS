<?php

namespace App\Enum;

enum ContentStatus: string
{
    case Draft = 'draft';
    /** Handed in by its author and waiting for an editor. Not visible to visitors. */
    case Review = 'review';
    case Published = 'published';

    public function label(): string
    {
        return 'status.'.$this->value;
    }
}
