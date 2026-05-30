<?php

declare(strict_types=1);

namespace App\Tests\Triage\Infrastructure\Controller;

use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;

/**
 * Test double that returns configurable AI responses for functional tests.
 * Set via config/services_test.yaml so Symfony uses this instead of the
 * real TriageAnalyzer during controller tests.
 */
final class TestTriageAnalyzer implements TriageAnalyzerInterface
{
    private static ?array $nextInitialResult = null;
    private static ?array $nextFollowUpResult = null;
    private static bool $shouldThrow = false;

    public static function willReturnQuestionOnNextCall(string $question = 'What are your symptoms?'): void
    {
        self::$nextInitialResult = ['type' => 'question', 'content' => $question];
        self::$nextFollowUpResult = ['type' => 'question', 'content' => 'Any other symptoms?'];
        self::$shouldThrow = false;
    }

    public static function willReturnResultOnNextCall(
        string $specialist = 'General Practitioner',
        string $urgency = 'LOW',
        string $justification = 'Routine check recommended.',
    ): void {
        self::$nextInitialResult = [
            'type' => 'result',
            'specialist' => $specialist,
            'urgency' => $urgency,
            'justification' => $justification,
        ];
        self::$nextFollowUpResult = null;
        self::$shouldThrow = false;
    }

    public static function willThrowOnNextCall(): void
    {
        self::$nextInitialResult = null;
        self::$nextFollowUpResult = null;
        self::$shouldThrow = true;
    }

    public static function reset(): void
    {
        self::$nextInitialResult = null;
        self::$nextFollowUpResult = null;
        self::$shouldThrow = false;
    }

    public function analyzeInitial(string $description): array
    {
        return $this->dequeueOrThrow('nextInitialResult');
    }

    public function analyzeFollowUp(string $answer, array $conversationHistory, int $currentTurn): array
    {
        return $this->dequeueOrThrow('nextFollowUpResult');
    }

    private function dequeueOrThrow(string $property): array
    {
        if (self::$shouldThrow) {
            throw new TriageAnalysisFailedException('Test-triggered failure.');
        }

        $result = self::${$property};

        if ($result === null) {
            throw new \RuntimeException(
                sprintf('TestTriageAnalyzer: no response configured for %s. Call willReturnQuestionOnNextCall() or willReturnResultOnNextCall() before the request.', $property),
            );
        }

        return $result;
    }
}
