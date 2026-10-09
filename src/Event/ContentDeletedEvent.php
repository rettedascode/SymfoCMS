<?php

namespace App\Event;

use App\Entity\Content;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a page or post is removed from the database, while its id is still known.
 */
final class ContentDeletedEvent extends Event
{
    public function __construct(private readonly Content $content)
    {
    }

    public function getContent(): Content
    {
        return $this->content;
    }
}
