<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Message dispatched when an admin triggers a synthetic case generation.
 *
 * Carries only the submission ID — the submission already exists with
 * a pre-generated symptom description. The handler runs the AI triage
 * analysis asynchronously.
 */
final readonly class ProcessSyntheticCaseMessage
{
    public function __construct(
        public Uuid $submissionId,
    ) {}
}
