<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/AttendanceRegisterRepository.php';

class AttendanceRegisterService
{
    private AttendanceRegisterRepository $repository;

    public function __construct(?AttendanceRegisterRepository $repository = null)
    {
        $this->repository = $repository ?? new AttendanceRegisterRepository();
    }

    public function getClosureForDate(int $yearId, string $date, int $classId, string $className): ?string
    {
        return $this->getClosureFromEvents(
            $this->repository->findClosuresOnDate($yearId, $date),
            $date,
            $classId,
            $className
        );
    }

    public function getClosureFromEvents(
        array $events,
        string $date,
        int $classId,
        string $className
    ): ?string {
        foreach ($events as $event) {
            if ($date < $event['date_debut'] || $date > $event['date_fin']) {
                continue;
            }

            $classes = trim((string) ($event['concerne_classes'] ?? ''));
            if ($classes !== '') {
                $targets = array_map('trim', explode(',', $classes));
                if (
                    !in_array((string) $classId, $targets, true)
                    && !in_array($className, $targets, true)
                ) {
                    continue;
                }
            }

            return $event['titre'];
        }

        return null;
    }

    public function isScheduledDay(int $classId, int $yearId, string $date): ?bool
    {
        $scheduledDays = $this->repository->findScheduledDays($classId, $yearId);
        if (!$scheduledDays) {
            return null;
        }

        $weekdayMap = [
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
        ];
        $weekday = $weekdayMap[date('l', strtotime($date))] ?? null;

        return $weekday !== null && in_array($weekday, array_column($scheduledDays, 'jour'), true);
    }

    public function getClosuresInRange(int $yearId, string $startDate, string $endDate): array
    {
        return $this->repository->findClosuresInRange($yearId, $startDate, $endDate);
    }

    public function getScheduledDayRows(int $classId, int $yearId): array
    {
        return $this->repository->findScheduledDayRows($classId, $yearId);
    }
}
