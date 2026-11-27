<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';

class SchoolSettingsRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findSettings(): ?array
    {
        return $this->db->fetchOne('SELECT * FROM parametres_ecole WHERE id = 1');
    }

    public function findYearLabel(int $yearId): ?array
    {
        return $this->db->fetchOne('SELECT libelle FROM annees_scolaires WHERE id = :id', ['id' => $yearId]);
    }

    public function findTermLabel(int $termId): ?array
    {
        return $this->db->fetchOne('SELECT libelle FROM trimestres WHERE id = :id', ['id' => $termId]);
    }

    public function findValidYearAndTerm(int $yearId, int $termId): bool
    {
        $year = $this->db->fetchOne('SELECT id FROM annees_scolaires WHERE id = :id', ['id' => $yearId]);
        $term = $this->db->fetchOne(
            'SELECT id FROM trimestres WHERE id = :id AND annee_scolaire_id = :year_id',
            ['id' => $termId, 'year_id' => $yearId]
        );
        return $year !== null && $term !== null;
    }

    public function saveSettings(array $settings): void
    {
        $this->db->beginTransaction();
        try {
            $existing = $this->findSettings();
            $grading = [
                'bareme' => (float) ($existing['bareme_note_max'] ?? 20),
                'moyenne' => (float) ($existing['moyenne_passage'] ?? 10),
                'pct_interro' => (float) ($existing['pourcentage_interro'] ?? 30),
                'pct_devoir' => (float) ($existing['pourcentage_devoir'] ?? 30),
                'pct_examen' => (float) ($existing['pourcentage_examen'] ?? 40),
            ];
            $data = array_merge($settings, $grading);
            if ($existing !== null) {
                $this->db->update(
                    'UPDATE parametres_ecole SET
                        nom_ecole = :nom_ecole, devise = :devise, taux_cdf_par_usd = :taux_cdf_par_usd,
                        adresse = :adresse, telephone = :telephone, email = :email, site_web = :site_web,
                        pays = :pays, province = :province, ministere = :ministere, ville = :ville,
                        commune_territoire = :commune, code_etablissement = :code_etab,
                        theme_interface = :theme_interface, annee_scolaire_active_id = :annee_id,
                        trimestre_actif_id = :trim_id, bareme_note_max = :bareme, moyenne_passage = :moyenne,
                        pourcentage_interro = :pct_interro, pourcentage_devoir = :pct_devoir,
                        pourcentage_examen = :pct_examen, format_matricule = :format_mat
                     WHERE id = 1',
                    $data
                );
            } else {
                $this->db->insert(
                    'INSERT INTO parametres_ecole
                        (nom_ecole, devise, taux_cdf_par_usd, adresse, telephone, email, site_web,
                         pays, province, ministere, ville, commune_territoire, code_etablissement,
                         theme_interface, annee_scolaire_active_id, trimestre_actif_id, bareme_note_max,
                         moyenne_passage, pourcentage_interro, pourcentage_devoir, pourcentage_examen, format_matricule)
                     VALUES
                        (:nom_ecole, :devise, :taux_cdf_par_usd, :adresse, :telephone, :email, :site_web,
                         :pays, :province, :ministere, :ville, :commune, :code_etab, :theme_interface,
                         :annee_id, :trim_id, :bareme, :moyenne, :pct_interro, :pct_devoir, :pct_examen, :format_mat)',
                    $data
                );
            }

            $this->db->update('UPDATE annees_scolaires SET est_active = 0');
            $this->db->update('UPDATE annees_scolaires SET est_active = 1 WHERE id = :id', ['id' => $settings['annee_id']]);
            $this->db->update(
                'UPDATE trimestres SET est_actif = 0 WHERE annee_scolaire_id = :year_id',
                ['year_id' => $settings['annee_id']]
            );
            $this->db->update('UPDATE trimestres SET est_actif = 1 WHERE id = :id', ['id' => $settings['term_id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    public function findLogo(): ?array
    {
        return $this->db->fetchOne('SELECT logo FROM parametres_ecole WHERE id = 1');
    }

    public function updateLogo(string $relativePath): void
    {
        $this->db->update('UPDATE parametres_ecole SET logo = :logo WHERE id = 1', ['logo' => $relativePath]);
    }

    public function findSchoolYears(): array
    {
        return $this->db->fetchAll('SELECT * FROM annees_scolaires ORDER BY date_debut DESC');
    }

    public function findTerms(int $yearId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM trimestres WHERE annee_scolaire_id = :year_id ORDER BY numero',
            ['year_id' => $yearId]
        );
    }

    public function findActiveYearId(): int
    {
        return (int) $this->db->fetchColumn('SELECT annee_scolaire_active_id FROM parametres_ecole LIMIT 1');
    }
}
