<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../services/TeacherListService.php';
class TeacherListReadController
{
    private TeacherListService $service;
    public function __construct(?TeacherListService $service = null) { $this->service = $service ?? new TeacherListService(); }
    public function getViewData(): array { return $this->service->getStatistics(); }
}