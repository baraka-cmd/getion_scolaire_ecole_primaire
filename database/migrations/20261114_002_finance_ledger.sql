SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_frais_config` (
    `frais_scolaire_id` INT(11) NOT NULL,
    `categorie` VARCHAR(80) NOT NULL DEFAULT 'Scolarité',
    `devise` ENUM('USD', 'CDF') NOT NULL DEFAULT 'USD',
    `periodicite` ENUM('unique', 'annuel', 'trimestriel', 'mensuel') NOT NULL DEFAULT 'annuel',
    `jour_echeance` TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`frais_scolaire_id`),
    CONSTRAINT `fk_finance_frais_config_frais` FOREIGN KEY (`frais_scolaire_id`)
        REFERENCES `frais_scolaires` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_creances` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eleve_id` INT(11) NOT NULL,
    `frais_scolaire_id` INT(11) NOT NULL,
    `annee_scolaire_id` INT(11) NOT NULL,
    `periode_code` VARCHAR(20) NOT NULL,
    `libelle_snapshot` VARCHAR(150) NOT NULL,
    `montant_du` DECIMAL(12,2) NOT NULL,
    `devise` ENUM('USD', 'CDF') NOT NULL,
    `date_echeance` DATE DEFAULT NULL,
    `source_creance_id` BIGINT UNSIGNED DEFAULT NULL,
    `origine` ENUM('frais', 'report') NOT NULL DEFAULT 'frais',
    `cree_par` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_creance_periode` (`eleve_id`, `frais_scolaire_id`, `annee_scolaire_id`, `periode_code`),
    KEY `idx_finance_creance_annee_eleve` (`annee_scolaire_id`, `eleve_id`),
    KEY `idx_finance_creance_frais` (`frais_scolaire_id`, `annee_scolaire_id`),
    CONSTRAINT `fk_finance_creance_eleve` FOREIGN KEY (`eleve_id`)
        REFERENCES `eleves` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_creance_frais` FOREIGN KEY (`frais_scolaire_id`)
        REFERENCES `frais_scolaires` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_creance_annee` FOREIGN KEY (`annee_scolaire_id`)
        REFERENCES `annees_scolaires` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_creance_source` FOREIGN KEY (`source_creance_id`)
        REFERENCES `finance_creances` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_creance_user` FOREIGN KEY (`cree_par`)
        REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_versements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `numero_recu` VARCHAR(50) NOT NULL,
    `eleve_id` INT(11) NOT NULL,
    `montant_recu` DECIMAL(12,2) NOT NULL,
    `devise_recue` ENUM('USD', 'CDF') NOT NULL,
    `taux_cdf_par_usd` DECIMAL(14,4) NOT NULL DEFAULT 1.0000,
    `mode_paiement` VARCHAR(60) NOT NULL,
    `reference_paiement` VARCHAR(100) DEFAULT NULL,
    `date_versement` DATETIME NOT NULL,
    `commentaire` TEXT DEFAULT NULL,
    `enregistre_par` INT(11) DEFAULT NULL,
    `source_facture_id` INT(11) DEFAULT NULL,
    `legacy_paiement_id` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_versement_recu` (`numero_recu`),
    UNIQUE KEY `uk_finance_versement_facture_legacy` (`source_facture_id`),
    KEY `idx_finance_versement_eleve_date` (`eleve_id`, `date_versement`),
    KEY `idx_finance_versement_date` (`date_versement`),
    CONSTRAINT `fk_finance_versement_eleve` FOREIGN KEY (`eleve_id`)
        REFERENCES `eleves` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_versement_user` FOREIGN KEY (`enregistre_par`)
        REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_affectations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `versement_id` BIGINT UNSIGNED NOT NULL,
    `creance_id` BIGINT UNSIGNED NOT NULL,
    `montant_affecte` DECIMAL(12,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_affectation` (`versement_id`, `creance_id`),
    KEY `idx_finance_affectation_creance` (`creance_id`),
    CONSTRAINT `fk_finance_affectation_versement` FOREIGN KEY (`versement_id`)
        REFERENCES `finance_versements` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_affectation_creance` FOREIGN KEY (`creance_id`)
        REFERENCES `finance_creances` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_annulations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `versement_id` BIGINT UNSIGNED NOT NULL,
    `numero_contre_ecriture` VARCHAR(50) NOT NULL,
    `type` ENUM('annulation', 'remboursement') NOT NULL,
    `motif` VARCHAR(500) NOT NULL,
    `date_annulation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `annule_par` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_finance_annulation_versement` (`versement_id`),
    UNIQUE KEY `uk_finance_annulation_numero` (`numero_contre_ecriture`),
    CONSTRAINT `fk_finance_annulation_versement` FOREIGN KEY (`versement_id`)
        REFERENCES `finance_versements` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_annulation_user` FOREIGN KEY (`annule_par`)
        REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_suivis` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eleve_id` INT(11) NOT NULL,
    `annee_scolaire_id` INT(11) NOT NULL,
    `type_suivi` VARCHAR(30) NOT NULL,
    `contact_parent` VARCHAR(150) DEFAULT NULL,
    `date_action` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_relance` DATE DEFAULT NULL,
    `motif` VARCHAR(500) NOT NULL,
    `suite_donnee` TEXT DEFAULT NULL,
    `statut` ENUM('à_faire', 'effectué', 'sans_suite') NOT NULL DEFAULT 'effectué',
    `saisi_par` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_finance_suivi_eleve_date` (`eleve_id`, `date_action`),
    KEY `idx_finance_suivi_relance` (`annee_scolaire_id`, `date_relance`, `statut`),
    CONSTRAINT `fk_finance_suivi_eleve` FOREIGN KEY (`eleve_id`)
        REFERENCES `eleves` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_suivi_annee` FOREIGN KEY (`annee_scolaire_id`)
        REFERENCES `annees_scolaires` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_finance_suivi_user` FOREIGN KEY (`saisi_par`)
        REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `finance_frais_config` (`frais_scolaire_id`)
SELECT `id` FROM `frais_scolaires`;

INSERT IGNORE INTO `finance_creances`
    (`eleve_id`, `frais_scolaire_id`, `annee_scolaire_id`, `periode_code`,
     `libelle_snapshot`, `montant_du`, `devise`, `cree_par`, `created_at`)
SELECT p.`eleve_id`, p.`frais_scolaire_id`, fs.`annee_scolaire_id`, 'ANNUEL',
       fs.`libelle`, MAX(p.`montant_total`), 'USD', MIN(p.`enregistre_par`), MIN(p.`created_at`)
FROM `paiements` p
INNER JOIN `frais_scolaires` fs ON fs.`id` = p.`frais_scolaire_id`
GROUP BY p.`eleve_id`, p.`frais_scolaire_id`, fs.`annee_scolaire_id`, fs.`libelle`;

INSERT IGNORE INTO `finance_versements`
    (`numero_recu`, `eleve_id`, `montant_recu`, `devise_recue`, `taux_cdf_par_usd`,
     `mode_paiement`, `date_versement`, `enregistre_par`, `source_facture_id`)
SELECT f.`numero_facture`, f.`eleve_id`, f.`montant_TTC`, 'USD', 1.0000,
       p.`mode_paiement`, TIMESTAMP(f.`date_emission`), f.`emise_par`, f.`id`
FROM `factures` f
INNER JOIN `paiements` p ON p.`id` = f.`paiement_id`
WHERE f.`montant_TTC` > 0;

INSERT IGNORE INTO `finance_versements`
    (`numero_recu`, `eleve_id`, `montant_recu`, `devise_recue`, `taux_cdf_par_usd`,
     `mode_paiement`, `date_versement`, `commentaire`, `enregistre_par`, `legacy_paiement_id`)
SELECT CONCAT('LEGACY-P-', p.`id`), p.`eleve_id`, p.`montant_paye`, 'USD', 1.0000,
       p.`mode_paiement`, TIMESTAMP(p.`date_paiement`), p.`commentaire`,
       p.`enregistre_par`, p.`id`
FROM `paiements` p
WHERE p.`montant_paye` > 0
  AND NOT EXISTS (SELECT 1 FROM `factures` f WHERE f.`paiement_id` = p.`id`);

INSERT IGNORE INTO `finance_affectations` (`versement_id`, `creance_id`, `montant_affecte`)
SELECT v.`id`, c.`id`, f.`montant_TTC`
FROM `finance_versements` v
INNER JOIN `factures` f ON f.`id` = v.`source_facture_id`
INNER JOIN `paiements` p ON p.`id` = f.`paiement_id`
INNER JOIN `frais_scolaires` fs ON fs.`id` = p.`frais_scolaire_id`
INNER JOIN `finance_creances` c ON c.`eleve_id` = p.`eleve_id`
    AND c.`frais_scolaire_id` = p.`frais_scolaire_id`
    AND c.`annee_scolaire_id` = fs.`annee_scolaire_id`
    AND c.`periode_code` = 'ANNUEL';

INSERT IGNORE INTO `finance_affectations` (`versement_id`, `creance_id`, `montant_affecte`)
SELECT v.`id`, c.`id`, p.`montant_paye`
FROM `finance_versements` v
INNER JOIN `paiements` p ON p.`id` = v.`legacy_paiement_id`
INNER JOIN `frais_scolaires` fs ON fs.`id` = p.`frais_scolaire_id`
INNER JOIN `finance_creances` c ON c.`eleve_id` = p.`eleve_id`
    AND c.`frais_scolaire_id` = p.`frais_scolaire_id`
    AND c.`annee_scolaire_id` = fs.`annee_scolaire_id`
    AND c.`periode_code` = 'ANNUEL';

INSERT IGNORE INTO `finance_annulations`
    (`versement_id`, `numero_contre_ecriture`, `type`, `motif`, `annule_par`)
SELECT v.`id`, CONCAT('AVOIR-LEGACY-', f.`id`), 'annulation',
       'Reçu déjà marqué annulé avant la migration.', f.`emise_par`
FROM `finance_versements` v
INNER JOIN `factures` f ON f.`id` = v.`source_facture_id`
WHERE f.`statut_facture` = 'Annulée';

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261114_002_finance_ledger');
