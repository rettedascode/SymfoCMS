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
    /** The id of a shared block from the "Shared blocks" section. */
    public const SHARED_BLOCK = 'shared_block';
    /** A list of image ids, e.g. for a gallery. */
    public const IMAGES = 'images';
    /** A repeatable group of fields, e.g. the questions of an accordion. */
    public const ITEMS = 'items';
    /** Source code or other text whose spaces and line breaks matter. */
    public const CODE = 'code';
    /** A place on the map: {lat, lon}. */
    public const LOCATION = 'location';
    /** The id of a form built in the "Forms" section. */
    public const FORM = 'form';

    public ?string $help = null;
    public bool $required = false;
    public ?string $placeholder = null;

    /**
     * @param array<int|string, string> $options value => label, for select fields
     * @param list<Field>               $fields  the fields of one entry, for item lists
     */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
        public readonly mixed $default,
        public readonly array $options = [],
        public readonly array $fields = [],
    ) {
    }

    /**
     * Stores a list of image ids from the media library. Look each one up with cms_media(id).
     */
    public static function images(string $name, string $label): self
    {
        return new self($name, self::IMAGES, $label, []);
    }

    /**
     * A list the user can add entries to, each entry made of the given fields.
     * In the template, data.<name> is a list of arrays keyed by field name.
     *
     * @param list<Field> $fields simple fields only (no item lists or nested blocks)
     */
    public static function items(string $name, string $label, array $fields): self
    {
        return new self($name, self::ITEMS, $label, [], fields: $fields);
    }

    /**
     * Text in a fixed-width font whose spaces and line breaks are kept exactly as typed.
     */
    public static function code(string $name, string $label): self
    {
        return new self($name, self::CODE, $label, '');
    }

    /**
     * A place found by typing an address. Stores {lat, lon} or null.
     */
    public static function location(string $name, string $label): self
    {
        return new self($name, self::LOCATION, $label, null);
    }

    /**
     * Stores the id of a form from the "Forms" section (or null).
     */
    public static function form(string $name, string $label): self
    {
        return new self($name, self::FORM, $label, null);
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
     * @param array<int|string, string> $options value => label (PHP turns numeric keys such as "2" into integers)
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
     * Stores the id of a shared block (or null). Render it with render_shared_block(data.name).
     */
    public static function sharedBlock(string $name, string $label): self
    {
        return new self($name, self::SHARED_BLOCK, $label, null);
    }

    /**
     * Short hint shown below the input.
     */
    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    /**
     * Marks the field as mandatory: content with an empty value cannot be saved.
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }
}
