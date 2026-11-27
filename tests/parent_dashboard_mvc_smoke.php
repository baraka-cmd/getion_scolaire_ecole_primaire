<?php

declare(strict_types=1);

$view = file_get_contents(__DIR__ . '/../public/views/dashboard/parent.php');
$controller = file_get_contents(__DIR__ . '/../src/controllers/ParentDashboardController.php');
$service = file_get_contents(__DIR__ . '/../src/services/ParentDashboardService.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/ParentDashboardRepository.php');

foreach (compact('view', 'controller', 'service', 'repository') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read parent dashboard source: ' . $name);
    }
}
if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn)\s*\(/', $view)) {
    throw new RuntimeException('Parent dashboard view must not query the database.');
}
if (!str_contains($view, 'ParentDashboardController') || !str_contains($view, 'getViewData')) {
    throw new RuntimeException('Parent dashboard view must delegate preparation to its controller.');
}
if (!str_contains($repository, 'WHERE ep.parent_id = :pid AND e.est_actif = 1') || !str_contains($repository, "['pid' => \$parentId]")) {
    throw new RuntimeException('Parent-child relationship scoping must remain in the repository query.');
}
if (!str_contains($service, 'childrenForParent($parentId)')) {
    throw new RuntimeException('Service must load only children linked to the authenticated parent.');
}
foreach (['childLatestAverage', 'childAbsenceCount', 'childLateCount', 'childOutstandingPayment'] as $method) {
    if (!str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException('Named child data repository method missing: ' . $method);
    }
}
echo "Parent dashboard MVC/scoping smoke: OK\n";
