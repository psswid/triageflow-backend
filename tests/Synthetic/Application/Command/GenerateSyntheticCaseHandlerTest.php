<?php

declare(strict_types=1);

namespace App\Tests\Synthetic\Application\Command;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Synthetic\Application\Command\GenerateSyntheticCaseCommand;
use App\Synthetic\Application\Command\GenerateSyntheticCaseHandler;
use App\Synthetic\Application\Message\ProcessSyntheticTurnMessage;
use App\Synthetic\Application\Service\SyntheticSystemPrompt;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class GenerateSyntheticCaseHandlerTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private OpenRouterClientInterface&MockObject $openRouter;
    private SyntheticSystemPrompt $syntheticPrompt;
    private TriageAnalyzerInterface&MockObject $analyzer;
    private TriageSubmissionRepository&MockObject $submissionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageBusInterface&MockObject $messageBus;
    private User $systemUser;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->openRouter = $this->createMock(OpenRouterClientInterface::class);
        $this->syntheticPrompt = new SyntheticSystemPrompt();
        $this->analyzer = $this->createMock(TriageAnalyzerInterface::class);
        $this->submissionRepository = $this->createMock(TriageSubmissionRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->systemUser = User::register(
            'system@triageflow.local',
            '$2y$13$hashedpassword',
        );
    }

    private function createHandler(): GenerateSyntheticCaseHandler
    {
        return new GenerateSyntheticCaseHandler(
            $this->userRepository,
            $this->openRouter,
            $this->syntheticPrompt,
            $this->analyzer,
            $this->submissionRepository,
            $this->entityManager,
            $this->messageBus,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 1: AI returns result → submission completed with outcome
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCreatesSyntheticSubmissionAndCompletesWhenAiReturnsResult(): void
    {
        $symptom = 'I have been experiencing sharp chest pain for 3 days.';

        // Arrange: system user found
        $this->userRepository->expects($this->once())
            ->method('findById')
            ->willReturn($this->systemUser);

        // Arrange: symptom generation returns a description
        $this->openRouter->expects($this->once())
            ->method('chat')
            ->with($this->callback(function (array $messages): bool {
                return count($messages) === 2
                    && $messages[0]['role'] === 'system'
                    && $messages[1]['role'] === 'user';
            }))
            ->willReturn($symptom);

        // Arrange: submission is saved with correct attributes
        $this->submissionRepository->expects($this->once())
            ->method('save')
            ->with($this->callback(function (TriageSubmission $submission) use ($symptom): bool {
                return $submission->getUser() === $this->systemUser
                    && $submission->getStatus() === TriageStatus::Pending
                    && $submission->isSynthetic() === true
                    && $submission->getCurrentTurn() === 0;
            }));

        // Arrange: AI returns a result directly
        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->with($symptom)
            ->willReturn([
                'type' => 'result',
                'specialist' => 'Cardiologist',
                'urgency' => 'HIGH',
                'justification' => 'Patient reports chest pain indicative of possible cardiac event.',
            ]);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->never())
            ->method('dispatch');

        // Act
        $handler = $this->createHandler();
        $result = $handler(new GenerateSyntheticCaseCommand());

        // Assert
        $this->assertSame(TriageStatus::Completed, $result->getStatus());
        $this->assertNotNull($result->getOutcome());
        $this->assertSame('Cardiologist', $result->getOutcome()->getSpecialist());
        $this->assertSame('HIGH', $result->getOutcome()->getUrgency());
        $this->assertTrue($result->isSynthetic());
        $this->assertNotNull($result->getProcessedAt());

        $history = $result->getConversationHistory();
        $this->assertCount(2, $history);
        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame($symptom, $history[0]['content']);
        $this->assertSame('result', $history[1]['type']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 2: AI returns question → dispatches async message
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerDispatchesAsyncMessageWhenAiReturnsQuestion(): void
    {
        $symptom = 'I have a persistent headache for the past week.';

        // Arrange: system user found
        $this->userRepository->expects($this->once())
            ->method('findById')
            ->willReturn($this->systemUser);

        // Arrange: symptom generation
        $this->openRouter->expects($this->once())
            ->method('chat')
            ->willReturn($symptom);

        // Arrange: submission saved
        $this->submissionRepository->expects($this->once())
            ->method('save');

        // Arrange: AI returns a follow-up question
        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->with($symptom)
            ->willReturn([
                'type' => 'question',
                'content' => 'How long have you had this headache? Does it throb or is it constant?',
            ]);

        // Arrange: flush called after recording the question
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Arrange: async message dispatched with delay
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (object $message): bool {
                if (!$message instanceof Envelope) {
                    return false;
                }
                $inner = $message->getMessage();
                if (!$inner instanceof ProcessSyntheticTurnMessage) {
                    return false;
                }
                return $inner->submissionId instanceof Uuid;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // Act
        $handler = $this->createHandler();
        $result = $handler(new GenerateSyntheticCaseCommand());

        // Assert
        $this->assertSame(TriageStatus::AwaitingAnswer, $result->getStatus());
        $this->assertNull($result->getOutcome());
        $this->assertNull($result->getProcessedAt());
        $this->assertTrue($result->isSynthetic());

        $history = $result->getConversationHistory();
        $this->assertCount(2, $history);
        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame('question', $history[1]['type']);
        $this->assertSame('How long have you had this headache? Does it throb or is it constant?', $history[1]['content']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 3: AI analysis fails → submission marked as failed
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerMarksSubmissionFailedWhenAnalyzerThrows(): void
    {
        // Arrange: system user found
        $this->userRepository->expects($this->once())
            ->method('findById')
            ->willReturn($this->systemUser);

        // Arrange: symptom generation
        $this->openRouter->expects($this->once())
            ->method('chat')
            ->willReturn('I have back pain for 2 weeks.');

        // Arrange: submission saved
        $this->submissionRepository->expects($this->once())
            ->method('save');

        // Arrange: AI throws
        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->willThrowException(new TriageAnalysisFailedException('AI communication failed'));

        // Arrange: flush called in catch block
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->never())
            ->method('dispatch');

        // Act
        $handler = $this->createHandler();
        $result = $handler(new GenerateSyntheticCaseCommand());

        // Assert
        $this->assertSame(TriageStatus::Failed, $result->getStatus());
        $this->assertNull($result->getOutcome());
        $this->assertNull($result->getProcessedAt());
        $this->assertTrue($result->isSynthetic());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 4: System user not found → throws RuntimeException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsExceptionWhenSystemUserNotFound(): void
    {
        // Arrange: system user not found
        $this->userRepository->expects($this->once())
            ->method('findById')
            ->willReturn(null);

        // Arrange: no other services should be called
        $this->openRouter->expects($this->never())
            ->method('chat');
        $this->submissionRepository->expects($this->never())
            ->method('save');
        $this->analyzer->expects($this->never())
            ->method('analyzeInitial');
        $this->entityManager->expects($this->never())
            ->method('flush');
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('System user not found');

        // Act
        $handler = $this->createHandler();
        $handler(new GenerateSyntheticCaseCommand());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 5: Empty symptom after retry → throws RuntimeException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsExceptionWhenSymptomGenerationReturnsEmptyAfterRetry(): void
    {
        // Arrange: system user found
        $this->userRepository->expects($this->once())
            ->method('findById')
            ->willReturn($this->systemUser);

        // Arrange: first call returns empty, retry also returns empty
        $this->openRouter->expects($this->exactly(2))
            ->method('chat')
            ->willReturn('');

        // Arrange: no other services should be called after generation failure
        $this->submissionRepository->expects($this->never())
            ->method('save');
        $this->analyzer->expects($this->never())
            ->method('analyzeInitial');
        $this->entityManager->expects($this->never())
            ->method('flush');
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenRouter returned empty symptom description after retry');

        // Act
        $handler = $this->createHandler();
        $handler(new GenerateSyntheticCaseCommand());
    }

    // ─────────────────────────────────────────────────────────────────
    // Class structure tests
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Synthetic/Application/Command/GenerateSyntheticCaseHandler.php');
        $this->assertNotFalse($content, 'GenerateSyntheticCaseHandler.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testCommandFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Synthetic/Application/Command/GenerateSyntheticCaseCommand.php');
        $this->assertNotFalse($content, 'GenerateSyntheticCaseCommand.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
