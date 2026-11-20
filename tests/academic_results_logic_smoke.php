<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/AcademicResultsService.php';

$expectedPeriods = [
    'Période 1' => [1, 'Période 1', 'p1'],
    'Période 2' => [1, 'Période 2', 'p2'],
    'Période 3' => [2, 'Période 1', 'p1'],
    'Période 4' => [2, 'Période 2', 'p2'],
    'Période 5' => [3, 'Période 1', 'p1'],
    'Période 6' => [3, 'Période 2', 'p2'],
];

foreach ($expectedPeriods as $label => [$trimester, $storedType, $component]) {
    $mapping = AcademicResultsService::getDisplayPeriodMapping($label);
    if (
        $mapping === null
        || $mapping['trimestre_numero'] !== $trimester
        || $mapping['type'] !== $storedType
        || $mapping['composante'] !== $component
    ) {
        fwrite(STDERR, "Incorrect display-to-storage mapping for {$label}.\n");
        exit(1);
    }
}

if (AcademicResultsService::getDisplayPeriodMapping('Période 7') !== null) {
    fwrite(STDERR, "An unsupported period label was accepted.\n");
    exit(1);
}

$quarter = AcademicResultsService::calculateSubjectQuarterFromScores(20, 12, 18, 31);
if (
    $quarter['p1']['max'] !== 20.0
    || $quarter['p2']['max'] !== 20.0
    || $quarter['ex']['max'] !== 40.0
    || $quarter['total_max'] !== 80.0
    || $quarter['total_obt'] !== 61.0
    || AcademicResultsService::calculatePercentage($quarter['total_obt'], $quarter['total_max']) !== 76.3
) {
    fwrite(STDERR, "Quarter maxima or score calculation is incorrect.\n");
    exit(1);
}

if (AcademicResultsService::calculatePercentage(162, 240) !== 67.5) {
    fwrite(STDERR, "Weighted annual percentage calculation is incorrect.\n");
    exit(1);
}

fwrite(STDOUT, "Academic results logic smoke tests passed.\n");
