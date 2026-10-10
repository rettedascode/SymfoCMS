<?php

namespace App\Service;

/**
 * The fields a form can be built from, and the clean-up of what the form builder sends.
 */
final class FormDefinition
{
    public const TEXT = 'text';
    public const EMAIL = 'email';
    public const TEXTAREA = 'textarea';
    public const SELECT = 'select';
    public const CHECKBOX = 'checkbox';
    /** A checkbox that must be ticked, e.g. to accept the privacy policy. */
    public const CONSENT = 'consent';

    public const TYPES = [self::TEXT, self::EMAIL, self::TEXTAREA, self::SELECT, self::CHECKBOX, self::CONSENT];

    /** Fields that sit side by side in one row of the form. */
    public const MAX_PER_ROW = 3;

    private const MAX_FIELDS = 30;
    private const MAX_OPTIONS = 50;

    /**
     * Turns untrusted builder input into a valid list of fields. Entries that make no sense are dropped.
     *
     * @return list<array{id: string, type: string, label: string, required: bool, options: list<string>, join: bool}>
     */
    public function normalize(mixed $fields): array
    {
        $normalized = [];
        $usedIds = [];
        $inRow = 0;

        foreach (\is_array($fields) ? $fields : [] as $field) {
            if (!\is_array($field) || \count($normalized) >= self::MAX_FIELDS) {
                continue;
            }

            $type = \is_string($field['type'] ?? null) ? $field['type'] : '';
            $label = $this->text($field['label'] ?? '', 300);
            if (!\in_array($type, self::TYPES, true) || '' === $label) {
                continue;
            }

            $options = [];
            if (self::SELECT === $type) {
                foreach (\is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                    $option = $this->text($option, 150);
                    if ('' !== $option && !\in_array($option, $options, true) && \count($options) < self::MAX_OPTIONS) {
                        $options[] = $option;
                    }
                }
                // A choice without anything to choose from is useless.
                if ([] === $options) {
                    continue;
                }
            }

            // Ids identify a field in the submitted data. Keep existing ones, create missing ones.
            $id = \is_string($field['id'] ?? null) && 1 === preg_match('/^f[a-z0-9]{6,12}$/', $field['id']) && !isset($usedIds[$field['id']])
                ? $field['id']
                : 'f'.bin2hex(random_bytes(4));
            $usedIds[$id] = true;

            // "join" puts a field next to the one before it. A row never holds more than three.
            $join = [] !== $normalized && $inRow < self::MAX_PER_ROW && filter_var($field['join'] ?? false, \FILTER_VALIDATE_BOOLEAN);
            $inRow = $join ? $inRow + 1 : 1;

            $normalized[] = [
                'id' => $id,
                'type' => $type,
                'label' => $label,
                // Consent is only meaningful when it cannot be skipped.
                'required' => self::CONSENT === $type || filter_var($field['required'] ?? false, \FILTER_VALIDATE_BOOLEAN),
                'options' => $options,
                'join' => $join,
            ];
        }

        return $normalized;
    }

    /**
     * The fields grouped the way the form shows them: one list per row.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<list<array<string, mixed>>>
     */
    public static function rows(array $fields): array
    {
        $rows = [];
        foreach ($fields as $field) {
            $last = \count($rows) - 1;
            if (true === ($field['join'] ?? false) && $last >= 0 && \count($rows[$last]) < self::MAX_PER_ROW) {
                $rows[$last][] = $field;
            } else {
                $rows[] = [$field];
            }
        }

        return $rows;
    }

    private function text(mixed $value, int $maxLength): string
    {
        $text = \is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return mb_substr($text, 0, $maxLength);
    }
}
