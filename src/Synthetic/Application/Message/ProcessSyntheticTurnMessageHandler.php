<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Message;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use App\Synthetic\Application\Service\SyntheticSystemPrompt;
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
 * Generates a synthetic patient answer and continues the triage interview.
 *
 * Flow:
 *   1. Load submission, no-op if already terminal
 *   2. Extract the last AI question from conversation history
 *   3. Call OpenRouter to generate a realistic patient answer
 *   4. Record the answer via addUserAnswer()
 *   5. Call TriageAnalyzer for follow-up analysis
 *   6. If AI returns a result → complete the submission
 *   7. If AI returns a question → addAiQuestion() + dispatch next turn (delayed 10s)
 */
#[AsMessageHandler]
final readonly class ProcessSyntheticTurnMessageHandler
{
    public function __construct(
        private TriageSubmissionRepository $repository,
        private OpenRouterClientInterface $openRouter,
        private SyntheticSystemPrompt $syntheticPrompt,
        private TriageAnalyzerInterface $analyzer,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ProcessSyntheticTurnMessage $message): void
    {
        $startTime = microtime(true);
        $correlationId = Uuid::v4()->toRfc4122();
        CorrelationIdProcessor::setCorrelationId($correlationId);
        $status = 'noop';

        try {
            $submission = $this->repository->findById($message->submissionId);

            if ($submission === null) {
                throw new \RuntimeException(sprintf(
                    'Submission "%s" not found.',
                    $message->submissionId->toRfc4122(),
                ));
            }

            // No-op if already terminal
            if ($submission->getStatus() === TriageStatus::Completed
                || $submission->getStatus() === TriageStatus::Failed) {
                $status = 'noop_already_terminal';

                return;
            }

            // No-op if not awaiting answer (shouldn't happen in normal flow)
            if ($submission->getStatus() !== TriageStatus::AwaitingAnswer) {
                $status = 'noop_not_awaiting_answer';

                return;
            }

            // Extract the last AI question from conversation history
            $lastQuestion = $this->extractLastQuestion($submission->getConversationHistory());
            if ($lastQuestion === null) {
                $status = 'noop_no_question';

                return;
            }

            // Generate a realistic patient answer via OpenRouter
            $patientAnswer = $this->openRouter->chat([
                ['role' => 'system', 'content' => $this->syntheticPrompt->getPatientAnswerPrompt()],
                ['role' => 'user', 'content' => "The doctor asks: {$lastQuestion}\n\nAnswer as the patient:"],
            ]);

            $patientAnswer = trim($patientAnswer);
            if ($patientAnswer === '') {
                $submission->markFailed();
                $this->entityManager->flush();
                $status = 'failed_empty_answer';

                return;
            }

            // Record the patient's answer (this transitions status back to Processing)
            $submission->addUserAnswer($patientAnswer);
            $this->entityManager->flush();

            // Run the AI follow-up analysis
            try {
                $result = $this->analyzer->analyzeFollowUp(
                    $patientAnswer,
                    $submission->getConversationHistory(),
                    $submission->getCurrentTurn(),
                );
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
                $this->entityManager->flush();
                $status = 'success_result';
            } else {
                // AI asked another question — record it and schedule next turn
                $submission->addAiQuestion($result['content']);
                $this->entityManager->flush();

                // Dispatch next synthetic turn with 10-second delay
                $this->messageBus->dispatch(
                    (new Envelope(new ProcessSyntheticTurnMessage($submission->getId())))
                        ->with(new DelayStamp(10000)),
                );
                $status = 'success_question';
            }
        } catch (\Throwable $e) {
            $status = 'error';
            throw $e;
        } finally {
            $this->logger->info('Message handler completed', [
                'message_class' => ProcessSyntheticTurnMessage::class,
                'submission_id' => $message->submissionId->toRfc4122(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
                'status' => $status,
            ]);
        }
    }

    /**
     * @param array<int, array{type: string, content: string, timestamp: string}> $history
     */
    private function extractLastQuestion(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['type'] === 'question') {
                return $history[$i]['content'];
            }
        }
        return null;
    }
}
