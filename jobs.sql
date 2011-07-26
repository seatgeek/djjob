/* If you want to create a table using a different table name, replace `jobs` with your table name */

CREATE TABLE `jobs` (
`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
`handler` TEXT NOT NULL,
`queue` VARCHAR(255) NOT NULL DEFAULT 'default',
`attempts` INT UNSIGNED NOT NULL DEFAULT 0,
`run_at` DATETIME NULL,
`locked_at` DATETIME NULL,
`locked_by` VARCHAR(255) NULL,
`failed_at` DATETIME NULL,
`error` TEXT NULL,
`created_at` DATETIME NOT NULL
) ENGINE = INNODB;
