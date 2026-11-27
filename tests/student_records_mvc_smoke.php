<?php
/**
 * Smoke contract sans base pour la délégation du dossier élève.
 * Exécuter : php tests/student_records_mvc_smoke.php
 */
define('ACCESS', true);
require_once __DIR__ . '/../src/repositories/StudentRecordRepository.php';
require_once __DIR__ . '/../src/services/StudentRecordService.php';
require_once __DIR__ . '/../src/repositories/StudentAccessRepository.php';
require_once __DIR__ . '/../src/policies/StudentAccessPolicy.php';
require_once __DIR__ . '/../src/repositories/DocumentRepository.php';
require_once __DIR__ . '/../src/services/DocumentService.php';

function studentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ÉCHEC: {$message}\n");
        exit(1);
    }
}

class StudentRecordRepositoryFake extends StudentRecordRepository
{
    public array $received = [];

    public function __construct() {}

    public function paginate(array $filters, int $page, int $perPage, string $role, int $userId): array
    {
        $this->received = compact('filters', 'page', 'perPage', 'role', 'userId');
        return ['rows' => [['id' => 17]], 'total' => 1];
    }

    public function findRecord(int $studentId): ?array
    {
        return $studentId === 17 ? ['id' => 17] : null;
    }

    public function findDetailForForm(int $studentId): ?array { return $studentId === 17 ? ['id' => 17, 'detail' => true] : null; }
    public function getActiveClassesForForms(): array { return [['id' => 3]]; }
    public function enrollmentHistory(int $studentId): array { return [['classe_nom' => 'A']]; }

    public function recentGrades(int $studentId): array { return [['valeur' => 15]]; }
    public function recentAbsences(int $studentId): array { return []; }
    public function payments(int $studentId): array { return []; }
    public function summaryStats(): array { return ['total_actifs' => 8, 'total_garcons' => 4, 'total_filles' => 4, 'nouvelles_inscriptions' => 2]; }
    public function exportRows(array $filters, string $role, int $userId): array { $this->received['export'] = compact('filters', 'role', 'userId'); return [['matricule' => 'EL-1']]; }
    public function updateProfile(int $studentId, array $data): void { $this->received['update'] = compact('studentId', 'data'); }
    public function deactivate(int $studentId, string $reason): void { $this->received['deactivate'] = compact('studentId', 'reason'); }
    public function transferEnrollment(int $studentId, int $newClassId, string $reason): array { $this->received['transfer'] = compact('studentId', 'newClassId', 'reason'); return ['success' => true, 'capacity' => 20]; }
}

class StudentAccessRepositoryFake extends StudentAccessRepository
{
    public string $role = 'Parent';
    public bool $linked = false;
    public bool $teacherAssigned = false;

    public function __construct() {}
    public function findActiveRole(int $userId): ?string { return $userId === 9 ? $this->role : null; }
    public function parentLinkedToStudent(int $userId, int $studentId): bool { return $this->linked; }
    public function teacherAssignedToStudent(int $userId, int $studentId): bool { return $this->teacherAssigned; }
}

class DocumentRepositoryFake extends DocumentRepository
{
    public array $lastArgs = [];
    public function __construct() {}
    public function statistics(bool $admin): array
    {
        return ['total' => 3, 'total_size' => 2048, 'public_count' => 2, 'current_month_count' => 1];
    }

    public function paginateVisible(array $filters, int $page, int $perPage, bool $admin): array
    {
        $this->lastArgs = compact('filters', 'page', 'perPage', 'admin');
        return ['documents' => [], 'total' => 0, 'total_fichiers' => 0, 'taille_totale_octets' => 0, 'par_categorie' => []];
    }
}

$repository = new StudentRecordRepositoryFake();
$service = new StudentRecordService($repository);
$page = $service->paginate(['recherche' => 'A'], 0, 25, 'Parent', 9);
studentAssert($repository->received['page'] === 1, 'page minimum protégée');
studentAssert($repository->received['perPage'] === 25, 'pagination transmise');
studentAssert($repository->received['userId'] === 9, 'identité serveur transmise');
studentAssert($page['rows'][0]['id'] === 17 && $page['total'] === 1, 'résultat de liste inchangé');
studentAssert($service->findRecord(17)['id'] === 17, 'fiche récupérée');
studentAssert($service->findRecord(0) === null, 'ID invalide rejeté');
studentAssert($service->findDetailForForm(17)['detail'] === true, 'lecture formulaire déléguée');
studentAssert($service->getActiveClassesForForms()[0]['id'] === 3, 'classes formulaire déléguées');
studentAssert($service->enrollmentHistory(17)[0]['classe_nom'] === 'A', 'historique inscriptions délégué');
$history = $service->getAcademicAndAttendanceHistory(17);
studentAssert(isset($history['notes'], $history['absences'], $history['paiements']), 'structure historique stable');
$stats = $service->summaryStats();
studentAssert($stats['total_actifs'] === 8 && $stats['total_filles'] === 4, 'statistiques liste transmises');
$export = $service->exportRows(['classe_id' => 2], 'Parent', 9);
studentAssert($export[0]['matricule'] === 'EL-1', 'export délégué au repository');
$service->updateProfile(17, ['nom' => 'Test']);
studentAssert($repository->received['update']['studentId'] === 17, 'modification déléguée');
$service->deactivate(17, 'Motif test');
studentAssert($repository->received['deactivate']['reason'] === 'Motif test', 'désactivation déléguée');
$transfer = $service->transferEnrollment(17, 4, 'Mutation');
studentAssert($transfer['success'] && $repository->received['transfer']['newClassId'] === 4, 'transfert délégué');

$accessRepository = new StudentAccessRepositoryFake();
$policy = new StudentAccessPolicy(null, $accessRepository);
$_SESSION['utilisateur_id'] = 9;
studentAssert(!$policy->canCurrentUserAccessStudent(17), 'parent non lié refusé');
$accessRepository->linked = true;
studentAssert($policy->canCurrentUserAccessStudent(17), 'parent lié autorisé');
$accessRepository->role = 'Admin';
studentAssert($policy->canCurrentUserAccessStudent(17), 'administrateur autorisé');
studentAssert(!$policy->canCurrentUserAccessStudent(0), 'ID invalide refusé');

echo "OK - contrats MVC dossiers élèves\n";

$documentRepository = new DocumentRepositoryFake();
$documentService = new DocumentService($documentRepository);
$documentService->paginateVisible(['categorie' => 'Cours'], 0, 20, false);
studentAssert($documentRepository->lastArgs['page'] === 1, 'page documents normalisée');
studentAssert($documentRepository->lastArgs['admin'] === false, 'règle de visibilité transmise');
$documentStats = $documentService->statistics(true);
studentAssert($documentStats['total'] === 3 && $documentStats['public_count'] === 2, 'statistiques documents déléguées');
echo "OK - contrats MVC dossiers élèves et documents\\n";
