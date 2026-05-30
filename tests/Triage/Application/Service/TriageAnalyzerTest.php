<?php

declare(strict_types=1);

namespace App\Tests\Triage\Application\Service;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Shared\Infrastructure\Ai\OpenRouterException;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Application\Service\TriageAnalyzer;
use App\Triage\Application\Service\TriageSystemPrompt;
use PHPUnit\Framework\TestCase;

final class TriageAnalyzerTest extends TestCase
{
    private TriageSystemPrompt $prompt;

    protected function setUp(): void
    {
        $this->prompt = new TriageSystemPrompt();
    }

    /**
     * Create a TriageAnalyzer with a mock OpenRouterClient that returns
     * the given JSON string when chat() is called.
     */
    private function createAnalyzer(string $mockResponse): TriageAnalyzer
    {
        $client = $this->createMock(OpenRouterClientInterface::class);
        $client->method('chat')->willReturn($mockResponse);

        return new TriageAnalyzer(client: $client, prompt: $this->prompt);
    }

    /**
     * Create a TriageAnalyzer with an OpenRouterClient mock that collects
     * the messages passed to chat() for inspection.
     *
     * @param callable(array<int, array{role: string, content: string}>): void $callback
     */
    private function createAnalyzerWithCallback(string $mockResponse, callable $callback): TriageAnalyzer
    {
        $client = $this->createMock(OpenRouterClientInterface::class);
        $client->method('chat')->willReturnCallback(
            function (array $messages, ?string $model = null) use ($mockResponse, $callback): string {
                $callback($messages);

                return $mockResponse;
            },
        );

        return new TriageAnalyzer(client: $client, prompt: $this->prompt);
    }

    // ─── analyzeInitial — Valid Responses ─────────────────────────────

    public function testAnalyzeInitialReturnsValidQuestion(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode(['type' => 'question', 'content' => 'How long have you had this pain?'], JSON_THROW_ON_ERROR),
        );

        $result = $analyzer->analyzeInitial('My head hurts');

