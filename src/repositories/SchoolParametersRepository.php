<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class SchoolParametersRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findSchoolParameters(): ?array
    {
        return $this->db->fetchOne('SELECT * FROM parametres_ecole LIMIT 1');
    }
}
