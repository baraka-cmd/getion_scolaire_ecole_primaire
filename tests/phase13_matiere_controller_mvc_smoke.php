<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/controllers/MatiereController.php');
$service = file_get_contents(__DIR__ . '/../src/services/MatiereService.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/MatiereRepository.php');

foreach (compact('controller', 'service', 'repository') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Cannot read subject MVC source: ' . $name);
    }
}

if (preg_match('/\\$this->db|private\\s+Database\\s+\\$db/', $controller)) {
    throw new RuntimeException('MatiereController must not access Database directly.');
}

foreach (['createSubject', 'deleteSubjectWithAssociations', 'isActiveGroup'] as $method) {
    if (!str_contains($controller, '$this->matiereService->' . $method)
        || !str_contains($service, 'function ' . $method)
        || !str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException('Subject MVC delegation missing: ' . $method);
    }
}

foreach (['beginTransaction()', 'commit()', 'rollback()'] as $transactionCall) {
    if (!str_contains($repository, $transactionCall)) {
        throw new RuntimeException('Subject repository transaction contract missing: ' . $transactionCall);
    }
}

foreach (['Matière créée', 'Matière supprimée', 'logActivity('] as $contract) {
    if (!str_contains($controller, $contract)) {
        throw new RuntimeException('Existing subject HTTP/audit behavior missing: ' . $contract);
    }
}

echo "Phase 13 subject-controller MVC smoke: OK\\n";
