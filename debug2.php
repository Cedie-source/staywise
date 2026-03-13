<?php
session_start();
echo "<h2>Debug 2</h2>";

$apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? $_SERVER['GROQ_API_KEY'] ?? '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');

echo "API Key being used: " . substr($apiKey, 0, 20) . "...<br>";
echo "Full key length: " . strlen($apiKey) . "<br>";

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
echo "Full Response: " . htmlspecialchars($response) . "<br>";

$json = json_decode($response, true);
if ($httpCode === 200 && isset($json['choices'][0]['message']['content'])) {
    echo "<br>✅ WORKS: " . $json['choices'][0]['message']['content'];
} else {
    echo "<br>❌ FAILED";
}
?>
