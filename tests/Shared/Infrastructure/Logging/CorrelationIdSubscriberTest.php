<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use App\Shared\Infrastructure\Logging\CorrelationIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationIdSubscriberTest extends TestCase
{
    private HttpKernelInterface $kernel;
    private CorrelationIdSubscriber $subscriber;

    protected function setUp(): void
    {
        // Reset static state before each test
        CorrelationIdProcessor::setCorrelationId('');

        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->subscriber = new CorrelationIdSubscriber();
    }

    public function testOnKernelRequestGeneratesUuidAndSetsProcessor(): void
    {
        $request = new Request();
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber->onKernelRequest($event);

        $correlationId = $request->attributes->get('_correlation_id');
        $this->assertNotNull($correlationId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $correlationId,
            'Expected a valid UUID v4',
        );
    }

    public function testOnKernelRequestSkipsSubRequests(): void
    {
        $request = new Request();
        $event = new RequestEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $this->subscriber->onKernelRequest($event);

        $this->assertNull($request->attributes->get('_correlation_id'));
    }

    public function testOnKernelResponseAddsHeaderWhenCorrelationIdPresent(): void
    {
        $request = new Request();
        $request->attributes->set('_correlation_id', 'test-uuid-1234');
        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertSame('test-uuid-1234', $response->headers->get('X-Correlation-Id'));
    }

    public function testOnKernelResponseSkipsSubRequests(): void
    {
        $request = new Request();
        $request->attributes->set('_correlation_id', 'test-uuid-1234');
        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::SUB_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertNull($response->headers->get('X-Correlation-Id'));
    }

    public function testOnKernelResponseSkipsHeaderWhenNoCorrelationId(): void
    {
        $request = new Request();
        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertNull($response->headers->get('X-Correlation-Id'));
    }

    public function testOnKernelResponseSkipsHeaderWhenCorrelationIdIsEmpty(): void
    {
        $request = new Request();
        $request->attributes->set('_correlation_id', '');
        $response = new Response();
        $event = new ResponseEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $this->subscriber->onKernelResponse($event);

        $this->assertNull($response->headers->get('X-Correlation-Id'));
    }
}
