<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AcademicResultsService.php';

if (AcademicResultsService::determineMention(95) !== 'Excellent') {
    throw new RuntimeException('The annual result mention rules changed.');
}
if (AcademicResultsService::determineMention(45) !== 'Insuffisant') {
    throw new RuntimeException('The annual failure mention rules changed.');
}

$empty = AcademicResultsService::emptySubjectQuarter(10);
if ($empty['total_max'] !== 40.0 || $empty['total_obt'] !== 0.0) {
    throw new RuntimeException('The empty subject quarter contract changed.');
}

fwrite(STDOUT, "Phase 6 cleanup smoke tests passed.\n");
