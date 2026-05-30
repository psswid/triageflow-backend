<?php

declare(strict_types=1);

namespace App\Triage\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * TriageOutcome is a Doctrine Embeddable value object — NOT a separate entity.
 *
 * Invariant: a TriageOutcome always has all three fields populated.
 * An in-progress submission with no result yet uses ?TriageOutcome = null
 * at the parent entity level — never a TriageOutcome with empty fields.
 */
#[ORM\Embeddable]
final readonly class TriageOutcome
{
    private function __construct(
        #[ORM\Column(type: 'string', length: 50, nullable: true)]
        private ?string $specialist = null,

        #[ORM\Column(type: 'string', length: 20, nullable: true)]
        private ?string $urgency = null,

        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $justification = null,
    ) {
    }

    /**
     * Create a complete TriageOutcome with all fields populated.
     * This is the factory for a completed triage result.
     */
    public static function create(string $specialist, string $urgency, string $justification): self
    {
        return new self(
            specialist: $specialist,
            urgency: $urgency,
            justification: $justification,
        );
    }

    /**
     * Create a TriageOutcome from AI response data.
     *
     * Expects array with keys: specialist, urgency, justification.
     * The 'type' key is expected but not validated here — the caller
     * should ensure only 'result'-type data is passed.
     *
     * @param array<string, mixed> $data Parsed JSON from AI response
     * @throws \InvalidArgumentException if required keys are missing
     */
    public static function fromAiResult(array $data): self
    {
        if (!isset($data['specialist'])) {
            throw new \InvalidArgumentException('Missing required field "specialist" in AI result data.');
        }

        if (!isset($data['urgency'])) {
            throw new \InvalidArgumentException('Missing required field "urgency" in AI result data.');
        }

        if (!isset($data['justification'])) {
            throw new \InvalidArgumentException('Missing required field "justification" in AI result data.');
        }

        return self::create(
            specialist: (string) $data['specialist'],
            urgency: (string) $data['urgency'],
            justification: (string) $data['justification'],
        );
    }

    public function getSpecialist(): ?string
    {
        return $this->specialist;
    }

    public function getUrgency(): ?string
    {
        return $this->urgency;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    /**
     * Always true — a TriageOutcome only exists when the result is complete.
     * Null/no-result is represented by ?TriageOutcome = null on the parent entity.
     */
    public function isComplete(): bool
    {
        return true;
    }

    /**
     * Value object equality — two outcomes are equal when
     * all three fields match.
     */
    public function equals(self $other): bool
    {
        return $this->specialist === $other->specialist
            && $this->urgency === $other->urgency
            && $this->justification === $other->justification;
    }
}
