<?php

declare(strict_types=1);

namespace App\Triage\Application\Service;

final readonly class TriageSystemPrompt
{
    /**
     * Returns the static system prompt instructing the AI model on its role,
     * the available specialists and urgency levels, the required JSON output
     * format, character limits, and the demonstration disclaimer.
     *
     * This method is idempotent — always returns the same string.
     */
    public function getSystemMessage(): string
    {
        return <<<'PROMPT'
You are a medical triage assistant for a DEMONSTRATION system.

Your role is to interview patients about their symptoms through follow-up
questions, then recommend the most appropriate medical specialist and urgency
level based on the information gathered.

Available specialists:
- GENERAL_PRACTITIONER: General medical care and initial assessment
- CARDIOLOGIST: Heart and cardiovascular conditions
- DERMATOLOGIST: Skin conditions
- NEUROLOGIST: Brain and nervous system conditions
- ORTHOPEDIST: Bone, joint, and muscle conditions
- GASTROENTEROLOGIST: Digestive system conditions
- PULMONOLOGIST: Lung and respiratory conditions
- PSYCHIATRIST: Mental health conditions

Urgency levels:
- LOW: Non-urgent, routine care appropriate within weeks
- MEDIUM: Should see specialist within days
- HIGH: Requires prompt attention within 24 hours
- EMERGENCY: Immediate medical attention required

IMPORTANT: This is a DEMONSTRATION system. All data is synthetic.
No real medical advice.

You must respond ONLY with valid JSON in one of these two formats:

1. To ask a follow-up question:
{"type":"question","content":"Your follow-up question here"}

2. To provide a final triage result:
{"type":"result","specialist":"GENERAL_PRACTITIONER","urgency":"MEDIUM","justification":"Brief medical justification."}

Character limit rules:
- User initial descriptions are limited to 500 characters
- User answers to questions are limited to 300 characters
- Your responses must be concise, under 1000 characters

If you have enough information to determine a specialist and urgency, provide a
result. Otherwise, ask a single focused follow-up question to gather the most
critical missing information.
PROMPT;
    }

    /**
     * Builds the full conversation context as an array of messages suitable
     * for the OpenRouter chat completion API.
     *
     * The returned array follows the OpenAI-compatible format:
     *   [{"role": "system", "content": "..."}, {"role": "user", "content": "..."}, ...]
     *
     * Message ordering:
     *   1. System prompt (role: system)
     *   2. Conversation history entries, mapped by type (in chronological order)
     *   3. Current user message (role: user)
     *
     * History entry type-to-role mapping:
     *   - initial_description → user (patient's opening complaint)
     *   - answer              → user (patient's answer to an AI question)
     *   - question            → assistant (AI's follow-up question)
     *   - result              → assistant (AI's final triage result)
     *
     * @param string $userMessage The current user message to include as the final entry
     * @param array<int, array{type: string, content: string, timestamp: string}> $history
     *        The conversation history entries, each with type, content, and timestamp
     *
     * @return array<int, array{role: string, content: string}>
     *         The complete messages array for the AI model
     *
     * @throws \InvalidArgumentException if a history entry has an unrecognized type
     */
    public function buildConversationContext(string $userMessage, array $history): array
    {
        $messages = [];

        // 1. System prompt — sets the AI's behavior and constraints
        $messages[] = [
            'role' => 'system',
            'content' => $this->getSystemMessage(),
        ];

        // 2. Map each conversation history entry to its OpenAI role
        foreach ($history as $entry) {
            $messages[] = [
                'role' => self::mapHistoryTypeToRole($entry['type']),
                'content' => $entry['content'],
            ];
        }

        // 3. Current user message — always the last entry
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        return $messages;
    }

    /**
     * Maps a conversation history entry 'type' value to an OpenAI message 'role'.
     *
     * @throws \InvalidArgumentException when the type is not one of the recognized values
     */
    private static function mapHistoryTypeToRole(string $type): string
    {
        return match ($type) {
            'initial_description', 'answer' => 'user',
            'question', 'result' => 'assistant',
            default => throw new \InvalidArgumentException(
                sprintf('Unknown conversation history entry type: "%s".', $type),
            ),
        };
    }
}
