<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Check if user is tenant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

$page_title = "My Complaints";

// Get tenant info
$tenant_stmt = $conn->prepare("SELECT tenant_id FROM tenants WHERE user_id = ?");
$tenant_stmt->bind_param("i", $_SESSION['user_id']);
$tenant_stmt->execute();
$tenant_result = $tenant_stmt->get_result();
$tenant = $tenant_result->fetch_assoc();

if (!$tenant) {
    header("Location: ../logout.php");
    exit();
}

$tenant_id = $tenant['tenant_id'];

$hasUrgent = db_column_exists($conn, 'complaints', 'urgent');

// Load emergency contact from settings
$emergency_phone = '(555) 123-4567'; // default fallback
try {
    $eStmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'emergency_phone'");
    if ($eStmt) {
        $eStmt->execute();
        $eResult = $eStmt->get_result();
        if ($eRow = $eResult->fetch_assoc()) {
            $emergency_phone = $eRow['setting_value'];
        }
        $eStmt->close();
    }
} catch (Throwable $e) {}

// Handle complaint submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_complaint'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $complaint_date = $_POST['complaint_date'];
    $urgentFlag = isset($_POST['urgent']) ? 1 : 0;
    
    if (!empty($title) && !empty($description)) {
        if ($hasUrgent) {
            $stmt = $conn->prepare("INSERT INTO complaints (tenant_id, title, description, complaint_date, status, urgent) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->bind_param("isssi", $tenant_id, $title, $description, $complaint_date, $urgentFlag);
        } else {
            if ($urgentFlag && stripos($title, '[URGENT]') !== 0) {
                $title = '[URGENT] ' . $title;
            }
            $stmt = $conn->prepare("INSERT INTO complaints (tenant_id, title, description, complaint_date, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->bind_param("isss", $tenant_id, $title, $description, $complaint_date);
        }
        
        if ($stmt->execute()) {
            $success = "Complaint submitted successfully! We'll respond soon.";
        } else {
            $error = "Failed to submit complaint";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all required fields";
    }
}

// Handle tenant reply to a complaint thread
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_reply'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $complaint_id = intval($_POST['complaint_id']);
    $message = trim($_POST['reply_message'] ?? '');

    // Verify this complaint belongs to the tenant
    $check = $conn->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND tenant_id = ?");
    $check->bind_param("ii", $complaint_id, $tenant_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0 && !empty($message)) {
        $stmt = $conn->prepare("INSERT INTO complaint_replies (complaint_id, user_id, role, message) VALUES (?, ?, 'tenant', ?)");
        $stmt->bind_param("iis", $complaint_id, $_SESSION['user_id'], $message);
        $stmt->execute();
        $stmt->close();
        $reply_success = true;
    }
    $check->close();
    header("Location: complaints.php?reply=sent");
    exit();
}

// Flash message from reply redirect
if (isset($_GET['reply']) && $_GET['reply'] === 'sent') {
    $success = "Reply sent successfully!";
}

// Get complaint history
$complaints_stmt = $conn->prepare("SELECT * FROM complaints WHERE tenant_id = ? ORDER BY created_at DESC");
$complaints_stmt->bind_param("i", $tenant_id);
$complaints_stmt->execute();
$complaints = $complaints_stmt->get_result();

// Pre-fetch all replies for this tenant's complaints
$allReplies = [];
$repliesStmt = $conn->prepare("
    SELECT cr.*, u.username, u.full_name 
    FROM complaint_replies cr 
    JOIN users u ON cr.user_id = u.id 
    WHERE cr.complaint_id IN (SELECT complaint_id FROM complaints WHERE tenant_id = ?)
    ORDER BY cr.created_at ASC
");
$repliesStmt->bind_param("i", $tenant_id);
$repliesStmt->execute();
$repliesResult = $repliesStmt->get_result();
while ($r = $repliesResult->fetch_assoc()) {
    $allReplies[$r['complaint_id']][] = $r;
}
$repliesStmt->close();

include '../includes/header.php';
?>

<div class="container mt-4 tenant-ui">
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>My Complaints & Requests
                    </h4>
                </div>
                <div class="card-body complaint-form-body">
                    <?php if ($complaints->num_rows > 0): ?>
                        <?php while ($complaint = $complaints->fetch_assoc()): 
                            $cid = $complaint['complaint_id'];
                            $replies = $allReplies[$cid] ?? [];
                            $replyCount = count($replies);
                        ?>
                            <div class="card mb-3">
                                <div class="card-body complaint-item">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($complaint['title']); ?></h6>
                                        <div>
                                            <?php if (!empty($hasUrgent) && isset($complaint['urgent']) && (int)$complaint['urgent'] === 1): ?>
                                                <span class="badge bg-danger me-2"><i class="fas fa-bolt me-1"></i>Urgent</span>
                                            <?php endif; ?>
                                            <span class="badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($complaint['status']))); ?>">
                                                <?php echo ucfirst($complaint['status']); ?>
                                            </span>
                                            <?php if ($replyCount > 0): ?>
                                                <span class="badge bg-info ms-1"><i class="fas fa-comments me-1"></i><?php echo $replyCount; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                        <p class="mb-2 complaint-text">
                                        <?php echo htmlspecialchars($complaint['description']); ?>
                                    </p>
                                    <?php if ($complaint['admin_response']): ?>
                                        <div class="alert alert-info mb-2">
                                            <strong><i class="fas fa-reply me-1"></i>Admin Response:</strong><br>
                                            <?php echo nl2br(htmlspecialchars($complaint['admin_response'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                    <i class="fas fa-calendar me-1 complaint-text"></i>
                                        Submitted: <?php echo date('M d, Y g:i A', strtotime($complaint['created_at'])); ?>
                                        <span class="ms-3">
                                            <i class="fas fa-clock me-1"></i>
                                            Issue Date: <?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?>
                                        </span>
                                    </small>

                                    <!-- Toggle conversation thread -->
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#thread<?php echo $cid; ?>">
                                            <i class="fas fa-comments me-1"></i>
                                            <?php echo $replyCount > 0 ? "View Thread ($replyCount)" : 'Reply'; ?>
                                        </button>
                                    </div>

                                    <!-- Conversation thread -->
                                    <div class="collapse mt-3" id="thread<?php echo $cid; ?>">
                                        <div class="border-top pt-3">
                                            <?php if (!empty($replies)): ?>
                                                <div class="complaint-thread" style="max-height: 300px; overflow-y: auto;">
                                                    <?php foreach ($replies as $reply): ?>
                                                        <div class="d-flex mb-2 <?php echo $reply['role'] === 'admin' ? 'justify-content-start' : 'justify-content-end'; ?>">
                                                            <div class="p-2 rounded-3 <?php echo $reply['role'] === 'admin' ? 'bg-dark text-white' : 'bg-light'; ?>" style="max-width: 80%; min-width: 180px;">
                                                                <small class="fw-bold d-block mb-1">
                                                                    <i class="fas <?php echo $reply['role'] === 'admin' ? 'fa-user-shield' : 'fa-user'; ?> me-1"></i>
                                                                    <?php echo htmlspecialchars($reply['full_name'] ?: $reply['username']); ?>
                                                                    <span class="badge <?php echo $reply['role'] === 'admin' ? 'bg-light text-dark' : 'bg-secondary'; ?> ms-1"><?php echo ucfirst($reply['role']); ?></span>
                                                                </small>
                                                                <div><?php echo nl2br(htmlspecialchars($reply['message'])); ?></div>
                                                                <small class="<?php echo $reply['role'] === 'admin' ? 'text-white-50' : 'text-muted'; ?> d-block text-end mt-1">
                                                                    <?php echo date('M d, g:i A', strtotime($reply['created_at'])); ?>
                                                                </small>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <p class="text-muted text-center mb-2"><em>No replies yet.</em></p>
                                            <?php endif; ?>

                                            <!-- Tenant reply form -->
                                            <form method="POST" class="mt-2">
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
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4 complaints-empty">
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
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-plus me-2"></i>Submit New Complaint
                    </h5>
                </div>
                <div class="card-body complaint-form-body">
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="needs-validation" novalidate>
                        <?php echo csrf_input(); ?>
                        <div class="mb-3">
                            <label for="title" class="form-label">
                                <i class="fas fa-tag me-1"></i>Issue Title *
                            </label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   placeholder="e.g., Leaky faucet in kitchen" required>
                            <div class="invalid-feedback">
                                Please provide a title for your complaint.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">
                                <i class="fas fa-align-left me-1"></i>Description *
                            </label>
                            <textarea class="form-control" id="description" name="description" rows="4" 
                                      placeholder="Please describe the issue in detail..." required></textarea>
                            <div class="invalid-feedback">
                                Please provide a detailed description.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="complaint_date" class="form-label">
                                <i class="fas fa-calendar me-1"></i>Issue Date *
                            </label>
                            <input type="date" class="form-control" id="complaint_date" name="complaint_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                            <div class="invalid-feedback">
                                Please select when the issue occurred.
                            </div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="urgent" name="urgent">
                            <label class="form-check-label" for="urgent"><i class="fas fa-bolt me-1"></i>Mark as urgent</label>
                            <div class="form-text">Use this for active leaks, electrical hazards, or security issues.</div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" name="submit_complaint" class="btn btn-warning">
                                <i class="fas fa-paper-plane me-2"></i>Submit Complaint
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Common Issues -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-lightbulb me-2"></i>Common Issues
                    </h6>
                </div>
                <div class="card-body">
                    <small>
                        <ul class="mb-0">
                            <li>Plumbing issues (leaks, clogs)</li>
                            <li>Electrical problems</li>
                            <li>Heating/cooling issues</li>
                            <li>Appliance malfunctions</li>
                            <li>Pest control</li>
                            <li>Noise complaints</li>
                            <li>Security concerns</li>
                        </ul>
                    </small>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div class="card mt-3">
                <div class="card-header bg-danger text-white">
                    <h6 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Emergency Contact
                    </h6>
                </div>
                <div class="card-body">
                    <p class="mb-2"><strong>For urgent issues:</strong></p>
                    <p class="mb-1">
                        <i class="fas fa-phone me-2"></i>
                        <strong>Emergency Line:</strong> <?php echo htmlspecialchars($emergency_phone); ?>
                    </p>
                    <small class="text-muted">
                        Available 24/7 for water leaks, electrical hazards, security issues, etc.
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>