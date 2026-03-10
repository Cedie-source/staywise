<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/paymongo_helper.php';

// Tenant auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

$page_title = "My Payments";

// ── Get tenant info ──────────────────────────────────────────────────
$tenant_stmt = $conn->prepare("SELECT tenant_id, name, email, unit_number, rent_amount, lease_start_date, deposit_amount, advance_amount, deposit_paid, advance_paid FROM tenants WHERE user_id = ?");
$tenant_stmt->bind_param("i", $_SESSION['user_id']);
$tenant_stmt->execute();
$tenant = $tenant_stmt->get_result()->fetch_assoc();
$tenant_stmt->close();

if (!$tenant) {
    header("Location: ../logout.php");
    exit();
}

$tenant_id      = $tenant['tenant_id'];
$rent_amount    = (float)($tenant['rent_amount'] ?? 0);
$unit_number    = $tenant['unit_number'] ?? '—';
$advance_amount = (float)($tenant['advance_amount'] ?? 0);
$advance_paid   = (int)($tenant['advance_paid'] ?? 0);
$deposit_amount = (float)($tenant['deposit_amount'] ?? 0);
$deposit_paid   = (int)($tenant['deposit_paid'] ?? 0);

// ── Load payment settings ────────────────────────────────────────────
$gcash_enabled = false;
$gcash_number = '';
$gcash_name   = '';
$gcash_qr_image = '';
$paymongo_enabled = false;

try {
    $settingsResult = $conn->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('gcash_enabled','gcash_number','gcash_name','gcash_qr_image','paymongo_enabled')");
    if ($settingsResult) {
        while ($s = $settingsResult->fetch_assoc()) {
            switch ($s['setting_key']) {
                case 'gcash_enabled':    $gcash_enabled = $s['setting_value'] === '1'; break;
                case 'gcash_number':     $gcash_number  = $s['setting_value']; break;
                case 'gcash_name':       $gcash_name    = $s['setting_value']; break;
                case 'gcash_qr_image':   $gcash_qr_image = $s['setting_value']; break;
                case 'paymongo_enabled': $paymongo_enabled = $s['setting_value'] === '1'; break;
            }
        }
    }
} catch (Throwable $e) {}

$paymongo = new PayMongoHelper($conn);
$paymongo_ready = $paymongo_enabled && $paymongo->isConfigured();

// ── Determine current billing period ─────────────────────────────────
$current_month = date('Y-m');
$due_date = date('Y-m-05'); // 5th of each month

// ── Overpayment + Advance carry-forward logic ───────────────────────
// Calculate total rent owed from lease start to current month, then subtract
// ALL verified payments + advance. Overpayments automatically reduce future balances.
// Deposit is NEVER applied to rent — it's held as security and returned at lease end.
$lease_start = $tenant['lease_start_date'] ?? date('Y-m-01');
$lease_start_dt = new DateTime(date('Y-m-01', strtotime($lease_start)));
$current_dt     = new DateTime(date('Y-m-01'));
$interval       = $lease_start_dt->diff($current_dt);
$billing_months = ($interval->y * 12) + $interval->m + 1; // inclusive of current month
if ($billing_months < 1) $billing_months = 1;

$total_rent_owed = $billing_months * $rent_amount;

// Sum ALL verified payments (across every month)
$total_paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM payments WHERE tenant_id = ? AND status = 'verified'");
$total_paid_stmt->bind_param("i", $tenant_id);
$total_paid_stmt->execute();
$total_paid = (float)$total_paid_stmt->get_result()->fetch_assoc()['total_paid'];
$total_paid_stmt->close();

// Add advance payment as pre-paid credit (covers first month(s) of rent)
// Advance is only counted if the tenant has paid it (advance_paid = 1)
$advance_credit = ($advance_paid && $advance_amount > 0) ? $advance_amount : 0;

// Overall running balance (negative = credit/overpayment)
// total_paid = online/manual payments, advance_credit = move-in advance
$running_balance = $total_rent_owed - $total_paid - $advance_credit;

// Current month effective balance (capped at one month's rent)
$balance_due = max(0, min($rent_amount, $running_balance));

