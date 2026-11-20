SET NAMES utf8mb4;

ALTER TABLE `parametres_ecole`
    ADD COLUMN `taux_cdf_par_usd` DECIMAL(14,4) NOT NULL DEFAULT 2500.0000
    AFTER `devise`;

CREATE TABLE IF NOT EXISTS `app_migrations` (
    `version` VARCHAR(64) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_migrations` (`version`)
VALUES ('20261116_001_add_finance_exchange_rate');
