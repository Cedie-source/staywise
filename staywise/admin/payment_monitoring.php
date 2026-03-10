<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Payment Tracker";

// Current and selected month
$selected_month = $_GET['month'] ?? date('Y-m');
$selected_label = date('F Y', strtotime($selected_month . '-01'));

// Default due day (1st of month)
$default_due_day = 1;
try {
    $s = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'late_fee_grace_days'");
    if ($s && $row = $s->fetch_assoc()) {
        // grace period info available
    }
} catch (Throwable $e) {}

// Check if due_day column exists
$has_due_day = false;
try {
    $chk = $conn->query("SHOW COLUMNS FROM tenants LIKE 'due_day'");
    $has_due_day = ($chk && $chk->num_rows > 0);
} catch (Throwable $e) {}

// Get all active tenants with their payment status for the selected month
$tenants_data = [];
$due_day_col = $has_due_day ? "t.due_day," : "";
$result = $conn->query("
    SELECT 
        t.tenant_id, t.name, t.email, t.contact, t.unit_number, t.rent_amount,
        $due_day_col
        COALESCE(
            (SELECT SUM(p.amount) FROM payments p 
             WHERE p.tenant_id = t.tenant_id 
             AND p.for_month = '$selected_month' 
             AND p.status = 'verified'), 0
        ) as paid_amount,
        COALESCE(
            (SELECT COUNT(*) FROM payments p 
             WHERE p.tenant_id = t.tenant_id 
             AND p.for_month = '$selected_month' 
             AND p.status = 'pending'), 0
        ) as pending_count,
        (SELECT MAX(p.payment_date) FROM payments p 
         WHERE p.tenant_id = t.tenant_id 
         AND p.status = 'verified') as last_payment_date
    FROM tenants t
    WHERE t.deleted_at IS NULL
    ORDER BY t.unit_number ASC
");

if (!$result) {
    die("Query error: " . $conn->error);
}

$total_expected = 0;
$total_collected = 0;
$paid_count = 0;
$partial_count = 0;
$unpaid_count = 0;
$overdue_count = 0;
$pending_count = 0;

while ($row = $result->fetch_assoc()) {
    $rent = floatval($row['rent_amount']);
    $paid = floatval($row['paid_amount']);
    $due_day = (isset($row['due_day']) && $row['due_day'] > 0) ? intval($row['due_day']) : $default_due_day;
    $due_date = $selected_month . '-' . str_pad($due_day, 2, '0', STR_PAD_LEFT);
    
    $total_expected += $rent;
    $total_collected += min($paid, $rent);
    
    // Determine status
    if ($paid >= $rent - 0.01) {
        $row['pay_status'] = 'paid';
        $row['badge_class'] = 'bg-success';
        $row['badge_icon'] = 'fa-check-circle';
        $paid_count++;
    } elseif ($row['pending_count'] > 0) {
        $row['pay_status'] = 'pending';
        $row['badge_class'] = 'bg-info';
        $row['badge_icon'] = 'fa-clock';
        $pending_count++;
    } elseif ($paid > 0) {
        $row['pay_status'] = 'partial';
        $row['badge_class'] = 'bg-warning text-dark';
        $row['badge_icon'] = 'fa-exclamation-circle';
        $partial_count++;
        if (date('Y-m-d') > $due_date) {
            $overdue_count++;
        }
    } else {
        if (date('Y-m-d') > $due_date) {
            $row['pay_status'] = 'overdue';
            $row['badge_class'] = 'bg-danger';
            $row['badge_icon'] = 'fa-times-circle';
            $overdue_count++;
            $unpaid_count++;
        } else {
            $row['pay_status'] = 'unpaid';
            $row['badge_class'] = 'bg-secondary';
            $row['badge_icon'] = 'fa-minus-circle';
            $unpaid_count++;
        }
    }
    
    $row['due_date'] = $due_date;
    $row['balance'] = max(0, $rent - $paid);
    $tenants_data[] = $row;
}

// Collection rate
$collection_rate = $total_expected > 0 ? round(($total_collected / $total_expected) * 100, 1) : 0;

// Monthly trend data (last 6 months)
$trend_months = [];
$trend_expected = [];
$trend_collected = [];
$trend_rates = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $trend_months[] = date('M', strtotime($m . '-01'));
    
    // Expected: sum of all active tenant rents
    $exp = $conn->query("SELECT COALESCE(SUM(rent_amount),0) as total FROM tenants WHERE deleted_at IS NULL")->fetch_assoc()['total'];
    $trend_expected[] = (float)$exp;
    
    // Collected for that month
    $col = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status='verified' AND for_month='$m'")->fetch_assoc()['total'];
    $trend_collected[] = (float)$col;
    
    $trend_rates[] = $exp > 0 ? round(($col / $exp) * 100, 1) : 0;
}

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h2 class="dashboard-title mb-0"><i class="fas fa-chart-line me-2"></i>Payment Tracker</h2>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="month" name="month" class="form-control form-control-sm" value="<?php echo $selected_month; ?>" style="width:180px;">
            <button class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>View</button>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="row g-2 mb-3">
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body text-center py-2">
                    <i class="fas fa-peso-sign text-primary"></i>
                    <h5 class="fw-bold mb-0 dashboard-title">₱<?php echo number_format($total_collected, 0); ?></h5>
                    <small class="text-muted">of ₱<?php echo number_format($total_expected, 0); ?></small>
                    <div class="progress mt-1" style="height:4px;">
                        <div class="progress-bar bg-primary" style="width:<?php echo $collection_rate; ?>%"></div>
                    </div>
                    <small class="fw-bold text-primary"><?php echo $collection_rate; ?>%</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center py-2">
                    <i class="fas fa-check-circle text-success"></i>
                    <h5 class="fw-bold mb-0 dashboard-title"><?php echo $paid_count; ?></h5>
                    <small class="text-muted">Fully Paid</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center py-2">
                    <i class="fas fa-exclamation-triangle text-danger"></i>
                    <h5 class="fw-bold mb-0 dashboard-title"><?php echo $overdue_count; ?></h5>
                    <small class="text-muted">Overdue</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-6">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center py-2">
                    <i class="fas fa-balance-scale text-warning"></i>
                    <h5 class="fw-bold mb-0 dashboard-title">₱<?php echo number_format($total_expected - $total_collected, 0); ?></h5>
                    <small class="text-muted">Outstanding</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs & Search -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-primary filter-btn active" data-filter="all">All</button>
            <button class="btn btn-sm btn-outline-success filter-btn" data-filter="paid"><i class="fas fa-check me-1"></i>Paid</button>
            <button class="btn btn-sm btn-outline-info filter-btn" data-filter="pending"><i class="fas fa-clock me-1"></i>Pending</button>
            <button class="btn btn-sm btn-outline-warning filter-btn" data-filter="partial"><i class="fas fa-exclamation me-1"></i>Partial</button>
            <button class="btn btn-sm btn-outline-danger filter-btn" data-filter="overdue"><i class="fas fa-times me-1"></i>Overdue</button>
            <button class="btn btn-sm btn-outline-secondary filter-btn" data-filter="unpaid"><i class="fas fa-minus me-1"></i>Not Paid</button>
        </div>
        <input type="text" class="form-control form-control-sm" id="tenantSearch" placeholder="Search tenant or unit..." style="max-width:250px;">
    </div>

    <!-- Tenants Table -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="monitorTable">
                    <thead>
                        <tr class="border-bottom">
                            <th class="ps-3 py-3">Unit</th>
                            <th class="py-3">Tenant</th>
                            <th class="py-3">Rent Due</th>
                            <th class="py-3">Paid</th>
                            <th class="py-3">Balance</th>
                            <th class="py-3">Due Date</th>
                            <th class="py-3">Status</th>
                            <th class="py-3">Last Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tenants_data)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-users fa-3x mb-3 d-block"></i>No tenants found</td></tr>
                        <?php else: ?>
                            <?php foreach ($tenants_data as $t): ?>
                            <tr data-status="<?php echo $t['pay_status']; ?>">
                                <td class="ps-3"><span class="badge bg-primary rounded-pill"><?php echo htmlspecialchars($t['unit_number']); ?></span></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($t['name']); ?></div>
                                    <?php if ($t['contact']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($t['contact']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold">₱<?php echo number_format($t['rent_amount'], 0); ?></td>
                                <td>
                                    <?php if ($t['paid_amount'] > 0): ?>
                                        <span class="text-success fw-semibold">₱<?php echo number_format($t['paid_amount'], 0); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($t['balance'] > 0): ?>
                                        <span class="text-danger fw-semibold">₱<?php echo number_format($t['balance'], 0); ?></span>
                                    <?php else: ?>
                                        <span class="text-success"><i class="fas fa-check"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $due_str = date('M d', strtotime($t['due_date']));
                                    $is_past = date('Y-m-d') > $t['due_date'];
                                    ?>
                                    <span class="<?php echo $is_past && $t['balance'] > 0 ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo $due_str; ?>
                                        <?php if ($is_past && $t['balance'] > 0): ?>
                                            <?php 
                                            $days_late = (int)((strtotime(date('Y-m-d')) - strtotime($t['due_date'])) / 86400);
                                            ?>
                                            <br><small class="text-danger"><?php echo $days_late; ?> days late</small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $t['badge_class']; ?> rounded-pill">
                                        <i class="fas <?php echo $t['badge_icon']; ?> me-1"></i><?php echo ucfirst($t['pay_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($t['last_payment_date']): ?>
                                        <small><?php echo date('M d, Y', strtotime($t['last_payment_date'])); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Never</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-muted small mt-3 mb-3">
        <i class="fas fa-info-circle me-1"></i>Showing payment status for <strong><?php echo $selected_label; ?></strong>. 
        Total tenants: <?php echo count($tenants_data); ?> &bull; 
        Expected collection: ₱<?php echo number_format($total_expected, 0); ?>
    </div>

    <!-- Charts Toggle -->
    <div class="mb-2">
        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#chartsSection" aria-expanded="false">
            <i class="fas fa-chart-bar me-1"></i>Show Charts
        </button>
    </div>
    <div class="collapse" id="chartsSection">
        <div class="row g-2 mb-3">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body py-2 px-3">
                        <h6 class="fw-bold mb-1 dashboard-title" style="font-size:0.8rem;"><i class="fas fa-chart-bar me-1"></i>Collection Trend (Last 6 Months)</h6>
                        <div style="height:130px;"><canvas id="trendChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body py-2 px-3">
                        <h6 class="fw-bold mb-1 dashboard-title" style="font-size:0.8rem;"><i class="fas fa-chart-pie me-1"></i><?php echo $selected_label; ?> Status</h6>
                        <div style="height:100px;"><canvas id="statusChart"></canvas></div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><i class="fas fa-circle text-success me-1"></i>Paid</span>
                                <span class="fw-bold"><?php echo $paid_count; ?></span>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span><i class="fas fa-circle text-info me-1"></i>Pending</span>
                                <span class="fw-bold"><?php echo $pending_count; ?></span>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span><i class="fas fa-circle text-warning me-1"></i>Partial</span>
                                <span class="fw-bold"><?php echo $partial_count; ?></span>
                            </div>
                            <div class="d-flex justify-content-between small mb-1">
                                <span><i class="fas fa-circle text-danger me-1"></i>Overdue</span>
                                <span class="fw-bold"><?php echo $overdue_count; ?></span>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span><i class="fas fa-circle text-secondary me-1"></i>Not Yet Due</span>
                                <span class="fw-bold"><?php echo max(0, $unpaid_count - $overdue_count + count(array_filter($tenants_data, fn($t) => $t['pay_status'] === 'unpaid'))); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Detect dark mode
const isDark = document.body.classList.contains('dark-mode');
const textColor = isDark ? '#cbd5e1' : '#475569';
const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

Chart.defaults.color = textColor;
Chart.defaults.borderColor = gridColor;

// Collection Trend Chart
new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($trend_months); ?>,
        datasets: [
            {
                label: 'Collected',
                data: <?php echo json_encode($trend_collected); ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.7)',
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7
            },
            {
                label: 'Expected',
                data: <?php echo json_encode($trend_expected); ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.2)',
                borderColor: 'rgba(59, 130, 246, 0.5)',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7
            },
            {
                label: 'Collection %',
                data: <?php echo json_encode($trend_rates); ?>,
                type: 'line',
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                borderWidth: 3,
                pointRadius: 5,
                pointBackgroundColor: '#f59e0b',
                tension: 0.4,
                yAxisID: 'y1',
                fill: false
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, padding: 8, font: { size: 10 } } },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        if (ctx.dataset.yAxisID === 'y1') return ctx.dataset.label + ': ' + ctx.parsed.y + '%';
                        return ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' },
                grid: { color: gridColor }
            },
            y1: {
                position: 'right',
                beginAtZero: true,
                max: 100,
                ticks: { callback: v => v + '%' },
                grid: { display: false }
            },
            x: { grid: { display: false } }
        }
    }
});

