<?php

namespace App\Block;

use Twig\Environment;

/**
 * Renders stored blocks with the Twig template of their type.
 */
final class BlockRenderer
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param array<array-key, mixed> $blocks
     */
    public function render(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            // Content may reference a block type that was removed from the code base: skip it.
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $html .= $this->twig->render($this->registry->get($block['type'])->getTemplate(), [
                'data' => \is_array($block['data'] ?? null) ? $block['data'] : [],
            ]);
        }

        return $html;
    }
}
