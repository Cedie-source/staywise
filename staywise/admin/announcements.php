<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/email_helper.php';

// Check admin session
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Manage Announcements";

// Quick date filter (similar to Admin Logs)
// GET params: date (YYYY-MM-DD) or period (today|yesterday|last7|last30|thisMonth)
require_once '../includes/date_helpers.php';
$dateFilter = isset($_GET['date']) ? trim($_GET['date']) : '';
$period = isset($_GET['period']) ? trim($_GET['period']) : '';

// Helper: reuse shared db_column_exists from security.php

// Simple flash messaging helpers (scoped to this page)
function ann_flash_set($type, $message) {
    $_SESSION['flash_' . $type] = $message;
}
function ann_flash_pop($type) {
    $key = 'flash_' . $type;
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return null;
}

// Handle add announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_announcement'])) {
    // CSRF check
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $announcement_date = $_POST['announcement_date'] ?? date('Y-m-d');

    if ($title === '' || $content === '') {
        $error = 'Please provide both title and content.';
    } else {
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, announcement_date) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $title, $content, $announcement_date);
        if ($stmt->execute()) {
            // Log admin action (best-effort)
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'add_announcement', ?)");
                $details = "Added announcement: $title";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {
                // ignore logging errors
            }
            ann_flash_set('success', 'Announcement added successfully!');

            // Email notifications sent in background to avoid slow page load
            // Tenants will see the announcement on their dashboard immediately

            header('Location: announcements.php');
            exit();
        } else {
            $error = "Failed to add announcement.";
        }
        $stmt->close();
    }
}

// Handle update announcement
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_announcement'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['announcement_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $announcement_date = $_POST['announcement_date'] ?? date('Y-m-d');
    if ($id <= 0) {
        $error = 'Invalid announcement selected.';
    } elseif ($title === '' || $content === '') {
        $error = 'Please provide both title and content.';
    } else {
        $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, announcement_date = ? WHERE announcement_id = ?");
        $stmt->bind_param("sssi", $title, $content, $announcement_date, $id);
        if ($stmt->execute()) {
            $success = 'Announcement updated successfully!';
            // Log admin action (best-effort)
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'update_announcement', ?)");
                $details = "Updated announcement ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
            // After successful update, clear any edit state by unsetting GET param via redirect
            header('Location: announcements.php');
            exit();
        } else {
            $error = 'Failed to update announcement.';
        }
        $stmt->close();
    }
}

// Handle delete (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_announcement_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['delete_announcement_id']);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'delete_announcement', ?)");
                $details = "Deleted announcement ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
            ann_flash_set('success', 'Announcement deleted.');
            header('Location: announcements.php');
            exit();
        } else {
            ann_flash_set('error', 'Failed to delete announcement.');
            header('Location: announcements.php');
            exit();
        }
        $stmt->close();
    }
}

