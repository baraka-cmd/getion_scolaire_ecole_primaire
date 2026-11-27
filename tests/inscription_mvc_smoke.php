<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/services/InscriptionService.php';

final class InscriptionRepositoryFake extends InscriptionRepository
{
    public function __construct() {}
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
if ($repository->calls[1] !== ['availableClasses', 0] || $service->findEnrollment(0) !== null) {
    throw new RuntimeException('Inscription service ID normalization failed.');
}
if (!str_contains(file_get_contents(__DIR__ . '/../src/controllers/InscriptionController.php'), '$this->inscriptionService->paginate')) {
    throw new RuntimeException('Inscription controller has not delegated its list query.');
}
$invalidRegistration = $service->createEnrollment(0, 0, 'Actif');
if ($invalidRegistration['ok'] || !isset($invalidRegistration['errors']['eleve_id'], $invalidRegistration['errors']['classe_id'])) {
    throw new RuntimeException('Invalid enrollment IDs must be rejected before persistence.');
}
if ($service->changeClass(4, 2, 0)['status'] !== 400) {
    throw new RuntimeException('Invalid class transfer parameters must be rejected.');
}
if ($service->discontinue(2, 'Actif', 'motif')['status'] !== 400) {
    throw new RuntimeException('Invalid closure status must be rejected.');
}
if ($service->discontinue(2, 'Abandon', '   ')['status'] !== 422) {
    throw new RuntimeException('Closure reason must be required.');
}
$controller = file_get_contents(__DIR__ . '/../src/controllers/InscriptionController.php');
if (preg_match('/(?:SELECT\\s+(?:\\*|[a-z_]+(?:\\.[a-z_*]+)?)\\s+FROM\\s+[a-z_]+|INSERT\\s+INTO\\s+[a-z_]+|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM\\s+[a-z_]+)/i', $controller)) {
    throw new RuntimeException('SQL must not remain in the enrollment controller.');
}
foreach (['nouvelle.php', 'liste.php', 'reinscription.php'] as $view) {
    $source = file_get_contents(__DIR__ . '/../public/views/modules/inscriptions/' . $view);
    if (preg_match('/(?:SELECT\\s+(?:\\*|[a-z_]+(?:\\.[a-z_*]+)?)\\s+FROM\\s+[a-z_]+|INSERT\\s+INTO\\s+[a-z_]+|UPDATE\\s+[a-z_]+\\s+SET|DELETE\\s+FROM\\s+[a-z_]+)/i', $source)) {
        throw new RuntimeException('SQL must not remain in enrollment views: ' . $view);
    }
}
$repositorySource = file_get_contents(__DIR__ . '/../src/repositories/InscriptionRepository.php');
if (!str_contains($repositorySource, 'FOR UPDATE') || !str_contains($repositorySource, 'beginTransaction')) {
    throw new RuntimeException('Enrollment writes must be transactionally protected.');
}
fwrite(STDOUT, "Phase 6 enrollment MVC/security smoke tests passed.\\n");
