<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AbsenceService.php';

final class InMemoryAbsenceRepository extends AbsenceRepository
{
    public bool $hasEnrollment = true;
    public ?int $existingAbsenceId = null;
    public array $calls = [];

    public function __construct()
    {
    }

    public function hasActiveEnrollment(int $studentId): bool
    {
        return $this->hasEnrollment;
    }

    public function findAbsenceId(int $studentId, string $date): ?int
    {
        return $this->existingAbsenceId;
    }

    public function updateStatus(int $absenceId, string $type, ?string $reason, int $userId): void
    {
        $this->calls[] = ['updateStatus', $absenceId, $type, $reason, $userId];
    }

    public function createStatus(int $studentId, string $date, string $type, ?string $reason, int $userId): void
    {
        $this->calls[] = ['createStatus', $studentId, $date, $type, $reason, $userId];
    }

    public function updateReason(int $studentId, string $date, ?string $reason): void
    {
        $this->calls[] = ['updateReason', $studentId, $date, $reason];
    }

    public function findAbsenceIdForJustification(int $studentId, string $date): ?int
    {
        return $this->existingAbsenceId;
    }

    public function updateJustification(int $absenceId, string $reason): void
    {
        $this->calls[] = ['updateJustification', $absenceId, $reason];
    }

    public function createJustifiedAbsence(int $studentId, string $date, string $reason, int $userId): void
    {
        $this->calls[] = ['createJustifiedAbsence', $studentId, $date, $reason, $userId];
    }
}

$repository = new InMemoryAbsenceRepository();
$service = new AbsenceService($repository);

if (!$service->markStatus(10, '2026-11-20', 'Absence', 'Maladie', 5)) {
    throw new RuntimeException('An actively enrolled student was rejected.');
}
if ($repository->calls[0] !== ['createStatus', 10, '2026-11-20', 'Absence', 'Maladie', 5]) {
    throw new RuntimeException('A new daily status was not created with its original values.');
}

$repository->existingAbsenceId = 24;
if (!$service->markStatus(10, '2026-11-20', 'Présent', '', 5)) {
    throw new RuntimeException('An existing daily status was rejected.');
}
if ($repository->calls[1] !== ['updateStatus', 24, 'Présent', null, 5]) {
    throw new RuntimeException('An existing daily status was not updated.');
}

$repository->hasEnrollment = false;
if ($service->markStatus(10, '2026-11-20', 'Absence', '', 5) || count($repository->calls) !== 2) {
    throw new RuntimeException('A student without active enrollment was allowed to be marked.');
}

if (!$service->saveReason(10, '2026-11-20', 'Justificatif remis')) {
    throw new RuntimeException('An existing absence reason was not saved.');
}
if ($repository->calls[2] !== ['updateReason', 10, '2026-11-20', 'Justificatif remis']) {
    throw new RuntimeException('The saved absence reason differs from the submitted value.');
}

if ($service->justifyAbsence(10, '2026-11-20', 'Maladie', 5) !== 24) {
    throw new RuntimeException('The existing absence ID was not returned after justification.');
}
if ($repository->calls[3] !== ['updateJustification', 24, 'Maladie']) {
    throw new RuntimeException('The existing absence was not justified.');
}

$repository->existingAbsenceId = null;
if ($service->justifyAbsence(10, '2026-11-19', 'Rendez-vous', 5) !== null) {
    throw new RuntimeException('A newly created justified absence returned an existing ID.');
}
if ($repository->calls[4] !== ['createJustifiedAbsence', 10, '2026-11-19', 'Rendez-vous', 5]) {
    throw new RuntimeException('A missing absence was not created as justified.');
}

$studentStats = AbsenceService::calculateStudentStatistics([
    ['type_absence' => 'Absence', 'est_justifiee' => 1],
    ['type_absence' => 'Présent', 'est_justifiee' => 0],
    ['type_absence' => 'Absence', 'est_justifiee' => 0],
]);
if (
    $studentStats['total_jours'] !== 3
    || $studentStats['total_absences'] !== 2
    || $studentStats['total_justifiees'] !== 1
    || $studentStats['taux_absenteisme'] !== 66.7
) {
    throw new RuntimeException('Student absence statistics were calculated incorrectly.');
}

fwrite(STDOUT, "Absence service smoke tests passed.\n");
