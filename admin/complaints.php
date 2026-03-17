<?php
ob_start(); // buffer all output so no stray text corrupts JSON responses
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
require_once '../includes/email_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php"); exit();
}

// ── Auto-create complaint_replies table if missing ───────────────────
$conn->query("CREATE TABLE IF NOT EXISTS complaint_replies (
    reply_id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin','tenant') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (complaint_id) REFERENCES complaints(complaint_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// ── AJAX: load replies ───────────────────────────────────────────────
if (isset($_GET['ajax_replies'])) {
    ob_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['complaint_id'] ?? 0);
    $rows = [];
    $st = $conn->prepare("
        SELECT cr.reply_id, cr.complaint_id, cr.role, cr.message, cr.created_at,
               u.username, u.full_name
        FROM complaint_replies cr
        JOIN users u ON cr.user_id = u.id
        WHERE cr.complaint_id = ?
        ORDER BY cr.created_at ASC
    ");
    if ($st) {
        $st->bind_param("i", $cid);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $st->close();
    }
    echo json_encode(['success' => true, 'replies' => $rows]);
    exit();
}

// ── AJAX: send reply ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_send_reply'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']); exit();
    }
    $cid = intval($_POST['complaint_id'] ?? 0);
    $msg = trim($_POST['reply_message'] ?? '');
    if (empty($msg)) {
        echo json_encode(['success' => false, 'error' => 'Empty message']); exit();
    }
    $ins = $conn->prepare("INSERT INTO complaint_replies (complaint_id, user_id, role, message) VALUES (?, ?, 'admin', ?)");
    if (!$ins) {
        echo json_encode(['success' => false, 'error' => 'Prepare failed: ' . $conn->error]); exit();
    }
    $ins->bind_param("iis", $cid, $_SESSION['user_id'], $msg);
    $ok  = $ins->execute();
    $rid = $ins->insert_id;
    $ins->close();
    if ($ok && $rid) {
        $fe = $conn->prepare("
            SELECT cr.reply_id, cr.complaint_id, cr.role, cr.message, cr.created_at,
                   u.username, u.full_name
            FROM complaint_replies cr
            JOIN users u ON cr.user_id = u.id
            WHERE cr.reply_id = ?
        ");
        $fe->bind_param("i", $rid);
        $fe->execute();
        $reply = $fe->get_result()->fetch_assoc();
        $fe->close();
        logAdminAction($conn, $_SESSION['user_id'], 'reply_complaint', "Replied to complaint #$cid");
        echo json_encode(['success' => true, 'reply' => $reply]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Insert failed: ' . $conn->error]);
    }
    exit();
}

// ── Update status / admin_response ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_complaint'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    $cid    = intval($_POST['complaint_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $resp   = trim($_POST['admin_response'] ?? '');
    $upd = $conn->prepare("UPDATE complaints SET status = ?, admin_response = ? WHERE complaint_id = ?");
    $upd->bind_param("ssi", $status, $resp, $cid);
    $upd->execute(); $upd->close();
    logAdminAction($conn, $_SESSION['user_id'], 'update_complaint', "Updated complaint #$cid to $status");
    header("Location: complaints.php?updated=1"); exit();
}

if (isset($_GET['updated'])) $success = "Complaint updated successfully!";

$hasUrgent = db_column_exists($conn, 'complaints', 'urgent');

// Fetch complaints
$sql = "SELECT c.complaint_id, c.complaint_date, c.title, c.description,
               c.status, c.admin_response, c.created_at,
               t.name AS tenant_name, t.unit_number"
     . ($hasUrgent ? ", c.urgent" : ", 0 AS urgent")
     . " FROM complaints c
         JOIN tenants t ON c.tenant_id = t.tenant_id
         ORDER BY c.complaint_date DESC";
$result = $conn->query($sql);
if (!$result) die("DB error: " . $conn->error);

// Reply counts
$replyCounts = [];
$rc = $conn->query("SELECT complaint_id, COUNT(*) as cnt FROM complaint_replies GROUP BY complaint_id");
if ($rc) while ($r = $rc->fetch_assoc()) $replyCounts[$r['complaint_id']] = (int)$r['cnt'];

$page_title = "Manage Complaints";
include '../includes/header.php';
?>

