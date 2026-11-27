<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../services/SecretaryDashboardService.php';

/** Contrôleur de préparation HTTP/vues du dashboard secrétaire. */
class SecretaryDashboardController
{
    private SecretaryDashboardService $service;
    public function __construct(?SecretaryDashboardService $service = null) { $this->service = $service ?? new SecretaryDashboardService(); }
    public function getViewData(int $userId): array { return $this->service->getDashboardData($userId); }
}