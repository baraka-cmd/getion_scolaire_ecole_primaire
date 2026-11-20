SET NAMES utf8mb4;

ALTER TABLE `parametres_ecole`
    ADD COLUMN `province` VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL
    AFTER `pays`;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261114_001_add_school_province');
