<?php
// ⚠️ DIAGNOSTIC TOOL — DELETE THIS FILE AFTER USE
// Visit: https://yourdomain.com/db_test.php

$servername = "sql100.infinityfree.com";
$username   = "if0_41312414";
$password   = "VY951lItwKHk";
$dbname     = "if0_41312414_staywise";

mysqli_report(MYSQLI_REPORT_OFF);

echo "<!DOCTYPE html><html><head><title>DB Test</title>
<style>body{font-family:monospace;padding:2rem;background:#0f172a;color:#e2e8f0;}
.ok{color:#4ade80;} .fail{color:#f87171;} .info{color:#60a5fa;}
h2{margin-top:1.5rem;} code{background:#1e293b;padding:2px 6px;border-radius:4px;}
</style></head><body>";

echo "<h1>StayWise — Database Connection Test</h1>";

// 1. Test connection
echo "<h2>1. Connecting to MySQL...</h2>";
$conn = new mysqli($servername, $username, $password);
if ($conn->connect_error) {
    echo "<p class='fail'>❌ Connection FAILED (Error {$conn->connect_errno}): " . htmlspecialchars($conn->connect_error) . "</p>";
    echo "<p>Check your host, username, and password.</p>";
    die("</body></html>");
}
echo "<p class='ok'>✅ Connected to MySQL server at <code>$servername</code></p>";

// 2. Test database selection
echo "<h2>2. Selecting database <code>$dbname</code>...</h2>";
if (!$conn->select_db($dbname)) {
    echo "<p class='fail'>❌ Database NOT FOUND: <code>$dbname</code></p>";
    echo "<p>Available databases on this user:</p><ul>";
    $res = $conn->query("SHOW DATABASES");
    while ($row = $res->fetch_row()) {
        echo "<li><code class='info'>{$row[0]}</code></li>";
    }
    echo "</ul>";
    echo "<p>👉 Update <code>config/db.php</code> with the correct database name from the list above.</p>";
    die("</body></html>");
}
echo "<p class='ok'>✅ Database <code>$dbname</code> selected successfully</p>";

// 3. Check tables
echo "<h2>3. Checking tables...</h2>";
$conn->set_charset("utf8");
$res = $conn->query("SHOW TABLES");
if ($res->num_rows === 0) {
    echo "<p class='fail'>❌ No tables found — database is empty!</p>";
    echo "<p>You need to import your SQL schema via phpMyAdmin.</p>";
} else {
    echo "<p class='ok'>✅ Found {$res->num_rows} table(s):</p><ul>";
    while ($row = $res->fetch_row()) {
        echo "<li><code>{$row[0]}</code></li>";
    }
    echo "</ul>";
}

// 4. Check users table
echo "<h2>4. Checking users table...</h2>";
$res = $conn->query("SELECT COUNT(*) as cnt FROM users");
if (!$res) {
    echo "<p class='fail'>❌ 'users' table missing or error: " . htmlspecialchars($conn->error) . "</p>";
} else {
    $row = $res->fetch_assoc();
    echo "<p class='ok'>✅ users table exists — {$row['cnt']} user(s) found</p>";
    if ($row['cnt'] == 0) {
        echo "<p class='fail'>⚠️ No users in database — you need to create an admin account first.</p>";
    }
}

// 5. URL & SSL Info
echo "<h2>5. Your Site URL & SSL</h2>";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'unknown';
$fullUrl  = $protocol . '://' . $host;
echo "<p class='info'>Your correct site URL is: <a href='$fullUrl' style='color:#60a5fa'>$fullUrl</a></p>";
echo "<p class='info'>Protocol detected: <code>$protocol</code></p>";
echo "<p class='info'>Host: <code>$host</code></p>";
if ($protocol === 'http') {
    echo "<p class='ok'>✅ Use <strong>http://</strong> — this host does not have a valid SSL cert for your domain.<br>Bookmark: <a href='$fullUrl' style='color:#4ade80'>$fullUrl</a></p>";
} else {
    echo "<p class='ok'>✅ HTTPS is working on this host.</p>";
}

// 6. PHP info
echo "<h2>6. PHP Info</h2>";
echo "<p class='info'>PHP Version: " . phpversion() . "</p>";
echo "<p class='info'>MySQLi: " . (extension_loaded('mysqli') ? '✅ enabled' : '❌ missing') . "</p>";
echo "<p class='info'>Session: " . (extension_loaded('session') ? '✅ enabled' : '❌ missing') . "</p>";

echo "<hr><p style='color:#64748b'>⚠️ Delete <code>db_test.php</code> from your server after diagnosing.</p>";
echo "</body></html>";
?>
