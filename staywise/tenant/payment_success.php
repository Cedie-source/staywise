<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/paymongo_helper.php';
require_once '../includes/receipt_generator.php';
require_once '../includes/email_helper.php';

// Tenant auth check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Payment Confirmation";

// Get tenant info
$tenant_stmt = $conn->prepare("SELECT tenant_id, name, email, unit_number, rent_amount FROM tenants WHERE user_id = ?");
$tenant_stmt->bind_param("i", $_SESSION['user_id']);
$tenant_stmt->execute();
$tenant = $tenant_stmt->get_result()->fetch_assoc();
$tenant_stmt->close();

if (!$tenant) {
    header("Location: ../logout.php");
    exit();
}

$tenant_id = $tenant['tenant_id'];

// Get the payment by link_id or payment_id
$payment = null;
$link_id = null;

if (isset($_GET['link_id'])) {
    $link_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['link_id']);
    $stmt = $conn->prepare("SELECT * FROM payments WHERE paymongo_checkout_id = ? AND tenant_id = ?");
    $stmt->bind_param("si", $link_id, $tenant_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} elseif (isset($_GET['payment_id'])) {
    $pid = intval($_GET['payment_id']);
    $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $pid, $tenant_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$payment) {
    header("Location: payments.php");
    exit();
}

// ── If still pending, try to verify via PayMongo polling ─────────────
if ($payment['status'] === 'pending' && !empty($payment['paymongo_checkout_id'])) {
    $paymongo = new PayMongoHelper($conn);
    $linkCheck = $paymongo->checkPaymentLinkStatus($payment['paymongo_checkout_id']);

    if ($linkCheck['paid']) {
        $now = date('Y-m-d H:i:s');
        $pmPid = $linkCheck['payment_id'] ?? '';
        $upd = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ?, transaction_id = ?, paymongo_payment_id = ? WHERE payment_id = ? AND status = 'pending'");
        $upd->bind_param("sssi", $now, $pmPid, $pmPid, $payment['payment_id']);
        $upd->execute();
        $upd->close();

        // Refresh the payment data
        $payment['status'] = 'verified';
        $payment['paid_at'] = $now;
        $payment['transaction_id'] = $pmPid;
    }
}

$is_paid = ($payment['status'] === 'verified');

// ── Generate PDF receipt if paid and not yet generated ────────────────
$receipt_file = '';
if ($is_paid) {
    // Check if receipt already exists for this payment
    $receiptDir = __DIR__ . '/../uploads/receipts';
    $existingReceipt = glob($receiptDir . '/receipt_' . $payment['payment_id'] . '_*.pdf');
    
    if (!empty($existingReceipt)) {
        $receipt_file = 'uploads/receipts/' . basename($existingReceipt[0]);
    } else {
        try {
            $receipt_file = ReceiptGenerator::generate([
                'payment_id'     => $payment['payment_id'],
                'tenant_name'    => $tenant['name'],
                'tenant_email'   => $tenant['email'],
                'unit_number'    => $tenant['unit_number'] ?? '',
                'amount'         => (float)$payment['amount'],
                'for_month'      => $payment['for_month'] ?? '',
                'payment_method' => $payment['payment_method'] ?? 'paymongo_gcash',
                'transaction_id' => $payment['transaction_id'] ?? '',
                'paid_at'        => $payment['paid_at'] ?? date('Y-m-d H:i:s'),
                'reference_no'   => $payment['reference_no'] ?? '',
            ]);
        } catch (Throwable $e) {
            // Receipt generation failed — not fatal
            error_log("Receipt generation error: " . $e->getMessage());
        }
    }

    // ── Email receipt to tenant (only once) ───────────────────────────
    // We use a simple flag check: if transaction_id is set and receipt exists
    if (!empty($receipt_file) && !empty($tenant['email'])) {
        // Check if email was already sent (avoid duplicate sends on page refresh)
        $emailSent = false;
        try {
            $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM email_logs WHERE to_email = ? AND type = 'payment_receipt' AND subject LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $subjectLike = '%Receipt%' . $payment['payment_id'] . '%';
            $chk->bind_param("ss", $tenant['email'], $subjectLike);
            $chk->execute();
            $emailSent = $chk->get_result()->fetch_assoc()['cnt'] > 0;
            $chk->close();
        } catch (Throwable $e) {
            // email_logs table might not exist — just proceed
        }

        if (!$emailSent) {
            $monthLabel = $payment['for_month'] ? date('F Y', strtotime($payment['for_month'] . '-01')) : date('F Y');
            $amountFmt  = number_format((float)$payment['amount'], 2);
            $receiptNo  = 'SW-' . str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT);

            $emailBody = "
                <p>Hi <strong>{$tenant['name']}</strong>,</p>
                <p>Your GCash payment has been confirmed! Here are the details:</p>
                <table style='border-collapse:collapse; width:100%; max-width:400px; margin:16px 0;'>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Receipt No.</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>{$receiptNo}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Month</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$monthLabel}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Amount</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold; color:#198754;'>PHP {$amountFmt}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Method</td><td style='padding:8px; border-bottom:1px solid #eee;'>GCash (PayMongo)</td></tr>
                </table>
                <p>Your PDF receipt is attached to this email. You can also download it from the StayWise tenant portal.</p>
                <p>Thank you for your payment!</p>
            ";

            try {
                // Use PHPMailer directly with attachment
                $phpmailerPath = __DIR__ . '/../vendor/phpmailer';
                if (file_exists($phpmailerPath . '/PHPMailer.php') && defined('SMTP_ENABLED') && SMTP_ENABLED) {
                    require_once $phpmailerPath . '/Exception.php';
                    require_once $phpmailerPath . '/PHPMailer.php';
                    require_once $phpmailerPath . '/SMTP.php';

                    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom(SMTP_FROM_EMAIL, 'StayWise');
                    $mail->addAddress($tenant['email'], $tenant['name']);
                    $mail->isHTML(true);
                    $mail->Subject = "StayWise Payment Receipt #{$receiptNo}";
                    $mail->Body    = $emailBody;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $emailBody));

                    // Attach the PDF receipt
                    $fullReceiptPath = __DIR__ . '/../' . $receipt_file;
                    if (file_exists($fullReceiptPath)) {
                        $mail->addAttachment($fullReceiptPath, "StayWise_Receipt_{$receiptNo}.pdf");
                    }

                    $mail->send();

                    // Log the email
                    try {
                        $logStmt = $conn->prepare("INSERT INTO email_logs (to_email, to_name, subject, body, type, status) VALUES (?, ?, ?, ?, 'payment_receipt', 'sent')");
                        $subj = "StayWise Payment Receipt #{$receiptNo}";
                        $logStmt->bind_param("ssss", $tenant['email'], $tenant['name'], $subj, $emailBody);
                        $logStmt->execute();
                        $logStmt->close();
                    } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {
                error_log("Receipt email error: " . $e->getMessage());
            }
        }
    }
}

