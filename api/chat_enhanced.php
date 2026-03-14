<?php
/**
 * Enhanced Chat API with Groq + Proactive AI
 *
 * Context-aware chatbot that integrates:
 * - Tenant-specific data (payments, complaints, unit info)
 * - Predictive maintenance insights
 * - Proactive notifications and advisories
 * - Persistent conversation history
 */

session_start();

// Require authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Rate limiting: max 30 requests per minute per user
$rate_key = 'chat_rate_' . $_SESSION['user_id'];
if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = ['count' => 0, 'reset' => time() + 60];
}
if (time() > $_SESSION[$rate_key]['reset']) {
    $_SESSION[$rate_key] = ['count' => 0, 'reset' => time() + 60];
}
$_SESSION[$rate_key]['count']++;
if ($_SESSION[$rate_key]['count'] > 30) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
    exit();
}

header('Content-Type: application/json');

// Load configuration
$root = dirname(__DIR__); // Dynamically resolve project root from this file's location
define('STAYWISE_ROOT', $root);
@include $root . '/config/groq.php';
@include $root . '/config/dialogflow.php';
require_once $root . '/config/db.php';
require_once $root . '/includes/predictive_analytics.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

// Get input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

$message = trim($data['message'] ?? '');
$history = $data['history'] ?? [];

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit();
}

