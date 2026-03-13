<?php
date_default_timezone_set('Asia/Manila');

$servername = getenv('MYSQLHOST')     ?: 'mysql.railway.internal';
$username   = getenv('MYSQLUSER')     ?: 'root';
$password   = getenv('MYSQLPASSWORD') ?: '';
$dbname     = getenv('MYSQLDATABASE') ?: 'railway';
$port       = (int)(getenv('MYSQLPORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    error_log("StayWise DB connect error: " . $conn->connect_error);
    die("Database connection failed.");
}

$conn->set_charset("utf8");
?>
