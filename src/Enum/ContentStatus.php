<?php

namespace App\Enum;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return 'status.'.$this->value;
    }
}