// ── Receipt download handler ─────────────────────────────────────────
if (isset($_GET['download']) && $is_paid && !empty($receipt_file)) {
    $fullPath = __DIR__ . '/../' . $receipt_file;
    if (file_exists($fullPath)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="StayWise_Receipt_' . $payment['payment_id'] . '.pdf"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit();
    }
}

$monthLabel = $payment['for_month'] ? date('F Y', strtotime($payment['for_month'] . '-01')) : '—';
$paidAt     = $payment['paid_at'] ? date('F j, Y – g:i A', strtotime($payment['paid_at'])) : '—';

include '../includes/header.php';
?>

<div class="container mt-4 tenant-ui">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <?php if ($is_paid): ?>
            <!-- ═══════ SUCCESS ═══════ -->
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-5">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 rounded-circle" style="width:80px; height:80px;">
                            <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                        </div>
                    </div>

                    <h3 class="text-success fw-bold mb-1">Payment Confirmed!</h3>
                    <p class="text-muted mb-4">Your GCash payment has been verified successfully.</p>

                    <!-- Payment Details -->
                    <div class="border rounded-3 p-3 text-start mx-auto" style="max-width: 380px;">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Receipt No.</span>
                            <strong>SW-<?= str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Tenant</span>
                            <strong><?= htmlspecialchars($tenant['name']) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Unit</span>
                            <strong><?= htmlspecialchars($tenant['unit_number'] ?? '—') ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Month</span>
                            <strong><?= $monthLabel ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Amount</span>
                            <strong class="text-success fs-5">₱<?= number_format((float)$payment['amount'], 2) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Method</span>
                            <span class="badge text-white" style="background:#007DFE;"><i class="fas fa-mobile-alt me-1"></i>GCash</span>
                        </div>
                        <?php if (!empty($payment['transaction_id'])): ?>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Transaction ID</span>
                            <small class="text-monospace"><?= htmlspecialchars(substr($payment['transaction_id'], 0, 20)) ?></small>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between py-2">
                            <span class="text-muted">Date Paid</span>
                            <span><?= $paidAt ?></span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-4 d-flex gap-2 justify-content-center flex-wrap">
                        <?php if (!empty($receipt_file)): ?>
                        <a href="?link_id=<?= urlencode($payment['paymongo_checkout_id']) ?>&download=1" 
                           class="btn btn-success">
                            <i class="fas fa-file-pdf me-2"></i>Download Receipt
                        </a>
                        <?php endif; ?>
                        <a href="payments.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Payments
                        </a>
                    </div>

                    <?php if (!empty($tenant['email'])): ?>
                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-envelope me-1"></i>A receipt has been emailed to <strong><?= htmlspecialchars($tenant['email']) ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- ═══════ PROCESSING ═══════ -->
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-5">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 rounded-circle" style="width:80px; height:80px;">
                            <i class="fas fa-clock text-warning" style="font-size: 3rem;"></i>
                        </div>
                    </div>

                    <h3 class="text-warning fw-bold mb-1">Payment Processing</h3>
                    <p class="text-muted mb-4">Your payment is being confirmed by GCash. This usually takes a few seconds.</p>

                    <div class="border rounded-3 p-3 text-start mx-auto" style="max-width: 380px;">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Amount</span>
                            <strong>₱<?= number_format((float)$payment['amount'], 2) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Month</span>
                            <strong><?= $monthLabel ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span class="text-muted">Status</span>
                            <span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i>Processing</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button onclick="location.reload()" class="btn btn-warning me-2">
                            <i class="fas fa-sync-alt me-2"></i>Check Again
                        </button>
                        <a href="payments.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Payments
                        </a>
                    </div>

                    <p class="text-muted small mt-3 mb-0">
                        <i class="fas fa-info-circle me-1"></i>If payment was completed, click "Check Again" or refresh this page. 
                        The system will automatically verify your payment.
                    </p>

                    <!-- Auto-refresh every 5 seconds while processing -->
                    <script>setTimeout(function() { location.reload(); }, 5000);</script>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
