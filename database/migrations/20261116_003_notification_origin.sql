SET NAMES utf8mb4;

ALTER TABLE `notifications`
    ADD COLUMN `origine` ENUM('automatique','historique') NOT NULL DEFAULT 'historique'
    AFTER `campagne_id`;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261116_003_notification_origin');
