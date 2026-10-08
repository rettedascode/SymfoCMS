<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists the interface languages enabled in config/packages/translation.yaml.
 */
final class LocaleChoices
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return $this->enabledLocales;
    }

    /**
     * @return array<string, string> language name in its own language => locale code
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->enabledLocales as $locale) {
            $name = \extension_loaded('intl') ? \Locale::getDisplayLanguage($locale, $locale) : '';
            $choices['' !== $name && $name !== $locale ? mb_convert_case($name, \MB_CASE_TITLE) : strtoupper($locale)] = $locale;
        }

        return $choices;
    }
}
