<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/smtp.php';
echo "<pre>";
echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT SET') . "\n";
echo "SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT SET') . "\n";
echo "SMTP_USERNAME: " . (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'NOT SET') . "\n";
echo "SMTP_PASSWORD length: " . (defined('SMTP_PASSWORD') ? strlen(SMTP_PASSWORD) : 'NOT SET') . "\n";
echo "openssl loaded: " . (extension_loaded('openssl') ? 'YES' : 'NO') . "\n";
echo "RAILPACK_PHP_EXTENSIONS env: " . getenv('RAILPACK_PHP_EXTENSIONS') . "\n\n";
$res = $conn->query("SELECT status, error_message, created_at FROM email_logs ORDER BY id DESC LIMIT 3");
echo "Recent email logs:\n";
if ($res) { while ($r = $res->fetch_assoc()) { echo $r['created_at'] . " | " . $r['status'] . " | " . $r['error_message'] . "\n"; } }
echo "</pre>";
