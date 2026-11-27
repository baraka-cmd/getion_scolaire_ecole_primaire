<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/StudentAccessRepository.php';

class StudentAccessPolicy
{
    private StudentAccessRepository $repository;

    public function __construct(?StudentAccessRepository $repository = null)
    {
        $this->repository = $repository ?? new StudentAccessRepository();
    }

    public function canCurrentUserAccessStudent(int $studentId): bool
    {
        $userId = (int) ($_SESSION['utilisateur_id'] ?? 0);
        if ($studentId <= 0 || $userId <= 0) {
            return false;
        }

        $role = $this->repository->findActiveRole($userId);
        if ($role === null) {
            return false;
        }

        if (in_array($role, ['Admin', 'Directeur'], true)) {
            return true;
        }

        if ($role === 'Parent') {
            return $this->repository->parentLinkedToStudent($userId, $studentId);
        }

        if ($role === 'Enseignant') {
            return $this->repository->teacherAssignedToStudent($userId, $studentId);
        }

        return function_exists('can') ? can('eleves.consulter') : false;
    }
}
