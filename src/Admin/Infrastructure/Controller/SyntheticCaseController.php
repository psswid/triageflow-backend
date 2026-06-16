<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Controller;

use App\Synthetic\Application\Command\GenerateSyntheticCaseCommand;
use App\Synthetic\Application\Command\GenerateSyntheticCaseHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin endpoint to manually trigger synthetic case generation.
 *
 * POST /api/admin/synthetic/generate
 *
 * The scheduler also triggers generation automatically every 60s.
 * This endpoint provides a manual trigger for the dashboard button.
 */
final class SyntheticCaseController extends AbstractController
{
    public function __construct(
        private readonly GenerateSyntheticCaseHandler $handler,
        private readonly RateLimiterFactory $syntheticGenerateLimiter,
    ) {}

    #[Route('/api/admin/synthetic/generate', methods: ['POST'], name: 'api_admin_synthetic_generate')]
    public function generate(): JsonResponse
    {
        // ── Rate limiter ──
        $rateLimit = $this->syntheticGenerateLimiter->create('admin')->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter()->getTimestamp() - \time();

            return $this->json(
                [
                    'errors' => [[
                        'status' => '429',
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'title' => 'Too Many Requests',
                        'detail' => \sprintf(
                            'Rate limit exceeded. You can make 2 requests per minute. Retry in %d seconds.',
                            $retryAfter,
                        ),
                    ]],
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }
        // ── End rate limiter ──

        try {
            $submission = ($this->handler)(new GenerateSyntheticCaseCommand());
        } catch (\RuntimeException $e) {
            return $this->json([
                'errors' => [[
                    'status' => '500',
                    'code' => 'GENERATION_FAILED',
                    'title' => 'Synthetic case generation failed',
                    'detail' => $e->getMessage(),
                ]],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'data' => [
                'id' => $submission->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'isSynthetic' => $submission->isSynthetic(),
                    'status' => $submission->getStatus()->value,
                    'submittedAt' => $submission->getSubmittedAt()->format('c'),
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}
