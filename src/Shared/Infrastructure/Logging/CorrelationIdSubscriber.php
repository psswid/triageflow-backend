<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Injects a correlation ID into the log context for every HTTP request
 * and echoes it back as an X-Correlation-Id response header.
 *
 * The correlation ID is set on CorrelationIdProcessor so that Monolog
 * attaches it to every log line emitted during request handling.
 *
 * Worker/CLI context (Messenger consumers) are handled separately by
 * each handler generating its own correlation ID.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class CorrelationIdSubscriber
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = Uuid::v4()->toRfc4122();
        CorrelationIdProcessor::setCorrelationId($correlationId);
        $event->getRequest()->attributes->set('_correlation_id', $correlationId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get('_correlation_id');
        if ($correlationId !== null && $correlationId !== '') {
            $event->getResponse()->headers->set('X-Correlation-Id', (string) $correlationId);
        }
    }
}
