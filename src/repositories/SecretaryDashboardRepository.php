<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../config/database.php';

/** Persistence du tableau de bord secrétariat. SQL uniquement. */
class SecretaryDashboardRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }

    public function activeYearId()
    {
        return $this->db->fetchColumn("SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1") ?? 1;
    }
    public function activeStudentCount(int $yearId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = :annee AND statut = 'Actif'", ['annee' => $yearId]);
    }
    public function activeTeacherCount(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM enseignants WHERE est_actif = 1");
    }
    public function classCount(int $yearId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = :annee", ['annee' => $yearId]);
    }
    public function newRegistrationsThisMonth(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE MONTH(date_inscription) = MONTH(CURDATE()) AND YEAR(date_inscription) = YEAR(CURDATE())");
    }
    public function recentActiveRegistrations(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM inscriptions WHERE statut = 'Actif' AND date_inscription >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    }
    public function studentsPerClass(int $yearId): array
    {
        return $this->db->fetchAll("SELECT c.nom, c.niveau, COUNT(i.id) as total FROM classes c LEFT JOIN inscriptions i ON c.id = i.classe_id AND i.statut = 'Actif' WHERE c.annee_scolaire_id = :annee GROUP BY c.id, c.nom, c.niveau ORDER BY c.niveau", ['annee' => $yearId]);
    }
    public function genderCounts(): array
    {
        return [
            'boys' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM eleves e INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif' WHERE e.sexe = 'M' AND e.est_actif = 1"),
            'girls' => (int) $this->db->fetchColumn("SELECT COUNT(*) FROM eleves e INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif' WHERE e.sexe = 'F' AND e.est_actif = 1"),
        ];
    }
    public function latestRegistrations(): array
    {
        return $this->db->fetchAll("SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe, e.date_naissance, DATE_FORMAT(i.date_inscription, '%d/%m/%Y') as date_inscription, c.nom as classe_nom FROM eleves e INNER JOIN inscriptions i ON e.id = i.eleve_id INNER JOIN classes c ON i.classe_id = c.id WHERE i.statut = 'Actif' ORDER BY i.date_inscription DESC LIMIT 10");
    }
    public function upcomingEvents(int $yearId): array
    {
        return $this->db->fetchAll("SELECT titre, type_evenement, date_debut, date_fin, lieu FROM calendrier_scolaire WHERE date_debut >= CURDATE() AND annee_scolaire_id = :annee ORDER BY date_debut ASC LIMIT 5", ['annee' => $yearId]);
    }
    public function absencesToday(): array
    {
        return $this->db->fetchAll("SELECT e.id, e.nom, e.prenom, e.matricule, c.nom as classe_nom, a.motif, a.est_justifiee FROM absences a INNER JOIN inscriptions i ON a.eleve_id = i.eleve_id AND i.statut = 'Actif' INNER JOIN eleves e ON a.eleve_id = e.id INNER JOIN classes c ON i.classe_id = c.id WHERE a.date_absence = CURDATE() AND a.type_absence = 'Absence' ORDER BY c.niveau, e.nom");
    }
    public function unreadMessages(int $userId): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM messages WHERE destinataire_id = :uid AND est_lu = 0 AND est_supprime_destinataire = 0", ['uid' => $userId]);
    }
}