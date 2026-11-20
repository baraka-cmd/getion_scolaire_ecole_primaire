SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_campaigns` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `created_by` INT DEFAULT NULL,
    `request_key` CHAR(32) NOT NULL,
    `type_destinataire` ENUM('tous','classe','parent') NOT NULL,
    `classe_id` INT DEFAULT NULL,
    `nb_destinataires` INT NOT NULL DEFAULT 0,
    `email_demande` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_campaign_request` (`created_by`,`request_key`),
    KEY `idx_notification_campaign_created` (`created_at`),
    CONSTRAINT `fk_notification_campaign_creator`
        FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_notification_campaign_class`
        FOREIGN KEY (`classe_id`) REFERENCES `classes` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `notifications`
    ADD COLUMN `campagne_id` INT DEFAULT NULL AFTER `utilisateur_id`,
    ADD KEY `idx_notifications_user_date` (`utilisateur_id`,`date_envoi`,`id`),
    ADD KEY `idx_notifications_campaign` (`campagne_id`),
    ADD CONSTRAINT `fk_notifications_campaign`
        FOREIGN KEY (`campagne_id`) REFERENCES `notification_campaigns` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS `notification_email_queue` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `notification_id` INT NOT NULL,
    `destinataire_email` VARCHAR(254) DEFAULT NULL,
    `statut` ENUM('en_attente','en_cours','envoye','echec','sans_email') NOT NULL DEFAULT 'en_attente',
    `tentatives` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `disponible_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `verrouille_le` DATETIME DEFAULT NULL,
    `jeton_verrou` CHAR(32) DEFAULT NULL,
    `envoye_le` DATETIME DEFAULT NULL,
    `derniere_erreur` VARCHAR(1000) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_email_item` (`notification_id`),
    KEY `idx_notification_email_ready` (`statut`,`disponible_le`,`id`),
    KEY `idx_notification_email_lock` (`statut`,`verrouille_le`),
    CONSTRAINT `fk_notification_email_item`
        FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261116_002_notification_campaigns_and_email_queue');
