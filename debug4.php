<?php
session_start();
echo "<h2>Debug 4</h2>";

$root = __DIR__ . "/..";
echo "Root: $root<br>";

// Test DB
echo "<h3>DB Connection</h3>";
$servername = getenv('MYSQLHOST')     ?: 'mysql.railway.internal';
$username   = getenv('MYSQLUSER')     ?: 'root';
$password   = getenv('MYSQLPASSWORD') ?: '';
$dbname     = getenv('MYSQLDATABASE') ?: 'railway';
$port       = (int)(getenv('MYSQLPORT') ?: 3306);

echo "Host: $servername<br>";
echo "DB: $dbname<br>";
echo "Port: $port<br>";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($servername, $username, $password, $dbname, $port);
if ($conn->connect_error) {
    echo "❌ DB Error: " . $conn->connect_error . "<br>";
} else {
    echo "✅ DB Connected!<br>";
}

// Test includes
echo "<h3>Includes</h3>";
echo "groq.php: " . (file_exists($root . '/config/groq.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "dialogflow.php: " . (file_exists($root . '/config/dialogflow.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "db.php: " . (file_exists($root . '/config/db.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "predictive_analytics.php: " . (file_exists($root . '/includes/predictive_analytics.php') ? '✅ exists' : '❌ missing') . "<br>";

// Test loading predictive_analytics
echo "<h3>Load predictive_analytics</h3>";
try {
    if (!defined('STAYWISE_ROOT')) define('STAYWISE_ROOT', $root);
    require_once $root . '/includes/predictive_analytics.php';
    echo "✅ Loaded!<br>";
    echo "ai_ensure_tables exists: " . (function_exists('ai_ensure_tables') ? '✅' : '❌') . "<br>";
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test ai_ensure_tables
echo "<h3>ai_ensure_tables</h3>";
try {
    ai_ensure_tables($conn);
    echo "✅ OK<br>";
} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<br>Done!";
?>
