<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../services/AccountantDashboardService.php';
class AccountantDashboardController
{
    private AccountantDashboardService $service;
    public function __construct(?AccountantDashboardService $service = null) { $this->service = $service ?? new AccountantDashboardService(); }
    public function getViewData(): array { return $this->service->getDashboardData(); }
}