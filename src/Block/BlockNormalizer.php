<?php

namespace App\Block;

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

    public function __construct(
        private readonly BlockRegistry $registry,
        #[Autowire(service: 'html_sanitizer.sanitizer.rich_text')]
        private readonly HtmlSanitizerInterface $richTextSanitizer,
    ) {
    }

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function normalize(mixed $blocks, bool $nested = false): array
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
            if ($nested && $this->registry->isLayout($type)) {
                continue;
            }

            $input = \is_array($block['data'] ?? null) ? $block['data'] : [];
            $data = [];
            foreach ($type->getFields() as $field) {
                $data[$field->name] = $this->normalizeValue($field, $input[$field->name] ?? $field->default);
            }

            $normalized[] = ['type' => $block['type'], 'data' => $data];
        }

        return $normalized;
    }

    /**
     * Decodes the JSON sent by the editor. Invalid JSON results in an empty list.
     *
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function normalizeJson(?string $json): array
    {
        try {
            return $this->normalize(json_decode($json ?? '[]', true, 32, \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return [];
        }
    }

    private function normalizeValue(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            Field::TEXT => mb_substr($this->plainText($value, singleLine: true), 0, self::MAX_TEXT_LENGTH),
            Field::TEXTAREA => mb_substr($this->plainText($value), 0, self::MAX_TEXTAREA_LENGTH),
            Field::RICH_TEXT => $this->richText($value),
            Field::SELECT => \is_scalar($value) && isset($field->options[(string) $value]) ? (string) $value : $field->default,
            Field::URL => $this->url($value),
            Field::IMAGE => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            Field::CHECKBOX => filter_var($value, \FILTER_VALIDATE_BOOLEAN),
            Field::BLOCKS => $this->normalize($value, nested: true),
            default => null,
        };
    }

    private function plainText(mixed $value, bool $singleLine = false): string
    {
        $text = \is_scalar($value) ? trim((string) $value) : '';
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

    /**
     * Accepts links within the site ("/contact", "#top") and http(s), mailto and tel URLs.
     */
    private function url(mixed $value): string
    {
        $url = \is_scalar($value) ? trim((string) $value) : '';
        if ('' === $url || mb_strlen($url) > 2000 || preg_match('/[\s<>"]/', $url)) {
            return '';
        }

        if (preg_match('~^(/(?!/)|#|\?)~', $url) || preg_match('~^(https?://|mailto:|tel:)~i', $url)) {
            return $url;
        }

        // "example.com/page" was most likely meant as an external link.
        if (preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+(/.*)?$~i', $url)) {
            return 'https://'.$url;
        }

        return '';
    }
}
