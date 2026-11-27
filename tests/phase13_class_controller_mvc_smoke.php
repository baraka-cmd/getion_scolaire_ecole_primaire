<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/controllers/ClasseController.php');
$service = file_get_contents(__DIR__ . '/../src/services/ClasseService.php');
$repository = file_get_contents(__DIR__ . '/../src/repositories/ClasseRepository.php');

foreach (compact('controller', 'service', 'repository') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Cannot read MVC source: ' . $name);
    }
}

if (preg_match('/\$this->db|private\s+Database\s+\$db/', $controller)) {
    throw new RuntimeException('ClasseController must not access Database directly.');
}

foreach (['createWithHolder', 'updateWithHolder', 'deleteWithSchedule'] as $method) {
    if (!str_contains($controller, '$this->classeService->' . $method)
        || !str_contains($service, 'function ' . $method)
        || !str_contains($repository, 'function ' . $method)) {
        throw new RuntimeException('Class mutation MVC chain missing: ' . $method);
    }
}

foreach (['beginTransaction()', 'commit()', 'rollback()'] as $transactionCall) {
    if (!str_contains($repository, $transactionCall)) {
        throw new RuntimeException('Repository transaction contract missing: ' . $transactionCall);
    }
}

foreach ([
    'Classe créée avec succès.',
    'Classe modifiée avec succès.',
    'Classe supprimée avec succès.',
    'logActivity(',
] as $contract) {
    if (!str_contains($controller, $contract)) {
        throw new RuntimeException('Legacy HTTP/audit contract was not preserved: ' . $contract);
    }
}

echo "Phase 13 class-controller MVC smoke: OK\n";
