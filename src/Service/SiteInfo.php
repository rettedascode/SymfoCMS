<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Name and tagline of the website. They are edited under "Settings"; until
 * then the values of APP_SITE_NAME and APP_SITE_TAGLINE from .env apply.
 */
final class SiteInfo
{
    public const NAME_SETTING = 'site.name';
    public const TAGLINE_SETTING = 'site.tagline';

    public function __construct(
        private readonly Settings $settings,
        #[Autowire(param: 'app.site_name')]
        private readonly string $defaultName,
        #[Autowire(param: 'app.site_tagline')]
        private readonly string $defaultTagline,
    ) {
    }

    public function name(): string
    {
        $name = $this->settings->get(self::NAME_SETTING);

        return \is_string($name) && '' !== $name ? $name : $this->defaultName;
    }

    public function tagline(): string
    {
        $tagline = $this->settings->get(self::TAGLINE_SETTING);

        return \is_string($tagline) ? $tagline : $this->defaultTagline;
    }

    public function save(string $name, string $tagline): void
    {
        $this->settings->set(self::NAME_SETTING, trim($name));
        $this->settings->set(self::TAGLINE_SETTING, trim($tagline));
    }
}
