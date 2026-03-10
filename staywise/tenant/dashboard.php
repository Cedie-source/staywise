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

$page_title = "Tenant Dashboard";

// Get tenant information
$tenant_stmt = $conn->prepare("SELECT * FROM tenants WHERE user_id = ?");
$tenant_stmt->bind_param("i", $_SESSION['user_id']);
$tenant_stmt->execute();
$tenant_result = $tenant_stmt->get_result();
$tenant = $tenant_result->fetch_assoc();

if (!$tenant) {
    header("Location: ../logout.php");
    exit();
}

$_SESSION['tenant_id'] = $tenant['tenant_id'];

// Get tenant statistics
$stats = [];

// --- Monthly rent due date and status ---
// Assumption: Rent is due on the 1st of each month unless tenant-specific due_day exists.
$RENT_DUE_DAY = 1; // default monthly due day (1..28 recommended)

$now = new DateTime('now');
$currentMonthStart = new DateTime($now->format('Y-m-01'));
$currentMonthEnd = new DateTime($now->format('Y-m-t'));
// Use per-tenant due_day if available, otherwise default
$tenantDueDay = isset($tenant['due_day']) && (int)$tenant['due_day'] > 0 ? (int)$tenant['due_day'] : $RENT_DUE_DAY;
$daysInMonth = (int)$now->format('t');
$dueDayUsed = max(1, min($tenantDueDay, $daysInMonth));
$dueDate = new DateTime($now->format('Y-m-') . str_pad($dueDayUsed, 2, '0', STR_PAD_LEFT));

// Sum of verified payments for this tenant in the current month
$paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total_paid FROM payments WHERE tenant_id = ? AND status = 'verified' AND payment_date BETWEEN ? AND ?");
$startStr = $currentMonthStart->format('Y-m-d');
$endStr = $currentMonthEnd->format('Y-m-d');
$paid_stmt->bind_param("iss", $tenant['tenant_id'], $startStr, $endStr);
$paid_stmt->execute();
$paid_res = $paid_stmt->get_result();
$paid_row = $paid_res->fetch_assoc();
$total_paid_this_month = (float)($paid_row['total_paid'] ?? 0);
$paid_stmt->close();

// Determine status using rent amount and payments
$rentAmount = (float)($tenant['rent_amount'] ?? 0);
$today = new DateTime('today');
$rentStatus = 'Pending';
if ($rentAmount > 0) {
    if ($total_paid_this_month >= $rentAmount - 0.01) { // allow tiny rounding tolerance
        $rentStatus = 'Paid';
    } elseif ($total_paid_this_month > 0 && $total_paid_this_month < $rentAmount) {
        $rentStatus = ($today > $dueDate) ? 'Overdue' : 'Partial';
    } else { // no payment yet
        $rentStatus = ($today > $dueDate) ? 'Overdue' : 'Pending';
    }
} else {
    // No configured rent amount; consider paid if any verified payment exists
    $rentStatus = $total_paid_this_month > 0 ? 'Paid' : (($today > $dueDate) ? 'Overdue' : 'Pending');
}

// Days until due (negative if past due)
$daysUntilDue = (int)$today->diff($dueDate)->format('%r%a');

// Choose badge color for due date based on status
$dueBadgeClass = 'bg-secondary';
if ($rentStatus === 'Paid') {
    $dueBadgeClass = 'bg-success';
} elseif ($rentStatus === 'Overdue') {
    $dueBadgeClass = 'bg-danger';
} elseif ($rentStatus === 'Partial') {
    $dueBadgeClass = 'bg-info text-dark';
} else { // Pending
    $dueBadgeClass = 'bg-warning text-dark';
}

// Outstanding balance (remaining due for the current month)
$remaining_this_month = 0.0;
if ($rentAmount > 0) {
    $remaining_this_month = max(0.0, round($rentAmount - $total_paid_this_month, 2));
}
$stats['balance'] = $remaining_this_month;

