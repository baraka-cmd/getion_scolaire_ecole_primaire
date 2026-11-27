<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../repositories/SchoolParametersRepository.php';

class SchoolParametersService
{
    private SchoolParametersRepository $repository;

    public function __construct(?SchoolParametersRepository $repository = null)
    {
        $this->repository = $repository ?? new SchoolParametersRepository();
    }

    public function getSchoolParameters(): ?array
    {
        return $this->repository->findSchoolParameters();
    }
}
