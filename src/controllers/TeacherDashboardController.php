<?php
defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../services/TeacherDashboardService.php';

/**
 * Prépare uniquement les données d'affichage du dashboard enseignant.
 * La vue conserve ses contrôles d'accès historiques et son rendu.
 */
class TeacherDashboardController
{
    private TeacherDashboardService $service;

    public function __construct(?TeacherDashboardService $service = null)
    {
        $this->service = $service ?? new TeacherDashboardService();
    }

    public function getViewData(int $userId): array
    {
        return $this->service->getDashboardData($userId);
    }
}