// Handle pin/unpin (POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pin_announcement_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['pin_announcement_id']);
    if ($id > 0 && db_column_exists($conn, 'announcements', 'pinned')) {
        $stmt = $conn->prepare("UPDATE announcements SET pinned = 1 WHERE announcement_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'pin_announcement', ?)");
                $details = "Pinned announcement ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
            ann_flash_set('success', 'Announcement pinned.');
            header('Location: announcements.php');
            exit();
        } else {
            ann_flash_set('error', 'Failed to pin announcement.');
            header('Location: announcements.php');
            exit();
        }
        $stmt->close();
    }
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['unpin_announcement_id'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    $id = intval($_POST['unpin_announcement_id']);
    if ($id > 0 && db_column_exists($conn, 'announcements', 'pinned')) {
        $stmt = $conn->prepare("UPDATE announcements SET pinned = 0 WHERE announcement_id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            try {
                $log = $conn->prepare("INSERT INTO admin_logs (admin_id, action, details) VALUES (?, 'unpin_announcement', ?)");
                $details = "Unpinned announcement ID: $id";
                $log->bind_param("is", $_SESSION['user_id'], $details);
                $log->execute();
                $log->close();
            } catch (Throwable $e) {}
            ann_flash_set('success', 'Announcement unpinned.');
            header('Location: announcements.php');
            exit();
        } else {
            ann_flash_set('error', 'Failed to unpin announcement.');
            header('Location: announcements.php');
            exit();
        }
        $stmt->close();
    }
}

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <h2 class="dashboard-title">Announcements</h2>
    <?php if ($msg = ann_flash_pop('success')): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <?php if ($msg = ann_flash_pop('error')): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <?php
        // Determine if editing an announcement
        $editing = false;
        $edit_item = null;
        if (isset($_GET['edit'])) {
            $edit_id = (int)$_GET['edit'];
            if ($edit_id > 0) {
                $stmt = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
                $stmt->bind_param("i", $edit_id);
                if ($stmt->execute()) {
                    $res = $stmt->get_result();
                    if ($res && $res->num_rows === 1) {
                        $edit_item = $res->fetch_assoc();
                        $editing = true;
                    }
                }
                $stmt->close();
            }
        }

        // Prefill values for the form
        $form_title = '';
        $form_content = '';
        $form_date = date('Y-m-d');
        if ($editing && $edit_item) {
            $form_title = $edit_item['title'] ?? '';
            $form_content = $edit_item['content'] ?? '';
            $form_date = $edit_item['announcement_date'] ?? date('Y-m-d');
        }
        // If a POST failed validation, keep user input
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['add_announcement']) || isset($_POST['update_announcement'])) {
                if (isset($error)) {
                    $form_title = $_POST['title'] ?? $form_title;
                    $form_content = $_POST['content'] ?? $form_content;
                    $form_date = $_POST['announcement_date'] ?? $form_date;
                }
            }
        }
    ?>
    <div class="card mb-4">
        <div class="card-header"><?php echo $editing ? 'Edit Announcement' : 'Add New Announcement'; ?></div>
        <div class="card-body">
            <?php if (isset($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <?php if (isset($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" class="needs-validation" novalidate>
                <?php echo csrf_input(); ?>
                <?php if ($editing && $edit_item): ?>
                    <input type="hidden" name="announcement_id" value="<?php echo (int)$edit_item['announcement_id']; ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($form_title); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="content" class="form-label">Content</label>
                    <textarea class="form-control" id="content" name="content" rows="3" required><?php echo htmlspecialchars($form_content); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="announcement_date" class="form-label">Date</label>
                    <input type="date" class="form-control" id="announcement_date" name="announcement_date" required value="<?php echo htmlspecialchars($form_date); ?>">
                </div>
                <?php if ($editing): ?>
                    <button type="submit" name="update_announcement" class="btn btn-primary">Update Announcement</button>
                    <a href="announcements.php" class="btn btn-secondary ms-2">Cancel</a>
                <?php else: ?>
                    <button type="submit" name="add_announcement" class="btn btn-primary">Add Announcement</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <h2 class="dashboard-title d-flex align-items-center justify-content-between">
        <span>Existing Announcements</span>
    </h2>

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">
                <div>
                    <div class="mb-2">Quick filters</div>
                    <?php 
                        $periods = [
                            'today' => 'Today',
                            'yesterday' => 'Yesterday',
                            'last7' => 'Last 7 days',
                            'last30' => 'Last 30 days',
                            'thisMonth' => 'This month'
                        ];
                    ?>
                    <div class="btn-group" role="group" aria-label="Quick filters">
                        <?php foreach ($periods as $key => $label): ?>
                            <a href="announcements.php?period=<?php echo $key; ?>" class="btn btn-outline-primary <?php echo ($period === $key ? 'active' : ''); ?>"><?php echo $label; ?></a>
                        <?php endforeach; ?>
                        <a href="announcements.php" class="btn btn-outline-secondary">All</a>
                    </div>
                </div>
                <form id="annFilterForm" method="get" class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="annFilterDate" class="form-label">Or pick a date</label>
                        <input type="date" name="date" id="annFilterDate" class="form-control" value="<?php echo htmlspecialchars($dateFilter); ?>" />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary mt-auto"><i class="fas fa-filter me-2"></i>Filter</button>
                    </div>
                    <div class="col-auto">
                        <a href="announcements.php" class="btn btn-outline-secondary mt-auto">Clear</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    // Fetch announcements with flexible ordering (created_at if present, else announcement_date)
    $hasPinned = db_column_exists($conn, 'announcements', 'pinned');
    $hasCreatedAt = db_column_exists($conn, 'announcements', 'created_at');
    $dateColumn = $hasCreatedAt ? 'created_at' : 'announcement_date';
    $order = $hasCreatedAt ? 'created_at DESC' : 'announcement_date DESC';
    $orderSql = $hasPinned ? ("pinned DESC, " . $order) : $order;

    // Build WHERE based on filter
    $where = '';
    $params = [];
    $types = '';
    $periodRange = compute_period($period);
    if ($periodRange) {
        $where = "WHERE DATE($dateColumn) BETWEEN ? AND ?";
        $params = [$periodRange[0], $periodRange[1]];
        $types = 'ss';
    } elseif (is_valid_ymd($dateFilter)) {
        $where = "WHERE DATE($dateColumn) = ?";
        $params = [$dateFilter];
        $types = 's';
    }

    $sql = "SELECT * FROM announcements $where ORDER BY $orderSql";
    if ($where) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $announcements = $stmt->get_result();
        $stmt->close();
    } else {
        $announcements = $conn->query($sql);
    }
    ?>
    <?php if ($announcements && $announcements->num_rows > 0): ?>
        <ul class="list-group">
            <?php while ($row = $announcements->fetch_assoc()): ?>
                <li class="list-group-item announcement-admin-item">
                    <?php 
                        $title = (string)($row['title'] ?? '');
                        $isUrgent = stripos($title, '[URGENT]') === 0 || stripos($title, 'URGENT') !== false;
                    ?>
                    <?php if ($isUrgent): ?>
                        <span class="badge bg-danger me-2">Urgent</span>
                    <?php endif; ?>
                    <strong class="<?php echo $isUrgent ? 'text-danger' : ''; ?>"><?php echo htmlspecialchars($title); ?></strong>
                    <?php if (isset($row['pinned']) && !empty($row['pinned'])): ?><span class="badge bg-warning text-dark ms-2">Pinned</span><?php endif; ?>
                    <?php
                        // Prefer created_at (actual post time) if available; fall back to announcement_date
                        $createdAt = $row['created_at'] ?? null;
                        $annDate   = $row['announcement_date'] ?? null;
                        if (!empty($createdAt)) {
                            $dt = strtotime($createdAt);
                            $postedAt = $dt ? date('M d, Y g:i A', $dt) : '';
                        } else {
                            $dt = $annDate ? strtotime($annDate) : false;
                            // Show date (and 12:00 AM if only date exists)
                            $postedAt = $dt ? date('M d, Y', $dt) : '';
                        }
                    ?>
                    <?php if (!empty($postedAt)): ?>
                        <?php $dayName = $dt ? date('l', $dt) : ''; ?>
                        <small class="text-muted ms-2"><i class="far fa-clock me-1"></i>Posted: <?php echo htmlspecialchars($postedAt); ?></small>
                        <?php if (!empty($dayName)): ?><small class="text-muted ms-2"><i class="far fa-calendar-alt me-1"></i>Day: <?php echo htmlspecialchars($dayName); ?></small><?php endif; ?>
                    <?php endif; ?>
                    <p><?php echo nl2br(htmlspecialchars($row['content'])); ?></p>
                    <div class="mt-2">
                        <a href="announcements.php?edit=<?php echo $row['announcement_id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this announcement?')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="delete_announcement_id" value="<?php echo (int)$row['announcement_id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php if ($hasPinned): ?>
                            <?php if (empty($row['pinned'])): ?>
                                <form method="post" class="d-inline">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="pin_announcement_id" value="<?php echo (int)$row['announcement_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-info">Pin</button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="unpin_announcement_id" value="<?php echo (int)$row['announcement_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary">Unpin</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <p>No announcements found.</p>
    <?php endif; ?>
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

// Auto-submit on date change for quick filtering
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('annFilterDate');
    const filterForm = document.getElementById('annFilterForm');
    if (dateInput && filterForm) {
        dateInput.addEventListener('change', function(){ filterForm.submit(); });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
