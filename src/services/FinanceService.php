<?php

defined('ACCESS') or die('Accès interdit');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../repositories/FinanceRepository.php';

class FinanceService
{
    private Database $db;
    private FinanceRepository $repository;

    public function __construct(?Database $db = null, ?FinanceRepository $repository = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->repository = $repository ?? new FinanceRepository($this->db);
    }

    public function getActiveYearId(): int
    {
        return $this->repository->getActiveYearId();
    }

    public function getStudentReceipt(int $versementId): ?array
    {
        return $this->repository->findStudentReceipts($versementId);
    }

    public function getActiveFees(): array
    {
        return $this->repository->findActiveFees();
    }

    public function searchStudents(int $yearId, string $search): array
    {
        return $this->repository->searchStudents($yearId, trim($search));
    }

    public function isStudentEnrolled(int $studentId, int $yearId): bool
    {
        return $this->repository->isStudentEnrolled($studentId, $yearId);
    }

    public function createFinancialFollowUp(array $data): int
    {
        return $this->repository->createFinancialFollowUp($data);
    }

    public function getActiveClasses(int $yearId): array
    {
        return $this->repository->findActiveClasses($yearId);
    }

    public function listFeesForYear(int $yearId): array
    {
        return $this->repository->listFeesForYear($yearId);
    }

    public function createFee(array $data): int
    {
        if (!$this->repository->schoolYearExists((int) $data['annee_id'])) {
            throw new DomainException('SCHOOL_YEAR_NOT_FOUND');
        }
        if (!empty($data['classe_id']) && !$this->repository->classBelongsToYear((int) $data['classe_id'], (int) $data['annee_id'])) {
            throw new DomainException('CLASS_YEAR_MISMATCH');
        }
        if ($this->repository->feeExists($data['libelle'], $data['annee_id'], $data['classe_id'])) {
            throw new DomainException('DUPLICATE_FEE');
        }
        $this->db->beginTransaction();
        try {
            $feeId = $this->repository->createFee($data);
            $this->repository->createFeeConfig($feeId, $data);
            $this->db->commit();
            return $feeId;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function updateFee(int $feeId, array $data): void
    {
        $fee = $this->repository->findFee($feeId);
        if (!$fee) {
            throw new DomainException('FEE_NOT_FOUND');
        }
        if (!empty($data['classe_id']) && !$this->repository->classBelongsToYear((int) $data['classe_id'], (int) $fee['annee_scolaire_id'])) {
            throw new DomainException('CLASS_YEAR_MISMATCH');
        }
        if ($this->repository->feeExists($data['libelle'], (int) $fee['annee_scolaire_id'], $data['classe_id'], $feeId)) {
            throw new DomainException('DUPLICATE_FEE');
        }
        $this->db->beginTransaction();
        try {
            $this->repository->updateFee($feeId, $data);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function deleteFeeIfUnused(int $feeId): void
    {
        if (!$this->repository->findFee($feeId)) {
            throw new DomainException('FEE_NOT_FOUND');
        }
        if ($this->repository->countLegacyPaymentsForFee($feeId) > 0
            || $this->repository->countClaimsForFee($feeId) > 0) {
            throw new DomainException('FEE_HAS_HISTORY');
        }
        $this->repository->deleteFee($feeId);
    }

    public function getFeeForStatus(int $feeId): ?array
    {
        return $this->repository->findFee($feeId);
    }

    public function toggleFeeStatus(int $feeId): int
    {
        $fee = $this->repository->findFee($feeId);
        if (!$fee) {
            throw new DomainException('FEE_NOT_FOUND');
        }
        $status = (int) $fee['est_actif'] === 1 ? 0 : 1;
        $this->repository->updateFeeStatus($feeId, $status);
        return $status;
    }

    public static function convertirMontant(float $montant, string $deviseSource, string $deviseCible, float $tauxCdfParUsd): float
    {
        if (!is_finite($montant) || $montant <= 0
            || !in_array($deviseSource, ['USD', 'CDF'], true)
            || !in_array($deviseCible, ['USD', 'CDF'], true)) {
            throw new InvalidArgumentException('Montant ou devise invalide.');
        }

        if ($deviseSource === $deviseCible) {
            return round($montant, 2);
        }
        if (!is_finite($tauxCdfParUsd) || $tauxCdfParUsd <= 0) {
            throw new InvalidArgumentException('Le taux CDF par USD doit être supérieur à zéro.');
        }

        return round(
            $deviseSource === 'CDF' ? $montant / $tauxCdfParUsd : $montant * $tauxCdfParUsd,
            2
        );
    }

    public static function estFraisScolarite(string $categorie): bool
    {
        $categorie = mb_strtolower(trim($categorie), 'UTF-8');
        $translitteree = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $categorie);
        $translitteree = $translitteree === false ? $categorie : str_replace("'", '', $translitteree);
        $categorieNormalisee = trim((string) preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            strtolower($translitteree)
        ));

        return preg_match('/^(scolarite|minerval)(?:\s|$)/', $categorieNormalisee) === 1
            || in_array($categorieNormalisee, ['frais scolaire', 'frais scolaires', 'frais de scolarite'], true);
    }

    public function getCreancesEleve(int $eleveId, int $anneeId): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, cfg.categorie,
                    COALESCE(p.montant_paye, 0) AS montant_paye,
                    GREATEST(c.montant_du - COALESCE(p.montant_paye, 0), 0) AS reste_a_payer
             FROM finance_creances c
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = c.frais_scolaire_id
             LEFT JOIN (
                 SELECT a.creance_id, SUM(a.montant_affecte) AS montant_paye
                 FROM finance_affectations a
                 INNER JOIN finance_versements v ON v.id = a.versement_id
                 LEFT JOIN finance_annulations an ON an.versement_id = v.id
                 WHERE an.id IS NULL
                 GROUP BY a.creance_id
             ) p ON p.creance_id = c.id
             WHERE c.eleve_id = :eleve AND c.annee_scolaire_id = :annee
               AND GREATEST(c.montant_du - COALESCE(p.montant_paye, 0), 0) > 0
             ORDER BY c.date_echeance IS NULL, c.date_echeance, c.libelle_snapshot, c.periode_code",
            ['eleve' => $eleveId, 'annee' => $anneeId]
        );
    }

