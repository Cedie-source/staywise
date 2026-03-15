<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Payment Settings";

// Helper functions
function get_setting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $value = null;
    if ($stmt->bind_result($value) && $stmt->fetch()) {
        $stmt->close();
        return (string)($value ?? $default);
    }
    $stmt->close();
    return $default;
}

function set_setting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$success = null;
$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    // GCash Manual Settings
    if (isset($_POST['save_gcash'])) {
        $gcash_enabled = isset($_POST['gcash_enabled']) ? '1' : '0';
        $gcash_number = trim($_POST['gcash_number'] ?? '');
        $gcash_name = trim($_POST['gcash_name'] ?? '');

        set_setting($conn, 'gcash_enabled', $gcash_enabled);
        set_setting($conn, 'gcash_number', $gcash_number);
        set_setting($conn, 'gcash_name', $gcash_name);

        // Handle QR code image upload
        if (isset($_FILES['gcash_qr_image']) && $_FILES['gcash_qr_image']['error'] == 0) {
            $upload_dir = '../uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['gcash_qr_image']['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed) && $_FILES['gcash_qr_image']['size'] <= 2 * 1024 * 1024) {
                $filename = 'gcash_qr_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['gcash_qr_image']['tmp_name'], $upload_dir . $filename)) {
                    // Delete old QR image
                    $oldQr = get_setting($conn, 'gcash_qr_image', '');
                    if ($oldQr && file_exists($upload_dir . $oldQr)) {
                        unlink($upload_dir . $oldQr);
                    }
                    set_setting($conn, 'gcash_qr_image', $filename);
                }
            } else {
                $error = "QR image must be JPG, PNG, or WEBP and under 2MB.";
            }
        }

        if (!$error) {
            logAdminAction($conn, $_SESSION['user_id'], 'update_payment_settings', "Updated GCash settings");
            $success = "GCash settings saved successfully!";
        }
    }

    // PayMongo Settings
    if (isset($_POST['save_paymongo'])) {
        $paymongo_enabled = isset($_POST['paymongo_enabled']) ? '1' : '0';
        $paymongo_secret = trim($_POST['paymongo_secret_key'] ?? '');
        $paymongo_public = trim($_POST['paymongo_public_key'] ?? '');
        $paymongo_webhook = trim($_POST['paymongo_webhook_secret'] ?? '');

        set_setting($conn, 'paymongo_enabled', $paymongo_enabled);
        if (!empty($paymongo_secret)) {
            set_setting($conn, 'paymongo_secret_key', $paymongo_secret);
        }
        if (!empty($paymongo_public)) {
            set_setting($conn, 'paymongo_public_key', $paymongo_public);
        }
        set_setting($conn, 'paymongo_webhook_secret', $paymongo_webhook);

        logAdminAction($conn, $_SESSION['user_id'], 'update_payment_settings', "Updated PayMongo settings");
        $success = "PayMongo settings saved successfully!";
    }
}

// Load current settings
$gcash_enabled = get_setting($conn, 'gcash_enabled', '0') === '1';
$gcash_number = get_setting($conn, 'gcash_number', '');
$gcash_name = get_setting($conn, 'gcash_name', '');
$gcash_qr_image = get_setting($conn, 'gcash_qr_image', '');
$paymongo_enabled = get_setting($conn, 'paymongo_enabled', '0') === '1';
$paymongo_secret = get_setting($conn, 'paymongo_secret_key', '');
$paymongo_public = get_setting($conn, 'paymongo_public_key', '');
$paymongo_webhook = get_setting($conn, 'paymongo_webhook_secret', '');

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title"><i class="fas fa-wallet me-2"></i>Payment Settings</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- GCash Manual Settings -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header" style="background: #007DFE; color: white;">
                    <h5 class="mb-0"><i class="fas fa-mobile-alt me-2"></i>GCash Manual Payment</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrf_input(); ?>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="gcash_enabled" name="gcash_enabled" <?php echo $gcash_enabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="gcash_enabled">
                                <strong>Enable GCash Payments</strong>
                            </label>
                            <div class="form-text">Show GCash payment option to tenants</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">GCash Number *</label>
                            <input type="text" class="form-control" name="gcash_number" value="<?php echo htmlspecialchars($gcash_number); ?>" placeholder="09XXXXXXXXX">
                            <div class="form-text">Your GCash number where tenants will send payments</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Account Name *</label>
                            <input type="text" class="form-control" name="gcash_name" value="<?php echo htmlspecialchars($gcash_name); ?>" placeholder="Juan Dela Cruz">
                            <div class="form-text">GCash account name for verification</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">GCash QR Code Image</label>
                            <?php if (!empty($gcash_qr_image)): ?>
                                <div class="mb-2">
                                    <img src="../uploads/<?php echo htmlspecialchars($gcash_qr_image); ?>" alt="Current QR" class="img-thumbnail" style="max-width: 150px;">
                                    <br><small class="text-muted">Current QR code</small>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" name="gcash_qr_image" accept=".jpg,.jpeg,.png,.webp">
                            <div class="form-text">Upload your GCash QR code image (JPG, PNG, WEBP, max 2MB)</div>
                        </div>

                        <button type="submit" name="save_gcash" class="btn btn-primary" style="background: #007DFE; border-color: #007DFE;">
                            <i class="fas fa-save me-2"></i>Save GCash Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- PayMongo Settings -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-globe me-2"></i>PayMongo Online Payments</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_input(); ?>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="paymongo_enabled" name="paymongo_enabled" <?php echo $paymongo_enabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="paymongo_enabled">
                                <strong>Enable PayMongo Payments</strong>
                            </label>
                            <div class="form-text">Allow tenants to pay online via GCash, GrabPay, or Card</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Public Key *</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="paymongo_public_key" id="pmPublicKey"
                                       value="<?php echo htmlspecialchars($paymongo_public); ?>" placeholder="pk_test_xxxxxxxx">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('pmPublicKey')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" name="save_paymongo" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Save PayMongo Settings
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(id) {
    var input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}
</script>

<?php include '../includes/footer.php'; ?>
