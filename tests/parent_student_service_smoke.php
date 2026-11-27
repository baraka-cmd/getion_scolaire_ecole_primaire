<?php

declare(strict_types=1);

define('ACCESS', true);
require_once __DIR__ . '/../src/helpers/functions.php';
require_once __DIR__ . '/../src/services/ParentStudentService.php';

final class InMemoryParentStudentRepository extends ParentStudentRepository
{
    public ?int $existingParentId = null;
    public array $links = [];
    public array $operations = [];

    public function __construct()
    {
    }

    public function findParentIdByTelephone(string $telephone): ?int
    {
        return $this->existingParentId;
    }

    public function createParent(
        string $nom,
        string $prenom,
        string $lien,
        string $telephone,
        ?string $email,
        ?string $profession
    ): int {
        $this->operations[] = ['createParent', $nom, $prenom, $lien, $telephone, $email, $profession];
        return 42;
    }

    public function hasLink(int $studentId, int $parentId): bool
    {
        return isset($this->links[$studentId][$parentId]);
    }

    public function createLink(int $studentId, int $parentId, int $isPrimary): void
    {
        $this->links[$studentId][$parentId] = $isPrimary;
        $this->operations[] = ['createLink', $studentId, $parentId, $isPrimary];
    }

    public function unsetPrimaryLinks(int $parentId): void
    {
        foreach ($this->links as $studentId => $parents) {
            if (isset($parents[$parentId])) {
                $this->links[$studentId][$parentId] = false;
            }
        }
        $this->operations[] = ['unsetPrimaryLinks', $parentId];
    }

    public function countLinksForStudent(int $studentId): int
    {
        return count($this->links[$studentId] ?? []);
    }

    public function deleteLink(int $studentId, int $parentId): void
    {
        unset($this->links[$studentId][$parentId]);
        $this->operations[] = ['deleteLink', $studentId, $parentId];
    }
}

$repository = new InMemoryParentStudentRepository();
$service = new ParentStudentService($repository);

$repository->existingParentId = 7;
if ($service->createOrFindParent('Jean', 'Paul', 'Père', '00000000') !== 7) {
    throw new RuntimeException('An existing parent was not reused.');
}
if ($repository->operations !== []) {
    throw new RuntimeException('Reusing a parent unexpectedly created a record.');
}

$repository->existingParentId = null;
$createdParentId = $service->createOrFindParent(
    'jean paul',
    'marie claire',
    '',
    '11111111',
    '',
    ''
);
if ($createdParentId !== 42) {
    throw new RuntimeException('A new parent was not created.');
}
$createdParent = end($repository->operations);
if ($createdParent !== ['createParent', 'Jean Paul', 'Marie Claire', 'Tuteur', '11111111', null, null]) {
    throw new RuntimeException('New parent fields were not normalized as expected.');
}

$repository->links = [10 => [3 => false]];
if ($service->linkParentToStudent(3, 10, 1) !== false) {
    throw new RuntimeException('An existing parent-student link was accepted twice.');
}
if ($repository->links[10][3] !== false) {
    throw new RuntimeException('A duplicate link unexpectedly changed the primary guardian.');
}

$repository->links[10][4] = false;
if ($service->linkParentToStudent(11, 10, 1) !== true) {
    throw new RuntimeException('A new parent-student link was rejected.');
}
if ($repository->links[10][3] !== false || $repository->links[10][11] !== 1) {
    throw new RuntimeException('The primary guardian link was not updated correctly.');
}

if ($service->unlinkParentFromStudent(11, 10) !== true || isset($repository->links[10][11])) {
    throw new RuntimeException('A link was not removed when the student had multiple parents.');
}
if ($service->unlinkParentFromStudent(4, 10) !== true || isset($repository->links[10][4])) {
    throw new RuntimeException('A parent link was not removed when multiple parents remained.');
}
if ($service->unlinkParentFromStudent(3, 10) !== false || !isset($repository->links[10][3])) {
    throw new RuntimeException('The last parent link was removed.');
}

fwrite(STDOUT, "Parent-student service smoke tests passed.\n");
