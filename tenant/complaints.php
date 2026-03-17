<?php
ob_start(); // buffer output so no stray text corrupts JSON
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php"); exit();
}

$page_title = "My Complaints";

$ts = $conn->prepare("SELECT tenant_id FROM tenants WHERE user_id = ?");
$ts->bind_param("i", $_SESSION['user_id']);
$ts->execute();
$tenant = $ts->get_result()->fetch_assoc();
$ts->close();
if (!$tenant) { header("Location: ../logout.php"); exit(); }

$tenant_id = $tenant['tenant_id'];
$hasUrgent = db_column_exists($conn, 'complaints', 'urgent');

// Auto-create complaint_replies table if missing
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

// Emergency phone
$emergency_phone = '(555) 123-4567';
try {
    $ep = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'emergency_phone'");
    if ($ep) { $ep->execute(); $epRow = $ep->get_result()->fetch_assoc(); if ($epRow) $emergency_phone = $epRow['setting_value']; $ep->close(); }
} catch (Throwable $e) {}

// ── AJAX: load replies ───────────────────────────────────────────────
if (isset($_GET['ajax_replies'])) {
    ob_clean();
    header('Content-Type: application/json');
    $cid = intval($_GET['complaint_id'] ?? 0);
    // Verify ownership
    $own = $conn->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND tenant_id = ?");
    $own->bind_param("ii", $cid, $tenant_id); $own->execute(); $own->store_result();
    if ($own->num_rows === 0) { echo json_encode(['success'=>false,'error'=>'Not found']); exit(); }
    $own->close();
    $rows = [];
    $st = $conn->prepare("SELECT cr.reply_id, cr.complaint_id, cr.role, cr.message, cr.created_at, u.username, u.full_name FROM complaint_replies cr JOIN users u ON cr.user_id = u.id WHERE cr.complaint_id = ? ORDER BY cr.created_at ASC");
    if ($st) { $st->bind_param("i", $cid); $st->execute(); $res = $st->get_result(); while ($r = $res->fetch_assoc()) $rows[] = $r; $st->close(); }
    echo json_encode(['success' => true, 'replies' => $rows]);
    exit();
}

// ── AJAX: send reply ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_send_reply'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'error'=>'Invalid token']); exit(); }
    $cid = intval($_POST['complaint_id'] ?? 0);
    $msg = trim($_POST['reply_message'] ?? '');
    $own = $conn->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND tenant_id = ?");
    $own->bind_param("ii", $cid, $tenant_id); $own->execute(); $own->store_result();
    if ($own->num_rows === 0 || empty($msg)) { echo json_encode(['success'=>false,'error'=>'Not allowed']); exit(); }
    $own->close();
    $ins = $conn->prepare("INSERT INTO complaint_replies (complaint_id, user_id, role, message) VALUES (?, ?, 'tenant', ?)");
    if (!$ins) { echo json_encode(['success'=>false,'error'=>'Prepare failed: '.$conn->error]); exit(); }
    $ins->bind_param("iis", $cid, $_SESSION['user_id'], $msg);
    $ok = $ins->execute(); $rid = $ins->insert_id; $ins->close();
    if ($ok && $rid) {
        $fe = $conn->prepare("SELECT cr.reply_id, cr.complaint_id, cr.role, cr.message, cr.created_at, u.username, u.full_name FROM complaint_replies cr JOIN users u ON cr.user_id = u.id WHERE cr.reply_id = ?");
        $fe->bind_param("i", $rid); $fe->execute();
        $reply = $fe->get_result()->fetch_assoc(); $fe->close();
        echo json_encode(['success' => true, 'reply' => $reply]);
    } else { echo json_encode(['success'=>false,'error'=>'Insert failed: '.$conn->error]); }
    exit();
}

// ── Submit new complaint ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    $title      = trim($_POST['title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $comp_date  = $_POST['complaint_date'] ?? date('Y-m-d');
    $urgentFlag = isset($_POST['urgent']) ? 1 : 0;
    if (!empty($title) && !empty($desc)) {
        if ($hasUrgent) {
            $ins = $conn->prepare("INSERT INTO complaints (tenant_id,title,description,complaint_date,status,urgent) VALUES (?,?,?,?,'pending',?)");
            $ins->bind_param("isssi", $tenant_id, $title, $desc, $comp_date, $urgentFlag);
        } else {
            if ($urgentFlag && stripos($title,'[URGENT]')!==0) $title='[URGENT] '.$title;
            $ins = $conn->prepare("INSERT INTO complaints (tenant_id,title,description,complaint_date,status) VALUES (?,?,?,?,'pending')");
            $ins->bind_param("isss", $tenant_id, $title, $desc, $comp_date);
        }
        $success = $ins->execute() ? "Complaint submitted! We'll respond soon." : "Failed to submit.";
        $ins->close();
    } else { $error = "Please fill in all required fields."; }
}

