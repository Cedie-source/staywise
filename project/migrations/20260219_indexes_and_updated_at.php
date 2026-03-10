<?php
/**
 * Migration: Add missing indexes and updated_at timestamps
 * Run once to improve database performance and track record modifications.
 */
require_once __DIR__ . '/../../config/db.php';

$statements = [
    // --- Indexes ---
    "ALTER TABLE payments ADD INDEX idx_payments_tenant_id (tenant_id)" ,
    "ALTER TABLE payments ADD INDEX idx_payments_status (status)",
    "ALTER TABLE payments ADD INDEX idx_payments_payment_date (payment_date)",
    "ALTER TABLE complaints ADD INDEX idx_complaints_tenant_id (tenant_id)",
    "ALTER TABLE complaints ADD INDEX idx_complaints_status (status)",
    "ALTER TABLE tenants ADD INDEX idx_tenants_user_id (user_id)",
    "ALTER TABLE tenants ADD INDEX idx_tenants_unit_number (unit_number)",

    // --- updated_at columns ---
    "ALTER TABLE tenants ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE complaints ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE announcements ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
    "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP",
];

$ok = 0;
$skip = 0;
foreach ($statements as $sql) {
    try {
        $conn->query($sql);
        echo "[OK]   $sql\n";
        $ok++;
    } catch (Throwable $e) {
        // Likely duplicate index or column already exists
        echo "[SKIP] $sql  --  " . $e->getMessage() . "\n";
        $skip++;
    }
}

echo "\nDone: $ok applied, $skip skipped (already exist).\n";