// Choose colors for the "Unpaid This Month" card (bring back colored UI)
$unpaid = $stats['balance'];
$balanceBg = '#f8f9fa'; // default light
$balanceText = '#212529';
if ($unpaid <= 0.009) { // effectively zero
    // Success subtle
    $balanceBg = '#d1e7dd';
    $balanceText = '#0f5132';
} elseif ($rentStatus === 'Overdue') {
    // Danger subtle
    $balanceBg = '#f8d7da';
    $balanceText = '#842029';
} elseif ($rentStatus === 'Partial') {
    // Info subtle
    $balanceBg = '#cff4fc';
    $balanceText = '#055160';
} else { // Pending
    // Warning subtle
    $balanceBg = '#fff3cd';
    $balanceText = '#664d03';
}

// Total payments made
$payments_stmt = $conn->prepare("SELECT COUNT(*) as count FROM payments WHERE tenant_id = ?");
$payments_stmt->bind_param("i", $tenant['tenant_id']);
$payments_stmt->execute();
$payments_result = $payments_stmt->get_result();
$stats['total_payments'] = $payments_result->fetch_assoc()['count'];

// Pending complaints
$complaints_stmt = $conn->prepare("SELECT COUNT(*) as count FROM complaints WHERE tenant_id = ? AND status != 'resolved'");
$complaints_stmt->bind_param("i", $tenant['tenant_id']);
$complaints_stmt->execute();
$complaints_result = $complaints_stmt->get_result();
$stats['pending_complaints'] = $complaints_result->fetch_assoc()['count'];

// Recent announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 3");

