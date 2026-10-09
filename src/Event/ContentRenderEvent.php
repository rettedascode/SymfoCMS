<?php

namespace App\Event;

use App\Entity\Content;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a page or post is about to be rendered on the public website.
 *
 * Listeners may replace the blocks that are shown (the stored content is not
 * changed) and pass additional variables to the Twig template.
 */
final class ContentRenderEvent extends Event
{
    /** @var array<string, mixed> */
    private array $templateVariables = [];

    /**
     * @param array<array-key, mixed> $blocks
     */
    public function __construct(
        private readonly Content $content,
        private array $blocks,
    ) {
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * @param array<array-key, mixed> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = $blocks;
    }

    public function setTemplateVariable(string $name, mixed $value): void
    {
        $this->templateVariables[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array
    {
        return $this->templateVariables;
    }
}
