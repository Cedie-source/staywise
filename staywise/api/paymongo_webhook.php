<?php
/**
 * PayMongo Webhook Handler – Payment Links
 * 
 * Receives webhook events from PayMongo to automatically update payment statuses,
 * generate PDF receipts, and email them to tenants.
 * 
 * Register this URL in PayMongo:
 *   https://yourdomain.com/api/paymongo_webhook.php
 * 
 * Events handled:
 *   - link.payment.paid          (Payment Link paid)
 *   - checkout_session.payment.paid (legacy fallback)
 *   - payment.paid               (generic fallback)
 */

// No session needed for webhooks
require_once '../config/db.php';
require_once '../includes/paymongo_helper.php';
require_once '../includes/receipt_generator.php';

date_default_timezone_set('Asia/Manila');

// ── Helper: write to webhook log ────────────────────────────────────
function webhookLog(string $msg): void {
    $logFile = __DIR__ . '/../storage/logs/paymongo_webhooks.log';
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// ── Only accept POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// ── Read raw payload ────────────────────────────────────────────────
$payload = file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit();
}

$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

// ── Verify HMAC-SHA256 signature ────────────────────────────────────
$paymongo = new PayMongoHelper($conn);

$webhookSecret = '';
try {
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'paymongo_webhook_secret'");
    $stmt->execute();
    $stmt->bind_result($webhookSecret);
    $stmt->fetch();
    $stmt->close();
} catch (Throwable $e) {}

if (!empty($webhookSecret)) {
    $sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
    if (!$paymongo->verifyWebhookSignature($payload, $sigHeader, $webhookSecret)) {
        webhookLog('SIGNATURE FAIL – header: ' . $sigHeader);
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit();
    }
}

// ── Parse event ─────────────────────────────────────────────────────
$eventType = $data['data']['attributes']['type'] ?? '';
$eventData = $data['data']['attributes']['data'] ?? [];

webhookLog("Event: $eventType – " . json_encode($data, JSON_UNESCAPED_SLASHES));

switch ($eventType) {
    case 'link.payment.paid':
        handleLinkPaymentPaid($conn, $eventData, $paymongo);
        break;

    case 'checkout_session.payment.paid':
        handleCheckoutPaid($conn, $eventData);
        break;

    case 'payment.paid':
        handlePaymentPaid($conn, $eventData);
        break;

    default:
        webhookLog("Ignored event type: $eventType");
        break;
}

// Always return 200 to acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'received']);

// ═════════════════════════════════════════════════════════════════════
//  HANDLERS
// ═════════════════════════════════════════════════════════════════════

/**
 * Handle link.payment.paid – a Payment Link was paid via GCash
 */
function handleLinkPaymentPaid($conn, $eventData, $paymongo) {
    $linkId     = $eventData['id'] ?? '';
    $attributes = $eventData['attributes'] ?? [];
    $payments   = $attributes['payments'] ?? [];

    if (empty($linkId)) {
        webhookLog("link.payment.paid – no link ID in payload");
        return;
    }

    // Extract payment info
    $pmPaymentId = '';
    $pmAmount    = 0;
    if (!empty($payments)) {
        $first       = $payments[0];
        $pmPaymentId = $first['id'] ?? '';
        $pmAmount    = ($first['attributes']['amount'] ?? 0) / 100; // centavos → pesos
    }

    webhookLog("Processing link: $linkId, payment: $pmPaymentId, amount: $pmAmount");

    // ── Update MySQL record ─────────────────────────────────────────
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status             = 'verified',
            paid_at            = ?,
            transaction_id     = ?,
            paymongo_payment_id = ?
        WHERE paymongo_checkout_id = ? AND status = 'pending'
    ");
    $stmt->bind_param("ssss", $now, $pmPaymentId, $pmPaymentId, $linkId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected <= 0) {
        webhookLog("No pending payment found for link_id: $linkId (may already be verified)");
        return;
    }

    webhookLog("Payment verified for link $linkId");

    // ── Fetch full payment + tenant details for receipt / email ──────
    $stmt = $conn->prepare("
        SELECT p.*, t.name AS tenant_name, t.email AS tenant_email, t.unit_number
        FROM payments p
        JOIN tenants t ON t.tenant_id = p.tenant_id
        WHERE p.paymongo_checkout_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $linkId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        webhookLog("Could not fetch payment/tenant record for receipt – link: $linkId");
        return;
    }

    // ── Generate PDF receipt ────────────────────────────────────────
    $receiptFile = '';
    try {
        $receiptFile = ReceiptGenerator::generate([
            'payment_id'     => $row['payment_id'],
            'tenant_name'    => $row['tenant_name'],
            'tenant_email'   => $row['tenant_email'],
            'unit_number'    => $row['unit_number'] ?? '',
            'amount'         => (float)$row['amount'],
            'for_month'      => $row['for_month'] ?? '',
            'payment_method' => 'paymongo_gcash',
            'transaction_id' => $pmPaymentId,
            'paid_at'        => $now,
            'reference_no'   => $row['reference_no'] ?? '',
        ]);
        webhookLog("Receipt generated: $receiptFile");
    } catch (Throwable $e) {
        webhookLog("Receipt generation FAILED: " . $e->getMessage());
    }

    // ── Email receipt to tenant via PHPMailer ────────────────────────
    if (!empty($row['tenant_email'])) {
        sendReceiptEmail($conn, $row, $receiptFile, $now);
    }

    // ── Admin log ───────────────────────────────────────────────────
    try {
        $logStmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (0, 'paymongo_payment', ?)");
        $details = "Webhook: Payment verified – Link: $linkId, PaymentID: $pmPaymentId, Amount: ₱" . number_format($pmAmount, 2) . ", Tenant: " . $row['tenant_name'];
        $logStmt->bind_param("s", $details);
        $logStmt->execute();
        $logStmt->close();
    } catch (Throwable $e) {}
}

