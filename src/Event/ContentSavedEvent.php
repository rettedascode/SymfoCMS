<?php

namespace App\Event;

use App\Entity\Content;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a page or post has been written to the database.
 */
final class ContentSavedEvent extends Event
{
    public function __construct(
        private readonly Content $content,
        private readonly bool $new,
    ) {
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    public function isNew(): bool
    {
        return $this->new;
    }
}