// Credit available (if overpaid)
$credit_amount = max(0, -$running_balance);

// How many months the advance covers (for display)
$advance_months_covered = ($advance_credit > 0 && $rent_amount > 0) ? floor($advance_credit / $rent_amount) : 0;

// What's been paid specifically for this month (for display)
$paid_check = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as paid FROM payments WHERE tenant_id = ? AND for_month = ? AND status = 'verified'");
$paid_check->bind_param("is", $tenant_id, $current_month);
$paid_check->execute();
$paid_this_month = (float)$paid_check->get_result()->fetch_assoc()['paid'];
$paid_check->close();

// ── Handle PayMongo success callback (redirect from checkout) ────────
if (isset($_GET['payment_link_id'])) {
    $link_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['payment_link_id']);
    
    // Poll PayMongo to check if paid
    $linkStatus = $paymongo->checkPaymentLinkStatus($link_id);
    
    if ($linkStatus['paid']) {
        $pmPaymentId = $linkStatus['payment_id'] ?? '';
        $now = date('Y-m-d H:i:s');
        
        $upd = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ?, transaction_id = ?, paymongo_payment_id = ? WHERE paymongo_checkout_id = ? AND tenant_id = ? AND status = 'pending'");
        $upd->bind_param("ssssi", $now, $pmPaymentId, $pmPaymentId, $link_id, $tenant_id);
        $upd->execute();
        $upd->close();

        // Redirect to success page
        header("Location: payment_success.php?link_id=" . urlencode($link_id));
        exit();
    } else {
        $success = "Payment is being processed. It will be verified automatically once confirmed by GCash.";
    }
}
if (isset($_GET['paymongo']) && $_GET['paymongo'] === 'cancelled') {
    $error = "Payment was cancelled. You can try again.";
}

// ── Handle Pay via GCash (PayMongo Payment Link) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_gcash'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    $amount    = floatval($_POST['amount']);
    $for_month = $_POST['for_month'] ?? $current_month;

    if ($amount < 100) {
        $error = "Minimum payment amount is ₱100.00 (PayMongo requirement).";
    } elseif (!$paymongo_ready) {
        $error = "Online GCash payment is not available right now. Contact your admin.";
    } else {
        $monthLabel  = date('F Y', strtotime($for_month . '-01'));
        $description = "Rent - {$monthLabel} - " . htmlspecialchars($tenant['name']) . " (Unit {$unit_number})";
        $remarks     = "tenant_id:{$tenant_id}|for_month:{$for_month}|user_id:{$_SESSION['user_id']}";

        $result = $paymongo->createPaymentLink([
            'amount'      => $amount,
            'description' => $description,
            'remarks'     => $remarks,
        ]);

        if ($result['success']) {
            $link_id      = $result['link_id'];
            $checkout_url  = $result['checkout_url'];
            $payment_date = date('Y-m-d');

            // Save a pending payment record
            $ins = $conn->prepare("INSERT INTO payments (tenant_id, amount, payment_method, payment_date, for_month, paymongo_checkout_id, status, payment_type) VALUES (?, ?, 'paymongo_gcash', ?, ?, ?, 'pending', 'rent')");
            $ins->bind_param("idsss", $tenant_id, $amount, $payment_date, $for_month, $link_id);
            $ins->execute();
            $ins->close();

            // Redirect tenant to PayMongo GCash checkout page
            header("Location: " . $checkout_url);
            exit();
        } else {
            $error = "Payment failed: " . ($result['error'] ?? 'Unknown error. Please try again.');
        }
    }
}

