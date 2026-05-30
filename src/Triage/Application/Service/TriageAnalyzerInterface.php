<?php

declare(strict_types=1);

namespace App\Triage\Application\Service;

/**
 * Contract for analyzing triage submissions via AI.
 *
 * Extracted to enable test mocking without requiring the concrete
 * implementation to be non-final.
 */
interface TriageAnalyzerInterface
{
    /**
     * Analyse the initial symptom description.
     *
     * @param string $description The user's initial symptom description
     *
     * @return array<string, mixed> Either ['type' => 'question', 'content' => '...']
     *                              or ['type' => 'result', 'specialist' => '...', ...]
     *
     * @throws TriageAnalysisFailedException When the AI call fails
     */
    public function analyzeInitial(string $description): array;

    /**
     * Analyse a follow-up answer in the context of an existing conversation.
     *
     * @param string                                                    $answer
     * @param array<int, array{type: string, content: string, timestamp: string}> $conversationHistory
     * @param int                                                       $currentTurn
     *
     * @return array<string, mixed>
     *
     * @throws TriageAnalysisFailedException
     */
    public function analyzeFollowUp(string $answer, array $conversationHistory, int $currentTurn): array;
}