try {
    ai_ensure_tables($conn);
    $userId = (int)$_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'tenant';

    // ── Gather tenant/admin context for the LLM ──
    $contextBlock = '';
    $proactiveAlerts = [];
    $quickAction = null;

    if ($userRole === 'tenant') {
        $ctx = ai_get_tenant_context($conn, $userId);
        if (!empty($ctx)) {
            $contextBlock  = "\n\n--- TENANT CONTEXT (use this to give personalised answers) ---\n";
            $contextBlock .= "Tenant: {$ctx['tenant_name']}, Unit: {$ctx['unit_number']}\n";
            $contextBlock .= "Rent: ₱" . number_format($ctx['rent_amount'], 2) . ", "
                           . "Paid this month: ₱" . number_format($ctx['paid_this_month'], 2) . " ({$ctx['payment_status']})\n";

            if (!empty($ctx['recent_complaints'])) {
                $contextBlock .= "Recent complaints:\n";
                foreach (array_slice($ctx['recent_complaints'], 0, 3) as $c) {
                    $contextBlock .= "  - {$c['title']} ({$c['status']}, {$c['complaint_date']})\n";
                }
            }
            if (!empty($ctx['predictions'])) {
                $contextBlock .= "Active maintenance predictions for this unit:\n";
                foreach ($ctx['predictions'] as $p) {
                    $contextBlock .= "  - {$p['category']}: {$p['prediction_text']} (risk: {$p['risk_level']})\n";
                    $proactiveAlerts[] = [
                        'type' => 'prediction',
                        'category' => $p['category'],
                        'text' => $p['prediction_text'],
                        'risk' => $p['risk_level'],
                    ];
                }
            }
            if (!empty($ctx['notifications'])) {
                $contextBlock .= "Recent advisories:\n";
                foreach (array_slice($ctx['notifications'], 0, 3) as $n) {
                    $contextBlock .= "  - [{$n['type']}] {$n['title']}\n";
                }
            }
            $contextBlock .= "--- END CONTEXT ---\n";
        }
    } else {
        // Admin context: summary stats
        $summary = ai_get_admin_summary($conn);
        $s = $summary['stats'];
        $contextBlock  = "\n\n--- ADMIN CONTEXT ---\n";
        $contextBlock .= "Active predictions: {$s['active_predictions']}, High risk: {$s['high_risk']}\n";
        $contextBlock .= "Active insights: {$s['active_insights']}, Patterns detected: {$s['patterns_detected']}\n";
        if (!empty($summary['predictions'])) {
            $contextBlock .= "Top predictions:\n";
            foreach (array_slice($summary['predictions'], 0, 3) as $p) {
                $contextBlock .= "  - Unit {$p['unit_number']}: {$p['category']} ({$p['risk_level']}) - {$p['prediction_text']}\n";
            }
        }
        $contextBlock .= "--- END CONTEXT ---\n";
    }

    // ── Build messages for Groq ──
    $basePrompt = getenv('AI_SYSTEM_PROMPT') ?: 'You are StayWise, a helpful assistant for tenants.';

    // Enhance system prompt with strict grounding instructions
    $enhancedPrompt = $basePrompt . "\n\n"
        . "IMPORTANT RULES:\n"
        . "- You MUST only use the data provided in the TENANT CONTEXT block below. Never invent, assume, or guess any information.\n"
        . "- Only mention maintenance predictions if they are explicitly listed in the TENANT CONTEXT block under 'Active maintenance predictions'. If that section is absent or empty, there are NO predictions — do NOT mention any.\n"
        . "- Only mention complaints that are explicitly listed in the TENANT CONTEXT block. Do not invent complaints or maintenance issues.\n"
        . "- If a tenant asks about maintenance predictions and none exist in the context, tell them there are currently no active predictions for their unit.\n"
        . "- If they ask about payments, use only their actual payment data from the context.\n"
        . "- Do NOT suggest, imply, or hint at any maintenance issues that are not in the context data.\n"
        . "- For admins: only reference prediction and insight data explicitly provided in the context.\n"
        . "- Always remain helpful, concise (under 200 words), and professional.\n"
        . $contextBlock;

    $messages = [];
    $messages[] = ['role' => 'system', 'content' => $enhancedPrompt];

    // Load recent chat history from DB (persistent across sessions)
    $dbHistory = [];
    $hStmt = $conn->prepare(
        "SELECT role, message FROM chat_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $hStmt->bind_param('i', $userId);
    $hStmt->execute();
    $hRes = $hStmt->get_result();
    while ($h = $hRes->fetch_assoc()) {
        $dbHistory[] = ['role' => $h['role'], 'content' => $h['message']];
    }
    $hStmt->close();
    $dbHistory = array_reverse($dbHistory);

    // Use DB history if client-side history is empty
    $historyToUse = is_array($history) && count($history) > 0 ? $history : $dbHistory;

    // Add history (limit to last 10 messages)
    foreach (array_slice($historyToUse, -10) as $h) {
        if (is_array($h) && isset($h['role']) && isset($h['content'])) {
            if (in_array($h['role'], ['user', 'assistant'])) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
        }
    }

    // Add current message
    $messages[] = ['role' => 'user', 'content' => $message];

    // Get Groq credentials
    $apiKey = getenv('GROQ_API_KEY');
    $model = getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile';
    $apiUrl = getenv('GROQ_API_URL') ?: 'https://api.groq.com/openai/v1/chat/completions';

    if ($apiKey === 'YOUR_KEY_HERE' || !$apiKey) {
        throw new Exception('Groq API key not configured. Get free key at: https://console.groq.com');
    }

    // Build request for Groq (OpenAI-compatible format)
    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 1024,
    ]);

    // Call Groq API
    $ch = curl_init($apiUrl);
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
        throw new Exception('Groq API connection failed: ' . $curlErr);
    }

    if ($httpCode !== 200) {
        $errorBody = json_decode($response, true);
        $errorMsg = $errorBody['error']['message'] ?? ($errorBody['error'] ?? $response);
        throw new Exception('HTTP ' . $httpCode . ': ' . $errorMsg);
    }

    $json = json_decode($response, true);

    // Extract reply from Groq response
    if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response from Groq: ' . json_encode($json));
    }

    $reply = $json['choices'][0]['message']['content'];
    $reply = trim($reply);

    if (!$reply) {
        $reply = "I'm not sure how to answer that. Could you rephrase your question?";
    }

    // ── Persist conversation to chat_history ──
    $saveMsg = $conn->prepare("INSERT INTO chat_history (user_id, role, message) VALUES (?, 'user', ?)");
    $saveMsg->bind_param('is', $userId, $message);
    $saveMsg->execute();
    $saveMsg->close();

    $saveReply = $conn->prepare("INSERT INTO chat_history (user_id, role, message) VALUES (?, 'assistant', ?)");
    $saveReply->bind_param('is', $userId, $reply);
    $saveReply->execute();
    $saveReply->close();

    // ── Detect intent for quick actions ──
    $intent = null;
    $confidence = 0;

    $msgLower = strtolower($message);
    if (preg_match('/\b(pay|payment|rent|bill|due|balance)\b/', $msgLower)) {
        $intent = 'payment_inquiry';
        $confidence = 0.85;
        $quickAction = ['label' => 'View Payments', 'url' => '/tenant/payments.php'];
    } elseif (preg_match('/\b(complaint|maintenance|repair|fix|broken|leak|damage)\b/', $msgLower)) {
        $intent = 'maintenance_request';
        $confidence = 0.85;
        $quickAction = ['label' => 'Submit Complaint', 'url' => '/tenant/complaints.php'];
    } elseif (preg_match('/\b(predict|forecast|upcoming|prevention|preventive)\b/', $msgLower)) {
        $intent = 'prediction_inquiry';
        $confidence = 0.80;
        if ($userRole === 'admin') {
            $quickAction = ['label' => 'View Insights', 'url' => '/admin/ai_insights.php'];
        }
    }

    if (class_exists('DialogflowIntentRouter')) {
        $intentData = DialogflowIntentRouter::detectIntent($message);
        $intent = $intentData['intent'] ?? $intent;
        $confidence = $intentData['confidence'] ?? $confidence;
    }

    // ── Return response ──
    echo json_encode([
        'reply' => $reply,
        'intent' => $intent,
        'confidence' => $confidence,
        'quick_action' => $quickAction,
        'proactive_alerts' => $proactiveAlerts,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
    ]);
}
?>
