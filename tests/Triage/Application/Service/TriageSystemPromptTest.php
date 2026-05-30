<?php

declare(strict_types=1);

namespace App\Tests\Triage\Application\Service;

use App\Triage\Application\Service\TriageSystemPrompt;
use PHPUnit\Framework\TestCase;

final class TriageSystemPromptTest extends TestCase
{
    private TriageSystemPrompt $service;

    protected function setUp(): void
    {
        $this->service = new TriageSystemPrompt();
    }

    // ─── System Prompt Content ────────────────────────────────────────

    public function testGetSystemMessageReturnsNonEmptyString(): void
    {
        $message = $this->service->getSystemMessage();

        $this->assertIsString($message);
        $this->assertNotEmpty($message);
    }

    public function testSystemPromptContainsRoleDescription(): void
    {
        $message = $this->service->getSystemMessage();

        $this->assertStringContainsString('medical triage', $message);
    }

    public function testSystemPromptContainsDisclaimer(): void
    {
        $message = $this->service->getSystemMessage();

        $this->assertStringContainsString('DEMONSTRATION', $message);
        $this->assertStringContainsString('synthetic', $message);
        $this->assertStringContainsString('No real medical advice', $message);
    }

    public function testSystemPromptContainsAllSpecialists(): void
    {
        $message = $this->service->getSystemMessage();

        $expectedSpecialists = [
            'GENERAL_PRACTITIONER',
            'CARDIOLOGIST',
            'DERMATOLOGIST',
            'NEUROLOGIST',
            'ORTHOPEDIST',
            'GASTROENTEROLOGIST',
            'PULMONOLOGIST',
            'PSYCHIATRIST',
        ];

        foreach ($expectedSpecialists as $specialist) {
            $this->assertStringContainsString(
                $specialist,
                $message,
                sprintf('System prompt must mention specialist: %s', $specialist),
            );
        }
    }

    public function testSystemPromptContainsAllUrgencyLevels(): void
    {
        $message = $this->service->getSystemMessage();

        $expectedUrgencies = ['LOW', 'MEDIUM', 'HIGH', 'EMERGENCY'];

        foreach ($expectedUrgencies as $urgency) {
            $this->assertStringContainsString(
                $urgency,
                $message,
                sprintf('System prompt must mention urgency: %s', $urgency),
            );
        }
    }

    public function testSystemPromptContainsJsonOutputFormatInstructions(): void
    {
        $message = $this->service->getSystemMessage();

        // Question format
        $this->assertStringContainsString('"type":"question"', $message);
        $this->assertStringContainsString('"content"', $message);

        // Result format
        $this->assertStringContainsString('"type":"result"', $message);
        $this->assertStringContainsString('"specialist"', $message);
        $this->assertStringContainsString('"urgency"', $message);
        $this->assertStringContainsString('"justification"', $message);
    }

    public function testSystemPromptContainsCharacterLimitInstructions(): void
    {
        $message = $this->service->getSystemMessage();

        $this->assertStringContainsString('500', $message);
        $this->assertStringContainsString('300', $message);
        $this->assertStringContainsString('1000', $message);
    }

    // ─── buildConversationContext — Structure ─────────────────────────

