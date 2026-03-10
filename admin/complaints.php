<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
require_once '../includes/email_helper.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

// Handle status update or admin response submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complaint_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    $complaint_id = intval($_POST['complaint_id']);

    // Status + admin_response update (from edit modal)
    if (isset($_POST['update_complaint'])) {
        $status = $_POST['status'] ?? '';
        $admin_response = trim($_POST['admin_response'] ?? '');

        $stmt = $conn->prepare("UPDATE complaints SET status = ?, admin_response = ? WHERE complaint_id = ?");
        $stmt->bind_param("ssi", $status, $admin_response, $complaint_id);
        $stmt->execute();
        $stmt->close();

        logAdminAction($conn, $_SESSION['user_id'], 'update_complaint', "Updated complaint #$complaint_id status to $status");

        // Email notification removed to avoid slow page load

        header("Location: complaints.php");
        exit();
    }

    // Admin reply to complaint thread
    if (isset($_POST['send_reply'])) {
        $message = trim($_POST['reply_message'] ?? '');
        if (!empty($message)) {
            $stmt = $conn->prepare("INSERT INTO complaint_replies (complaint_id, user_id, role, message) VALUES (?, ?, 'admin', ?)");
            $stmt->bind_param("iis", $complaint_id, $_SESSION['user_id'], $message);
            $stmt->execute();
            $stmt->close();

            logAdminAction($conn, $_SESSION['user_id'], 'reply_complaint', "Replied to complaint #$complaint_id");
        }
        header("Location: complaints.php?reply=sent");
        exit();
    }
}

// Flash message from reply redirect
if (isset($_GET['reply']) && $_GET['reply'] === 'sent') {
    $success = "Reply sent successfully!";
}

$hasUrgent = db_column_exists($conn, 'complaints', 'urgent');

// Fetch complaints with tenant info
$sql = "SELECT c.complaint_id, c.complaint_date, c.title, c.description, 
           c.status, c.admin_response, c.created_at,
           t.name AS tenant_name, t.unit_number" .
       ($hasUrgent ? ", c.urgent AS urgent" : ", 0 AS urgent") .
       " FROM complaints c
    JOIN tenants t ON c.tenant_id = t.tenant_id
    WHERE t.deleted_at IS NULL
    ORDER BY c.complaint_date DESC";

$result = $conn->query($sql);
if (!$result) {
    die("Database query failed: " . $conn->error);
}

