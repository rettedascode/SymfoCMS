<?php

namespace App\EventSubscriber;

use App\Service\RedirectManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Redirects are a last resort: they are only looked up when a request would
 * otherwise end in "page not found". Existing pages are therefore never
 * hidden by a redirect, and normal requests cost no extra database query.
 */
final class RedirectSubscriber
{
    public function __construct(private readonly RedirectManager $redirects)
    {
    }

    #[AsEventListener(priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethodSafe()) {
            return;
        }

        $target = $this->redirects->match($request->getPathInfo());
        if (null === $target) {
            return;
        }

        // Local targets are stored without the folder the site may be installed in.
        $url = str_starts_with($target, '/') ? $request->getBaseUrl().$target : $target;
        $event->setResponse(new RedirectResponse($url, Response::HTTP_MOVED_PERMANENTLY));
    }
}
