<?php

declare(strict_types=1);

namespace App\Triage\Application\Service;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Shared\Infrastructure\Ai\OpenRouterException;

/**
 * Analyses user symptom descriptions through an AI-powered interview pipeline.
 *
 * Delegates AI communication to OpenRouterClient and conversation formatting
 * to TriageSystemPrompt. Parses the JSON response from the AI and discriminates
 * between follow-up questions and final triage results.
 *
 * On turns 0–2 (initial + first two follow-ups), malformed or unrecognized AI
 * responses are treated as follow-up questions so the interview can continue.
 * On turn >= 3, the AI is instructed to produce a final result; non-compliance
 * or malformed output throws TriageAnalysisFailedException.
 */
final readonly class TriageAnalyzer implements TriageAnalyzerInterface
{
    private const string FORCE_RESULT_MESSAGE = <<<'FORCE'
You have been given 3 turns to gather information. Now you MUST provide a final triage result with the specialist, urgency, and justification. Do NOT ask any more questions. Respond ONLY with the result JSON format: {"type":"result","specialist":"SPECIALIST","urgency":"URGENCY","justification":"Justification text."}
FORCE;

    public function __construct(
        private OpenRouterClientInterface $client,
        private TriageSystemPrompt $prompt,
    ) {}

    /**
     * Analyse the initial symptom description and return either a follow-up
     * question or an immediate triage result.
     *
     * Called once per Triage Submission, at turn 0 (no history yet).
     *
     * @param string $description The user's initial symptom description
     *
     * @return array<string, mixed> Either ['type' => 'question', 'content' => '...']
     *                              or ['type' => 'result', 'specialist' => '...', ...]
     *
     * @throws TriageAnalysisFailedException When the AI call fails at the
     *         transport level (OpenRouterException) or returns a malformed
     *         result-type response
     */
    public function analyzeInitial(string $description): array
    {
        try {
            $messages = $this->prompt->buildConversationContext($description, []);
            $rawResponse = $this->client->chat($messages);

            return $this->parseAndDiscriminate($rawResponse, 0);
        } catch (OpenRouterException $e) {
            throw new TriageAnalysisFailedException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Analyse a follow-up answer in the context of an existing conversation.
     *
     * On turn >= 3, injects a force-result instruction before the user's
     * answer to ensure the AI produces a final result. If the AI still
     * returns a question or malformed output at turn >= 3, throws
     * TriageAnalysisFailedException.
     *
     * @param string                                                    $answer             The user's answer to the last AI question
     * @param array<int, array{type: string, content: string, timestamp: string}> $conversationHistory The full conversation history
     * @param int                                                       $currentTurn        The 1-based follow-up turn number (1, 2, or 3)
     *
     * @return array<string, mixed> Either ['type' => 'question', 'content' => '...']
     *                              or ['type' => 'result', 'specialist' => '...', ...]
     *
     * @throws TriageAnalysisFailedException When the AI fails to produce a
     *         valid result on the final turn or the transport layer fails
     */
    public function analyzeFollowUp(string $answer, array $conversationHistory, int $currentTurn): array
    {
        try {
            $messages = $this->prompt->buildConversationContext($answer, $conversationHistory);

            // On turn >= 3, insert the force-result instruction as a user
            // message immediately before the current user answer (which is
            // always the last element in the returned messages array).
            if ($currentTurn >= 3) {
                array_splice(
                    $messages,
                    -1,
                    0,
                    [
                        [
                            'role' => 'user',
                            'content' => self::FORCE_RESULT_MESSAGE,
                        ],
                    ],
                );
            }

            $rawResponse = $this->client->chat($messages);

            return $this->parseAndDiscriminate($rawResponse, $currentTurn);
        } catch (OpenRouterException $e) {
            throw new TriageAnalysisFailedException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Parse the raw AI response string as JSON and discriminate between
     * a follow-up question and a final triage result.
     *
     * Discrimination rules:
     *   - Valid {"type":"question","content":"..."} → question array
     *   - Valid {"type":"result","specialist":"...","urgency":"...","justification":"..."} → result array
     *   - Malformed JSON on turns < 3 → treated as question (wrap raw text)
     *   - Malformed JSON on turn >= 3 → TriageAnalysisFailedException
     *   - Unknown type (not question/result) → treated as malformed
     *   - Result missing required keys → TriageAnalysisFailedException
     *
     * @param string $rawResponse The raw string returned by the AI
     * @param int    $turn        Current turn number (0 = initial, 1–3 = follow-ups)
     *
     * @return array<string, mixed>
     *
     * @throws TriageAnalysisFailedException
     */
    private function parseAndDiscriminate(string $rawResponse, int $turn): array
    {
        $data = json_decode($rawResponse, true);

        // ── Malformed JSON ────────────────────────────────────────────
        if (!is_array($data)) {
            if ($turn >= 3) {
                throw new TriageAnalysisFailedException(
                    'AI returned malformed JSON on the final turn — unable to produce a triage result.',
                );
            }

            // Turns 0–2: treat as question, wrapping the raw text
            return ['type' => 'question', 'content' => $rawResponse];
        }

        $type = (string) ($data['type'] ?? '');

        // ── Question response ──────────────────────────────────────────
        if ($type === 'question') {
            if ($turn >= 3) {
                throw new TriageAnalysisFailedException(
                    'AI returned a question on the final turn — non-compliance with the force-result instruction.',
                );
            }

            return [
                'type' => 'question',
                'content' => (string) ($data['content'] ?? $rawResponse),
            ];
        }

        // ── Result response ────────────────────────────────────────────
        if ($type === 'result') {
            if (!isset($data['specialist']) || $data['specialist'] === null) {
                throw new TriageAnalysisFailedException(
                    'AI result is missing required field: specialist.',
                );
            }

            if (!isset($data['urgency']) || $data['urgency'] === null) {
                throw new TriageAnalysisFailedException(
                    'AI result is missing required field: urgency.',
                );
            }

            if (!isset($data['justification']) || $data['justification'] === null) {
                throw new TriageAnalysisFailedException(
                    'AI result is missing required field: justification.',
                );
            }

            return [
                'type' => 'result',
                'specialist' => (string) $data['specialist'],
                'urgency' => (string) $data['urgency'],
                'justification' => (string) $data['justification'],
            ];
        }

        // ── Unknown type — treat as malformed ──────────────────────────
        if ($turn >= 3) {
            throw new TriageAnalysisFailedException(
                sprintf(
                    'AI returned unknown response type "%s" on the final turn.',
                    $type ?: '(empty)',
                ),
            );
        }

        return ['type' => 'question', 'content' => $rawResponse];
    }
}
