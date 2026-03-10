<?php
// TEMPORARY LOCAL RESET TOOL — DELETE AFTER USE
// Restrict to localhost and a static token to prevent exposure
require_once '../../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../../config/db.php';

$allowedAddrs = ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '127.0.0.1'];
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, $allowedAddrs, true)) {
        http_response_code(403);
        ?><!DOCTYPE html>
        <html lang="en"><head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Access Denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
        </head><body class="container py-4">
            <div class="alert alert-danger">
                <strong>Forbidden:</strong> This tool is only available from localhost.
            </div>
            <p>Your IP: <code><?php echo htmlspecialchars($remote, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>Allowed: <code>127.0.0.1</code> or <code>::1</code> (open on the same machine).</p>
            <p>Tip: Use <code>http://localhost/StayWise/admin/tools/reset_admin.php?token=reset-2026-01-10-local-only</code></p>
        </body></html><?php
        exit();
}

// Change this token if you want; share only with trusted local users
$RESET_TOKEN = 'reset-2026-01-10-local-only';
if (!isset($_GET['token']) || !hash_equals($RESET_TOKEN, (string)$_GET['token'])) {
        http_response_code(403);
        ?><!DOCTYPE html>
        <html lang="en"><head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title>Invalid Token</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
        </head><body class="container py-4">
            <div class="alert alert-warning">
                <strong>Forbidden:</strong> Missing or invalid token.
            </div>
            <p>Open this exact URL:</p>
            <p><code>/StayWise/admin/tools/reset_admin.php?token=<?php echo htmlspecialchars($RESET_TOKEN, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <p>Example: <code>http://localhost/StayWise/admin/tools/reset_admin.php?token=<?php echo htmlspecialchars($RESET_TOKEN, ENT_QUOTES, 'UTF-8'); ?></code></p>
        </body></html><?php
        exit();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { $error = 'Invalid request token.'; }
    $username = strtolower(trim($_POST['username'] ?? 'admin'));
    $newPw = (string)($_POST['password'] ?? '');
    if (!$error) {
        if (!preg_match('/^.{6,}$/', $newPw)) { $error = 'Password must be at least 6 characters.'; }
    }
    if (!$error) {
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        if (!$hash) { $error = 'Failed to hash password.'; }
        if (!$error) {
            // Only update existing admin accounts; do NOT change roles here
            $sql = "UPDATE users SET password = ?, force_password_change = 1, is_active = 1 WHERE LOWER(username) = ? AND role = 'admin'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ss', $hash, $username);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                $info = 'Admin password reset successful. You can now log in and you will be asked to change it.';
            } else {
                $error = 'No admin account updated. Check the username or ensure the account has role=admin.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Local Admin Password Reset</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
<style>
    /* Constrain content width for readability */
    body.reset-card { max-width: 450px; margin: 0 auto; }
    @media (max-width: 480px) {
        body.reset-card { max-width: 360px; }
    }
    /* Modern panel */
    .reset-panel {
        background: #181B2A;
        color: #E5E7EB;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 16px 40px rgba(0,0,0,0.55);
        padding: 1.5rem 1.75rem;
    }
    .reset-title {
        font-weight: 800;
        margin-bottom: 1rem;
        background: linear-gradient(90deg, #60A5FA, #3B82F6);
        background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    }
    .reset-sub { color: #A3A7B3; }
    .form-label { margin-bottom: 0.35rem; font-weight: 600; letter-spacing: 0.2px; }
    .mb-1 { margin-bottom: 0.35rem !important; }
    .form-control {
        background: #232636;
        color: #E5E7EB;
        border: 2px solid #3B82F6;
        border-radius: 12px;
        height: 52px;
        padding: 0.65rem 0.9rem;
        font-size: 1.05rem;
        transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
    }
    .form-control::placeholder { color: #94A3B8; }
    .form-control:focus { border-color: #2563EB; box-shadow: 0 0 0 0.15rem rgba(37,99,235,.25); }
    .input-group .btn { min-height: 52px; border-radius: 12px; }
    .btn-primary { background: linear-gradient(90deg, #3B82F6, #2563EB); border: none; }
    .btn-outline-secondary { border-width: 2px; }
    .divider { border-top: 1px solid rgba(255,255,255,0.08); }
    /* Autofill preservation for dark theme */
    .form-control:-webkit-autofill,
    .form-control:-webkit-autofill:hover,
    .form-control:-webkit-autofill:focus {
        -webkit-text-fill-color: #E5E7EB !important;
        box-shadow: 0 0 0px 1000px #232636 inset !important;
        border: 2px solid #3B82F6 !important;
    }
</style>
</head>
<body class="container py-4 reset-card">
    <div class="reset-panel">
        <div class="d-flex align-items-center mb-2">
            <i class="fa-solid fa-key me-2 text-primary"></i>
            <h1 class="h4 reset-title mb-0">Local Admin Password Reset</h1>
        </div>
        <p class="reset-sub">Restricted to localhost. Delete this file after use: <code>admin/tools/reset_admin.php</code></p>
        <?php if ($info): ?><div class="alert alert-success"><?php echo h($info); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
        <form method="post" class="row gy-2 gx-2">
            <?php echo csrf_input(); ?>
            <div class="col-12 mb-1">
                <label class="form-label" for="admin_username">Username</label>
                <input type="text" id="admin_username" class="form-control" name="username" value="<?php echo h($_POST['username'] ?? 'admin'); ?>" required />
            </div>
            <div class="col-12 mb-1">
                <label class="form-label" for="admin_new_password">New Password</label>
                <div class="input-group">
                    <input type="password" id="admin_new_password" class="form-control" name="password" placeholder="New password" required />
                    <button class="btn btn-outline-light" type="button" id="togglePw" title="Show/Hide">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary flex-fill" type="submit">Reset Password</button>
                <a class="btn btn-outline-secondary" href="../../index.php">Back to Login</a>
            </div>
        </form>
        <div class="divider my-3"></div>
        <div class="small reset-sub">
            URL to open: <code>/StayWise/admin/tools/reset_admin.php?token=<?php echo h($RESET_TOKEN); ?></code>
        </div>
    </div>
</body>
</html>
<script>
// Password visibility toggle
(function(){
    var btn = document.getElementById('togglePw');
    var input = document.getElementById('admin_new_password');
    if (!btn || !input) return;
    btn.addEventListener('click', function(){
        var isText = input.type === 'text';
        input.type = isText ? 'password' : 'text';
        var icon = btn.querySelector('i');
        if (icon) { icon.className = isText ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'; }
    });
})();
</script>
</html>
