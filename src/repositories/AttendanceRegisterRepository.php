<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class AttendanceRegisterRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findClosuresOnDate(int $yearId, string $date): array
    {
        return $this->db->fetchAll(
            "SELECT titre, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :year_id
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :date_start AND date_fin >= :date_end
             ORDER BY date_debut",
            ['year_id' => $yearId, 'date_start' => $date, 'date_end' => $date]
        );
    }

    public function findScheduledDays(int $classId, int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT jour FROM emploi_du_temps
             WHERE classe_id = :class_id AND annee_scolaire_id = :year_id
               AND COALESCE(type_creneau, 'cours') <> 'recréation'",
            ['class_id' => $classId, 'year_id' => $yearId]
        );
    }

    public function findClosuresInRange(int $yearId, string $startDate, string $endDate): array
    {
        return $this->db->fetchAll(
            "SELECT titre, type_evenement, date_debut, date_fin, concerne_classes
             FROM calendrier_scolaire
             WHERE annee_scolaire_id = :year_id
               AND type_evenement IN ('Vacances', 'Jour férié')
               AND date_debut <= :end_date AND date_fin >= :start_date",
            [
                'year_id' => $yearId,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]
        );
    }

    public function findScheduledDayRows(int $classId, int $yearId): array
    {
        return $this->findScheduledDays($classId, $yearId);
    }

    public function getSchoolYears(): array
    {
        return $this->db->fetchAll('SELECT id, libelle, date_debut, date_fin FROM annees_scolaires ORDER BY date_debut DESC');
    }

    public function getActiveSchoolYearId(): int
    {
        return (int) $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
    }

    public function findClasses(int $yearId, ?int $teacherUserId = null): array
    {
        $where = 'c.annee_scolaire_id = :annee';
        $params = ['annee' => $yearId];
        if ($teacherUserId !== null) {
            $where .= ' AND c.id = (SELECT e.classe_id FROM enseignants e WHERE e.utilisateur_id = :utilisateur AND e.est_actif = 1 LIMIT 1)';
            $params['utilisateur'] = $teacherUserId;
        }
        return $this->db->fetchAll("SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id FROM classes c WHERE {$where} ORDER BY c.niveau, c.nom", $params);
    }

    public function findClassForDate(int $classId): ?array
    {
        return $this->db->fetchOne(
            'SELECT c.id, c.nom, c.annee_scolaire_id, a.date_debut, a.date_fin FROM classes c INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id WHERE c.id = :id',
            ['id'=>$classId]
        );
    }

    public function findClassOwner(int $userId, int $classId): bool
    {
        return (bool) $this->db->fetchColumn(
            'SELECT id FROM enseignants WHERE utilisateur_id = :utilisateur AND classe_id = :classe AND est_actif = 1 LIMIT 1',
            ['utilisateur'=>$userId,'classe'=>$classId]
        );
    }

    public function findStudentsForCall(int $classId, int $yearId): array
    {
        return $this->db->fetchAll(
            "SELECT e.id FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
               AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1",
            ['classe'=>$classId,'annee'=>$yearId]
        );
    }

    public function getPointageData(int $classId, int $yearId, string $date): array
    {
        return [
            'eleves'=>$this->db->fetchAll(
                "SELECT e.id, e.matricule, e.nom, e.prenom, e.sexe FROM inscriptions i
                 INNER JOIN eleves e ON e.id = i.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                   AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1 ORDER BY e.nom, e.prenom",
                ['classe'=>$classId,'annee'=>$yearId]
            ),
            'statuts'=>$this->db->fetchAll(
                'SELECT eleve_id, type_absence, est_justifiee, motif FROM absences
                 WHERE date_absence = :date AND eleve_id IN
                   (SELECT eleve_id FROM inscriptions WHERE classe_id = :classe AND annee_scolaire_id = :annee)',
                ['date'=>$date,'classe'=>$classId,'annee'=>$yearId]
            ),
            'appel'=>$this->db->fetchOne(
                'SELECT statut, motif_conge FROM appels_presence WHERE classe_id = :classe AND date_appel = :date',
                ['classe'=>$classId,'date'=>$date]
            )
        ];
    }

    public function findRegisterClass(int $classId, int $yearId): ?array
    {
        return $this->db->fetchOne(
            "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id, a.libelle AS annee_libelle,
                    a.date_debut, a.date_fin, p.nom_ecole, p.logo,
                    CONCAT(COALESCE(en.prenom, ''), ' ', COALESCE(en.nom, '')) AS titulaire
             FROM classes c INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
             LEFT JOIN parametres_ecole p ON p.id = 1
             LEFT JOIN enseignants en ON en.classe_id = c.id AND en.est_actif = 1
             WHERE c.id = :classe AND c.annee_scolaire_id = :annee LIMIT 1",
            ['classe'=>$classId,'annee'=>$yearId]
        );
    }

    public function getRegisterData(int $classId, int $yearId, string $startDate, string $endDate): array
    {
        return [
            'classe'=>$this->db->fetchOne(
                "SELECT c.id, c.nom, c.niveau, c.annee_scolaire_id, a.libelle AS annee_libelle,
                        a.date_debut, a.date_fin, p.nom_ecole, p.logo,
                        CONCAT(COALESCE(en.prenom, ''), ' ', COALESCE(en.nom, '')) AS titulaire
                 FROM classes c INNER JOIN annees_scolaires a ON a.id = c.annee_scolaire_id
                 LEFT JOIN parametres_ecole p ON p.id = 1
                 LEFT JOIN enseignants en ON en.classe_id = c.id AND en.est_actif = 1
                 WHERE c.id = :classe AND c.annee_scolaire_id = :annee LIMIT 1",
                ['classe'=>$classId,'annee'=>$yearId]
            ),
            'eleves'=>$this->db->fetchAll(
                "SELECT i.id AS inscription_id, i.statut AS statut_inscription, i.date_inscription,
                        i.date_changement_statut, e.id, e.matricule, e.nom, e.prenom, e.sexe,
                        e.date_naissance, e.lieu_naissance,
                        (SELECT GROUP_CONCAT(CONCAT(pt.prenom, ' ', pt.nom) ORDER BY pt.lien SEPARATOR ' / ')
                         FROM eleve_parent ep INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                         WHERE ep.eleve_id = e.id AND pt.lien = 'Père') AS pere,
                        (SELECT GROUP_CONCAT(CONCAT(pt.prenom, ' ', pt.nom) ORDER BY pt.lien SEPARATOR ' / ')
                         FROM eleve_parent ep INNER JOIN parents_tuteurs pt ON pt.id = ep.parent_id
                         WHERE ep.eleve_id = e.id AND pt.lien = 'Mère') AS mere
                 FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                 ORDER BY e.nom, e.prenom, i.date_inscription",
                ['classe'=>$classId,'annee'=>$yearId]
            ),
            'appels'=>$this->db->fetchAll('SELECT date_appel, statut, motif_conge FROM appels_presence WHERE classe_id = :classe AND annee_scolaire_id = :annee',['classe'=>$classId,'annee'=>$yearId]),
            'absences'=>$this->db->fetchAll(
                "SELECT a.eleve_id, a.date_absence, a.type_absence, a.est_justifiee
                 FROM absences a INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                   AND a.date_absence BETWEEN :debut AND :fin",
                ['classe'=>$classId,'annee'=>$yearId,'debut'=>$startDate,'fin'=>$endDate]
            )
        ];
    }


    public function saveDailyCall(int $classId, int $yearId, string $date, array $studentIds, array $presentIds, int $userId, bool $scheduled): string
    {
        $this->db->beginTransaction();
        try {
            $call = $this->db->fetchOne('SELECT statut FROM appels_presence WHERE classe_id = :classe AND date_appel = :date FOR UPDATE', ['classe'=>$classId,'date'=>$date]);
            if ($call && $call['statut'] === 'conge') {
                $this->db->rollback();
                return 'holiday';
            }
            if (!$scheduled && (!$call || $call['statut'] !== 'valide')) {
                $this->db->rollback();
                return 'not_scheduled';
            }
            $current = $this->db->fetchAll(
                "SELECT e.id FROM inscriptions i INNER JOIN eleves e ON e.id = i.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee
                   AND i.statut IN ('Actif', 'Redoublant') AND e.est_actif = 1
                 ORDER BY e.id FOR UPDATE",
                ['classe'=>$classId,'annee'=>$yearId]
            );
            $currentIds = array_map('intval', array_column($current, 'id'));
            if (!$currentIds || array_diff($studentIds, $currentIds) || array_diff($currentIds, $studentIds)) {
                $this->db->rollback();
                return 'enrollment_changed';
            }
            foreach ($currentIds as $studentId) {
                $this->db->query(
                    "INSERT INTO absences (eleve_id, date_absence, type_absence, est_justifiee, saisie_par, created_at)
                     VALUES (:eleve, :date, :type, 0, :utilisateur, NOW())
                     ON DUPLICATE KEY UPDATE
                        type_absence = VALUES(type_absence),
                        est_justifiee = IF(VALUES(type_absence) = 'Présent', 0, est_justifiee),
                        motif = IF(VALUES(type_absence) = 'Présent', NULL, motif),
                        date_justification = IF(VALUES(type_absence) = 'Présent', NULL, date_justification),
                        saisie_par = VALUES(saisie_par)",
                    ['eleve'=>$studentId,'date'=>$date,'type'=>in_array($studentId,$presentIds,true)?'Présent':'Absence','utilisateur'=>$userId]
                );
            }
            $this->db->query(
                "INSERT INTO appels_presence (classe_id, annee_scolaire_id, date_appel, statut, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'valide', :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE statut = 'valide', motif_conge = NULL,
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                ['classe'=>$classId,'annee'=>$yearId,'date'=>$date,'utilisateur'=>$userId]
            );
            $this->db->commit();
            return 'saved';
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function saveManualClosure(int $classId, int $yearId, string $date, string $reason, int $userId): string
    {
        $this->db->beginTransaction();
        try {
            $call = $this->db->fetchOne('SELECT statut FROM appels_presence WHERE classe_id = :classe AND date_appel = :date FOR UPDATE', ['classe'=>$classId,'date'=>$date]);
            $marks = (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM absences a INNER JOIN inscriptions i ON i.eleve_id = a.eleve_id
                 WHERE i.classe_id = :classe AND i.annee_scolaire_id = :annee AND a.date_absence = :date",
                ['classe'=>$classId,'annee'=>$yearId,'date'=>$date]
            );
            if (($call && $call['statut'] === 'valide') || $marks > 0) {
                $this->db->rollback();
                return 'already_marked';
            }
            $this->db->query(
                "INSERT INTO appels_presence (classe_id, annee_scolaire_id, date_appel, statut, motif_conge, traite_par, traite_le)
                 VALUES (:classe, :annee, :date, 'conge', :motif, :utilisateur, NOW())
                 ON DUPLICATE KEY UPDATE statut = 'conge', motif_conge = VALUES(motif_conge),
                    traite_par = VALUES(traite_par), traite_le = NOW()",
                ['classe'=>$classId,'annee'=>$yearId,'date'=>$date,'motif'=>$reason,'utilisateur'=>$userId]
            );
            $this->db->commit();
            return 'saved';
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function cancelManualClosure(int $classId, int $yearId, string $date): int
    {
        return $this->db->delete(
            "DELETE FROM appels_presence WHERE classe_id = :classe AND annee_scolaire_id = :annee
             AND date_appel = :date AND statut = 'conge'",
            ['classe'=>$classId,'annee'=>$yearId,'date'=>$date]
        );
    }

}