// Status Donut Chart
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Paid', 'Pending', 'Partial', 'Overdue', 'Unpaid'],
        datasets: [{
            data: [
                <?php echo $paid_count; ?>,
                <?php echo $pending_count; ?>,
                <?php echo $partial_count; ?>,
                <?php echo $overdue_count; ?>,
                <?php echo max(0, $unpaid_count - $overdue_count); ?>
            ],
            backgroundColor: [
                'rgba(34, 197, 94, 0.85)',
                'rgba(14, 165, 233, 0.85)',
                'rgba(245, 158, 11, 0.85)',
                'rgba(239, 68, 68, 0.85)',
                'rgba(148, 163, 184, 0.6)'
            ],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { display: false }
        }
    }
});

// Filter buttons
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => {
            b.classList.remove('active', 'btn-primary', 'btn-success', 'btn-info', 'btn-warning', 'btn-danger', 'btn-secondary');
            b.classList.add('btn-outline-' + (b.dataset.filter === 'all' ? 'primary' : 
                b.dataset.filter === 'paid' ? 'success' : 
                b.dataset.filter === 'pending' ? 'info' : 
                b.dataset.filter === 'partial' ? 'warning' : 
                b.dataset.filter === 'overdue' ? 'danger' : 'secondary'));
        });
        const f = this.dataset.filter;
        const btnType = f === 'all' ? 'primary' : f === 'paid' ? 'success' : f === 'pending' ? 'info' : f === 'partial' ? 'warning' : f === 'overdue' ? 'danger' : 'secondary';
        this.classList.remove('btn-outline-' + btnType);
        this.classList.add('btn-' + btnType, 'active');
        
        document.querySelectorAll('#monitorTable tbody tr').forEach(row => {
            const status = row.dataset.status;
            if (f === 'all') {
                row.style.display = '';
            } else if (f === 'unpaid') {
                row.style.display = (status === 'unpaid' || status === 'overdue') ? '' : 'none';
            } else {
                row.style.display = status === f ? '' : 'none';
            }
        });
    });
});

// Toggle button text
const chartsEl = document.getElementById('chartsSection');
const toggleBtn = document.querySelector('[data-bs-target="#chartsSection"]');
chartsEl?.addEventListener('shown.bs.collapse', () => { toggleBtn.innerHTML = '<i class="fas fa-chart-bar me-1"></i>Hide Charts'; });
chartsEl?.addEventListener('hidden.bs.collapse', () => { toggleBtn.innerHTML = '<i class="fas fa-chart-bar me-1"></i>Show Charts'; });

// Search
document.getElementById('tenantSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#monitorTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
