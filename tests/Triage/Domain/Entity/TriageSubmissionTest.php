<?php

declare(strict_types=1);

namespace App\Tests\Triage\Domain\Entity;

use App\Triage\Domain\Entity\TriageOutcome;
use App\Triage\Domain\Entity\TriageStatus;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TriageSubmissionTest extends TestCase
{
    private const string TEST_EMAIL = 'triage-test@example.com';
    private const string TEST_PASSWORD = '$2y$13$hashedpasswordstringhere';

    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(self::TEST_EMAIL, self::TEST_PASSWORD);
    }

    // ─── Submit (Named Constructor) ──────────────────────────────────

    public function testSubmitCreatesSubmissionWithPendingStatus(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertSame($this->user, $submission->getUser());
        $this->assertSame(TriageStatus::Pending, $submission->getStatus());
        $this->assertSame(0, $submission->getCurrentTurn());
        $this->assertFalse($submission->isSynthetic());
        $this->assertNull($submission->getOutcome());
        $this->assertNull($submission->getProcessedAt());
        $this->assertGreaterThan(
            new \DateTimeImmutable('-1 minute'),
            $submission->getSubmittedAt(),
        );
    }

    public function testSubmitStoresInitialDescriptionInConversation(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'I have a severe headache and dizziness',
        );

        $history = $submission->getConversationHistory();
        $this->assertCount(1, $history);
        $this->assertSame('initial_description', $history[0]['type']);
        $this->assertSame('I have a severe headache and dizziness', $history[0]['content']);
        $this->assertArrayHasKey('timestamp', $history[0]);
    }

    public function testSubmitEnforcesInitialDescriptionCharacterLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/description|500/i');

        \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            str_repeat('x', 501),
        );
    }

    public function testSubmitAllowsMaxLengthInitialDescription(): void
    {
        $description = str_repeat('x', 500);

        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            $description,
        );

        $history = $submission->getConversationHistory();
        $this->assertCount(1, $history);
        $this->assertSame(500, mb_strlen($history[0]['content']));
    }

    // ─── addAiQuestion ──────────────────────────────────────────────

    public function testAddAiQuestionIncrementsCurrentTurn(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertSame(0, $submission->getCurrentTurn());

        $submission->addAiQuestion('When did the pain start?');
        $this->assertSame(1, $submission->getCurrentTurn());

        $submission->addAiQuestion('Do you have any other symptoms?');
        $this->assertSame(2, $submission->getCurrentTurn());
    }

    public function testAddAiQuestionAddsQuestionEntryToConversation(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did the pain start?');

        $history = $submission->getConversationHistory();
        $this->assertCount(2, $history); // initial + question

        $questionEntry = $history[1];
        $this->assertSame('question', $questionEntry['type']);
        $this->assertSame('When did the pain start?', $questionEntry['content']);
        $this->assertArrayHasKey('timestamp', $questionEntry);
    }

    public function testAddAiQuestionChangesStatusToAwaitingAnswer(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did the pain start?');

        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());
    }

    public function testAddAiQuestionEnforcesCharacterLimit(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/question|1000/i');

        $submission->addAiQuestion(str_repeat('x', 1001));
    }

    public function testAddAiQuestionAllowsMaxLengthQuestion(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $question = str_repeat('x', 1000);
        $submission->addAiQuestion($question);

        $history = $submission->getConversationHistory();
        $this->assertSame(1000, mb_strlen($history[1]['content']));
    }

    public function testAddAiQuestionDoesNotSetOutcome(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did it start?');

        $this->assertNull($submission->getOutcome());
        $this->assertNull($submission->getProcessedAt());
    }

    // ─── addUserAnswer ──────────────────────────────────────────────

    public function testAddUserAnswerAddsAnswerEntryToConversation(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did the pain start?');
        $submission->addUserAnswer('It started yesterday morning');

        $history = $submission->getConversationHistory();
        $this->assertCount(3, $history); // initial + question + answer

        $answerEntry = $history[2];
        $this->assertSame('answer', $answerEntry['type']);
        $this->assertSame('It started yesterday morning', $answerEntry['content']);
        $this->assertArrayHasKey('timestamp', $answerEntry);
    }

    public function testAddUserAnswerDoesNotIncrementTurn(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did the pain start?');
        $this->assertSame(1, $submission->getCurrentTurn());

        $submission->addUserAnswer('It started yesterday');
        $this->assertSame(1, $submission->getCurrentTurn(), 'User answer should not increment currentTurn');
    }

    public function testAddUserAnswerChangesStatusToProcessing(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did the pain start?');
        $submission->addUserAnswer('It started yesterday');

        $this->assertSame(TriageStatus::Processing, $submission->getStatus());
    }

    public function testAddUserAnswerEnforcesCharacterLimit(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did it start?');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/answer|300/i');

        $submission->addUserAnswer(str_repeat('x', 301));
    }

    public function testAddUserAnswerAllowsMaxLengthAnswer(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did it start?');
        $answer = str_repeat('x', 300);
        $submission->addUserAnswer($answer);

        $history = $submission->getConversationHistory();
        $this->assertSame(300, mb_strlen($history[2]['content']));
    }

    // ─── completeWithOutcome ────────────────────────────────────────

    public function testCompleteWithOutcomeSetsOutcomeAndCompletesStatus(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did it start?');
        $submission->addUserAnswer('Yesterday');

        $outcome = TriageOutcome::create('NEUROLOGIST', 'HIGH', 'Severe headache requires specialist evaluation');
        $submission->completeWithOutcome($outcome);

        $this->assertSame($outcome, $submission->getOutcome());
        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $submission->getProcessedAt());
    }

    public function testCompleteWithOutcomeAddsResultEntryToConversation(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $outcome = TriageOutcome::create('GP', 'LOW', 'Mild symptoms, rest recommended');
        $submission->completeWithOutcome($outcome);

        $history = $submission->getConversationHistory();
        $this->assertCount(2, $history); // initial + result

        $resultEntry = $history[1];
        $this->assertSame('result', $resultEntry['type']);
        $this->assertStringContainsString('GP', $resultEntry['content']);
        $this->assertStringContainsString('LOW', $resultEntry['content']);
        $this->assertArrayHasKey('timestamp', $resultEntry);
    }

    public function testCompleteWithOutcomeSetsProcessedAt(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertNull($submission->getProcessedAt());

        $outcome = TriageOutcome::create('GP', 'LOW', 'Mild');
        $submission->completeWithOutcome($outcome);

        $processedAt = $submission->getProcessedAt();
        $this->assertNotNull($processedAt);
    }

    // ─── markFailed ─────────────────────────────────────────────────

    public function testMarkFailedSetsStatusToFailed(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->markFailed();

        $this->assertSame(TriageStatus::Failed, $submission->getStatus());
    }

    public function testMarkFailedDoesNotSetOutcome(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->markFailed();

        $this->assertNull($submission->getOutcome());
    }

    // ─── Conversation History JSON Structure ────────────────────────

    public function testConversationHistoryHasNoRoleField(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission->addAiQuestion('When did it start?');
        $submission->addUserAnswer('Yesterday');

        foreach ($submission->getConversationHistory() as $entry) {
            $this->assertArrayNotHasKey('role', $entry, 'Conversation entries must NOT have a "role" field');
            $this->assertArrayHasKey('type', $entry, 'Each entry must have a "type" field');
            $this->assertArrayHasKey('content', $entry, 'Each entry must have a "content" field');
            $this->assertArrayHasKey('timestamp', $entry, 'Each entry must have a "timestamp" field');
        }
    }

    public function testConversationHistoryTypesMatchExpectedValues(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertSame('initial_description', $submission->getConversationHistory()[0]['type']);

        $submission->addAiQuestion('When did it start?');
        $this->assertSame('question', $submission->getConversationHistory()[1]['type']);

        $submission->addUserAnswer('Yesterday');
        $this->assertSame('answer', $submission->getConversationHistory()[2]['type']);
    }

    // ─── Status Transitions ─────────────────────────────────────────

    public function testFullInterviewFlowStatusTransitions(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        // After submit: pending
        $this->assertSame(TriageStatus::Pending, $submission->getStatus());

        // AI responds with question
        $submission->addAiQuestion('When did the pain start?');
        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());

        // User answers
        $submission->addUserAnswer('It started yesterday');
        $this->assertSame(TriageStatus::Processing, $submission->getStatus());

        // AI responds with another question
        $submission->addAiQuestion('Do you have a fever?');
        $this->assertSame(TriageStatus::AwaitingAnswer, $submission->getStatus());

        // User answers again
        $submission->addUserAnswer('No fever');
        $this->assertSame(TriageStatus::Processing, $submission->getStatus());

        // AI produces result
        $outcome = TriageOutcome::create('GP', 'LOW', 'Mild symptoms, rest recommended');
        $submission->completeWithOutcome($outcome);
        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
    }

    public function testFullInterviewFlowWithThreeTurns(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        // Turn 1
        $submission->addAiQuestion('When did the pain start?');
        $submission->addUserAnswer('Yesterday morning');
        $this->assertSame(1, $submission->getCurrentTurn());

        // Turn 2
        $submission->addAiQuestion('Do you have a fever?');
        $submission->addUserAnswer('No');
        $this->assertSame(2, $submission->getCurrentTurn());

        // Turn 3
        $submission->addAiQuestion('Have you taken any medication?');
        $submission->addUserAnswer('Ibuprofen, no relief');
        $this->assertSame(3, $submission->getCurrentTurn());

        // Complete after 3 turns
        $outcome = TriageOutcome::create('NEUROLOGIST', 'MEDIUM', 'Persistent headache with no relief');
        $submission->completeWithOutcome($outcome);

        $this->assertSame(TriageStatus::Completed, $submission->getStatus());
        $this->assertCount(8, $submission->getConversationHistory());
        // initial_description + 3 questions + 3 answers + 1 result = 8
    }

    // ─── UUID ───────────────────────────────────────────────────────

    public function testIdIsUuidV4Instance(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $id = $submission->getId();
        $this->assertNotEmpty($id->toRfc4122());
    }

    public function testEachSubmissionHasUniqueId(): void
    {
        $submission1 = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $submission2 = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My stomach hurts',
        );

        $this->assertNotSame(
            $submission1->getId()->toRfc4122(),
            $submission2->getId()->toRfc4122(),
        );
    }

    // ─── Default Values ─────────────────────────────────────────────

    public function testIsSyntheticDefaultsToFalse(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertFalse($submission->isSynthetic());
    }

    public function testSubmittedAtIsSetOnCreation(): void
    {
        $before = new \DateTimeImmutable();

        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $after = new \DateTimeImmutable();

        $submittedAt = $submission->getSubmittedAt();
        $this->assertGreaterThanOrEqual($before, $submittedAt);
        $this->assertLessThanOrEqual($after, $submittedAt);
    }

    public function testProcessedAtIsNullUntilCompletion(): void
    {
        $submission = \App\Triage\Domain\Entity\TriageSubmission::submit(
            $this->user,
            'My head hurts',
        );

        $this->assertNull($submission->getProcessedAt());

        $submission->addAiQuestion('When did it start?');
        $this->assertNull($submission->getProcessedAt());

        $submission->addUserAnswer('Yesterday');
        $this->assertNull($submission->getProcessedAt());

        $outcome = TriageOutcome::create('GP', 'LOW', 'Mild');
        $submission->completeWithOutcome($outcome);
        $this->assertNotNull($submission->getProcessedAt());
    }

    // ─── Class Structure ────────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(\App\Triage\Domain\Entity\TriageSubmission::class);

        $this->assertTrue($reflection->isFinal(), 'TriageSubmission must be a final class');
    }

    public function testClassHasStrictTypes(): void
    {
        $filePath = (new \ReflectionClass(\App\Triage\Domain\Entity\TriageSubmission::class))->getFileName();
        $this->assertIsString($filePath);

        $fileContents = file_get_contents($filePath);
        $this->assertStringContainsString('declare(strict_types=1)', (string) $fileContents);
    }
}
