<?php

namespace App\EventSubscriber;

use App\Service\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Everything the read API needs around its controller: the switch in the
 * settings, errors as JSON and the headers for browsers and caches.
 */
final class ApiSubscriber
{
    public const ENABLED_SETTING = 'api.enabled';
    private const PREFIX = '/api/';

    public function __construct(
        private readonly Settings $settings,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {
    }

    /**
     * A disabled API looks exactly like an address that does not exist.
     */
    #[AsEventListener(priority: 40)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->isApi($event) && true !== $this->settings->get(self::ENABLED_SETTING)) {
            $event->setResponse($this->error(Response::HTTP_NOT_FOUND, 'Not found.'));
        }
    }

    #[AsEventListener(priority: 20)]
    public function onException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApi($event)) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            // Only messages written for API users are passed on.
            $message = Response::HTTP_BAD_REQUEST === $status ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Error').'.';
            $event->setResponse($this->error($status, $message));
        } elseif (!$this->debug) {
            // While developing, the detailed error page of Symfony is more useful.
            $event->setResponse($this->error(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal server error.'));
        }
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isApi($event)) {
            return;
        }

        $response = $event->getResponse();
        // Public, read-only data: any website may fetch it from the browser.
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('X-Robots-Tag', 'noindex');
        if ($response->isSuccessful()) {
            $response->setPublic();
            $response->setMaxAge(60);
        }
    }

    private function isApi(RequestEvent|ExceptionEvent|ResponseEvent $event): bool
    {
        return str_starts_with($event->getRequest()->getPathInfo(), self::PREFIX);
    }

    private function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['status' => $status, 'message' => $message]], $status);
    }
}
