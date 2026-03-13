<?php
session_start();
echo "<h2>StayWise Debug</h2>";

// 1. Check PHP extensions
echo "<h3>1. Extensions</h3>";
echo "curl: " . (extension_loaded('curl') ? '✅ YES' : '❌ NO') . "<br>";
echo "mysqli: " . (extension_loaded('mysqli') ? '✅ YES' : '❌ NO') . "<br>";
echo "openssl: " . (extension_loaded('openssl') ? '✅ YES' : '❌ NO') . "<br>";

// 2. Check environment variables
echo "<h3>2. Environment Variables</h3>";
echo "GROQ_API_KEY: " . (getenv('GROQ_API_KEY') ? '✅ SET → ' . substr(getenv('GROQ_API_KEY'), 0, 15) . '...' : '❌ NOT SET') . "<br>";
echo "GROQ_MODEL: " . (getenv('GROQ_MODEL') ?: '❌ NOT SET') . "<br>";
echo "GROQ_API_URL: " . (getenv('GROQ_API_URL') ?: '❌ NOT SET') . "<br>";

// 3. Check if groq.php file exists and loads
echo "<h3>3. Config File</h3>";
$root = dirname(__DIR__);
echo "Root path: $root<br>";
$groqFile = $root . '/config/groq.php';
echo "groq.php exists: " . (file_exists($groqFile) ? '✅ YES' : '❌ NO') . "<br>";
if (file_exists($groqFile)) {
    include $groqFile;
    echo "After loading groq.php, GROQ_API_KEY: " . (getenv('GROQ_API_KEY') ? '✅ SET → ' . substr(getenv('GROQ_API_KEY'), 0, 15) . '...' : '❌ STILL NOT SET') . "<br>";
}

// 4. Test actual Groq API call
echo "<h3>4. Groq API Test</h3>";
$apiKey = getenv('GROQ_API_KEY');
if (!$apiKey) {
    echo "❌ No API key - skipping test<br>";
} elseif (!extension_loaded('curl')) {
    echo "❌ curl not loaded - cannot make HTTP requests<br>";
} else {
    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [['role' => 'user', 'content' => 'Say hi']],
        'max_tokens' => 10,
    ]);
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    echo "HTTP Code: $httpCode<br>";
    if ($curlErr) echo "cURL Error: $curlErr<br>";
    $json = json_decode($response, true);
    if ($httpCode === 200 && isset($json['choices'][0]['message']['content'])) {
        echo "✅ Groq works! Response: " . $json['choices'][0]['message']['content'] . "<br>";
    } else {
        echo "❌ Error: " . htmlspecialchars($response) . "<br>";
    }
}
?>
