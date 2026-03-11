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
$otp_sent = false;
$otp_resent = false;

// Check if we're already in OTP step
if (!empty($_SESSION['pw_otp_code']) && !empty($_SESSION['pw_otp_expires'])) {
    $otp_sent = true;
}

// Helper: get user email for OTP
function _get_user_email_for_otp($conn, $user_id) {
    $stmt = $conn->prepare('SELECT email, username, full_name, role FROM users WHERE id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $email = !empty($row['email']) ? $row['email'] : null;
    $name  = !empty($row['full_name']) ? $row['full_name'] : $row['username'];
    $role  = $row['role'] ?? 'tenant';

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
                $errors[] = 'Verification code has expired. Please click "Start Over" and try again.';
                unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash']);
                $otp_sent = false;
            } elseif (empty($entered_otp)) {
                $errors[] = 'Please enter the 6-digit verification code sent to your email.';
                $otp_sent = true;
            } elseif (!preg_match('/^\d{6}$/', $entered_otp)) {
                $errors[] = 'The verification code must be exactly 6 digits.';
                $otp_sent = true;
            } elseif (!hash_equals($_SESSION['pw_otp_code'], $entered_otp)) {
                $_SESSION['pw_otp_attempts'] = ($_SESSION['pw_otp_attempts'] ?? 0) + 1;
                if ($_SESSION['pw_otp_attempts'] >= 5) {
                    $errors[] = 'Too many incorrect attempts. Please start over.';
                    unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts']);
                    $otp_sent = false;
                } else {
                    $remaining = 5 - $_SESSION['pw_otp_attempts'];
                    $errors[] = 'Incorrect verification code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
                    $otp_sent = true;
                }
            } else {
                // OTP correct — change the password
                $newHash = $_SESSION['pw_new_hash'];
                $uid = (int)$_SESSION['user_id'];

                if (db_column_exists($conn, 'users', 'password_changed_at')) {
                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0, password_changed_at = NOW() WHERE id = ?');
                } else {
                    $upd = $conn->prepare('UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?');
                }
                $upd->bind_param('si', $newHash, $uid);

                if ($upd->execute()) {
                    $userInfo = _get_user_email_for_otp($conn, $uid);
                    $logDetails = ucfirst($userInfo['role']) . ' "' . $userInfo['username'] . '" (' . $userInfo['name'] . ') changed their password';
                    logAdminAction($conn, $uid, 'password_change', $logDetails);

                    if (function_exists('notify_admin_password_change')) {
                        notify_admin_password_change($conn, $userInfo['name'], $userInfo['role'], $userInfo['email'] ?? '');
                    }

                    unset($_SESSION['pw_otp_code'], $_SESSION['pw_otp_expires'], $_SESSION['pw_new_hash'], $_SESSION['pw_otp_attempts'], $_SESSION['pw_otp_email']);
                    unset($_SESSION['must_change_password']);

                    $role = strtolower($_SESSION['role'] ?? '');
                    header($role === 'tenant' ? 'Location: tenant/dashboard.php' : 'Location: admin/dashboard.php');
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
                    $_SESSION['pw_otp_code']     = $newOtp;
                    $_SESSION['pw_otp_expires']  = time() + 600;
                    $_SESSION['pw_otp_attempts'] = 0;

                    $emailSent = notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $newOtp, 10);
                    $otp_sent  = true;
                    $otp_resent = ($emailSent !== false);
                    if (!$otp_resent) {
                        $errors[] = 'Failed to send email. Please check your email configuration or contact admin.';
                    }
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
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            // Password policy
            $pwErrs = [];
            if (strlen($new) < 8)                                                    $pwErrs[] = 'at least 8 characters';
            if (!preg_match('/[A-Z]/', $new))                                        $pwErrs[] = 'one uppercase letter';
            if (!preg_match('/[0-9]/', $new))                                        $pwErrs[] = 'one number';
            if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\"\\\|,.<>\/?]/', $new))     $pwErrs[] = 'one special character';
            if (!empty($pwErrs)) $errors[] = 'New password must include: ' . implode(', ', $pwErrs) . '.';
            if ($new !== $confirm) $errors[] = 'New password and confirm password do not match.';

            if (empty($errors)) {
                $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->bind_param('i', $_SESSION['user_id']);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    $errors[] = 'Account not found.';
                } else {
                    $stored = (string)$row['password'];
                    $ok = false;
                    if (!empty($stored) && (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0)) {
                        $ok = password_verify($current, $stored);
                    } else {
                        $is_md5  = preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
                        $is_sha1 = preg_match('/^[a-f0-9]{40}$/i', $stored) === 1;
                        if ($is_md5)       { $ok = hash_equals(strtolower($stored), md5($current)); }
                        elseif ($is_sha1)  { $ok = hash_equals(strtolower($stored), sha1($current)); }
                        else               { $ok = hash_equals($stored, $current); }
                    }

                    if (!$ok) {
                        $errors[] = 'Current password is incorrect.';
                    } else {
                        $newHash = password_hash($new, PASSWORD_DEFAULT);
                        if (!$newHash) {
                            $errors[] = 'Failed to process new password.';
                        } else {
                            // Forced change — skip OTP
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
                                    $role = strtolower($_SESSION['role'] ?? '');
                                    header($role === 'tenant' ? 'Location: tenant/dashboard.php' : 'Location: admin/dashboard.php');
                                    exit();
                                } else {
                                    $errors[] = 'Failed to update password. Please try again.';
                                }
                                $upd->close();
                            } else {
                                // Normal change — send OTP
                                $userInfo = _get_user_email_for_otp($conn, $_SESSION['user_id']);
                                if (empty($userInfo['email'])) {
                                    $errors[] = 'No email address on file. Please contact your administrator to add one before changing your password.';
                                } else {
                                    $otpCode = _generate_otp();
                                    $_SESSION['pw_otp_code']     = $otpCode;
                                    $_SESSION['pw_otp_expires']  = time() + 600;
                                    $_SESSION['pw_new_hash']     = $newHash;
                                    $_SESSION['pw_otp_email']    = $userInfo['email'];
                                    $_SESSION['pw_otp_attempts'] = 0;

                                    $emailSent = notify_password_otp($conn, $userInfo['email'], $userInfo['name'], $otpCode, 10);
                                    $otp_sent  = true;
                                    if ($emailSent === false) {
                                        // OTP saved in session — let user resend from OTP screen
                                        $errors[] = 'Verification code generated but the email may not have arrived. Please use "Resend Code" below.';
                                    }
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
    $e      = $_SESSION['pw_otp_email'];
    $parts  = explode('@', $e);
    $local  = $parts[0];
    $domain = $parts[1] ?? '';
    $maskedEmail = substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 3)) . '@' . $domain;
}

// Back link
$role_for_back = strtolower($_SESSION['role'] ?? '');
$back_link = ($role_for_back === 'tenant') ? 'tenant/profile.php?tab=security' : 'admin/profile.php?tab=security';
?>

<style>
/* ── Matches profile.php design system ── */
.cpw-wrap {
    max-width: 560px;
    margin: 0 auto;
    padding: 1.75rem 1.25rem 3rem;
}

.cpw-breadcrumb {
    display: flex; align-items: center; gap: 8px;
    font-size: .82rem; color: #94a3b8; margin-bottom: 1.5rem;
}
.cpw-breadcrumb a { color: #94a3b8; text-decoration: none; }
.cpw-breadcrumb a:hover { color: #16a34a; }
body.dark-mode .cpw-breadcrumb a:hover { color: #4ED6C1; }

.cpw-heading { font-size: 1.45rem; font-weight: 700; margin-bottom: .25rem; }
body:not(.dark-mode) .cpw-heading { color: #111827; }
body.dark-mode .cpw-heading { color: #f1f5f9; }
.cpw-sub { font-size: .82rem; color: #94a3b8; margin-bottom: 2rem; }

.cpw-card {
    border-radius: 14px; padding: 1.75rem;
    border: 1.5px solid #e2e8f0; background: #fff;
}
body.dark-mode .cpw-card { background: #1e293b; border-color: #2d3748; }

.cpw-section-title { font-weight: 700; font-size: .95rem; margin-bottom: .2rem; }
.cpw-section-sub   { font-size: .8rem; color: #94a3b8; margin-bottom: 1.5rem; }
body:not(.dark-mode) .cpw-section-title { color: #111827; }
body.dark-mode .cpw-section-title { color: #f1f5f9; }

.cpw-divider { border: none; border-top: 1px solid #e2e8f0; margin: 1.5rem 0; }
body.dark-mode .cpw-divider { border-top-color: #2d3748; }

.tc-label { display: block; font-size: .78rem; font-weight: 600; color: #6b7280; margin-bottom: 5px; }
body.dark-mode .tc-label { color: #94a3b8; }

.tc-input {
    width: 100%; padding: .65rem .9rem;
    border: 1.5px solid #d1d5db; border-radius: 8px;
    font-size: .88rem; color: #111827; background: #fff;
    transition: border-color .15s, box-shadow .15s; outline: none; font-family: inherit;
}
.tc-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.1); }
body.dark-mode .tc-input { background: #0f172a; border-color: #374151; color: #e2e8f0; }
body.dark-mode .tc-input:focus { border-color: #4ED6C1; box-shadow: 0 0 0 3px rgba(78,214,193,.1); }

.otp-input {
    font-size: 2rem !important; letter-spacing: 14px;
    text-align: center; font-family: monospace; font-weight: 700;
    height: 68px !important; border-radius: 12px !important;
}

.pw-wrap { position: relative; }
.pw-wrap .tc-input { padding-right: 2.75rem; }
.pw-eye {
    position: absolute; right: 0.75rem; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #94a3b8; padding: 0; line-height: 1;
}
.pw-eye:hover { color: #16a34a; }
body.dark-mode .pw-eye:hover { color: #4ED6C1; }

.tc-input:-webkit-autofill,
.tc-input:-webkit-autofill:hover,
.tc-input:-webkit-autofill:focus {
    -webkit-text-fill-color: #111827 !important;
    box-shadow: 0 0 0px 1000px #fff inset !important;
}
body.dark-mode .tc-input:-webkit-autofill,
body.dark-mode .tc-input:-webkit-autofill:hover,
body.dark-mode .tc-input:-webkit-autofill:focus {
    -webkit-text-fill-color: #e2e8f0 !important;
    box-shadow: 0 0 0px 1000px #0f172a inset !important;
}

.btn-tc-save {
    background: #16a34a; color: #fff; border: none;
    padding: .65rem 1.75rem; border-radius: 8px;
    font-weight: 600; font-size: .88rem; cursor: pointer;
    font-family: inherit; transition: background .15s;
    display: inline-flex; align-items: center; gap: 6px;
}
.btn-tc-save:hover { background: #15803d; }

.btn-outline-tc {
    border: 1.5px solid #d1d5db; background: none; color: #374151;
    padding: .5rem 1.1rem; border-radius: 8px;
    font-weight: 600; font-size: .82rem; cursor: pointer;
    font-family: inherit; text-decoration: none;
    transition: border-color .15s, color .15s; display: inline-flex; align-items: center; gap: 6px;
}
.btn-outline-tc:hover { border-color: #16a34a; color: #16a34a; }
body.dark-mode .btn-outline-tc { border-color: #374151; color: #94a3b8; }
body.dark-mode .btn-outline-tc:hover { border-color: #4ED6C1; color: #4ED6C1; }

.otp-icon-wrap {
    width: 72px; height: 72px; border-radius: 50%;
    background: #f0fdf4; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
}
body.dark-mode .otp-icon-wrap { background: rgba(20,83,45,.2); }

.otp-timer { font-size: .82rem; color: #94a3b8; }
.otp-timer .time-val { font-weight: 700; color: #16a34a; }
.otp-timer .time-val.expiring { color: #dc2626; }

.pw-strength-bar { height: 4px; border-radius: 4px; background: #e2e8f0; margin-top: 8px; overflow: hidden; }
body.dark-mode .pw-strength-bar { background: #2d3748; }
.pw-strength-fill { height: 100%; border-radius: 4px; transition: width .3s, background .3s; width: 0; }
.pw-hint { font-size: .72rem; color: #94a3b8; margin-top: 4px; }

.caps-hint { font-size: .75rem; color: #d97706; margin-top: 4px; display: none; }
.caps-hint.show { display: block; }

.alert { border-radius: 10px; font-size: .87rem; }
</style>

<div class="container mt-4">
<div class="cpw-wrap">

    <?php if (empty($_SESSION['must_change_password'])): ?>
    <nav class="cpw-breadcrumb">
        <a href="<?= htmlspecialchars($back_link) ?>"><i class="fas fa-user-circle me-1"></i>Profile</a>
        <i class="fas fa-chevron-right" style="font-size:.65rem;"></i>
        <span>Change Password</span>
    </nav>
    <?php endif; ?>

    <div class="cpw-heading"><i class="fas fa-key me-2" style="color:#16a34a;font-size:1.2rem;"></i>Change Password</div>
    <div class="cpw-sub">
        <?php if (!empty($_SESSION['must_change_password'])): ?>
            You must set a new password before you can continue.
        <?php elseif ($otp_sent): ?>
            Step 2 of 2 &mdash; Enter the verification code sent to your email.
        <?php else: ?>
            Step 1 of 2 &mdash; Enter your current and new password.
        <?php endif; ?>
    </div>

    <?php if (!empty($_SESSION['must_change_password'])): ?>
    <div class="alert alert-warning mb-3">
        <i class="fas fa-exclamation-triangle me-2"></i>Please change your temporary password to continue.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3">
        <?php foreach ($errors as $e): ?>
            <div><i class="fas fa-times-circle me-1"></i><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($otp_resent && empty($errors)): ?>
    <div class="alert alert-success mb-3">
        <i class="fas fa-check-circle me-2"></i>A new verification code has been sent to your email.
    </div>
    <?php endif; ?>

    <div class="cpw-card">

    <?php if ($otp_sent): ?>
    <!-- ════ OTP STEP ════ -->
    <div class="text-center mb-4">
        <div class="otp-icon-wrap">
            <i class="fas fa-envelope-open-text fa-2x" style="color:#16a34a;"></i>
        </div>
        <div class="cpw-section-title mb-1">Verify your identity</div>
        <p style="font-size:.85rem;color:#64748b;margin-bottom:.5rem;">
            A 6-digit code was sent to <strong><?= htmlspecialchars($maskedEmail) ?></strong>.<br>
            <span style="font-size:.78rem;">Check your inbox and spam folder.</span>
        </p>
        <div class="otp-timer">
            Expires in <span class="time-val" id="otpTimerVal">--:--</span>
        </div>
    </div>

    <form method="post" novalidate autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="verify_otp">
        <div class="mb-4">
            <label for="otp_code" class="tc-label text-center d-block mb-2">Verification Code</label>
            <input type="text" class="tc-input otp-input" id="otp_code" name="otp_code"
                   required maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                   placeholder="••••••" autocomplete="one-time-code">
        </div>
        <button type="submit" class="btn-tc-save w-100 justify-content-center" style="font-size:.95rem;padding:.75rem;">
            <i class="fas fa-check-circle"></i> Verify &amp; Change Password
        </button>
    </form>

    <div class="cpw-divider"></div>

    <div class="d-flex justify-content-between align-items-center">
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="cancel_otp">
            <button type="submit" class="btn-outline-tc">
                <i class="fas fa-arrow-left"></i> Start Over
            </button>
        </form>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" class="btn-outline-tc" id="resendBtn">
                <i class="fas fa-redo"></i> Resend Code
            </button>
        </form>
    </div>

    <script>
    (function(){
        var expiresAt = <?= (int)($_SESSION['pw_otp_expires'] ?? 0) ?>;
        var timerEl   = document.getElementById('otpTimerVal');
        function tick() {
            var diff = expiresAt - Math.floor(Date.now() / 1000);
            if (diff <= 0) {
                timerEl.textContent = 'Expired';
                timerEl.classList.add('expiring');
                return;
            }
            var m = Math.floor(diff / 60), s = diff % 60;
            timerEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            if (diff <= 60) timerEl.classList.add('expiring');
            setTimeout(tick, 1000);
        }
        tick();

        var inp = document.getElementById('otp_code');
        if (inp) {
            inp.focus();
            inp.addEventListener('input', function() { this.value = this.value.replace(/\D/g,''); });
        }

        // Resend cooldown (15s)
        var resendBtn = document.getElementById('resendBtn');
        if (resendBtn) {
            var cd = 15;
            resendBtn.disabled = true;
            resendBtn.style.opacity = '0.6';
            var t = setInterval(function(){
                if (--cd <= 0) { resendBtn.disabled = false; resendBtn.style.opacity = ''; clearInterval(t); }
            }, 1000);
        }
    })();
    </script>

    <?php else: ?>
    <!-- ════ PASSWORD FORM STEP ════ -->
    <div class="cpw-section-title">Update your password</div>
    <div class="cpw-section-sub">Use a strong password you don't use elsewhere.</div>

    <form method="post" novalidate autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="validate">

        <div class="mb-4">
            <label for="current_password" class="tc-label">Current Password <span style="color:#ef4444">*</span></label>
            <div class="pw-wrap">
                <input type="password" class="tc-input" id="current_password" name="current_password"
                       required autocomplete="current-password" data-lpignore="true">
                <button type="button" class="pw-eye" tabindex="-1" onclick="togglePw('current_password',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="caps-hint" id="capsCurrent"><i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON</div>
        </div>

        <div class="cpw-divider" style="margin:1rem 0;"></div>

        <div class="mb-3">
            <label for="new_password" class="tc-label">New Password <span style="color:#ef4444">*</span></label>
            <div class="pw-wrap">
                <input type="password" class="tc-input" id="new_password" name="new_password"
                       required autocomplete="new-password" data-lpignore="true"
                       oninput="updateStrength(this.value)">
                <button type="button" class="pw-eye" tabindex="-1" onclick="togglePw('new_password',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="pw-strength-bar"><div class="pw-strength-fill" id="strengthFill"></div></div>
            <div class="pw-hint" id="strengthLabel">Min. 8 chars, uppercase, number, special character</div>
            <div class="caps-hint" id="capsNew"><i class="fas fa-exclamation-triangle me-1"></i>Caps Lock is ON</div>
        </div>

        <div class="mb-4">
            <label for="confirm_password" class="tc-label">Confirm New Password <span style="color:#ef4444">*</span></label>
            <div class="pw-wrap">
                <input type="password" class="tc-input" id="confirm_password" name="confirm_password"
                       required autocomplete="new-password" data-lpignore="true">
                <button type="button" class="pw-eye" tabindex="-1" onclick="togglePw('confirm_password',this)">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <div class="pw-hint" id="matchLabel"></div>
        </div>

        <?php if (empty($_SESSION['must_change_password'])): ?>
        <div class="d-flex align-items-start gap-2 mb-4 p-3 rounded-3"
             style="background:#f0fdf4;border:1px solid #bbf7d0;">
            <i class="fas fa-shield-alt mt-1" style="color:#16a34a;flex-shrink:0;font-size:.85rem;"></i>
            <div style="font-size:.82rem;color:#15803d;line-height:1.5;">
                <strong>Email verification required.</strong>
                A one-time code will be sent to your registered email to confirm this change.
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center">
            <?php if (!empty($_SESSION['must_change_password'])): ?>
                <a href="logout.php" class="btn-outline-tc"><i class="fas fa-sign-out-alt"></i> Logout</a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($back_link) ?>" class="btn-outline-tc"><i class="fas fa-arrow-left"></i> Back</a>
            <?php endif; ?>

            <button type="submit" class="btn-tc-save">
                <?php if (!empty($_SESSION['must_change_password'])): ?>
                    <i class="fas fa-key"></i> Set New Password
                <?php else: ?>
                    <i class="fas fa-paper-plane"></i> Send Verification Code
                <?php endif; ?>
            </button>
        </div>
    </form>
    <?php endif; ?>

    </div><!-- .cpw-card -->
</div><!-- .cpw-wrap -->
</div><!-- .container -->

<script>
function togglePw(id, btn) {
    var inp = document.getElementById(id);
    var icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye','fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash','fa-eye');
    }
}

function updateStrength(pw) {
    var fill  = document.getElementById('strengthFill');
    var label = document.getElementById('strengthLabel');
    if (!fill || !label) return;
    var score = 0;
    if (pw.length >= 8)        score++;
    if (/[A-Z]/.test(pw))     score++;
    if (/[0-9]/.test(pw))     score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    var pct    = (score / 4) * 100;
    var colors = ['#dc2626','#f97316','#eab308','#16a34a'];
    var labels = ['Weak','Fair','Good','Strong'];
    fill.style.width      = pct + '%';
    fill.style.background = colors[score - 1] || '#e2e8f0';
    label.textContent     = score > 0 ? labels[score - 1] : 'Min. 8 chars, uppercase, number, special character';
    label.style.color     = colors[score - 1] || '#94a3b8';
    var ci = document.getElementById('confirm_password');
    if (ci && ci.value) checkMatch(ci.value, pw);
}

document.addEventListener('DOMContentLoaded', function(){
    var ci = document.getElementById('confirm_password');
    if (ci) {
        ci.addEventListener('input', function(){
            var np = (document.getElementById('new_password') || {}).value || '';
            checkMatch(this.value, np);
        });
    }
});

function checkMatch(conf, pw) {
    var ml = document.getElementById('matchLabel');
    if (!ml) return;
    if (!conf) { ml.textContent = ''; return; }
    if (conf === pw) { ml.textContent = '✓ Passwords match'; ml.style.color = '#16a34a'; }
    else             { ml.textContent = '✗ Passwords do not match'; ml.style.color = '#dc2626'; }
}

(function(){
    function bindCaps(inputId, hintId) {
        var inp  = document.getElementById(inputId);
        var hint = document.getElementById(hintId);
        if (!inp || !hint) return;
        function upd(e) {
            var on = false;
            try { on = e.getModifierState && e.getModifierState('CapsLock'); } catch(_){}
            hint.classList.toggle('show', on);
        }
        ['keydown','keyup','focus'].forEach(function(ev){ inp.addEventListener(ev, upd); });
        inp.addEventListener('blur', function(){ hint.classList.remove('show'); });
    }
    bindCaps('current_password','capsCurrent');
    bindCaps('new_password','capsNew');
})();
</script>
<?php include 'includes/footer.php'; ?>
