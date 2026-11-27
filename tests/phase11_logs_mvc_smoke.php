<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/LogService.php';

final class Phase11InMemoryLogRepository extends LogRepository
{
    public array $calls = [];
    public function __construct() {}
    public function countFiltered(string $where, array $params): int
    {
        $this->calls[] = ['count', $where, $params];
        return 41;
    }
    public function listFiltered(string $where, array $params, int $limit, int $offset): array
    {
        $this->calls[] = ['list', $where, $params, $limit, $offset];
        return [['id' => 1]];
    }
    public function exportFiltered(string $where, array $params): array
    {
        $this->calls[] = ['export', $where, $params];
        return [];
    }
}

$repo = new Phase11InMemoryLogRepository();
$service = new LogService($repo);
$result = $service->listFiltered('WHERE l.utilisateur_id = :uid', ['uid' => 7], 3, 15);
if ($result !== ['logs' => [['id' => 1]], 'total' => 41, 'page' => 3, 'pages' => 3.0]) {
    throw new RuntimeException('Activity log pagination contract changed.');
}
if ($repo->calls !== [
    ['count', 'WHERE l.utilisateur_id = :uid', ['uid' => 7]],
    ['list', 'WHERE l.utilisateur_id = :uid', ['uid' => 7], 15, 30],
]) {
    throw new RuntimeException('Activity log repository delegation or parameter binding changed.');
}

$controller = file_get_contents(__DIR__ . '/../src/controllers/LogController.php');
if (!is_string($controller) || preg_match('/\$this->db|->(?:fetchAll|fetchOne|fetchColumn|insert|update|delete)\s*\(/', $controller)) {
    throw new RuntimeException('LogController must not access the database directly.');
}
foreach (['listFiltered', 'exportFiltered', 'statistics', 'topActions', 'topUsers', 'activityLastSevenDays'] as $method) {
    if (!str_contains(file_get_contents(__DIR__ . '/../src/services/LogService.php'), 'function ' . $method)) {
        throw new RuntimeException('LogService contract missing: ' . $method);
    }
}
echo "Phase 11 activity-log MVC smoke: OK\n";
