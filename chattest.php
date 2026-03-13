<?php
session_start();

// Fake session for testing
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'tenant';

header('Content-Type: application/json');

$root = "/app";

@include $root . '/config/groq.php';
@include $root . '/config/dialogflow.php';

try {
    require_once $root . '/config/db.php';
} catch (Throwable $e) {
    echo json_encode(['error' => 'db.php failed: ' . $e->getMessage()]);
    exit();
}

try {
    if (!defined('STAYWISE_ROOT')) define('STAYWISE_ROOT', $root);
    require_once $root . '/includes/predictive_analytics.php';
} catch (Throwable $e) {
    echo json_encode(['error' => 'predictive_analytics.php failed: ' . $e->getMessage()]);
    exit();
}

try {
    ai_ensure_tables($conn);
} catch (Throwable $e) {
    echo json_encode(['error' => 'ai_ensure_tables failed: ' . $e->getMessage()]);
    exit();
}

$apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? $_SERVER['GROQ_API_KEY'] ?? '');

if (!$apiKey) {
    echo json_encode(['error' => 'No API key']);
    exit();
}

$payload = json_encode([
    'model' => getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user', 'content' => 'Say hello']
    ],
    'max_tokens' => 50,
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
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if (!$response) {
    echo json_encode(['error' => 'curl failed: ' . $curlErr]);
    exit();
}

if ($httpCode !== 200) {
    echo json_encode(['error' => 'HTTP ' . $httpCode . ': ' . $response]);
    exit();
}

$json = json_decode($response, true);
$reply = $json['choices'][0]['message']['content'] ?? 'no reply';

echo json_encode(['success' => true, 'reply' => $reply]);
?>
