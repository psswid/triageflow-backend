<?php

declare(strict_types=1);

namespace App\Triage\Application\Message;

use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processes a Triage Submission that has a user answer awaiting AI analysis.
 *
 * The controller adds the user's answer to the submission (via addUserAnswer)
 * BEFORE dispatching this message. The handler loads the submission, extracts
 * the latest answer from the conversation history, calls the AI analyzer, and
 * updates the submission accordingly.
 *
 * Idempotency: the handler safely no-ops when the submission is already in a
 * terminal state (Completed, Failed), is Pending with only the initial
 * description, or is AwaitingAnswer with no answer yet.
 */
#[AsMessageHandler]
final readonly class ProcessTriageMessageHandler
{
    public function __construct(
        private TriageSubmissionRepository $repository,
        private TriageAnalyzerInterface $analyzer,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @throws \RuntimeException When the submission is not found
     */
    public function __invoke(ProcessTriageMessage $message): void
    {
        $submission = $this->repository->findById($message->submissionId);

        if ($submission === null) {
            throw new \RuntimeException(
                sprintf(
                    'Triage Submission not found for ID "%s".',
                    $message->submissionId->toRfc4122(),
                ),
            );
        }

        // No-op: already in a terminal state
        if ($submission->getStatus() === TriageStatus::Completed
            || $submission->getStatus() === TriageStatus::Failed) {
            return;
        }

        // No-op: Pending submission has only the initial description — nothing to process
        if ($submission->getStatus() === TriageStatus::Pending) {
            return;
        }

        // Extract the last user answer from the conversation history.
        $answerContent = $this->extractLastAnswer($submission->getConversationHistory());
        if ($answerContent === null) {
            // AwaitingAnswer with no answer yet (e.g., dispatched from initial submit)
            return;
        }

        try {
            $result = $this->analyzer->analyzeFollowUp(
                $answerContent,
                $submission->getConversationHistory(),
                $submission->getCurrentTurn(),
            );
        } catch (TriageAnalysisFailedException) {
            $submission->markFailed();
            $this->entityManager->flush();

            return;
        }

        if ($result['type'] === 'result') {
            $outcome = TriageOutcome::create(
                specialist: $result['specialist'],
                urgency: $result['urgency'],
                justification: $result['justification'],
            );
            $submission->completeWithOutcome($outcome);
        } else {
            $submission->addAiQuestion($result['content']);
        }

        $this->entityManager->flush();
    }

    /**
     * Scan the conversation history backwards for the most recent
     * answer entry and return its content, or null if none found.
     *
     * @param array<int, array{type: string, content: string, timestamp: string}> $history
     */
    private function extractLastAnswer(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['type'] === 'answer') {
                return $history[$i]['content'];
            }
        }

        return null;
    }
}
