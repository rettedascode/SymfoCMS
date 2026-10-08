<?php

namespace App\Block;

/**
 * Sensible defaults by convention: a block with the type "example" is labelled
 * with the translation key "block.example.label" and rendered by
 * templates/blocks/example.html.twig.
 */
abstract class AbstractBlockType implements BlockTypeInterface
{
    public function getLabel(): string
    {
        return 'block.'.static::getType().'.label';
    }

    public function getIcon(): string
    {
        return '▢';
    }

    public function getFields(): array
    {
        return [];
    }

    public function getTemplate(): string
    {
        return 'blocks/'.static::getType().'.html.twig';
    }

    public function getPosition(): int
    {
        return 100;
    }
}
