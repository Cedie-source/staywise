<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Super admin only
if (function_exists('require_super_admin')) {
    require_super_admin();
} else {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') != 'admin' || strtolower($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        header("Location: ../index.php");
        exit();
    }
}

$page_title = 'System Settings';

// Helper: fetch setting by key
function get_setting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    if (!$stmt) { return $default; }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    // Use bind_result/fetch for broader compatibility (avoids mysqlnd dependency)
    $value = null;
    if ($stmt->bind_result($value) && $stmt->fetch()) {
        $stmt->close();
        return (string)($value ?? $default);
    }
    $stmt->close();
    return $default;
}
// Helper: set setting
function set_setting($conn, $key, $value) {
    // Upsert
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $site_name = trim($_POST['site_name'] ?? 'StayWise');
    $base_url = trim($_POST['base_url'] ?? '/StayWise/');
    $pw_min = (int)($_POST['password_min_length'] ?? 8);
    $pw_upper = isset($_POST['password_require_uppercase']) ? '1' : '0';
    $pw_number = isset($_POST['password_require_number']) ? '1' : '0';
    $pw_special = isset($_POST['password_require_special']) ? '1' : '0';

    $errs = [];
    if ($site_name === '') $errs[] = 'Site name is required.';
    if ($base_url === '') $errs[] = 'Base URL is required.';
    if ($pw_min < 6) $errs[] = 'Password minimum length must be at least 6.';

    if (empty($errs)) {
        $ok = true;
        $ok = $ok && set_setting($conn, 'site_name', $site_name);
        $ok = $ok && set_setting($conn, 'base_url', $base_url);
        $ok = $ok && set_setting($conn, 'password_min_length', (string)$pw_min);
        $ok = $ok && set_setting($conn, 'password_require_uppercase', $pw_upper);
        $ok = $ok && set_setting($conn, 'password_require_number', $pw_number);
        $ok = $ok && set_setting($conn, 'password_require_special', $pw_special);
        if ($ok) {
            $success = 'Settings saved.';
            // Log admin action (best-effort)
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'update_settings', ?)");
                $details = json_encode(['site_name'=>$site_name,'base_url'=>$base_url,'pw_min'=>$pw_min,'upper'=>$pw_upper,'num'=>$pw_number,'special'=>$pw_special]);
                $log->bind_param('is', $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
        } else {
            $error = 'Failed to save settings.';
        }
    } else {
        // Use actual newlines for display with nl2br
        $error = implode("\n", $errs);
    }
}

// Prefill current settings
$cur_site_name = get_setting($conn, 'site_name', 'StayWise');
$cur_base_url = get_setting($conn, 'base_url', '/StayWise/');
$cur_pw_min = (int)get_setting($conn, 'password_min_length', '8');
$cur_pw_upper = get_setting($conn, 'password_require_uppercase', '1') === '1';
$cur_pw_number = get_setting($conn, 'password_require_number', '1') === '1';
$cur_pw_special = get_setting($conn, 'password_require_special', '1') === '1';

include '../includes/header.php';
?>
<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title"><i class="fas fa-cogs me-2"></i>System Settings</h2>
    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error)); ?></div><?php endif; ?>
        <div class="card flat-card">
        <div class="card-body">
            <form method="post" class="needs-validation" novalidate>
                <?php echo csrf_input(); ?>
                <div class="mb-3">
                    <label class="form-label" for="site_name">Site Name</label>
                    <input type="text" class="form-control" id="site_name" name="site_name" required value="<?php echo htmlspecialchars($cur_site_name); ?>" />
                </div>
                <div class="mb-3">
                    <label class="form-label" for="base_url">Base URL</label>
                    <input type="text" class="form-control" id="base_url" name="base_url" required value="<?php echo htmlspecialchars($cur_base_url); ?>" />
                    <div class="form-text">Example: /StayWise/ (include leading and trailing slashes for subfolder deployments)</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password_min_length">Password Minimum Length</label>
                    <input type="number" class="form-control" id="password_min_length" name="password_min_length" min="6" max="64" value="<?php echo (int)$cur_pw_min; ?>" />
                </div>
                <div class="mb-3">
                    <label class="form-label">Password Requirements</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pw_upper" name="password_require_uppercase" <?php echo $cur_pw_upper ? 'checked' : ''; ?> />
                        <label class="form-check-label" for="pw_upper">Require at least one uppercase letter</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pw_number" name="password_require_number" <?php echo $cur_pw_number ? 'checked' : ''; ?> />
                        <label class="form-check-label" for="pw_number">Require at least one number</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="pw_special" name="password_require_special" <?php echo $cur_pw_special ? 'checked' : ''; ?> />
                        <label class="form-check-label" for="pw_special">Require at least one special character</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