<style>
.complaint-thread-box {
    max-height: 380px; overflow-y: auto;
    display: flex; flex-direction: column; gap: .5rem;
    padding: .5rem 0; scroll-behavior: smooth;
}
.chat-bubble {
    display: flex; flex-direction: column;
    max-width: 78%; padding: .55rem .85rem;
    border-radius: 14px; font-size: .875rem; line-height: 1.5; word-break: break-word;
}
.chat-bubble.admin  { align-self: flex-end;  background: #1e293b; color: #f1f5f9; border-bottom-right-radius: 4px; }
.chat-bubble.tenant { align-self: flex-start; background: #f1f5f9; color: #0f172a; border-bottom-left-radius: 4px; }
body.dark-mode .chat-bubble.tenant { background: #2a2a2a; color: #e2e8f0; }
.chat-bubble .bubble-meta { font-size: .7rem; opacity: .6; margin-bottom: .2rem; }
.chat-bubble .bubble-time { font-size: .68rem; opacity: .5; margin-top: .25rem; text-align: right; }
.chat-bubble.tenant .bubble-time { text-align: left; }
.thread-input-row { display: flex; gap: .5rem; margin-top: .75rem; align-items: center; }
.thread-input-row input  { flex: 1; border-radius: 20px; padding: .45rem 1rem; }
.thread-input-row button { border-radius: 20px; padding: .45rem 1.1rem; white-space: nowrap; }
.thread-loading { text-align: center; padding: 1.5rem 0; color: #94a3b8; font-size: .85rem; }
</style>

<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title"><i class="fas fa-exclamation-triangle me-2"></i>Tenant Complaints</h2>

    <?php if (isset($success)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <input type="text" class="form-control" id="complaintSearch" placeholder="Search by tenant, unit, title, or status...">
    </div>

    <?php if ($result->num_rows > 0): ?>
    <div class="row" id="complaintsList">
    <?php while ($c = $result->fetch_assoc()):
        $cid      = $c['complaint_id'];
        $rcnt     = $replyCounts[$cid] ?? 0;
        $isUrgent = ((int)($c['urgent'] ?? 0) === 1) || stripos((string)$c['title'], '[URGENT]') === 0;
    ?>
        <div class="col-12 mb-3 complaint-card-wrapper">
            <div class="card <?= $isUrgent ? 'border-danger' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <?php if ($isUrgent): ?><span class="badge bg-danger me-2"><i class="fas fa-bolt me-1"></i>Urgent</span><?php endif; ?>
                        <strong><?= htmlspecialchars($c['title']) ?></strong>
                        <span class="badge status-<?= htmlspecialchars(str_replace(' ', '-', strtolower($c['status']))) ?> ms-2"><?= ucfirst($c['status']) ?></span>
                        <span class="badge bg-info ms-1" id="badge-<?= $cid ?>">
                            <i class="fas fa-comments me-1"></i><span><?= $rcnt ?></span>
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted">
                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($c['tenant_name']) ?>
                            <span class="badge bg-primary ms-1"><?= htmlspecialchars($c['unit_number']) ?></span>
                        </small>
                        <button class="btn btn-sm btn-dark"
                                data-bs-toggle="collapse"
                                data-bs-target="#thread<?= $cid ?>">
                            <i class="fas fa-comments me-1"></i>Thread
                        </button>
                        <button class="btn btn-sm btn-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#editModal<?= $cid ?>">
                            <i class="fas fa-edit me-1"></i>Edit
                        </button>
                    </div>
                </div>

                <div class="card-body">
                    <p class="mb-2"><?= nl2br(htmlspecialchars($c['description'])) ?></p>
                    <small class="text-muted">
                        <i class="fas fa-calendar me-1"></i>Issue Date: <?= date('M d, Y', strtotime($c['complaint_date'])) ?>
                        <span class="ms-3"><i class="fas fa-clock me-1"></i>Submitted: <?= date('M d, Y g:i A', strtotime($c['created_at'])) ?></span>
                    </small>
                    <?php if (!empty($c['admin_response'])): ?>
                        <div class="alert alert-secondary mt-2 mb-0">
                            <strong><i class="fas fa-reply me-1"></i>Admin Response:</strong><br>
                            <?= nl2br(htmlspecialchars($c['admin_response'])) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Thread (lazy-loaded via AJAX) -->
                <div class="collapse" id="thread<?= $cid ?>" data-cid="<?= $cid ?>">
                    <div class="card-body border-top pt-3">
                        <h6 class="mb-2"><i class="fas fa-comments me-2"></i>Conversation Thread</h6>
                        <div class="complaint-thread-box" id="threadMessages<?= $cid ?>">
                            <div class="thread-loading" id="threadLoading<?= $cid ?>">
                                <i class="fas fa-circle-notch fa-spin me-1"></i>Loading messages...
                            </div>
                        </div>
                        <div class="thread-input-row">
                            <input type="text"
                                   class="form-control thread-reply-input"
                                   id="replyInput<?= $cid ?>"
                                   placeholder="Type your reply and press Enter..."
                                   data-cid="<?= $cid ?>">
                            <button class="btn btn-primary thread-send-btn" data-cid="<?= $cid ?>">
                                <i class="fas fa-paper-plane me-1"></i>Send
                            </button>
                        </div>
                        <div class="text-danger small mt-1" id="threadError<?= $cid ?>" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal<?= $cid ?>" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="complaint_id" value="<?= $cid ?>">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Update Complaint</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" required>
                                    <option value="pending"  <?= $c['status'] == 'pending'  ? 'selected' : '' ?>>Pending</option>
                                    <option value="ongoing"  <?= $c['status'] == 'ongoing'  ? 'selected' : '' ?>>Ongoing</option>
                                    <option value="resolved" <?= $c['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Admin Response</label>
                                <textarea class="form-control" name="admin_response" rows="3"><?= htmlspecialchars($c['admin_response'] ?? '') ?></textarea>
                                <div class="form-text">Shown on tenant's card. Use Thread for chat messages.</div>
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
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var loaded = {};

    // Search
    var srch = document.getElementById('complaintSearch');
    if (srch) srch.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('.complaint-card-wrapper').forEach(function (el) {
            el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function makeBubble(r) {
        var isAdmin = r.role === 'admin';
        var name    = r.full_name || r.username || (isAdmin ? 'Admin' : 'Tenant');
        var dt      = new Date(r.created_at.replace(' ', 'T'));
        var time    = dt.toLocaleString('en-PH', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
        var div     = document.createElement('div');
        div.className = 'chat-bubble ' + r.role;
        div.innerHTML =
            '<div class="bubble-meta">'
          +   '<i class="fas ' + (isAdmin ? 'fa-user-shield' : 'fa-user') + ' me-1"></i>'
          +   esc(name)
          +   ' <span class="badge ' + (isAdmin ? 'bg-light text-dark' : 'bg-secondary') + ' ms-1" style="font-size:.65rem;">' + (isAdmin ? 'Admin' : 'Tenant') + '</span>'
          + '</div>'
          + '<div>' + esc(r.message).replace(/\n/g, '<br>') + '</div>'
          + '<div class="bubble-time">' + time + '</div>';
        return div;
    }

    function scrollBottom(box) { box.scrollTop = box.scrollHeight; }

    function showError(cid, msg) {
        var el = document.getElementById('threadError' + cid);
        if (el) { el.textContent = msg; el.style.display = msg ? 'block' : 'none'; }
    }

    // Load replies when thread opens
    function loadReplies(cid) {
        if (loaded[cid]) return;
        fetch('complaints.php?ajax_replies=1&complaint_id=' + cid, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var box = document.getElementById('threadMessages' + cid);
                var ldr = document.getElementById('threadLoading' + cid);
                if (ldr) ldr.remove();
                box.innerHTML = '';
                if (data.replies && data.replies.length > 0) {
                    data.replies.forEach(function (r) { box.appendChild(makeBubble(r)); });
                } else {
                    box.innerHTML = '<p class="text-muted text-center small py-3 mb-0"><em>No messages yet.</em></p>';
                }
                scrollBottom(box);
                loaded[cid] = true;
            })
            .catch(function (err) {
                var box = document.getElementById('threadMessages' + cid);
                if (box) box.innerHTML = '<p class="text-danger text-center small py-3">Could not load messages. Try refreshing.</p>';
            });
    }

    document.querySelectorAll('.collapse[id^="thread"]').forEach(function (el) {
        var cid = el.dataset.cid;
        el.addEventListener('show.bs.collapse', function () { loadReplies(cid); });
    });

    // Send reply
    function sendReply(cid) {
        var inp = document.getElementById('replyInput' + cid);
        var btn = document.querySelector('.thread-send-btn[data-cid="' + cid + '"]');
        var msg = inp ? inp.value.trim() : '';
        if (!msg) return;

        showError(cid, '');
        inp.disabled = true;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';

        var fd = new FormData();
        fd.append('ajax_send_reply', '1');
        fd.append('complaint_id', cid);
        fd.append('reply_message', msg);
        fd.append('csrf_token', CSRF);

        fetch('complaints.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                // Check content-type first
                var ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    return r.text().then(function (txt) {
                        throw new Error('Server returned non-JSON: ' + txt.substring(0, 200));
                    });
                }
                return r.json();
            })
            .then(function (data) {
                if (data.success) {
                    var box   = document.getElementById('threadMessages' + cid);
                    var empty = box.querySelector('p.text-muted');
                    if (empty) empty.remove();
                    box.appendChild(makeBubble(data.reply));
                    scrollBottom(box);
                    inp.value = '';
                    // Update badge count
                    var badge = document.getElementById('badge-' + cid);
                    if (badge) {
                        var sp = badge.querySelector('span');
                        if (sp) sp.textContent = parseInt(sp.textContent || '0') + 1;
                    }
                } else {
                    showError(cid, 'Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function (err) {
                showError(cid, 'Failed to send: ' + err.message);
            })
            .finally(function () {
                inp.disabled = false;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send';
                inp.focus();
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.thread-send-btn');
        if (btn) sendReply(btn.dataset.cid);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('thread-reply-input')) {
            sendReply(e.target.dataset.cid);
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
