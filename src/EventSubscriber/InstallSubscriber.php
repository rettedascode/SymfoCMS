<?php

namespace App\EventSubscriber;

use App\Install\InstallState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Sends every visitor of a website that is not set up yet to the setup wizard.
 */
final class InstallSubscriber
{
    /** Addresses that must stay reachable during the setup: the wizard itself, assets and the debug tools. */
    private const ALLOWED = '#^/(install(/|$)|assets/|_wdt/|_profiler/)#';

    public function __construct(private readonly InstallState $state)
    {
    }

    // Runs before the router, so even unknown addresses lead to the wizard.
    #[AsEventListener(priority: 128)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->state->isInstalled()) {
            return;
        }

        $request = $event->getRequest();
        if (1 !== preg_match(self::ALLOWED, $request->getPathInfo())) {
            $event->setResponse(new RedirectResponse($request->getBaseUrl().'/install'));
        }
    }
}
