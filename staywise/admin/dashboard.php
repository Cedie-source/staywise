<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
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
$page_title = "Admin Dashboard";

// Get statistics
$stats = [];

// Total tenants (exclude soft-deleted)
$result = $conn->query("SELECT COUNT(*) as count FROM tenants WHERE deleted_at IS NULL");
$stats['tenants'] = $result->fetch_assoc()['count'];

// Pending payments
$result = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'");
$stats['pending_payments'] = $result->fetch_assoc()['count'];

// Pending complaints
$result = $conn->query("SELECT COUNT(*) as count FROM complaints WHERE status = 'pending'");
$stats['pending_complaints'] = $result->fetch_assoc()['count'];

// Total announcements
$result = $conn->query("SELECT COUNT(*) as count FROM announcements");
$stats['announcements'] = $result->fetch_assoc()['count'];

// Recent activities
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

// Chart data: monthly payment totals for last 6 months (verified only)
$chart_months = [];
$chart_amounts = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chart_months[] = date('M Y', strtotime($m . '-01'));
    $r = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status='verified' AND DATE_FORMAT(payment_date,'%Y-%m')='$m'");
    $chart_amounts[] = (float)($r->fetch_assoc()['total'] ?? 0);
}

// Chart data: payment status breakdown
$chart_statuses = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
$r = $conn->query("SELECT status, COUNT(*) as cnt FROM payments GROUP BY status");
if ($r) { while ($row = $r->fetch_assoc()) { $chart_statuses[$row['status']] = (int)$row['cnt']; } }

include '../includes/header.php';

// Load predictive analytics for dashboard widget
define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';
ai_ensure_tables($conn);
$aiSummary = ai_get_admin_summary($conn);
?>

