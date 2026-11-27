<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AttendanceRegisterService.php';

final class InMemoryAttendanceRegisterRepository extends AttendanceRegisterRepository
{
    public array $closures = [];
    public array $scheduledDays = [];

    public function __construct()
    {
    }

    public function findClosuresOnDate(int $yearId, string $date): array
    {
        return $this->closures;
    }

    public function findScheduledDays(int $classId, int $yearId): array
    {
        return $this->scheduledDays;
    }

    public function findClosuresInRange(int $yearId, string $startDate, string $endDate): array
    {
        return $this->closures;
    }

    public function findScheduledDayRows(int $classId, int $yearId): array
    {
        return $this->scheduledDays;
    }
}

$repository = new InMemoryAttendanceRegisterRepository();
$service = new AttendanceRegisterService($repository);
$repository->closures = [
    [
        'titre' => 'Fête scolaire',
        'date_debut' => '2026-12-01',
        'date_fin' => '2026-12-03',
        'concerne_classes' => '4, 7',
    ],
];

if ($service->getClosureForDate(1, '2026-12-02', 7, 'CM1') !== 'Fête scolaire') {
    throw new RuntimeException('A matching calendar closure was not returned.');
}
if ($service->getClosureForDate(1, '2026-12-02', 8, 'CM2') !== null) {
    throw new RuntimeException('A closure targeted at other classes was incorrectly applied.');
}
if ($service->getClosureFromEvents([], '2026-12-02', 7, 'CM1') !== null) {
    throw new RuntimeException('An absent calendar event should not close the day.');
}

if ($service->isScheduledDay(7, 1, '2026-11-23') !== null) {
    throw new RuntimeException('No timetable should preserve the unknown schedule state.');
}
$repository->scheduledDays = [['jour' => 'Lundi'], ['jour' => 'Mercredi']];
if ($service->isScheduledDay(7, 1, '2026-11-23') !== true) {
    throw new RuntimeException('A configured weekday was not recognized.');
}
if ($service->isScheduledDay(7, 1, '2026-11-24') !== false) {
    throw new RuntimeException('An unconfigured weekday should not be scheduled.');
}
if ($service->getScheduledDayRows(7, 1) !== $repository->scheduledDays) {
    throw new RuntimeException('Scheduled timetable rows were not delegated.');
}
if ($service->getClosuresInRange(1, '2026-12-01', '2026-12-03') !== $repository->closures) {
    throw new RuntimeException('Calendar closure rows were not delegated.');
}

fwrite(STDOUT, "Attendance register service smoke tests passed.\n");
