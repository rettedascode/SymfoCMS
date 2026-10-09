<?php

namespace App\Twig;

use App\Entity\Content;
use App\Service\ContentLocales;
use App\Service\ContentTranslator;
use App\Service\SiteUrls;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions for language-aware links, the language switcher and hreflang tags.
 */
final class LocaleExtension
{
    public function __construct(
        private readonly SiteUrls $siteUrls,
        private readonly ContentLocales $locales,
        private readonly ContentTranslator $translator,
    ) {
    }

    /**
     * Like path(), but for the public website: adds the language prefix where needed.
     *
     * @param array<string, mixed> $parameters
     */
    #[AsTwigFunction('site_path')]
    public function sitePath(string $route, array $parameters = [], ?string $locale = null): string
    {
        return $this->siteUrls->generate($route, $parameters, $locale);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[AsTwigFunction('site_url')]
    public function siteUrl(string $route, array $parameters = [], ?string $locale = null): string
    {
        return $this->siteUrls->generate($route, $parameters, $locale, absolute: true);
    }

    #[AsTwigFunction('content_path')]
    public function contentPath(Content $content): string
    {
        return $this->siteUrls->content($content);
    }

    #[AsTwigFunction('content_url')]
    public function contentUrl(Content $content): string
    {
        return $this->siteUrls->content($content, absolute: true);
    }

    #[AsTwigFunction('is_multilingual')]
    public function isMultilingual(): bool
    {
        return $this->locales->isMultilingual();
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('content_locales')]
    public function contentLocales(): array
    {
        return $this->locales->all();
    }

    #[AsTwigFunction('default_content_locale')]
    public function defaultContentLocale(): string
    {
        return $this->locales->default();
    }

    #[AsTwigFunction('locale_name')]
    public function localeName(string $locale): string
    {
        return $this->locales->name($locale);
    }

    /**
     * Entries of the language switcher. Each language links to the matching
     * version of the page being shown, or to a fallback (by default the
     * homepage of that language) when there is no public version.
     *
     * Returns an empty list on single-language sites.
     *
     * @param array<string, mixed> $fallbackParameters
     *
     * @return list<array{locale: string, name: string, url: string, current: bool}>
     */
    #[AsTwigFunction('language_switcher')]
    public function languageSwitcher(?Content $content = null, string $fallbackRoute = 'app_home', array $fallbackParameters = []): array
    {
        if (!$this->locales->isMultilingual()) {
            return [];
        }

        $current = $this->siteUrls->currentLocale();
        $versions = null !== $content ? $this->translator->versions($content) : [];
        $entries = [];

        foreach ($this->locales->all() as $locale) {
            $version = $versions[$locale] ?? null;
            $entries[] = [
                'locale' => $locale,
                'name' => $this->locales->name($locale),
                'url' => null !== $version && $version->isPublic()
                    ? $this->siteUrls->content($version)
                    : $this->siteUrls->generate($fallbackRoute, $fallbackParameters, $locale),
                'current' => $locale === $current,
            ];
        }

        return $entries;
    }

    /**
     * Public language versions of a page or post for hreflang tags, as
     * language => absolute URL. Empty unless at least two versions are public.
     * The "x-default" entry points to the version in the default language, if there is one.
     *
     * @return array<string, string>
     */
    #[AsTwigFunction('content_alternates')]
    public function contentAlternates(Content $content): array
    {
        if (!$this->locales->isMultilingual()) {
            return [];
        }

        $alternates = [];
        foreach ($this->translator->versions($content) as $locale => $version) {
            if ($version->isPublic() && $this->locales->isEnabled($locale)) {
                $alternates[$locale] = $this->siteUrls->content($version, absolute: true);
            }
        }

        if (\count($alternates) < 2) {
            return [];
        }

        ksort($alternates);
        if (isset($alternates[$this->locales->default()])) {
            $alternates['x-default'] = $alternates[$this->locales->default()];
        }

        return $alternates;
    }
}
