<?php
/**
 * StayWise Email Notification Helper
 * 
 * Provides functions to send email notifications and log them.
 * Uses PHPMailer with Gmail SMTP when configured, falls back to PHP mail().
 */

// Load SMTP config if available
$_smtp_config_path = __DIR__ . '/../config/smtp.php';
if (file_exists($_smtp_config_path)) {
    require_once $_smtp_config_path;
}

if (!function_exists('staywise_send_email')) {

    /**
     * Send an email and log it to the email_logs table.
     * Uses PHPMailer+SMTP when configured, falls back to PHP mail().
     *
     * @param mysqli $conn       Database connection
     * @param string $to_email   Recipient email
     * @param string $to_name    Recipient name
     * @param string $subject    Email subject
     * @param string $body       HTML body
     * @param string $type       Notification type (payment_verified, complaint_update, etc.)
     * @return bool              Whether the email was sent successfully
     */
    function staywise_send_email($conn, $to_email, $to_name, $subject, $body, $type = 'general') {
        // Check if email is enabled
        $enabled = '1';
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'email_enabled'");
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) $enabled = $row['setting_value'];
        } catch (Throwable $e) { /* default enabled */ }

        if ($enabled !== '1') {
            _log_email($conn, $to_email, $to_name, $subject, $body, $type, 'queued', 'Email sending is disabled');
            return false;
        }

        // Get from settings (overridden by SMTP config if set)
        $from_name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'StayWise';
        $from_email = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@staywise.local';
        try {
            $res = $conn->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('email_from_name','email_from_address')");
            while ($row = $res->fetch_assoc()) {
                if ($row['setting_key'] === 'email_from_name' && !defined('SMTP_ENABLED')) $from_name = $row['setting_value'];
                if ($row['setting_key'] === 'email_from_address' && !defined('SMTP_ENABLED')) $from_email = $row['setting_value'];
            }
        } catch (Throwable $e) { /* use defaults */ }

        // Wrap body in HTML template
        $html = _email_template($subject, $body, $from_name);

        $sent = false;
        $error = '';

        // ── Try PHPMailer + SMTP first ──────────────────────────────
        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $phpmailerPath = __DIR__ . '/../vendor/phpmailer';
            if (file_exists($phpmailerPath . '/PHPMailer.php')) {
                require_once $phpmailerPath . '/Exception.php';
                require_once $phpmailerPath . '/PHPMailer.php';
                require_once $phpmailerPath . '/SMTP.php';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    // Server settings
                    $mail->SMTPDebug = defined('SMTP_DEBUG') ? SMTP_DEBUG : 0;
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_ENCRYPTION === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = SMTP_PORT;
                    $mail->CharSet    = 'UTF-8';
                    // Prevent page from hanging on SMTP connection timeout
                    $mail->Timeout    = 10;  // seconds to wait for SMTP connection
                    $mail->SMTPKeepAlive = false;

                    // Recipients
                    $mail->setFrom(SMTP_FROM_EMAIL, $from_name);
                    $mail->addAddress($to_email, $to_name);
                    $mail->addReplyTo(SMTP_FROM_EMAIL, $from_name);

                    // Content
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body    = $html;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));

                    $mail->send();
                    $sent = true;
                } catch (Throwable $e) {
                    $error = 'PHPMailer: ' . $mail->ErrorInfo;
                    // Log to file for debugging
                    if (function_exists('app_log')) {
                        app_log('email', 'PHPMailer error: ' . $mail->ErrorInfo . ' | to=' . $to_email);
                    }
                }
            } else {
                $error = 'PHPMailer files not found in vendor/phpmailer/';
            }
        }

        // ── Fallback to PHP mail() ──────────────────────────────────
        if (!$sent && empty($error)) {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: $from_name <$from_email>\r\n";
            $headers .= "Reply-To: $from_email\r\n";
            $headers .= "X-Mailer: StayWise/1.0\r\n";

            try {
                $sent = @mail($to_email, $subject, $html, $headers);
                if (!$sent) {
                    $error = 'mail() returned false - SMTP not configured. See config/smtp.php';
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // Log
        _log_email($conn, $to_email, $to_name, $subject, $body, $type, $sent ? 'sent' : 'failed', $error);

        return $sent;
    }

    /**
     * Log an email to the database
     */
    function _log_email($conn, $email, $name, $subject, $body, $type, $status, $error = '') {
        try {
            $stmt = $conn->prepare("INSERT INTO email_logs (recipient_email, recipient_name, subject, body, type, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $email, $name, $subject, $body, $type, $status, $error);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // Fallback to file log
            if (function_exists('app_log')) {
                app_log('email', "Failed to log email: " . $e->getMessage());
            }
        }
    }

    /**
     * Beautiful HTML email template
     */
    function _email_template($title, $content, $brand = 'StayWise') {
        return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
    <tr><td style="background:linear-gradient(135deg,#1a365d,#2d5a87);padding:28px 32px;text-align:center;">
        <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;">
            <span style="margin-right:8px;">&#127968;</span> ' . htmlspecialchars($brand) . '
        </h1>
        <p style="margin:4px 0 0;color:rgba(255,255,255,0.8);font-size:13px;">AI-Enabled Rental Management Platform</p>
    </td></tr>
    <tr><td style="padding:32px;">
        <h2 style="margin:0 0 16px;color:#1a365d;font-size:20px;">' . htmlspecialchars($title) . '</h2>
        <div style="color:#4a5568;font-size:15px;line-height:1.7;">' . $content . '</div>
    </td></tr>
    <tr><td style="background:#f8fafc;padding:20px 32px;text-align:center;border-top:1px solid #e2e8f0;">
        <p style="margin:0;color:#a0aec0;font-size:12px;">&copy; ' . date('Y') . ' ' . htmlspecialchars($brand) . '. All rights reserved.</p>
        <p style="margin:4px 0 0;color:#a0aec0;font-size:11px;">This is an automated notification. Please do not reply directly.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
    }

    // ── Specific notification helpers ─────────────────────────────

    /**
     * Notify tenant when payment status changes
     */
    function notify_payment_status($conn, $tenant_email, $tenant_name, $amount, $status, $for_month = '') {
        $statusLabel = ucfirst($status);
        $statusColor = $status === 'verified' ? '#38a169' : ($status === 'rejected' ? '#e53e3e' : '#d69e2e');
        $monthLabel = $for_month ? date('F Y', strtotime($for_month . '-01')) : 'N/A';
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>Your payment has been updated:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Amount</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>₱" . number_format($amount, 2) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>For Month</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$monthLabel</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Status</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'><span style='color:$statusColor;font-weight:700;'>$statusLabel</span></td></tr>
        </table>
        <p>Log in to your dashboard for details.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Payment $statusLabel - StayWise", $body, 'payment_' . $status);
    }

    /**
     * Notify tenant when complaint status is updated
     */
    function notify_complaint_update($conn, $tenant_email, $tenant_name, $complaint_title, $new_status, $admin_reply = '') {
        $statusLabel = ucfirst(str_replace('_', ' ', $new_status));
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>Your complaint has been updated:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Complaint</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($complaint_title) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>New Status</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'><strong>$statusLabel</strong></td></tr>
        </table>";
        
        if (!empty($admin_reply)) {
            $body .= "<div style='background:#f0fff4;padding:12px 16px;border-left:4px solid #38a169;border-radius:4px;margin:16px 0;'>
                <strong>Admin Response:</strong><br>" . nl2br(htmlspecialchars($admin_reply)) . "</div>";
        }
        
        $body .= "<p>Log in to your dashboard for details.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Complaint Update: " . $complaint_title . " - StayWise", $body, 'complaint_update');
    }

    /**
     * Send rent due reminder
     */
    function notify_rent_reminder($conn, $tenant_email, $tenant_name, $amount, $due_date, $days_left) {
        $urgency = $days_left <= 1 ? 'color:#e53e3e;font-weight:700;' : ($days_left <= 3 ? 'color:#d69e2e;font-weight:600;' : '');
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>This is a friendly reminder that your rent payment is <span style='$urgency'>due in $days_left day(s)</span>.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Rent Amount</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>₱" . number_format($amount, 2) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Due Date</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$due_date</td></tr>
        </table>
        <p>Please submit your payment proof through your tenant dashboard to avoid late fees.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Rent Payment Reminder - StayWise", $body, 'rent_reminder');
    }

    /**
     * Notify about new announcement
     */
    function notify_announcement($conn, $tenant_email, $tenant_name, $announcement_title, $announcement_content) {
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>A new announcement has been posted:</p>
        <div style='background:#ebf8ff;padding:16px;border-left:4px solid #3182ce;border-radius:4px;margin:16px 0;'>
            <h3 style='margin:0 0 8px;color:#2c5282;'>" . htmlspecialchars($announcement_title) . "</h3>
            <p style='margin:0;color:#4a5568;'>" . nl2br(htmlspecialchars(substr($announcement_content, 0, 500))) . "</p>
        </div>
        <p>Log in to your dashboard to read the full announcement.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "New Announcement: " . $announcement_title . " - StayWise", $body, 'announcement');
    }

    /**
     * Notify about lease expiry
     */
    function notify_lease_expiry($conn, $tenant_email, $tenant_name, $unit_number, $end_date, $days_left) {
        $urgencyColor = $days_left <= 7 ? '#e53e3e' : ($days_left <= 30 ? '#d69e2e' : '#3182ce');
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>Your lease for <strong>Unit " . htmlspecialchars($unit_number) . "</strong> is expiring soon.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Unit</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($unit_number) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Lease End Date</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$end_date</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Days Remaining</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'><span style='color:$urgencyColor;font-weight:700;'>$days_left days</span></td></tr>
        </table>
        <p>Please contact your property manager to discuss lease renewal options.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Lease Expiry Notice - StayWise", $body, 'lease_expiry');
    }

    /**
     * Notify about work order update
     */
    function notify_work_order_update($conn, $tenant_email, $tenant_name, $wo_title, $new_status, $notes = '') {
        $statusLabel = ucfirst(str_replace('_', ' ', $new_status));
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>Your maintenance request has been updated:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Work Order</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($wo_title) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Status</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'><strong>$statusLabel</strong></td></tr>
        </table>";
        
        if (!empty($notes)) {
            $body .= "<div style='background:#f0fff4;padding:12px 16px;border-left:4px solid #38a169;border-radius:4px;margin:16px 0;'>
                <strong>Notes:</strong><br>" . nl2br(htmlspecialchars($notes)) . "</div>";
        }
        
        $body .= "<p>Log in to your dashboard for details.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Maintenance Update: " . $wo_title . " - StayWise", $body, 'work_order_update');
    }

    /**
     * Send OTP code for password change verification
     */
    function notify_password_otp($conn, $to_email, $to_name, $otp_code, $expires_minutes = 10) {
        $body = "<p>Dear <strong>" . htmlspecialchars($to_name) . "</strong>,</p>
        <p>You are attempting to change your password. Use the verification code below to confirm this action:</p>
        <div style='text-align:center;margin:24px 0;'>
            <div style='display:inline-block;background:linear-gradient(135deg,#1a365d,#2d5a87);color:#ffffff;font-size:32px;font-weight:700;letter-spacing:8px;padding:16px 32px;border-radius:12px;font-family:monospace;'>" . htmlspecialchars($otp_code) . "</div>
        </div>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Valid For</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$expires_minutes minutes</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Sent To</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($to_email) . "</td></tr>
        </table>
        <p style='color:#e53e3e;font-weight:600;'>If you did not request this, please ignore this email and your password will remain unchanged.</p>";

        return staywise_send_email($conn, $to_email, $to_name, "Password Change Verification Code - StayWise", $body, 'password_otp');
    }

    /**
     * Notify admin when a user changes their password
     */
    function notify_admin_password_change($conn, $user_name, $user_role, $user_email) {
        // Get all admin emails
        $admins = [];
        try {
            $res = $conn->query("SELECT email, full_name, username FROM users WHERE role = 'admin' AND is_active = 1 AND email IS NOT NULL AND email != ''");
            while ($row = $res->fetch_assoc()) {
                $admins[] = $row;
            }
        } catch (Throwable $e) { /* ignore */ }

        $roleLabel = ucfirst($user_role);
        $body = "<p>A user has changed their account password:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>User</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($user_name) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Role</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$roleLabel</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Email</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . htmlspecialchars($user_email) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Changed At</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>" . date('F d, Y g:i A') . "</td></tr>
        </table>
        <p>This is an automated security notification. If this was unauthorized, please take immediate action.</p>";

        foreach ($admins as $admin) {
            $adminName = !empty($admin['full_name']) ? $admin['full_name'] : $admin['username'];
            staywise_send_email($conn, $admin['email'], $adminName, "Security Alert: Password Changed by $roleLabel - StayWise", $body, 'password_change_alert');
        }
    }

    /**
     * Notify about late fee
     */
    function notify_late_fee($conn, $tenant_email, $tenant_name, $fee_amount, $for_month, $reason = '') {
        $monthLabel = date('F Y', strtotime($for_month . '-01'));
        
        $body = "<p>Dear <strong>" . htmlspecialchars($tenant_name) . "</strong>,</p>
        <p>A late fee has been applied to your account:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;'>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>Late Fee</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;color:#e53e3e;font-weight:700;'>₱" . number_format($fee_amount, 2) . "</td></tr>
            <tr><td style='padding:8px 12px;background:#f7fafc;border:1px solid #e2e8f0;font-weight:600;'>For Month</td>
                <td style='padding:8px 12px;border:1px solid #e2e8f0;'>$monthLabel</td></tr>
        </table>";
        
        if (!empty($reason)) {
            $body .= "<p><em>Reason: " . htmlspecialchars($reason) . "</em></p>";
        }
        
        $body .= "<p>Please settle this amount along with your rent to avoid additional charges.</p>";

        return staywise_send_email($conn, $tenant_email, $tenant_name, "Late Fee Notice - StayWise", $body, 'late_fee');
    }
}
