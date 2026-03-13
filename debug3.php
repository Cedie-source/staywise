<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'tenant';

echo "<h2>Chat Enhanced Debug</h2>";

$root = dirname(__DIR__);
echo "Root: $root<br>";

// Test each include
echo "Testing groq.php: ";
$r = @include $root . '/config/groq.php';
echo ($r ? '✅' : '❌') . "<br>";

echo "Testing dialogflow.php: ";
$r = @include $root . '/config/dialogflow.php';
echo ($r ? '✅' : '❌') . "<br>";

echo "Testing db.php: ";
try {
    require_once $root . '/config/db.php';
    echo "✅<br>";
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "<br>";
}

echo "Testing predictive_analytics.php: ";
try {
    if (!defined('STAYWISE_ROOT')) define('STAYWISE_ROOT', $root);
    require_once $root . '/includes/predictive_analytics.php';
    echo "✅<br>";
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "<br>";
}

echo "Testing ai_ensure_tables: ";
try {
    ai_ensure_tables($conn);
    echo "✅<br>";
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "<br>";
}

echo "Testing ai_get_tenant_context: ";
try {
    $ctx = ai_get_tenant_context($conn, 1);
    echo "✅<br>";
} catch (Throwable $e) {
    echo "❌ " . $e->getMessage() . "<br>";
}

echo "<br>All done!";
?>
