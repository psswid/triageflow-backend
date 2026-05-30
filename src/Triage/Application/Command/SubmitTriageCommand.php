<?php

declare(strict_types=1);

namespace App\Triage\Application\Command;

use App\User\Domain\Entity\User;

/**
 * Command DTO for initiating a new Triage Submission.
 *
 * Carries the User who is submitting and the free-text initial
 * symptom description that starts the AI interview pipeline.
 */
final readonly class SubmitTriageCommand
{
    public function __construct(
        public string $initialDescription,
        public User $user,
    ) {}
}
