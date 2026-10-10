<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Page;
use App\Entity\Post;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates the addresses of the public website in the right language.
 *
 * Every public route exists twice: "app_blog" for the default language
 * ("/blog") and "app_blog.localized" for all others ("/de/blog"). This class
 * picks the right one, so templates and services never deal with the prefix.
 */
final class SiteUrls
{
    /** Request attribute holding the language of the public page being shown. */
    public const LOCALE_ATTRIBUTE = '_content_locale';
    public const LOCALIZED_SUFFIX = '.localized';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ContentLocales $locales,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Language of the public page being shown, or the default language (e.g. in the admin area).
     */
    public function currentLocale(): string
    {
        $locale = $this->requestStack->getMainRequest()?->attributes->get(self::LOCALE_ATTRIBUTE);

        return \is_string($locale) && $this->locales->isEnabled($locale) ? $locale : $this->locales->default();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $route, array $parameters = [], ?string $locale = null, bool $absolute = false): string
    {
        $locale ??= $this->currentLocale();
        $type = $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;

        if ($this->locales->isDefault($locale) || !$this->locales->isEnabled($locale)) {
            return $this->urlGenerator->generate($route, $parameters, $type);
        }

        return $this->urlGenerator->generate($route.self::LOCALIZED_SUFFIX, ['_locale' => $locale] + $parameters, $type);
    }

    /**
     * Public address of a page or post.
     */
    public function content(Content $content, bool $absolute = false): string
    {
        if ($content instanceof Post) {
            return $this->generate('app_blog_post', ['slug' => $content->getSlug()], $content->getLocale(), $absolute);
        }

        // A published homepage lives at "/" (or "/de"); as a draft it is previewed under its own address.
        if ($content instanceof Page && $content->isHomepage() && $content->isPublic()) {
            return $this->generate('app_home', [], $content->getLocale(), $absolute);
        }

        // Child pages carry the slugs of their parents in front of their own: "/about/team".
        return $this->generate('app_page', ['slug' => $content instanceof Page ? $content->getPath() : $content->getSlug()], $content->getLocale(), $absolute);
    }
}
