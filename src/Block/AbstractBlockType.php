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

    /**
     * Reports required fields that were left empty. Override to add your own rules
     * and merge them with parent::validate($data).
     */
    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->getFields() as $field) {
            $value = $data[$field->name] ?? null;
            if ($field->required && (null === $value || '' === $value || [] === $value || false === $value)) {
                $errors[$field->name] = 'block.error.required';
            }
        }

        return $errors;
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
