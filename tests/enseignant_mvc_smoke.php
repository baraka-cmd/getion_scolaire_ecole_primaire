<?php
declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/EnseignantService.php';

final class EnseignantRepositoryFakeForMvc extends EnseignantRepository
{
    public array $filters = [];
    public int $page = 0;
    public int $perPage = 0;
    public function __construct() {}
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $this->filters = $filters;
        $this->page = $page;
        $this->perPage = $perPage;
        return ['enseignants' => [['id' => 6, 'matricule' => 'ENS-26-006']], 'total' => 1];
    }
}

$repository = new EnseignantRepositoryFakeForMvc();
$service = new EnseignantService($repository);
$result = $service->paginate(['recherche' => 'K', 'ordre' => 'nom'], 0, 20);
if ($repository->page !== 1 || $repository->perPage !== 20 || $repository->filters['recherche'] !== 'K') {
    throw new RuntimeException('Teacher list filters/pagination were not delegated correctly.');
}
if ($result['total'] !== 1 || $result['enseignants'][0]['matricule'] !== 'ENS-26-006') {
    throw new RuntimeException('Teacher list result contract changed.');
}
fwrite(STDOUT, "Teacher MVC smoke test passed.\n");
