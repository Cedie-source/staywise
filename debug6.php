<?php
session_start();
echo "<h2>Debug 6</h2>";

$root = "/app";
echo "Root: $root<br>";

// Test includes
echo "<h3>Includes</h3>";
echo "groq.php: " . (file_exists($root . '/config/groq.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "dialogflow.php: " . (file_exists($root . '/config/dialogflow.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "db.php: " . (file_exists($root . '/config/db.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "predictive_analytics.php: " . (file_exists($root . '/includes/predictive_analytics.php') ? '✅ exists' : '❌ missing') . "<br>";

// Test loading everything
echo "<h3>Loading Files</h3>";
@include $root . '/config/groq.php';
@include $root . '/config/dialogflow.php';

echo "GROQ_API_KEY: " . (getenv('GROQ_API_KEY') ? '✅ SET' : '❌ NOT SET') . "<br>";

try {
    require_once $root . '/config/db.php';
    echo "db.php: ✅ loaded<br>";
} catch (Throwable $e) {
    echo "db.php: ❌ " . $e->getMessage() . "<br>";
}

try {
    if (!defined('STAYWISE_ROOT')) define('STAYWISE_ROOT', $root);
    require_once $root . '/includes/predictive_analytics.php';
    echo "predictive_analytics.php: ✅ loaded<br>";
} catch (Throwable $e) {
    echo "predictive_analytics.php: ❌ " . $e->getMessage() . "<br>";
}

// Test DB
echo "<h3>DB</h3>";
if (isset($conn) && !$conn->connect_error) {
    echo "✅ DB Connected!<br>";
    // Test ai_ensure_tables
    try {
        ai_ensure_tables($conn);
        echo "ai_ensure_tables: ✅<br>";
    } catch (Throwable $e) {
        echo "ai_ensure_tables: ❌ " . $e->getMessage() . "<br>";
    }
} else {
    echo "❌ DB not connected<br>";
}

echo "<br>Done!";
?>
