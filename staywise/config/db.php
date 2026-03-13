<?php
// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Railway MySQL credentials from environment variables
$servername = getenv('MYSQLHOST')     ?: 'mysql.railway.internal';
$username   = getenv('MYSQLUSER')     ?: 'root';
$password   = getenv('MYSQLPASSWORD') ?: '';
$dbname     = getenv('MYSQLDATABASE') ?: 'railway';
$port       = (int)(getenv('MYSQLPORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    $errCode = $conn->connect_errno;
    $errMsg  = $conn->connect_error;
    error_log("StayWise DB connect error ($errCode): $errMsg");
    die(
        "<!DOCTYPE html><html><head><title>StayWise — DB Error</title></head><body style='font-family:sans-serif;padding:2rem;'>" .
        "<h2 style='color:#c0392b'>Database Connection Failed</h2>" .
        "<p><strong>Error $errCode:</strong> " . htmlspecialchars($errMsg) . "</p>" .
        "<p>Host: <code>$servername</code> | DB: <code>$dbname</code></p>" .
        "</body></html>"
    );
}

$conn->set_charset("utf8");
?>
