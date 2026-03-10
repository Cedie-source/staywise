<?php
date_default_timezone_set('Asia/Manila');

$servername = getenv('MYSQLHOST') ?: 'localhost';
$username   = getenv('MYSQLUSER') ?: 'root';
$password   = getenv('MYSQLPASSWORD') ?: '';
$dbname     = getenv('MYSQLDATABASE') ?: 'staywise';
$port       = getenv('MYSQLPORT') ?: 3306;

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    error_log("DB Error: " . $conn->connect_error);
    die("Database connection failed.");
}

$conn->set_charset("utf8");
?>
