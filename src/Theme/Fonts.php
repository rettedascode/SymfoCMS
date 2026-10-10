<?php

namespace App\Theme;

/**
 * The fonts a theme setting can choose from. "System" fonts are already on
 * the visitor's device; the others are served from assets/fonts/.
 */
final class Fonts
{
    /** Stylesheet with the @font-face rules of the fonts that are not system fonts. */
    public const STYLESHEET = 'styles/fonts.css';

    /** @var array<string, array{label: string, stack: string, hosted: bool}> */
    private const FONTS = [
        'system-sans' => ['label' => 'theme.font.system_sans', 'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', 'hosted' => false],
        'system-serif' => ['label' => 'theme.font.system_serif', 'stack' => 'Georgia, "Times New Roman", Times, serif', 'hosted' => false],
        'inter' => ['label' => 'Inter', 'stack' => '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif', 'hosted' => true],
        'lora' => ['label' => 'Lora', 'stack' => '"Lora", Georgia, "Times New Roman", serif', 'hosted' => true],
        'playfair-display' => ['label' => 'Playfair Display', 'stack' => '"Playfair Display", Georgia, "Times New Roman", serif', 'hosted' => true],
    ];

    public static function has(string $font): bool
    {
        return isset(self::FONTS[$font]);
    }

    /**
     * @return array<string, string> font key => name (a translation key for the system fonts)
     */
    public static function labels(): array
    {
        return array_map(static fn (array $font): string => $font['label'], self::FONTS);
    }

    /**
     * The value for the CSS font-family property.
     */
    public static function stack(string $font): string
    {
        return self::FONTS[$font]['stack'] ?? self::FONTS['system-sans']['stack'];
    }

    /**
     * True when the font has to be loaded from this website.
     */
    public static function isHosted(string $font): bool
    {
        return self::FONTS[$font]['hosted'] ?? false;
    }
}
