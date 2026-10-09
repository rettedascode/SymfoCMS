<?php

namespace App\Block;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Asks every block type whether the data of its blocks is acceptable.
 */
final class BlockValidator
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Returns translated messages keyed by "<block path>:<field>". The path is
     * the position of the block, e.g. "2" for the third block or "2.left.0"
     * for the first block in the left column of the third block.
     *
     * @param array<array-key, mixed> $blocks normalized blocks
     *
     * @return array<string, string>
     */
    public function validate(array $blocks, string $prefix = ''): array
    {
        $errors = [];

        foreach (array_values($blocks) as $index => $block) {
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $type = $this->registry->get($block['type']);
            $data = \is_array($block['data'] ?? null) ? $block['data'] : [];
            $path = $prefix.$index;

            foreach ($type->validate($data) as $field => $message) {
                $errors[$path.':'.$field] = $this->translator->trans($message);
            }

            foreach ($type->getFields() as $field) {
                if (Field::BLOCKS === $field->type && \is_array($data[$field->name] ?? null)) {
                    $errors += $this->validate($data[$field->name], $path.'.'.$field->name.'.');
                }
            }
        }

        return $errors;
    }
}
