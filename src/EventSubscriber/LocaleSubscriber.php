<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\ContentLocales;
use App\Service\LocaleChoices;
use App\Service\SiteUrls;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Decides which language a request is answered in.
 *
 * - Admin area: the language the logged-in user chose for the interface.
 * - Login form: the language of the browser.
 * - Public website: the language of the content, taken from the URL prefix
 *   ("/de/...") or the default content language when there is no prefix.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LocaleChoices $interfaceLocales,
        private readonly ContentLocales $contentLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs after the router (32) and the firewall (8), so route and user are known.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $route = (string) $request->attributes->get('_route', '');

        if (str_starts_with($path, '/admin') || 'app_two_factor' === $route) {
            $user = $this->security->getUser();
            $this->useInterfaceLocale($request, $user instanceof User ? $user->getLocale() : null);
        } elseif ('app_login' === $route || str_starts_with($path, '/install')) {
            $this->useInterfaceLocale($request, $request->getPreferredLanguage($this->interfaceLocales->codes()));
        } elseif (str_starts_with($route, 'app_')) {
            $this->useContentLocale($event, $request, $route);
        }
    }

    private function useInterfaceLocale(Request $request, ?string $locale): void
    {
        if (null !== $locale && \in_array($locale, $this->interfaceLocales->codes(), true)) {
            $request->setLocale($locale);
            $this->localeSwitcher->setLocale($locale);
        }
    }

    private function useContentLocale(RequestEvent $event, Request $request, string $route): void
    {
        $default = $this->contentLocales->default();
        $locale = $default;

        if (str_ends_with($route, SiteUrls::LOCALIZED_SUFFIX)) {
            $prefix = (string) $request->attributes->get('_locale');
            $isLocalizedHome = 'app_home'.SiteUrls::LOCALIZED_SUFFIX === $route;

            if ($this->contentLocales->isEnabled($prefix) && $prefix !== $default) {
                $locale = $prefix;
            } elseif ($prefix === $default && !$isLocalizedHome) {
                // "/en/about" on a site whose default language is English: the canonical address has no prefix.
                $target = substr($request->getPathInfo(), \strlen('/'.$prefix)) ?: '/';
                $query = $request->getQueryString();
                $event->setResponse(new RedirectResponse($request->getBaseUrl().$target.(null !== $query ? '?'.$query : ''), Response::HTTP_MOVED_PERMANENTLY));

                return;
            } elseif ('app_page'.SiteUrls::LOCALIZED_SUFFIX === $route) {
                // "/uk/offices" where "uk" is no language of the website: the child page "offices" of the page "uk".
                $request->attributes->set('slug', $prefix.'/'.$request->attributes->get('slug'));
            } elseif (!$isLocalizedHome) {
                throw new NotFoundHttpException(\sprintf('"%s" is not a language of this website.', $prefix));
            }
            // "/xy" may also be a page whose address happens to look like a language code: the controller decides.
        }

        $request->attributes->set(SiteUrls::LOCALE_ATTRIBUTE, $locale);
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }
}
