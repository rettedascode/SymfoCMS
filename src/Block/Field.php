<?php

namespace App\Block;

/**
 * Describes one input of a block. The editor builds its forms from these
 * definitions, so a block type never needs its own JavaScript.
 */
final class Field
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const RICH_TEXT = 'richtext';
    public const SELECT = 'select';
    public const URL = 'url';
    public const IMAGE = 'image';
    public const CHECKBOX = 'checkbox';
    /** A nested list of blocks, used by layout blocks such as columns. */
    public const BLOCKS = 'blocks';

    public ?string $help = null;
    public ?string $placeholder = null;

    /**
     * @param array<string, string> $options value => label, for select fields
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly mixed $default,
        public readonly array $options = [],
    ) {
    }

    public static function text(string $name, string $label, string $default = ''): self
    {
        return new self($name, self::TEXT, $label, $default);
    }

    public static function textarea(string $name, string $label, string $default = ''): self
    {
        return new self($name, self::TEXTAREA, $label, $default);
    }

    public static function richText(string $name, string $label, string $default = ''): self
    {
        return new self($name, self::RICH_TEXT, $label, $default);
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $name, string $label, array $options, string $default): self
    {
        return new self($name, self::SELECT, $label, $default, $options);
    }

    public static function url(string $name, string $label, string $default = ''): self
    {
        return new self($name, self::URL, $label, $default);
    }

    /**
     * Stores the id of an image from the media library (or null).
     */
    public static function image(string $name, string $label): self
    {
        return new self($name, self::IMAGE, $label, null);
    }

    public static function checkbox(string $name, string $label, bool $default = false): self
    {
        return new self($name, self::CHECKBOX, $label, $default);
    }

    public static function blocks(string $name, string $label): self
    {
        return new self($name, self::BLOCKS, $label, []);
    }

    /**
     * Short hint shown below the input.
     */
    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }
}