// Fetch complaints
$cst = $conn->prepare("SELECT * FROM complaints WHERE tenant_id = ? ORDER BY created_at DESC");
$cst->bind_param("i", $tenant_id); $cst->execute();
$complaints = $cst->get_result();

// Reply counts
$replyCounts = [];
$rct = $conn->prepare("SELECT cr.complaint_id, COUNT(*) as cnt FROM complaint_replies cr JOIN complaints c ON cr.complaint_id = c.complaint_id WHERE c.tenant_id = ? GROUP BY cr.complaint_id");
$rct->bind_param("i", $tenant_id); $rct->execute();
$rcRes = $rct->get_result();
while ($rc = $rcRes->fetch_assoc()) $replyCounts[$rc['complaint_id']] = (int)$rc['cnt'];
$rct->close();

include '../includes/header.php';
?>

<style>
.complaint-thread-box { max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: .45rem; padding: .4rem 0; scroll-behavior: smooth; }
.chat-bubble { display: flex; flex-direction: column; max-width: 80%; padding: .5rem .8rem; border-radius: 14px; font-size: .875rem; line-height: 1.5; word-break: break-word; }
.chat-bubble.tenant { align-self: flex-end;  background: #007DFE; color: #fff; border-bottom-right-radius: 4px; }
.chat-bubble.admin  { align-self: flex-start; background: #f1f5f9; color: #0f172a; border-bottom-left-radius: 4px; }
body.dark-mode .chat-bubble.admin  { background: #2a2a2a; color: #e2e8f0; }
body.dark-mode .chat-bubble.tenant { background: #1d4ed8; }
.chat-bubble .bubble-meta { font-size: .7rem; opacity: .65; margin-bottom: .15rem; }
.chat-bubble .bubble-time { font-size: .67rem; opacity: .55; margin-top: .2rem; text-align: right; }
.chat-bubble.admin .bubble-time { text-align: left; }
.thread-input-row { display: flex; gap: .5rem; margin-top: .75rem; align-items: center; }
.thread-input-row input  { flex: 1; border-radius: 20px; padding: .4rem .9rem; }
.thread-input-row button { border-radius: 20px; padding: .4rem 1rem; white-space: nowrap; }
.thread-loading { text-align: center; padding: 1.2rem 0; color: #94a3b8; font-size: .83rem; }
</style>

<div class="container mt-4 tenant-ui">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>My Complaints & Requests</h4>
                </div>
                <div class="card-body complaint-form-body">
                <?php if ($complaints->num_rows > 0):
                    while ($complaint = $complaints->fetch_assoc()):
                        $cid = $complaint['complaint_id'];
                        $rc  = $replyCounts[$cid] ?? 0;
                ?>
                    <div class="card mb-3">
                        <div class="card-body complaint-item">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-0"><?= htmlspecialchars($complaint['title']) ?></h6>
                                <div class="d-flex gap-1 align-items-center flex-wrap">
                                    <?php if (!empty($hasUrgent) && !empty($complaint['urgent'])): ?>
                                        <span class="badge bg-danger"><i class="fas fa-bolt me-1"></i>Urgent</span>
                                    <?php endif; ?>
                                    <span class="badge status-<?= htmlspecialchars(str_replace(' ','-',strtolower($complaint['status']))) ?>">
                                        <?= ucfirst($complaint['status']) ?>
                                    </span>
                                    <span class="badge bg-info" id="badge-<?= $cid ?>">
                                        <i class="fas fa-comments me-1"></i><span><?= $rc ?></span>
                                    </span>
                                </div>
                            </div>
                            <p class="mb-2 complaint-text"><?= htmlspecialchars($complaint['description']) ?></p>
                            <?php if (!empty($complaint['admin_response'])): ?>
                                <div class="alert alert-info mb-2">
                                    <strong><i class="fas fa-reply me-1"></i>Admin Response:</strong><br>
                                    <?= nl2br(htmlspecialchars($complaint['admin_response'])) ?>
                                </div>
                            <?php endif; ?>
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>Submitted: <?= date('M d, Y g:i A', strtotime($complaint['created_at'])) ?>
                                <span class="ms-3"><i class="fas fa-clock me-1"></i>Issue Date: <?= date('M d, Y', strtotime($complaint['complaint_date'])) ?></span>
                            </small>
                            <div class="mt-2">
                                <button class="btn btn-sm btn-outline-primary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#thread<?= $cid ?>">
                                    <i class="fas fa-comments me-1"></i><?= $rc > 0 ? "Thread ($rc)" : 'Reply' ?>
                                </button>
                            </div>
                            <div class="collapse mt-3" id="thread<?= $cid ?>" data-cid="<?= $cid ?>">
                                <div class="border-top pt-3">
                                    <div class="complaint-thread-box" id="threadMessages<?= $cid ?>">
                                        <div class="thread-loading" id="threadLoading<?= $cid ?>">
                                            <i class="fas fa-circle-notch fa-spin me-1"></i>Loading...
                                        </div>
                                    </div>
                                    <div class="thread-input-row">
                                        <input type="text"
                                               class="form-control thread-reply-input"
                                               id="replyInput<?= $cid ?>"
                                               placeholder="Type your reply..."
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
                <?php endwhile; else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-3x complaint-text mb-3"></i>
                        <h5 class="complaint-text">No Complaints Submitted</h5>
                        <p class="complaint-text">Submit your first maintenance request or complaint.</p>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card complaint-form-card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-plus me-2"></i>Submit New Complaint</h5></div>
                <div class="card-body complaint-form-body">
                    <?php if (isset($success)): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= $success ?></div><?php endif; ?>
                    <?php if (isset($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= $error ?></div><?php endif; ?>
                    <form method="POST" novalidate>
                        <?= csrf_input() ?>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-tag me-1"></i>Issue Title *</label>
                            <input type="text" class="form-control" name="title" placeholder="e.g., Leaky faucet" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-align-left me-1"></i>Description *</label>
                            <textarea class="form-control" name="description" rows="4" placeholder="Describe the issue..." required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-calendar me-1"></i>Issue Date *</label>
                            <input type="date" class="form-control" name="complaint_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="urgent" name="urgent">
                            <label class="form-check-label" for="urgent"><i class="fas fa-bolt me-1"></i>Mark as urgent</label>
                        </div>
                        <div class="d-grid">
                            <button type="submit" name="submit_complaint" class="btn btn-warning">
                                <i class="fas fa-paper-plane me-2"></i>Submit Complaint
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header"><h6 class="mb-0"><i class="fas fa-lightbulb me-2"></i>Common Issues</h6></div>
                <div class="card-body"><small><ul class="mb-0">
                    <li>Plumbing issues (leaks, clogs)</li>
                    <li>Electrical problems</li>
                    <li>Heating/cooling issues</li>
                    <li>Appliance malfunctions</li>
                    <li>Pest control</li>
                    <li>Noise complaints</li>
                    <li>Security concerns</li>
                </ul></small></div>
            </div>

            <div class="card mt-3">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Emergency Contact</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><i class="fas fa-phone me-2"></i><strong>Emergency Line:</strong> <?= htmlspecialchars($emergency_phone) ?></p>
                    <small class="text-muted">Available 24/7 for urgent issues.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF   = <?= json_encode(csrf_token()) ?>;
    var loaded = {};

    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function makeBubble(r) {
        var isAdmin = r.role === 'admin';
        var name    = r.full_name || r.username || (isAdmin ? 'Admin' : 'You');
        var dt      = new Date(r.created_at.replace(' ', 'T'));
        var time    = dt.toLocaleString('en-PH', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
        var d       = document.createElement('div');
        d.className = 'chat-bubble ' + r.role;
        d.innerHTML =
            '<div class="bubble-meta"><i class="fas '+(isAdmin?'fa-user-shield':'fa-user')+' me-1"></i>'
          + esc(name) + ' <span class="badge '+(isAdmin?'bg-secondary':'bg-light text-dark')+' ms-1" style="font-size:.63rem;">'+(isAdmin?'Admin':'You')+'</span></div>'
          + '<div>'+esc(r.message).replace(/\n/g,'<br>')+'</div>'
          + '<div class="bubble-time">'+time+'</div>';
        return d;
    }

    function scrollBottom(box) { box.scrollTop = box.scrollHeight; }

    function showError(cid, msg) {
        var el = document.getElementById('threadError' + cid);
        if (el) { el.textContent = msg; el.style.display = msg ? 'block' : 'none'; }
    }

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
            .catch(function () {
                var box = document.getElementById('threadMessages' + cid);
                if (box) box.innerHTML = '<p class="text-danger text-center small py-3">Could not load messages.</p>';
            });
    }

    document.querySelectorAll('.collapse[id^="thread"]').forEach(function (el) {
        var cid = el.dataset.cid;
        el.addEventListener('show.bs.collapse', function () { loadReplies(cid); });
    });

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
                var ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json')) {
                    return r.text().then(function (txt) {
                        throw new Error('Server error: ' + txt.substring(0, 200));
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
                    var badge = document.getElementById('badge-' + cid);
                    if (badge) { var sp = badge.querySelector('span'); if (sp) sp.textContent = parseInt(sp.textContent || '0') + 1; }
                } else {
                    showError(cid, 'Error: ' + (data.error || 'Unknown'));
                }
            })
            .catch(function (err) { showError(cid, 'Failed: ' + err.message); })
            .finally(function () {
                inp.disabled = false;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send';
                inp.focus();
            });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('.thread-send-btn');
        if (b) sendReply(b.dataset.cid);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.classList.contains('thread-reply-input')) sendReply(e.target.dataset.cid);
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
