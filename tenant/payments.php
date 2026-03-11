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

<style>
/* ── Payments Page Redesign ── */
.pay-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #0d6efd22 100%);
    border-radius: 16px;
    color: #fff;
    padding: 1.5rem 1.75rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.pay-hero::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: rgba(78, 214, 193, 0.08);
}
.pay-hero .hero-label { font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; opacity: .65; }
.pay-hero .hero-amount { font-size: 2.4rem; font-weight: 800; line-height: 1.1; }
.pay-hero .hero-unit  { font-size: .9rem; opacity: .7; }
.balance-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .35rem .9rem; border-radius: 50px;
    font-size: .8rem; font-weight: 600;
}
.balance-pill.due   { background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }
.balance-pill.paid  { background: rgba(34,197,94,.15); color: #86efac;  border: 1px solid rgba(34,197,94,.3); }
.balance-pill.credit{ background: rgba(78,214,193,.15); color: #4ED6C1; border: 1px solid rgba(78,214,193,.3); }

/* Stat cards */
.stat-card {
    border-radius: 12px;
    border: none;
    transition: transform .15s;
}
.stat-card:hover { transform: translateY(-2px); }
.stat-icon-wrap {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}

/* Method tabs */
.method-tabs { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
.method-tab {
    flex: 1; padding: .6rem .5rem; border-radius: 10px; border: 1.5px solid #dee2e6;
    background: transparent; cursor: pointer; font-size: .8rem; font-weight: 600;
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    transition: all .18s; color: #64748b;
}
.method-tab i { font-size: 1.1rem; }
.method-tab.active { border-color: #007DFE; background: #007DFE12; color: #007DFE; }
body.dark-mode .method-tab { border-color: #334155; color: #94a3b8; background: transparent; }
body.dark-mode .method-tab.active { border-color: #007DFE; background: #007DFE18; color: #60a5fa; }
.method-tab.cash-tab.active { border-color: #22c55e; background: #22c55e12; color: #22c55e; }

/* Payment form card */
.pay-form-card {
    border-radius: 14px; border: none;
    box-shadow: 0 2px 16px rgba(0,0,0,.07);
}
body.dark-mode .pay-form-card { box-shadow: 0 2px 16px rgba(0,0,0,.3); }

/* GCash info box */
.gcash-info-box {
    border-radius: 12px;
    background: linear-gradient(135deg, #007DFE08 0%, #007DFE18 100%);
    border: 1.5px solid #007DFE30;
    padding: 1rem;
}

/* History card */
.history-item {
    display: flex; align-items: center; gap: .9rem;
    padding: .85rem 1rem; border-bottom: 1px solid rgba(0,0,0,.06);
    transition: background .12s;
}
.history-item:last-child { border-bottom: none; }
.history-item:hover { background: rgba(0,0,0,.02); }
body.dark-mode .history-item { border-bottom-color: rgba(255,255,255,.06); }
body.dark-mode .history-item:hover { background: rgba(255,255,255,.03); }
.history-icon {
    width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: .9rem;
}
.history-meta { flex: 1; min-width: 0; }
.history-meta .month { font-weight: 600; font-size: .88rem; }
.history-meta .detail { font-size: .75rem; color: #94a3b8; }
.history-amount { font-weight: 700; font-size: .95rem; text-align: right; }
.history-actions { display: flex; gap: .35rem; align-items: center; }
.status-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
    display: inline-block; margin-right: 3px;
}
.status-dot.verified  { background: #22c55e; }
.status-dot.pending   { background: #f59e0b; }
.status-dot.rejected  { background: #ef4444; }
.status-dot.cancelled { background: #94a3b8; }

/* Deposit/advance ribbon */
.info-ribbon {
    border-radius: 12px;
    background: linear-gradient(90deg, #4ED6C108, #4ED6C122);
    border: 1.5px solid #4ED6C133;
    padding: .75rem 1rem;
}
</style>

<div class="container-fluid px-3 px-md-4 mt-3 pb-4 tenant-ui">

    <?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3">
        <i class="fas fa-check-circle me-2"></i><?= $success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3">
        <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ═══════ HERO BILLING BANNER ═══════ -->
    <div class="pay-hero mb-4">
        <div class="row align-items-center g-3">
            <div class="col-sm-7">
                <div class="hero-label mb-1">My Payments · Unit <?= htmlspecialchars($unit_number) ?></div>
                <div class="hero-amount">
                    ₱<?= number_format($balance_due, 2) ?>
                    <?php if ($credit_amount > 0): ?>
                    <span class="balance-pill credit ms-2" style="font-size:1rem;">
                        <i class="fas fa-arrow-down"></i> ₱<?= number_format($credit_amount, 2) ?> credit
                    </span>
                    <?php elseif ($balance_due <= 0): ?>
                    <span class="balance-pill paid ms-2" style="font-size:1rem;">
                        <i class="fas fa-check"></i> Fully Paid
                    </span>
                    <?php else: ?>
                    <span class="balance-pill due ms-2" style="font-size:1rem;">
                        <i class="fas fa-exclamation"></i> Due
                    </span>
                    <?php endif; ?>
                </div>
                <div class="hero-unit mt-1"><?= date('F Y') ?> · Due by <?= date('M d', strtotime($due_date)) ?></div>
            </div>
            <div class="col-sm-5">
                <div class="row g-2 text-center">
                    <div class="col-6">
                        <div style="background:rgba(255,255,255,.08);border-radius:10px;padding:.6rem .5rem;">
                            <div style="font-size:1.2rem;font-weight:800;"><?= (int)$stats['verified_count'] ?></div>
                            <div style="font-size:.7rem;opacity:.7;">Verified</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background:rgba(255,255,255,.08);border-radius:10px;padding:.6rem .5rem;">
                            <div style="font-size:1.2rem;font-weight:800;"><?= (int)$stats['pending_count'] ?></div>
                            <div style="font-size:.7rem;opacity:.7;">Pending</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div style="background:rgba(78,214,193,.15);border-radius:10px;padding:.6rem .5rem;">
                            <div style="font-size:1.1rem;font-weight:800;color:#4ED6C1;">₱<?= number_format((float)$stats['total_paid'], 2) ?></div>
                            <div style="font-size:.7rem;opacity:.7;">Total Paid</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($advance_credit > 0 || ($deposit_paid && $deposit_amount > 0)): ?>
    <!-- Deposit & Advance Ribbon -->
    <div class="info-ribbon mb-4 d-flex flex-wrap gap-3">
        <?php if ($deposit_paid && $deposit_amount > 0): ?>
        <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;border-radius:9px;background:#4ED6C120;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-shield-alt" style="color:#4ED6C1;"></i>
            </div>
            <div>
                <div style="font-size:.72rem;color:#94a3b8;">Security Deposit</div>
                <div style="font-weight:700;">₱<?= number_format($deposit_amount, 2) ?> <span style="font-size:.75rem;font-weight:400;color:#94a3b8;">· held, returned at lease end</span></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($advance_credit > 0): ?>
        <div class="d-flex align-items-center gap-2">
            <div style="width:34px;height:34px;border-radius:9px;background:#22c55e20;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-forward" style="color:#22c55e;"></i>
            </div>
            <div>
                <div style="font-size:.72rem;color:#94a3b8;">Advance Payment</div>
                <div style="font-weight:700;color:#22c55e;">₱<?= number_format($advance_credit, 2) ?> <span style="font-size:.75rem;font-weight:400;color:#94a3b8;">· covers <?= $advance_months_covered ?> month<?= $advance_months_covered != 1 ? 's' : '' ?></span></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ═══════ MAKE A PAYMENT ═══════ -->
        <div class="col-lg-5 order-lg-2">
            <div class="pay-form-card card mb-3">
                <div class="card-body p-3 p-md-4">
                    <h6 class="fw-bold mb-3" style="font-size:.95rem;">
                        <i class="fas fa-plus-circle me-2" style="color:#007DFE;"></i>Make a Payment
                    </h6>

                    <!-- Method tabs -->
                    <div class="method-tabs">
                        <?php if ($paymongo_ready): ?>
                        <button type="button" class="method-tab active" data-method="gcash_online">
                            <i class="fas fa-mobile-alt" style="color:#007DFE;"></i>
                            <span>GCash</span>
                        </button>
                        <?php endif; ?>
                        <?php if ($gcash_enabled): ?>
                        <button type="button" class="method-tab <?= !$paymongo_ready ? 'active' : '' ?>" data-method="gcash_manual">
                            <i class="fas fa-upload" style="color:#007DFE;"></i>
                            <span>Manual</span>
                        </button>
                        <?php endif; ?>
                        <button type="button" class="method-tab cash-tab <?= !$paymongo_ready && !$gcash_enabled ? 'active' : '' ?>" data-method="cash">
                            <i class="fas fa-money-bill-wave" style="color:#22c55e;"></i>
                            <span>Cash</span>
                        </button>
                    </div>

                    <!-- ── GCash Online (PayMongo) ── -->
                    <?php if ($paymongo_ready): ?>
                    <div class="method-panel" id="panel-gcash_online">
                        <?php if ($credit_amount > 0): ?>
                        <div class="alert alert-success border-0 rounded-3 small py-2 mb-3">
                            <i class="fas fa-check-circle me-1"></i>
                            This month is <strong>fully covered</strong> by your ₱<?= number_format($credit_amount, 2) ?> credit. You can still pay ahead.
                        </div>
                        <?php else: ?>
                        <div class="d-flex align-items-center gap-2 mb-3 small" style="color:#60a5fa;">
                            <i class="fas fa-bolt"></i>
                            <span>Redirects to GCash · <strong>Auto-verified instantly</strong></span>
                        </div>
                        <?php endif; ?>
                        <form method="POST" id="gcashPayForm">
                            <?= csrf_input() ?>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">Amount (₱) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text fw-bold">₱</span>
                                    <input type="number" class="form-control form-control-lg fw-bold text-center"
                                           name="amount" step="0.01" min="100"
                                           value="<?= $balance_due > 0 ? number_format($balance_due, 2, '.', '') : number_format($rent_amount, 2, '.', '') ?>" required>
                                </div>
                                <div class="form-text">Min ₱100 · Overpayments carry forward</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">For Month <span class="text-danger">*</span></label>
                                <input type="month" class="form-control" name="for_month" value="<?= $current_month ?>" required>
                            </div>
                            <button type="submit" name="pay_gcash" class="btn w-100 text-white fw-bold py-2 rounded-3" style="background:#007DFE; font-size:1rem;">
                                <i class="fas fa-mobile-alt me-2"></i>Pay via GCash
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- ── Manual GCash ── -->
                    <?php if ($gcash_enabled): ?>
                    <div class="method-panel d-none" id="panel-gcash_manual">
                        <div class="gcash-info-box mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Send to</div>
                                    <div class="fw-bold fs-5" id="gcashNumberDisplay"><?= htmlspecialchars($gcash_number) ?></div>
                                    <div style="font-size:.8rem;color:#94a3b8;"><?= htmlspecialchars($gcash_name) ?></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-2" onclick="copyGcashNumber()" title="Copy number">
                                    <i class="fas fa-copy" id="copyIcon"></i>
                                </button>
                            </div>
                            <?php if (!empty($gcash_qr_image)): ?>
                            <div class="text-center mt-2">
                                <img src="../uploads/<?= htmlspecialchars($gcash_qr_image) ?>"
                                     alt="GCash QR" class="img-fluid rounded-2 border" style="max-width:140px;cursor:pointer;"
                                     onclick="this.style.maxWidth = this.style.maxWidth === '140px' ? '280px' : '140px'">
                                <div class="form-text">Tap to enlarge</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_input() ?>
                            <div class="mb-2">
                                <label class="form-label fw-semibold small mb-1">Amount (₱) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="amount" step="0.01" min="1"
                                           value="<?= number_format($rent_amount, 2, '.', '') ?>" required>
                                </div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">For Month</label>
                                    <input type="month" class="form-control form-control-sm" name="for_month" value="<?= $current_month ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">Date Paid</label>
                                    <input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-semibold small mb-1">Reference No.</label>
                                <input type="text" class="form-control form-control-sm" name="reference_no" placeholder="e.g. 1234 567 890">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">GCash Screenshot <span class="text-danger">*</span></label>
                                <input type="file" class="form-control form-control-sm" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,.webp" required>
                            </div>
                            <button type="submit" name="upload_manual_gcash" class="btn w-100 text-white rounded-3 fw-semibold" style="background:#007DFE;">
                                <i class="fas fa-paper-plane me-2"></i>Submit GCash Proof
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- ── Cash ── -->
                    <div class="method-panel d-none" id="panel-cash">
                        <div class="d-flex align-items-center gap-2 mb-3 small" style="color:#22c55e;">
                            <i class="fas fa-hand-holding-usd"></i>
                            <span>Cash payment · No fees · Admin verifies within 24h</span>
                        </div>
                        <form method="POST" enctype="multipart/form-data">
                            <?= csrf_input() ?>
                            <div class="mb-2">
                                <label class="form-label fw-semibold small mb-1">Amount (₱) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="amount" step="0.01" min="1"
                                           value="<?= number_format($rent_amount, 2, '.', '') ?>" required>
                                </div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">For Month</label>
                                    <input type="month" class="form-control form-control-sm" name="for_month" value="<?= $current_month ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold small mb-1">Date Paid</label>
                                    <input type="date" class="form-control form-control-sm" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small mb-1">Receipt / Proof <small class="text-muted fw-normal">(optional)</small></label>
                                <input type="file" class="form-control form-control-sm" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,.webp">
                            </div>
                            <button type="submit" name="upload_cash" class="btn btn-dark w-100 rounded-3 fw-semibold">
                                <i class="fas fa-paper-plane me-2"></i>Submit Cash Payment
                            </button>
                        </form>
                    </div>

                    <?php if (!$paymongo_ready && !$gcash_enabled): ?>
                    <p class="text-center text-muted small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>GCash online payment coming soon.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Tips -->
            <div class="card border-0 rounded-3" style="background:rgba(0,0,0,.03);">
                <div class="card-body py-2 px-3">
                    <div class="d-flex flex-column gap-1" style="font-size:.78rem;color:#94a3b8;">
                        <?php if ($paymongo_ready): ?>
                        <div><i class="fas fa-bolt me-2" style="color:#007DFE;"></i><strong style="color:inherit;">GCash Online</strong> — instant, auto-verified</div>
                        <?php endif; ?>
                        <div><i class="fas fa-clock me-2" style="color:#f59e0b;"></i>Manual/Cash verified within 24 hours</div>
                        <div><i class="fas fa-file-pdf me-2" style="color:#ef4444;"></i>PDF receipt emailed on confirmation</div>
                        <div><i class="fas fa-ban me-2" style="color:#ef4444;"></i>Pending payments can be cancelled anytime</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════ PAYMENT HISTORY ═══════ -->
        <div class="col-lg-7 order-lg-1">
            <div class="pay-form-card card">
                <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center py-3 px-3 px-md-4">
                    <h6 class="fw-bold mb-0" style="font-size:.95rem;">
                        <i class="fas fa-history me-2" style="color:#007DFE;"></i>Payment History
                    </h6>
                    <?php if ($payments->num_rows > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-2" id="exportPaymentsCsv">
                        <i class="fas fa-download me-1"></i><span class="d-none d-sm-inline">Export </span>CSV
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0" id="historyList">
                    <?php if ($payments->num_rows > 0):
                        $methodMap = [
                            'cash'           => ['Cash',         'fa-money-bill-wave', '#22c55e20', '#22c55e'],
                            'manual_gcash'   => ['GCash',        'fa-mobile-alt',      '#007DFE20', '#007DFE'],
                            'paymongo_gcash' => ['GCash Online', 'fa-bolt',            '#007DFE20', '#007DFE'],
                        ];
                        $statusLabel = ['pending'=>'Pending','verified'=>'Paid','rejected'=>'Rejected','cancelled'=>'Cancelled'];
                        $statusColor = ['pending'=>'#f59e0b','verified'=>'#22c55e','rejected'=>'#ef4444','cancelled'=>'#94a3b8'];
                        while ($p = $payments->fetch_assoc()):
                            $pm = $methodMap[$p['payment_method'] ?? 'cash'] ?? $methodMap['cash'];
                            $st = $p['status'] ?? 'pending';
                            $fm = $p['for_month'] ?? '';
                    ?>
                    <div class="history-item" id="hist-<?= $p['payment_id'] ?>">
                        <div class="history-icon" style="background:<?= $pm[2] ?>; color:<?= $pm[3] ?>;">
                            <i class="fas <?= $pm[1] ?>"></i>
                        </div>
                        <div class="history-meta">
                            <div class="month"><?= $fm ? date('F Y', strtotime($fm.'-01')) : '—' ?></div>
                            <div class="detail">
                                <span class="status-dot <?= $st ?>"></span>
                                <span><?= $statusLabel[$st] ?? ucfirst($st) ?></span>
                                <span class="mx-1">·</span>
                                <span><?= $pm[0] ?></span>
                                <span class="mx-1">·</span>
                                <span><?= date('M d, Y', strtotime($p['payment_date'])) ?></span>
                            </div>
                        </div>
                        <div class="history-amount">
                            <div>₱<?= number_format($p['amount'], 2) ?></div>
                            <div style="font-size:.7rem;font-weight:400;color:<?= $statusColor[$st] ?? '#94a3b8' ?>;"><?= $statusLabel[$st] ?? ucfirst($st) ?></div>
                        </div>
                        <div class="history-actions">
                            <?php if ($p['proof_file']): ?>
                            <a href="../uploads/payments/<?= $p['proof_file'] ?>" target="_blank"
                               class="btn btn-sm btn-outline-secondary rounded-2 p-1" title="View proof" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-eye" style="font-size:.75rem;"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($st === 'verified' && !empty($p['paymongo_checkout_id'])): ?>
                            <a href="payment_success.php?link_id=<?= urlencode($p['paymongo_checkout_id']) ?>"
                               class="btn btn-sm btn-outline-success rounded-2 p-1" title="Receipt" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-file-pdf" style="font-size:.75rem;"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($st === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-2 cancel-payment-btn p-1"
                                    data-id="<?= $p['payment_id'] ?>" title="Cancel" style="width:30px;height:30px;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-times" style="font-size:.75rem;"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <div class="text-center py-5 px-3">
                        <div style="width:64px;height:64px;border-radius:16px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                            <i class="fas fa-receipt" style="font-size:1.5rem;color:#94a3b8;"></i>
                        </div>
                        <h6 class="text-muted mb-1">No payments yet</h6>
                        <p class="text-muted small mb-0">Make your first payment using the form.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /row -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Method tabs ──
    document.querySelectorAll('.method-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.method-tab').forEach(function (t) { t.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.method-panel').forEach(function (p) { p.classList.add('d-none'); });
            var panel = document.getElementById('panel-' + this.dataset.method);
            if (panel) panel.classList.remove('d-none');
        });
    });

    // ── Cancel payment (AJAX) ──
    document.querySelectorAll('.cancel-payment-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Cancel this payment?')) return;
            var id = this.dataset.id, button = this;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:.75rem;"></i>';

            var fd = new FormData();
            fd.append('cancel_payment', '1');
            fd.append('payment_id', id);

            fetch('payments.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var item = document.getElementById('hist-' + id);
                    if (item) {
                        var dot  = item.querySelector('.status-dot');
                        var lbl  = item.querySelector('.history-meta .detail');
                        var amt  = item.querySelector('.history-amount div:last-child');
                        if (dot) { dot.className = 'status-dot cancelled'; }
                        if (amt) { amt.textContent = 'Cancelled'; amt.style.color = '#94a3b8'; }
                        button.remove();
                    }
                } else {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-times" style="font-size:.75rem;"></i>';
                    alert('Unable to cancel.');
                }
            })
            .catch(function () {
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-times" style="font-size:.75rem;"></i>';
            });
        });
    });

    // ── Export CSV ──
    var exportBtn = document.getElementById('exportPaymentsCsv');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            var items = document.querySelectorAll('#historyList .history-item');
            var csv = 'Month,Amount,Method,Status,Date\n';
            items.forEach(function (item) {
                var month  = item.querySelector('.month')?.textContent.trim() || '';
                var amount = item.querySelector('.history-amount div')?.textContent.trim() || '';
                var detail = item.querySelector('.detail')?.textContent.trim().split('·') || [];
                var status = (detail[0] || '').trim();
                var method = (detail[1] || '').trim();
                var date   = (detail[2] || '').trim();
                csv += [month, amount, method, status, date].map(function (v) {
                    return '"' + v.replace(/"/g, '""') + '"';
                }).join(',') + '\n';
            });
            var blob = new Blob([csv], { type: 'text/csv' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'payments_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
        });
    }
});

function copyGcashNumber() {
    var num = document.getElementById('gcashNumberDisplay');
    if (!num) return;
    navigator.clipboard.writeText(num.textContent.trim()).then(function () {
        var icon = document.getElementById('copyIcon');
        icon.className = 'fas fa-check text-success';
        setTimeout(function () { icon.className = 'fas fa-copy'; }, 1500);
    });
}
</script>

<?php include '../includes/footer.php'; ?>
