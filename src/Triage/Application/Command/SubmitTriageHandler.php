<?php

declare(strict_types=1);

namespace App\Triage\Application\Command;

use App\Triage\Application\Message\ProcessTriageMessage;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Creates a new Triage Submission, persists it, runs the initial AI analysis,
 * and dispatches async follow-up processing when the AI returns a question.
 *
 * Flow:
 *   1. Validate the initial description length (max 500 chars).
 *   2. Create the submission entity via the named constructor.
 *   3. Persist via the repository (so it exists before AI call begins).
 *   4. Call TriageAnalyzer for the initial analysis.
 *   5. If the AI returns a result → complete the submission immediately.
 *   6. If the AI returns a question → add it to the conversation and
 *      dispatch a ProcessTriageMessage for async follow-up.
 *   7. On analysis failure → mark the submission as failed.
 */
final readonly class SubmitTriageHandler
{
    private const int MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        private TriageSubmissionRepository $repository,
        private TriageAnalyzerInterface $analyzer,
        private EntityManagerInterface $entityManager,
        private ?MessageBusInterface $messageBus = null,
    ) {}

    /**
     * @throws \InvalidArgumentException When the description exceeds 500 characters
     */
    public function __invoke(SubmitTriageCommand $command): TriageSubmission
    {
        $this->validateDescription($command->initialDescription);

        $submission = TriageSubmission::submit($command->user, $command->initialDescription);

        // Persist before AI call so the submission exists in the database
        // even if the AI call takes time or fails.
        $this->repository->save($submission);

        try {
            $result = $this->analyzer->analyzeInitial($command->initialDescription);
        } catch (TriageAnalysisFailedException) {
            $submission->markFailed();
            $this->entityManager->flush();

            return $submission;
        }

        if ($result['type'] === 'result') {
            $outcome = TriageOutcome::create(
                specialist: $result['specialist'],
                urgency: $result['urgency'],
                justification: $result['justification'],
            );
            $submission->completeWithOutcome($outcome);
            $this->entityManager->flush();
        } else {
            // AI returned a follow-up question — record it and dispatch
            // async processing for subsequent answers.
            $submission->addAiQuestion($result['content']);
            $this->entityManager->flush();

            $this->messageBus?->dispatch(
                new ProcessTriageMessage($submission->getId()),
            );
        }

        return $submission;
    }

    /**
     * @throws \InvalidArgumentException When the description exceeds the character limit
     */
    private function validateDescription(string $description): void
    {
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Initial symptom description exceeds maximum length of %d characters (got %d).',
                    self::MAX_DESCRIPTION_LENGTH,
                    mb_strlen($description),
                ),
            );
        }
    }
}
