-- Settings key/value table for app configuration
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_app_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed defaults if not present
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'site_name', 'StayWise'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'site_name');

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'base_url', '/StayWise/'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'base_url');

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'password_min_length', '8'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'password_min_length');

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'password_require_uppercase', '1'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'password_require_uppercase');

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'password_require_number', '1'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'password_require_number');

INSERT INTO `app_settings` (`setting_key`, `setting_value`)
SELECT 'password_require_special', '1'
WHERE NOT EXISTS (SELECT 1 FROM `app_settings` WHERE `setting_key` = 'password_require_special');
