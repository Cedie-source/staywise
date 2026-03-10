<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
// Ensure required columns exist
if (function_exists('db_ensure_user_force_change_columns')) { db_ensure_user_force_change_columns($conn); }

// Super admin only
if (function_exists('require_super_admin')) {
    require_super_admin();
} else {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') != 'admin' || strtolower($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        header("Location: ../index.php");
        exit();
    }
}

$page_title = "Admin Management";

// Handle add admin (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    // Normalize username/email to lowercase for consistent storage
    $username = strtolower(trim($_POST['username'] ?? ''));
    $passwordPlain = $_POST['password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));

    $errs = [];
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) $errs[] = 'Username must be 3-20 characters using letters, numbers, or underscores.';
    // Password must include uppercase, number, and special character
    $pwErrs = [];
    if (!preg_match('/[A-Z]/', $passwordPlain)) $pwErrs[] = 'one uppercase letter';
    if (!preg_match('/[0-9]/', $passwordPlain)) $pwErrs[] = 'one number';
    if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $passwordPlain)) $pwErrs[] = 'one special character';
    if (!empty($pwErrs)) $errs[] = 'Password must contain at least ' . implode(', ', $pwErrs) . '.';
    if ($full_name === '') $errs[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Valid email is required.';

    if (empty($errs)) {
        // Uniqueness checks
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
    $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errs[] = 'Username already exists.';
        $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
    $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errs[] = 'Email is already in use.';
        $stmt->close();
    }

    if (empty($errs)) {
        $password = password_hash($passwordPlain, PASSWORD_DEFAULT);
        // Set force_password_change=1 for new admin accounts
        if (db_column_exists($conn, 'users', 'force_password_change')) {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, force_password_change) VALUES (?, ?, ?, ?, 'admin', 1)");
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, 'admin')");
        }
        $stmt->bind_param("ssss", $username, $password, $full_name, $email);
        if ($stmt->execute()) {
            // Fallback: ensure the flag is set even if the insert branch couldn't include it
            $newAdminId = $conn->insert_id;
            if (db_column_exists($conn, 'users', 'force_password_change') && $newAdminId) {
                try {
                    $upd = $conn->prepare("UPDATE users SET force_password_change = 1 WHERE id = ?");
                    $upd->bind_param("i", $newAdminId);
                    $upd->execute();
                    $upd->close();
                } catch (Throwable $e) { /* ignore */ }
            }
            $success = 'New admin added successfully!';
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'add_admin', ?)");
                $details = "Added admin: $username";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
        } else {
            $error = 'Failed to add admin.';
        }
        $stmt->close();
    } else {
        $error = implode("\n", $errs);
    }
}

// Handle delete admin (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['delete_admin_id']);
    if ($id != $_SESSION['user_id']) { // Prevent self-delete
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'delete_admin', ?)");
                $details = "Deleted admin ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
            $success = 'Admin deleted.';
        } else {
            $error = 'Failed to delete admin.';
        }
        $stmt->close();
    } else {
        $error = 'You cannot delete your own account.';
    }
}

