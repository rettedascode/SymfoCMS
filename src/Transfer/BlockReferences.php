<?php

namespace App\Transfer;

use App\Block\BlockRegistry;
use App\Block\Field;

/**
 * Blocks point to images, shared blocks and forms by id. In another
 * installation these have other ids, so an import rewrites every reference.
 */
final class BlockReferences
{
    public function __construct(private readonly BlockRegistry $registry)
    {
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @param array<int, int>         $media        id in the export => id in this installation
     * @param array<int, int>         $sharedBlocks
     * @param array<int, int>         $forms
     *
     * @return list<array{type: string, data: array<string, mixed>}> references without a counterpart are emptied
     */
    public function remap(array $blocks, array $media, array $sharedBlocks, array $forms): array
    {
        $remapped = [];
        foreach ($blocks as $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $data = \is_array($block['data'] ?? null) ? $block['data'] : [];
            $remapped[] = [
                'type' => $block['type'],
                'data' => $this->remapFields($this->registry->get($block['type'])->getFields(), $data, $media, $sharedBlocks, $forms),
            ];
        }

        return $remapped;
    }

    /**
     * @param list<Field>         $fields
     * @param array<mixed, mixed> $data
     * @param array<int, int>     $media
     * @param array<int, int>     $sharedBlocks
     * @param array<int, int>     $forms
     *
     * @return array<string, mixed>
     */
    private function remapFields(array $fields, array $data, array $media, array $sharedBlocks, array $forms): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!\array_key_exists($field->name, $data)) {
                continue;
            }

            $value = $data[$field->name];
            $result[$field->name] = match ($field->type) {
                Field::IMAGE => $this->lookup($media, $value),
                Field::SHARED_BLOCK => $this->lookup($sharedBlocks, $value),
                Field::FORM => $this->lookup($forms, $value),
                Field::IMAGES => array_values(array_filter(array_map(fn (mixed $id): ?int => $this->lookup($media, $id), \is_array($value) ? $value : []))),
                Field::BLOCKS => $this->remap(\is_array($value) ? $value : [], $media, $sharedBlocks, $forms),
                Field::ITEMS => array_map(
                    fn (mixed $item): array => $this->remapFields($field->fields, \is_array($item) ? $item : [], $media, $sharedBlocks, $forms),
                    array_values(\is_array($value) ? $value : []),
                ),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * @param array<int, int> $map
     */
    private function lookup(array $map, mixed $id): ?int
    {
        return is_numeric($id) ? ($map[(int) $id] ?? null) : null;
    }
}
