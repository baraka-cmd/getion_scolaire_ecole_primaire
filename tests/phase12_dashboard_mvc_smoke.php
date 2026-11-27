<?php

declare(strict_types=1);

define('ACCESS', true);

$adminView = file_get_contents(__DIR__ . '/../public/views/dashboard/admin.php');
$controller = file_get_contents(__DIR__ . '/../src/controllers/DashboardController.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/DashboardRepository.php');
$service = file_get_contents(__DIR__ . '/../src/services/DashboardService.php');

foreach (['adminView' => $adminView, 'controller' => $controller, 'repository' => $repository, 'service' => $service] as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read dashboard MVC source: ' . $name);
    }
}

// The initial admin KPI view must not bypass the established MVC data path.
if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn)\s*\(/', $adminView)) {
    throw new RuntimeException('Admin dashboard initial view must not issue SQL directly.');
}
if (!str_contains($adminView, 'getInitialStats')) {
    throw new RuntimeException('Admin dashboard view is not delegating initial metrics.');
}
if (!str_contains($controller, 'getInitialStats(int $userId)')) {
    throw new RuntimeException('DashboardController initial stats API is missing.');
}
if (!str_contains($controller, "hasRole(['Admin', 'Directeur'])")) {
    throw new RuntimeException('Global dashboard AJAX must enforce admin/director authorization.');
}
if (preg_match('/private\s+Database\s+\$db|\$this->db\s*=\s*Database::getInstance\(\)/', $controller)) {
    throw new RuntimeException('DashboardController must not retain an unused direct database dependency.');
}
foreach (['getStats', 'studentsPerClass', 'monthlyPayments'] as $method) {
    if (!str_contains($service, 'function ' . $method) || !str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException('Existing dashboard service/repository contract missing: ' . $method);
    }
}
echo "Phase 12 dashboard MVC/security smoke: OK\n";
