<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/FinanceService.php';

$tuitionCategories = ['Scolarité', 'Frais de scolarité', 'Minerval annuel'];
foreach ($tuitionCategories as $category) {
    if (!FinanceService::estFraisScolarite($category)) {
        fwrite(STDERR, "Tuition category was not recognized: {$category}\n");
        exit(1);
    }
}

if (FinanceService::estFraisScolarite('Fournitures scolaires')) {
    fwrite(STDERR, "A non-tuition category was incorrectly recognized as tuition.\n");
    exit(1);
}

if (
    FinanceService::convertirMontant(25000, 'CDF', 'USD', 2500) !== 10.0
    || FinanceService::convertirMontant(10, 'USD', 'CDF', 2500) !== 25000.0
) {
    fwrite(STDERR, "Exchange conversion does not use the configured CDF/USD rate.\n");
    exit(1);
}

fwrite(STDOUT, "Finance exchange-rate smoke tests passed.\n");
