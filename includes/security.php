<?php
// Security helpers: CSRF protection and session cookie hardening

if (!function_exists('set_secure_session_cookies')) {
    function set_secure_session_cookies() {
        // Detect HTTPS — also handles Cloudflare / reverse-proxy setups (e.g. InfinityFree)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (($_SERVER['HTTP_CF_VISITOR'] ?? '') && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false)
                || (($_SERVER['SERVER_PORT'] ?? '') == '443');

        // Must be called BEFORE session_start() to take effect
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');
        // Use 'Lax' instead of 'Strict' to prevent CSRF token loss on redirect flows
        @ini_set('session.cookie_samesite', 'Lax');
        if ($isHttps) {
            @ini_set('session.cookie_secure', '1');
        }
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input() {
        $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $t . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (!isset($_SESSION['csrf_token']) || !is_string($token) || empty($token)) {
            return false;
        }
        // Validate without rotating — rotating causes "Invalid token" errors
        // when a form re-renders after a failed submission (e.g. wrong password)
        // because the new token in the session no longer matches the form's token.
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Database helpers: ensure required auth columns exist
if (!function_exists('db_column_exists')) {
    function db_column_exists($conn, $table, $column) {
        // Prefer information_schema which works reliably with prepared statements
        try {
            $dbRes = $conn->query('SELECT DATABASE() AS db');
            $dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
            $dbName = $dbRow ? $dbRow['db'] : '';
            if (!empty($dbName)) {
                $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param('sss', $dbName, $table, $column);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $exists = $res && $res->num_rows > 0;
                    $stmt->close();
                    if ($exists) return true;
                }
            }
        } catch (Throwable $e) { /* continue to fallback */ }

        // Fallback: SHOW COLUMNS with safely-escaped identifiers
        try {
            $tableEsc = $conn->real_escape_string($table);
            $colEsc = $conn->real_escape_string($column);
            $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$colEsc}'";
            $res = $conn->query($sql);
            return ($res && $res->num_rows > 0);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('db_ensure_user_force_change_columns')) {
    function db_ensure_user_force_change_columns($conn) {
        // Add users.force_password_change tinyint default 0
        if (!db_column_exists($conn, 'users', 'force_password_change')) {
            try {
                $conn->query("ALTER TABLE `users` ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
            } catch (Throwable $e) { /* ignore */ }
        }
        // Optional: password_changed_at
        if (!db_column_exists($conn, 'users', 'password_changed_at')) {
            try {
                $conn->query("ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL AFTER `force_password_change`");
            } catch (Throwable $e) { /* ignore */ }
        }
        // Account active flag
        if (!db_column_exists($conn, 'users', 'is_active')) {
            try {
                $conn->query("ALTER TABLE `users` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `password_changed_at`");
            } catch (Throwable $e) { /* ignore */ }
        }
        // Password reset support
        if (!db_column_exists($conn, 'users', 'reset_token')) {
            try {
                $conn->query("ALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(255) NULL AFTER `is_active`");
            } catch (Throwable $e) { /* ignore */ }
        }
        if (!db_column_exists($conn, 'users', 'reset_expires')) {
            try {
                $conn->query("ALTER TABLE `users` ADD COLUMN `reset_expires` DATETIME NULL AFTER `reset_token`");
            } catch (Throwable $e) { /* ignore */ }
        }
    }
}

?>

<?php
// Access control helpers
if (!function_exists('require_admin_role')) {
    function require_admin_role($requiredAdminRole = null) {
        if (!isset($_SESSION)) { session_start(); }
        $isAdmin = isset($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'admin');
        if (!$isAdmin) {
            header('Location: ../index.php');
            exit();
        }
        if ($requiredAdminRole !== null) {
            $adminRole = strtolower((string)($_SESSION['admin_role'] ?? ''));
            if ($adminRole !== strtolower($requiredAdminRole)) {
                header('Location: ../index.php');
                exit();
            }
        }
    }
}

if (!function_exists('require_super_admin')) {
    function require_super_admin() { require_admin_role('super_admin'); }
}
