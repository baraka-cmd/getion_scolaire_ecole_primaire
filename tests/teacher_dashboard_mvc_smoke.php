<?php

declare(strict_types=1);

$view = file_get_contents(__DIR__ . '/../public/views/dashboard/enseignant.php');
$controller = file_get_contents(__DIR__ . '/../src/controllers/TeacherDashboardController.php');
$service = file_get_contents(__DIR__ . '/../src/services/TeacherDashboardService.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/TeacherDashboardRepository.php');

foreach (compact('view', 'controller', 'service', 'repository') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read teacher dashboard source: ' . $name);
    }
}
if (preg_match('/\$db\s*->\s*(?:fetchAll|fetchOne|fetchColumn)\s*\(/', $view)) {
    throw new RuntimeException('Teacher dashboard view must not query the database.');
}
if (!str_contains($view, 'TeacherDashboardController') || !str_contains($view, 'getViewData')) {
    throw new RuntimeException('Teacher dashboard view must delegate preparation to its controller.');
}
if (!str_contains($controller, 'TeacherDashboardService') || !str_contains($service, 'TeacherDashboardRepository')) {
    throw new RuntimeException('Teacher dashboard MVC dependency direction is invalid.');
}
foreach (['assignedClass', 'dailyAttendance', 'enteredNotesCount', 'recentAbsences', 'upcomingExams', 'studentsInDifficulty', 'upcomingBirthdays'] as $method) {
    if (!str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException('Named teacher dashboard persistence method missing: ' . $method);
    }
}
echo "Teacher dashboard MVC delegation smoke: OK\n";
