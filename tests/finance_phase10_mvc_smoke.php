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
$serviceDelegations = [
    '$this->repository->listFeesForYear($yearId)',
    '$this->repository->createFee($data)',
    '$this->repository->updateFee($feeId, $data)',
    '$this->repository->countClaimsForFee($feeId)',
    '$this->repository->updateFeeStatus($feeId, $status)',
];
foreach ($serviceDelegations as $delegation) {
    if (!str_contains($service, $delegation)) {
        throw new RuntimeException("Finance service delegation missing: {$delegation}");
    }
}
foreach (['listFeesForYear', 'feeExists', 'classBelongsToYear', 'createFee', 'createFeeConfig', 'updateFee', 'countClaimsForFee'] as $method) {
    if (!str_contains($repository, 'function ' . $method . '(')) {
        throw new RuntimeException("Finance repository method missing: {$method}");
    }
}
echo "Phase 10 finance MVC and fee authorization boundary: OK\\n";
