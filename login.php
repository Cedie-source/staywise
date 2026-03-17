<?php
require_once 'includes/security.php';
set_secure_session_cookies();
session_start();
require_once 'config/db.php';
require_once 'includes/email_helper.php';

if (function_exists('db_ensure_user_force_change_columns')) {
    db_ensure_user_force_change_columns($conn);
}

// ── Already logged in ────────────────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    $adminRole = $_SESSION['admin_role'] ?? '';
    if ($_SESSION['role'] === 'admin') {
        header("Location: " . ($adminRole === 'super_admin' ? 'admin/super_dashboard.php' : 'admin/dashboard.php'));
    } else {
        header("Location: tenant/dashboard.php");
    }
    exit();
}

// ════════════════════════════════════════════════════════════════════
// STEP 2 — Verify 2FA code
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_2fa'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header("Location: index.php?error=Invalid request"); exit();
    }

    $entered = trim($_POST['otp_code'] ?? '');

    if (empty($_SESSION['2fa_user_id']) || empty($_SESSION['2fa_code'])) {
        header("Location: index.php?error=Session expired. Please log in again."); exit();
    }
    if (time() > ($_SESSION['2fa_expires'] ?? 0)) {
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_user_data'], $_SESSION['2fa_attempts']);
        header("Location: index.php?error=Code expired. Please log in again."); exit();
    }

    $_SESSION['2fa_attempts'] = ($_SESSION['2fa_attempts'] ?? 0) + 1;
    if ($_SESSION['2fa_attempts'] > 5) {
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_user_data'], $_SESSION['2fa_attempts']);
        header("Location: index.php?error=Too many attempts. Please log in again."); exit();
    }

    if (!hash_equals((string)$_SESSION['2fa_code'], $entered)) {
        $left = 5 - $_SESSION['2fa_attempts'];
        header("Location: index.php?step=2fa&error=Incorrect code. $left attempt(s) remaining."); exit();
    }

    // ── Code correct — complete login ────────────────────────────────
    $user = $_SESSION['2fa_user_data'];
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_code'], $_SESSION['2fa_expires'], $_SESSION['2fa_user_data'], $_SESSION['2fa_attempts']);
    session_regenerate_id(true);
    _finish_login($conn, $user);
    exit();
}

