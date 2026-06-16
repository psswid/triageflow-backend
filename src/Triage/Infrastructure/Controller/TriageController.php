<?php

declare(strict_types=1);

namespace App\Triage\Infrastructure\Controller;

use App\Triage\Application\Command\SubmitTriageCommand;
use App\Triage\Application\Command\SubmitTriageHandler;
use App\Triage\Application\Message\ProcessTriageMessage;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TriageController extends AbstractController
{
    public function __construct(
        private readonly SubmitTriageHandler $submitHandler,
        private readonly TriageSubmissionRepository $repository,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $messageBus,
        private readonly RateLimiterFactory $triageSubmitLimiter,
        private readonly RateLimiterFactory $triageAnswerLimiter,
    ) {}

    #[Route('/api/triage/submit', methods: ['POST'], name: 'api_triage_submit')]
    public function submit(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $violations = $this->validator->validate($data, new Assert\Collection([
            'initialDescription' => [
                new Assert\NotBlank(message: 'Initial symptom description is required.'),
                new Assert\Length(max: 500, maxMessage: 'Description must be at most {{ limit }} characters.'),
            ],
        ]));

        if (count($violations) > 0) {
            return $this->json([
                'errors' => array_map(fn($v) => [
                    'status' => '422',
                    'code' => 'VALIDATION_FAILED',
                    'title' => 'Validation Failed',
                    'detail' => $v->getPropertyPath() . ': ' . $v->getMessage(),
                ], iterator_to_array($violations)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $this->getUser();

        // ── Rate limiter ──
        $rateLimit = $this->triageSubmitLimiter->create($user->getId()->toRfc4122())->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter()->getTimestamp() - \time();
            $resetTimestamp = $rateLimit->getRetryAfter()->getTimestamp();

            return $this->json(
                [
                    'errors' => [[
                        'status' => '429',
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'title' => 'Too Many Requests',
                        'detail' => \sprintf(
                            'Rate limit exceeded. You can make 5 requests per minute. Retry in %d seconds.',
                            $retryAfter,
                        ),
                    ]],
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => (string) $retryAfter,
                    'X-Rate-Limit-Limit' => '5',
                    'X-Rate-Limit-Remaining' => '0',
                    'X-Rate-Limit-Reset' => (string) $resetTimestamp,
                ],
            );
        }
        // ── End rate limiter ──

        try {
            $submission = ($this->submitHandler)(new SubmitTriageCommand($data['initialDescription'], $user));
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'VALIDATION_FAILED',
                    'title' => 'Validation Failed',
                    'detail' => $e->getMessage(),
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'data' => [
                'id' => $submission->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'status' => $submission->getStatus()->value,
                    'submittedAt' => $submission->getSubmittedAt()->format('c'),
                ],
            ],
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/api/triage/{id}/answer', methods: ['POST'], name: 'api_triage_answer')]
    public function answer(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $violations = $this->validator->validate($data, new Assert\Collection([
            'content' => [
                new Assert\NotBlank(message: 'Answer content is required.'),
                new Assert\Length(max: 300, maxMessage: 'Answer must be at most {{ limit }} characters.'),
            ],
        ]));

        if (count($violations) > 0) {
            return $this->json([
                'errors' => array_map(fn($v) => [
                    'status' => '422',
                    'code' => 'VALIDATION_FAILED',
                    'title' => 'Validation Failed',
                    'detail' => $v->getPropertyPath() . ': ' . $v->getMessage(),
                ], iterator_to_array($violations)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $submission = $this->findSubmissionOr404($id);

        /** @var User $user */
        $user = $this->getUser();

        // ── Rate limiter ──
        $rateLimit = $this->triageAnswerLimiter->create($user->getId()->toRfc4122())->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter()->getTimestamp() - \time();

            return $this->json(
                [
                    'errors' => [[
                        'status' => '429',
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'title' => 'Too Many Requests',
                        'detail' => \sprintf(
                            'Rate limit exceeded. You can make 5 requests per minute. Retry in %d seconds.',
                            $retryAfter,
                        ),
                    ]],
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }
        // ── End rate limiter ──

        $this->assertOwnership($submission->getUser()->getId()->toRfc4122(), $user->getId()->toRfc4122());

        if ($submission->getStatus() !== TriageStatus::AwaitingAnswer) {
            return $this->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'INVALID_STATUS',
                    'title' => 'Submission not awaiting answer',
                    'detail' => sprintf(
                        'Submission is in status "%s", expected "awaiting_answer".',
                        $submission->getStatus()->value,
                    ),
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $submission->addUserAnswer($data['content']);
        $this->repository->save($submission);

        $this->messageBus->dispatch(new ProcessTriageMessage($submission->getId()));

        // Reload to get the state after the handler processed (sync in test)
        $submission = $this->repository->findById($submission->getId());

        return $this->json([
            'data' => [
                'id' => $submission->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'status' => $submission->getStatus()->value,
                ],
            ],
        ]);
    }

    #[Route('/api/triage/status/{id}', methods: ['GET'], name: 'api_triage_status')]
    public function status(string $id): JsonResponse
    {
        $submission = $this->findSubmissionOr404($id);

        /** @var User $user */
        $user = $this->getUser();
        $this->assertOwnership($submission->getUser()->getId()->toRfc4122(), $user->getId()->toRfc4122());

        $lastAssistantMessage = $this->extractLastAssistantMessage($submission->getConversationHistory());

        return $this->json([
            'data' => [
                'id' => $submission->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'status' => $submission->getStatus()->value,
                    'currentTurn' => $submission->getCurrentTurn(),
                    'lastAssistantMessage' => $lastAssistantMessage,
                ],
            ],
        ]);
    }

    #[Route('/api/triage/result/{id}', methods: ['GET'], name: 'api_triage_result')]
    public function result(string $id): JsonResponse
    {
        $submission = $this->findSubmissionOr404($id);

        /** @var User $user */
        $user = $this->getUser();
        $this->assertOwnership($submission->getUser()->getId()->toRfc4122(), $user->getId()->toRfc4122());

        $outcome = $submission->getOutcome();

        return $this->json([
            'data' => [
                'id' => $submission->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'status' => $submission->getStatus()->value,
                    'currentTurn' => $submission->getCurrentTurn(),
                    'outcome' => ($outcome && $outcome->getSpecialist() !== null) ? [
                        'specialist' => $outcome->getSpecialist(),
                        'urgency' => $outcome->getUrgency(),
                        'justification' => $outcome->getJustification(),
                    ] : null,
                    'conversationHistory' => $submission->getConversationHistory(),
                    'isSynthetic' => $submission->isSynthetic(),
                    'submittedAt' => $submission->getSubmittedAt()->format('c'),
                    'processedAt' => $submission->getProcessedAt()?->format('c'),
                ],
            ],
        ]);
    }

    #[Route('/api/triage/submissions', methods: ['GET'], name: 'api_triage_submissions')]
    public function submissions(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $submissions = $this->repository->findByUser($user->getId());

        return $this->json([
            'data' => array_map(fn(TriageSubmission $s) => [
                'id' => $s->getId()->toRfc4122(),
                'type' => 'triage_submission',
                'attributes' => [
                    'status' => $s->getStatus()->value,
                    'isSynthetic' => $s->isSynthetic(),
                    'currentTurn' => $s->getCurrentTurn(),
                    'submittedAt' => $s->getSubmittedAt()->format('c'),
                    'processedAt' => $s->getProcessedAt()?->format('c'),
                    'outcome' => $s->getOutcome()?->getSpecialist() !== null ? [
                        'specialist' => $s->getOutcome()->getSpecialist(),
                        'urgency' => $s->getOutcome()->getUrgency(),
                        'justification' => $s->getOutcome()->getJustification(),
                    ] : null,
                ],
            ], $submissions),
        ]);
    }

    private function findSubmissionOr404(string $id): \App\Triage\Domain\Entity\TriageSubmission
    {
        $uuid = \Symfony\Component\Uid\Uuid::fromString($id);
        $submission = $this->repository->findById($uuid);

        if ($submission === null) {
            throw new NotFoundHttpException(sprintf('Triage Submission "%s" not found.', $id));
        }

        return $submission;
    }

    private function assertOwnership(string $submissionUserId, string $currentUserId): void
    {
        if ($submissionUserId !== $currentUserId) {
            throw new AccessDeniedHttpException('You do not have access to this Triage Submission.');
        }
    }

    /**
     * @param array<int, array{type: string, content: string, timestamp: string}> $history
     */
    private function extractLastAssistantMessage(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['type'] === 'question' || $history[$i]['type'] === 'result') {
                return $history[$i]['content'];
            }
        }

        return null;
    }
}
