SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `notification_push_subscriptions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `utilisateur_id` INT NOT NULL,
    `endpoint_hash` CHAR(64) NOT NULL,
    `subscription_chiffree` MEDIUMTEXT NOT NULL,
    `statut` ENUM('actif','invalide') NOT NULL DEFAULT 'actif',
    `dernier_enregistrement` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `invalide_le` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_push_endpoint` (`endpoint_hash`),
    KEY `idx_notification_push_user_status` (`utilisateur_id`,`statut`),
    CONSTRAINT `fk_notification_push_user`
        FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_push_queue` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `notification_id` INT NOT NULL,
    `subscription_id` INT NOT NULL,
    `statut` ENUM('en_attente','en_cours','envoye','echec','invalide') NOT NULL DEFAULT 'en_attente',
    `tentatives` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `disponible_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `verrouille_le` DATETIME DEFAULT NULL,
    `jeton_verrou` CHAR(32) DEFAULT NULL,
    `envoye_le` DATETIME DEFAULT NULL,
    `derniere_erreur` VARCHAR(1000) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_notification_push_delivery` (`notification_id`,`subscription_id`),
    KEY `idx_notification_push_ready` (`statut`,`disponible_le`,`id`),
    KEY `idx_notification_push_lock` (`statut`,`verrouille_le`),
    CONSTRAINT `fk_notification_push_queue_notification`
        FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notification_push_queue_subscription`
        FOREIGN KEY (`subscription_id`) REFERENCES `notification_push_subscriptions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261116_004_push_subscriptions_and_queue');
