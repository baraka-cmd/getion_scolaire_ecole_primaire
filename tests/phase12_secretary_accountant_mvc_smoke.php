<?php
declare(strict_types=1);
$files = [
 'secretary_view' => __DIR__ . '/../public/views/dashboard/secretaire.php',
 'secretary_controller' => __DIR__ . '/../src/controllers/SecretaryDashboardController.php',
 'secretary_service' => __DIR__ . '/../src/services/SecretaryDashboardService.php',
 'secretary_repository' => __DIR__ . '/../src/repositories/SecretaryDashboardRepository.php',
 'accountant_view' => __DIR__ . '/../public/views/dashboard/comptable.php',
 'accountant_controller' => __DIR__ . '/../src/controllers/AccountantDashboardController.php',
 'accountant_service' => __DIR__ . '/../src/services/AccountantDashboardService.php',
 'accountant_repository' => __DIR__ . '/../src/repositories/AccountantDashboardRepository.php',
];
$source = [];
foreach ($files as $key => $path) {
 $source[$key] = file_get_contents($path);
 if (!is_string($source[$key])) throw new RuntimeException("Unable to read {$key}");
}
foreach (['secretary_view','accountant_view'] as $view) {
 if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn|insert|update|delete)\s*\(/', $source[$view])) {
  throw new RuntimeException("Dashboard view still queries database: {$view}");
 }
 if (!str_contains($source[$view], 'getViewData')) throw new RuntimeException("Dashboard view lacks controller delegation: {$view}");
}
foreach ([
 'secretary_controller' => 'SecretaryDashboardService',
 'secretary_service' => 'SecretaryDashboardRepository',
 'accountant_controller' => 'AccountantDashboardService',
 'accountant_service' => 'AccountantDashboardRepository',
] as $file => $dependency) {
 if (!str_contains($source[$file], $dependency)) throw new RuntimeException("MVC dependency missing in {$file}");
}
foreach (['activeYearId','balances','receiptsThisMonth','overdueReceivables','scheduledFollowups','monthlyReceipts','classBalances','studentDebtStates','latestReceipts'] as $method) {
 if (!str_contains($source['accountant_repository'], 'function ' . $method)) throw new RuntimeException("Finance query method missing: {$method}");
}
foreach (['finance_annulations','finance_affectations','taux_cdf_par_usd','GREATEST(cr.montant_du - COALESCE(p.montant_paye, 0), 0)'] as $financeInvariant) {
 if (!str_contains($source['accountant_repository'], $financeInvariant)) throw new RuntimeException("Finance calculation/invalidation guard missing: {$financeInvariant}");
}
foreach (['studentsPerClass','genderCounts','latestRegistrations','upcomingEvents','absencesToday','unreadMessages'] as $method) {
 if (!str_contains($source['secretary_repository'], 'function ' . $method)) throw new RuntimeException("Secretary data method missing: {$method}");
}
echo "Phase 12 secretary/accountant MVC smoke: OK\n";