    public function testBuildConversationContextReturnsArray(): void
    {
        $result = $this->service->buildConversationContext('Hello', []);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testBuildConversationContextFirstMessageIsSystem(): void
    {
        $result = $this->service->buildConversationContext('Hello', []);

        $this->assertSame('system', $result[0]['role']);
        $this->assertSame($this->service->getSystemMessage(), $result[0]['content']);
    }

    public function testBuildConversationContextLastMessageIsCurrentUserMessage(): void
    {
        $result = $this->service->buildConversationContext('Hello', []);

        $lastMessage = $result[array_key_last($result)];
        $this->assertSame('user', $lastMessage['role']);
        $this->assertSame('Hello', $lastMessage['content']);
    }

    public function testBuildConversationContextWithEmptyHistoryProducesTwoMessages(): void
    {
        $result = $this->service->buildConversationContext('Hello', []);

        $this->assertCount(2, $result);
    }

    // ─── buildConversationContext — Role Mapping ──────────────────────

    public function testBuildConversationContextMapsInitialDescriptionToUserRole(): void
    {
        $history = [
            [
                'type' => 'initial_description',
                'content' => 'My head hurts',
                'timestamp' => '2026-05-30T10:00:00+00:00',
            ],
        ];

        $result = $this->service->buildConversationContext('Tell me more', $history);

        $this->assertCount(3, $result);
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame('user', $result[1]['role']);
        $this->assertSame('My head hurts', $result[1]['content']);
        $this->assertSame('user', $result[2]['role']);
        $this->assertSame('Tell me more', $result[2]['content']);
    }

    public function testBuildConversationContextMapsAnswerToUserRole(): void
    {
        $history = [
            [
                'type' => 'answer',
                'content' => 'Yes, since yesterday',
                'timestamp' => '2026-05-30T10:01:00+00:00',
            ],
        ];

        $result = $this->service->buildConversationContext('Anything else?', $history);

        $this->assertSame('user', $result[1]['role']);
        $this->assertSame('Yes, since yesterday', $result[1]['content']);
    }

    public function testBuildConversationContextMapsQuestionToAssistantRole(): void
    {
        $history = [
            [
                'type' => 'question',
                'content' => 'How long have you had this pain?',
                'timestamp' => '2026-05-30T10:01:00+00:00',
            ],
        ];

        $result = $this->service->buildConversationContext('Two days', $history);

        $this->assertSame('assistant', $result[1]['role']);
        $this->assertSame('How long have you had this pain?', $result[1]['content']);
    }

    public function testBuildConversationContextMapsResultToAssistantRole(): void
    {
        $history = [
            [
                'type' => 'result',
                'content' => 'Specialist: NEUROLOGIST | Urgency: HIGH | ...',
                'timestamp' => '2026-05-30T10:02:00+00:00',
            ],
        ];

        $result = $this->service->buildConversationContext('Thanks', $history);

        $this->assertSame('assistant', $result[1]['role']);
    }

    public function testBuildConversationContextWithFullConversationHistory(): void
    {
        $history = [
            [
                'type' => 'initial_description',
                'content' => 'I have chest pain',
                'timestamp' => '2026-05-30T10:00:00+00:00',
            ],
            [
                'type' => 'question',
                'content' => 'Does the pain radiate to your arm?',
                'timestamp' => '2026-05-30T10:01:00+00:00',
            ],
            [
                'type' => 'answer',
                'content' => 'Yes, to my left arm',
                'timestamp' => '2026-05-30T10:02:00+00:00',
            ],
        ];

        $result = $this->service->buildConversationContext('It hurts when I breathe deeply', $history);

        // 1 system + 3 history + 1 current = 5 messages
        $this->assertCount(5, $result);

        // System
        $this->assertSame('system', $result[0]['role']);

        // History entries
        $this->assertSame('user', $result[1]['role']);      // initial_description
        $this->assertSame('assistant', $result[2]['role']);  // question
        $this->assertSame('user', $result[3]['role']);       // answer

        // Current user message
        $this->assertSame('user', $result[4]['role']);
        $this->assertSame('It hurts when I breathe deeply', $result[4]['content']);
    }

    // ─── buildConversationContext — Error Handling ────────────────────

    public function testBuildConversationContextThrowsOnUnknownHistoryType(): void
    {
        $history = [
            [
                'type' => 'invalid_type',
                'content' => 'Some content',
                'timestamp' => '2026-05-30T10:00:00+00:00',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown.*type/i');

        $this->service->buildConversationContext('Hello', $history);
    }

    // ─── Class Structure ─────────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(TriageSystemPrompt::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'TriageSystemPrompt must be a final class',
        );
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(TriageSystemPrompt::class);

        $this->assertTrue(
            $reflection->isReadOnly(),
            'TriageSystemPrompt must be a readonly class',
        );
    }

    public function testFileHasStrictTypes(): void
    {
        $reflection = new \ReflectionClass(TriageSystemPrompt::class);
        $filePath = $reflection->getFileName();
        $this->assertIsString($filePath);

        $fileContents = file_get_contents($filePath);
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            (string) $fileContents,
        );
    }
}
