<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Dispatched after each AI question to a synthetic submission.
 * The handler generates a realistic patient answer via OpenRouter
 * and continues the triage interview.
 *
 * Dispatched with a 10-second DelayStamp to simulate human typing speed.
 */
final readonly class ProcessSyntheticTurnMessage
{
    public function __construct(
        public Uuid $submissionId,
    ) {}
}
