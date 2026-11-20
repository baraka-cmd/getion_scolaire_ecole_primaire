-- Limitation des requêtes de l'assistant par utilisateur et par fenêtre.
-- Migration idempotente et sans modification des tables métier.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `assistant_rate_limits` (
    `utilisateur_id` INT(11) NOT NULL,
    `window_started_at` DATETIME NOT NULL,
    `request_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`utilisateur_id`),
    CONSTRAINT `fk_assistant_rate_limits_user`
        FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261110_002_add_assistant_rate_limits');
