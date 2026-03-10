<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
require_once '../includes/email_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$page_title = "Change Password";
$errors = [];
$success = null;
$otp_sent = false;
$otp_resent = false;

// Check if we're already in OTP step
if (!empty($_SESSION['pw_otp_code']) && !empty($_SESSION['pw_otp_expires'])) {
    $otp_sent = true;
}

// Helper: get user email for OTP
function _admin_get_user_email($conn, $user_id) {
    $stmt = $conn->prepare('SELECT email, username, full_name, role FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $email = !empty($row['email']) ? $row['email'] : null;
    $name = !empty($row['full_name']) ? $row['full_name'] : $row['username'];
    return ['email' => $email, 'name' => $name, 'role' => $row['role'] ?? 'admin', 'username' => $row['username'] ?? ''];
}

function _admin_generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'validate';

        // --- STEP 2: Verify OTP ---
        if ($action === 'verify_otp') {
            $entered_otp = trim($_POST['otp_code'] ?? '');

            if (empty($_SESSION['pw_otp_code']) || empty($_SESSION['pw_otp_expires'])) {
                $errors[] = 'No pending verification. Please start over.';
                $otp_sent = false;
            } elseif (time() > $_SESSION['pw_otp_expires']) {
                $errors[] = 'Verification code has expired. Please start over.';
                unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash']);
                $otp_sent = false;
            } elseif (!hash_equals($_SESSION['pw_otp_code'], $entered_otp)) {
                $_SESSION['pw_otp_attempts'] = ($_SESSION['pw_otp_attempts'] ?? 0) + 1;
                if ($_SESSION['pw_otp_attempts'] >= 5) {
                    $errors[] = 'Too many incorrect attempts. Please start over.';
                    unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts']);
                    $otp_sent = false;
                } else {
                    $errors[] = 'Incorrect verification code. ' . (5 - $_SESSION['pw_otp_attempts']) . ' attempts remaining.';
                    $otp_sent = true;
                }
            } else {
                // OTP correct — change password
                $newHash = $_SESSION['pw_new_hash'];
                $uid = (int)$_SESSION['user_id'];

                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $newHash, $uid);
                if ($stmt->execute()) {
                    // Also update password_changed_at if column exists
                    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'password_changed_at')) {
                        $conn->query("UPDATE users SET password_changed_at = NOW() WHERE id = " . (int)$uid);
                    }

                    // Log password change for admin visibility
                    $userInfo = _admin_get_user_email($conn, $uid);
                    $logDetails = ucfirst($userInfo['role']) . ' "' . $userInfo['username'] . '" (' . $userInfo['name'] . ') changed their password';
                    logAdminAction($conn, $uid, 'password_change', $logDetails);

                    // Notify admins about the password change
                    if (function_exists('notify_admin_password_change')) {
                        notify_admin_password_change($conn, $userInfo['name'], $userInfo['role'], $userInfo['email'] ?? '');
                    }

                    // Clear OTP session data
                    unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts'], $_SESSION['pw_otp_email']);

                    $success = "Password changed successfully.";
                } else {
                    $errors[] = "Failed to change password.";
                }
                $stmt->close();

                if ($success) {
                    $otp_sent = false; // Show success on the main form
                }
            }

        // --- RESEND OTP ---
        } elseif ($action === 'resend_otp') {
            if (!empty($_SESSION['pw_new_hash'])) {
                $userInfo = _admin_get_user_email($conn, $_SESSION['user_id']);
                if ($userInfo['email']) {
                    $newOtp = _admin_generate_otp();
                    $_SESSION['pw_otp_code'] = $newOtp;
                    $_SESSION['pw_otp_expires'] = time() + 600;
                    $_SESSION['pw_otp_attempts'] = 0;
                    notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $newOtp, 10);
                    $otp_sent = true;
                    $otp_resent = true;
                } else {
                    $errors[] = 'No email address found. Please update your profile.';
                    $otp_sent = true;
                }
            } else {
                $errors[] = 'Session expired. Please start over.';
                $otp_sent = false;
            }

        // --- CANCEL OTP ---
        } elseif ($action === 'cancel_otp') {
            unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts'], $_SESSION['pw_otp_email']);
            $otp_sent = false;

        // --- STEP 1: Validate and send OTP ---
        } else {
            $old_password = $_POST['old_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $stmt->bind_result($hashed);
            $stmt->fetch();
            $stmt->close();

            if (!password_verify($old_password, $hashed)) {
                $errors[] = "Old password is incorrect.";
            } elseif ($new_password !== $confirm_password) {
                $errors[] = "New passwords do not match.";
            } elseif (strlen($new_password) < 6) {
                $errors[] = "New password must be at least 6 characters.";
            } else {
                $userInfo = _admin_get_user_email($conn, $_SESSION['user_id']);
                if (empty($userInfo['email'])) {
                    $errors[] = 'No email address on file. Please update your profile before changing your password.';
                } else {
                    $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                    $otpCode = _admin_generate_otp();

                    $_SESSION['pw_otp_code'] = $otpCode;
                    $_SESSION['pw_otp_expires'] = time() + 600;
                    $_SESSION['pw_new_hash'] = $newHash;
                    $_SESSION['pw_otp_email'] = $userInfo['email'];
                    $_SESSION['pw_otp_attempts'] = 0;

                    notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $otpCode, 10);
                    $otp_sent = true;
                }
            }
        }
    }
}

