<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AttendanceRegisterService.php';
require_once __DIR__ . '/../src/services/RetardService.php';

final class Phase7AttendanceRepositoryFake extends AttendanceRegisterRepository
{
    public array $pointage = [];
    public array $closures = [];
    public array $scheduled = [];

    public function __construct() {}
    public function getPointageData(int $classId, int $yearId, string $date): array { return $this->pointage; }
    public function findClosuresOnDate(int $yearId, string $date): array { return $this->closures; }
    public function findScheduledDays(int $classId, int $yearId): array { return $this->scheduled; }
}

$attendanceRepository = new Phase7AttendanceRepositoryFake();
$attendanceRepository->pointage = [
    'eleves' => [['id'=>3,'nom'=>'Test']],
    'statuts' => [['eleve_id'=>3,'type_absence'=>'Présent','est_justifiee'=>0,'motif'=>null]],
    'appel' => ['statut'=>'valide','motif_conge'=>null],
];
$attendanceService = new AttendanceRegisterService($attendanceRepository);
$pointage = $attendanceService->getPointage(9, ['id'=>9,'nom'=>'CM1','annee_scolaire_id'=>2], '2026-11-27');
if (($pointage['eleves'][0]['pointage']['type_absence'] ?? null) !== 'Présent'
    || $pointage['appel']['statut'] !== 'valide') {
    throw new RuntimeException('Daily attendance projection changed after service extraction.');
}

$controllerPaths = [
    __DIR__ . '/../src/controllers/AbsenceController.php',
    __DIR__ . '/../src/controllers/RetardController.php',
    __DIR__ . '/../src/controllers/AttendanceRegisterController.php',
];
$viewPaths = [
    __DIR__ . '/../public/views/enseignant/modules/absences.php',
    __DIR__ . '/../public/views/parent/modules/absences.php',
    __DIR__ . '/../public/views/parent/modules/retards.php',
    __DIR__ . '/../public/views/modules/absences/justifier.php',
    __DIR__ . '/../public/views/modules/absences/liste.php',
    __DIR__ . '/../public/views/modules/absences/marquer.php',
    __DIR__ . '/../public/views/modules/retards/enregistrer.php',
    __DIR__ . '/../public/views/modules/retards/liste.php',
];
$sqlPattern = '/(?:SELECT\\s+(?:\\*|[a-z_]+(?:\\.[a-z_*]+)?)\\s+FROM\\s+[a-z_]+|INSERT\\s+INTO\\s+[a-z_]+|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM\\s+[a-z_]+)/i';
foreach (array_merge($controllerPaths, $viewPaths) as $path) {
    $source = file_get_contents($path);
    if ($source === false || preg_match($sqlPattern, $source)) {
        throw new RuntimeException('SQL must remain in repositories, not controller/view: ' . basename($path));
    }
}

$attendanceController = file_get_contents(__DIR__ . '/../src/controllers/AttendanceRegisterController.php');
foreach (['validerAppel', 'marquerConge', 'annulerConge'] as $action) {
    if (!str_contains($attendanceController, "case '{$action}'")) {
        throw new RuntimeException('Existing attendance action route was lost: ' . $action);
    }
}
if (!str_contains($attendanceController, 'validateCsrf')
    || !str_contains($attendanceController, 'requireClassAccess')) {
    throw new RuntimeException('Attendance write CSRF/access checks were removed.');
}
$repository = file_get_contents(__DIR__ . '/../src/repositories/AttendanceRegisterRepository.php');
foreach (['beginTransaction', 'FOR UPDATE', 'commit', 'rollback', 'enrollment_changed'] as $guard) {
    if (!str_contains($repository, $guard)) {
        throw new RuntimeException('Atomic attendance safeguard missing: ' . $guard);
    }
}
$retardController = file_get_contents(__DIR__ . '/../src/controllers/RetardController.php');
if (!str_contains($retardController, 'Security::getInstance()->validateCsrf()')
    || !str_contains($retardController, "requirePermission('retards.justifier')")) {
    throw new RuntimeException('Tardiness mutation CSRF and action permissions must remain enforced.');
}
if (!str_contains($retardController, 'StudentAccessPolicy')
    || !str_contains($retardController, 'canCurrentUserAccessStudent')) {
    throw new RuntimeException('Student-level access policy must protect tardiness endpoints.');
}

fwrite(STDOUT, "Phase 7 attendance MVC/security smoke tests passed.\\n");
