<?php

declare(strict_types=1);

namespace App\Triage\Application\Service;

/**
 * Thrown when the AI triage analysis fails irrecoverably.
 *
 * Wraps underlying failures: OpenRouter API exhaustion, malformed JSON
 * on the final turn, non-compliance with the force-result instruction,
 * or result responses missing required fields.
 */
final class TriageAnalysisFailedException extends \RuntimeException
{
}
