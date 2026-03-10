-- Migration: Add deposit, advance payment, and lease start date columns to tenants table
-- Also add for_month and payment_date columns to payments if missing
-- Run this in MySQL (phpMyAdmin or CLI). Safe to run multiple times.

-- Add deposit and advance payment tracking to tenants
ALTER TABLE `tenants`
    ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '3-month deposit amount',
    ADD COLUMN IF NOT EXISTS `advance_amount` DECIMAL(10,2) NULL DEFAULT NULL COMMENT '1-month advance payment amount',
    ADD COLUMN IF NOT EXISTS `deposit_paid` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether deposit has been confirmed',
    ADD COLUMN IF NOT EXISTS `advance_paid` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether advance has been confirmed',
    ADD COLUMN IF NOT EXISTS `lease_start_date` DATE NULL DEFAULT NULL COMMENT 'Lease/rental start date for calendar';

-- Add for_month column to payments if not present
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `for_month` VARCHAR(7) NULL COMMENT 'YYYY-MM billing month' AFTER `payment_date`,
    ADD COLUMN IF NOT EXISTS `payment_type` VARCHAR(30) NULL DEFAULT 'rent' COMMENT 'rent, deposit, advance' AFTER `for_month`;
