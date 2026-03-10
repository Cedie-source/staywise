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

$page_title = 'Role Manager';

$success = null;
$error = null;

function safe_int($v){ return (int)($v ?? 0); }

// Handle promote/demote actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $targetId = safe_int($_POST['admin_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($targetId <= 0) {
        $error = 'Invalid admin selected.';
    } else {
        if ($action === 'promote') {
            $stmt = $conn->prepare("UPDATE users SET admin_role = 'super_admin' WHERE id = ? AND role = 'admin'");
            $stmt->bind_param('i', $targetId);
            if ($stmt->execute()) {
                $success = 'Admin promoted to Super Admin.';
                // Log
                try {
                    $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'promote_admin', ?)");
                    $details = "Promoted admin ID: $targetId";
                    $log->bind_param('is', $_SESSION['user_id'], $details);
                    $log->execute();
                    $log->close();
                } catch (Throwable $e) {}
            } else {
                $error = 'Failed to promote admin.';
            }
            $stmt->close();
        } elseif ($action === 'demote') {
            // Prevent self-demote to avoid accidental lockout
            if ($targetId === (int)($_SESSION['user_id'] ?? 0)) {
                $error = 'You cannot demote your own account.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET admin_role = NULL WHERE id = ? AND role = 'admin'");
                $stmt->bind_param('i', $targetId);
                if ($stmt->execute()) {
                    $success = 'Super Admin demoted to Admin.';
                    try {
                        $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'demote_admin', ?)");
                        $details = "Demoted admin ID: $targetId";
                        $log->bind_param('is', $_SESSION['user_id'], $details);
                        $log->execute();
                        $log->close();
                    } catch (Throwable $e) {}
                } else {
                    $error = 'Failed to demote admin.';
                }
                $stmt->close();
            }
        } else {
            $error = 'Unknown action.';
        }
    }
}

// List admins
$sql = "SELECT id, username, full_name, email, role, admin_role, is_active, created_at FROM users WHERE role = 'admin' ORDER BY (admin_role = 'super_admin') DESC, created_at DESC";
$result = $conn->query($sql);

include '../includes/header.php';
?>
<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title d-flex align-items-center justify-content-between">
        <span><i class="fas fa-user-shield me-2"></i>Role Manager</span>
        <a class="btn btn-outline-primary" href="admin_management.php"><i class="fas fa-users-cog me-2"></i>Admin Management</a>
    </h2>

    <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card flat-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Level</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo (int)$row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                                    <td>
                                        <?php if ((int)$row['is_active'] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $isSuper = strtolower((string)($row['admin_role'] ?? '')) === 'super_admin'; ?>
                                        <?php if ($isSuper): ?>
                                            <span class="badge bg-warning text-dark">Super Admin</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isSuper): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Demote this Super Admin to Admin?')">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_id" value="<?php echo (int)$row['id']; ?>" />
                                                <input type="hidden" name="action" value="demote" />
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fas fa-arrow-down me-1"></i>Demote</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Promote this Admin to Super Admin?')">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_id" value="<?php echo (int)$row['id']; ?>" />
                                                <input type="hidden" name="action" value="promote" />
                                                <button class="btn btn-sm btn-outline-success" type="submit"><i class="fas fa-arrow-up me-1"></i>Promote</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center">No admins found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
