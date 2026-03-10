<?php
$conn = new mysqli('localhost', 'root', '', 'staywise1');
if ($conn->connect_error) die('Connection failed: ' . $conn->connect_error);

$queries = [
    'ALTER TABLE tenants ADD COLUMN deposit_amount DECIMAL(10,2) NULL DEFAULT NULL',
    'ALTER TABLE tenants ADD COLUMN advance_amount DECIMAL(10,2) NULL DEFAULT NULL',
    'ALTER TABLE tenants ADD COLUMN deposit_paid TINYINT(1) NOT NULL DEFAULT 0',
    'ALTER TABLE tenants ADD COLUMN advance_paid TINYINT(1) NOT NULL DEFAULT 0',
    'ALTER TABLE tenants ADD COLUMN lease_start_date DATE NULL DEFAULT NULL',
    "ALTER TABLE payments ADD COLUMN payment_type VARCHAR(30) NULL DEFAULT 'rent'",
];

foreach ($queries as $q) {
    if (!$conn->query($q)) {
        echo 'Note: ' . $conn->error . PHP_EOL;
    } else {
        echo 'OK: ' . substr($q, 0, 60) . PHP_EOL;
    }
}

// Check for_month column
$r = $conn->query("SHOW COLUMNS FROM payments LIKE 'for_month'");
if ($r && $r->num_rows == 0) {
    $conn->query('ALTER TABLE payments ADD COLUMN for_month VARCHAR(7) NULL AFTER payment_date');
    echo 'Added for_month column' . PHP_EOL;
} else {
    echo 'for_month already exists' . PHP_EOL;
}

echo 'Migration complete!' . PHP_EOL;
$conn->close();