// Recent payments
$recent_payments_stmt = $conn->prepare("SELECT * FROM payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
$recent_payments_stmt->bind_param("i", $tenant['tenant_id']);
$recent_payments_stmt->execute();
$recent_payments = $recent_payments_stmt->get_result();

// === Dynamic Monthly Calendar Data ===
$cal_month = isset($_GET['cal_month']) ? $_GET['cal_month'] : $now->format('Y-m');
// Validate cal_month format
if (!preg_match('/^\d{4}-\d{2}$/', $cal_month)) {
    $cal_month = $now->format('Y-m');
}
$cal_date = new DateTime($cal_month . '-01');
$cal_year = (int)$cal_date->format('Y');
$cal_month_num = (int)$cal_date->format('m');
$cal_days_in_month = (int)$cal_date->format('t'); // Dynamic days!
$cal_first_day_of_week = (int)$cal_date->format('w'); // 0=Sun, 6=Sat
$cal_month_label = $cal_date->format('F Y');
$cal_prev_month = (clone $cal_date)->modify('-1 month')->format('Y-m');
$cal_next_month = (clone $cal_date)->modify('+1 month')->format('Y-m');

// Get lease start date
$leaseStartDate = !empty($tenant['lease_start_date']) ? $tenant['lease_start_date'] : $tenant['created_at'];
$leaseStartObj = new DateTime(substr($leaseStartDate, 0, 10));

// Get all payments for this tenant in the calendar month
$cal_payments = [];
$cal_start = $cal_date->format('Y-m-01');
$cal_end = $cal_date->format('Y-m-t');
$cal_pay_stmt = $conn->prepare("SELECT payment_id, amount, payment_date, status, payment_type, for_month FROM payments WHERE tenant_id = ? AND payment_date BETWEEN ? AND ?");
$cal_pay_stmt->bind_param("iss", $tenant['tenant_id'], $cal_start, $cal_end);
$cal_pay_stmt->execute();
$cal_pay_res = $cal_pay_stmt->get_result();
while ($cp = $cal_pay_res->fetch_assoc()) {
    $day = (int)date('d', strtotime($cp['payment_date']));
    $cal_payments[$day][] = $cp;
}
$cal_pay_stmt->close();

// Determine due day for calendar highlighting
$calDueDay = isset($tenant['due_day']) && (int)$tenant['due_day'] > 0 ? (int)$tenant['due_day'] : 1;
$calDueDayUsed = min($calDueDay, $cal_days_in_month);

// Check if rent is paid for calendar month
$cal_paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE tenant_id = ? AND status = 'verified' AND for_month = ?");
$cal_for_month = $cal_date->format('Y-m');
$cal_paid_stmt->bind_param("is", $tenant['tenant_id'], $cal_for_month);
$cal_paid_stmt->execute();
$cal_paid_res = $cal_paid_stmt->get_result();
$cal_paid_total = (float)($cal_paid_res->fetch_assoc()['total'] ?? 0);
$cal_paid_stmt->close();
$cal_rent_paid = ($rentAmount > 0 && $cal_paid_total >= $rentAmount - 0.01);

// Load proactive AI notifications & predictions
define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';
ai_ensure_tables($conn);

$dash_alerts = [];
$pStmt = $conn->prepare(
    "SELECT title, message, type, priority FROM ai_notifications WHERE user_id = ? AND is_read = 0 ORDER BY priority DESC, created_at DESC LIMIT 5"
);
$pStmt->bind_param('i', $_SESSION['user_id']);
$pStmt->execute();
$pRes = $pStmt->get_result();
while ($row = $pRes->fetch_assoc()) $dash_alerts[] = $row;
$pStmt->close();

$dash_predictions = [];
$dpStmt = $conn->prepare(
    "SELECT category, risk_level, prediction_text, predicted_date FROM ai_predictions WHERE unit_number = ? AND status = 'active' ORDER BY predicted_date ASC LIMIT 3"
);
$dpStmt->bind_param('s', $tenant['unit_number']);
$dpStmt->execute();
$dpRes = $dpStmt->get_result();
while ($row = $dpRes->fetch_assoc()) $dash_predictions[] = $row;
$dpStmt->close();

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui tenant-ui">
    <div class="p-4 mb-4 rounded-4 shadow-lg bg-gradient dashboard-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2 fw-bold dashboard-title">
                    <i class="fas fa-home me-3"></i>Tenant Dashboard
                </h1>
                <p class="mb-1 fs-5 dashboard-title">Welcome back, <span class="fw-bold dashboard-title"><?php echo htmlspecialchars($tenant['name']); ?></span>!</p>
                <span class="opacity-75 dashboard-desc">Unit <?php echo htmlspecialchars($tenant['unit_number']); ?> • Manage your rental account and requests</span>
            </div>
            <div class="col-md-4 text-md-end">
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo date('M d, Y'); ?></h2>
                <span class="fs-6 dashboard-desc"><?php echo date('l'); ?></span>
            </div>
        </div>
    </div>

    <!-- Current Billing Cycle -->
    <?php
        // Calculate billing cycle based on lease start day
        $leaseStartDay = !empty($tenant['lease_start_date']) ? (int)date('d', strtotime($tenant['lease_start_date'])) : 1;
        $todayObj = new DateTime('today');
        $currentDay = (int)$todayObj->format('d');

        // Billing cycle: starts on lease_start_day of current or previous month
        if ($currentDay >= $leaseStartDay) {
            // We're past the start day, so cycle is this month's start day → next month's start day
            $cycleStart = new DateTime($todayObj->format('Y-m-') . str_pad($leaseStartDay, 2, '0', STR_PAD_LEFT));
            $cycleEnd = (clone $cycleStart)->modify('+1 month');
        } else {
            // We haven't reached the start day yet, so cycle is last month's start day → this month's start day
            $cycleEnd = new DateTime($todayObj->format('Y-m-') . str_pad($leaseStartDay, 2, '0', STR_PAD_LEFT));
            $cycleStart = (clone $cycleEnd)->modify('-1 month');
        }
    ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4" style="background: #f0f4ff;">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between py-3 px-4">
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <i class="fas fa-calendar-alt fa-lg text-primary me-3"></i>
                <div>
                    <span class="fw-bold text-dark">Billing Start:</span>
                    <span class="ms-1 fw-semibold"><?php echo $cycleStart->format('F d, Y'); ?></span>
                </div>
            </div>
            <div class="d-flex align-items-center">
                <i class="fas fa-calendar-check fa-lg text-success me-3"></i>
                <div>
                    <span class="fw-bold text-dark">Billing End:</span>
                    <span class="ms-1 fw-semibold"><?php echo $cycleEnd->format('F d, Y'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <!-- Monthly Rent Status Card -->
        <div class="col-12">
            <div class="card border-0 shadow-lg rounded-4 py-3" style="background: #fff; color: #212529;">
                <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                        <strong>Rent for <?php echo $now->format('F Y'); ?></strong>
                        <span class="ms-2">Due Date: <span class="badge <?php echo $dueBadgeClass; ?>"><?php echo $dueDate->format('M d, Y'); ?></span></span>
                        <?php if ($rentAmount > 0): ?>
                            <span class="ms-3 small text-muted">Paid: ₱<?php echo number_format($total_paid_this_month, 2); ?> / ₱<?php echo number_format($rentAmount, 2); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($rentStatus === 'Paid'): ?>
                            <span class="badge bg-success px-3 py-2">Paid</span>
                        <?php elseif ($rentStatus === 'Overdue'): ?>
                            <span class="badge bg-danger px-3 py-2">Overdue</span>
                        <?php elseif ($rentStatus === 'Partial'): ?>
                            <span class="badge bg-info text-dark px-3 py-2">Partial</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark px-3 py-2">Pending</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($rentStatus === 'Overdue'): ?>
                    <div class="alert alert-danger mb-0 mx-3 mt-2">
                        <i class="fas fa-exclamation-triangle me-2"></i>Your rent for this month is overdue. Please settle immediately.
                    </div>
                <?php elseif (($rentStatus === 'Pending' || $rentStatus === 'Partial') && $daysUntilDue >= 0 && $daysUntilDue <= 3): ?>
                    <div class="alert alert-warning mb-0 mx-3 mt-2">
                        <i class="fas fa-clock me-2"></i>Your rent is due in <?php echo $daysUntilDue; ?> day<?php echo $daysUntilDue == 1 ? '' : 's'; ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card border-0 shadow-lg rounded-4 text-center py-4" style="background: <?php echo $balanceBg; ?>; color: #000;">
                <div class="mb-2"><i class="fas fa-wallet fa-2x"></i></div>
                <h2 class="fw-bold mb-0">₱<?php echo number_format($stats['balance'], 2); ?></h2>
                <span class="fs-6">Outstanding Balance</span>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card border-0 shadow-lg rounded-4 text-center py-4" style="background: #e3f7fc; color: #000;">
                <div class="mb-2"><i class="fas fa-credit-card fa-2x"></i></div>
                <h2 class="fw-bold mb-0"><?php echo $stats['total_payments']; ?></h2>
                <span class="fs-6">Total Payments</span>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card border-0 shadow-lg rounded-4 text-center py-4" style="background: #f8d7da; color: #000;">
                <div class="mb-2"><i class="fas fa-exclamation-triangle fa-2x"></i></div>
                <h2 class="fw-bold mb-0"><?php echo $stats['pending_complaints']; ?></h2>
                <span class="fs-6">Open Complaints</span>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content: Announcements + Payments -->
        <div class="<?php echo (!empty($dash_predictions) || !empty($dash_alerts)) ? 'col-lg-8' : 'col-12'; ?>">
        <div class="row">
        <!-- Recent Announcements -->
        <div class="<?php echo (!empty($dash_predictions) || !empty($dash_alerts)) ? 'col-md-6 mb-4' : 'col-md-6 mb-4'; ?>">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-bullhorn me-2"></i>Recent Announcements
                </div>
                <div class="card-body">
                    <?php if ($announcements->num_rows > 0): ?>
                        <ul class="list-group admin-recent-list">
                            <?php while ($announcement = $announcements->fetch_assoc()): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start">
                                    <div class="me-3 flex-grow-1">
                                        <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                        <p class="mb-1 small"><?php echo htmlspecialchars(mb_strimwidth($announcement['content'], 0, 100, '...')); ?></p>
                                        <?php
                                            $rawPosted = !empty($announcement['created_at']) ? $announcement['created_at'] : ($announcement['announcement_date'] ?? null);
                                            $postedText = $rawPosted ? date('M d, Y g:i A', strtotime($rawPosted)) : '';
                                        ?>
                                        <small class="text-muted"><i class="fas fa-clock me-1"></i><?php echo $postedText ? ('Posted: ' . $postedText) : 'Posted: N/A'; ?></small>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                        <div class="text-center mt-3">
                            <a href="announcements.php" class="btn btn-outline-primary btn-sm">View All Announcements</a>
                        </div>
                    <?php else: ?>
                        <p class="no-record-message text-center">No announcements available</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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
                                        <strong><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></strong>
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
                        <p class="no-record-message text-center">No payment history</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </div><!-- /inner row -->

        <!-- Dynamic Monthly Rent Calendar -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Rent Calendar</h5>
                    <div class="d-flex align-items-center gap-2">
                        <a href="?cal_month=<?php echo $cal_prev_month; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-chevron-left"></i></a>
                        <span class="fw-bold"><?php echo $cal_month_label; ?></span>
                        <a href="?cal_month=<?php echo $cal_next_month; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
                <div class="card-body p-2 p-md-3">
                    <?php if (!empty($tenant['lease_start_date'])): ?>
                        <div class="small text-muted mb-2">
                            <i class="fas fa-info-circle me-1"></i>Lease started: <?php echo date('M d, Y', strtotime($tenant['lease_start_date'])); ?>
                            | Due day: <?php echo $calDueDayUsed; ?> of each month
                            <?php if ($cal_rent_paid): ?>
                                | <span class="text-success fw-bold"><i class="fas fa-check-circle me-1"></i>Rent Paid for <?php echo $cal_date->format('F'); ?></span>
                            <?php elseif ($cal_paid_total > 0): ?>
                                | <span class="text-warning fw-bold">Partial: ₱<?php echo number_format($cal_paid_total, 2); ?> of ₱<?php echo number_format($rentAmount, 2); ?></span>
                            <?php else: ?>
                                | <span class="text-danger fw-bold">Unpaid for <?php echo $cal_date->format('F'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                        <table class="table table-bordered text-center rent-calendar mb-0">
                            <thead>
                                <tr>
                                    <th class="text-danger">Sun</th>
                                    <th>Mon</th>
                                    <th>Tue</th>
                                    <th>Wed</th>
                                    <th>Thu</th>
                                    <th>Fri</th>
                                    <th class="text-primary">Sat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $dayCounter = 1;
                                $todayStr = $today->format('Y-m-d');
                                $leaseStartStr = $leaseStartObj->format('Y-m-d');
                                $totalCells = $cal_first_day_of_week + $cal_days_in_month;
                                $totalRows = ceil($totalCells / 7);
                                
                                for ($row = 0; $row < $totalRows; $row++):
                                ?>
                                <tr>
                                    <?php for ($col = 0; $col < 7; $col++):
                                        $cellIndex = $row * 7 + $col;
                                        if ($cellIndex < $cal_first_day_of_week || $dayCounter > $cal_days_in_month):
                                    ?>
                                        <td class="cal-empty"></td>
                                    <?php else:
                                        $currentDayStr = $cal_date->format('Y-m-') . str_pad($dayCounter, 2, '0', STR_PAD_LEFT);
                                        $isToday = ($currentDayStr === $todayStr);
                                        $isDueDay = ($dayCounter === $calDueDayUsed);
                                        $isLeaseStart = ($currentDayStr === $leaseStartStr);
                                        $isBeforeLease = ($currentDayStr < $leaseStartStr);
                                        $hasPayment = isset($cal_payments[$dayCounter]);
                                        
                                        $cellClass = 'cal-day';
                                        if ($isToday) $cellClass .= ' cal-today';
                                        if ($isDueDay) $cellClass .= ' cal-due-day';
                                        if ($isLeaseStart) $cellClass .= ' cal-lease-start';
                                        if ($isBeforeLease) $cellClass .= ' cal-before-lease';
                                        if ($hasPayment) $cellClass .= ' cal-has-payment';
                                    ?>
                                        <td class="<?php echo $cellClass; ?>">
                                            <div class="cal-day-number"><?php echo $dayCounter; ?></div>
                                            <?php if ($isDueDay && !$isBeforeLease): ?>
                                                <div class="cal-marker cal-marker-due" title="Rent Due"><i class="fas fa-bell"></i></div>
                                            <?php endif; ?>
                                            <?php if ($isLeaseStart): ?>
                                                <div class="cal-marker cal-marker-start" title="Lease Start"><i class="fas fa-flag"></i></div>
                                            <?php endif; ?>
                                            <?php if ($hasPayment):
                                                foreach ($cal_payments[$dayCounter] as $cp):
                                                    $pClass = $cp['status'] === 'verified' ? 'success' : ($cp['status'] === 'pending' ? 'warning' : 'danger');
                                                    $pType = !empty($cp['payment_type']) && $cp['payment_type'] !== 'rent' ? ' (' . ucfirst($cp['payment_type']) . ')' : '';
                                            ?>
                                                <div class="cal-payment badge bg-<?php echo $pClass; ?>" title="₱<?php echo number_format($cp['amount'], 2); ?><?php echo $pType; ?> - <?php echo ucfirst($cp['status']); ?>">
                                                    ₱<?php echo number_format($cp['amount'], 0); ?>
                                                </div>
                                            <?php endforeach; endif; ?>
                                        </td>
                                    <?php
                                        $dayCounter++;
                                        endif;
                                    endfor; ?>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap gap-3 mt-2 small">
                        <span><span class="cal-legend cal-legend-today"></span> Today</span>
                        <span><span class="cal-legend cal-legend-due"></span> Due Day</span>
                        <span><span class="cal-legend cal-legend-start"></span> Lease Start</span>
                        <span><span class="cal-legend cal-legend-paid"></span> Payment</span>
                    </div>
                </div>
            </div>
        </div>

        </div><!-- /col-lg-8 -->

        <!-- Proactive Advisories Side Panel -->
        <?php if (!empty($dash_predictions) || !empty($dash_alerts)): ?>
        <div class="col-lg-4 mb-4">
            <div class="advisory-panel">
                <div class="advisory-panel-header">
                    <div class="d-flex align-items-center">
                        <div class="advisory-icon-badge me-2">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">Proactive Advisories</h6>
                            <small class="advisory-subtitle">For Your Unit</small>
                        </div>
                    </div>
                </div>
                <div class="advisory-panel-body">
                    <?php
                    // Category icon mapping
                    $cat_icons = [
                        'plumbing'    => 'fa-faucet',
                        'electrical'  => 'fa-bolt',
                        'hvac'        => 'fa-fan',
                        'structural'  => 'fa-building',
                        'pest'        => 'fa-bug',
                        'appliance'   => 'fa-blender',
                        'general'     => 'fa-wrench',
                        'payment'     => 'fa-file-invoice-dollar',
                    ];
                    ?>
                    <?php foreach ($dash_predictions as $pred): ?>
                        <?php
                            $cat_key = strtolower($pred['category']);
                            $cat_icon = $cat_icons[$cat_key] ?? 'fa-exclamation-circle';
                            $risk = $pred['risk_level'];
                            $risk_class = ($risk === 'high' || $risk === 'critical') ? 'danger' : ($risk === 'medium' ? 'warning' : 'info');
                        ?>
                        <div class="advisory-item">
                            <div class="d-flex align-items-start">
                                <div class="advisory-cat-icon advisory-cat-<?php echo $risk_class; ?> me-3 mt-1">
                                    <i class="fas <?php echo $cat_icon; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold small"><?php echo ucfirst(htmlspecialchars($pred['category'])); ?></span>
                                        <span class="badge advisory-badge-<?php echo $risk_class; ?>"><?php echo ucfirst($risk); ?></span>
                                    </div>
                                    <p class="mb-1 small advisory-text"><?php echo htmlspecialchars($pred['prediction_text']); ?></p>
                                    <?php if ($pred['predicted_date']): ?>
                                        <div class="advisory-date">
                                            <i class="fas fa-calendar-day me-1"></i>Expected: <?php echo date('M d, Y', strtotime($pred['predicted_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($dash_alerts as $alert): ?>
                        <?php $a_class = $alert['priority'] === 'high' ? 'danger' : ($alert['priority'] === 'medium' ? 'warning' : 'info'); ?>
                        <div class="advisory-item">
                            <div class="d-flex align-items-start">
                                <div class="advisory-cat-icon advisory-cat-<?php echo $a_class; ?> me-3 mt-1">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold small"><?php echo htmlspecialchars($alert['title']); ?></span>
                                        <span class="badge advisory-badge-<?php echo $a_class; ?>"><?php echo ucfirst($alert['type']); ?></span>
                                    </div>
                                    <p class="mb-0 small advisory-text"><?php echo htmlspecialchars(mb_strimwidth($alert['message'], 0, 150, '...')); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="advisory-panel-footer">
                    <a href="chatbot.php" class="btn btn-sm advisory-ask-btn w-100">
                        <i class="fas fa-robot me-1"></i>Ask AI About These
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /row -->

</div>

<style>
/* ========== Advisory Side Panel ========== */
.advisory-panel {
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    background: #ffffff;
    position: sticky;
    top: 90px;
}
.advisory-panel-header {
    padding: .85rem 1rem;
    background: linear-gradient(135deg, #f0fdfa 0%, #e0f7f3 100%);
    border-bottom: 1px solid #d1fae5;
}
.advisory-icon-badge {
    width: 36px; height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #4ED6C1, #38b2a0);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: .85rem;
    flex-shrink: 0;
}
.advisory-subtitle {
    color: #64748b;
    font-size: .75rem;
}
.advisory-panel-body {
    padding: .75rem;
    max-height: 420px;
    overflow-y: auto;
}
.advisory-item {
    padding: .7rem .6rem;
    border-radius: 10px;
    margin-bottom: .5rem;
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    transition: box-shadow .2s, border-color .2s;
}
.advisory-item:last-child { margin-bottom: 0; }
.advisory-item:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

/* Category icon circles */
.advisory-cat-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    flex-shrink: 0;
}
.advisory-cat-danger  { background: #fee2e2; color: #dc2626; }
.advisory-cat-warning { background: #fef3c7; color: #d97706; }
.advisory-cat-info    { background: #dbeafe; color: #2563eb; }

/* Risk badges */
.advisory-badge-danger  { background: #fecaca; color: #991b1b; font-weight: 600; font-size: .7rem; }
.advisory-badge-warning { background: #fde68a; color: #92400e; font-weight: 600; font-size: .7rem; }
.advisory-badge-info    { background: #bfdbfe; color: #1e40af; font-weight: 600; font-size: .7rem; }

.advisory-text  { color: #475569; line-height: 1.4; }
.advisory-date  { font-size: .75rem; color: #0d9488; font-weight: 500; }

.advisory-panel-footer {
    padding: .65rem .75rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}
.advisory-ask-btn {
    background: linear-gradient(135deg, #4ED6C1, #38b2a0);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .82rem;
    transition: box-shadow .2s, transform .15s;
}
.advisory-ask-btn:hover {
    color: #fff;
    box-shadow: 0 4px 14px rgba(78,214,193,0.35);
    transform: translateY(-1px);
}

/* ===== Dark Mode ===== */
body.dark-mode .advisory-panel {
    background: #1c2130 !important;
    border-color: #2a3040 !important;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3) !important;
}
/* Nuclear: force ALL text/icons inside the panel to white first */
body.dark-mode .advisory-panel,
body.dark-mode .advisory-panel *:not(.advisory-cat-danger):not(.advisory-cat-warning):not(.advisory-cat-info):not(.advisory-badge-danger):not(.advisory-badge-warning):not(.advisory-badge-info):not(.advisory-subtitle):not(.advisory-text):not(.advisory-date):not(.advisory-ask-btn) {
    color: #ffffff !important;
}
body.dark-mode .advisory-panel-header {
    background: linear-gradient(135deg, #162029 0%, #1a2838 100%) !important;
    border-color: #2a3040 !important;
}
body.dark-mode .advisory-icon-badge {
    color: #fff !important;
}
body.dark-mode .advisory-subtitle { color: #94a3b8 !important; }
body.dark-mode .advisory-text     { color: #94a3b8 !important; }
body.dark-mode .advisory-date,
body.dark-mode .advisory-date *   { color: #4ED6C1 !important; }
body.dark-mode .advisory-item {
    background: #141820 !important;
    border-color: #232a38 !important;
}
body.dark-mode .advisory-item:hover {
    border-color: #3a4558 !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2) !important;
}
body.dark-mode .advisory-cat-danger,
body.dark-mode .advisory-cat-danger i  { background: rgba(220,38,38,0.15) !important; color: #fca5a5 !important; }
body.dark-mode .advisory-cat-warning,
body.dark-mode .advisory-cat-warning i { background: rgba(217,119,6,0.15) !important; color: #fcd34d !important; }
body.dark-mode .advisory-cat-info,
body.dark-mode .advisory-cat-info i    { background: rgba(37,99,235,0.15) !important; color: #93c5fd !important; }
body.dark-mode .advisory-badge-danger  { background: rgba(254,202,202,0.15) !important; color: #fca5a5 !important; }
body.dark-mode .advisory-badge-warning { background: rgba(253,230,138,0.15) !important; color: #fcd34d !important; }
body.dark-mode .advisory-badge-info    { background: rgba(191,219,254,0.15) !important; color: #93c5fd !important; }
body.dark-mode .advisory-panel-footer {
    background: #141820 !important;
    border-color: #2a3040 !important;
}
body.dark-mode .advisory-ask-btn {
    background: linear-gradient(135deg, #4ED6C1, #38b2a0) !important;
    color: #0b1320 !important;
}

/* ===== Responsive ===== */
@media (max-width: 991.98px) {
    .advisory-panel { position: static; }
}

/* ========== Rent Calendar ========== */
.rent-calendar th {
    font-size: 0.75rem;
    padding: 6px 2px;
    background: #f8f9fa;
}
.rent-calendar td {
    vertical-align: top;
    padding: 4px;
    min-width: 40px;
    height: 60px;
    font-size: 0.8rem;
    position: relative;
}
.cal-empty { background: #fafafa; }
.cal-day-number {
    font-weight: 600;
    font-size: 0.82rem;
    margin-bottom: 2px;
}
.cal-today {
    background: #e8f4fd !important;
    border: 2px solid #0d6efd !important;
}
.cal-due-day {
    background: #fff3cd !important;
}
.cal-lease-start {
    background: #d1e7dd !important;
}
.cal-before-lease {
    opacity: 0.35;
}
.cal-has-payment {
    background: #d4edda !important;
}
.cal-marker {
    font-size: 0.65rem;
    line-height: 1;
}
.cal-marker-due { color: #ff9800; }
.cal-marker-start { color: #198754; }
.cal-payment {
    font-size: 0.6rem;
    padding: 1px 3px;
    margin-top: 1px;
    display: inline-block;
}
.cal-legend {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 3px;
    vertical-align: middle;
    margin-right: 3px;
}
.cal-legend-today { background: #e8f4fd; border: 2px solid #0d6efd; }
.cal-legend-due { background: #fff3cd; }
.cal-legend-start { background: #d1e7dd; }
.cal-legend-paid { background: #d4edda; }

/* Dark mode calendar */
body.dark-mode .rent-calendar th { background: #1e2530; }
body.dark-mode .cal-empty { background: #141820; }
body.dark-mode .cal-today { background: #1a2a3a !important; border-color: #3b82f6 !important; }
body.dark-mode .cal-due-day { background: #332b00 !important; }
body.dark-mode .cal-lease-start { background: #0a3d26 !important; }
body.dark-mode .cal-has-payment { background: #0d3320 !important; }
body.dark-mode .cal-before-lease { opacity: 0.25; }
body.dark-mode .cal-legend-today { background: #1a2a3a; border-color: #3b82f6; }
body.dark-mode .cal-legend-due { background: #332b00; }
body.dark-mode .cal-legend-start { background: #0a3d26; }
body.dark-mode .cal-legend-paid { background: #0d3320; }

@media (max-width: 575.98px) {
    .rent-calendar td { height: 45px; min-width: 30px; font-size: 0.7rem; }
    .cal-payment { font-size: 0.55rem; }
}
</style>

<?php include '../includes/footer.php'; ?>