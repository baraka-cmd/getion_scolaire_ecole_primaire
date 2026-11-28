<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/TeacherDashboardRepository.php';

class TeacherDashboardService
{
    private TeacherDashboardRepository $repository;

    public function __construct(?TeacherDashboardRepository $repository = null)
    {
        $this->repository = $repository ?? new TeacherDashboardRepository();
    }

    public function getDashboardData(int $userId): array
    {
        $class = $this->repository->assignedClass($userId);
        $classId = (int) ($class['id'] ?? 0);
        $termId = 0;
        $students = $present = $absent = $notes = $boys = $girls = 0;
        $attendanceByDay = [];
        $recentAbsences = $exams = $studentsInDifficulty = $birthdays = [];

        if ($classId > 0) {
            $students = $this->repository->activeStudentCount($classId);
            $attendance = $this->repository->dailyAttendance($classId);
            $present = $attendance['present'];
            $absent = $attendance['absent'];
            $termId = $this->repository->activeTermId();
            $notes = $this->repository->enteredNotesCount($classId, $termId);
            for ($day = 1; $day <= (int) date('d'); $day++) {
                $date = date('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                $attendanceByDay[] = $this->repository->attendanceForDate($classId, $date);
            }
            $gender = $this->repository->genderCounts($classId);
            $boys = $gender['boys'];
            $girls = $gender['girls'];
            $recentAbsences = $this->repository->recentAbsences($classId);
            $exams = $this->repository->upcomingExams($classId);
            $studentsInDifficulty = $this->repository->studentsInDifficulty($classId, $termId);
            $birthdays = $this->repository->upcomingBirthdays($classId);
        } else {
            // Le comportement historique affiche les listes vides pour une classe non attribuée.
            $recentAbsences = $this->repository->recentAbsences(0);
            $exams = $this->repository->upcomingExams(0);
            $studentsInDifficulty = $this->repository->studentsInDifficulty(0, 0);
            $birthdays = $this->repository->upcomingBirthdays(0);
        }

        return [
            'maClasse' => $class,
            'classeId' => $classId,
            'classeNom' => $class['nom'] ?? 'Aucune classe assignée',
            'totalEleves' => $students,
            'presencesJour' => $present,
            'absencesJour' => $absent,
            'tauxPresence' => $students > 0 ? round(($present / $students) * 100, 1) : 0,
            'notesSaisies' => $notes,
            'notesAttendues' => $students * 9 * 3,
            'trimestreActif' => $termId,
            'presenceMois' => $attendanceByDay,
            'garcons' => $boys,
            'filles' => $girls,
            'dernieresAbsences' => $recentAbsences,
            'prochainsExamens' => $exams,
            'elevesDifficulte' => $studentsInDifficulty,
            'prochainsAnniversaires' => $birthdays,
        ];
    }
}