// Handle update admin (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['admin_id'] ?? 0);
    // Normalize username/email to lowercase for consistent storage
    $username = strtolower(trim($_POST['username'] ?? ''));
    $full_name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $passwordPlain = $_POST['password'] ?? '';

    $errs = [];
    if ($id <= 0) $errs[] = 'Invalid admin selected.';
    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) $errs[] = 'Username must be 3-20 characters using letters, numbers, or underscores.';
    if ($full_name === '') $errs[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Valid email is required.';
    if ($passwordPlain !== '') {
        $pwErrs = [];
        if (!preg_match('/[A-Z]/', $passwordPlain)) $pwErrs[] = 'one uppercase letter';
        if (!preg_match('/[0-9]/', $passwordPlain)) $pwErrs[] = 'one number';
        if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $passwordPlain)) $pwErrs[] = 'one special character';
        if (!empty($pwErrs)) $errs[] = 'If changing password, it must contain at least ' . implode(', ', $pwErrs) . '.';
    }

    if (empty($errs)) {
        // Uniqueness checks excluding current id
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(username) = ? AND id <> ?");
    $stmt->bind_param("si", $username, $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errs[] = 'Username already exists.';
        $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?");
    $stmt->bind_param("si", $email, $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errs[] = 'Email is already in use.';
        $stmt->close();
    }

    if (empty($errs)) {
        if ($passwordPlain !== '') {
            $password = password_hash($passwordPlain, PASSWORD_DEFAULT);
            if (db_column_exists($conn, 'users', 'force_password_change') && $id != $_SESSION['user_id']) {
                // If admin resets another admin's password, require change on next login
                $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, password = ?, force_password_change = 1 WHERE id = ? AND role = 'admin'");
                $stmt->bind_param("ssssi", $username, $full_name, $email, $password, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, password = ? WHERE id = ? AND role = 'admin'");
                $stmt->bind_param("ssssi", $username, $full_name, $email, $password, $id);
            }
        } else {
            $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, email = ? WHERE id = ? AND role = 'admin'");
            $stmt->bind_param("sssi", $username, $full_name, $email, $id);
        }
        if ($stmt->execute()) {
            $success = 'Admin updated successfully!';
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'update_admin', ?)");
                $details = "Updated admin ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
        } else {
            $error = 'Failed to update admin.';
        }
        $stmt->close();
    } else {
        $error = implode("\n", $errs);
    }
}

// Handle require password change for an admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['require_pw_change_admin_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['require_pw_change_admin_id']);
    if ($id > 0) {
        if (function_exists('db_column_exists') && !db_column_exists($conn, 'users', 'force_password_change')) {
            $error = 'Cannot require password change (missing users.force_password_change column).';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE users SET force_password_change = 1 WHERE id = ? AND role = 'admin'");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success = 'Password change required for selected admin on next login.';
                    try {
                        $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'force_pw_change_admin', ?)");
                        $details = "Forced password change for admin ID: $id";
                        $log->bind_param("is", $_SESSION['user_id'], $details);
                        $log->execute();
                        $log->close();
                    } catch (Throwable $e) {}
                } else {
                    $error = 'Failed to set password change requirement.';
                }
                $stmt->close();
            } catch (Throwable $e) {
                $error = 'Failed to set password change requirement.';
            }
        }
    }
}

// Fetch all admins
$result = $conn->query("SELECT id, username, full_name, email, force_password_change FROM users WHERE role = 'admin'");

include '../includes/header.php';
?>
<div class="container mt-4 admin-ui">
    <?php if (function_exists('db_column_exists') && !db_column_exists($conn, 'users', 'force_password_change')): ?>
        <div class="alert alert-warning d-flex align-items-start" role="alert">
            <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
            <div>
                <strong>Password change enforcement not fully enabled.</strong>
                <div class="small mt-1">
                    To require new admin accounts to change their password on first login, add the following columns to the <code>users</code> table:
                </div>
                <pre class="mb-1 mt-2" style="white-space:pre-wrap;">
