<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../services/ParentDashboardService.php';

class ParentDashboardController
{
    private ParentDashboardService $service;
    public function __construct(?ParentDashboardService $service = null)
    {
        $this->service = $service ?? new ParentDashboardService();
    }
    public function getViewData(int $userId): array
    {
        return $this->service->getDashboardData($userId);
    }
}
