<?php

namespace App\Twig;

use App\Entity\Media;
use App\Theme\ThemeCustomizer;
use App\Theme\ThemeRegistry;
use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFunction;

/**
 * What a theme's layout needs: its stylesheets, the chosen colours and fonts, and the logo.
 */
final class ThemeExtension
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeCustomizer $customizer,
        private readonly Packages $packages,
    ) {
    }

    /**
     * Addresses of the stylesheets of the active theme, to be linked in this order.
     *
     * @return list<string>
     */
    #[AsTwigFunction('theme_stylesheets')]
    public function stylesheets(): array
    {
        return array_map($this->packages->getUrl(...), $this->customizer->stylesheets($this->themes->active()));
    }

    /**
     * A <style> element with the colours and fonts chosen in the admin area (empty when the theme has no settings).
     */
    #[AsTwigFunction('theme_style', isSafe: ['html'])]
    public function style(): string
    {
        $css = $this->customizer->css($this->themes->active());

        // Every value was checked by the customizer, so the CSS cannot break out of the element.
        return '' !== $css ? '<style>'.$css.'</style>' : '';
    }

    #[AsTwigFunction('theme_logo')]
    public function logo(): ?Media
    {
        return $this->customizer->logo();
    }

    /**
     * Address of a file from the assets folder of the active theme, e.g. theme_asset('images/pattern.svg').
     * Falls back to the parent theme when the active theme does not have the file.
     */
    #[AsTwigFunction('theme_asset')]
    public function asset(string $file): string
    {
        $themes = $this->themes->lineage($this->themes->active());
        foreach ($themes as $theme) {
            if (is_file($theme->path.'/assets/'.$file)) {
                return $this->packages->getUrl('themes/'.$theme->id.'/assets/'.$file);
            }
        }

        return $this->packages->getUrl('themes/'.$themes[0]->id.'/assets/'.$file);
    }
}
