<?php

namespace App\Theme;

/**
 * One theme as described by its theme.json. See docs/themes.md for the format.
 */
final class Theme
{
    /**
     * @param list<string>                                                           $stylesheets file names inside the theme's assets folder
     * @param array<string, array{label: string, variable: string, default: string}> $colors      setting name => definition
     * @param array<string, array{label: string, variable: string, default: string}> $fonts       setting name => definition; the default is a key of App\Theme\Fonts
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $name,
        public readonly string $description,
        public readonly string $author,
        public readonly string $version,
        public readonly ?string $parent,
        public readonly array $stylesheets,
        public readonly array $colors,
        public readonly array $fonts,
    ) {
    }

    /**
     * Folder with the Twig templates that replace those of the parent theme, if the theme has any.
     */
    public function templatePath(): ?string
    {
        return is_dir($this->path.'/templates') ? $this->path.'/templates' : null;
    }
}
