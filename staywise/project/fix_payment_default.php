<?php
require_once dirname(__DIR__) . '/config/db.php';

// Check payment_method column
$r = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
$row = $r->fetch_assoc();
echo "Type: " . $row['Type'] . "\n";
echo "Default: " . ($row['Default'] ?? 'NULL') . "\n";

// Update default to 'cash'
$conn->query("ALTER TABLE payments ALTER COLUMN payment_method SET DEFAULT 'cash'");
echo "Default updated to 'cash'\n";

// Verify
$r2 = $conn->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
$row2 = $r2->fetch_assoc();
echo "New Default: " . ($row2['Default'] ?? 'NULL') . "\n";
