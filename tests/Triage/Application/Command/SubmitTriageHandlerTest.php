<?php

declare(strict_types=1);

namespace App\Tests\Triage\Application\Command;

use App\Triage\Application\Command\SubmitTriageCommand;
use App\Triage\Application\Command\SubmitTriageHandler;
use App\Triage\Application\Message\ProcessTriageMessage;
use App\Triage\Application\Service\TriageAnalyzerInterface;
use App\Triage\Application\Service\TriageAnalysisFailedException;
use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class SubmitTriageHandlerTest extends TestCase
{
    private TriageSubmissionRepository&MockObject $repository;
    private TriageAnalyzerInterface&MockObject $analyzer;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageBusInterface&MockObject $messageBus;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TriageSubmissionRepository::class);
        $this->analyzer = $this->createMock(TriageAnalyzerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->user = User::register(
            'test-' . uniqid() . '@example.com',
            '$2y$13$hashedpassword',
        );
    }

    private function createHandler(): SubmitTriageHandler
    {
        return new SubmitTriageHandler(
            $this->repository,
            $this->analyzer,
            $this->entityManager,
            $this->messageBus,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 1: AI returns question → submission gets question in conversation
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCreatesSubmissionAndAddsQuestionToConversation(): void
    {
        $description = 'I have a headache that will not go away';

        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->with($description)
            ->willReturn(['type' => 'question', 'content' => 'How long have you had this headache?']);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($submission) {
                return $submission->getUser() === $this->user
                    && $submission->getStatus() === TriageStatus::Pending
                    && $submission->getCurrentTurn() === 0;
            }));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (ProcessTriageMessage $message) {
                return true;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $result = $handler($command);

        $this->assertSame(TriageStatus::AwaitingAnswer, $result->getStatus());
        $this->assertSame(1, $result->getCurrentTurn());

        $history = $result->getConversationHistory();
        $this->assertCount(2, $history);

        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame($description, $history[0]['content']);

        $this->assertSame('question', $history[1]['type']);
        $this->assertSame('How long have you had this headache?', $history[1]['content']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 2: AI returns result → submission completed with outcome
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCompletesSubmissionWhenAiReturnsResult(): void
    {
        $description = 'Chest pain radiating to left arm';

        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->with($description)
            ->willReturn([
                'type' => 'result',
                'specialist' => 'Cardiologist',
                'urgency' => 'HIGH',
                'justification' => 'Patient reports chest pain with radiating symptoms indicative of possible cardiac event.',
            ]);

        $this->repository->expects($this->once())
            ->method('save');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $result = $handler($command);

        $this->assertSame(TriageStatus::Completed, $result->getStatus());
        $this->assertNotNull($result->getOutcome());
        $this->assertSame('Cardiologist', $result->getOutcome()->getSpecialist());
        $this->assertSame('HIGH', $result->getOutcome()->getUrgency());
        $this->assertNotNull($result->getProcessedAt());

        $history = $result->getConversationHistory();
        $this->assertCount(2, $history);
        $this->assertSame('result', $history[1]['type']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 3: Description exceeds 500 chars → throws InvalidArgumentException
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerThrowsExceptionWhenDescriptionExceedsLimit(): void
    {
        $description = str_repeat('x', 501);

        $this->analyzer->expects($this->never())
            ->method('analyzeInitial');

        $this->repository->expects($this->never())
            ->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length of 500 characters');

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $handler($command);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 4: Analyzer throws → submission marked as failed
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerMarksSubmissionFailedWhenAnalyzerThrows(): void
    {
        $description = 'I feel unwell';

        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->with($description)
            ->willThrowException(new TriageAnalysisFailedException('AI communication failed'));

        $this->repository->expects($this->once())
            ->method('save');

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $result = $handler($command);

        $this->assertSame(TriageStatus::Failed, $result->getStatus());
        $this->assertNull($result->getOutcome());
        $this->assertNull($result->getProcessedAt());
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 5: Handler persists before calling analyzer (spy test)
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerPersistsSubmissionBeforeCallingAnalyzer(): void
    {
        $description = 'Back pain for 3 weeks';
        $persistCalled = false;
        $analyzerCalledAfterPersist = false;

        $this->repository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function () use (&$persistCalled) {
                $persistCalled = true;
            });

        $this->analyzer->expects($this->once())
            ->method('analyzeInitial')
            ->willReturnCallback(function () use (&$persistCalled, &$analyzerCalledAfterPersist) {
                $analyzerCalledAfterPersist = $persistCalled;
                return ['type' => 'question', 'content' => 'Where exactly is the pain?'];
            });

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $handler($command);

        $this->assertTrue($analyzerCalledAfterPersist, 'Analyzer should be called AFTER the submission is persisted');
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 6: No message dispatched when result is returned
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerDoesNotDispatchMessageWhenResultIsReturned(): void
    {
        $description = 'Shortness of breath';

        $this->analyzer->method('analyzeInitial')
            ->willReturn([
                'type' => 'result',
                'specialist' => 'Pulmonologist',
                'urgency' => 'HIGH',
                'justification' => 'Respiratory distress requires immediate evaluation.',
            ]);

        $this->repository->method('save');
        $this->entityManager->method('flush');

        $this->messageBus->expects($this->never())
            ->method('dispatch');

        $command = new SubmitTriageCommand($description, $this->user);
        $handler = $this->createHandler();
        $handler($command);
    }

    // ─────────────────────────────────────────────────────────────────
    // Class structure tests
    // ─────────────────────────────────────────────────────────────────

    public function testFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Triage/Application/Command/SubmitTriageHandler.php');
        $this->assertNotFalse($content, 'SubmitTriageHandler.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testCommandFileHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../../../../src/Triage/Application/Command/SubmitTriageCommand.php');
        $this->assertNotFalse($content, 'SubmitTriageCommand.php file must exist');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }
}
