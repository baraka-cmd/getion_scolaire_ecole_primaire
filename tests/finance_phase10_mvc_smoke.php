<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/FinanceService.php';

$controller = file_get_contents(__DIR__ . '/../src/controllers/PaiementController.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/FinanceRepository.php');
$service = file_get_contents(__DIR__ . '/../src/services/FinanceService.php');

$feeControllerMethods = ['getListeFrais', 'ajouterFrais', 'modifierFrais', 'supprimerFrais', 'toggleStatutFrais'];
foreach ($feeControllerMethods as $method) {
    if (!preg_match('/public function ' . preg_quote($method, '/') . '\\([^)]*\\)\\s*(?::\\s*[^\\{]+)?\\s*\\{([\\s\\S]*?)\\n\\}/', $controller, $matches)) {
        throw new RuntimeException("Controller method missing: {$method}");
    }
    if (preg_match('/->(?:fetchAll|fetchOne|fetchColumn|insert|update|delete)\\s*\\(/', $matches[1])) {
        throw new RuntimeException("Direct database access remains in fee controller method: {$method}");
    }
}
foreach (['listFeesForYear', 'createFee', 'updateFee', 'deleteFeeIfUnused', 'toggleFeeStatus'] as $method) {
    if (!preg_match('/public function ' . preg_quote($method, '/') . '\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\n\\}/', $service, $matches)
        || !str_contains($matches[1], '$this->repository->')) {
        throw new RuntimeException("Finance service does not delegate {$method} to repository.");
    }
}
foreach (['listFeesForYear', 'feeExists', 'classBelongsToYear', 'createFee', 'createFeeConfig', 'updateFee', 'countClaimsForFee'] as $method) {
    if (!str_contains($repository, 'function ' . $method . '(')) {
        throw new RuntimeException("Finance repository method missing: {$method}");
    }
}
echo "Phase 10 finance MVC and fee authorization boundary: OK\\n";
