<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/helpers/functions.php';
require_once __DIR__ . '/../src/controllers/AnneeController.php';

$controller = (new ReflectionClass(AnneeController::class))->newInstanceWithoutConstructor();
$trimestres = [
    1 => ['date_debut' => '2026-09-01', 'date_fin' => '2026-12-15'],
    2 => ['date_debut' => '2027-01-05', 'date_fin' => '2027-03-30'],
    3 => ['date_debut' => '2027-04-06', 'date_fin' => '2027-07-05'],
];

$result = $controller->validerDatesTrimestres($trimestres, '2026-09-01', '2027-07-05');
if ($result['errors'] !== [] || count($result['dates']) !== 3) {
    fwrite(STDERR, "Valid trimester dates were rejected.\n");
    exit(1);
}

$overlapping = $trimestres;
$overlapping[2]['date_debut'] = '2026-12-10';
$result = $controller->validerDatesTrimestres($overlapping, '2026-09-01', '2027-07-05');
if (!isset($result['errors']['trimestre_2'])) {
    fwrite(STDERR, "Overlapping trimester dates were accepted.\n");
    exit(1);
}

$outsideYear = $trimestres;
$outsideYear[3]['date_fin'] = '2027-07-06';
$result = $controller->validerDatesTrimestres($outsideYear, '2026-09-01', '2027-07-05');
if (!isset($result['errors']['trimestre_3'])) {
    fwrite(STDERR, "A trimester outside the school year was accepted.\n");
    exit(1);
}

$invalidDate = $trimestres;
$invalidDate[1]['date_debut'] = '2026-02-30';
$result = $controller->validerDatesTrimestres($invalidDate, '2026-09-01', '2027-07-05');
if (!isset($result['errors']['trimestre_1'])) {
    fwrite(STDERR, "An invalid calendar date was accepted.\n");
    exit(1);
}

fwrite(STDOUT, "Trimester date validation smoke tests passed.\n");
