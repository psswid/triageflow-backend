<?php

declare(strict_types=1);

namespace App\Tests\Synthetic\Application\Command;

use App\Shared\Infrastructure\Ai\OpenRouterClientInterface;
use App\Synthetic\Application\Command\GenerateSyntheticCaseCommand;
use App\Synthetic\Application\Command\GenerateSyntheticCaseHandler;
use App\Synthetic\Application\Message\ProcessSyntheticCaseMessage;
use App\Synthetic\Application\Service\SyntheticSystemPrompt;
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
    private TriageSubmissionRepository&MockObject $submissionRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageBusInterface&MockObject $messageBus;
    private User $systemUser;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->openRouter = $this->createMock(OpenRouterClientInterface::class);
        $this->syntheticPrompt = new SyntheticSystemPrompt();
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
            $this->submissionRepository,
            $this->entityManager,
            $this->messageBus,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 1: Success — submission created and async message dispatched
    // ─────────────────────────────────────────────────────────────────

    public function testHandlerCreatesSubmissionAndDispatchesAsyncMessage(): void
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

        // Arrange: flush called after saving
        $this->entityManager->expects($this->once())
            ->method('flush');

        // Arrange: async message dispatched for AI analysis
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (object $message): bool {
                return $message instanceof ProcessSyntheticCaseMessage
                    && $message->submissionId instanceof Uuid;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // Act
        $handler = $this->createHandler();
        $result = $handler(new GenerateSyntheticCaseCommand());

        // Assert
        $this->assertSame(TriageStatus::Pending, $result->getStatus());
        $this->assertNull($result->getOutcome());
        $this->assertNull($result->getProcessedAt());
        $this->assertTrue($result->isSynthetic());

        $history = $result->getConversationHistory();
        $this->assertCount(1, $history);
        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame($symptom, $history[0]['content']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Test 2: System user not found → throws RuntimeException
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
    // Test 3: Empty symptom after retry → throws RuntimeException
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
