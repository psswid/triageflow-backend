<?php

declare(strict_types=1);

namespace App\Triage\Domain\Entity;

enum TriageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case AwaitingAnswer = 'awaiting_answer';
    case Completed = 'completed';
    case Failed = 'failed';
}
