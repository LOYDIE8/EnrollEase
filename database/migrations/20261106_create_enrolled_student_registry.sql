-- Migration: Create enrolled_student_registry table and default system setting
CREATE TABLE IF NOT EXISTS `enrolled_student_registry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_number` VARCHAR(12) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `program` VARCHAR(150) NOT NULL,
  `year_level` VARCHAR(40) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_registry_student_number` (`student_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES ('require_registry_match', 'false')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
