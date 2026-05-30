<?php

declare(strict_types=1);

namespace App\Tests\Triage\Infrastructure\Repository;

use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Infrastructure\Repository\DoctrineTriageSubmissionRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineTriageSubmissionRepositoryTest extends KernelTestCase
{
    private DoctrineTriageSubmissionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DoctrineTriageSubmissionRepository::class);
    }

    public function testSaveAndFindById(): void
    {
        $user = $this->createAndPersistUser();
        $submission = TriageSubmission::submit($user, 'Test initial symptom description for repository');

        $this->repository->save($submission);

        $found = $this->repository->findById($submission->getId());
        $this->assertNotNull($found);
        $this->assertSame($submission->getId()->toRfc4122(), $found->getId()->toRfc4122());
        $this->assertSame($user->getId()->toRfc4122(), $found->getUser()->getId()->toRfc4122());
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findById(Uuid::v4());
        $this->assertNull($found);
    }

    public function testFindByUserReturnsSubmissionsOrderedBySubmittedAtDesc(): void
    {
        $user = $this->createAndPersistUser();

        // Create submissions with distinct timestamps
        $sub1 = TriageSubmission::submit($user, 'First submission');
        $this->repository->save($sub1);
        \sleep(1);

        $sub2 = TriageSubmission::submit($user, 'Second submission');
        $this->repository->save($sub2);

        $results = $this->repository->findByUser($user->getId());
        $this->assertCount(2, $results);

        // Verify DESC ordering by timestamps (not by detached entity IDs)
        $this->assertGreaterThan(
            $results[1]->getSubmittedAt(),
            $results[0]->getSubmittedAt(),
            'Newest submission should be first (DESC order)',
        );
    }

    public function testFindByUserReturnsOnlyThatUsersSubmissions(): void
    {
        $userA = $this->createAndPersistUser();
        $userB = $this->createAndPersistUser();

        $subA1 = TriageSubmission::submit($userA, 'User A first');
        $subA2 = TriageSubmission::submit($userA, 'User A second');
        $subB1 = TriageSubmission::submit($userB, 'User B first');

        $this->repository->save($subA1);
        $this->repository->save($subA2);
        $this->repository->save($subB1);

        $resultsA = $this->repository->findByUser($userA->getId());
        $this->assertCount(2, $resultsA, 'User A should have exactly 2 submissions');

        $resultsB = $this->repository->findByUser($userB->getId());
        $this->assertCount(1, $resultsB, 'User B should have exactly 1 submission');
    }

    public function testFindByUserReturnsEmptyArrayForUserWithNoSubmissions(): void
    {
        $user = $this->createAndPersistUser();

        $results = $this->repository->findByUser($user->getId());
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function createAndPersistUser(): User
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = new User(
            'repo-test-' . \uniqid() . '@example.com',
            '$2y$13$hashedpassword',
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
