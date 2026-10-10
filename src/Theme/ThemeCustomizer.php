<?php

namespace App\Theme;

use App\Entity\Media;
use App\Repository\MediaRepository;
use App\Service\Settings;

/**
 * The choices an administrator made for a theme: logo, colours and fonts.
 *
 * Colours and fonts reach the stylesheet as CSS custom properties. Every
 * value is checked against the theme's manifest before it is stored or
 * printed, so nothing but colours and known fonts can end up in the page.
 */
final class ThemeCustomizer
{
    private const LOGO_SETTING = 'theme.logo';
    private const VALUES_SETTING = 'theme.settings.';

    public function __construct(
        private readonly ThemeRegistry $registry,
        private readonly Settings $settings,
        private readonly MediaRepository $media,
    ) {
    }

    /**
     * The settings a theme offers. A theme inherits the settings of its parent and may change their defaults.
     *
     * @return array{colors: array<string, array{label: string, variable: string, default: string}>, fonts: array<string, array{label: string, variable: string, default: string}>}
     */
    public function definitions(Theme $theme): array
    {
        $definitions = ['colors' => [], 'fonts' => []];
        foreach (array_reverse($this->registry->lineage($theme)) as $ancestor) {
            $definitions['colors'] = array_replace($definitions['colors'], $ancestor->colors);
            $definitions['fonts'] = array_replace($definitions['fonts'], $ancestor->fonts);
        }

        return $definitions;
    }

    /**
     * The current values: what was chosen in the admin area, or the defaults of the theme.
     *
     * @return array{colors: array<string, string>, fonts: array<string, string>}
     */
    public function values(Theme $theme): array
    {
        $stored = $this->settings->get(self::VALUES_SETTING.$theme->id);
        $stored = \is_array($stored) ? $stored : [];
        $values = ['colors' => [], 'fonts' => []];

        foreach ($this->definitions($theme) as $group => $definitions) {
            foreach ($definitions as $name => $definition) {
                $value = $stored[$group][$name] ?? null;
                $values[$group][$name] = \is_string($value) && $this->isValid($group, $value) ? $value : $definition['default'];
            }
        }

        return $values;
    }

    /**
     * Stores the chosen values. Values equal to the default are not stored, so an update of the theme can change them.
     *
     * @param array<string, mixed> $colors
     * @param array<string, mixed> $fonts
     */
    public function save(Theme $theme, array $colors, array $fonts): void
    {
        $custom = [];
        foreach (['colors' => $colors, 'fonts' => $fonts] as $group => $chosen) {
            foreach ($this->definitions($theme)[$group] as $name => $definition) {
                $value = \is_string($chosen[$name] ?? null) ? strtolower($chosen[$name]) : null;
                if (null !== $value && $value !== $definition['default'] && $this->isValid($group, $value)) {
                    $custom[$group][$name] = $value;
                }
            }
        }

        $this->settings->set(self::VALUES_SETTING.$theme->id, [] !== $custom ? $custom : null);
    }

    public function reset(Theme $theme): void
    {
        $this->settings->set(self::VALUES_SETTING.$theme->id, null);
    }

    /**
     * The logo belongs to the website, not to a theme: it stays when the theme changes.
     */
    public function logo(): ?Media
    {
        $id = $this->settings->get(self::LOGO_SETTING);

        return \is_int($id) ? $this->media->find($id) : null;
    }

    public function setLogo(?Media $logo): void
    {
        $this->settings->set(self::LOGO_SETTING, $logo?->getId());
    }

    /**
     * The custom properties for the page, e.g. ":root{--color-accent:#2f55d4}".
     */
    public function css(Theme $theme): string
    {
        $definitions = $this->definitions($theme);
        $values = $this->values($theme);
        $declarations = [];

        foreach ($definitions['colors'] as $name => $definition) {
            $declarations[] = $definition['variable'].':'.$values['colors'][$name];
        }
        foreach ($definitions['fonts'] as $name => $definition) {
            $declarations[] = $definition['variable'].':'.Fonts::stack($values['fonts'][$name]);
        }

        return [] !== $declarations ? ':root{'.implode(';', $declarations).'}' : '';
    }

    /**
     * Logical asset paths of the stylesheets to load, in order: fonts, parent themes, the theme itself.
     *
     * @return list<string>
     */
    public function stylesheets(Theme $theme): array
    {
        $stylesheets = [];
        foreach ($this->values($theme)['fonts'] as $font) {
            if (Fonts::isHosted($font)) {
                $stylesheets[] = Fonts::STYLESHEET;
                break;
            }
        }

        foreach (array_reverse($this->registry->lineage($theme)) as $ancestor) {
            foreach ($ancestor->stylesheets as $file) {
                $stylesheets[] = 'themes/'.$ancestor->id.'/assets/'.$file;
            }
        }

        return $stylesheets;
    }

    private function isValid(string $group, string $value): bool
    {
        return 'colors' === $group ? 1 === preg_match('/^#[0-9a-f]{6}$/', $value) : Fonts::has($value);
    }
}
