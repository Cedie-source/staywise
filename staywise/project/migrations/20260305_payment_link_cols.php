<?php
/**
 * Migration: Add paid_at, transaction_id columns to payments table
 *            + fix status enum to include 'cancelled'
 *
 * Run once:  php project/migrations/20260305_payment_link_cols.php
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$queries = [
    // Add paid_at timestamp column
    "ALTER TABLE payments ADD COLUMN paid_at DATETIME NULL DEFAULT NULL AFTER status",

    // Add transaction_id for PayMongo reference
    "ALTER TABLE payments ADD COLUMN transaction_id VARCHAR(100) NULL DEFAULT NULL AFTER paid_at",

    // Expand status enum to include 'cancelled'
    "ALTER TABLE payments MODIFY COLUMN status ENUM('pending','verified','rejected','cancelled') NOT NULL DEFAULT 'pending'",

    // Index for payment link lookups
    "ALTER TABLE payments ADD INDEX idx_payments_transaction (transaction_id)",
];

echo "Running payment link migration...\n";
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        echo "  OK: " . substr($sql, 0, 70) . "...\n";
    } else {
        $err = $conn->error;
        if (stripos($err, 'Duplicate column') !== false || stripos($err, 'Duplicate key') !== false) {
            echo "  SKIP (already exists): " . substr($sql, 0, 70) . "...\n";
        } else {
            echo "  ERROR: $err\n";
        }
    }
}
echo "\nDone.\n";