// Pre-fetch all replies grouped by complaint_id
$allReplies = [];
$repliesResult = $conn->query("
    SELECT cr.*, u.username, u.full_name 
    FROM complaint_replies cr 
    JOIN users u ON cr.user_id = u.id 
    ORDER BY cr.created_at ASC
");
if ($repliesResult) {
    while ($r = $repliesResult->fetch_assoc()) {
        $allReplies[$r['complaint_id']][] = $r;
    }
}

$page_title = "Manage Complaints";
include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title"><i class="fas fa-exclamation-triangle me-2"></i>Tenant Complaints</h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search bar -->
    <div class="mb-3">
        <input type="text" class="form-control" id="complaintSearch" placeholder="Search by tenant, unit, title, or status...">
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="row" id="complaintsList">
            <?php while ($complaint = $result->fetch_assoc()): 
                $cid = $complaint['complaint_id'];
                $replies = $allReplies[$cid] ?? [];
                $isUrgent = (isset($complaint['urgent']) && (int)$complaint['urgent'] === 1) || stripos((string)$complaint['title'], '[URGENT]') === 0;
                $replyCount = count($replies);
            ?>
            <div class="col-12 mb-3 complaint-card-wrapper">
                <div class="card <?php echo $isUrgent ? 'border-danger' : ''; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($isUrgent): ?>
                                <span class="badge bg-danger me-2"><i class="fas fa-bolt me-1"></i>Urgent</span>
                            <?php endif; ?>
                            <strong><?php echo htmlspecialchars($complaint['title']); ?></strong>
                            <span class="badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($complaint['status']))); ?> ms-2">
                                <?php echo ucfirst($complaint['status']); ?>
                            </span>
                            <?php if ($replyCount > 0): ?>
                                <span class="badge bg-info ms-1"><i class="fas fa-comments me-1"></i><?php echo $replyCount; ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <small class="text-muted me-3">
                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($complaint['tenant_name']); ?> 
                                <span class="badge bg-primary ms-1"><?php echo htmlspecialchars($complaint['unit_number']); ?></span>
                            </small>
                            <button class="btn btn-sm btn-dark me-1" data-bs-toggle="collapse" data-bs-target="#thread<?php echo $cid; ?>">
                                <i class="fas fa-comments me-1"></i>Thread
                            </button>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $cid; ?>">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                        <small class="text-muted">
                            <i class="fas fa-calendar me-1"></i>Issue Date: <?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?>
                            <span class="ms-3"><i class="fas fa-clock me-1"></i>Submitted: <?php echo date('M d, Y g:i A', strtotime($complaint['created_at'])); ?></span>
                        </small>

                        <?php if (!empty($complaint['admin_response'])): ?>
                            <div class="alert alert-secondary mt-2 mb-0">
                                <strong><i class="fas fa-reply me-1"></i>Admin Response:</strong><br>
                                <?php echo nl2br(htmlspecialchars($complaint['admin_response'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Collapsible conversation thread -->
                    <div class="collapse" id="thread<?php echo $cid; ?>">
                        <div class="card-body border-top pt-3">
                            <h6 class="mb-3"><i class="fas fa-comments me-2"></i>Conversation Thread</h6>

                            <?php if (empty($replies)): ?>
                                <p class="text-muted text-center"><em>No replies yet. Start the conversation below.</em></p>
                            <?php else: ?>
                                <div class="complaint-thread" style="max-height: 350px; overflow-y: auto;">
                                    <?php foreach ($replies as $reply): ?>
                                        <div class="d-flex mb-3 <?php echo $reply['role'] === 'admin' ? 'justify-content-end' : 'justify-content-start'; ?>">
                                            <div class="p-2 rounded-3 <?php echo $reply['role'] === 'admin' ? 'bg-dark text-white' : 'bg-light'; ?>" style="max-width: 75%; min-width: 200px;">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <small class="fw-bold">
                                                        <i class="fas <?php echo $reply['role'] === 'admin' ? 'fa-user-shield' : 'fa-user'; ?> me-1"></i>
                                                        <?php echo htmlspecialchars($reply['full_name'] ?: $reply['username']); ?>
                                                        <span class="badge <?php echo $reply['role'] === 'admin' ? 'bg-light text-dark' : 'bg-secondary'; ?> ms-1"><?php echo ucfirst($reply['role']); ?></span>
                                                    </small>
                                                </div>
                                                <div><?php echo nl2br(htmlspecialchars($reply['message'])); ?></div>
                                                <small class="<?php echo $reply['role'] === 'admin' ? 'text-white-50' : 'text-muted'; ?> d-block text-end mt-1">
                                                    <?php echo date('M d, Y g:i A', strtotime($reply['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Admin reply form -->
                            <form method="POST" class="mt-3">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="complaint_id" value="<?php echo $cid; ?>">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="reply_message" placeholder="Type your reply..." required>
                                    <button type="submit" name="send_reply" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-1"></i>Send
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Modal -->
            <div class="modal fade" id="editModal<?php echo $cid; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="complaint_id" value="<?php echo $cid; ?>">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Update Complaint</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status" required>
                                        <option value="pending" <?php if ($complaint['status'] == 'pending') echo 'selected'; ?>>Pending</option>
                                        <option value="ongoing" <?php if ($complaint['status'] == 'ongoing') echo 'selected'; ?>>Ongoing</option>
                                        <option value="resolved" <?php if ($complaint['status'] == 'resolved') echo 'selected'; ?>>Resolved</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Admin Response</label>
                                    <textarea class="form-control" name="admin_response" rows="3"><?php echo htmlspecialchars($complaint['admin_response']); ?></textarea>
                                    <div class="form-text">This is the primary response shown on the tenant's complaint card. Use the thread for follow-up messages.</div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" name="update_complaint" class="btn btn-primary">Save changes</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p class="no-record-message dashboard-title">No complaints found.</p>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Search filter
    var search = document.getElementById('complaintSearch');
    if (search) {
        search.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            document.querySelectorAll('.complaint-card-wrapper').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