/**
 * Send receipt email with PDF attachment using PHPMailer
 */
function sendReceiptEmail($conn, $row, $receiptFile, $paidAt) {
    // Load SMTP config
    $smtpConfigFile = __DIR__ . '/../config/smtp.php';
    if (file_exists($smtpConfigFile)) {
        require_once $smtpConfigFile;
    }
    if (!defined('SMTP_HOST') || !defined('SMTP_USERNAME')) {
        webhookLog("SMTP not configured – skipping email");
        return;
    }

    $phpmailerPath = __DIR__ . '/../vendor/phpmailer';
    if (!file_exists($phpmailerPath . '/PHPMailer.php')) {
        webhookLog("PHPMailer not found – skipping email");
        return;
    }

    require_once $phpmailerPath . '/Exception.php';
    require_once $phpmailerPath . '/PHPMailer.php';
    require_once $phpmailerPath . '/SMTP.php';

    $receiptNo  = 'SW-' . str_pad($row['payment_id'], 6, '0', STR_PAD_LEFT);
    $monthLabel = $row['for_month'] ? date('F Y', strtotime($row['for_month'] . '-01')) : date('F Y');
    $amountFmt  = number_format((float)$row['amount'], 2);
    $paidFmt    = date('F j, Y – g:i A', strtotime($paidAt));

    $body = "
        <div style='font-family:Arial,sans-serif; max-width:520px; margin:0 auto;'>
            <div style='background:#198754; color:white; padding:20px; text-align:center; border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;'>Payment Confirmed</h2>
            </div>
            <div style='padding:24px; background:#fff; border:1px solid #e9ecef; border-top:none; border-radius:0 0 8px 8px;'>
                <p>Hi <strong>{$row['tenant_name']}</strong>,</p>
                <p>Your GCash payment has been successfully received!</p>
                <table style='width:100%; border-collapse:collapse; margin:16px 0;'>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Receipt No.</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold;'>{$receiptNo}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Month</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$monthLabel}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Amount</td><td style='padding:8px; border-bottom:1px solid #eee; font-weight:bold; color:#198754;'>PHP {$amountFmt}</td></tr>
                    <tr><td style='padding:8px; border-bottom:1px solid #eee; color:#666;'>Date Paid</td><td style='padding:8px; border-bottom:1px solid #eee;'>{$paidFmt}</td></tr>
                    <tr><td style='padding:8px; color:#666;'>Method</td><td style='padding:8px;'>GCash (PayMongo)</td></tr>
                </table>
                <p>Your PDF receipt is attached to this email.</p>
                <p style='color:#999; font-size:13px;'>— StayWise</p>
            </div>
        </div>
    ";

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl')
                            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->CharSet    = 'UTF-8';

        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : SMTP_USERNAME;
        $mail->setFrom($fromEmail, 'StayWise');
        $mail->addAddress($row['tenant_email'], $row['tenant_name']);
        $mail->isHTML(true);
        $mail->Subject = "StayWise Payment Receipt #{$receiptNo}";
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $body));

        // Attach PDF receipt
        if (!empty($receiptFile)) {
            $fullPath = __DIR__ . '/../' . $receiptFile;
            if (file_exists($fullPath)) {
                $mail->addAttachment($fullPath, "StayWise_Receipt_{$receiptNo}.pdf");
            }
        }

        $mail->send();
        webhookLog("Receipt email sent to: " . $row['tenant_email']);

        // Log in email_logs table
        try {
            $logStmt = $conn->prepare("INSERT INTO email_logs (to_email, to_name, subject, body, type, status) VALUES (?, ?, ?, ?, 'payment_receipt', 'sent')");
            $subj = "StayWise Payment Receipt #{$receiptNo}";
            $logStmt->bind_param("ssss", $row['tenant_email'], $row['tenant_name'], $subj, $body);
            $logStmt->execute();
            $logStmt->close();
        } catch (Throwable $e) {}

    } catch (Throwable $e) {
        webhookLog("Email FAILED: " . $e->getMessage());
    }
}

/**
 * Legacy: Handle checkout_session.payment.paid event
 */
function handleCheckoutPaid($conn, $eventData) {
    $checkoutId = $eventData['id'] ?? '';
    $attributes = $eventData['attributes'] ?? [];
    $payments   = $attributes['payments'] ?? [];

    if (empty($checkoutId) || empty($payments)) return;

    $pmPayment   = $payments[0];
    $pmPaymentId = $pmPayment['id'] ?? '';
    $now         = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ?, transaction_id = ?, paymongo_payment_id = ? WHERE paymongo_checkout_id = ? AND status = 'pending'");
    $stmt->bind_param("ssss", $now, $pmPaymentId, $pmPaymentId, $checkoutId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Legacy: Handle payment.paid event
 */
function handlePaymentPaid($conn, $eventData) {
    $paymentId = $eventData['id'] ?? '';
    if (empty($paymentId)) return;

    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE payments SET status = 'verified', paid_at = ? WHERE paymongo_payment_id = ? AND status = 'pending'");
    $stmt->bind_param("ss", $now, $paymentId);
    $stmt->execute();
    $stmt->close();
}
