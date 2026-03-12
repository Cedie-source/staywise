<?php
// TEMPORARY DEBUG PAGE - DELETE AFTER USE
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SELECT recipient_email, subject, status, error_message, created_at FROM email_logs ORDER BY id DESC LIMIT 5");
echo "<pre>";
echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST')) . "\n";
echo "SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : getenv('SMTP_PORT')) . "\n";
echo "SMTP_USERNAME: " . (defined('SMTP_USERNAME') ? SMTP_USERNAME : getenv('SMTP_USERNAME')) . "\n";
echo "SMTP_PASSWORD set: " . ((defined('SMTP_PASSWORD') && SMTP_PASSWORD) ? 'YES (' . strlen(SMTP_PASSWORD) . ' chars)' : 'NO') . "\n";
echo "openssl loaded: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n\n";
echo "Last 5 email logs:\n";
if ($res) { while ($row = $res->fetch_assoc()) { print_r($row); } }
echo "</pre>";
