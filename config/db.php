<?php
// Set Philippine timezone
date_default_timezone_set('Asia/Manila');

// Database configuration
// NOTE: On InfinityFree, the DB name shown in vPanel is the FULL name e.g. if0_41312414_staywise
// Make sure this matches EXACTLY what appears in your vPanel > MySQL Databases
$servername = "sql100.infinityfree.com";
$username   = "if0_41312414";
$password   = "VY951lItwKHk";
$dbname     = "if0_41312414_staywise";

// Suppress mysqli warnings — handle them manually below
mysqli_report(MYSQLI_REPORT_OFF);

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // Show a friendly error instead of white screen
    $errCode = $conn->connect_errno;
    $errMsg  = $conn->connect_error;
    error_log("StayWise DB connect error ($errCode): $errMsg");
    die(
        "<!DOCTYPE html><html><head><title>StayWise — DB Error</title></head><body style='font-family:sans-serif;padding:2rem;'>" .
        "<h2 style='color:#c0392b'>Database Connection Failed</h2>" .
        "<p><strong>Error $errCode:</strong> " . htmlspecialchars($errMsg) . "</p>" .
        "<p>Please check your <code>config/db.php</code>:<ul>" .
        "<li>Host: <code>$servername</code></li>" .
        "<li>User: <code>$username</code></li>" .
        "<li>Database: <code>$dbname</code></li>" .
        "</ul>Make sure the database name exactly matches what is shown in your InfinityFree vPanel &rarr; MySQL Databases.</p>" .
        "</body></html>"
    );
}

// Set charset to utf8
$conn->set_charset("utf8");
?>