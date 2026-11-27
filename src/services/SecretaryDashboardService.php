<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../repositories/SecretaryDashboardRepository.php';

/** Cas d'usage et préparation des données du dashboard secrétaire. */
class SecretaryDashboardService
{
    private SecretaryDashboardRepository $repository;
    public function __construct(?SecretaryDashboardRepository $repository = null) { $this->repository = $repository ?? new SecretaryDashboardRepository(); }
    public function getDashboardData(int $userId): array
    {
        $yearId = (int) $this->repository->activeYearId();
        $gender = $this->repository->genderCounts();
        return [
            'anneeActive' => $yearId,
            'totalEleves' => $this->repository->activeStudentCount($yearId),
            'totalEnseignants' => $this->repository->activeTeacherCount(),
            'totalClasses' => $this->repository->classCount($yearId),
            'nouvellesInscriptions' => $this->repository->newRegistrationsThisMonth(),
            'inscriptionsAttente' => $this->repository->recentActiveRegistrations(),
            'elevesParClasse' => $this->repository->studentsPerClass($yearId),
            'totalGarcons' => $gender['boys'],
            'totalFilles' => $gender['girls'],
            'derniersInscrits' => $this->repository->latestRegistrations(),
            'prochainsEvenements' => $this->repository->upcomingEvents($yearId),
            'absencesJour' => $this->repository->absencesToday(),
            'messagesNonLus' => $this->repository->unreadMessages($userId),
        ];
    }
}