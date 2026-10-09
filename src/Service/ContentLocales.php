<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The languages the website's content is written in.
 *
 * The first one is the default language: its URLs carry no prefix
 * ("/about"), all others are prefixed with their code ("/de/ueber-uns").
 * Not to be confused with LocaleChoices, the languages of the admin interface.
 */
final class ContentLocales
{
    public const SETTING = 'locales';
    public const MAX_LOCALES = 12;

    /** A language code such as "de" or "pt-br", as it appears in URLs. */
    public const PATTERN = '[a-z]{2}(?:-[a-z]{2})?';

    public function __construct(
        private readonly Settings $settings,
        #[Autowire(param: 'kernel.default_locale')]
        private readonly string $fallbackLocale,
    ) {
    }

    /**
     * @return non-empty-list<string> the default language first
     */
    public function all(): array
    {
        $stored = $this->settings->get(self::SETTING);
        $locales = [];
        foreach (\is_array($stored) ? $stored : [] as $locale) {
            if (\is_string($locale) && self::isValidCode($locale) && !\in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        return [] === $locales ? [self::isValidCode($this->fallbackLocale) ? $this->fallbackLocale : 'en'] : $locales;
    }

    /**
     * @param list<string> $locales the default language first
     */
    public function save(array $locales): void
    {
        $this->settings->set(self::SETTING, array_values(array_unique($locales)));
    }

    public function default(): string
    {
        return $this->all()[0];
    }

    public function isDefault(string $locale): bool
    {
        return $this->default() === $locale;
    }

    public function isEnabled(string $locale): bool
    {
        return \in_array($locale, $this->all(), true);
    }

    public function isMultilingual(): bool
    {
        return \count($this->all()) > 1;
    }

    /**
     * @return list<string>
     */
    public function others(string $locale): array
    {
        return array_values(array_filter($this->all(), static fn (string $candidate): bool => $candidate !== $locale));
    }

    /**
     * The language an admin list or form works with: "?locale=de", or the default language.
     */
    public function fromRequest(Request $request): string
    {
        $locale = $request->query->getString('locale');

        return $this->isEnabled($locale) ? $locale : $this->default();
    }

    /**
     * Name of a language in that language, e.g. "Deutsch" for "de".
     */
    public function name(string $locale): string
    {
        $name = \extension_loaded('intl') ? (string) \Locale::getDisplayName(str_replace('-', '_', $locale), $locale) : '';

        return '' !== $name && $name !== $locale ? mb_convert_case(mb_substr($name, 0, 1), \MB_CASE_UPPER).mb_substr($name, 1) : strtoupper($locale);
    }

    public static function isValidCode(string $locale): bool
    {
        return 1 === preg_match('/^'.self::PATTERN.'$/', $locale);
    }
}
