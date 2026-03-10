<?php
require_once dirname(__DIR__) . '/config/db.php';
$r = $conn->query('SHOW COLUMNS FROM tenants');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . ($row['Default'] ?? 'NULL') . "\n";
}
echo "\n--- payments table ---\n";
$r2 = $conn->query('SHOW COLUMNS FROM payments');
while ($row = $r2->fetch_assoc()) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . ($row['Default'] ?? 'NULL') . "\n";
}
