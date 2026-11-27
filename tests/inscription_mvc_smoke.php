<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/InscriptionService.php';

final class InscriptionRepositoryFake
{
    public array $calls = [];
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $this->calls[] = ['paginate', $filters, $page, $perPage];
        return ['inscriptions' => [['id' => 17]], 'total' => 1];
    }
    public function studentHistory(int $id): array
    {
        $this->calls[] = ['studentHistory', $id];
        return ['eleve' => ['id' => $id], 'historique' => []];
    }
    public function availableClasses(int $id): array { $this->calls[] = ['availableClasses', $id]; return []; }
    public function schoolYears(): array { $this->calls[] = ['schoolYears']; return []; }
    public function activeClasses(): array { $this->calls[] = ['activeClasses']; return []; }
    public function unenrolledStudents(): array { $this->calls[] = ['unenrolledStudents']; return []; }
    public function findEnrollment(int $id): ?array { $this->calls[] = ['findEnrollment', $id]; return ['id' => $id]; }
    public function enrollmentHistory(int $id): array { $this->calls[] = ['enrollmentHistory', $id]; return []; }
}

$repository = new InscriptionRepositoryFake();
$service = new InscriptionService($repository);
$filters = ['recherche' => 'Aline', 'classe_id' => 3, 'annee_id' => 8, 'statut' => 'Actif'];
$page = $service->paginate($filters, 0, 25);
if ($page !== ['inscriptions' => [['id' => 17]], 'total' => 1] || $repository->calls[0] !== ['paginate', $filters, 1, 25]) {
    throw new RuntimeException('Inscription pagination service contract failed.');
}
if ($service->getStudentHistory(0) !== ['eleve' => null, 'historique' => []]) {
    throw new RuntimeException('Invalid student IDs must return a safe empty history.');
}
$service->getAvailableClasses(-5);
$service->findEnrollment(0);
if ($repository->calls[1] !== ['availableClasses', 0] || $repository->calls[2] !== ['findEnrollment', 0]) {
    throw new RuntimeException('Inscription service ID normalization failed.');
}
if (!str_contains(file_get_contents(__DIR__ . '/../src/controllers/InscriptionController.php'), '$this->inscriptionService->paginate')) {
    throw new RuntimeException('Inscription controller has not delegated its list query.');
}
fwrite(STDOUT, "Phase 6 enrollment MVC smoke tests passed.\n");
