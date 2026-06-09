<?php

declare(strict_types=1);

namespace App\Synthetic\Infrastructure\Scheduler;

use App\Synthetic\Application\Command\GenerateSyntheticCaseCommand;
use App\Synthetic\Application\Command\GenerateSyntheticCaseHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

/**
 * Scheduled task that generates a synthetic triage case every hour.
 *
 * Triggered by the symfony/scheduler configured in scheduler.yaml.
 * Runs only when the messenger consumer for scheduler_default is running.
 */
#[AsCronTask('0 * * * *')]
final readonly class GenerateSyntheticCaseTask
{
    public function __construct(
        private GenerateSyntheticCaseHandler $handler,
    ) {}

    public function __invoke(): void
    {
        ($this->handler)(new GenerateSyntheticCaseCommand());
    }
}
