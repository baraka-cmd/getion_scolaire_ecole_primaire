<?php
defined('ACCESS') or die('Accès interdit');
require_once __DIR__ . '/../config/database.php';

/** SQL and persistence for the guardian dashboard; child queries remain parent-scoped. */
class ParentDashboardRepository
{
    private Database $db;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }

    public function findParentByUser(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, nom, prenom FROM parents_tuteurs WHERE utilisateur_id = :uid LIMIT 1',
            ['uid' => $userId]
        ) ?: null;
    }

    public function activeTermId(): int
    {
        return (int) $this->db->fetchColumn('SELECT trimestre_actif_id FROM parametres_ecole LIMIT 1');
    }

    public function childrenForParent(int $parentId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.date_naissance, e.sexe, e.photo,
                    c.nom as classe_nom, c.niveau,
                    i.statut as statut_inscription
             FROM eleves e
             INNER JOIN eleve_parent ep ON e.id = ep.eleve_id
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             INNER JOIN classes c ON i.classe_id = c.id
             WHERE ep.parent_id = :pid AND e.est_actif = 1
             ORDER BY c.niveau DESC",
            ['pid' => $parentId]
        );
    }

    public function childLatestAverage(int $studentId)
    {
        return $this->db->fetchColumn(
            'SELECT moyenne_generale FROM resultats_trimestriels WHERE eleve_id = :eid ORDER BY trimestre_id DESC LIMIT 1',
            ['eid' => $studentId]
        );
    }

    public function childAbsenceCount(int $studentId, int $termId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences
             WHERE eleve_id = :eid AND type_absence = 'Absence'
             AND date_absence BETWEEN
                 (SELECT date_debut FROM trimestres WHERE id = :tid1)
                 AND (SELECT date_fin FROM trimestres WHERE id = :tid2)",
            ['eid' => $studentId, 'tid1' => $termId, 'tid2' => $termId]
        );
    }

    public function childLateCount(int $studentId, int $termId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM retards WHERE eleve_id = :eid
             AND date_retard BETWEEN
                 (SELECT date_debut FROM trimestres WHERE id = :tid1)
                 AND (SELECT date_fin FROM trimestres WHERE id = :tid2)',
            ['eid' => $studentId, 'tid1' => $termId, 'tid2' => $termId]
        );
    }

    public function childOutstandingPayment(int $studentId): float
    {
        return (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(montant_total - montant_paye), 0)
             FROM paiements WHERE eleve_id = :eid AND statut != 'Payé'",
            ['eid' => $studentId]
        );
    }

    public function upcomingEvents(): array
    {
        return $this->db->fetchAll(
            "SELECT titre, type_evenement, date_debut, date_fin, lieu
             FROM calendrier_scolaire
             WHERE date_debut >= CURDATE()
             AND annee_scolaire_id = (SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1)
             ORDER BY date_debut ASC LIMIT 5"
        );
    }

    public function unreadMessages(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM messages WHERE destinataire_id = :uid AND est_lu = 0 AND est_supprime_destinataire = 0',
            ['uid' => $userId]
        );
    }

    public function unreadNotifications(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :uid AND est_lue = 0',
            ['uid' => $userId]
        );
    }
}