    public function genererCreancesAnnee(int $anneeId, ?int $utilisateurId): int
    {
        $annee = $this->db->fetchOne(
            "SELECT date_debut, date_fin FROM annees_scolaires WHERE id = :id",
            ['id' => $anneeId]
        );
        if (!$annee) {
            throw new DomainException('Année scolaire introuvable.');
        }

        $frais = $this->db->fetchAll(
            "SELECT f.id, f.libelle, f.montant, f.classe_id, cfg.categorie, cfg.devise, cfg.periodicite, cfg.jour_echeance
             FROM frais_scolaires f
             INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = f.id
             WHERE f.annee_scolaire_id = :annee AND f.est_actif = 1",
            ['annee' => $anneeId]
        );
        $inscriptions = $this->db->fetchAll(
            "SELECT i.eleve_id, i.classe_id
             FROM inscriptions i
             INNER JOIN eleves e ON e.id = i.eleve_id
             WHERE i.annee_scolaire_id = :annee
               AND i.statut IN ('Actif', 'Redoublant')
               AND e.est_actif = 1",
            ['annee' => $anneeId]
        );

        $created = 0;
        foreach ($frais as $fraisItem) {
            $periodes = $this->genererPeriodes(
                (string) $fraisItem['periodicite'],
                (string) $annee['date_debut'],
                (string) $annee['date_fin'],
                (int) $fraisItem['jour_echeance']
            );
            foreach ($inscriptions as $inscription) {
                if ($fraisItem['classe_id'] !== null && (int) $fraisItem['classe_id'] !== (int) $inscription['classe_id']) {
                    continue;
                }
                foreach ($periodes as $periode) {
                    $statement = $this->db->query(
                        "INSERT IGNORE INTO finance_creances
                            (eleve_id, frais_scolaire_id, annee_scolaire_id, periode_code,
                             libelle_snapshot, montant_du, devise, date_echeance, cree_par)
                         VALUES (:eleve, :frais, :annee, :periode, :libelle, :montant, :devise, :echeance, :user)",
                        [
                            'eleve' => (int) $inscription['eleve_id'],
                            'frais' => (int) $fraisItem['id'],
                            'annee' => $anneeId,
                            'periode' => $periode['code'],
                            'libelle' => $fraisItem['libelle'],
                            'montant' => $fraisItem['montant'],
                            'devise' => self::estFraisScolarite((string) $fraisItem['categorie'])
                                ? 'USD'
                                : $fraisItem['devise'],
                            'echeance' => $periode['echeance'],
                            'user' => $utilisateurId,
                        ]
                    );
                    $created += $statement->rowCount();
                }
            }
        }

        return $created;
    }

