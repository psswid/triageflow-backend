<?php

declare(strict_types=1);

namespace App\Triage\Domain\Entity;

use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'triage_submissions')]
final class TriageSubmission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: 'json')]
    private array $conversationHistory = [];

    #[ORM\Embedded(class: TriageOutcome::class, columnPrefix: 'outcome_')]
    private ?TriageOutcome $outcome = null;

    #[ORM\Column(type: 'string', length: 20, enumType: TriageStatus::class)]
    private TriageStatus $status;

    #[ORM\Column(type: 'integer')]
    private int $currentTurn = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isSynthetic = false;

    #[ORM\Column]
    private readonly \DateTimeImmutable $submittedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** Duration of processing in seconds (submittedAt → processedAt). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $processingDuration = null;

    private function __construct(User $user, string $initialDescription)
    {
        $this->validateContentLength($initialDescription, 500, 'Initial symptom description');

        $this->id = Uuid::v4();
        $this->user = $user;
        $this->status = TriageStatus::Pending;
        $this->submittedAt = new \DateTimeImmutable();

        $this->conversationHistory[] = [
            'type' => 'initial_description',
            'content' => $initialDescription,
            'timestamp' => $this->submittedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Named constructor — submit a new triage with an initial symptom description.
     */
    public static function submit(User $user, string $initialDescription): self
    {
        return new self($user, $initialDescription);
    }

    /**
     * Record an AI follow-up question in the conversation history.
     *
     * Increments currentTurn and transitions status to awaiting_answer.
     *
     * @throws \InvalidArgumentException if content exceeds 1000 characters
     */
    public function addAiQuestion(string $content): void
    {
        $this->validateContentLength($content, 1000, 'AI question');

        $this->currentTurn++;
        $this->status = TriageStatus::AwaitingAnswer;

        $this->conversationHistory[] = [
            'type' => 'question',
            'content' => $content,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Record a user's answer to an AI question.
     *
     * Does NOT increment currentTurn. Transitions status to processing.
     *
     * @throws \InvalidArgumentException if content exceeds 300 characters
     */
    public function addUserAnswer(string $content): void
    {
        $this->validateContentLength($content, 300, 'User answer');

        $this->status = TriageStatus::Processing;

        $this->conversationHistory[] = [
            'type' => 'answer',
            'content' => $content,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Complete the triage with an outcome produced by the AI.
     *
     * Sets the outcome embeddable, transitions status to completed,
     * records the processedAt timestamp, and appends a result entry
     * to the conversation history.
     */
    public function completeWithOutcome(TriageOutcome $outcome): void
    {
        $this->outcome = $outcome;
        $this->status = TriageStatus::Completed;
        $this->processedAt = new \DateTimeImmutable();
        $this->processingDuration = $this->processedAt->getTimestamp() - $this->submittedAt->getTimestamp();

        $this->conversationHistory[] = [
            'type' => 'result',
            'content' => sprintf(
                'Specialist: %s | Urgency: %s | %s',
                $outcome->getSpecialist(),
                $outcome->getUrgency(),
                $outcome->getJustification(),
            ),
            'timestamp' => $this->processedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Mark the triage submission as failed.
     *
     * Used when AI processing produces unrecoverable errors
     * (e.g., 3 retries exhausted, or turn-3 force result fails).
     */
    public function markFailed(): void
    {
        $this->status = TriageStatus::Failed;
    }

    // ─── Getters ─────────────────────────────────────────────────────

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return array<int, array{type: string, content: string, timestamp: string}>
     */
    public function getConversationHistory(): array
    {
        return $this->conversationHistory;
    }

    public function getOutcome(): ?TriageOutcome
    {
        return $this->outcome;
    }

    public function getStatus(): TriageStatus
    {
        return $this->status;
    }

    public function getCurrentTurn(): int
    {
        return $this->currentTurn;
    }

    public function isSynthetic(): bool
    {
        return $this->isSynthetic;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessingDuration(): ?int
    {
        return $this->processingDuration;
    }

    // ─── Internal Helpers ────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException if content exceeds the character limit
     */
    private function validateContentLength(string $content, int $maxLength, string $label): void
    {
        if (mb_strlen($content) > $maxLength) {
            throw new \InvalidArgumentException(
                sprintf('%s exceeds maximum length of %d characters (got %d).', $label, $maxLength, mb_strlen($content)),
            );
        }
    }
}
