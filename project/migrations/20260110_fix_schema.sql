-- StayWise schema repair / alignment (MySQL/MariaDB)
-- Safe to run multiple times; uses IF NOT EXISTS where supported

-- USERS
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','tenant') NOT NULL DEFAULT 'tenant',
  `full_name` VARCHAR(100) NULL,
  `email` VARCHAR(255) NULL,
  `force_password_change` TINYINT(1) NOT NULL DEFAULT 0,
  `password_changed_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `reset_token` VARCHAR(255) NULL,
  `reset_expires` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(100) NULL AFTER `role`,
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL AFTER `full_name`,
  ADD COLUMN IF NOT EXISTS `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`,
  ADD COLUMN IF NOT EXISTS `password_changed_at` DATETIME NULL AFTER `force_password_change`,
  ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `password_changed_at`,
  ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(255) NULL AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `reset_expires` DATETIME NULL AFTER `reset_token`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `reset_expires`,
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

-- TENANTS
CREATE TABLE IF NOT EXISTS `tenants` (
  `tenant_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(100) NULL,
  `unit_number` VARCHAR(50) NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `due_day` TINYINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tenants_user_id` (`user_id`),
  CONSTRAINT `fk_tenants_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `tenants`
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(100) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `unit_number` VARCHAR(50) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `unit_number`,
  ADD COLUMN IF NOT EXISTS `due_day` TINYINT NULL AFTER `must_change_password`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `due_day`;

-- ANNOUNCEMENTS
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `announcement_date` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pinned` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `announcements`
  ADD COLUMN IF NOT EXISTS `announcement_date` DATE NOT NULL AFTER `content`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `announcement_date`,
  ADD COLUMN IF NOT EXISTS `pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `created_at`;

CREATE INDEX IF NOT EXISTS `idx_announcements_created_at` ON `announcements`(`created_at`);
CREATE INDEX IF NOT EXISTS `idx_announcements_date` ON `announcements`(`announcement_date`);

-- ADMIN LOGS
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_admin_logs_created_at` (`created_at`),
  CONSTRAINT `fk_admin_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `admin_logs`
  ADD COLUMN IF NOT EXISTS `details` TEXT NULL AFTER `action`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `details`,
  ADD KEY IF NOT EXISTS `idx_admin_logs_created_at` (`created_at`);

-- PAYMENTS
CREATE TABLE IF NOT EXISTS `payments` (
  `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `method` VARCHAR(50) NULL,
  `reference_no` VARCHAR(100) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `proof_path` VARCHAR(255) NULL,
  `bill_month` TINYINT NULL,
  `bill_year` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` DATETIME NULL,
  KEY `idx_payments_created_at` (`created_at`),
  KEY `idx_payments_status` (`status`),
  CONSTRAINT `fk_payments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `method` VARCHAR(50) NULL AFTER `amount`,
  ADD COLUMN IF NOT EXISTS `reference_no` VARCHAR(100) NULL AFTER `method`,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `reference_no`,
  ADD COLUMN IF NOT EXISTS `proof_path` VARCHAR(255) NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `bill_month` TINYINT NULL AFTER `proof_path`,
  ADD COLUMN IF NOT EXISTS `bill_year` INT NULL AFTER `bill_month`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `bill_year`,
  ADD COLUMN IF NOT EXISTS `verified_at` DATETIME NULL AFTER `created_at`,
  ADD KEY IF NOT EXISTS `idx_payments_created_at` (`created_at`),
  ADD KEY IF NOT EXISTS `idx_payments_status` (`status`);

-- COMPLAINTS
CREATE TABLE IF NOT EXISTS `complaints` (
  `complaint_id` INT AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `urgent` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  KEY `idx_complaints_created_at` (`created_at`),
  KEY `idx_complaints_status` (`status`),
  CONSTRAINT `fk_complaints_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `complaints`
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `title`,
  ADD COLUMN IF NOT EXISTS `status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `description`,
  ADD COLUMN IF NOT EXISTS `urgent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `urgent`,
  ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `resolved_at` DATETIME NULL AFTER `updated_at`,
  ADD KEY IF NOT EXISTS `idx_complaints_created_at` (`created_at`),
  ADD KEY IF NOT EXISTS `idx_complaints_status` (`status`);

-- OPTIONAL: seed default admin if none exists (password will be upgraded on first login)
INSERT INTO `users` (`username`, `password`, `role`, `is_active`, `force_password_change`)
SELECT 'admin', 'admin123', 'admin', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `role` = 'admin');
