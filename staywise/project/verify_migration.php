<?php
require_once dirname(__DIR__) . '/config/db.php';

// Show which DB we're connected to
echo "Database: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n\n";

echo "=== PAYMENTS TABLE COLUMNS ===\n";
$r = $conn->query('SHOW COLUMNS FROM payments');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        echo $row['Field'] . ' (' . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
    // Try to list tables
    echo "\n=== AVAILABLE TABLES ===\n";
    $t = $conn->query('SHOW TABLES');
    while ($row = $t->fetch_row()) {
        echo $row[0] . "\n";
    }
}

echo "\n=== GCASH/PAYMONGO SETTINGS ===\n";
$r2 = $conn->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'gcash%' OR setting_key LIKE 'paymongo%'");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        echo $row['setting_key'] . ' = ' . $row['setting_value'] . "\n";
    }
} else {
    echo "Settings query failed: " . $conn->error . "\n";
}

$conn->close();