// ════════════════════════════════════════════════════════════════════
// STEP 1 — Username + Password
// ════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['username'])) $_SESSION['last_login_identifier'] = trim($_POST['username']);
        header("Location: index.php?error=Invalid request"); exit();
    }

    $username_input = trim($_POST['username']);
    $identifier     = strtolower($username_input);
    $password       = $_POST['password'];

    if (empty($identifier) || empty($password)) {
        $_SESSION['last_login_identifier'] = $username_input;
        header("Location: index.php?error=Please fill in all fields"); exit();
    }

    // Rate limiting
    if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts']))   $_SESSION['login_attempts']   = [];
    if (!isset($_SESSION['login_lock_until']) || !is_array($_SESSION['login_lock_until'])) $_SESSION['login_lock_until'] = [];
    $now        = time();
    $lock_until = intval($_SESSION['login_lock_until'][$identifier] ?? 0);
    if ($lock_until && $now >= $lock_until) { unset($_SESSION['login_lock_until'][$identifier]); $_SESSION['login_attempts'][$identifier] = 0; }
    if ($lock_until && $now < $lock_until) {
        $_SESSION['last_login_identifier'] = $username_input;
        header("Location: index.php?lock=1&remain=" . max(1, $lock_until - $now)); exit();
    }

    $isEmail = (bool)filter_var($username_input, FILTER_VALIDATE_EMAIL);
    if ($isEmail) {
        if (!function_exists('db_column_exists') || !db_column_exists($conn, 'users', 'email')) {
            $_SESSION['last_login_identifier'] = $username_input;
            header("Location: index.php?error=Email login not supported. Use your username."); exit();
        }
    } else {
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $identifier)) {
            $_SESSION['last_login_identifier'] = $username_input;
            header("Location: index.php?error=Invalid username format."); exit();
        }
    }

    // Build SELECT
    $hasForceCol  = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'force_password_change') : false;
    $hasActiveCol = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'is_active') : false;
    $select = "SELECT id, username, password, role";
    if ($hasForceCol)  $select .= ", force_password_change";
    if ($hasActiveCol) $select .= ", is_active";
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'full_name'))  $select .= ", full_name";
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'email'))      $select .= ", email";
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'admin_role')) $select .= ", admin_role";

    $lookupField  = $isEmail ? 'email' : 'username';
    $hasDeletedAt = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'deleted_at') : false;
    $sql = $select . " FROM users WHERE LOWER($lookupField) = ?";
    if ($hasDeletedAt) $sql .= " AND deleted_at IS NULL";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    $login_ok = false;
    $user     = null;

    if ($result->num_rows == 1) {
        $user   = $result->fetch_assoc();
        $stored = (string)$user['password'];

        // Modern hash
        if (!empty($stored) && (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0)) {
            if (password_verify($password, $stored)) {
                $login_ok = true;
                if (function_exists('password_needs_rehash') && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($newHash) { $upd = $conn->prepare("UPDATE users SET password=? WHERE id=?"); $upd->bind_param("si",$newHash,$user['id']); $upd->execute(); $upd->close(); }
                }
            }
        }
        // Legacy
        if (!$login_ok) {
            $is_md5  = preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
            $is_sha1 = preg_match('/^[a-f0-9]{40}$/i', $stored) === 1;
            if ($is_md5 && hash_equals(strtolower($stored), md5($password)))       $login_ok = true;
            elseif ($is_sha1 && hash_equals(strtolower($stored), sha1($password))) $login_ok = true;
            elseif (hash_equals($stored, $password))                               $login_ok = true;
            if ($login_ok) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if ($newHash) { $upd = $conn->prepare("UPDATE users SET password=? WHERE id=?"); $upd->bind_param("si",$newHash,$user['id']); $upd->execute(); $upd->close(); }
            }
        }
    }
    $stmt->close();

    if ($login_ok && $user) {
        // Check active
        if ($hasActiveCol && isset($user['is_active']) && intval($user['is_active']) !== 1) {
            session_unset(); session_destroy();
            header("Location: index.php?error=Invalid username or password"); exit();
        }

        $userEmail = trim($user['email'] ?? '');

        // No email on file — skip 2FA
        if (empty($userEmail)) {
            session_regenerate_id(true);
            _finish_login($conn, $user);
            exit();
        }

        // Generate 6-digit OTP
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['2fa_user_id']   = $user['id'];
        $_SESSION['2fa_code']      = $otp;
        $_SESSION['2fa_expires']   = time() + 600;
        $_SESSION['2fa_attempts']  = 0;
        $_SESSION['2fa_user_data'] = $user;

        // Mask email for display
        $atPos  = strrpos($userEmail, '@');
        $local  = substr($userEmail, 0, $atPos);
        $domain = substr($userEmail, $atPos);
        $masked = substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2)) . $domain;
        $_SESSION['2fa_masked_email'] = $masked;

        // Clear login attempts on success
        unset($_SESSION['login_attempts'][$identifier], $_SESSION['login_lock_until'][$identifier]);

        // Send email
        $name    = $user['full_name'] ?: $user['username'];
        $subject = "StayWise Login Verification Code";
        $body    = "
            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Your login verification code is:</p>
            <div style='text-align:center;margin:24px 0;'>
                <span style='font-size:2.5rem;font-weight:800;letter-spacing:.35em;background:#f1f5f9;padding:16px 32px;border-radius:12px;color:#0f172a;display:inline-block;font-family:monospace;'>$otp</span>
            </div>
            <p>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
            <p style='color:#94a3b8;font-size:.85rem;'>If you didn't try to log in, ignore this email.</p>
        ";
        staywise_send_email($conn, $userEmail, $name, $subject, $body, '2fa');

        header("Location: index.php?step=2fa"); exit();

    } else {
        $attempts = intval($_SESSION['login_attempts'][$identifier] ?? 0) + 1;
        $_SESSION['login_attempts'][$identifier] = $attempts;
        $_SESSION['last_login_identifier']       = $username_input;
        if ($attempts >= 5) {
            $_SESSION['login_lock_until'][$identifier] = time() + 30;
            header("Location: index.php?lock=1&remain=30");
        } else {
            header("Location: index.php?error=Invalid username or password");
        }
        exit();
    }
}

$conn->close();

// ── Complete login after password check or 2FA ───────────────────────
function _finish_login($conn, $user) {
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $role = strtolower((string)$user['role']);
    $_SESSION['role'] = $role;
    if (isset($user['admin_role'])) $_SESSION['admin_role'] = strtolower((string)$user['admin_role']);
    else unset($_SESSION['admin_role']);
    if (isset($user['full_name'])) $_SESSION['full_name'] = $user['full_name'];
    if (isset($user['email']))     $_SESSION['email']     = $user['email'];
    unset($_SESSION['last_login_identifier']);

    $hasForceCol = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'force_password_change') : false;
    if ($hasForceCol && !empty($user['force_password_change'])) {
        $_SESSION['must_change_password'] = true;
        header("Location: change_password.php"); return;
    }

    if ($role === 'tenant') {
        $ts = $conn->prepare("SELECT tenant_id FROM tenants WHERE user_id=?");
        $ts->bind_param("i", $user['id']); $ts->execute();
        $tr = $ts->get_result();
        if ($tr->num_rows == 1) { $t = $tr->fetch_assoc(); $_SESSION['tenant_id'] = $t['tenant_id']; }
        $ts->close();
        header("Location: tenant/dashboard.php");
    } else {
        $adminRole = strtolower((string)($user['admin_role'] ?? ''));
        header("Location: " . ($adminRole === 'super_admin' ? 'admin/super_dashboard.php' : 'admin/dashboard.php'));
    }
}
?>
