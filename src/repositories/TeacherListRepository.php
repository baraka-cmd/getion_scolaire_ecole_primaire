<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../config/database.php';

/** SQL de lecture des statistiques du répertoire des enseignants. */
class TeacherListRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }
    public function activeCount(): int { return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM enseignants WHERE est_actif = 1"); }
    public function assignedActiveCount(): int { return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM enseignants WHERE classe_id IS NOT NULL AND est_actif = 1"); }
    public function unassignedActiveCount(): int { return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM enseignants WHERE classe_id IS NULL AND est_actif = 1"); }
}