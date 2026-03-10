<?php
// API endpoint: POST /api/chat.php
// Purpose: Proxy chat requests from authenticated users to Groq AI

declare(strict_types=1);

session_start();

// Require authentication (tenant or admin)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Load DB and Groq config
$root = dirname(__DIR__);
@require_once $root . '/config/db.php';
@require_once $root . '/config/groq.php';

header('Content-Type: application/json');

// Only accept JSON POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    http_response_code(415);
    echo json_encode(['error' => 'Unsupported Media Type; expected application/json']);
    exit();
}

// Read and validate input
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: 'null', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

$message = trim((string)($data['message'] ?? ''));
$history = $data['history'] ?? [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit();
}

if (mb_strlen($message) > 2000) {
    $message = mb_substr($message, 0, 2000);
}
if (is_array($history)) {
    $history = array_slice($history, -10);
} else {
    $history = [];
}

// Build messages array
$messages = [];

$systemPrompt = getenv('AI_SYSTEM_PROMPT') ?: 'You are StayWise AI Assistant, a helpful property management assistant for tenants. Answer clearly and concisely. If asked about personal data or account-specific info, remind the user you can only provide general guidance.';
$messages[] = ['role' => 'system', 'content' => $systemPrompt];

foreach ($history as $h) {
    if (!is_array($h)) continue;
    $role = $h['role'] ?? null;
    $content = $h['content'] ?? null;
    if (in_array($role, ['user', 'assistant', 'system'], true) && is_string($content) && $content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

$messages[] = ['role' => 'user', 'content' => $message];

// Send to Groq
try {
    $result = chat_with_groq($messages);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'AI request failed', 'details' => $e->getMessage()]);
}

function chat_with_groq(array $messages): array {
    $apiKey = getenv('GROQ_API_KEY') ?: null;
    if (!$apiKey) {
        throw new RuntimeException('Missing GROQ_API_KEY');
    }

    $model = getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile';
    $url   = getenv('GROQ_API_URL') ?: 'https://api.groq.com/openai/v1/chat/completions';

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.2,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($resp, true);
    if ($status >= 400) {
        $summary = is_array($json) ? json_encode($json) : (string)$resp;
        throw new RuntimeException('Groq API HTTP ' . $status . ': ' . $summary);
    }
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from Groq');
    }

    $reply = $json['choices'][0]['message']['content'] ?? null;
    if (!is_string($reply)) {
        throw new RuntimeException('Unexpected Groq response structure');
    }

    return [
        'provider' => 'groq',
        'model'    => $json['model'] ?? $model,
        'reply'    => $reply,
        'usage'    => $json['usage'] ?? null,
    ];
}
