-- Migration: Add payment_method and paymongo columns to payments table
-- Date: 2026-03-05

-- Add payment_method column (manual_gcash, paymongo_gcash, paymongo_grab_pay, paymongo_card, cash, bank_transfer)
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) DEFAULT 'manual' AFTER `amount`;

-- Add PayMongo reference columns
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `reference_no` VARCHAR(100) NULL AFTER `payment_method`,
  ADD COLUMN IF NOT EXISTS `paymongo_payment_id` VARCHAR(100) NULL AFTER `reference_no`,
  ADD COLUMN IF NOT EXISTS `paymongo_checkout_id` VARCHAR(100) NULL AFTER `paymongo_payment_id`;

-- Add index for PayMongo lookups
ALTER TABLE `payments`
  ADD INDEX IF NOT EXISTS `idx_payments_paymongo` (`paymongo_payment_id`),
  ADD INDEX IF NOT EXISTS `idx_payments_checkout` (`paymongo_checkout_id`);

-- Add GCash settings to app_settings
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('gcash_enabled', '1'),
  ('gcash_number', '09XXXXXXXXX'),
  ('gcash_name', 'Property Owner Name'),
  ('gcash_qr_image', ''),
  ('paymongo_enabled', '0'),
  ('paymongo_secret_key', ''),
  ('paymongo_public_key', ''),
  ('paymongo_webhook_secret', '');
