<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Command;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Synthetic\Application\Message\ProcessSyntheticCaseMessage;
use App\Synthetic\Application\Service\SyntheticSystemPrompt;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Generates one synthetic triage case.
 *
 * Flow:
 *   1. Resolve the system user (UUID 00000000-...)
 *   2. Call OpenRouter to generate a realistic symptom description
 *   3. Create TriageSubmission::create(systemUser, symptom, isSynthetic: true)
 *   4. Dispatch ProcessSyntheticCaseMessage for async AI analysis
 *
 * The AI triage analysis runs asynchronously via the messenger worker
 * (ProcessSyntheticCaseMessageHandler), so the generator returns quickly.
 */
final readonly class GenerateSyntheticCaseHandler
{
    private const string SYSTEM_USER_ID = '00000000-0000-0000-0000-000000000001';

    public function __construct(
        private UserRepository $userRepository,
        private OpenRouterClientInterface $openRouter,
        private SyntheticSystemPrompt $syntheticPrompt,
        private TriageSubmissionRepository $submissionRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
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
        $this->entityManager->flush();

        // Step 3: Dispatch async AI analysis
        $this->messageBus->dispatch(
            new ProcessSyntheticCaseMessage($submission->getId()),
        );

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

        // Enforce the 500-character limit (defense-in-depth; AI prompt already
        // asks for under 500 chars, but the model sometimes ignores it).
        if (mb_strlen($symptom) > 500) {
            $symptom = mb_substr($symptom, 0, 497) . '...';
        }

        return $symptom;
    }
}
