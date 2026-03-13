<?php
session_start();
echo "<h2>Debug 5</h2>";

$root = "/app/staywise";
echo "Root: $root<br>";

// Test includes
echo "<h3>Includes</h3>";
echo "groq.php: " . (file_exists($root . '/config/groq.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "dialogflow.php: " . (file_exists($root . '/config/dialogflow.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "db.php: " . (file_exists($root . '/config/db.php') ? '✅ exists' : '❌ missing') . "<br>";
echo "predictive_analytics.php: " . (file_exists($root . '/includes/predictive_analytics.php') ? '✅ exists' : '❌ missing') . "<br>";

// List actual files on server
echo "<h3>What's actually in /app/</h3>";
$files = scandir('/app/');
foreach ($files as $f) echo "$f<br>";

echo "<h3>What's actually in /app/staywise/ (if exists)</h3>";
if (is_dir('/app/staywise/')) {
    $files = scandir('/app/staywise/');
    foreach ($files as $f) echo "$f<br>";
} else {
    echo "❌ /app/staywise/ does NOT exist!<br>";
}
?>
