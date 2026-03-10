<?php
require_once 'includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once 'config/db.php';
// Ensure required auth columns exist
if (function_exists('db_ensure_user_force_change_columns')) {
    db_ensure_user_force_change_columns($conn);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['username'])) { $_SESSION['last_login_identifier'] = trim($_POST['username']); }
        header("Location: index.php?error=Invalid request");
        exit();
    }
    // Normalize identifier to lowercase for case-insensitive login (username or email)
    $username_input = trim($_POST['username']);
    $identifier = strtolower($username_input);
    $password = $_POST['password'];
    // Validate input
    if (empty($identifier) || empty($password)) {
        $_SESSION['last_login_identifier'] = $username_input;
        header("Location: index.php?error=Please fill in all fields");
        exit();
    }

    // Login attempt limiting: per-identifier tracking in session
    if (!isset($_SESSION['login_attempts']) || !is_array($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    if (!isset($_SESSION['login_lock_until']) || !is_array($_SESSION['login_lock_until'])) {
        $_SESSION['login_lock_until'] = [];
    }
    $now = time();
    $lock_until = isset($_SESSION['login_lock_until'][$identifier]) ? intval($_SESSION['login_lock_until'][$identifier]) : 0;
    // If lock expired, clear state
    if ($lock_until && $now >= $lock_until) {
        unset($_SESSION['login_lock_until'][$identifier]);
        $_SESSION['login_attempts'][$identifier] = 0;
    }
    // If currently locked, block login
    if ($lock_until && $now < $lock_until) {
        $_SESSION['last_login_identifier'] = $username_input;
        $remaining = max(1, $lock_until - $now);
        // Redirect without static error text; client shows realtime countdown
        header("Location: index.php?lock=1&remain=$remaining");
        exit();
    }
    // Determine if identifier is email or username and validate accordingly
    $isEmail = (bool)filter_var($username_input, FILTER_VALIDATE_EMAIL);
    if ($isEmail) {
        // If email, ensure users.email column exists; otherwise fail gracefully
        if (!function_exists('db_column_exists') || !db_column_exists($conn, 'users', 'email')) {
            $_SESSION['last_login_identifier'] = $username_input;
            header("Location: index.php?error=Email login is not supported on this system. Please use your username.");
            exit();
        }
    } else {
        // Basic username format validation to help users with typos/random input
        if (!preg_match('/^[a-z0-9_]{3,20}$/', $identifier)) {
            $_SESSION['last_login_identifier'] = $username_input;
            header("Location: index.php?error=Invalid username format. Use 3-20 letters, numbers, or underscore.");
            exit();
        }
    }
    // Check user credentials
    // Use case-insensitive lookup; keep password comparison case-sensitive
    $hasForceCol = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'force_password_change') : false;
    // Ensure schema exists for active flag and reset helpers
    $hasActiveCol = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'is_active') : false;
    $select = "SELECT id, username, password, role";
    if ($hasForceCol) { $select .= ", force_password_change"; }
    if ($hasActiveCol) { $select .= ", is_active"; }
    // Also fetch full_name and email for session convenience if present
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'full_name')) { $select .= ", full_name"; }
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'email')) { $select .= ", email"; }
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'admin_role')) { $select .= ", admin_role"; }
    // Choose lookup field
    $lookupField = $isEmail ? 'email' : 'username';
    $hasDeletedAt = function_exists('db_column_exists') ? db_column_exists($conn, 'users', 'deleted_at') : false;
    $sql = $select . " FROM users WHERE LOWER($lookupField) = ?";
    if ($hasDeletedAt) { $sql .= " AND deleted_at IS NULL"; }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $stored = (string)$user['password'];
        $login_ok = false;

        // Preferred: verify modern hash
        if (!empty($stored) && (strpos($stored, '$2y$') === 0 || strpos($stored, '$argon2') === 0)) {
            if (password_verify($password, $stored)) {
                $login_ok = true;
                // Optional rehash if algorithm changed
                if (function_exists('password_needs_rehash') && password_needs_rehash($stored, PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    if ($newHash) {
                        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->bind_param("si", $newHash, $user['id']);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }
        }

        // Legacy support: md5/sha1/plaintext -> verify and upgrade
        if (!$login_ok) {
            $is_md5 = preg_match('/^[a-f0-9]{32}$/i', $stored) === 1;
            $is_sha1 = preg_match('/^[a-f0-9]{40}$/i', $stored) === 1;
            if ($is_md5) {
                if (hash_equals(strtolower($stored), md5($password))) {
                    $login_ok = true;
                }
            } elseif ($is_sha1) {
                if (hash_equals(strtolower($stored), sha1($password))) {
                    $login_ok = true;
                }
            } else {
                // Treat as plaintext if not a recognized hash pattern
                if (hash_equals($stored, $password)) {
                    $login_ok = true;
                }
            }

            // If legacy matched, upgrade to modern hash
            if ($login_ok) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                if ($newHash) {
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param("si", $newHash, $user['id']);
                    $upd->execute();
                    $upd->close();
                }
            }
        }

        if ($login_ok) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $role = strtolower((string)$user['role']);
            $_SESSION['role'] = $role;
            if (isset($user['admin_role'])) {
                $_SESSION['admin_role'] = strtolower((string)$user['admin_role']);
            } else {
                unset($_SESSION['admin_role']);
            }
            // Optional session extras
            if (isset($user['full_name'])) $_SESSION['full_name'] = $user['full_name'];
            if (isset($user['email'])) $_SESSION['email'] = $user['email'];
            // Clear preserved identifier after successful login
            unset($_SESSION['last_login_identifier']);

            // If account is inactive, show generic invalid message (do not reveal status)
            if ($hasActiveCol && isset($user['is_active']) && intval($user['is_active']) !== 1) {
                // Clear partial session
                session_unset();
                session_destroy();
                header("Location: index.php?error=Invalid username or password");
                exit();
            }
            // If force_password_change flag set at users table, require immediate change
            $mustChange = $hasForceCol ? (!empty($user['force_password_change'])) : false;
            if ($mustChange) {
                $_SESSION['must_change_password'] = true;
                header("Location: change_password.php");
                exit();
            }
            // Get tenant info
            if ($role === 'tenant') {
                $tenant_stmt = $conn->prepare("SELECT tenant_id FROM tenants WHERE user_id = ?");
                $tenant_stmt->bind_param("i", $user['id']);
                $tenant_stmt->execute();
                $tenant_result = $tenant_stmt->get_result();
                if ($tenant_result->num_rows == 1) {
                    $tenant = $tenant_result->fetch_assoc();
                    $_SESSION['tenant_id'] = $tenant['tenant_id'];
                    // Optional: also honor tenants.must_change_password if present
                    if (function_exists('db_column_exists') && db_column_exists($conn, 'tenants', 'must_change_password')) {
                        try {
                            $mc = $conn->prepare("SELECT must_change_password FROM tenants WHERE tenant_id = ?");
                            $mc->bind_param("i", $tenant['tenant_id']);
                            $mc->execute();
                            $mcRes = $mc->get_result();
                            $mcRow = $mcRes ? $mcRes->fetch_assoc() : null;
                            $mc->close();
                            if ($mcRow && intval($mcRow['must_change_password']) === 1) {
                                $_SESSION['must_change_password'] = true;
                                header("Location: change_password.php");
                                exit();
                            }
                        } catch (Throwable $e) { /* ignore */ }
                    }
                }
                header("Location: tenant/dashboard.php");
                exit();
            } else {
                // Route super admins to dedicated dashboard if flagged
                $adminRole = isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : '';
                if ($adminRole === 'super_admin') {
                    header("Location: admin/super_dashboard.php");
                } else {
                    header("Location: admin/dashboard.php");
                }
                exit();
            }
        } else {
            // Count failed attempts for this identifier; lock for 30s on 5th failure
            $attempts = isset($_SESSION['login_attempts'][$identifier]) ? intval($_SESSION['login_attempts'][$identifier]) : 0;
            $attempts++;
            $_SESSION['login_attempts'][$identifier] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_lock_until'][$identifier] = time() + 30;
                $remaining = 30;
                // Optional: reset attempts so user starts fresh after lock expires
                // $_SESSION['login_attempts'][$identifier] = 0;
                $_SESSION['last_login_identifier'] = $username_input;
                // Redirect without static error text; client shows realtime countdown
                header("Location: index.php?lock=1&remain=$remaining");
            } else {
                $_SESSION['last_login_identifier'] = $username_input;
                header("Location: index.php?error=Invalid username or password");
            }
            exit();
        }
        $stmt->close();
    } else {
        $_SESSION['last_login_identifier'] = $username_input;
        header("Location: index.php?error=Invalid username or password");
        exit();
    }
}

$conn->close();
?>