<?php

declare(strict_types=1);

namespace App\Tests\Synthetic\Application\Message;

use App\Synthetic\Application\Message\ProcessSyntheticCaseMessage;
use App\Synthetic\Application\Message\ProcessSyntheticCaseMessageHandler;
use App\Synthetic\Application\Message\ProcessSyntheticTurnMessage;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

final class ProcessSyntheticCaseMessageHandlerTest extends TestCase
{
    private TriageSubmissionRepository&MockObject $repository;
    private TriageAnalyzerInterface&MockObject $analyzer;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private User $systemUser;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TriageSubmissionRepository::class);
        $this->analyzer = $this->createMock(TriageAnalyzerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->systemUser = User::register(
            'system@triageflow.local',
            '$2y$13$hashedpassword',
        );
    }

    private function createHandler(): ProcessSyntheticCaseMessageHandler
    {
        return new ProcessSyntheticCaseMessageHandler(
            $this->repository,
            $this->analyzer,
            $this->entityManager,
            $this->logger,
            $this->messageBus,
        );
    }

    private function createPendingSyntheticSubmission(string $symptom): TriageSubmission
    {
        return TriageSubmission::create($this->systemUser, $symptom, isSynthetic: true);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 1: AI returns result → submission completed with outcome
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCompletesSubmissionWhenAiReturnsResult(): void
    {
        $symptom = 'I have been experiencing sharp chest pain for 3 days.';
        $submission = $this->createPendingSyntheticSubmission($symptom);
        $submissionId = $submission->getId();

        // Arrange: submission found
        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

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

        // Arrange: flush called after completing
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Arrange: no async message for follow-up (result is terminal)
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        // Act
        $handler = $this->createHandler();
        $handler(new ProcessSyntheticCaseMessage($submissionId));

        // Assert
        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
        $this->assertNotNull($submission->getOutcome());
        $this->assertSame('Cardiologist', $submission->getOutcome()->getSpecialist());
        $this->assertSame('HIGH', $submission->getOutcome()->getUrgency());
        $this->assertNotNull($submission->getProcessedAt());

        $history = $submission->getConversationHistory();
        $this->assertCount(2, $history);
        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame('result', $history[1]['type']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 2: AI returns question → dispatches async turn message
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerDispatchesTurnMessageWhenAiReturnsQuestion(): void
    {
        $symptom = 'I have a persistent headache for the past week.';
        $submission = $this->createPendingSyntheticSubmission($symptom);
        $submissionId = $submission->getId();

        // Arrange: submission found
        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

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

        // Arrange: async turn message dispatched with 10-second delay
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
                // Verify DelayStamp is present (10s = 10000ms)
                $stamps = $message->all(DelayStamp::class);
                return count($stamps) === 1;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // Act
        $handler = $this->createHandler();
        $handler(new ProcessSyntheticCaseMessage($submissionId));

        // Assert
        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());
        $this->assertNull($submission->getOutcome());
        $this->assertNull($submission->getProcessedAt());

        $history = $submission->getConversationHistory();
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
        $symptom = 'I have back pain for 2 weeks.';
        $submission = $this->createPendingSyntheticSubmission($symptom);
        $submissionId = $submission->getId();

        // Arrange: submission found
        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        // Arrange: AI throws
        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->willThrowException(new TriageAnalysisFailedException('AI communication failed'));

        // Arrange: flush called in catch block
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Arrange: no async message dispatched on failure
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        // Act
        $handler = $this->createHandler();
        $handler(new ProcessSyntheticCaseMessage($submissionId));

        // Assert
        $this->assertSame(TriageStatus::Failed, $submission->getStatus());
        $this->assertNull($submission->getOutcome());
        $this->assertNull($submission->getProcessedAt());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 4: Submission not found → throws RuntimeException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsExceptionWhenSubmissionNotFound(): void
    {
        $submissionId = Uuid::v4();

        // Arrange: submission not found
        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn(null);

        // Arrange: no other services should be called
        $this->analyzer->expects($this->never())
            ->method('analyzeInitial');
        $this->entityManager->expects($this->never())
            ->method('flush');
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Triage Submission not found');

        // Act
        $handler = $this->createHandler();
        $handler(new ProcessSyntheticCaseMessage($submissionId));
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 5: Missing initial description → throws RuntimeException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsExceptionWhenInitialDescriptionMissing(): void
    {
        // Need a submission with no conversation history
        $submission = TriageSubmission::submit($this->systemUser, 'test symptom');
        $submissionId = $submission->getId();

        // Overwrite conversation history to empty (simulating corruption)
        $ref = new \ReflectionProperty($submission, 'conversationHistory');
        $ref->setValue($submission, []);

        // Arrange: submission found
        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        // Arrange: analyzer should not be called
        $this->analyzer->expects($this->never())
            ->method('analyzeInitial');
        $this->entityManager->expects($this->never())
            ->method('flush');
        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing the initial symptom description');

        // Act
        $handler = $this->createHandler();
        $handler(new ProcessSyntheticCaseMessage($submissionId));
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 6: Class structure tests
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Synthetic/Application/Message/ProcessSyntheticCaseMessageHandler.php');
        $this->assertNotFalse($content, 'ProcessSyntheticCaseMessageHandler.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testMessageFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Synthetic/Application/Message/ProcessSyntheticCaseMessage.php');
        $this->assertNotFalse($content, 'ProcessSyntheticCaseMessage.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
