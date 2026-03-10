<?php
/**
 * Migration: High-Priority Features — February 20, 2026
 * 
 * Adds tables/columns for:
 *   1. Lease / Contract Management (leases table)
 *   2. Tenant ↔ Property linkage (property_id FK on tenants)
 *   3. Maintenance Work Orders (work_orders table)
 *   4. Late Fee Configuration (late_fees table + settings)
 *   5. Email Notification Log (email_logs table)
 *   6. Invoice / Receipt Records (invoices table)
 *
 * Run: php project/migrations/20260220_high_priority_features.php
 */
require_once __DIR__ . '/../../config/db.php';

$statements = [
    // ── 1. Tenant ↔ Property linkage ──────────────────────────────
    "ALTER TABLE tenants ADD COLUMN property_id INT NULL DEFAULT NULL AFTER unit_number",
    "ALTER TABLE tenants ADD INDEX idx_tenants_property (property_id)",

    // ── 2. Leases table ───────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS leases (
        lease_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        property_id INT NULL,
        unit_number VARCHAR(20) NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        rent_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        terms TEXT NULL COMMENT 'Contract terms / notes',
        document_file VARCHAR(255) NULL COMMENT 'Uploaded contract PDF',
        status ENUM('active','expired','terminated','pending_renewal') DEFAULT 'active',
        renewal_reminder_sent TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
        INDEX idx_leases_status (status),
        INDEX idx_leases_end_date (end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

    // ── 3. Work Orders table ──────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS work_orders (
        work_order_id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NULL COMMENT 'Linked complaint if any',
        tenant_id INT NOT NULL,
        property_id INT NULL,
        unit_number VARCHAR(20) NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
        status ENUM('open','assigned','in_progress','completed','cancelled') DEFAULT 'open',
        assigned_to VARCHAR(100) NULL COMMENT 'Staff / maintenance person name',
        estimated_completion DATE NULL,
        actual_completion DATE NULL,
        admin_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
        INDEX idx_wo_status (status),
        INDEX idx_wo_priority (priority)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

    // ── 4. Late Fees table ────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS late_fees (
        fee_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        for_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
        fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(255) NULL,
        status ENUM('pending','paid','waived') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
        INDEX idx_lf_tenant_month (tenant_id, for_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

    // Late fee settings in app_settings
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('late_fee_enabled', '1')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('late_fee_type', 'flat')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('late_fee_amount', '500')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('late_fee_percentage', '5')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('late_fee_grace_days', '5')",

    // ── 5. Email Notification Log ─────────────────────────────────
    "CREATE TABLE IF NOT EXISTS email_logs (
        email_id INT AUTO_INCREMENT PRIMARY KEY,
        recipient_email VARCHAR(255) NOT NULL,
        recipient_name VARCHAR(100) NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        type VARCHAR(50) NULL COMMENT 'payment_verified, complaint_update, rent_reminder, etc.',
        status ENUM('sent','failed','queued') DEFAULT 'queued',
        error_message TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_type (type),
        INDEX idx_email_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

    // Email settings
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('email_enabled', '1')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('email_from_name', 'StayWise')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('email_from_address', 'noreply@staywise.local')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('smtp_host', 'localhost')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('smtp_port', '587')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('smtp_username', '')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('smtp_password', '')",
    "INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('smtp_encryption', 'tls')",

    // ── 6. Invoices table ─────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        invoice_number VARCHAR(50) NOT NULL,
        for_month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
        rent_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        late_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
        due_date DATE NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
        UNIQUE INDEX idx_invoice_number (invoice_number),
        INDEX idx_invoice_tenant_month (tenant_id, for_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8",
];

echo "=== StayWise High-Priority Features Migration ===\n\n";

$ok = 0;
$skip = 0;
foreach ($statements as $sql) {
    try {
        $conn->query($sql);
        echo "[OK]   " . substr(trim($sql), 0, 80) . "...\n";
        $ok++;
    } catch (Throwable $e) {
        echo "[SKIP] " . substr(trim($sql), 0, 80) . "...  --  " . $e->getMessage() . "\n";
        $skip++;
    }
}

echo "\nDone: $ok applied, $skip skipped.\n";
