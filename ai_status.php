<?php
/**
 * StayWise AI System Status Check
 * Run this in your browser: http://localhost/StayWise/health.php
 * (or use the existing health.php file)
 * 
 * This checks if Ollama is running and configured correctly
 */

echo "<!DOCTYPE html>
<html>
<head>
    <title>StayWise AI Status</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .check { margin: 10px 0; padding: 10px; border-radius: 5px; }
        .pass { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .fail { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        code { background: #f0f0f0; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🤖 StayWise AI System Status</h1>
";

require_once __DIR__ . '/config/ai.php';

// Check 1: Config file exists
echo "<div class='check pass'>";
echo "✓ <strong>Config file loaded</strong><br>";
echo "AI Provider: <code>" . (getenv('AI_PROVIDER') ?: 'ollama') . "</code>";
echo "</div>";

// Check 2: Ollama connectivity
echo "<div class='check' id='ollama-check'>";
echo "<strong>🔄 Checking Ollama connection...</strong>";
echo "</div>";

$base = getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434';
$model = getenv('OLLAMA_MODEL') ?: 'llama2';

$ch = curl_init($base . '/api/tags');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpcode === 200) {
    $models = json_decode($response, true)['models'] ?? [];
    $model_names = array_map(fn($m) => $m['name'], $models);
    
    echo "<script>
    document.getElementById('ollama-check').className = 'check pass';
    document.getElementById('ollama-check').innerHTML = '✓ <strong>Ollama is running</strong><br>URL: <code>$base</code><br>Models available: <code>" . implode(', ', $model_names) . "</code>';
    </script>";
    
    // Check if llama2 exists (handle version tags like llama2:latest)
    $model_found = false;
    foreach ($model_names as $available_model) {
        // Check if model name matches (ignore :tag suffix)
        if (strpos($available_model, $model) === 0 || strpos($available_model, str_replace(':', '', $model)) === 0) {
            $model_found = true;
            break;
        }
    }
    
    if ($model_found) {
        echo "<div class='check pass'>";
        echo "✓ <strong>Model available</strong><br>";
        echo "Model: <code>" . implode(', ', $model_names) . "</code>";
        echo "</div>";
    } else {
        echo "<div class='check fail'>";
        echo "✗ <strong>Model not found</strong><br>";
        echo "Required: <code>$model</code><br>";
        echo "Download with: <code>ollama pull $model</code>";
        echo "</div>";
    }
} else {
    echo "<script>
    document.getElementById('ollama-check').className = 'check fail';
    document.getElementById('ollama-check').innerHTML = '✗ <strong>Ollama is not running</strong><br>Error: " . ($error ?: "HTTP $httpcode") . "<br>Start with: <code>ollama serve</code>';
    </script>";
}

// Check 3: API files exist
$files = [
    '/api/chat.php' => 'Basic Chat API',
    '/api/chat_enhanced.php' => 'Enhanced Chat API (recommended)',
    '/config/dialogflow.php' => 'Dialogflow Intent Router',
];

echo "<div style='margin-top: 20px;'>";
echo "<h2>📁 Required Files</h2>";

foreach ($files as $path => $name) {
    $fullpath = __DIR__ . $path;
    if (file_exists($fullpath)) {
        echo "<div class='check pass'>";
        echo "✓ <strong>$name</strong><br>";
        echo "Location: <code>$path</code>";
        echo "</div>";
    } else {
        echo "<div class='check fail'>";
        echo "✗ <strong>$name (MISSING)</strong><br>";
        echo "Location: <code>$path</code>";
        echo "</div>";
    }
}

echo "</div>";

// Check 4: Test API
echo "<div style='margin-top: 20px;'>";
echo "<h2>🔌 API Test</h2>";

// Check if model is found (same logic as above)
$model_found = false;
if ($httpcode === 200) {
    $models = json_decode($response, true)['models'] ?? [];
    $model_names = array_map(fn($m) => $m['name'], $models);
    
    foreach ($model_names as $available_model) {
        if (strpos($available_model, $model) === 0 || strpos($available_model, str_replace(':', '', $model)) === 0) {
            $model_found = true;
            break;
        }
    }
}

if ($httpcode === 200 && $model_found) {
    echo "<div class='check info'>";
    echo "<strong>Ready to test?</strong><br>";
    echo "Log in as a tenant and visit: <strong>AI Assistant - StayWise Helper</strong>";
    echo "</div>";
    
    echo "<div style='margin-top: 10px;'>";
    echo "<h3>Quick Test (cURL)</h3>";
    echo "<code style='display: block; background: #f0f0f0; padding: 10px; margin: 10px 0;'>";
    echo "curl http://127.0.0.1:11434/api/chat -X POST \<br>";
    echo "&nbsp;&nbsp;-H 'Content-Type: application/json' \<br>";
    echo "&nbsp;&nbsp;-d '{<br>";
    echo "&nbsp;&nbsp;&nbsp;&nbsp;\"model\": \"$model\",<br>";
    echo "&nbsp;&nbsp;&nbsp;&nbsp;\"messages\": [{\"role\": \"user\", \"content\": \"Hello, what is 2+2?\"}],<br>";
    echo "&nbsp;&nbsp;&nbsp;&nbsp;\"stream\": false<br>";
    echo "&nbsp;&nbsp;}'<br>";
    echo "</code>";
    echo "</div>";
} else {
    echo "<div class='check fail'>";
    echo "❌ <strong>Cannot test API</strong><br>";
    echo "Reason: Ollama is not running or model not found<br>";
    echo "Start Ollama: <code>ollama serve</code>";
    echo "</div>";
}

echo "</div>";

// Summary
echo "<div style='margin-top: 30px; padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 5px;'>";
echo "<h2>📋 Summary</h2>";
echo "<p><strong>Status:</strong> " . ($httpcode === 200 && $model_found ? "✅ <strong>Ready to use!</strong>" : "❌ <strong>Setup incomplete</strong>") . "</p>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ol>";
if ($httpcode !== 200) {
    echo "<li>Start Ollama: <code>ollama serve</code></li>";
}
if ($httpcode === 200 && !$model_found) {
    echo "<li>Download model: <code>ollama pull $model</code></li>";
}
echo "<li>Log in to StayWise as a tenant</li>";
echo "<li>Go to: <strong>AI Assistant - StayWise Helper</strong></li>";
echo "<li>Start chatting with your free AI! 🤖</li>";
echo "</ol>";
echo "</div>";

echo "</body></html>";
?>
