<?php

declare(strict_types=1);

namespace App\Triage\Application\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message dispatched when a Triage Submission needs async AI processing.
 *
 * Carries only the submission ID — the handler loads the full entity
 * from the repository when processing begins.
 */
final readonly class ProcessTriageMessage
{
    public function __construct(
        public Uuid $submissionId,
    ) {}
}
