<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class DashboardRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getActiveYearId(): int
    {
        $id = $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
        if (!$id) {
            $id = $this->db->fetchColumn("SELECT id FROM annees_scolaires WHERE est_active = 1 LIMIT 1");
        }
        return (int) ($id ?? 0);
    }

    public function getStats(int $yearId, int $userId): array
    {
        $params = ['annee' => $yearId];
        $totalStudents = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inscriptions WHERE annee_scolaire_id = :annee AND statut = 'Actif'",
            $params
        );
        $totalTeachers = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM enseignants WHERE est_actif = 1');
        $totalClasses = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM classes WHERE annee_scolaire_id = :annee',
            $params
        );
        $absencesToday = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences WHERE date_absence = CURDATE() AND type_absence = 'Absence'"
        );
        $presencesToday = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM absences WHERE date_absence = CURDATE() AND type_absence = 'Présent'"
        );
        $totalToday = $absencesToday + $presencesToday;
        $paymentsMonth = (float) $this->db->fetchColumn(
            "SELECT COALESCE(SUM(
                    CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu
                         ELSE v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0) END
                 ), 0)
             FROM finance_versements v
             INNER JOIN finance_affectations a ON a.versement_id = v.id
             INNER JOIN finance_creances cr ON cr.id = a.creance_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             WHERE cr.annee_scolaire_id = :annee AND an.id IS NULL
               AND MONTH(v.date_versement) = MONTH(CURDATE())
               AND YEAR(v.date_versement) = YEAR(CURDATE())",
            $params
        );
        $unreadMessages = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM messages
             WHERE destinataire_id = :uid AND est_lu = 0 AND est_supprime_destinataire = 0',
            ['uid' => $userId]
        );
        $studentsThisMonth = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM eleves
             WHERE MONTH(date_inscription) = MONTH(CURDATE()) AND YEAR(date_inscription) = YEAR(CURDATE())'
        );
        $studentsLastMonth = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM eleves
             WHERE MONTH(date_inscription) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
               AND YEAR(date_inscription) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))'
        );
        return [
            'totalEleves' => $totalStudents,
            'totalEnseignants' => $totalTeachers,
            'totalClasses' => $totalClasses,
            'tauxPresence' => $totalToday > 0 ? round(($presencesToday / $totalToday) * 100, 1) : 0,
            'paiementsMois' => $paymentsMonth,
            'messagesNonLus' => $unreadMessages,
            'elevesCeMois' => $studentsThisMonth,
            'elevesMoisDernier' => $studentsLastMonth,
        ];
    }

    public function studentsPerClass(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT c.nom, COUNT(i.id) AS total
             FROM classes c
             LEFT JOIN inscriptions i ON c.id = i.classe_id AND i.statut = 'Actif'
             WHERE c.annee_scolaire_id = :annee
             GROUP BY c.id, c.nom
             ORDER BY c.niveau",
            ['annee' => $yearId]
        );
    }

    public function monthlyPayments(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT MONTH(v.date_versement) AS mois,
                    COALESCE(SUM(
                        CASE WHEN v.devise_recue = 'USD' THEN v.montant_recu
                             ELSE v.montant_recu / NULLIF(v.taux_cdf_par_usd, 0) END
                    ), 0) AS total
             FROM finance_versements v
             INNER JOIN finance_affectations a ON a.versement_id = v.id
             INNER JOIN finance_creances cr ON cr.id = a.creance_id
             LEFT JOIN finance_annulations an ON an.versement_id = v.id
             WHERE cr.annee_scolaire_id = :annee AND an.id IS NULL
               AND YEAR(v.date_versement) = YEAR(CURDATE())
             GROUP BY MONTH(v.date_versement)
             ORDER BY mois",
            ['annee' => $yearId]
        );
    }

    public function presencePerClass(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT c.nom,
                    COALESCE(SUM(CASE WHEN a.type_absence = 'Présent' THEN 1 ELSE 0 END), 0) AS presents,
                    COUNT(a.id) AS total
             FROM classes c
             INNER JOIN inscriptions i ON c.id = i.classe_id AND i.statut = 'Actif'
             INNER JOIN eleves e ON i.eleve_id = e.id AND e.est_actif = 1
             LEFT JOIN absences a ON e.id = a.eleve_id AND a.date_absence = CURDATE()
             WHERE c.annee_scolaire_id = :annee
             GROUP BY c.id, c.nom
             ORDER BY c.niveau",
            ['annee' => $yearId]
        );
    }

    public function genderDistribution(int $yearId): array
    {
        $boys = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             WHERE e.sexe = 'M' AND i.annee_scolaire_id = :annee AND e.est_actif = 1",
            ['annee' => $yearId]
        );
        $girls = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM eleves e
             INNER JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             WHERE e.sexe = 'F' AND i.annee_scolaire_id = :annee AND e.est_actif = 1",
            ['annee' => $yearId]
        );
        return [$boys, $girls];
    }

    public function termResults(int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT t.libelle,
                    COALESCE(SUM(CASE WHEN r.decision = 'Passe' THEN 1 ELSE 0 END), 0) AS reussite,
                    COALESCE(SUM(CASE WHEN r.decision = 'Ajourné' THEN 1 ELSE 0 END), 0) AS ajournement,
                    COALESCE(SUM(CASE WHEN r.decision = 'Redouble' THEN 1 ELSE 0 END), 0) AS echec
             FROM trimestres t
             LEFT JOIN resultats_trimestriels r ON t.id = r.trimestre_id
             WHERE t.annee_scolaire_id = :annee
             GROUP BY t.id, t.libelle
             ORDER BY t.numero",
            ['annee' => $yearId]
        );
    }

    public function latestStudents(): array
    {
        return $this->db->fetchAll(
            "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe,
                    DATE_FORMAT(e.date_inscription, '%d/%m/%Y') AS date_inscription,
                    c.nom AS classe
             FROM eleves e
             LEFT JOIN inscriptions i ON e.id = i.eleve_id AND i.statut = 'Actif'
             LEFT JOIN classes c ON i.classe_id = c.id
             WHERE e.est_actif = 1
             ORDER BY e.date_inscription DESC
             LIMIT 5"
        );
    }

    public function upcomingBirthdays(): array
    {
        return $this->db->fetchAll(
            "SELECT id, nom, prenom,
                    DATE_FORMAT(date_naissance, '%d/%m') AS date_naissance,
                    TIMESTAMPDIFF(YEAR, date_naissance, CURDATE()) + 1 AS age
             FROM eleves
             WHERE est_actif = 1
               AND DATE_FORMAT(date_naissance, '%m-%d')
                   BETWEEN DATE_FORMAT(CURDATE(), '%m-%d')
                   AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '%m-%d')
             ORDER BY DATE_FORMAT(date_naissance, '%m-%d')
             LIMIT 5"
        );
    }

    public function recentActivities(): array
    {
        return $this->db->fetchAll(
            "SELECT description,
                    DATE_FORMAT(date_action, '%d/%m/%Y à %H:%i') AS date_action
             FROM logs_activites
             ORDER BY date_action DESC
             LIMIT 10"
        );
    }
}
