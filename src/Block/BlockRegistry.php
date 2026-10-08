<?php

namespace App\Block;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Knows every available block type.
 */
final class BlockRegistry
{
    /** @var array<string, BlockTypeInterface> */
    private array $types = [];

    /**
     * @param iterable<BlockTypeInterface> $types
     */
    public function __construct(#[AutowireIterator('app.block_type')] iterable $types)
    {
        foreach ($types as $type) {
            $this->types[$type::getType()] = $type;
        }

        uasort($this->types, static fn (BlockTypeInterface $a, BlockTypeInterface $b): int => $a->getPosition() <=> $b->getPosition());
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): BlockTypeInterface
    {
        return $this->types[$type] ?? throw new \InvalidArgumentException(\sprintf('Unknown block type "%s".', $type));
    }

    /**
     * @return array<string, BlockTypeInterface>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * Layout blocks contain other blocks and cannot be nested into each other.
     */
    public function isLayout(BlockTypeInterface $type): bool
    {
        foreach ($type->getFields() as $field) {
            if (Field::BLOCKS === $field->type) {
                return true;
            }
        }

        return false;
    }
}
