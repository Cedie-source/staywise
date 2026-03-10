<?php
require_once dirname(__DIR__) . '/config/db.php';

$updates = [
    'paymongo_secret_key' => 'sk_live_FPKkjxzLHHJmZ4geQ5hhdW2d',
    'paymongo_public_key' => 'pk_live_AykY9pPbiiRNZjGNttaHJhGB',
    'paymongo_enabled'    => '1',
];

foreach ($updates as $key => $value) {
    $stmt = $conn->prepare("UPDATE app_settings SET setting_value = ? WHERE setting_key = ?");
    $stmt->bind_param("ss", $value, $key);
    $stmt->execute();
    echo "$key: " . ($stmt->affected_rows >= 0 ? "OK" : "FAILED") . "\n";
    $stmt->close();
}

// Verify
$res = $conn->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'paymongo%'");
echo "\nCurrent PayMongo settings:\n";
while ($row = $res->fetch_assoc()) {
    $display = (strpos($row['setting_key'], 'key') !== false || strpos($row['setting_key'], 'secret') !== false)
        ? substr($row['setting_value'], 0, 12) . '...'
        : $row['setting_value'];
    echo "  " . $row['setting_key'] . " = " . $display . "\n";
}

echo "\nPayMongo is now configured and enabled!\n";
