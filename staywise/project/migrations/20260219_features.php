<?php
/**
 * Migration: Add for_month column to payments for billing period tracking.
 * Also add deleted_at to tenants/users for soft deletes.
 */
require_once __DIR__ . '/../../config/db.php';

$statements = [
    // Payment period tracking
    "ALTER TABLE payments ADD COLUMN for_month VARCHAR(7) NULL DEFAULT NULL COMMENT 'Billing period YYYY-MM' AFTER payment_date",
    "ALTER TABLE payments ADD INDEX idx_payments_for_month (for_month)",
    // Backfill existing payments: use payment_date month as for_month
    "UPDATE payments SET for_month = DATE_FORMAT(payment_date, '%Y-%m') WHERE for_month IS NULL",

    // Soft deletes
    "ALTER TABLE tenants ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE tenants ADD INDEX idx_tenants_deleted_at (deleted_at)",

    // Properties table
    "CREATE TABLE IF NOT EXISTS properties (
        property_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        address TEXT,
        total_units INT DEFAULT 0,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
    )",
];

$ok = 0;
$skip = 0;
foreach ($statements as $sql) {
    try {
        $conn->query($sql);
        echo "[OK]   " . substr($sql, 0, 80) . "...\n";
        $ok++;
    } catch (Throwable $e) {
        echo "[SKIP] " . substr($sql, 0, 80) . "...  --  " . $e->getMessage() . "\n";
        $skip++;
    }
}

echo "\nDone: $ok applied, $skip skipped.\n";