ALTER TABLE `users` ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL;
                </pre>
                <div class="small">After adding, newly created admins will be forced to change their password.</div>
            </div>
        </div>
    <?php endif; ?>
    <h2 class="dashboard-title">Admin Management</h2>
    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo nl2br(htmlspecialchars($success)); ?></div><?php endif; ?>
    <?php if (isset($error)): ?><div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error)); ?></div><?php endif; ?>
    <div class="mb-3">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal"><i class="fas fa-user-plus me-2"></i>Add New Admin</button>
    </div>
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Username</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-warning btn-edit-admin"
                                data-bs-toggle="modal" data-bs-target="#editAdminModal"
                                data-id="<?php echo (int)$row['id']; ?>"
                                data-username="<?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>"
                                data-full_name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars($row['email'], ENT_QUOTES); ?>">
                            Edit
                        </button>
                        <form method="post" class="d-inline" title="Require password change on next login">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="require_pw_change_admin_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning"><i class="fa-solid fa-key"></i></button>
                        </form>
                        <?php if ($row['id'] != $_SESSION['user_id']): ?>
                            <form method="post" class="d-inline" onsubmit="return confirmDelete('Are you sure you want to delete this admin? This action cannot be undone.')">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="delete_admin_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    
    <!-- Add Admin Modal -->
    <div class="modal fade" id="addAdminModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" class="needs-validation" novalidate>
                    <?php echo csrf_input(); ?>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                            <div class="form-text">3-20 chars, letters/numbers/underscore.</div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                <div class="pw-toggle-wrap">
                <input type="password" class="form-control" id="password" name="password" required style="padding-right:2.5rem"
                    pattern="^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$"
                    title="Password must include at least one uppercase letter, one number, and one special character">
                <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                </div>
                            <div id="addAdminPwdHelp" class="form-text mt-1">Must include uppercase, number, and special character.</div>
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_admin" class="btn btn-primary">Add Admin</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Admin Modal -->
<div class="modal fade" id="editAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Admin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" class="needs-validation" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_id" id="edit_admin_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="edit_username" name="username" required>
                        <div class="form-text">3-20 chars, letters/numbers/underscore.</div>
                    </div>
                    <div class="mb-3">
                        <label for="edit_full_name" class="form-label">Full Name</label>
                        <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_password" class="form-label">New Password (optional)</label>
               <div class="pw-toggle-wrap">
               <input type="password" class="form-control" id="edit_password" name="password" placeholder="Leave blank to keep current password" style="padding-right:2.5rem"
                   pattern="^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).*$">
               <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
               </div>
                        <div id="editAdminPwdHelp" class="form-text mt-1"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_admin" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
</div>

<script>
// Populate Edit Admin modal with row data
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.btn-edit-admin').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id = this.getAttribute('data-id');
      const username = this.getAttribute('data-username');
      const fullName = this.getAttribute('data-full_name');
      const email = this.getAttribute('data-email');
      document.getElementById('edit_admin_id').value = id;
      document.getElementById('edit_username').value = username;
      document.getElementById('edit_full_name').value = fullName;
      document.getElementById('edit_email').value = email;
      const pwd = document.getElementById('edit_password');
      if (pwd) pwd.value = '';
    });
  });
});
</script>
<script>
// Real-time password validation for Add and Edit Admin modals
document.addEventListener('DOMContentLoaded', function(){
    function meetsRules(v){
        return /[A-Z]/.test(v) && /[0-9]/.test(v) && /[!@#$%^&*()_+\-=\[\]{};:'"\\|,.<>\/?]/.test(v);
    }
    const addForm = document.querySelector('#addAdminModal form');
    if (addForm) {
        const pwd = document.getElementById('password');
        const help = document.getElementById('addAdminPwdHelp');
        const submit = addForm.querySelector('button[type="submit"]');
        function upd(){
            const v = pwd.value || '';
            const ok = v.length>0 && meetsRules(v);
            if (!v) { help.textContent = 'Must include uppercase, number, and special character.'; help.className='form-text mt-1'; }
            else if (ok) { help.textContent = 'Strong password.'; help.className='form-text mt-1 text-success'; }
            else { help.textContent = 'Weak password. Add uppercase, number, and special character.'; help.className='form-text mt-1 text-danger'; }
            submit.disabled = !ok;
        }
        submit.disabled = true;
        pwd.addEventListener('input', upd);
        upd();
    }

    const editForm = document.querySelector('#editAdminModal form');
    if (editForm) {
        const pwd = document.getElementById('edit_password');
        const help = document.getElementById('editAdminPwdHelp');
        const submit = editForm.querySelector('button[type="submit"]');
        function upd(){
            const v = pwd.value || '';
            if (!v) { help.textContent = ''; help.className='form-text mt-1'; submit.disabled = false; return; }
            const ok = meetsRules(v);
            if (ok) { help.textContent = 'Strong password.'; help.className='form-text mt-1 text-success'; }
            else { help.textContent = 'Weak password. Add uppercase, number, and special character.'; help.className='form-text mt-1 text-danger'; }
            submit.disabled = !ok;
        }
        pwd.addEventListener('input', upd);
    }
});
</script>
<?php include '../includes/footer.php'; ?>

<script>
// Unified confirmation helper for destructive actions
function confirmDelete(message){
    try {
        return window.confirm(message || 'Are you sure you want to proceed?');
    } catch (e) {
        return true;
    }
}
</script>
