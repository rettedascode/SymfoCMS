<?php

namespace App\Event;

use App\Entity\Content;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched right before a page or post is written to the database.
 * Listeners may change the entity or replace the blocks that will be stored.
 */
final class ContentSavingEvent extends Event
{
    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function __construct(
        private readonly Content $content,
        private array $blocks,
        private readonly bool $new,
    ) {
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = $blocks;
    }

    /**
     * True when the content is stored for the first time.
     */
    public function isNew(): bool
    {
        return $this->new;
    }
}
