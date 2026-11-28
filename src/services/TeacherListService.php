<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../repositories/TeacherListRepository.php';
class TeacherListService
{
    private TeacherListRepository $repository;
    public function __construct(?TeacherListRepository $repository = null) { $this->repository = $repository ?? new TeacherListRepository(); }
    public function getStatistics(): array
    {
        return [
            'totalEnseignants' => $this->repository->activeCount(),
            'totalClassesAssignees' => $this->repository->assignedActiveCount(),
            'totalSansClasse' => $this->repository->unassignedActiveCount(),
        ];
    }
}