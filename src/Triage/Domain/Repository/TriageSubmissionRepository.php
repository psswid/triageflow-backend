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

    /**
     * Returns all Triage Submissions ordered by submittedAt descending.
     *
     * @return array<int, TriageSubmission>
     */
    public function findAllOrdered(): array;

    /** Total count of all submissions. */
    public function countTotal(): int;

    /** Count of synthetic submissions. */
    public function countSynthetic(): int;

    /**
     * Counts submissions grouped by status.
     *
     * @return array<string, int> e.g. ['pending' => 5, 'completed' => 12]
     */
    public function countByStatus(): array;

    /**
     * Counts completed submissions grouped by specialist.
     *
     * @return array<string, int> e.g. ['Cardiologist' => 3, 'Neurologist' => 7]
     */
    public function countBySpecialist(): array;

    /**
     * Counts completed submissions grouped by urgency.
     *
     * @return array<string, int> e.g. ['HIGH' => 8, 'LOW' => 4]
     */
    public function countByUrgency(): array;

    /** Average processing duration in seconds for completed submissions, or null if none. */
    public function avgProcessingDuration(): ?int;
}