    public function enregistrerVersement(
        int $creanceId,
        int $eleveId,
        float $montantRecu,
        string $deviseRecue,
        float $tauxCdfParUsd,
        string $modePaiement,
        ?string $reference,
        ?string $commentaire,
        ?string $dateVersement,
        ?int $utilisateurId
    ): array {
        if (!is_finite($montantRecu) || $montantRecu <= 0
            || !in_array($deviseRecue, ['USD', 'CDF'], true)) {
            throw new InvalidArgumentException('Le montant reçu et la devise doivent être valides.');
        }

        $this->db->beginTransaction();
        try {
            $creance = $this->db->fetchOne(
                "SELECT c.id, c.eleve_id, c.montant_du, c.devise, cfg.categorie
                 FROM finance_creances c
                 INNER JOIN finance_frais_config cfg ON cfg.frais_scolaire_id = c.frais_scolaire_id
                 WHERE c.id = :id FOR UPDATE",
                ['id' => $creanceId]
            );
            if (!$creance || (int) $creance['eleve_id'] !== $eleveId) {
                throw new DomainException('La créance sélectionnée ne correspond pas à cet élève.');
            }

            $tauxEffectif = $tauxCdfParUsd;
            if (self::estFraisScolarite((string) $creance['categorie'])) {
                $tauxEffectif = (float) $this->db->fetchColumn(
                    "SELECT taux_cdf_par_usd FROM parametres_ecole WHERE id = 1"
                );
            }
            if (!is_finite($tauxEffectif) || $tauxEffectif <= 0) {
                throw new DomainException('Le taux CDF/USD doit être configuré dans les paramètres de l’école.');
            }

            $montantDejaAffecte = (float) $this->db->fetchColumn(
                "SELECT COALESCE(SUM(a.montant_affecte), 0)
                 FROM finance_affectations a
                 INNER JOIN finance_versements v ON v.id = a.versement_id
                 LEFT JOIN finance_annulations an ON an.versement_id = v.id
                 WHERE a.creance_id = :id AND an.id IS NULL",
                ['id' => $creanceId]
            );
            $reste = round((float) $creance['montant_du'] - $montantDejaAffecte, 2);
            if ($reste <= 0) {
                throw new DomainException('Cette créance est déjà entièrement réglée.');
            }

            $montantAffecte = self::convertirMontant(
                $montantRecu,
                $deviseRecue,
                (string) $creance['devise'],
                $tauxEffectif
            );
            if ($montantAffecte > $reste) {
                throw new DomainException(
                    'Le versement dépasse le solde de ' . number_format($reste, 2, ',', ' ') . ' ' . $creance['devise'] . '.'
                );
            }

            $date = $dateVersement ?: date('Y-m-d');
            $dateObjet = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$dateObjet || $dateObjet->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('La date du versement est invalide.');
            }
            if (!in_array($modePaiement, ['Espèces', 'Chèque', 'Virement bancaire', 'Mobile Money', 'Autre'], true)) {
                throw new InvalidArgumentException('Le mode de paiement est invalide.');
            }

            $numeroRecu = 'REC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
            $versementId = $this->db->insert(
                "INSERT INTO finance_versements
                    (numero_recu, eleve_id, montant_recu, devise_recue, taux_cdf_par_usd,
                     mode_paiement, reference_paiement, date_versement, commentaire, enregistre_par)
                 VALUES (:recu, :eleve, :montant, :devise, :taux, :mode, :reference,
                         :date_versement, :commentaire, :user)",
                [
                    'recu' => $numeroRecu,
                    'eleve' => $eleveId,
                    'montant' => round($montantRecu, 2),
                    'devise' => $deviseRecue,
                    'taux' => round($tauxEffectif, 4),
                    'mode' => $modePaiement,
                    'reference' => $reference ?: null,
                    'date_versement' => $date . ' ' . date('H:i:s'),
                    'commentaire' => $commentaire ?: null,
                    'user' => $utilisateurId,
                ]
            );
            $this->db->insert(
                "INSERT INTO finance_affectations (versement_id, creance_id, montant_affecte)
                 VALUES (:versement, :creance, :montant)",
                ['versement' => $versementId, 'creance' => $creanceId, 'montant' => $montantAffecte]
            );

            $this->db->commit();
            return [
                'id' => $versementId,
                'numero_recu' => $numeroRecu,
                'montant_recu' => round($montantRecu, 2),
                'devise_recue' => $deviseRecue,
                'montant_affecte' => $montantAffecte,
                'devise_creance' => $creance['devise'],
                'taux_cdf_par_usd' => round($tauxEffectif, 4),
                'reste_a_payer' => round($reste - $montantAffecte, 2),
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function annulerVersement(int $versementId, string $type, string $motif, ?int $utilisateurId): string
    {
        if (!in_array($type, ['annulation', 'remboursement'], true) || trim($motif) === '') {
            throw new InvalidArgumentException('Le type et le motif de correction sont obligatoires.');
        }

        $this->db->beginTransaction();
        try {
            $versement = $this->db->fetchOne(
                "SELECT id FROM finance_versements WHERE id = :id FOR UPDATE",
                ['id' => $versementId]
            );
            if (!$versement) {
                throw new DomainException('Versement introuvable.');
            }
            if ($this->db->fetchColumn(
                "SELECT id FROM finance_annulations WHERE versement_id = :id",
                ['id' => $versementId]
            )) {
                throw new DomainException('Ce versement a déjà fait l’objet d’une contre-écriture.');
            }

            $numero = 'AVOIR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
            $this->db->insert(
                "INSERT INTO finance_annulations
                    (versement_id, numero_contre_ecriture, type, motif, annule_par)
                 VALUES (:versement, :numero, :type, :motif, :user)",
                [
                    'versement' => $versementId,
                    'numero' => $numero,
                    'type' => $type,
                    'motif' => trim($motif),
                    'user' => $utilisateurId,
                ]
            );
            $this->db->commit();
            return $numero;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function genererPeriodes(string $periodicite, string $dateDebut, string $dateFin, int $jourEcheance): array
    {
        $debut = new DateTimeImmutable($dateDebut);
        $fin = new DateTimeImmutable($dateFin);
        $jourEcheance = max(1, min(31, $jourEcheance));

        if ($periodicite === 'trimestriel') {
            $periodes = [];
            foreach ([0, 4, 8] as $index => $mois) {
                $datePeriode = $debut->modify('+' . $mois . ' months');
                if ($datePeriode > $fin) {
                    continue;
                }
                $periodes[] = [
                    'code' => 'T' . ($index + 1),
                    'echeance' => $this->dateEcheance($datePeriode, $jourEcheance),
                ];
            }
            return $periodes;
        }

        if ($periodicite === 'mensuel') {
            $periodes = [];
            $mois = $debut->modify('first day of this month');
            $dernierMois = $fin->modify('first day of this month');
            while ($mois <= $dernierMois) {
                $periodes[] = [
                    'code' => $mois->format('Y-m'),
                    'echeance' => $this->dateEcheance($mois, $jourEcheance),
                ];
                $mois = $mois->modify('+1 month');
            }
            return $periodes;
        }

        return [[
            'code' => $periodicite === 'unique' ? 'UNIQUE' : 'ANNUEL',
            'echeance' => $this->dateEcheance($debut, $jourEcheance),
        ]];
    }

    private function dateEcheance(DateTimeImmutable $mois, int $jour): string
    {
        $debutMois = $mois->modify('first day of this month');
        $dernierJour = (int) $debutMois->format('t');
        return $debutMois->setDate(
            (int) $debutMois->format('Y'),
            (int) $debutMois->format('m'),
            min($jour, $dernierJour)
        )->format('Y-m-d');
    }
}
