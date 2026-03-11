<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
require_once '../includes/email_helper.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Manage Payments";

// AJAX endpoint: return JSON payment history for a specific tenant
if (isset($_GET['history'])) {
    $tid = intval($_GET['history']);
    $stmt = $conn->prepare("SELECT amount, payment_date, status, created_at FROM payments WHERE tenant_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── AJAX polling endpoint: new payments since a given timestamp ──────
if (isset($_GET['poll_since'])) {
    $since = $_GET['poll_since'];
    $stmt = $conn->prepare("
        SELECT p.*, t.name, t.unit_number, t.email
        FROM payments p
        JOIN tenants t ON p.tenant_id = t.tenant_id
        WHERE t.deleted_at IS NULL AND p.created_at > ?
        ORDER BY p.created_at DESC
    ");
    $stmt->bind_param("s", $since);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// Handle payment status updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $payment_id = intval($_POST['payment_id']);
    $status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE payments SET status = ? WHERE payment_id = ?");
    $stmt->bind_param("si", $status, $payment_id);
    if ($stmt->execute()) {
        $success = "Payment status updated successfully!";
        $details = "Changed payment ID $payment_id status to $status";
        logAdminAction($conn, $_SESSION['user_id'], 'update_payment_status', $details);

        // Email notification removed to avoid slow page load
        // Tenants can check their payment status on their dashboard
    } else {
        $error = "Failed to update payment status";
    }
    $stmt->close();
}

// Auto-verify any stuck PayMongo payments by re-checking with the API
require_once '../includes/paymongo_helper.php';
$paymongoHelper = new PayMongoHelper($conn);
if ($paymongoHelper->isConfigured()) {
    $stuckPayments = $conn->query("SELECT payment_id, paymongo_checkout_id FROM payments WHERE status = 'pending' AND payment_method LIKE 'paymongo_%' AND paymongo_checkout_id IS NOT NULL AND paymongo_checkout_id != '' AND created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)");
    if ($stuckPayments && $stuckPayments->num_rows > 0) {
        while ($sp = $stuckPayments->fetch_assoc()) {
            $linkId = $sp['paymongo_checkout_id'];
            $pmPaymentId = '';
            $paid = false;

            // Try Payment Links API first (new flow)
            $linkCheck = $paymongoHelper->checkPaymentLinkStatus($linkId);
            if ($linkCheck['paid']) {
                $pmPaymentId = $linkCheck['payment_id'] ?? '';
                $paid = true;
            }

            // Fallback: try legacy Checkout Session API
            if (!$paid) {
                $session = $paymongoHelper->getCheckoutSession($linkId);
                if (isset($session['data']['attributes']['payments']) && !empty($session['data']['attributes']['payments'])) {
                    $pmPaymentId = $session['data']['attributes']['payments'][0]['id'] ?? '';
                    $paid = true;
                } elseif (isset($session['data']['attributes']['payment_intent']['attributes']['status']) &&
                          in_array($session['data']['attributes']['payment_intent']['attributes']['status'], ['succeeded', 'processing'])) {
                    $paid = true;
                }
            }

            if ($paid) {
                $now = date('Y-m-d H:i:s');
                $autoVerify = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ?, transaction_id = ?, paymongo_payment_id = ? WHERE payment_id = ? AND status = 'pending'");
                $autoVerify->bind_param("sssi", $now, $pmPaymentId, $pmPaymentId, $sp['payment_id']);
                $autoVerify->execute();
                $autoVerify->close();
            }
        }
    }
}

// Get all payments with tenant information
$payments = $conn->query("
    SELECT p.*, t.name, t.unit_number, t.email 
    FROM payments p 
    JOIN tenants t ON p.tenant_id = t.tenant_id 
    WHERE t.deleted_at IS NULL
    ORDER BY p.created_at DESC
");

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="dashboard-title"><i class="fas fa-credit-card me-2"></i>Manage Payments</h2>
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" name="filter" id="all" autocomplete="off" checked>
            <label class="btn btn-outline-primary" for="all">All</label>
            
            <input type="radio" class="btn-check" name="filter" id="pending" autocomplete="off">
            <label class="btn btn-outline-warning" for="pending">Pending</label>
            
            <input type="radio" class="btn-check" name="filter" id="verified" autocomplete="off">
            <label class="btn btn-outline-success" for="verified">Verified</label>
        </div>
    </div>

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

    <!-- Search bar -->
    <div class="mb-3">
        <input type="text" class="form-control" id="paymentSearch" placeholder="Search payments by tenant, unit, amount, or month...">
    </div>

    <div class="card flat-card">
        <div class="card-body">
            <?php if ($payments->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table" id="paymentsTable">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th>Unit</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>For Month</th>
                                <th>Payment Date</th>
                                <th>Proof</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($payment = $payments->fetch_assoc()): ?>
                                <tr data-status="<?php echo $payment['status']; ?>" data-id="<?php echo $payment['payment_id']; ?>">
                                    <td class="align-middle">
                                        <div class="tenant-cell">
                                            <button class="btn btn-link p-0" onclick="viewTenantPayments('<?php echo addslashes($payment['tenant_id']); ?>', '<?php echo addslashes($payment['name']); ?>')">
                                                <strong><?php echo htmlspecialchars($payment['name']); ?></strong>
                                            </button>
                                            <small class="text-muted"><?php echo htmlspecialchars($payment['email']); ?></small>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($payment['unit_number']); ?></span>
                                    </td>
                                    <td class="align-middle">
                                        <strong>₱<?php echo number_format($payment['amount'], 2); ?></strong>
                                    </td>
                                    <td class="align-middle">
                                        <?php
                                            $method = $payment['payment_method'] ?? 'cash';
                                            $methodLabels = [
                                                'cash' => ['label' => 'Cash', 'icon' => 'fa-money-bill-wave', 'class' => 'bg-dark bg-opacity-75'],
                                                'manual' => ['label' => 'Manual', 'icon' => 'fa-upload', 'class' => 'bg-secondary'],
                                                'manual_gcash' => ['label' => 'GCash', 'icon' => 'fa-mobile-alt', 'class' => 'bg-primary'],
                                                'paymongo_gcash' => ['label' => 'GCash (Online)', 'icon' => 'fa-mobile-alt', 'class' => 'bg-info'],
                                                'paymongo_grab_pay' => ['label' => 'GrabPay', 'icon' => 'fa-car', 'class' => 'bg-success'],
                                                'paymongo_card' => ['label' => 'Card', 'icon' => 'fa-credit-card', 'class' => 'bg-dark'],
                                                'bank_transfer' => ['label' => 'Bank', 'icon' => 'fa-university', 'class' => 'bg-secondary'],
                                            ];
                                            $m = $methodLabels[$method] ?? $methodLabels['cash'];
                                        ?>
                                        <span class="badge <?php echo $m['class']; ?>">
                                            <i class="fas <?php echo $m['icon']; ?> me-1"></i><?php echo $m['label']; ?>
                                        </span>
                                        <?php if (!empty($payment['reference_no'])): ?>
                                            <br><small class="text-muted">Ref: <?php echo htmlspecialchars($payment['reference_no']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php 
                                            $fm = $payment['for_month'] ?? '';
                                            echo $fm ? date('M Y', strtotime($fm . '-01')) : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php if ($payment['proof_file']): ?>
                                            <a href="../uploads/payments/<?php echo $payment['proof_file']; ?>" 
                                               target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-file me-1"></i>View
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">No file</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge status-<?php echo $payment['status']; ?>">
                                            <?php echo ucfirst($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <small class="text-muted">
                                            <?php echo date('M d, Y g:i A', strtotime($payment['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td class="align-middle">
                                        <?php
                                            $is_paymongo_method = str_starts_with($method, 'paymongo_');
                                        ?>
                                        <?php if ($payment['status'] == 'pending' && !$is_paymongo_method): ?>
                                            <div class="btn-group" role="group">
                                                <form method="POST" class="d-inline">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                                    <input type="hidden" name="status" value="verified">
                                                    <button type="submit" name="update_status" class="btn btn-sm btn-success" 
                                                            title="Verify Payment" onclick="return confirm('Verify this payment?')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                                    <input type="hidden" name="status" value="rejected">
                                                    <button type="submit" name="update_status" class="btn btn-sm btn-danger" 
                                                            title="Reject Payment" onclick="return confirm('Reject this payment?')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php elseif ($payment['status'] == 'pending' && $is_paymongo_method): ?>
                                            <span class="badge bg-info text-white">
                                                <i class="fas fa-sync-alt me-1"></i>Processing
                                            </span>
                                            <small class="text-muted d-block mt-1">Auto-verifies on refresh</small>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="payment_id" value="<?php echo $payment['payment_id']; ?>">
                                                <input type="hidden" name="status" value="pending">
                                                <button type="submit" name="update_status" class="btn btn-sm btn-outline-secondary" 
                                                        title="Reset to Pending">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-credit-card fa-3x text-muted mb-3"></i>
                    <h5>No Payments Found</h5>
                    <p class="text-muted">Payment submissions will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Statistics -->
    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-warning" id="pendingCount">0</h3>
                    <p class="mb-0">Pending Payments</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-success" id="verifiedCount">0</h3>
                    <p class="mb-0">Verified Payments</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center">
                <div class="card-body">
                    <h3 class="text-primary" id="totalAmount">₱0.00</h3>
                    <p class="mb-0">Total Verified Amount</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const table = document.getElementById('paymentsTable');
    const filterButtons = document.querySelectorAll('input[name="filter"]');
    
    function updateStats() {
        const rows = table.querySelectorAll('tbody tr');
        let pendingCount = 0, verifiedCount = 0, totalAmount = 0;
        rows.forEach(row => {
            if (row.style.display !== 'none') {
                const status = row.dataset.status;
                const amount = parseFloat((row.cells[2].textContent || '').replace(/[^0-9.\-]/g, '')) || 0;
                if (status === 'pending') pendingCount++;
                if (status === 'verified') { verifiedCount++; totalAmount += amount; }
            }
        });
        document.getElementById('pendingCount').textContent = pendingCount;
        document.getElementById('verifiedCount').textContent = verifiedCount;
        document.getElementById('totalAmount').textContent = '₱' + totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    
    function filterTable(status) {
        table.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
        });
        updateStats();
    }
    
    filterButtons.forEach(button => {
        button.addEventListener('change', function() { if (this.checked) filterTable(this.id); });
    });
    
    updateStats();

    const paymentSearch = document.getElementById('paymentSearch');
    if (paymentSearch) {
        paymentSearch.addEventListener('input', function() {
            const q = this.value.toLowerCase();
            const statusFilter = (document.querySelector('input[name="filter"]:checked') || {id:'all'}).id;
            table.querySelectorAll('tbody tr').forEach(row => {
                row.style.display = ((!q || row.textContent.toLowerCase().includes(q)) && (statusFilter === 'all' || row.dataset.status === statusFilter)) ? '' : 'none';
            });
            updateStats();
        });
    }

    // ── Real-time polling for new payments ──────────────────────────
    var lastPollTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
    var knownIds = new Set();
    table.querySelectorAll('tbody tr[data-id]').forEach(function(r) { knownIds.add(r.dataset.id); });

    var methodLabels = {
        'cash':              { label: 'Cash',          icon: 'fa-money-bill-wave', cls: 'bg-dark bg-opacity-75' },
        'manual_gcash':      { label: 'GCash',         icon: 'fa-mobile-alt',      cls: 'bg-primary' },
        'paymongo_gcash':    { label: 'GCash (Online)', icon: 'fa-mobile-alt',     cls: 'bg-info' },
        'paymongo_grab_pay': { label: 'GrabPay',       icon: 'fa-car',             cls: 'bg-success' },
        'paymongo_card':     { label: 'Card',          icon: 'fa-credit-card',     cls: 'bg-dark' },
        'bank_transfer':     { label: 'Bank',          icon: 'fa-university',      cls: 'bg-secondary' },
    };

    function buildRow(p) {
        var m = methodLabels[p.payment_method] || methodLabels['cash'];
        var isPaymongo = p.payment_method && p.payment_method.startsWith('paymongo_');
        var fm = p.for_month ? new Date(p.for_month + '-02').toLocaleDateString('en-US', {month:'short', year:'numeric'}) : '—';
        var pd = new Date(p.payment_date).toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric'});
        var created = new Date(p.created_at).toLocaleDateString('en-US', {month:'short', day:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'});
        var proof = p.proof_file
            ? '<a href="../uploads/payments/' + p.proof_file + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file me-1"></i>View</a>'
            : '<span class="text-muted">No file</span>';
        var actions = isPaymongo
            ? '<span class="badge bg-info text-white"><i class="fas fa-sync-alt me-1"></i>Processing</span><small class="text-muted d-block mt-1">Auto-verifies on refresh</small>'
            : '<div class="btn-group" role="group">'
              + '<form method="POST" class="d-inline"><input type="hidden" name="payment_id" value="' + p.payment_id + '"><input type="hidden" name="status" value="verified"><button type="submit" name="update_status" class="btn btn-sm btn-success" onclick="return confirm(\'Verify this payment?\')"><i class="fas fa-check"></i></button></form>'
              + '<form method="POST" class="d-inline"><input type="hidden" name="payment_id" value="' + p.payment_id + '"><input type="hidden" name="status" value="rejected"><button type="submit" name="update_status" class="btn btn-sm btn-danger" onclick="return confirm(\'Reject this payment?\')"><i class="fas fa-times"></i></button></form>'
              + '</div>';
        var tr = document.createElement('tr');
        tr.dataset.status = p.status;
        tr.dataset.id = p.payment_id;
        tr.style.background = '#fffbeb';
        tr.innerHTML = '<td class="align-middle"><div class="tenant-cell"><strong>' + p.name + '</strong><small class="text-muted d-block">' + p.email + '</small></div></td>'
            + '<td class="align-middle"><span class="badge bg-primary">' + p.unit_number + '</span></td>'
            + '<td class="align-middle"><strong>₱' + parseFloat(p.amount).toLocaleString('en-US',{minimumFractionDigits:2}) + '</strong></td>'
            + '<td class="align-middle"><span class="badge ' + m.cls + '"><i class="fas ' + m.icon + ' me-1"></i>' + m.label + '</span>' + (p.reference_no ? '<br><small class="text-muted">Ref: ' + p.reference_no + '</small>' : '') + '</td>'
            + '<td class="align-middle">' + fm + '</td>'
            + '<td class="align-middle">' + pd + '</td>'
            + '<td class="align-middle">' + proof + '</td>'
            + '<td class="align-middle"><span class="badge status-' + p.status + '">' + p.status.charAt(0).toUpperCase() + p.status.slice(1) + '</span></td>'
            + '<td class="align-middle"><small class="text-muted">' + created + '</small></td>'
            + '<td class="align-middle">' + actions + '</td>';
        return tr;
    }

    function showToast(count) {
        var existing = document.getElementById('newPaymentToast');
        if (existing) existing.remove();
        var toast = document.createElement('div');
        toast.id = 'newPaymentToast';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;background:#16a34a;color:#fff;padding:.75rem 1.25rem;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.2);font-weight:600;display:flex;align-items:center;gap:.6rem;';
        toast.innerHTML = '<i class="fas fa-bell"></i> ' + count + ' new payment' + (count > 1 ? 's' : '') + ' received!';
        document.body.appendChild(toast);
        setTimeout(function() { toast.style.transition='opacity .5s'; toast.style.opacity='0'; setTimeout(function(){ toast.remove(); }, 500); }, 4000);
    }

    function pollNewPayments() {
        fetch('payments.php?poll_since=' + encodeURIComponent(lastPollTime))
            .then(function(r) { return r.json(); })
            .then(function(rows) {
                if (!rows.length) return;
                var newRows = rows.filter(function(p) { return !knownIds.has(String(p.payment_id)); });
                if (!newRows.length) return;
                var tbody = table.querySelector('tbody');
                var statusFilter = (document.querySelector('input[name="filter"]:checked') || {id:'all'}).id;
                newRows.forEach(function(p) {
                    knownIds.add(String(p.payment_id));
                    var tr = buildRow(p);
                    if (statusFilter !== 'all' && p.status !== statusFilter) tr.style.display = 'none';
                    tbody.insertBefore(tr, tbody.firstChild);
                    setTimeout(function() { tr.style.transition = 'background 1.5s'; tr.style.background = ''; }, 5000);
                });
                lastPollTime = rows[0].created_at;
                showToast(newRows.length);
                updateStats();
            })
            .catch(function() {});
    }

    setInterval(pollNewPayments, 10000);
});

function viewTenantPayments(tenantId, tenantName) {
    fetch('payments.php?history=' + tenantId)
        .then(response => response.json())
        .then(data => {
            let modal = document.getElementById('tenantPaymentsModal');
            modal.querySelector('#tenantPaymentsName').textContent = tenantName;
            let tbody = modal.querySelector('tbody');
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center">No payments found.</td></tr>';
            } else {
                data.forEach(row => {
                    tbody.innerHTML += `<tr>
                        <td>₱${parseFloat(row.amount).toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                        <td>${row.payment_date}</td>
                        <td>${row.status}</td>
                        <td>${row.created_at}</td>
                    </tr>`;
                });
            }
            new bootstrap.Modal(modal).show();
        });
}
</script>

<?php include '../includes/footer.php'; ?>
<!-- Tenant Payments Modal -->
<div class="modal fade" id="tenantPaymentsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payment History for <span id="tenantPaymentsName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Amount</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