// ── Handle manual GCash payment upload ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_manual_gcash'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    $amount       = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $for_month    = $_POST['for_month'] ?? $current_month;
    $reference_no = trim($_POST['reference_no'] ?? '');
    $proof_file   = '';

    if ($amount <= 0) {
        $error = "Please enter a valid amount.";
    }

    // Handle proof screenshot upload
    if (!isset($error) && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === 0) {
        $upload_dir = '../uploads/payments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $allowed_ext   = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
        $allowed_mime  = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $ext           = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
        $finfo         = new finfo(FILEINFO_MIME_TYPE);
        $detected_mime = $finfo->file($_FILES['proof_file']['tmp_name']);

        if (!in_array($ext, $allowed_ext)) {
            $error = "Invalid file type. Allowed: " . implode(', ', $allowed_ext);
        } elseif (!in_array($detected_mime, $allowed_mime)) {
            $error = "File content does not match its extension.";
        } elseif ($_FILES['proof_file']['size'] > 5 * 1024 * 1024) {
            $error = "File too large. Maximum is 5MB.";
        } else {
            $proof_file = 'payment_' . $tenant_id . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_dir . $proof_file)) {
                $error = "Failed to upload file.";
            }
        }
    }

    if (!isset($error)) {
        $stmt = $conn->prepare("INSERT INTO payments (tenant_id, amount, payment_method, reference_no, payment_date, for_month, proof_file, status) VALUES (?, ?, 'manual_gcash', ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("idssss", $tenant_id, $amount, $reference_no, $payment_date, $for_month, $proof_file);

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: payments.php?uploaded=1");
            exit();
        } else {
            $error = "Failed to record payment.";
        }
        $stmt->close();
    }
}

// ── Handle cash payment upload ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_cash'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    $amount       = floatval($_POST['amount']);
    $payment_date = $_POST['payment_date'];
    $for_month    = $_POST['for_month'] ?? $current_month;
    $proof_file   = '';

    if ($amount <= 0) {
        $error = "Please enter a valid amount.";
    }

    if (!isset($error) && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === 0) {
        $upload_dir = '../uploads/payments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $ext = strtolower(pathinfo($_FILES['proof_file']['name'], PATHINFO_EXTENSION));
        $proof_file = 'payment_' . $tenant_id . '_' . time() . '.' . $ext;
        move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_dir . $proof_file);
    }

    if (!isset($error)) {
        $stmt = $conn->prepare("INSERT INTO payments (tenant_id, amount, payment_method, payment_date, for_month, proof_file, status) VALUES (?, ?, 'cash', ?, ?, ?, 'pending')");
        $stmt->bind_param("idsss", $tenant_id, $amount, $payment_date, $for_month, $proof_file);

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: payments.php?uploaded=1");
            exit();
        } else {
            $error = "Failed to record payment.";
        }
        $stmt->close();
    }
}

// ── Handle payment cancellation ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $stmt = $conn->prepare("UPDATE payments SET status = 'cancelled' WHERE payment_id = ? AND tenant_id = ? AND status = 'pending'");
    $stmt->bind_param("ii", $payment_id, $tenant_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => $affected > 0]);
        exit();
    }
    header("Location: payments.php?cancelled=1");
    exit();
}

