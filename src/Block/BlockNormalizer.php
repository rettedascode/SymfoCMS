<?php

namespace App\Block;

use App\Service\LinkSanitizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Turns untrusted editor input into a clean list of blocks.
 *
 * Unknown block types and unknown fields are dropped, every value is coerced
 * to the type its field declares and rich text is sanitized. Whatever comes
 * out of here is safe to store and to render.
 */
final class BlockNormalizer
{
    private const MAX_BLOCKS = 300;
    private const MAX_TEXT_LENGTH = 500;
    private const MAX_TEXTAREA_LENGTH = 10000;
    private const MAX_CODE_LENGTH = 50000;
    private const MAX_IMAGES = 60;
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly BlockRegistry $registry,
        #[Autowire(service: 'html_sanitizer.sanitizer.rich_text')]
        private readonly HtmlSanitizerInterface $richTextSanitizer,
        private readonly LinkSanitizer $linkSanitizer,
    ) {
    }

    /**
     * @param bool $nested      true for blocks inside a layout block: layout blocks cannot be nested
     * @param bool $allowShared false for the content of a shared block: it cannot show other shared blocks
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function normalize(mixed $blocks, bool $nested = false, bool $allowShared = true): array
    {
        if (!\is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach ($blocks as $block) {
            if (\count($normalized) >= self::MAX_BLOCKS) {
                break;
            }
            if (!\is_array($block) || !\is_string($block['type'] ?? null) || !$this->registry->has($block['type'])) {
                continue;
            }

            $type = $this->registry->get($block['type']);
            if (($nested && $this->registry->isLayout($type)) || (!$allowShared && $this->registry->isReference($type))) {
                continue;
            }

            $input = \is_array($block['data'] ?? null) ? $block['data'] : [];
            $normalized[] = ['type' => $block['type'], 'data' => $this->normalizeFields($type->getFields(), $input, $allowShared)];
        }

        return $normalized;
    }

    /**
     * Decodes the JSON sent by the editor. Invalid JSON results in an empty list.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function normalizeJson(?string $json, bool $allowShared = true): array
    {
        try {
            return $this->normalize(json_decode($json ?? '[]', true, 32, \JSON_THROW_ON_ERROR), allowShared: $allowShared);
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @param list<Field>         $fields
     * @param array<mixed, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalizeFields(array $fields, array $input, bool $allowShared): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field->name] = $this->normalizeValue($field, $input[$field->name] ?? $field->default, $allowShared);
        }

        return $data;
    }

    private function normalizeValue(Field $field, mixed $value, bool $allowShared): mixed
    {
        return match ($field->type) {
            Field::TEXT => mb_substr($this->plainText($value, singleLine: true), 0, self::MAX_TEXT_LENGTH),
            Field::TEXTAREA => mb_substr($this->plainText($value), 0, self::MAX_TEXTAREA_LENGTH),
            Field::CODE => mb_substr(rtrim($this->plainText($value, trim: false)), 0, self::MAX_CODE_LENGTH),
            Field::RICH_TEXT => $this->richText($value),
            Field::SELECT => \is_scalar($value) && isset($field->options[(string) $value]) ? (string) $value : $field->default,
            Field::URL => $this->linkSanitizer->sanitize($value),
            Field::IMAGE, Field::SHARED_BLOCK, Field::FORM => $this->id($value),
            Field::IMAGES => $this->ids($value),
            Field::CHECKBOX => filter_var($value, \FILTER_VALIDATE_BOOLEAN),
            Field::LOCATION => $this->location($value),
            Field::ITEMS => $this->items($field, $value, $allowShared),
            Field::BLOCKS => $this->normalize($value, nested: true, allowShared: $allowShared),
            default => null,
        };
    }

    private function plainText(mixed $value, bool $singleLine = false, bool $trim = true): string
    {
        $text = \is_scalar($value) ? (string) $value : '';
        $text = $trim ? trim($text) : str_replace(["\r\n", "\r"], "\n", $text);
        // Drop control characters; templates escape everything else.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return $singleLine ? (string) preg_replace('/\s+/u', ' ', $text) : $text;
    }

    private function richText(mixed $value): string
    {
        $html = trim($this->richTextSanitizer->sanitize(\is_scalar($value) ? (string) $value : ''));

        // An editor that was emptied may still contain a blank paragraph.
        return '' === trim(strip_tags($html)) ? '' : $html;
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        $ids = [];
        foreach (\is_array($value) ? $value : [] as $candidate) {
            $id = $this->id($candidate);
            if (null !== $id && \count($ids) < self::MAX_IMAGES) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private function location(mixed $value): ?array
    {
        if (!\is_array($value) || !is_numeric($value['lat'] ?? null) || !is_numeric($value['lon'] ?? null)) {
            return null;
        }

        $lat = round((float) $value['lat'], 6);
        $lon = round((float) $value['lon'], 6);

        return abs($lat) <= 90 && abs($lon) <= 180 ? ['lat' => $lat, 'lon' => $lon] : null;
    }

    /**
     * A repeatable group of fields, e.g. the questions of an accordion.
     *
     * @return list<array<string, mixed>>
     */
    private function items(Field $field, mixed $value, bool $allowShared): array
    {
        $items = [];
        foreach (\is_array($value) ? $value : [] as $item) {
            if (\is_array($item) && \count($items) < self::MAX_ITEMS) {
                $items[] = $this->normalizeFields($field->fields, $item, $allowShared);
            }
        }

        return $items;
    }
}
