<?php

declare(strict_types=1);

namespace App\Triage\Infrastructure\Repository;

use App\Triage\Domain\Entity\TriageSubmission;
use App\Triage\Domain\Repository\TriageSubmissionRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TriageSubmission>
 */
final class DoctrineTriageSubmissionRepository extends ServiceEntityRepository implements TriageSubmissionRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TriageSubmission::class);
    }

    public function save(TriageSubmission $submission): void
    {
        $this->getEntityManager()->persist($submission);
        $this->getEntityManager()->flush();
    }

    public function findById(Uuid $id): ?TriageSubmission
    {
        return $this->find($id);
    }

    public function findByUser(Uuid $userId): array
    {
        return $this->findBy(
            ['user' => $userId],
            ['submittedAt' => 'DESC'],
        );
    }
}
