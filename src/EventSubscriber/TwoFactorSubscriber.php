<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\TwoFactor\TwoFactorManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enforces the second step of the login.
 *
 * A user with two-step login who has only entered the password so far can
 * open nothing but the page that asks for the code (and log out). The rule
 * is "closed unless verified", so it also covers sessions that were open
 * before two-step login was switched on.
 *
 * Administrators who are required to use two-step login but have not set it
 * up are kept on the setup page in the same way.
 */
final class TwoFactorSubscriber
{
    /** Never held back: logging out, static files and the debug tools. */
    private const ALWAYS_ALLOWED = '#^/(logout$|assets/|uploads/|_wdt/|_profiler/)#';

    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorManager $twoFactor,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // After the firewall (8), so the user is known; before any controller runs.
    #[AsEventListener(priority: 6)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // Visitors without a session cannot be logged in: do not start one for them.
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || 1 === preg_match(self::ALWAYS_ALLOWED, $request->getPathInfo())) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $route = (string) $request->attributes->get('_route');

        if ($user->isTwoFactorEnabled()) {
            if (!$this->twoFactor->isVerified($user, $request->getSession()) && 'app_two_factor' !== $route) {
                $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_two_factor')));
            }
        } elseif ($this->twoFactor->mustSetUp($user) && !str_starts_with($route, 'admin_two_factor_')) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_two_factor_index')));
        }
    }
}
