<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Super admin gate
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin' || (strtolower($_SESSION['admin_role'] ?? '') !== 'super_admin')) {
    header("Location: ../index.php");
    exit();
}

// Hard guard: if user's force_password_change is set, redirect to change password
try {
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'force_password_change')) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && intval($row['force_password_change']) === 1) {
                $_SESSION['must_change_password'] = true;
                header('Location: ../change_password.php');
                exit();
            }
        }
    }
} catch (Throwable $e) { /* ignore */ }

$page_title = "Super Admin Dashboard";

// Reuse same stats as admin dashboard
$stats = [];
$stats['tenants'] = ($conn->query("SELECT COUNT(*) as count FROM tenants WHERE deleted_at IS NULL")->fetch_assoc()['count']) ?? 0;
$stats['pending_payments'] = ($conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count']) ?? 0;
$stats['pending_complaints'] = ($conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'pending'")->fetch_assoc()['count']) ?? 0;
$stats['announcements'] = ($conn->query("SELECT COUNT(*) as count FROM announcements")->fetch_assoc()['count']) ?? 0;

$recent_payments = $conn->query("
    SELECT p.*, t.name, t.unit_number 
    FROM payments p 
    JOIN tenants t ON p.tenant_id = t.tenant_id 
    ORDER BY p.created_at DESC 
    LIMIT 5
");

$recent_complaints = $conn->query("
    SELECT c.*, t.name, t.unit_number 
    FROM complaints c 
    JOIN tenants t ON c.tenant_id = t.tenant_id 
    ORDER BY c.created_at DESC 
    LIMIT 5
");

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <div class="p-4 mb-4 rounded-4 shadow-lg bg-gradient dashboard-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2 fw-bold dashboard-title">
                    Super Admin Dashboard
                </h1>
                <p class="mb-1 fs-5 dashboard-title">Welcome, <span class="fw-bold dashboard-title"><?php echo htmlspecialchars($_SESSION['username']); ?></span></p>
                <span class="badge bg-warning text-dark">Super Admin</span>
            </div>
            <div class="col-md-4 text-md-end">
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo date('M d, Y'); ?></h2>
                <span class="fs-6 dashboard-desc"><?php echo date('l'); ?></span>
            </div>
        </div>
    </div>

    <!-- Statistics Cards (NO HOVER) -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-users fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo (int)$stats['tenants']; ?></h2>
                <span class="fs-6 dashboard-title">Total Tenants</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-clock fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo (int)$stats['pending_payments']; ?></h2>
                <span class="fs-6 dashboard-title">Pending Payments</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-exclamation-triangle fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo (int)$stats['pending_complaints']; ?></h2>
                <span class="fs-6 dashboard-title">Pending Complaints</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-bullhorn fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo (int)$stats['announcements']; ?></h2>
                <span class="fs-6 dashboard-title">Announcements</span>
            </div>
        </div>
    </div>

    <!-- Navigation/Menu Cards (WITH HOVER EFFECT) -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="admin_management.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-users-cog fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Admins</div>
                    <small class="dashboard-desc">Manage administrators</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="tenants.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-users fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Tenants</div>
                    <small class="dashboard-desc">Manage tenants</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="payments.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-credit-card fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Payments</div>
                    <small class="dashboard-desc">Review & verify</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="complaints.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-exclamation-triangle fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Complaints</div>
                    <small class="dashboard-desc">Track & resolve</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="announcements.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-bullhorn fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Announcements</div>
                    <small class="dashboard-desc">Post updates</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="system_settings.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-cogs fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Settings</div>
                    <small class="dashboard-desc">App configuration</small>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-6">
            <a class="card menu-card text-decoration-none" href="reports.php">
                <div class="card-body text-center">
                    <div class="mb-2"><i class="fas fa-chart-line fa-2x dashboard-title"></i></div>
                    <div class="fw-bold dashboard-title">Reports</div>
                    <small class="dashboard-desc">Overview & export</small>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Activity (NO HOVER) -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-credit-card me-2"></i>Recent Payments
                </div>
                <div class="card-body">
                    <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>
                        <ul class="list-group admin-recent-list">
                            <?php while ($payment = $recent_payments->fetch_assoc()): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div class="me-2">
                                        <strong><?php echo htmlspecialchars($payment['name']); ?></strong><br>
                                        <small class="text-muted">Unit <?php echo htmlspecialchars($payment['unit_number']); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="admin-recent-amount">₱<?php echo number_format((float)$payment['amount'], 2); ?></div>
                                        <span class="badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($payment['status']))); ?>"><?php echo ucfirst($payment['status']); ?></span>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                        <div class="text-center mt-3">
                            <a href="payments.php" class="btn btn-outline-primary btn-sm">View All Payments</a>
                        </div>
                    <?php else: ?>
                        <p class="no-record-message text-center">No recent payments</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exclamation-triangle me-2"></i>Recent Complaints
                </div>
                <div class="card-body">
                    <?php if ($recent_complaints && $recent_complaints->num_rows > 0): ?>
                        <ul class="list-group admin-recent-list">
                            <?php while ($complaint = $recent_complaints->fetch_assoc()): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="me-3 flex-grow-1">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <strong><?php echo htmlspecialchars($complaint['title']); ?></strong>
                                            <?php if (isset($complaint['urgent']) && (int)$complaint['urgent'] === 1): ?>
                                                <span class="badge bg-danger"><i class="fas fa-bolt me-1"></i>Urgent</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($complaint['name']); ?> • Unit <?php echo htmlspecialchars($complaint['unit_number']); ?></small>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars(mb_strimwidth($complaint['description'] ?? '', 0, 80, '...')); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($complaint['status']))); ?>"><?php echo ucfirst($complaint['status']); ?></span>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                        <div class="text-center mt-3">
                            <a href="complaints.php" class="btn btn-outline-primary btn-sm">View All Complaints</a>
                        </div>
                    <?php else: ?>
                        <p class="no-record-message text-center">No recent complaints</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>