<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Command;

/**
 * Command to generate one synthetic triage case.
 * Dispatched by the scheduler (every 60s) or by the manual admin endpoint.
 */
final readonly class GenerateSyntheticCaseCommand
{
    public function __construct() {}
}
