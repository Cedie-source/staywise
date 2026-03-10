<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Admin Profile";

// Fetch current admin info

$stmt = $conn->prepare("SELECT username, full_name, email FROM users WHERE id = ?");
if (!$stmt) {
    die('Database prepare failed: ' . $conn->error);
}
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // User not found, logout for safety
    header("Location: ../logout.php");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

$success = $error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token. Please try again.";
    }
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    // Basic validation
    if (empty($full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Update query (password change is handled separately via change_password.php with OTP)
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $email, $_SESSION['user_id']);

        if ($stmt->execute()) {
            $success = "Profile updated successfully.";
            // Refresh user data
            $user['full_name'] = $full_name;
            $user['email'] = $email;
            // Sync to tenants table if this user has a tenant record
            $sync = $conn->prepare("UPDATE tenants SET name = ?, email = ? WHERE user_id = ?");
            if ($sync) {
                $sync->bind_param("ssi", $full_name, $email, $_SESSION['user_id']);
                $sync->execute();
                $sync->close();
            }
        } else {
            $error = "Failed to update profile. Please try again.";
        }
        $stmt->close();
    }
}

include '../includes/header.php';
?>

<div class="container mt-4">
    <h2 class="dashboard-title">Admin Profile</h2>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" class="needs-validation" novalidate>
        <?php echo csrf_input(); ?>
        <div class="mb-3">
            <label for="username" class="form-label">Username (cannot change)</label>
            <input type="text" id="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
        </div>

        <div class="mb-3">
            <label for="full_name" class="form-label">Full Name *</label>
            <input type="text" name="full_name" id="full_name" class="form-control" required value="<?php echo htmlspecialchars($user['full_name']); ?>">
            <div class="invalid-feedback">Please enter your full name.</div>
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email *</label>
            <input type="email" name="email" id="email" class="form-control" required value="<?php echo htmlspecialchars($user['email']); ?>">
            <div class="invalid-feedback">Please enter a valid email address.</div>
        </div>

        <hr>

        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0">Password</h5>
            <a href="change_password.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-key me-1"></i>Change Password</a>
        </div>
        <p class="text-muted small">Password changes require email verification for security.</p>

        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
    </form>
</div>

<script>
// Bootstrap form validation
(() => {
  'use strict'
  const forms = document.querySelectorAll('.needs-validation')
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) {
        event.preventDefault()
        event.stopPropagation()
      }
      form.classList.add('was-validated')
    }, false)
  })
})()
</script>

<?php include '../includes/footer.php'; ?>
