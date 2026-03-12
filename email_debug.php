<?php
// Test Brevo HTTP API
$apiKey = getenv('BREVO_API_KEY') ?: '';

echo "<pre>";
echo "Testing Brevo HTTP API...\n";
echo "API Key length: " . strlen($apiKey) . "\n\n";

$data = json_encode([
    'sender'     => ['name' => 'StayWise', 'email' => 'christianpisalbon24@gmail.com'],
    'to'         => [['email' => 'christianpisalbon24@gmail.com', 'name' => 'Test']],
    'subject'    => 'StayWise API Test',
    'htmlContent'=> '<p>If you get this, Brevo API works!</p>'
]);

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $data,
    CURLOPT_HTTPHEADER     => [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
if ($curlError) echo "Curl Error: $curlError\n";
echo "</pre>";
