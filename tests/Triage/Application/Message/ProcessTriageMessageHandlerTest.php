<?php

declare(strict_types=1);

namespace App\Tests\Triage\Application\Message;

use App\Triage\Application\Message\ProcessTriageMessage;
use App\Triage\Application\Message\ProcessTriageMessageHandler;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class ProcessTriageMessageHandlerTest extends TestCase
{
    private TriageSubmissionRepository&MockObject $repository;
    private TriageAnalyzerInterface&MockObject $analyzer;
    private EntityManagerInterface&MockObject $entityManager;
    private LoggerInterface&MockObject $logger;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TriageSubmissionRepository::class);
        $this->analyzer = $this->createMock(TriageAnalyzerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->user = User::register(
            'test-' . uniqid() . '@example.com',
            '$2y$13$hashedpassword',
        );
    }

    private function createHandler(): ProcessTriageMessageHandler
    {
        return new ProcessTriageMessageHandler(
            $this->repository,
            $this->analyzer,
            $this->entityManager,
            $this->logger,
        );
    }

    private function createSubmissionInProcessingState(): TriageSubmission
    {
        $submission = TriageSubmission::submit($this->user, 'I have a headache');
        $submission->addAiQuestion('How long have you had this headache?');
        $submission->addUserAnswer('About 3 days now.');

        return $submission;
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 1: Processing submission, AI returns question → status
    //         returns to awaiting_answer, question added, turn incremented
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerAddsQuestionWhenAiReturnsQuestion(): void
    {
        $submission = $this->createSubmissionInProcessingState();
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->once())
            ->method('analyzeFollowUp')
            ->with(
                'About 3 days now.',
                $this->callback(function (array $history) {
                    $this->assertCount(3, $history); // initial + question + answer
                    return true;
                }),
                1,
            )
            ->willReturn(['type' => 'question', 'content' => 'Is the pain constant or intermittent?']);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());
        $this->assertSame(2, $submission->getCurrentTurn());

        $history = $submission->getConversationHistory();
        $this->assertCount(4, $history);
        $this->assertSame('question', $history[3]['type']);
        $this->assertSame('Is the pain constant or intermittent?', $history[3]['content']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 2: Processing submission, AI returns result → status=completed,
    //         outcome set, processedAt set
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCompletesSubmissionWhenAiReturnsResult(): void
    {
        $submission = $this->createSubmissionInProcessingState();
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->once())
            ->method('analyzeFollowUp')
            ->with('About 3 days now.', $this->anything(), 1)
            ->willReturn([
                'type' => 'result',
                'specialist' => 'Neurologist',
                'urgency' => 'MEDIUM',
                'justification' => 'Persistent headache of 3 days requires neurological evaluation.',
            ]);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
        $this->assertNotNull($submission->getOutcome());
        $this->assertSame('Neurologist', $submission->getOutcome()->getSpecialist());
        $this->assertSame('MEDIUM', $submission->getOutcome()->getUrgency());
        $this->assertNotNull($submission->getProcessedAt());

        $history = $submission->getConversationHistory();
        $this->assertCount(4, $history);
        $this->assertSame('result', $history[3]['type']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 3: Submission already completed → no-op (no analyzer calls,
    //         no persists)
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerSkipsCompletedSubmission(): void
    {
        $submission = TriageSubmission::submit($this->user, 'Chest pain');
        $submission->completeWithOutcome(
            TriageOutcome::create(
                specialist: 'Cardiologist',
                urgency: 'HIGH',
                justification: 'Chest pain requires immediate cardiac evaluation.',
            ),
        );
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->never())
            ->method('analyzeFollowUp');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 4: Submission already failed → no-op
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerSkipsFailedSubmission(): void
    {
        $submission = TriageSubmission::submit($this->user, 'Dizziness');
        $submission->markFailed();
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->never())
            ->method('analyzeFollowUp');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::Failed, $submission->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 5: Pending submission (only initial_description, no answer)
    //         → no-op
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerSkipsPendingSubmissionWithNoAnswer(): void
    {
        $submission = TriageSubmission::submit($this->user, 'Back pain');
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->never())
            ->method('analyzeFollowUp');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::Pending, $submission->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 6: Analyzer throws → status=failed
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerMarksFailedWhenAnalyzerThrows(): void
    {
        $submission = $this->createSubmissionInProcessingState();
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->once())
            ->method('analyzeFollowUp')
            ->with('About 3 days now.', $this->anything(), 1)
            ->willThrowException(new TriageAnalysisFailedException('AI communication failed'));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::Failed, $submission->getStatus());
        $this->assertNull($submission->getOutcome());
        $this->assertNull($submission->getProcessedAt());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 7: Submission not found → throws RuntimeException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsRuntimeExceptionWhenSubmissionNotFound(): void
    {
        $submissionId = Uuid::v4();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn(null);

        $this->analyzer->expects($this->never())
            ->method('analyzeFollowUp');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 8: AwaitingAnswer submission with no answer → no-op
    //         (dispatched from initial submit, user hasn't answered yet)
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerSkipsAwaitingAnswerWithNoAnswer(): void
    {
        $submission = TriageSubmission::submit($this->user, 'Headache');
        $submission->addAiQuestion('How long has this been going on?');
        $submissionId = $submission->getId();

        $this->repository->expects($this->once())
            ->method('findById')
            ->with($submissionId)
            ->willReturn($submission);

        $this->analyzer->expects($this->never())
            ->method('analyzeFollowUp');

        $this->entityManager->expects($this->never())
            ->method('flush');

        $message = new ProcessTriageMessage($submissionId);
        $handler = $this->createHandler();

        $handler($message);

        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────
    // Class structure tests
    // ─────────────────────────────────────────────────────────────────

    public function testFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Triage/Application/Message/ProcessTriageMessageHandler.php');
        $this->assertNotFalse($content, 'ProcessTriageMessageHandler.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testClassHasAsMessageHandlerAttribute(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Triage/Application/Message/ProcessTriageMessageHandler.php');
        $this->assertNotFalse($content, 'ProcessTriageMessageHandler.php file must exist');
        $this->assertStringContainsString('AsMessageHandler', $content);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Triage/Application/Message/ProcessTriageMessageHandler.php');
        $this->assertNotFalse($content, 'ProcessTriageMessageHandler.php file must exist');
        $this->assertStringContainsString('final readonly class ProcessTriageMessageHandler', $content);
    }
}
