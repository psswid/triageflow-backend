<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Command;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Synthetic\Application\Message\ProcessSyntheticTurnMessage;
use App\Synthetic\Application\Service\SyntheticSystemPrompt;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Generates one synthetic triage case end-to-end.
 *
 * Flow:
 *   1. Resolve the system user (UUID 00000000-...)
 *   2. Call OpenRouter to generate a realistic symptom description
 *   3. Create TriageSubmission::create(systemUser, symptom, isSynthetic: true)
 *   4. Run initial AI analysis via TriageAnalyzer
 *   5. If AI returns a result → complete immediately
 *   6. If AI asks a question → record it + dispatch ProcessSyntheticTurnMessage (10s delay)
 */
final readonly class GenerateSyntheticCaseHandler
{
    private const string SYSTEM_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        private UserRepository $userRepository,
        private OpenRouterClientInterface $openRouter,
        private SyntheticSystemPrompt $syntheticPrompt,
        private TriageAnalyzerInterface $analyzer,
        private TriageSubmissionRepository $submissionRepository,
        private EntityManagerInterface $entityManager,
        private ?MessageBusInterface $messageBus = null,
    ) {}

    public function __invoke(GenerateSyntheticCaseCommand $command): TriageSubmission
    {
        $systemUser = $this->userRepository->findById(Uuid::fromString(self::SYSTEM_USER_ID));

        if ($systemUser === null) {
            throw new \RuntimeException(
                'System user not found. Run doctrine:migrations:migrate to create it.',
            );
        }

        // Step 1: Generate symptom description via OpenRouter
        $symptomDescription = $this->generateSymptom();

        // Step 2: Create submission with isSynthetic=true
        $submission = TriageSubmission::create($systemUser, $symptomDescription, isSynthetic: true);
        $this->submissionRepository->save($submission);

        // Step 3: Run initial AI analysis
        try {
            $result = $this->analyzer->analyzeInitial($symptomDescription);
        } catch (TriageAnalysisFailedException) {
            $submission->markFailed();
            $this->entityManager->flush();
            return $submission;
        }

        // Step 4: Handle result or question
        if ($result['type'] === 'result') {
            $outcome = TriageOutcome::create(
                specialist: $result['specialist'],
                urgency: $result['urgency'],
                justification: $result['justification'],
            );
            $submission->completeWithOutcome($outcome);
            $this->entityManager->flush();
        } else {
            $submission->addAiQuestion($result['content']);
            $this->entityManager->flush();

            // Dispatch follow-up turn with 10-second delay (simulate human typing)
            $this->messageBus?->dispatch(
                (new Envelope(new ProcessSyntheticTurnMessage($submission->getId())))
                    ->with(new DelayStamp(10000)),
            );
        }

        return $submission;
    }

    /**
     * Call OpenRouter to generate a realistic symptom description.
     * Retries once if the AI returns empty.
     */
    private function generateSymptom(): string
    {
        $symptom = $this->openRouter->chat([
            ['role' => 'system', 'content' => $this->syntheticPrompt->getSymptomGenerationPrompt()],
            ['role' => 'user', 'content' => 'Generate a random symptom description.'],
        ]);

        $symptom = trim($symptom);

        // Retry once if empty
        if ($symptom === '') {
            $symptom = $this->openRouter->chat([
                ['role' => 'system', 'content' => $this->syntheticPrompt->getSymptomGenerationPrompt()],
                ['role' => 'user', 'content' => 'Generate a random symptom description. Vary the medical domain.'],
            ]);
            $symptom = trim($symptom);
        }

        if ($symptom === '') {
            throw new \RuntimeException('OpenRouter returned empty symptom description after retry.');
        }

        return $symptom;
    }
}