// ── Auto-verify stuck PayMongo payments (polling fallback) ───────────
try {
    $pending_pm = $conn->prepare("SELECT payment_id, paymongo_checkout_id FROM payments WHERE tenant_id = ? AND status = 'pending' AND payment_method = 'paymongo_gcash' AND paymongo_checkout_id IS NOT NULL AND paymongo_checkout_id != '' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $pending_pm->bind_param("i", $tenant_id);
    $pending_pm->execute();
    $pm_result = $pending_pm->get_result();

    while ($pm_row = $pm_result->fetch_assoc()) {
        $linkCheck = $paymongo->checkPaymentLinkStatus($pm_row['paymongo_checkout_id']);
        if ($linkCheck['paid']) {
            $now = date('Y-m-d H:i:s');
            $pmPid = $linkCheck['payment_id'] ?? '';
            $fix = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ?, transaction_id = ?, paymongo_payment_id = ? WHERE payment_id = ? AND status = 'pending'");
            $fix->bind_param("sssi", $now, $pmPid, $pmPid, $pm_row['payment_id']);
            $fix->execute();
            $fix->close();
        }
    }
    $pending_pm->close();
} catch (Throwable $e) {}

// ── Load payment history ─────────────────────────────────────────────
$payments_stmt = $conn->prepare("SELECT * FROM payments WHERE tenant_id = ? ORDER BY created_at DESC");
$payments_stmt->bind_param("i", $tenant_id);
$payments_stmt->execute();
$payments = $payments_stmt->get_result();

// Summary stats
$stats_stmt = $conn->prepare("SELECT 
    COALESCE(SUM(CASE WHEN status='verified' THEN amount END), 0) as total_paid,
    COALESCE(SUM(CASE WHEN status='pending' THEN 1 END), 0) as pending_count,
    COALESCE(SUM(CASE WHEN status='verified' THEN 1 END), 0) as verified_count
    FROM payments WHERE tenant_id = ?");
$stats_stmt->bind_param("i", $tenant_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Flash messages
if (isset($_GET['cancelled'])) $success = "Payment has been cancelled.";
if (isset($_GET['uploaded']))  $success = "Payment submitted! Awaiting admin verification.";

include '../includes/header.php';
?>

<div class="container mt-4 tenant-ui">

    <?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ═══════ BILLING SUMMARY ═══════ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-3 text-center border-end">
                    <small class="text-muted d-block">Unit</small>
                    <h4 class="mb-0 text-primary fw-bold"><?= htmlspecialchars($unit_number) ?></h4>
                </div>
                <div class="col-md-3 text-center border-end">
                    <small class="text-muted d-block">Month</small>
                    <h5 class="mb-0"><?= date('F Y') ?></h5>
                </div>
                <div class="col-md-3 text-center border-end">
                    <small class="text-muted d-block">Amount Due</small>
                    <h4 class="mb-0 <?= $balance_due > 0 ? 'text-danger' : 'text-success' ?> fw-bold">
                        ₱<?= number_format($balance_due, 2) ?>
                    </h4>
                    <?php if ($credit_amount > 0): ?>
                    <small class="text-success"><i class="fas fa-arrow-down me-1"></i>₱<?= number_format($credit_amount, 2) ?> credit</small>
                    <?php elseif ($paid_this_month > 0 && $balance_due > 0): ?>
                    <small class="text-muted">₱<?= number_format($paid_this_month, 2) ?> paid</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-3 text-center">
                    <small class="text-muted d-block">Due Date</small>
                    <h5 class="mb-0"><?= date('M d, Y', strtotime($due_date)) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <?php if ($advance_credit > 0 || ($deposit_paid && $deposit_amount > 0)): ?>
    <!-- ═══════ DEPOSIT & ADVANCE INFO ═══════ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <div class="row g-2 text-center">
                <?php if ($deposit_paid && $deposit_amount > 0): ?>
                <div class="col-md-6">
                    <small class="text-muted"><i class="fas fa-shield-alt me-1"></i>Security Deposit</small>
                    <div class="fw-bold">₱<?= number_format($deposit_amount, 2) ?></div>
                    <small class="text-muted">Held — returned at lease end</small>
                </div>
                <?php endif; ?>
                <?php if ($advance_credit > 0): ?>
                <div class="col-md-6">
                    <small class="text-muted"><i class="fas fa-forward me-1"></i>Advance Payment</small>
                    <div class="fw-bold text-success">₱<?= number_format($advance_credit, 2) ?></div>
                    <small class="text-muted">Covers <?= $advance_months_covered ?> month<?= $advance_months_covered != 1 ? 's' : '' ?> of rent</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══════ STATS ROW ═══════ -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body py-3">
                    <div class="text-success mb-1"><i class="fas fa-check-circle fa-lg"></i></div>
                    <h5 class="mb-0"><?= (int)$stats['verified_count'] ?></h5>
                    <small class="text-muted">Verified</small>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body py-3">
                    <div class="text-warning mb-1"><i class="fas fa-clock fa-lg"></i></div>
                    <h5 class="mb-0"><?= (int)$stats['pending_count'] ?></h5>
                    <small class="text-muted">Pending</small>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body py-3">
                    <div class="text-info mb-1"><i class="fas fa-wallet fa-lg"></i></div>
                    <h5 class="mb-0">₱<?= number_format((float)$stats['total_paid'], 2) ?></h5>
                    <small class="text-muted">Total Paid</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT: Make a Payment -->
        <div class="col-lg-5 order-lg-2">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i>Make a Payment</h5>
                </div>
                <div class="card-body">

                    <!-- Method Selector -->
                    <div class="d-flex gap-2 mb-3" id="methodSelector">
                        <?php if ($paymongo_ready): ?>
                        <button type="button" class="btn btn-outline-primary flex-fill method-btn active" data-method="gcash_online" style="border-color:#007DFE; color:#007DFE;">
                            <i class="fas fa-mobile-alt me-1"></i>GCash
                        </button>
                        <?php endif; ?>
                        <?php if ($gcash_enabled): ?>
                        <button type="button" class="btn btn-outline-secondary flex-fill method-btn <?= !$paymongo_ready ? 'active' : '' ?>" data-method="gcash_manual">
                            <i class="fas fa-upload me-1"></i>Manual GCash
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-dark flex-fill method-btn <?= !$paymongo_ready && !$gcash_enabled ? 'active' : '' ?>" data-method="cash">
                            <i class="fas fa-money-bill-wave me-1"></i>Cash
                        </button>
                    </div>

                    <!-- ====== PAY VIA GCASH (PayMongo) ====== -->
                    <?php if ($paymongo_ready): ?>
                    <div class="method-panel" id="panel-gcash_online">
                        <div class="text-center mb-3">
                            <span class="badge px-3 py-2 text-white" style="background:#007DFE;">
                                <i class="fas fa-bolt me-1"></i> Pay via GCash — Auto-Verified
                            </span>
                        </div>

                        <?php if ($credit_amount > 0): ?>
                        <div class="alert alert-success border small mb-3">
                            <i class="fas fa-check-circle me-1"></i>
                            This month is <strong>fully covered</strong> by your ₱<?= number_format($credit_amount, 2) ?> credit from overpayment. You can still pay ahead for future months.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-light border small mb-3">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            You'll be redirected to GCash to pay. Once paid, your payment is <strong>automatically verified</strong> — no screenshot needed.
                        </div>
                        <?php endif; ?>

                        <form method="POST" id="gcashPayForm">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-lg text-center fw-bold" 
                                       name="amount" step="0.01" min="100" 
                                       value="<?= $balance_due > 0 ? number_format($balance_due, 2, '.', '') : number_format($rent_amount, 2, '.', '') ?>" required>
                                <div class="form-text">Minimum ₱100.00<?php if ($credit_amount > 0): ?> · Overpayments carry forward to next month<?php endif; ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">For Month <span class="text-danger">*</span></label>
                                <input type="month" class="form-control" name="for_month" value="<?= $current_month ?>" required>
                            </div>
                            <button type="submit" name="pay_gcash" class="btn w-100 text-white fw-semibold py-2" style="background:#007DFE; font-size: 1.05rem;">
                                <i class="fas fa-mobile-alt me-2"></i>Pay via GCash
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- ====== MANUAL GCASH ====== -->
                    <?php if ($gcash_enabled): ?>
                    <div class="method-panel d-none" id="panel-gcash_manual">
                        <div class="text-center mb-3">
                            <span class="badge px-3 py-2 text-white" style="background:#007DFE;">
                                <i class="fas fa-upload me-1"></i> Manual GCash — Upload Proof
                            </span>
                        </div>

                        <div class="border rounded-3 p-3 mb-3" style="background: linear-gradient(135deg, #007DFE08, #007DFE15);">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <small class="text-muted d-block">Send to GCash Number</small>
                                    <span class="fs-5 fw-bold" id="gcashNumberDisplay"><?= htmlspecialchars($gcash_number) ?></span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyGcashNumber()" title="Copy">
                                    <i class="fas fa-copy" id="copyIcon"></i>
                                </button>
                            </div>
                            <div>
                                <small class="text-muted">Account Name</small><br>
                                <strong><?= htmlspecialchars($gcash_name) ?></strong>
                            </div>
                            <?php if (!empty($gcash_qr_image)): ?>
                            <div class="text-center mt-3">
                                <img src="../uploads/<?= htmlspecialchars($gcash_qr_image) ?>" 
                                     alt="GCash QR" class="img-fluid rounded border" style="max-width: 160px; cursor:pointer;" 
                                     onclick="this.style.maxWidth = this.style.maxWidth === '160px' ? '300px' : '160px'">
                                <div class="form-text">Tap QR to enlarge</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount" step="0.01" min="1" 
                                       value="<?= number_format($rent_amount, 2, '.', '') ?>" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold">For Month</label>
                                    <input type="month" class="form-control" name="for_month" value="<?= $current_month ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Payment Date</label>
                                    <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">GCash Reference No.</label>
                                <input type="text" class="form-control" name="reference_no" placeholder="e.g. 1234 567 890">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">GCash Screenshot <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,.webp" required>
                            </div>
                            <button type="submit" name="upload_manual_gcash" class="btn w-100 text-white" style="background:#007DFE;">
                                <i class="fas fa-paper-plane me-2"></i>Submit GCash Payment
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- ====== CASH ====== -->
                    <div class="method-panel d-none" id="panel-cash">
                        <div class="text-center mb-3">
                            <span class="badge bg-dark bg-opacity-10 text-dark px-3 py-2">
                                <i class="fas fa-hand-holding-usd me-1"></i> Cash Payment — No fees
                            </span>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Amount (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="amount" step="0.01" min="1" 
                                       value="<?= number_format($rent_amount, 2, '.', '') ?>" required>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold">For Month</label>
                                    <input type="month" class="form-control" name="for_month" value="<?= $current_month ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold">Payment Date</label>
                                    <input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Receipt / Proof <small class="text-muted fw-normal">(optional)</small></label>
                                <input type="file" class="form-control" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,.webp">
                            </div>
                            <button type="submit" name="upload_cash" class="btn btn-dark w-100">
                                <i class="fas fa-paper-plane me-2"></i>Submit Cash Payment
                            </button>
                        </form>
                    </div>

                    <?php if (!$paymongo_ready && !$gcash_enabled): ?>
                    <div class="form-text text-center mt-2">
                        <i class="fas fa-info-circle me-1"></i>GCash online payment coming soon.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tips -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="mb-2"><i class="fas fa-lightbulb text-warning me-2"></i>Tips</h6>
                    <ul class="list-unstyled small text-muted mb-0">
                        <?php if ($paymongo_ready): ?>
                        <li class="mb-1"><i class="fas fa-bolt text-primary me-2"></i><strong>GCash</strong> online payments are instant and auto-verified.</li>
                        <?php endif; ?>
                        <li class="mb-1"><i class="fas fa-clock text-warning me-2"></i>Manual/cash payments are verified within 24 hours.</li>
                        <li class="mb-1"><i class="fas fa-file-pdf text-danger me-2"></i>A PDF receipt is emailed after payment is confirmed.</li>
                        <li><i class="fas fa-ban text-danger me-2"></i>You can cancel pending payments anytime.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- RIGHT: Payment History -->
        <div class="col-lg-7 order-lg-1">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>Payment History</h5>
                    <?php if ($payments->num_rows > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="exportPaymentsCsv" title="Export CSV">
                        <i class="fas fa-download me-1"></i>CSV
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if ($payments->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tenantPaymentsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Month</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($p = $payments->fetch_assoc()): 
                                    $pMethod = $p['payment_method'] ?? 'cash';
                                    $methodMap = [
                                        'cash'           => ['Cash',         'fa-money-bill-wave', 'bg-dark bg-opacity-75'],
                                        'manual_gcash'   => ['GCash',        'fa-mobile-alt',      'text-white', '#007DFE'],
                                        'paymongo_gcash' => ['GCash Online', 'fa-mobile-alt',      'text-white', '#007DFE'],
                                    ];
                                    $pm = $methodMap[$pMethod] ?? $methodMap['cash'];
                                    $pmStyle = isset($pm[3]) ? "background:{$pm[3]};" : '';
                                ?>
                                <tr>
                                    <td>
                                        <?php 
                                            $fm = $p['for_month'] ?? '';
                                            echo $fm ? date('M Y', strtotime($fm . '-01')) : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td class="fw-semibold">₱<?= number_format($p['amount'], 2) ?></td>
                                    <td>
                                        <span class="badge <?= $pm[2] ?>" <?php if ($pmStyle) echo "style=\"$pmStyle\""; ?>>
                                            <i class="fas <?= $pm[1] ?> me-1"></i><?= $pm[0] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $statusClass = [
                                                'pending'   => 'bg-warning text-dark',
                                                'verified'  => 'bg-success',
                                                'rejected'  => 'bg-danger',
                                                'cancelled' => 'bg-secondary',
                                            ];
                                            $statusLabel = [
                                                'pending'   => 'Pending',
                                                'verified'  => 'Paid',
                                                'rejected'  => 'Rejected',
                                                'cancelled' => 'Cancelled',
                                            ];
                                        ?>
                                        <span class="badge <?= $statusClass[$p['status']] ?? 'bg-secondary' ?>">
                                            <?= $statusLabel[$p['status']] ?? ucfirst($p['status']) ?>
                                        </span>
                                    </td>
                                    <td><small><?= date('M d, Y', strtotime($p['payment_date'])) ?></small></td>
                                    <td class="text-end">
                                        <?php if ($p['proof_file']): ?>
                                        <a href="../uploads/payments/<?= $p['proof_file'] ?>" target="_blank" 
                                           class="btn btn-sm btn-outline-secondary me-1" title="View proof">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($p['status'] === 'verified' && !empty($p['paymongo_checkout_id'])): ?>
                                        <a href="payment_success.php?link_id=<?= urlencode($p['paymongo_checkout_id']) ?>" 
                                           class="btn btn-sm btn-outline-success me-1" title="View receipt">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($p['status'] === 'pending'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger cancel-payment-btn" 
                                                data-id="<?= $p['payment_id'] ?>" title="Cancel">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-receipt fa-3x text-muted mb-3 d-block"></i>
                        <h6 class="text-muted">No payments yet</h6>
                        <p class="text-muted small mb-0">Click "Pay via GCash" to make your first payment.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== Method Selector =====
    document.querySelectorAll('.method-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.method-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.method-panel').forEach(function(p) { p.classList.add('d-none'); });
            var panel = document.getElementById('panel-' + this.dataset.method);
            if (panel) panel.classList.remove('d-none');
        });
    });

    // ===== Cancel Payment (AJAX) =====
    document.querySelectorAll('.cancel-payment-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Cancel this payment?')) return;
            var paymentId = this.dataset.id;
            var button = this;
            var row = button.closest('tr');
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            var formData = new FormData();
            formData.append('cancel_payment', '1');
            formData.append('payment_id', paymentId);

            fetch('payments.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    var badge = row.querySelector('td:nth-child(4) .badge');
                    if (badge) { badge.className = 'badge bg-secondary'; badge.textContent = 'Cancelled'; }
                    button.remove();
                } else {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-times"></i>';
                    alert('Unable to cancel.');
                }
            })
            .catch(function() {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-times"></i>';
            });
        });
    });

    // ===== Export CSV =====
    var exportBtn = document.getElementById('exportPaymentsCsv');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            var table = document.getElementById('tenantPaymentsTable');
            if (!table) return;
            var rows = table.querySelectorAll('tbody tr');
            var csv = 'Month,Amount,Method,Status,Date\n';
            rows.forEach(function(row) {
                var cells = row.cells;
                csv += [0,1,2,3,4].map(function(i) {
                    return '"' + cells[i].textContent.trim().replace(/"/g, '""') + '"';
                }).join(',') + '\n';
            });
            var blob = new Blob([csv], { type: 'text/csv' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'payments_' + new Date().toISOString().slice(0,10) + '.csv';
            a.click();
        });
    }
});

function copyGcashNumber() {
    var num = document.getElementById('gcashNumberDisplay');
    if (!num) return;
    navigator.clipboard.writeText(num.textContent.trim()).then(function() {
        var icon = document.getElementById('copyIcon');
        icon.className = 'fas fa-check text-success';
        setTimeout(function() { icon.className = 'fas fa-copy'; }, 1500);
    });
}
</script>

<?php include '../includes/footer.php'; ?>
