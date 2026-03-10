<?php
/**
 * Run GCash/PayMongo migration on staywise1 database
 */
require_once dirname(__DIR__) . '/config/db.php';

echo "Connected to: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n\n";

// Helper to check if a column exists
function column_exists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Helper to check if an index exists
function index_exists($conn, $table, $index) {
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index'");
    return $result && $result->num_rows > 0;
}

// 1. Add payment_method column
if (!column_exists($conn, 'payments', 'payment_method')) {
    if ($conn->query("ALTER TABLE `payments` ADD COLUMN `payment_method` VARCHAR(50) DEFAULT 'manual' AFTER `amount`")) {
        echo "OK: Added payment_method column\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: payment_method already exists\n";
}

// 2. Add reference_no column
if (!column_exists($conn, 'payments', 'reference_no')) {
    if ($conn->query("ALTER TABLE `payments` ADD COLUMN `reference_no` VARCHAR(100) NULL AFTER `payment_method`")) {
        echo "OK: Added reference_no column\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: reference_no already exists\n";
}

// 3. Add paymongo_payment_id column
if (!column_exists($conn, 'payments', 'paymongo_payment_id')) {
    if ($conn->query("ALTER TABLE `payments` ADD COLUMN `paymongo_payment_id` VARCHAR(100) NULL AFTER `reference_no`")) {
        echo "OK: Added paymongo_payment_id column\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: paymongo_payment_id already exists\n";
}

// 4. Add paymongo_checkout_id column
if (!column_exists($conn, 'payments', 'paymongo_checkout_id')) {
    if ($conn->query("ALTER TABLE `payments` ADD COLUMN `paymongo_checkout_id` VARCHAR(100) NULL AFTER `paymongo_payment_id`")) {
        echo "OK: Added paymongo_checkout_id column\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: paymongo_checkout_id already exists\n";
}

// 5. Add indexes
if (!index_exists($conn, 'payments', 'idx_payments_paymongo')) {
    if ($conn->query("ALTER TABLE `payments` ADD INDEX `idx_payments_paymongo` (`paymongo_payment_id`)")) {
        echo "OK: Added idx_payments_paymongo index\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: idx_payments_paymongo already exists\n";
}

if (!index_exists($conn, 'payments', 'idx_payments_checkout')) {
    if ($conn->query("ALTER TABLE `payments` ADD INDEX `idx_payments_checkout` (`paymongo_checkout_id`)")) {
        echo "OK: Added idx_payments_checkout index\n";
    } else {
        echo "ERR: " . $conn->error . "\n";
    }
} else {
    echo "SKIP: idx_payments_checkout already exists\n";
}

// 6. Create app_settings table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS `app_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "OK: app_settings table ensured\n";

// 7. Insert default GCash/PayMongo settings
$defaults = [
    'gcash_enabled' => '1',
    'gcash_number' => '09XXXXXXXXX',
    'gcash_name' => 'Property Owner Name',
    'gcash_qr_image' => '',
    'paymongo_enabled' => '0',
    'paymongo_secret_key' => '',
    'paymongo_public_key' => '',
    'paymongo_webhook_secret' => '',
];

foreach ($defaults as $key => $value) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    echo ($affected > 0 ? "OK: Inserted " : "SKIP: Already exists - ") . "$key\n";
}

echo "\n=== Migration complete! ===\n";
$conn->close();
