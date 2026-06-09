<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Controller;

use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class AdminController extends AbstractController
{
    public function __construct(
        private readonly TriageSubmissionRepository $triageRepository,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/api/admin/stats', methods: ['GET'], name: 'api_admin_stats')]
    public function stats(): JsonResponse
    {
        $total = $this->triageRepository->countTotal();
        $synthetic = $this->triageRepository->countSynthetic();
        $byStatus = $this->triageRepository->countByStatus();

        return $this->json([
            'data' => [
                'total' => $total,
                'synthetic' => $synthetic,
                'pending' => $byStatus['pending'] ?? 0,
                'processing' => $byStatus['processing'] ?? 0,
                'completed' => $byStatus['completed'] ?? 0,
                'failed' => $byStatus['failed'] ?? 0,
                'avgProcessingDuration' => $this->triageRepository->avgProcessingDuration(),
                'bySpecialist' => array_map(
                    fn(string $specialist, int $count) => ['specialist' => $specialist, 'count' => $count],
                    array_keys($this->triageRepository->countBySpecialist()),
                    array_values($this->triageRepository->countBySpecialist()),
                ),
                'byUrgency' => array_map(
                    fn(string $urgency, int $count) => ['urgency' => $urgency, 'count' => $count],
                    array_keys($this->triageRepository->countByUrgency()),
                    array_values($this->triageRepository->countByUrgency()),
                ),
            ],
        ]);
    }

    #[Route('/api/admin/submissions', methods: ['GET'], name: 'api_admin_submissions')]
    public function submissions(): JsonResponse
    {
        $submissions = $this->triageRepository->findAllOrdered();

        return $this->json([
            'data' => array_map(fn(TriageSubmission $s) => $this->serializeSubmission($s), $submissions),
        ]);
    }

    #[Route('/api/admin/submissions/{id}', methods: ['GET'], name: 'api_admin_submission_detail')]
    public function submissionDetail(string $id): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $submission = $this->triageRepository->findById($uuid);

        if ($submission === null) {
            throw new NotFoundHttpException(sprintf('Triage Submission "%s" not found.', $id));
        }

        return $this->json([
            'data' => $this->serializeSubmission($submission, includeHistory: true),
        ]);
    }

    #[Route('/api/admin/users', methods: ['GET'], name: 'api_admin_users')]
    public function users(): JsonResponse
    {
        $users = $this->userRepository->findAll();

        return $this->json([
            'data' => array_map(fn(User $u) => [
                'id' => $u->getId()->toRfc4122(),
                'type' => 'user',
                'attributes' => [
                    'email' => $u->getEmail(),
                    'roles' => $u->getRoles(),
                    'createdAt' => $u->getCreatedAt()->format('c'),
                ],
            ], $users),
        ]);
    }

    #[Route('/api/admin/synthetic/generate', methods: ['POST'], name: 'api_admin_synthetic_generate')]
    public function generateSynthetic(): JsonResponse
    {
        // DEFERRED: Full implementation planned with AI integration (Issue #5 or later).
        return $this->json([
            'data' => [
                'message' => 'Synthetic case generation not yet implemented.',
            ],
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    #[Route('/api/admin/users/{id}/impersonate', methods: ['POST'], name: 'api_admin_impersonate')]
    public function impersonate(string $id): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $user = $this->userRepository->findById($uuid);

        if ($user === null) {
            throw new NotFoundHttpException(sprintf('User "%s" not found.', $id));
        }

        // Generate a JWT token for the impersonated user.
        // Reuses the same login endpoint logic.
        return $this->json([
            'data' => [
                'token' => '', // DEFERRED: Token generation will use JWT helper
                'impersonated' => $user->getEmail(),
            ],
        ], Response::HTTP_NOT_IMPLEMENTED);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubmission(TriageSubmission $submission, bool $includeHistory = false): array
    {
        $outcome = $submission->getOutcome();
        $data = [
            'id' => $submission->getId()->toRfc4122(),
            'type' => 'triage_submission',
            'attributes' => [
                'status' => $submission->getStatus()->value,
                'isSynthetic' => $submission->isSynthetic(),
                'currentTurn' => $submission->getCurrentTurn(),
                'processingDuration' => $submission->getProcessingDuration(),
                'submittedAt' => $submission->getSubmittedAt()->format('c'),
                'processedAt' => $submission->getProcessedAt()?->format('c'),
                'userEmail' => $submission->getUser()->getEmail(),
                'userId' => $submission->getUser()->getId()->toRfc4122(),
                'outcome' => $outcome !== null && $outcome->getSpecialist() !== null ? [
                    'specialist' => $outcome->getSpecialist(),
                    'urgency' => $outcome->getUrgency(),
                    'justification' => $outcome->getJustification(),
                ] : null,
            ],
        ];

        if ($includeHistory) {
            $data['attributes']['conversationHistory'] = $submission->getConversationHistory();
        }

        return $data;
    }
}
