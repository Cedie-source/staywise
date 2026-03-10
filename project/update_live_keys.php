<?php
require_once dirname(__DIR__) . '/config/db.php';

$updates = [
    'paymongo_secret_key'      => 'sk_live_FPKkjxzLHHJmZ4geQ5hhdW2d',
    'paymongo_public_key'      => 'pk_live_AykY9pPbiiRNZjGNttaHJhGB',
    'paymongo_webhook_secret'  => 'whsk_SpKmCBpGE2ujszA4nomyYbri',
    'paymongo_enabled'         => '1',
];

foreach ($updates as $key => $val) {
    $stmt = $conn->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
    $stmt->bind_param('sss', $key, $val, $val);
    $stmt->execute();
    echo "$key => $val\n";
}
echo "\nDone - live PayMongo keys saved to app_settings.\n";
