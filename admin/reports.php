<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Admins and Super Admins
if (function_exists('require_admin_role')) {
    require_admin_role();
} else {
    if (!isset($_SESSION['user_id']) || (isset($_SESSION['role']) ? $_SESSION['role'] : '') != 'admin') {
        header("Location: ../index.php");
        exit();
    }
}

$page_title = 'Reports';

// ── Date / Period Filters ──
require_once '../includes/date_helpers.php';
$dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';
$period     = isset($_GET['period']) ? trim($_GET['period']) : '';

$periodRange = compute_period($period);

function build_where($col, $dateFilter, $periodRange) {
    return build_date_where($col, $dateFilter, $periodRange);
}

// ── Summary Stats ──
function run_count_query($conn, $sql, $where) {
    if ($where[0]) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($where[2], ...$where[1]);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        $row = $res ? $res->fetch_assoc() : null;
    }
    return $row;
}

$payWhere = build_where('p.created_at', $dateFilter, $periodRange);
$payRow   = run_count_query($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(p.amount),0) AS total FROM payments p " . $payWhere[0], $payWhere);
$payCnt   = (int)($payRow['cnt'] ?? 0);
$payTotal = (float)($payRow['total'] ?? 0);

$cWhere = build_where('c.created_at', $dateFilter, $periodRange);
$cRow   = run_count_query($conn, "SELECT COUNT(*) AS cnt FROM complaints c " . $cWhere[0], $cWhere);
$cCnt   = (int)($cRow['cnt'] ?? 0);

$aWhere = build_where('a.created_at', $dateFilter, $periodRange);
$aRow   = run_count_query($conn, "SELECT COUNT(*) AS cnt FROM announcements a " . $aWhere[0], $aWhere);
$aCnt   = (int)($aRow['cnt'] ?? 0);

