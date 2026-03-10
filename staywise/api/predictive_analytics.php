<?php
/**
 * Predictive Analytics API
 *
 * POST /api/predictive_analytics.php  → Run analysis or get specific data
 * GET  /api/predictive_analytics.php  → Get summary data
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/config/db.php';
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';

ai_ensure_tables($conn);

$role = $_SESSION['role'] ?? 'tenant';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'summary';

    if ($action === 'summary' && $role === 'admin') {
        $summary = ai_get_admin_summary($conn);
        echo json_encode(['success' => true, 'data' => $summary]);
        exit();
    }

    if ($action === 'tenant_context') {
        $ctx = ai_get_tenant_context($conn, (int)$_SESSION['user_id']);
        echo json_encode(['success' => true, 'data' => $ctx]);
        exit();
    }

    if ($action === 'predictions' && $role === 'admin') {
        $status = $_GET['status'] ?? 'active';
        $stmt = $conn->prepare(
            "SELECT p.*, t.name AS tenant_name
             FROM ai_predictions p
             LEFT JOIN tenants t ON p.tenant_id = t.tenant_id
             WHERE p.status = ?
             ORDER BY FIELD(p.risk_level,'critical','high','medium','low'), p.predicted_date ASC"
        );
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $res = $stmt->get_result();
        $predictions = [];
        while ($row = $res->fetch_assoc()) $predictions[] = $row;
        $stmt->close();
        echo json_encode(['success' => true, 'predictions' => $predictions]);
        exit();
    }

    if ($action === 'patterns' && $role === 'admin') {
        $res = $conn->query("SELECT * FROM maintenance_patterns ORDER BY occurrence_count DESC");
        $patterns = [];
        while ($row = $res->fetch_assoc()) $patterns[] = $row;
        echo json_encode(['success' => true, 'patterns' => $patterns]);
        exit();
    }

    if ($action === 'insights' && $role === 'admin') {
        $res = $conn->query("SELECT * FROM ai_insights WHERE is_active = 1 ORDER BY severity DESC, created_at DESC");
        $insights = [];
        while ($row = $res->fetch_assoc()) $insights[] = $row;
        echo json_encode(['success' => true, 'insights' => $insights]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or insufficient permissions']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit();
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $action = $data['action'] ?? '';

    if ($action === 'run_analysis') {
        $conn->query("INSERT INTO ai_analysis_log (analysis_type, status) VALUES ('api_triggered','running')");
        $logId = $conn->insert_id;

        $patternResults = ai_detect_patterns($conn);
        $insightResults = ai_generate_insights($conn);
        $notifCount = ai_generate_notifications($conn);

        $totalRecords = $patternResults['patterns_found'] + $patternResults['predictions_created'] + $insightResults['insights_created'];
        $details = json_encode(['patterns' => $patternResults, 'insights' => $insightResults, 'notifications' => $notifCount]);
        $insightCount = $insightResults['insights_created'];

        $stmt = $conn->prepare(
            "UPDATE ai_analysis_log SET status='completed', records_processed=?, insights_generated=?, details=?, completed_at=NOW()
             WHERE log_id=?"
        );
        $stmt->bind_param('iisi', $totalRecords, $insightCount, $details, $logId);
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'success' => true,
            'patterns' => $patternResults,
            'insights' => $insightResults,
            'notifications' => $notifCount,
        ]);
        exit();
    }

    if ($action === 'update_prediction') {
        $pId = (int)($data['prediction_id'] ?? 0);
        $newStatus = $data['status'] ?? '';
        if ($pId > 0 && in_array($newStatus, ['acknowledged','resolved','dismissed'])) {
            $stmt = $conn->prepare("UPDATE ai_predictions SET status = ? WHERE prediction_id = ?");
            $stmt->bind_param('si', $newStatus, $pId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
        }
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
