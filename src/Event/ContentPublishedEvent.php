<?php

namespace App\Event;

use App\Entity\Content;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a save that changed the status of a page or post to "published".
 *
 * A post with a publish date in the future is published in this sense too:
 * check Post::isScheduled() if the difference matters to a listener.
 */
final class ContentPublishedEvent extends Event
{
    public function __construct(private readonly Content $content)
    {
    }

    public function getContent(): Content
    {
        return $this->content;
    }
}