        $this->assertSame('question', $result['type']);
        $this->assertSame('How long have you had this pain?', $result['content']);
    }

    public function testAnalyzeInitialReturnsValidResult(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => 'NEUROLOGIST',
                'urgency' => 'HIGH',
                'justification' => 'Severe headache with neurological symptoms.',
            ], JSON_THROW_ON_ERROR),
        );

        $result = $analyzer->analyzeInitial('My head is going to explode and I see flashing lights');

        $this->assertSame('result', $result['type']);
        $this->assertSame('NEUROLOGIST', $result['specialist']);
        $this->assertSame('HIGH', $result['urgency']);
        $this->assertSame('Severe headache with neurological symptoms.', $result['justification']);
    }

    // ─── analyzeFollowUp — Valid Responses ────────────────────────────

    public function testAnalyzeFollowUpReturnsValidQuestion(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode(['type' => 'question', 'content' => 'Does the pain radiate anywhere?'], JSON_THROW_ON_ERROR),
        );

        $history = [
            [
                'type' => 'initial_description',
                'content' => 'My head hurts',
                'timestamp' => '2026-05-30T10:00:00+00:00',
            ],
            [
                'type' => 'question',
                'content' => 'How long have you had this pain?',
                'timestamp' => '2026-05-30T10:01:00+00:00',
            ],
        ];

        $result = $analyzer->analyzeFollowUp('Since yesterday', $history, 1);

        $this->assertSame('question', $result['type']);
        $this->assertSame('Does the pain radiate anywhere?', $result['content']);
    }

    public function testAnalyzeFollowUpReturnsValidResult(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => 'CARDIOLOGIST',
                'urgency' => 'EMERGENCY',
                'justification' => 'Chest pain radiating to left arm with shortness of breath.',
            ], JSON_THROW_ON_ERROR),
        );

        $history = [
            [
                'type' => 'initial_description',
                'content' => 'I have chest pain',
                'timestamp' => '2026-05-30T10:00:00+00:00',
            ],
            [
                'type' => 'question',
                'content' => 'Does the pain radiate?',
                'timestamp' => '2026-05-30T10:01:00+00:00',
            ],
            [
                'type' => 'answer',
                'content' => 'Yes, to my left arm',
                'timestamp' => '2026-05-30T10:02:00+00:00',
            ],
        ];

        $result = $analyzer->analyzeFollowUp('Also short of breath', $history, 2);

        $this->assertSame('result', $result['type']);
        $this->assertSame('CARDIOLOGIST', $result['specialist']);
        $this->assertSame('EMERGENCY', $result['urgency']);
    }

    // ─── Turn 3 — Force Result ────────────────────────────────────────

    public function testTurn3ForcesResultThrowsWhenAiReturnsQuestion(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode(['type' => 'question', 'content' => 'Any other symptoms?'], JSON_THROW_ON_ERROR),
        );

        $history = [
            ['type' => 'initial_description', 'content' => 'Headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => '2 days', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Where?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
            ['type' => 'answer', 'content' => 'Forehead', 'timestamp' => '2026-05-30T10:04:00+00:00'],
            ['type' => 'question', 'content' => 'Nausea?', 'timestamp' => '2026-05-30T10:05:00+00:00'],
        ];

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/result/i');

        $analyzer->analyzeFollowUp('Yes, a bit nauseous', $history, 3);
    }

    public function testTurn3AcceptsValidResult(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => 'NEUROLOGIST',
                'urgency' => 'MEDIUM',
                'justification' => 'Persistent headache with nausea, no red flags.',
            ], JSON_THROW_ON_ERROR),
        );

        $history = [
            ['type' => 'initial_description', 'content' => 'Headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => '2 days', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Where?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
            ['type' => 'answer', 'content' => 'Forehead', 'timestamp' => '2026-05-30T10:04:00+00:00'],
            ['type' => 'question', 'content' => 'Nausea?', 'timestamp' => '2026-05-30T10:05:00+00:00'],
        ];

        $result = $analyzer->analyzeFollowUp('Yes, a bit nauseous', $history, 3);

        $this->assertSame('result', $result['type']);
        $this->assertSame('NEUROLOGIST', $result['specialist']);
    }

    public function testTurn3SendsForceResultInstruction(): void
    {
        $forceInstructionSeen = false;

        $analyzer = $this->createAnalyzerWithCallback(
            json_encode([
                'type' => 'result',
                'specialist' => 'GENERAL_PRACTITIONER',
                'urgency' => 'LOW',
                'justification' => 'Mild symptoms.',
            ], JSON_THROW_ON_ERROR),
            function (array $messages) use (&$forceInstructionSeen): void {
                foreach ($messages as $message) {
                    if (
                        $message['role'] === 'user'
                        && str_contains($message['content'], 'MUST provide a final triage result')
                    ) {
                        $forceInstructionSeen = true;
                    }
                }
            },
        );

        $history = [
            ['type' => 'initial_description', 'content' => 'Mild headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => 'A few hours', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Where?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
            ['type' => 'answer', 'content' => 'Temples', 'timestamp' => '2026-05-30T10:04:00+00:00'],
            ['type' => 'question', 'content' => 'Other symptoms?', 'timestamp' => '2026-05-30T10:05:00+00:00'],
        ];

        $analyzer->analyzeFollowUp('No', $history, 3);

        $this->assertTrue(
            $forceInstructionSeen,
            'Turn 3 must include the force-result instruction in the messages sent to the AI',
        );
    }

    // ─── Malformed JSON on Turn 0 (analyzeInitial) ────────────────────

    public function testAnalyzeInitialMalformedJsonTreatedAsQuestion(): void
    {
        $analyzer = $this->createAnalyzer('This is not valid JSON at all');

        $result = $analyzer->analyzeInitial('My head hurts');

        $this->assertSame('question', $result['type']);
        $this->assertSame('This is not valid JSON at all', $result['content']);
    }

    // ─── Malformed JSON on Turn 1 — Treated as Question ───────────────

    public function testMalformedJsonOnTurn1TreatedAsQuestion(): void
    {
        $analyzer = $this->createAnalyzer('Unparseable raw text response');

        $history = [
            ['type' => 'initial_description', 'content' => 'Headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
        ];

        $result = $analyzer->analyzeFollowUp('Two days', $history, 1);

        $this->assertSame('question', $result['type']);
        $this->assertSame('Unparseable raw text response', $result['content']);
    }

    // ─── Malformed JSON on Turn 3 — Throws ────────────────────────────

    public function testMalformedJsonOnTurn3ThrowsException(): void
    {
        $analyzer = $this->createAnalyzer('Not JSON');

        $history = [
            ['type' => 'initial_description', 'content' => 'Headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => '2 days', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Where?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
            ['type' => 'answer', 'content' => 'Forehead', 'timestamp' => '2026-05-30T10:04:00+00:00'],
            ['type' => 'question', 'content' => 'Nausea?', 'timestamp' => '2026-05-30T10:05:00+00:00'],
        ];

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/JSON/i');

        $analyzer->analyzeFollowUp('Yes', $history, 3);
    }

    // ─── Unknown JSON Type ────────────────────────────────────────────

    public function testUnknownTypeInJsonTreatedAsMalformed(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode(['type' => 'greeting', 'message' => 'Hello!'], JSON_THROW_ON_ERROR),
        );

        $result = $analyzer->analyzeInitial('Hello');

        // Unknown type on turn 0 → treated as malformed → treated as question
        $this->assertSame('question', $result['type']);
        // Content should be the raw JSON string since it can't be parsed meaningfully
        $this->assertStringContainsString('greeting', $result['content']);
    }

    // ─── Result Missing Required Keys ─────────────────────────────────

    public function testResultMissingSpecialistThrowsException(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'urgency' => 'HIGH',
                'justification' => 'Severe pain.',
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/specialist/i');

        $analyzer->analyzeInitial('Severe pain');
    }

    public function testResultMissingUrgencyThrowsException(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => 'CARDIOLOGIST',
                'justification' => 'Chest pain.',
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/urgency/i');

        $analyzer->analyzeInitial('Chest pain');
    }

    public function testResultMissingJustificationThrowsException(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => 'DERMATOLOGIST',
                'urgency' => 'LOW',
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/justification/i');

        $analyzer->analyzeInitial('Skin rash');
    }

    public function testResultWithNullRequiredKeyThrowsException(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode([
                'type' => 'result',
                'specialist' => null,
                'urgency' => 'HIGH',
                'justification' => 'Pain.',
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(TriageAnalysisFailedException::class);

        $analyzer->analyzeInitial('Pain');
    }

    // ─── OpenRouterException Propagation ──────────────────────────────

    public function testOpenRouterExceptionWrappedInAnalysisFailedException(): void
    {
        $client = $this->createMock(OpenRouterClientInterface::class);
        $client->method('chat')->willThrowException(
            new OpenRouterException('API call failed after 3 attempts: Network error'),
        );

        $analyzer = new TriageAnalyzer(client: $client, prompt: $this->prompt);

        $this->expectException(TriageAnalysisFailedException::class);
        $this->expectExceptionMessageMatches('/API call failed/i');

        $analyzer->analyzeInitial('My head hurts');
    }

    // ─── Conversation Context Passing ─────────────────────────────────

    public function testAnalyzeInitialPassesDescriptionToAiViaContext(): void
    {
        $receivedUserMessage = null;

        $analyzer = $this->createAnalyzerWithCallback(
            json_encode(['type' => 'question', 'content' => 'OK'], JSON_THROW_ON_ERROR),
            function (array $messages) use (&$receivedUserMessage): void {
                // Find the last user message (after system prompt)
                foreach (array_reverse($messages) as $message) {
                    if ($message['role'] === 'user') {
                        $receivedUserMessage = $message['content'];
                        break;
                    }
                }
            },
        );

        $analyzer->analyzeInitial('I have chest pain radiating to my left arm');

        $this->assertSame('I have chest pain radiating to my left arm', $receivedUserMessage);
    }

    public function testAnalyzeFollowUpPassesAnswerToAiViaContext(): void
    {
        $receivedUserMessage = null;

        $analyzer = $this->createAnalyzerWithCallback(
            json_encode(['type' => 'question', 'content' => 'OK'], JSON_THROW_ON_ERROR),
            function (array $messages) use (&$receivedUserMessage): void {
                foreach (array_reverse($messages) as $message) {
                    if ($message['role'] === 'user' && !str_contains($message['content'], 'MUST provide')) {
                        $receivedUserMessage = $message['content'];
                        break;
                    }
                }
            },
        );

        $history = [
            ['type' => 'initial_description', 'content' => 'Headache', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
        ];

        $analyzer->analyzeFollowUp('About 3 hours', $history, 1);

        $this->assertSame('About 3 hours', $receivedUserMessage);
    }

    // ─── Class Structure ──────────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(TriageAnalyzer::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'TriageAnalyzer must be a final class',
        );
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(TriageAnalyzer::class);

        $this->assertTrue(
            $reflection->isReadOnly(),
            'TriageAnalyzer must be a readonly class',
        );
    }

    public function testFileHasStrictTypes(): void
    {
        $reflection = new \ReflectionClass(TriageAnalyzer::class);
        $filePath = $reflection->getFileName();
        $this->assertIsString($filePath);

        $fileContents = file_get_contents($filePath);
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            (string) $fileContents,
        );
    }

    // ─── TriageAnalysisFailedException Structure ──────────────────────

    public function testTriageAnalysisFailedExceptionIsRuntimeException(): void
    {
        $reflection = new \ReflectionClass(TriageAnalysisFailedException::class);

        $this->assertTrue(
            $reflection->isSubclassOf(\RuntimeException::class),
            'TriageAnalysisFailedException must extend RuntimeException',
        );
    }

    // ─── Edge Cases ──────────────────────────────────────────────────

    public function testAnalyzeFollowUpAtTurn2StillAllowsQuestions(): void
    {
        $analyzer = $this->createAnalyzer(
            json_encode(['type' => 'question', 'content' => 'One more question?'], JSON_THROW_ON_ERROR),
        );

        $history = [
            ['type' => 'initial_description', 'content' => 'Cough', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => '1 week', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Fever?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
        ];

        // Turn 2 — questions are still allowed
        $result = $analyzer->analyzeFollowUp('No fever', $history, 2);

        $this->assertSame('question', $result['type']);
        $this->assertSame('One more question?', $result['content']);
    }

    public function testMalformedJsonOnTurn2TreatedAsQuestion(): void
    {
        $analyzer = $this->createAnalyzer('Garbled AI output that is not JSON');

        $history = [
            ['type' => 'initial_description', 'content' => 'Cough', 'timestamp' => '2026-05-30T10:00:00+00:00'],
            ['type' => 'question', 'content' => 'How long?', 'timestamp' => '2026-05-30T10:01:00+00:00'],
            ['type' => 'answer', 'content' => '1 week', 'timestamp' => '2026-05-30T10:02:00+00:00'],
            ['type' => 'question', 'content' => 'Fever?', 'timestamp' => '2026-05-30T10:03:00+00:00'],
        ];

        $result = $analyzer->analyzeFollowUp('No', $history, 2);

        $this->assertSame('question', $result['type']);
        $this->assertSame('Garbled AI output that is not JSON', $result['content']);
    }
}
