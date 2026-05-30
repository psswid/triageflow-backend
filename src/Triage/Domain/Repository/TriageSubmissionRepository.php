<?php

declare(strict_types=1);

namespace App\Triage\Domain\Repository;

use App\Triage\Domain\Entity\TriageSubmission;
use Symfony\Component\Uid\Uuid;

interface TriageSubmissionRepository
{
    public function save(TriageSubmission $submission): void;

    public function findById(Uuid $id): ?TriageSubmission;

    /**
     * Returns all Triage Submissions belonging to the given User,
     * ordered by submittedAt descending (newest first).
     *
     * @return array<int, TriageSubmission>
     */
    public function findByUser(Uuid $userId): array;
}
