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

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['submittedAt' => 'DESC']);
    }

    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSynthetic(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.isSynthetic = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.status, COUNT(t.id) AS cnt')
            ->groupBy('t.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $status = $row['status'];
            if ($status instanceof \App\Triage\Domain\Entity\TriageStatus) {
                $status = $status->value;
            }
            $counts[(string) $status] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countBySpecialist(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.outcome.specialist AS specialist, COUNT(t.id) AS cnt')
            ->where('t.outcome.specialist IS NOT NULL')
            ->groupBy('t.outcome.specialist')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $specialist = $row['specialist'] ?? (string) ($row['outcome.specialist'] ?? '');
            if ($specialist !== '') {
                $counts[(string) $specialist] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    public function countByUrgency(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.outcome.urgency AS urgency, COUNT(t.id) AS cnt')
            ->where('t.outcome.urgency IS NOT NULL')
            ->groupBy('t.outcome.urgency')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $urgency = $row['urgency'] ?? (string) ($row['outcome.urgency'] ?? '');
            if ($urgency !== '') {
                $counts[(string) $urgency] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    public function avgProcessingDuration(): ?int
    {
        $result = (int) $this->createQueryBuilder('t')
            ->select('AVG(t.processingDuration)')
            ->where('t.processingDuration IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0 ? $result : null;
    }
}
