<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\LocaleChoices;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The public website always uses the site language (APP_LOCALE). The admin
 * area uses the language of the logged-in user, and the login form follows
 * the language of the browser.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly LocaleChoices $localeChoices,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs after the firewall (priority 8), so the user is known.
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $locales = $this->localeChoices->codes();

        if (str_starts_with($path, '/admin')) {
            $user = $this->security->getUser();
            $locale = $user instanceof User ? $user->getLocale() : null;
        } elseif ('/login' === $path) {
            $locale = $request->getPreferredLanguage($locales);
        } else {
            return;
        }

        if (null !== $locale && \in_array($locale, $locales, true)) {
            $request->setLocale($locale);
            $this->localeSwitcher->setLocale($locale);
        }
    }
}