<div class="container mt-4 admin-ui">
    <div class="p-4 mb-4 rounded-4 shadow-lg bg-gradient dashboard-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2 fw-bold dashboard-title">
                    <i class="fas fa-tachometer-alt me-3"></i>Admin Dashboard
                </h1>
                <p class="mb-1 fs-5 dashboard-title">Welcome back, <span class="fw-bold dashboard-title"><?php echo $_SESSION['username']; ?></span>!</p>
                <span class="opacity-75 dashboard-desc">Manage your rental properties efficiently</span>
            </div>
            <div class="col-md-4 text-md-end">
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo date('M d, Y'); ?></h2>
                <span class="fs-6 dashboard-desc"><?php echo date('l'); ?></span>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-users fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $stats['tenants']; ?></h2>
                <span class="fs-6 dashboard-title">Total Tenants</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-clock fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $stats['pending_payments']; ?></h2>
                <span class="fs-6 dashboard-title">Pending Payments</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-exclamation-triangle fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $stats['pending_complaints']; ?></h2>
                <span class="fs-6 dashboard-title">Pending Complaints</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-bullhorn fa-2x dashboard-title"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $stats['announcements']; ?></h2>
                <span class="fs-6 dashboard-title">Announcements</span>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line me-2"></i>Monthly Revenue (Last 6 Months)
                </div>
                <div class="card-body">
                    <canvas id="revenueChart" height="220"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie me-2"></i>Payment Status
                </div>
                <div class="card-body">
                    <canvas id="statusChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Payments -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-credit-card me-2"></i>Recent Payments
                </div>
                <div class="card-body">
                    <?php if ($recent_payments->num_rows > 0): ?>
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

        <!-- Recent Complaints -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-exclamation-triangle me-2"></i>Recent Complaints
                </div>
                <div class="card-body">
                    <?php if ($recent_complaints->num_rows > 0): ?>
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
                                        <small class="text-muted d-block"><?php echo htmlspecialchars(mb_strimwidth($complaint['description'], 0, 80, '...')); ?></small>
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

    <!-- AI Predictive Insights Widget -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card" style="border-left: 4px solid #4ED6C1;">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-brain me-2"></i>AI Predictive Insights</span>
                    <a href="ai_insights.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>Full Dashboard</a>
                </div>
                <div class="card-body">
                    <?php if ($aiSummary['stats']['active_predictions'] > 0 || $aiSummary['stats']['active_insights'] > 0): ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3 col-6 text-center">
                                <h4 class="fw-bold mb-0 dashboard-title"><?php echo $aiSummary['stats']['active_predictions']; ?></h4>
                                <small class="text-muted">Active Predictions</small>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <h4 class="fw-bold mb-0" style="color:#dc3545;"><?php echo $aiSummary['stats']['high_risk']; ?></h4>
                                <small class="text-muted">High Risk Alerts</small>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <h4 class="fw-bold mb-0 dashboard-title"><?php echo $aiSummary['stats']['active_insights']; ?></h4>
                                <small class="text-muted">Active Insights</small>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <h4 class="fw-bold mb-0 dashboard-title"><?php echo $aiSummary['stats']['patterns_detected']; ?></h4>
                                <small class="text-muted">Patterns Found</small>
                            </div>
                        </div>
                        <?php if (!empty($aiSummary['predictions'])): ?>
                            <h6 class="fw-bold mt-3 mb-2"><i class="fas fa-exclamation-triangle me-1 text-warning"></i>Top Predictions</h6>
                            <?php foreach (array_slice($aiSummary['predictions'], 0, 3) as $pred): ?>
                                <div class="d-flex align-items-center mb-2 p-2 rounded" style="background: rgba(78,214,193,0.05); border: 1px solid rgba(78,214,193,0.15);">
                                    <span class="badge bg-<?php echo $pred['risk_level'] === 'critical' ? 'danger' : ($pred['risk_level'] === 'high' ? 'warning text-dark' : 'info'); ?> me-2">
                                        <?php echo ucfirst($pred['risk_level']); ?>
                                    </span>
                                    <div class="flex-grow-1">
                                        <strong>Unit <?php echo htmlspecialchars($pred['unit_number']); ?></strong> -
                                        <span class="small"><?php echo htmlspecialchars(mb_strimwidth($pred['prediction_text'], 0, 100, '...')); ?></span>
                                    </div>
                                    <?php if ($pred['predicted_date']): ?>
                                        <small class="text-muted ms-2"><?php echo date('M d', strtotime($pred['predicted_date'])); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-brain fa-2x mb-2" style="color: #ccc;"></i>
                            <p class="mb-2 no-record-message">No predictive data yet</p>
                            <a href="ai_insights.php" class="btn btn-sm btn-primary"><i class="fas fa-sync-alt me-1"></i>Run Analysis</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity Feed -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-stream me-2"></i>Recent Activity
                </div>
                <div class="card-body">
                    <ul class="list-group">
                        <?php
                        // Get latest actions (payments, complaints, announcements, tenants)
                        $activities = [];
                        $q1 = $conn->query("SELECT 'Payment' as type, created_at, CONCAT('Payment of ₱', amount, ' by ', (SELECT name FROM tenants WHERE tenant_id = p.tenant_id)) as detail FROM payments p ORDER BY created_at DESC LIMIT 3");
                        while ($row = $q1->fetch_assoc()) $activities[] = $row;
                        $q2 = $conn->query("SELECT 'Complaint' as type, created_at, CONCAT('Complaint: ', title, ' by ', (SELECT name FROM tenants WHERE tenant_id = c.tenant_id)) as detail FROM complaints c ORDER BY created_at DESC LIMIT 3");
                        while ($row = $q2->fetch_assoc()) $activities[] = $row;
                        $q3 = $conn->query("SELECT 'Announcement' as type, created_at, CONCAT('Announcement: ', title) as detail FROM announcements ORDER BY created_at DESC LIMIT 2");
                        while ($row = $q3->fetch_assoc()) $activities[] = $row;
                        $q4 = $conn->query("SELECT 'Tenant' as type, created_at, CONCAT('New tenant: ', name) as detail FROM tenants ORDER BY created_at DESC LIMIT 2");
                        while ($row = $q4->fetch_assoc()) $activities[] = $row;
                        // Sort by created_at desc
                        usort($activities, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
                        $activities = array_slice($activities, 0, 8);
                        foreach ($activities as $act): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><strong><?php echo $act['type']; ?>:</strong> <?php echo htmlspecialchars($act['detail']); ?></span>
                                <span class="badge bg-info text-dark ms-2 recent-activity-date"><?php echo date('M d, Y g:i A', strtotime($act['created_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Revenue line chart
    var revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_months); ?>,
                datasets: [{
                    label: 'Verified Revenue (₱)',
                    data: <?php echo json_encode($chart_amounts); ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) { return '₱' + v.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }

    // Payment status doughnut chart
    var statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Verified', 'Rejected'],
                datasets: [{
                    data: [<?php echo $chart_statuses['pending']; ?>, <?php echo $chart_statuses['verified']; ?>, <?php echo $chart_statuses['rejected']; ?>],
                    backgroundColor: ['#ffc107', '#198754', '#dc3545'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>