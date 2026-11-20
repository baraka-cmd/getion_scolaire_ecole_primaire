CREATE TABLE IF NOT EXISTS `appels_presence` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `classe_id` INT(11) NOT NULL,
    `annee_scolaire_id` INT(11) NOT NULL,
    `date_appel` DATE NOT NULL,
    `statut` ENUM('valide', 'conge') NOT NULL,
    `motif_conge` VARCHAR(255) DEFAULT NULL,
    `traite_par` INT(11) DEFAULT NULL,
    `traite_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_appel_classe_date` (`classe_id`, `date_appel`),
    KEY `idx_appel_annee_classe_date` (`annee_scolaire_id`, `classe_id`, `date_appel`),
    CONSTRAINT `fk_appel_classe` FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_appel_annee` FOREIGN KEY (`annee_scolaire_id`) REFERENCES `annees_scolaires` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_appel_utilisateur` FOREIGN KEY (`traite_par`) REFERENCES `utilisateurs` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