// Mask email for display
$maskedEmail = '';
if ($otp_sent && !empty($_SESSION['pw_otp_email'])) {
    $e = $_SESSION['pw_otp_email'];
    $parts = explode('@', $e);
    $local = $parts[0];
    $domain = $parts[1] ?? '';
    $maskedEmail = substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 3)) . '@' . $domain;
}

include '../includes/header.php';
?>
<style>
.admin-change-pw .otp-input {
    font-size: 28px !important;
    letter-spacing: 12px;
    text-align: center;
    font-family: monospace;
    font-weight: 700;
    height: 64px !important;
}
.admin-change-pw .otp-countdown { font-size: 14px; color: #64748b; }
.admin-change-pw .otp-countdown .time-left { font-weight: 700; color: #2563EB; }
</style>
<div class="container mt-4 admin-change-pw">
    <h2 class="dashboard-title"><i class="fas fa-key me-2"></i>Change Password
        <?php if ($otp_sent): ?>
            <span class="badge bg-warning text-dark ms-2" style="font-size:14px;"><i class="fas fa-shield-alt me-1"></i>Verification Required</span>
        <?php endif; ?>
    </h2>

    <?php if (isset($success)): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div><?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <div><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($otp_resent): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>A new verification code has been sent to your email.</div>
    <?php endif; ?>

    <?php if ($otp_sent): ?>
    <!-- ========== OTP VERIFICATION STEP ========== -->
    <div class="card flat-card">
        <div class="card-body">
            <div class="text-center mb-4">
                <div class="mb-3"><i class="fas fa-envelope-open-text fa-3x text-primary"></i></div>
                <h5 class="mb-2">Enter Verification Code</h5>
                <p class="text-muted mb-1">
                    A 6-digit code has been sent to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                </p>
                <p class="otp-countdown">
                    Code expires in <span class="time-left" id="otpTimer">--:--</span>
                </p>
            </div>

            <form method="post" class="needs-validation" novalidate autocomplete="off" style="max-width: 400px; margin: 0 auto;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="verify_otp">
                <div class="mb-4">
                    <label for="otp_code" class="form-label dashboard-title">Verification Code</label>
                    <input type="text" class="form-control otp-input" id="otp_code" name="otp_code"
                           required maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           placeholder="000000" autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="fas fa-check-circle me-2"></i>Verify & Change Password
                </button>
            </form>

            <div class="d-flex justify-content-between" style="max-width: 400px; margin: 0 auto;">
                <form method="post" class="d-inline">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="cancel_otp">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Start Over</button>
                </form>
                <form method="post" class="d-inline">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" class="btn btn-outline-primary btn-sm" id="resendBtn"><i class="fas fa-redo me-1"></i>Resend Code</button>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var expiresAt = <?php echo (int)($_SESSION['pw_otp_expires'] ?? 0); ?>;
        var timerEl = document.getElementById('otpTimer');
        function updateTimer() {
            var now = Math.floor(Date.now() / 1000);
            var diff = expiresAt - now;
            if (diff <= 0) { timerEl.textContent = 'Expired'; timerEl.style.color = '#e53e3e'; return; }
            var m = Math.floor(diff / 60); var s = diff % 60;
            timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            setTimeout(updateTimer, 1000);
        }
        updateTimer();
        var otpInput = document.getElementById('otp_code');
        if (otpInput) {
            otpInput.focus();
            otpInput.addEventListener('input', function() { this.value = this.value.replace(/[^0-9]/g, ''); });
        }
    })();
    </script>

    <?php else: ?>
    <!-- ========== PASSWORD FORM STEP ========== -->
    <form method="post" class="needs-validation" novalidate autocomplete="off">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="validate">
        <div class="mb-3">
            <label for="old_password" class="form-label dashboard-title">Old Password</label>
            <div class="pw-toggle-wrap">
            <input type="password" class="form-control" id="old_password" name="old_password" required autocomplete="current-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem">
            <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="mb-3">
            <label for="new_password" class="form-label dashboard-title">New Password</label>
            <div class="pw-toggle-wrap">
            <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem">
            <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label dashboard-title">Confirm New Password</label>
            <div class="pw-toggle-wrap">
            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem">
            <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="mb-3">
            <div class="alert alert-info mb-0 py-2">
                <i class="fas fa-shield-alt me-2"></i>A verification code will be sent to your email to confirm this change.
            </div>
        </div>
        <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send Verification Code</button>
    </form>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
