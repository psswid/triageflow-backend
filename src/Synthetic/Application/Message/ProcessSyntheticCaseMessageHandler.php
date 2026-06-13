<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Message;

use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Processes a synthetic case submission by running the AI triage analysis.
 *
 * The handler is invoked after GenerateSyntheticCaseHandler creates the
 * submission with a pre-generated symptom. This handler:
 *   1. Loads the submission from the repository
 *   2. Extracts the symptom description from the conversation history
 *   3. Calls TriageAnalyzer::analyzeInitial() for AI triage analysis
 *   4. If result → completes the submission with outcome
 *   5. If question → records it + dispatches ProcessSyntheticTurnMessage (10s delay)
 *   6. On analysis failure → marks the submission as failed
 */
#[AsMessageHandler]
final readonly class ProcessSyntheticCaseMessageHandler
{
    public function __construct(
        private TriageSubmissionRepository $repository,
        private TriageAnalyzerInterface $analyzer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ?MessageBusInterface $messageBus = null,
    ) {}

    public function __invoke(ProcessSyntheticCaseMessage $message): void
    {
        $startTime = microtime(true);
        $correlationId = Uuid::v4()->toRfc4122();
        CorrelationIdProcessor::setCorrelationId($correlationId);
        $status = 'noop_already_terminal';

        try {
            $submission = $this->repository->findById($message->submissionId);

            if ($submission === null) {
                throw new \RuntimeException(
                    sprintf(
                        'Triage Submission not found for ID "%s".',
                        $message->submissionId->toRfc4122(),
                    ),
                );
            }

            // No-op if already terminal (defensive — shouldn't happen in normal flow)
            if ($submission->getStatus() === TriageStatus::Completed
                || $submission->getStatus() === TriageStatus::Failed) {
                $status = 'noop_already_terminal';

                return;
            }

            // Extract the symptom description from the conversation history
            $symptomDescription = $this->extractInitialDescription(
                $submission->getConversationHistory(),
            );

            try {
                $result = $this->analyzer->analyzeInitial($symptomDescription);
            } catch (TriageAnalysisFailedException) {
                $submission->markFailed();
                $this->entityManager->flush();
                $status = 'analysis_failed';

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
                // AI returned a follow-up question — record it and dispatch
                // the synthetic answer processing with a 10-second delay.
                $submission->addAiQuestion($result['content']);

                $this->messageBus?->dispatch(
                    (new Envelope(new ProcessSyntheticTurnMessage($submission->getId())))
                        ->with(new DelayStamp(10000)),
                );
            }

            $this->entityManager->flush();
            $status = 'success';
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $this->logger->info('Message handler completed', [
                'message_class' => ProcessSyntheticCaseMessage::class,
                'submission_id' => $message->submissionId->toRfc4122(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'status' => $status,
            ]);
        }
    }

    /**
     * Scan the conversation history for the initial description entry.
     *
     * @param array<int, array{type: string, content: string, timestamp: string}> $history
     */
    private function extractInitialDescription(array $history): string
    {
        foreach ($history as $entry) {
            if ($entry['type'] === 'initial_description') {
                return $entry['content'];
            }
        }

        throw new \RuntimeException(
            'Submission conversation history is missing the initial symptom description.',
        );
    }
}
