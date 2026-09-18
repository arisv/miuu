<?php

namespace App\EventSubscriber;

use App\Exception\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns any exception under /api/ into {"error": {code, message, details?}}. Runs after the
 * security ExceptionListener (priority 1) so access denials are already HTTP exceptions here.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'too_many_requests',
    ];

    public function __construct(#[Autowire(param: 'kernel.debug')] private bool $debug)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $e = $event->getThrowable();
        $headers = [];
        if ($e instanceof ApiException) {
            $status = $e->getStatusCode();
            $body = ['code' => $e->getErrorCode(), 'message' => $e->getMessage()];
            if ($e->getDetails()) {
                $body['details'] = $e->getDetails();
            }
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $body = ['code' => self::CODES[$status] ?? 'http_error', 'message' => $e->getMessage() ?: (self::CODES[$status] ?? 'Error')];
        } else {
            $status = 500;
            $body = ['code' => 'internal_error', 'message' => $this->debug ? $e->getMessage() : 'Internal server error'];
        }
        $event->setResponse(new JsonResponse(['error' => $body], $status, $headers));
    }
}
