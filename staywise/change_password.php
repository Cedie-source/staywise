<?php
require_once 'includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once 'config/db.php';
require_once 'includes/logger.php';
require_once 'includes/email_helper.php';

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Ensure columns exist (best-effort)
if (function_exists('db_ensure_user_force_change_columns')) {
    db_ensure_user_force_change_columns($conn);
}

$page_title = 'Change Password';
$errors = [];
$success = null;
$otp_sent = false; // Whether we're in OTP verification step
$otp_resent = false;

// Check if we're already in OTP step (session has pending OTP)
if (!empty($_SESSION['pw_otp_code']) && !empty($_SESSION['pw_otp_expires'])) {
    $otp_sent = true;
}

// Helper: get user email for OTP
function _get_user_email_for_otp($conn, $user_id) {
    // Try users.email first
    $stmt = $conn->prepare('SELECT email, username, full_name, role FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $email = !empty($row['email']) ? $row['email'] : null;
    $name = !empty($row['full_name']) ? $row['full_name'] : $row['username'];
    $role = $row['role'] ?? 'tenant';

    // Fallback: check tenants table
    if (!$email) {
        $stmt2 = $conn->prepare('SELECT email, name FROM tenants WHERE user_id = ?');
        $stmt2->bind_param('i', $user_id);
        $stmt2->execute();
        $tRow = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        if ($tRow && !empty($tRow['email'])) {
            $email = $tRow['email'];
            if (empty($name) || $name === ($row['username'] ?? '')) {
                $name = $tRow['name'];
            }
        }
    }
    return ['email' => $email, 'name' => $name, 'role' => $role, 'username' => $row['username'] ?? ''];
}

// Helper: generate OTP
function _generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request token. Please try again.';
    } else {
        $action = $_POST['action'] ?? 'validate';

        // --- STEP 2: Verify OTP and change password ---
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
                // Track attempts
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
                // OTP is correct — change the password
                $newHash = $_SESSION['pw_new_hash'];
                $uid = (int)$_SESSION['user_id'];

                if (db_column_exists($conn, 'users', 'password_changed_at')) {
                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0, password_changed_at = NOW() WHERE id = ?');
                } else {
                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?');
                }
                $upd->bind_param('si', $newHash, $uid);

                if ($upd->execute()) {
                    // Log password change for admin visibility
                    $userInfo = _get_user_email_for_otp($conn, $uid);
                    $logDetails = ucfirst($userInfo['role']) . ' "' . $userInfo['username'] . '" (' . $userInfo['name'] . ') changed their password';
                    logAdminAction($conn, $uid, 'password_change', $logDetails);

                    // Notify admins about the password change
                    if (function_exists('notify_admin_password_change')) {
                        notify_admin_password_change($conn, $userInfo['name'], $userInfo['role'], $userInfo['email'] ?? '');
                    }

                    // Clear OTP session data
                    unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts'], $_SESSION['pw_otp_email']);
                    unset($_SESSION['must_change_password']);

                    $success = 'Your password has been updated successfully.';

                    // Redirect to dashboard by role
                    $role = strtolower($_SESSION['role'] ?? '');
                    if ($role === 'tenant') {
                        header('Location: tenant/dashboard.php');
                    } else {
                        header('Location: admin/dashboard.php');
                    }
                    exit();
                } else {
                    $errors[] = 'Failed to update password. Please try again.';
                }
                $upd->close();
            }

        // --- RESEND OTP ---
        } elseif ($action === 'resend_otp') {
            if (!empty($_SESSION['pw_new_hash'])) {
                $userInfo = _get_user_email_for_otp($conn, $_SESSION['user_id']);
                if ($userInfo['email']) {
                    $newOtp = _generate_otp();
                    $_SESSION['pw_otp_code'] = $newOtp;
                    $_SESSION['pw_otp_expires'] = time() + 600; // 10 minutes
                    $_SESSION['pw_otp_attempts'] = 0;

                    notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $newOtp, 10);
                    $otp_sent = true;
                    $otp_resent = true;
                } else {
                    $errors[] = 'No email address found. Please contact your administrator.';
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

        // --- STEP 1: Validate passwords and send OTP ---
        } else {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            // Validate new password policy
            $pwErrs = [];
            if (!preg_match('/[A-Z]/', $new)) $pwErrs[] = 'one uppercase letter';
            if (!preg_match('/[0-9]/', $new)) $pwErrs[] = 'one number';
            if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $new)) $pwErrs[] = 'one special character';
            if (!empty($pwErrs)) $errors[] = 'New password must include at least ' . implode(', ', $pwErrs) . '.';
            if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters long.';
            if ($new !== $confirm) $errors[] = 'New password and confirm password do not match.';

            if (empty($errors)) {
                // Fetch current hash
                $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->bind_param('i', $_SESSION['user_id']);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res->fetch_assoc();
                $stmt->close();
                if (!$row) {
                    $errors[] = 'Account not found.';
                } else {
                    $stored = (string)$row['password'];
                    $ok = false;
                    if (!empty($stored) && (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0)) {
                        $ok = password_verify($current, $stored);
                    } else {
                        // Legacy hashes support
                        $is_md5 = preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
                        $is_sha1 = preg_match('/^[a-f0-9]{40}$/i', $stored) === 1;
                        if ($is_md5) { $ok = hash_equals(strtolower($stored), md5($current)); }
                        elseif ($is_sha1) { $ok = hash_equals(strtolower($stored), sha1($current)); }
                        else { $ok = hash_equals($stored, $current); }
                    }
                    if (!$ok) {
                        $errors[] = 'Current password is incorrect.';
                    } else {
                        // Password validated — hash it
                        $newHash = password_hash($new, PASSWORD_DEFAULT);
                        if (!$newHash) {
                            $errors[] = 'Failed to process new password.';
                        } else {
                            // If forced password change (new tenant), skip OTP and change directly
                            if (!empty($_SESSION['must_change_password'])) {
                                $uid = (int)$_SESSION['user_id'];
                                if (db_column_exists($conn, 'users', 'password_changed_at')) {
                                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0, password_changed_at = NOW() WHERE id = ?');
                                } else {
                                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?');
                                }
                                $upd->bind_param('si', $newHash, $uid);

                                if ($upd->execute()) {
                                    $userInfo = _get_user_email_for_otp($conn, $uid);
                                    $logDetails = ucfirst($userInfo['role']) . ' "' . $userInfo['username'] . '" (' . $userInfo['name'] . ') changed their password (first login)';
                                    logAdminAction($conn, $uid, 'password_change', $logDetails);

                                    unset($_SESSION['must_change_password']);
                                    $success = 'Your password has been updated successfully.';

                                    $role = strtolower($_SESSION['role'] ?? '');
                                    if ($role === 'tenant') {
                                        header('Location: tenant/dashboard.php');
                                    } else {
                                        header('Location: admin/dashboard.php');
                                    }
                                    exit();
                                } else {
                                    $errors[] = 'Failed to update password. Please try again.';
                                }
                                $upd->close();
                            } else {
                                // Normal password change — require OTP verification
                                $userInfo = _get_user_email_for_otp($conn, $_SESSION['user_id']);
                                if (empty($userInfo['email'])) {
                                    $errors[] = 'No email address on file. Please contact your administrator to add one before changing your password.';
                                } else {
                                    $otpCode = _generate_otp();
                                    $_SESSION['pw_otp_code'] = $otpCode;
                                    $_SESSION['pw_otp_expires'] = time() + 600; // 10 minutes
                                    $_SESSION['pw_new_hash'] = $newHash;
                                    $_SESSION['pw_otp_email'] = $userInfo['email'];
                                    $_SESSION['pw_otp_attempts'] = 0;

                                    // Send OTP email
                                    notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $otpCode, 10);

                                    $otp_sent = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

include 'includes/header.php';

// Mask email for display
$maskedEmail = '';
if ($otp_sent && !empty($_SESSION['pw_otp_email'])) {
    $e = $_SESSION['pw_otp_email'];
    $parts = explode('@', $e);
    $local = $parts[0];
    $domain = $parts[1] ?? '';
    $maskedEmail = substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 3)) . '@' . $domain;
}
?>
<style>
/* Improve visibility for light mode on the change password page */
.change-pw-ui .card { border: 2px solid #3B82F6; border-radius: 14px; }
.change-pw-ui .card-header { background-color: #f8fafc; border-bottom: 2px solid #3B82F6; font-weight: 600; }
.change-pw-ui .form-label { font-weight: 600; letter-spacing: 0.2px; }
.change-pw-ui .form-control {
    background: #ffffff;
    color: #0f172a;
    border: 2px solid #3B82F6;
    border-radius: 12px;
    height: 52px;
    padding: 0.65rem 0.9rem;
}
.change-pw-ui .form-control::placeholder { color: #64748b; }
.change-pw-ui .form-control:focus { border-color: #2563EB; box-shadow: 0 0 0 0.15rem rgba(37,99,235,0.25); }
.change-pw-ui .btn-primary { background: linear-gradient(90deg, #3B82F6, #2563EB); border: none; }
.change-pw-ui .btn-outline-secondary { border-width: 2px; }
/* Keep light background on browser autofill */
.change-pw-ui .form-control:-webkit-autofill,
.change-pw-ui .form-control:-webkit-autofill:hover,
.change-pw-ui .form-control:-webkit-autofill:focus {
    -webkit-text-fill-color: #0f172a !important;
    box-shadow: 0 0 0px 1000px #ffffff inset !important;
    border: 2px solid #3B82F6 !important;
}
/* OTP input styling */
.otp-input {
    font-size: 28px !important;
    letter-spacing: 12px;
    text-align: center;
    font-family: monospace;
    font-weight: 700;
    height: 64px !important;
}
.otp-countdown { font-size: 14px; color: #64748b; }
.otp-countdown .time-left { font-weight: 700; color: #2563EB; }
</style>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-6 change-pw-ui">
            <div class="card">
                <div class="card-header">
                    <i class="fa-solid fa-key me-2"></i>Change Password
                    <?php if ($otp_sent): ?>
                        <span class="badge bg-warning text-dark ms-2"><i class="fas fa-shield-alt me-1"></i>Verification Required</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($_SESSION['must_change_password'])): ?>
                        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>You must change your password before continuing.</div>
                    <?php endif; ?>
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
                    <div class="text-center mb-4">
                        <div class="mb-3">
                            <i class="fas fa-envelope-open-text fa-3x text-primary"></i>
                        </div>
                        <h5 class="mb-2">Enter Verification Code</h5>
                        <p class="text-muted mb-1">
                            A 6-digit code has been sent to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                        </p>
                        <p class="otp-countdown">
                            Code expires in <span class="time-left" id="otpTimer">--:--</span>
                        </p>
                    </div>

                    <form method="post" class="needs-validation" novalidate autocomplete="off">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="verify_otp">
                        <div class="mb-4">
                            <label for="otp_code" class="form-label">Verification Code</label>
                            <input type="text" class="form-control otp-input" id="otp_code" name="otp_code"
                                   required maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                                   placeholder="000000" autocomplete="one-time-code">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mb-3">
                            <i class="fas fa-check-circle me-2"></i>Verify & Change Password
                        </button>
                    </form>

                    <div class="d-flex justify-content-between">
                        <form method="post" class="d-inline">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="cancel_otp">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Start Over
                            </button>
                        </form>
                        <form method="post" class="d-inline">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="resend_otp">
                            <button type="submit" class="btn btn-outline-primary btn-sm" id="resendBtn">
                                <i class="fas fa-redo me-1"></i>Resend Code
                            </button>
                        </form>
                    </div>

                    <script>
                    // OTP countdown timer
                    (function(){
                        var expiresAt = <?php echo (int)($_SESSION['pw_otp_expires'] ?? 0); ?>;
                        var timerEl = document.getElementById('otpTimer');
                        function updateTimer() {
                            var now = Math.floor(Date.now() / 1000);
                            var diff = expiresAt - now;
                            if (diff <= 0) {
                                timerEl.textContent = 'Expired';
                                timerEl.style.color = '#e53e3e';
                                return;
                            }
                            var m = Math.floor(diff / 60);
                            var s = diff % 60;
                            timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                            setTimeout(updateTimer, 1000);
                        }
                        updateTimer();

                        // Auto-focus OTP input
                        var otpInput = document.getElementById('otp_code');
                        if (otpInput) {
                            otpInput.focus();
                            // Only allow digits
                            otpInput.addEventListener('input', function() {
                                this.value = this.value.replace(/[^0-9]/g, '');
                            });
                        }
                    })();
                    </script>

                    <?php else: ?>
                    <!-- ========== PASSWORD FORM STEP ========== -->
                    <form method="post" class="needs-validation" novalidate autocomplete="off">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="validate">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="pw-toggle-wrap">
                            <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem">
                            <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                            </div>
                                <div id="capsCurrent" class="form-text text-warning mt-1" style="display:none;"><i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON</div>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                <div class="pw-toggle-wrap">
                <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem"
                    pattern="^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$"
                    title="At least 8 chars, include uppercase, number, and special character">
                <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                </div>
                            <div id="pwHelp" class="form-text mt-1">At least 8 chars, include uppercase, number, and special character.</div>
                                <div id="capsNew" class="form-text text-warning mt-1" style="display:none;"><i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="pw-toggle-wrap">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" style="padding-right:2.5rem">
                            <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                        <?php if (empty($_SESSION['must_change_password'])): ?>
                        <div class="mb-3">
                            <div class="alert alert-info mb-0 py-2">
                                <i class="fas fa-shield-alt me-2"></i>A verification code will be sent to your email to confirm this change.
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-between">
                            <?php if (!empty($_SESSION['must_change_password'])): ?>
                            <a href="logout.php" class="btn btn-outline-secondary">Logout</a>
                            <?php else: ?>
                            <div></div>
                            <?php endif; ?>
                            <?php if (!empty($_SESSION['must_change_password'])): ?>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-key me-2"></i>Change Password</button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send Verification Code</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
</div>
<script>
// Caps Lock hints for current and new password fields
(function(){
    function bindCaps(inputId, hintId){
        var input = document.getElementById(inputId);
        var hint = document.getElementById(hintId);
        if (!input || !hint) return;
        function upd(e){
            var on = false;
            try { on = e.getModifierState && e.getModifierState('CapsLock'); } catch(_) {}
            hint.style.display = on ? 'block' : 'none';
        }
        input.addEventListener('keydown', upd);
        input.addEventListener('keyup', upd);
        input.addEventListener('input', upd);
        input.addEventListener('focus', upd);
        input.addEventListener('blur', function(){ hint.style.display='none'; });
    }
    bindCaps('current_password','capsCurrent');
    bindCaps('new_password','capsNew');
})();
</script>
<?php include 'includes/footer.php'; ?>
