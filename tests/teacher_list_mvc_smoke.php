<?php
declare(strict_types=1);
$view = file_get_contents(__DIR__ . '/../public/views/modules/enseignants/liste.php');
$repo = file_get_contents(__DIR__ . '/../src/repositories/TeacherListRepository.php');
$service = file_get_contents(__DIR__ . '/../src/services/TeacherListService.php');
$controller = file_get_contents(__DIR__ . '/../src/controllers/TeacherListReadController.php');
if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn|insert|update|delete)\s*\(/', $view)) {
 throw new RuntimeException('Teacher directory view still executes SQL.');
}
foreach (['activeCount','assignedActiveCount','unassignedActiveCount'] as $method) {
 if (!str_contains($repo, 'function ' . $method)) throw new RuntimeException("Teacher statistics repository method missing: {$method}");
}
if (!str_contains($service, 'TeacherListRepository') || !str_contains($controller, 'TeacherListService') || !str_contains($view, 'TeacherListReadController')) {
 throw new RuntimeException('Teacher directory MVC chain incomplete.');
}
echo "Teacher directory MVC smoke: OK\n";
