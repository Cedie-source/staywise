<?php
/**
 * AI Notifications API
 *
 * GET  /api/notifications.php          → Fetch notifications for current user
 * POST /api/notifications.php          → Mark notification(s) as read
 * GET  /api/notifications.php?count=1  → Get unread count only
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

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Count-only mode
    if (isset($_GET['count'])) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ai_notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        echo json_encode(['unread' => (int)$row['cnt']]);
        exit();
    }

    // Fetch notifications (paginated)
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $filter = $_GET['filter'] ?? 'all'; // all, unread, prediction, maintenance, payment, advisory, trend

    $where = "WHERE user_id = ?";
    $params = [$userId];
    $types = 'i';

    if ($filter === 'unread') {
        $where .= " AND is_read = 0";
    } elseif (in_array($filter, ['prediction','maintenance','payment','advisory','trend'])) {
        $where .= " AND type = ?";
        $params[] = $filter;
        $types .= 's';
    }

    // Total count
    $countStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ai_notifications $where");
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
    $countStmt->close();

    // Fetch items
    $sql = "SELECT * FROM ai_notifications $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $notifications = [];
    while ($row = $res->fetch_assoc()) {
        $row['time_ago'] = ai_time_ago($row['created_at']);
        $notifications[] = $row;
    }
    $stmt->close();

    // Unread count
    $unreadStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM ai_notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->bind_param('i', $userId);
    $unreadStmt->execute();
    $unread = (int)$unreadStmt->get_result()->fetch_assoc()['cnt'];
    $unreadStmt->close();

    echo json_encode([
        'notifications' => $notifications,
        'total' => $total,
        'unread' => $unread,
        'page' => $page,
        'pages' => ceil($total / $limit),
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit();
    }

    $action = $data['action'] ?? 'mark_read';

    if ($action === 'mark_read') {
        $notifId = (int)($data['notification_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $conn->prepare("UPDATE ai_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $notifId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        exit();
    }

    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE ai_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        echo json_encode(['success' => true, 'marked' => $affected]);
        exit();
    }

    if ($action === 'dismiss') {
        $notifId = (int)($data['notification_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $conn->prepare("DELETE FROM ai_notifications WHERE notification_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $notifId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

/* ---- Helper ---- */
function ai_time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', strtotime($datetime));
}
