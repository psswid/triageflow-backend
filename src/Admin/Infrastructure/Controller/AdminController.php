<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Controller;

use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Uid\Uuid;

final class AdminController extends AbstractController
{
    public function __construct(
        private readonly TriageSubmissionRepository $triageRepository,
        private readonly UserRepository $userRepository,
        private readonly Connection $dbal,
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

    #[Route('/api/admin/failed-messages', methods: ['GET'], name: 'api_admin_failed_messages')]
    public function failedMessages(): JsonResponse
    {
        try {
            $rows = $this->dbal->fetchAllAssociative(
                "SELECT id, body, headers, created_at FROM messenger_messages WHERE queue_name = 'failed' ORDER BY created_at DESC"
            );
        } catch (DBALException) {
            return $this->json(['data' => []], 200);
        }

        return $this->json([
            'data' => array_map(fn(array $row) => $this->serializeFailedMessage($row), $rows),
        ]);
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeFailedMessage(array $row): array
    {
        $headers = json_decode($row['headers'], true) ?? [];
        $body = $row['body'];
        $decoded = \json_decode($body, true);
        $description = \is_array($decoded) ? ($decoded['description'] ?? null) : null;
        $preview = \mb_substr(\trim((string) ($description ?? $body)), 0, 120);

        return [
            'id' => (int) $row['id'],
            'type' => 'failed_message',
            'attributes' => [
                'messageId' => (int) $row['id'],
                'type' => $headers['X-Message-Class'] ?? 'Unknown',
                'failedAt' => (new \DateTimeImmutable($row['created_at']))->format('c'),
                'error' => $headers['X-Failed-Description'] ?? 'Unknown error',
                'preview' => $preview,
            ],
        ];
    }
}