// ── Monthly Payments Report ──
$mpr_month = isset($_GET['mpr_month']) ? trim($_GET['mpr_month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mpr_month)) {
    $mpr_month = date('Y-m');
}
$mpr_label = date('F Y', strtotime($mpr_month . '-01'));
$mpr_start = $mpr_month . '-01';
$mpr_end   = date('Y-m-t', strtotime($mpr_month . '-01'));

$mpr_payments = [];
$mpr_total    = 0.0;

// Primary: payments with for_month set
$mpr_stmt = $conn->prepare("
    SELECT p.payment_id, p.amount, p.payment_date, p.status, p.payment_type, p.for_month,
           t.name AS tenant_name, t.unit_number, t.email
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.tenant_id
    WHERE p.status = 'verified' AND p.for_month = ?
    ORDER BY p.payment_date ASC, t.unit_number ASC
");
$mpr_stmt->bind_param("s", $mpr_month);
$mpr_stmt->execute();
$mpr_result = $mpr_stmt->get_result();
$seen_ids = [];
while ($row = $mpr_result->fetch_assoc()) {
    $mpr_payments[]  = $row;
    $mpr_total      += (float)$row['amount'];
    $seen_ids[]      = (int)$row['payment_id'];
}
$mpr_stmt->close();

// Fallback: payments without for_month, by date range
$mpr_stmt2 = $conn->prepare("
    SELECT p.payment_id, p.amount, p.payment_date, p.status, p.payment_type, p.for_month,
           t.name AS tenant_name, t.unit_number, t.email
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.tenant_id
    WHERE p.status = 'verified'
      AND (p.for_month IS NULL OR p.for_month = '')
      AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date ASC, t.unit_number ASC
");
$mpr_stmt2->bind_param("ss", $mpr_start, $mpr_end);
$mpr_stmt2->execute();
$mpr_result2 = $mpr_stmt2->get_result();
while ($row2 = $mpr_result2->fetch_assoc()) {
    if (!in_array((int)$row2['payment_id'], $seen_ids, true)) {
        $mpr_payments[] = $row2;
        $mpr_total     += (float)$row2['amount'];
    }
}
$mpr_stmt2->close();

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">

    <!-- Page Title -->
    <h2 class="dashboard-title d-flex align-items-center mb-4">
        <i class="fas fa-chart-line me-2"></i>Reports
    </h2>

    <!-- ── Quick Filter Bar ── -->
    <div class="card report-filter-card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div>
                    <small class="fw-semibold text-uppercase report-filter-label mb-2 d-block">Quick Filters</small>
                    <?php
                    $periods = [
                        'today'     => 'Today',
                        'yesterday' => 'Yesterday',
                        'last7'     => 'Last 7 Days',
                        'last30'    => 'Last 30 Days',
                        'thisMonth' => 'This Month',
                    ];
                    ?>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Quick filters">
                        <?php foreach ($periods as $key => $label): ?>
                            <a href="reports.php?period=<?php echo $key; ?>"
                               class="btn btn-outline-primary <?php echo $period === $key ? 'active' : ''; ?>">
                                <?php echo $label; ?>
                            </a>
                        <?php endforeach; ?>
                        <a href="reports.php" class="btn btn-outline-secondary">All</a>
                    </div>
                </div>
                <form id="repFilterForm" method="get" class="d-flex align-items-end gap-2">
                    <div>
                        <label for="repDate" class="form-label small mb-1">Pick a Date</label>
                        <input type="date" name="date" id="repDate" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($dateFilter); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                    <a href="reports.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Summary Cards ── -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card report-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="report-stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fas fa-credit-card fa-lg"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="report-stat-label">Total Payments</small>
                        <h4 class="mb-0 fw-bold"><?php echo (int)$payCnt; ?></h4>
                        <small class="report-stat-amount">₱<?php echo number_format($payTotal, 2); ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="report-stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="report-stat-label">Total Complaints</small>
                        <h4 class="mb-0 fw-bold"><?php echo (int)$cCnt; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card report-stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="report-stat-icon bg-info bg-opacity-10 text-info">
                        <i class="fas fa-bullhorn fa-lg"></i>
                    </div>
                    <div class="flex-grow-1">
                        <small class="report-stat-label">Total Announcements</small>
                        <h4 class="mb-0 fw-bold"><?php echo (int)$aCnt; ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Monthly Payments Report ── -->
    <div class="card report-mpr-card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="d-flex align-items-center gap-2">
                <i class="fas fa-file-invoice-dollar"></i>
                <span class="fw-semibold">Monthly Payments Report</span>
            </span>
            <form method="GET" id="mprFilterForm" class="d-flex align-items-center gap-2 mb-0">
                <?php if ($period): ?>
                    <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                <?php endif; ?>
                <?php if ($dateFilter): ?>
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>">
                <?php endif; ?>
                <label for="mpr_month" class="form-label mb-0 small fw-semibold text-white">Month:</label>
                <input type="month" name="mpr_month" id="mpr_month"
                       class="form-control form-control-sm mpr-month-input"
                       value="<?php echo htmlspecialchars($mpr_month); ?>">
                <button type="submit" class="btn btn-light btn-sm">
                    <i class="fas fa-search"></i>
                </button>
            </form>
        </div>

        <div class="card-body">
            <!-- Summary row -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-1 mpr-title"><?php echo $mpr_label; ?></h5>
                    <span class="mpr-subtitle">
                        <?php echo count($mpr_payments); ?> payment<?php echo count($mpr_payments) !== 1 ? 's' : ''; ?>
                        &bull; Total: <strong>₱<?php echo number_format($mpr_total, 2); ?></strong>
                    </span>
                </div>
                <?php if (!empty($mpr_payments)): ?>
                    <button class="btn btn-sm btn-success" id="exportMprCsv">
                        <i class="fas fa-file-csv me-1"></i>Export CSV
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($mpr_payments)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="mprTable">
                        <thead>
                            <tr>
                                <th class="text-center" style="width:50px">#</th>
                                <th>Tenant Name</th>
                                <th>Unit / Room</th>
                                <th class="text-end">Amount Paid</th>
                                <th>Payment Date</th>
                                <th class="text-center">Type</th>
                                <th>For Month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $mpr_i = 1; foreach ($mpr_payments as $mp): ?>
                                <?php
                                $pt      = $mp['payment_type'] ?? 'rent';
                                $ptClass = match ($pt) {
                                    'deposit' => 'bg-info text-dark',
                                    'advance' => 'bg-warning text-dark',
                                    default   => 'bg-secondary',
                                };
                                ?>
                                <tr>
                                    <td class="text-center text-muted"><?php echo $mpr_i++; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($mp['tenant_name']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($mp['unit_number']); ?></span></td>
                                    <td class="text-end fw-semibold mpr-amount">₱<?php echo number_format((float)$mp['amount'], 2); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($mp['payment_date'])); ?></td>
                                    <td class="text-center"><span class="badge <?php echo $ptClass; ?>"><?php echo ucfirst($pt); ?></span></td>
                                    <td><?php echo $mp['for_month'] ? date('M Y', strtotime($mp['for_month'] . '-01')) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="mpr-total-row">
                                <td colspan="3" class="text-end fw-bold">Total</td>
                                <td class="text-end fw-bold mpr-amount">₱<?php echo number_format($mpr_total, 2); ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 mpr-empty">
                    <i class="fas fa-receipt fa-3x mb-3 d-block"></i>
                    <h6 class="fw-semibold mb-1">No verified payments for <?php echo $mpr_label; ?></h6>
                    <p class="small mb-0">Try selecting a different month using the filter above.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.admin-ui -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-submit on date change
    var dateInput = document.getElementById('repDate');
    var dateForm  = document.getElementById('repFilterForm');
    if (dateInput && dateForm) {
        dateInput.addEventListener('change', function () { dateForm.submit(); });
    }

    // Auto-submit MPR month change
    var mprInput = document.getElementById('mpr_month');
    var mprForm  = document.getElementById('mprFilterForm');
    if (mprInput && mprForm) {
        mprInput.addEventListener('change', function () { mprForm.submit(); });
    }

    // CSV export
    var exportBtn = document.getElementById('exportMprCsv');
    var mprTable  = document.getElementById('mprTable');
    if (exportBtn && mprTable) {
        exportBtn.addEventListener('click', function () {
            var rows = mprTable.querySelectorAll('tbody tr');
            var csv  = 'No,Tenant Name,Unit/Room,Amount Paid,Payment Date,Type,For Month\n';

            rows.forEach(function (row) {
                var cells = row.querySelectorAll('td');
                var line  = [];
                cells.forEach(function (c) {
                    line.push('"' + (c.textContent.trim().replace(/"/g, '""')) + '"');
                });
                csv += line.join(',') + '\n';
            });

            csv += ',,Total,"₱<?php echo number_format($mpr_total, 2); ?>",,\n';

            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var a    = document.createElement('a');
            a.href     = URL.createObjectURL(blob);
            a.download = 'monthly_payments_<?php echo $mpr_month; ?>.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
