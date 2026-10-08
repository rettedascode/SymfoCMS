<?php

namespace App\Enum;

enum MenuLocation: string
{
    case Main = 'main';
    case Footer = 'footer';

    /**
     * Only the main navigation supports dropdowns (one level of nesting).
     */
    public function allowsNesting(): bool
    {
        return self::Main === $this;
    }
}
