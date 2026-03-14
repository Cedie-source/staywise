<?php
require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';
require_once '../includes/logger.php';
// Ensure required columns exist on users table for forced password change
if (function_exists('db_ensure_user_force_change_columns')) { db_ensure_user_force_change_columns($conn); }

// Enable strict error reporting (errors logged, not displayed to users)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Add debug output for database connection
if (!$conn) {
    die('Database connection failed: ' . mysqli_connect_error());
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}


if (!function_exists('validateInput')) {
    function validateInput($data, $type) {
        switch ($type) {
            case 'username':
                return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data);
            case 'email':
                return filter_var($data, FILTER_VALIDATE_EMAIL);
            case 'contact':
                // Accept either +63XXXXXXXXXX or 09XXXXXXXXX
                return preg_match('/^(\+63\d{10}|09\d{9})$/', $data);
            case 'rent':
                return is_numeric($data) && $data > 0;
            default:
                return !empty(trim($data));
        }
    }
}

// Utility: reuse shared db_column_exists from security.php

// Handle tenant operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        die('Invalid request token.');
    }
    if (isset($_POST['add_tenant'])) {
        // Add new tenant
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $contact = trim($_POST['contact']);
        $unit_number = trim($_POST['unit_number']);
        $rent_amount = floatval($_POST['rent_amount']);
        $lease_start_date = trim($_POST['lease_start_date'] ?? '');
        $lease_end_date = trim($_POST['lease_end_date'] ?? '');
        $deposit_paid = isset($_POST['deposit_paid']) ? 1 : 0;
        $advance_paid = isset($_POST['advance_paid']) ? 1 : 0;
    $errors = [];
    $fieldErrors = [];
        $prefill = [
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'contact' => $contact,
            'unit_number' => $unit_number,
            'rent_amount' => $_POST['rent_amount'],
            'lease_start_date' => $lease_start_date,
            'lease_end_date' => $lease_end_date,
            'deposit_paid' => $deposit_paid,
            'advance_paid' => $advance_paid
        ];

        // Deposit (3 months) and advance (1 month) are required
        $deposit_amount = $rent_amount * 3;
        $advance_amount = $rent_amount * 1;
        if (!$deposit_paid) {
            $fieldErrors['deposit_paid'] = '3-month deposit (₱' . number_format($deposit_amount, 2) . ') confirmation is required.';
            $errors[] = $fieldErrors['deposit_paid'];
        }
        if (!$advance_paid) {
            $fieldErrors['advance_paid'] = '1-month advance payment (₱' . number_format($advance_amount, 2) . ') confirmation is required.';
            $errors[] = $fieldErrors['advance_paid'];
        }
        // Lease start date is required
        if (empty($lease_start_date)) {
            $fieldErrors['lease_start_date'] = 'Lease start date is required.';
            $errors[] = $fieldErrors['lease_start_date'];
        }
        
        // Refactor and improve the Add Tenant functionality

        // Password rules: require uppercase, number, special character
        $pwErrs = [];
        if (!preg_match('/[A-Z]/', $password)) $pwErrs[] = 'one uppercase letter';
        if (!preg_match('/[0-9]/', $password)) $pwErrs[] = 'one number';
        if (!preg_match('/[!@#$%^&*()_+\-=[\]{};:\"\\|,.<>\/?]/', $password)) $pwErrs[] = 'one special character';
        if (!empty($pwErrs)) {
            $fieldErrors['password'] = 'Password must contain at least ' . implode(', ', $pwErrs) . '.';
            $errors[] = $fieldErrors['password'];
        }

        // Validate inputs (collect friendly, page-level messages)
        if (!validateInput($username, 'username')) {
            $fieldErrors['username'] = 'Username must be 3-20 characters and contain only letters, numbers, and underscores.';
            $errors[] = $fieldErrors['username'];
        }
        if (!validateInput($email, 'email')) {
            $fieldErrors['email'] = 'Please enter a valid email address.';
            $errors[] = $fieldErrors['email'];
        }
        // Contact: allow empty, or accept +63XXXXXXXXXX or 09XXXXXXXXX; normalize to +63XXXXXXXXXX
        if ($contact !== '') {
            if (preg_match('/^\\+63\\d{10}$/', $contact)) {
                // already in +63 format
            } elseif (preg_match('/^09\\d{9}$/', $contact)) {
                $contact = '+63' . substr($contact, 1); // drop leading 0
            } else {
                // Compute friendly message lengths
                if (preg_match('/^\+?63(\d*)$/', preg_replace('/^\+/', '', $contact), $m)) {
                    $digits = strlen($m[1]);
                    if ($digits < 10) {
                        $fieldErrors['contact'] = 'Contact number is too short. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    } elseif ($digits > 10) {
                        $fieldErrors['contact'] = 'Contact number is too long. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    } else {
                        $fieldErrors['contact'] = 'Invalid contact number format. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    }
                } else {
                    $fieldErrors['contact'] = 'Invalid contact number format. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                }
                $errors[] = $fieldErrors['contact'];
            }
        } else {
            // store NULL if empty
            $contact = null;
        }
        if (!validateInput($rent_amount, 'rent')) {
            $fieldErrors['rent_amount'] = 'Monthly rent must be a positive number.';
            $errors[] = $fieldErrors['rent_amount'];
        }

        // Check if the username already exists
        if (empty($errors)) {
            $check_username_stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check_username_stmt->bind_param("s", $username);
            $check_username_stmt->execute();
            $check_username_stmt->store_result();
            if ($check_username_stmt->num_rows > 0) {
                $fieldErrors['username'] = 'The username is already taken. Please choose a different username.';
                $errors[] = $fieldErrors['username'];
            }
            $check_username_stmt->close();
        }

        // Additional server-side required checks for name and unit number
        if (!validateInput($name, 'text')) {
            $fieldErrors['name'] = 'Full name is required.';
            $errors[] = $fieldErrors['name'];
        }
        if (!validateInput($unit_number, 'text')) {
            $fieldErrors['unit_number'] = 'Unit number is required.';
            $errors[] = $fieldErrors['unit_number'];
        }

        // Duplicate email check across users and tenants (if provided)
        if (empty($errors) && !empty($email)) {
            $check_email_users = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check_email_users->bind_param("s", $email);
            $check_email_users->execute();
            $check_email_users->store_result();
            if ($check_email_users->num_rows > 0) {
                $fieldErrors['email'] = 'Email is already in use.';
                $errors[] = $fieldErrors['email'];
            }
            $check_email_users->close();

            if (empty($fieldErrors['email'])) {
                $check_email_tenants = $conn->prepare("SELECT tenant_id FROM tenants WHERE email = ?");
                $check_email_tenants->bind_param("s", $email);
                $check_email_tenants->execute();
                $check_email_tenants->store_result();
                if ($check_email_tenants->num_rows > 0) {
                    $fieldErrors['email'] = 'Email is already associated with another tenant.';
                    $errors[] = $fieldErrors['email'];
                }
                $check_email_tenants->close();
            }
        }

        // Duplicate unit number check
        if (empty($errors) && !empty($unit_number)) {
            $check_unit = $conn->prepare("SELECT tenant_id FROM tenants WHERE unit_number = ?");
            $check_unit->bind_param("s", $unit_number);
            $check_unit->execute();
            $check_unit->store_result();
            if ($check_unit->num_rows > 0) {
                $fieldErrors['unit_number'] = 'Unit number is already assigned to another tenant.';
                $errors[] = $fieldErrors['unit_number'];
            }
            $check_unit->close();
        }

        if (empty($errors)) {
            try {
            // Wrap in a transaction to keep users and tenants in sync
            $conn->begin_transaction();

            // Create user account
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if (db_column_exists($conn, 'users', 'force_password_change')) {
                $user_stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, force_password_change) VALUES (?, ?, ?, ?, 'tenant', 1)");
            } else {
                $user_stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role) VALUES (?, ?, ?, ?, 'tenant')");
            }
            $user_stmt->bind_param("ssss", $username, $name, $email, $hashed_password);
            $user_stmt->execute();
            $user_id = $conn->insert_id;
            $user_stmt->close();

            // Fallback: if insert path couldn't set flag (e.g., column just created), set it now
            if (db_column_exists($conn, 'users', 'force_password_change')) {
                try {
                    $updFlag = $conn->prepare("UPDATE users SET force_password_change = 1 WHERE id = ?");
                    $updFlag->bind_param("i", $user_id);
                    $updFlag->execute();
                    $updFlag->close();
                } catch (Throwable $e) { /* ignore */ }
            }

            // Create tenant profile with deposit/advance and lease start date
            $lease_end_val = !empty($lease_end_date) ? $lease_end_date : null;
            $tenant_stmt = $conn->prepare("INSERT INTO tenants (user_id, name, email, contact, unit_number, rent_amount, deposit_amount, advance_amount, deposit_paid, advance_paid, lease_start_date, lease_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $tenant_stmt->bind_param("issssdddiiss", $user_id, $name, $email, $contact, $unit_number, $rent_amount, $deposit_amount, $advance_amount, $deposit_paid, $advance_paid, $lease_start_date, $lease_end_val);
            $tenant_stmt->execute();
            $new_tenant_id = $conn->insert_id;
            $tenant_stmt->close();

            // Auto-record deposit payment
            if ($deposit_paid && $deposit_amount > 0) {
                $dep_stmt = $conn->prepare("INSERT INTO payments (tenant_id, amount, payment_date, for_month, status, payment_type) VALUES (?, ?, ?, ?, 'verified', 'deposit')");
                $dep_for = date('Y-m', strtotime($lease_start_date));
                $dep_stmt->bind_param("idss", $new_tenant_id, $deposit_amount, $lease_start_date, $dep_for);
                $dep_stmt->execute();
                $dep_stmt->close();
            }

            // Auto-record advance payment (covers first month rent)
            if ($advance_paid && $advance_amount > 0) {
                $adv_stmt = $conn->prepare("INSERT INTO payments (tenant_id, amount, payment_date, for_month, status, payment_type) VALUES (?, ?, ?, ?, 'verified', 'advance')");
                $adv_for = date('Y-m', strtotime($lease_start_date));
                $adv_stmt->bind_param("idss", $new_tenant_id, $advance_amount, $lease_start_date, $adv_for);
                $adv_stmt->execute();
                $adv_stmt->close();
            }

            // Set due_day based on lease start date if column exists
            if (!empty($lease_start_date) && db_column_exists($conn, 'tenants', 'due_day')) {
                $startDay = (int)date('d', strtotime($lease_start_date));
                $dueDayVal = min($startDay, 28);
                $updDue = $conn->prepare("UPDATE tenants SET due_day = ? WHERE tenant_id = ?");
                $updDue->bind_param("ii", $dueDayVal, $new_tenant_id);
                $updDue->execute();
                $updDue->close();
            }

            // Log admin action
            $details = "Added tenant: $name, Unit: $unit_number, Deposit: ₱" . number_format($deposit_amount, 2) . ", Advance: ₱" . number_format($advance_amount, 2) . ", Lease start: $lease_start_date";
            logAdminAction($conn, $_SESSION['user_id'], 'add_tenant', $details);

            $conn->commit();
            $_SESSION['tenant_success'] = "Tenant added successfully! Deposit (₱" . number_format($deposit_amount, 2) . ") and advance (₱" . number_format($advance_amount, 2) . ") recorded.";
            header("Location: tenants.php");
            exit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log("Error: " . $e->getMessage());
            $error = "An error occurred while adding the tenant. Please try again later.";
        }
        } else {
            // Show errors on the same page and reopen the modal with highlighted fields
            $errorTitle = 'Please fix the highlighted fields';
            $errorList = $errors;
            $showAddModal = true;
            $addPrefill = $prefill;
            $addFieldErrors = $fieldErrors;
        }
    }
    
    // Force password change for a tenant's linked user account
    if (isset($_POST['require_pw_change_user_id'])) {
        $uid = intval($_POST['require_pw_change_user_id']);
        if ($uid > 0) {
            if (function_exists('db_column_exists') && !db_column_exists($conn, 'users', 'force_password_change')) {
                $error = 'Cannot require password change (missing users.force_password_change column).';
            } else {
                try {
                    $stmt = $conn->prepare("UPDATE users SET force_password_change = 1 WHERE id = ?");
                    $stmt->bind_param("i", $uid);
                    if ($stmt->execute()) {
                        $success = 'Password change required for selected tenant on next login.';
                        logAdminAction($conn, $_SESSION['user_id'], 'force_pw_change_tenant', 'Forced password change for user ID: ' . $uid);
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

    // Reset tenant password: generate a temporary password and force change on next login
    if (isset($_POST['reset_tenant_password'])) {
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        if ($tenant_id <= 0) {
            $error = 'Invalid tenant ID.';
        } else {
            try {
                // Fetch linked user (use bind_result/fetch to avoid mysqlnd dependency)
                $stmt = $conn->prepare('SELECT t.tenant_id, t.name, t.user_id, u.username FROM tenants t JOIN users u ON t.user_id = u.id WHERE t.tenant_id = ? LIMIT 1');
                $stmt->bind_param('i', $tenant_id);
                $stmt->execute();
                $tenant_id_out = $name_out = $user_id_out = $username_out = null;
                $stmt->bind_result($tenant_id_out, $name_out, $user_id_out, $username_out);
                $found = $stmt->fetch();
                $stmt->close();
                if (!$found) {
                    $error = 'Tenant not found.';
                } else {
                    $user_id = (int)$user_id_out;
                    // Set a fixed temporary password per request
                    $tmp = '123';
                    $hash = password_hash($tmp, PASSWORD_DEFAULT);

                    $conn->begin_transaction();
                    // Update users password and force change
                    $upd = $conn->prepare("UPDATE users SET password = ?, force_password_change = 1 WHERE id = ? AND role = 'tenant'");
                    $upd->bind_param('si', $hash, $user_id);
                    $upd->execute();
                    $affected = $upd->affected_rows;
                    $upd->close();

                    // Also set tenants.must_change_password when present
                    if (function_exists('db_column_exists') && db_column_exists($conn, 'tenants', 'must_change_password')) {
                        try { $st = $conn->prepare('UPDATE tenants SET must_change_password = 1 WHERE tenant_id = ?'); $st->bind_param('i', $tenant_id); $st->execute(); $st->close(); } catch (Throwable $e) { /* ignore */ }
                    }

                    if ($affected > 0) {
                        $conn->commit();
                        $success = 'Temporary password for ' . htmlspecialchars((string)$username_out) . ': <code>' . htmlspecialchars($tmp) . '</code>. They will be required to change it after login.';
                        logAdminAction($conn, $_SESSION['user_id'], 'reset_tenant_password', 'Reset tenant password for tenant ID: ' . $tenant_id . ' (user ID: ' . $user_id . ')');
                    } else {
                        $conn->rollback();
                        $error = 'Failed to reset password. Ensure the account is a tenant user.';
                    }
                }
            } catch (Throwable $e) {
                if (method_exists($conn, 'rollback')) { $conn->rollback(); }
                $error = 'Failed to reset password.';
            }
        }
    }
    
    // Approve/Reject tenant (only if tenants.status column exists)
    if (isset($_POST['approve_tenant']) || isset($_POST['reject_tenant'])) {
        $tenant_id = intval($_POST['tenant_id'] ?? 0);
        if ($tenant_id <= 0) {
            $error = 'Invalid tenant ID.';
        } elseif (!db_column_exists($conn, 'tenants', 'status')) {
            $error = 'Approval workflow is not available (missing status column).';
        } else {
            $newStatus = isset($_POST['approve_tenant']) ? 'approved' : 'rejected';
            $stmt = $conn->prepare("UPDATE tenants SET status = ? WHERE tenant_id = ?");
            $stmt->bind_param("si", $newStatus, $tenant_id);
            if ($stmt->execute()) {
                $success = $newStatus === 'approved' ? 'Tenant approved.' : 'Tenant rejected.';
                $details = ucfirst($newStatus) . " tenant ID: $tenant_id";
                logAdminAction($conn, $_SESSION['user_id'], $newStatus === 'approved' ? 'approve_tenant' : 'reject_tenant', $details);
            } else {
                $error = 'Failed to update tenant status.';
            }
            $stmt->close();
        }
    }
    
    if (isset($_POST['update_tenant'])) {
        // Update tenant with validation and duplicate checks
        $tenant_id = intval($_POST['tenant_id']);
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $contact = trim($_POST['contact']);
        $unit_number = trim($_POST['unit_number']);
        $rent_amount = floatval($_POST['rent_amount']);

        $editErrors = [];
        $editFieldErrors = [];
        $editPrefill = [
            'tenant_id' => $tenant_id,
            'name' => $name,
            'email' => $email,
            'contact' => $contact,
            'unit_number' => $unit_number,
            'rent_amount' => $_POST['rent_amount'],
        ];

        // Basic validations
        if (!validateInput($name, 'text')) {
            $editFieldErrors['name'] = 'Full name is required.';
            $editErrors[] = $editFieldErrors['name'];
        }
        if (!validateInput($email, 'email')) {
            $editFieldErrors['email'] = 'Please enter a valid email address.';
            $editErrors[] = $editFieldErrors['email'];
        }
        if ($contact !== '') {
            if (preg_match('/^\+63\d{10}$/', $contact)) {
                // ok
            } elseif (preg_match('/^09\d{9}$/', $contact)) {
                $contact = '+63' . substr($contact, 1);
            } else {
                if (preg_match('/^\+?63(\d*)$/', preg_replace('/^\+/', '', $contact), $m)) {
                    $digits = strlen($m[1]);
                    if ($digits < 10) {
                        $editFieldErrors['contact'] = 'Contact number is too short. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    } elseif ($digits > 10) {
                        $editFieldErrors['contact'] = 'Contact number is too long. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    } else {
                        $editFieldErrors['contact'] = 'Invalid contact number format. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                    }
                } else {
                    $editFieldErrors['contact'] = 'Invalid contact number format. Use +63XXXXXXXXXX or 09XXXXXXXXX.';
                }
                $editErrors[] = $editFieldErrors['contact'];
            }
        } else {
            $contact = null;
        }
        if (!validateInput($unit_number, 'text')) {
            $editFieldErrors['unit_number'] = 'Unit number is required.';
            $editErrors[] = $editFieldErrors['unit_number'];
        }
        if (!validateInput($rent_amount, 'rent')) {
            $editFieldErrors['rent_amount'] = 'Monthly rent must be a positive number.';
            $editErrors[] = $editFieldErrors['rent_amount'];
        }

        // Check duplicates for email and unit_number (exclude current tenant)
        if (empty($editErrors)) {
            $dupeEmailTenants = $conn->prepare("SELECT tenant_id FROM tenants WHERE email = ? AND tenant_id <> ?");
            $dupeEmailTenants->bind_param("si", $email, $tenant_id);
            $dupeEmailTenants->execute();
            $dupeEmailTenants->store_result();
            if ($dupeEmailTenants->num_rows > 0) {
                $editFieldErrors['email'] = 'Email is already associated with another tenant.';
                $editErrors[] = $editFieldErrors['email'];
            }
            $dupeEmailTenants->close();

            $dupeUnit = $conn->prepare("SELECT tenant_id FROM tenants WHERE unit_number = ? AND tenant_id <> ?");
            $dupeUnit->bind_param("si", $unit_number, $tenant_id);
            $dupeUnit->execute();
            $dupeUnit->store_result();
            if ($dupeUnit->num_rows > 0) {
                $editFieldErrors['unit_number'] = 'Unit number is already assigned to another tenant.';
                $editErrors[] = $editFieldErrors['unit_number'];
            }
            $dupeUnit->close();
        }

        if (empty($editErrors)) {
            // Update tenant and the linked user's profile (full_name, email)
            try {
                $conn->begin_transaction();

                $edit_lease_start = trim($_POST['lease_start_date'] ?? '');
                $edit_lease_end = trim($_POST['lease_end_date'] ?? '');
                $edit_lease_end_val = !empty($edit_lease_end) ? $edit_lease_end : null;
                $edit_lease_start_val = !empty($edit_lease_start) ? $edit_lease_start : null;
                $stmt = $conn->prepare("UPDATE tenants SET name = ?, email = ?, contact = ?, unit_number = ?, rent_amount = ?, lease_start_date = ?, lease_end_date = ? WHERE tenant_id = ?");
                $stmt->bind_param("ssssdssi", $name, $email, $contact, $unit_number, $rent_amount, $edit_lease_start_val, $edit_lease_end_val, $tenant_id);
                $stmt->execute();
                $stmt->close();

                // Sync to users table
                $uidStmt = $conn->prepare("SELECT user_id FROM tenants WHERE tenant_id = ?");
                $uidStmt->bind_param("i", $tenant_id);
                $uidStmt->execute();
                $uidRes = $uidStmt->get_result();
                $row = $uidRes->fetch_assoc();
                $uidStmt->close();
                if ($row && !empty($row['user_id'])) {
                    $userUpdate = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                    $userUpdate->bind_param("ssi", $name, $email, $row['user_id']);
                    $userUpdate->execute();
                    $userUpdate->close();
                }

                // Log admin action
                $details = "Updated tenant: $name, Unit: $unit_number";
                logAdminAction($conn, $_SESSION['user_id'], 'update_tenant', $details);

                $conn->commit();
                $_SESSION['tenant_success'] = "Tenant updated successfully!";
                header("Location: tenants.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $error = "Failed to update tenant: " . $e->getMessage();
            }
        } else {
            // Display errors and show Edit modal with highlights
            $editErrorTitle = 'Please fix the highlighted fields';
            $editErrorList = $editErrors;
            $showEditModal = true;
            $editFieldErrors = $editFieldErrors;
            $editPrefill = $editPrefill;
        }
    }
    
    if (isset($_POST['delete_tenant'])) {
        // Soft-delete tenant (set deleted_at timestamp instead of removing rows)
        $tenant_id = intval($_POST['tenant_id']);

        // Get user_id first
        $user_stmt = $conn->prepare("SELECT user_id FROM tenants WHERE tenant_id = ?");
        $user_stmt->bind_param("i", $tenant_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_stmt->close();

        if ($user_data) {
            $conn->begin_transaction();
            try {
                // Soft-delete tenant
                $softDel = $conn->prepare("UPDATE tenants SET deleted_at = NOW() WHERE tenant_id = ?");
                $softDel->bind_param("i", $tenant_id);
                $softDel->execute();
                $softDel->close();

                // Soft-delete linked user (if deleted_at column exists) or deactivate
                $uid = (int)$user_data['user_id'];
                if (db_column_exists($conn, 'users', 'deleted_at')) {
                    $softDelUser = $conn->prepare("UPDATE users SET deleted_at = NOW() WHERE id = ?");
                    $softDelUser->bind_param("i", $uid);
                    $softDelUser->execute();
                    $softDelUser->close();
                }

                logAdminAction($conn, $_SESSION['user_id'], 'delete_tenant', "Soft-deleted tenant ID: $tenant_id and user ID: $uid");
                $conn->commit();
                $_SESSION['tenant_success'] = "Tenant removed successfully.";
                header("Location: tenants.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $error = "Failed to delete tenant: " . $e->getMessage();
            }
        } else {
            $error = "Tenant not found.";
        }
    }
}

// Get all tenants (exclude soft-deleted; order by name; status column may not exist)
$tenants = $conn->query("SELECT t.*, u.username, u.profile_photo, u.force_password_change AS u_force_password_change FROM tenants t JOIN users u ON t.user_id = u.id WHERE t.deleted_at IS NULL ORDER BY t.name");

$page_title = "Manage Tenants";

// Flash message from PRG redirect
if (!empty($_SESSION['tenant_success'])) {
    $success = $_SESSION['tenant_success'];
    unset($_SESSION['tenant_success']);
}

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui manage-tenants">
    <?php if (function_exists('db_column_exists') && !db_column_exists($conn, 'users', 'force_password_change')): ?>
        <div class="alert alert-warning d-flex align-items-start" role="alert">
            <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
            <div>
                <strong>Password change enforcement not fully enabled.</strong>
                <div class="small mt-1">
                    To require new accounts to change their password on first login, add the following columns to the <code>users</code> table:
                </div>
                <pre class="mb-1 mt-2" style="white-space:pre-wrap;">
ALTER TABLE `users` ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `users` ADD COLUMN `password_changed_at` DATETIME NULL;
                </pre>
                <div class="small">After adding, newly created tenants will be forced to change their password.</div>
            </div>
        </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="dashboard-title"><i class="fas fa-users me-2"></i>Manage Tenants</h2>
        <div>
            <button class="btn btn-success me-2" onclick="exportTenantsCSV()">
                <i class="fas fa-file-csv me-2"></i>Export CSV
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTenantModal">
                <i class="fas fa-user-plus me-2"></i>Add New Tenant
            </button>
        </div>
    </div>

    <!-- Search bar -->
    <div class="mb-3">
        <input type="text" class="form-control" id="tenantSearch" placeholder="Search tenants by name, unit, email, or username...">
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success <?php echo (strpos($success, '<code>') !== false) ? 'alert-permanent' : ''; ?>">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorList ?? []) || isset($errorTitle)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-circle me-2 mt-1"></i>
                <div>
                    <strong><?php echo htmlspecialchars($errorTitle ?? 'Please review the errors'); ?></strong>
                    <?php if (!empty($errorList ?? [])): ?>
                        <ul class="mb-0 mt-1">
                            <?php foreach ($errorList as $msg): ?>
                                <li><?php echo htmlspecialchars($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif (!empty($error)): ?>
                        <div class="mt-1"><?php echo nl2br(htmlspecialchars($error)); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if ($tenants && $tenants->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Unit</th>
                                <th>Email</th>
                                <th>Contact</th>
                                <th>Rent</th>
                                <th>Username</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($tenant = $tenants->fetch_assoc()): ?>
                                <tr>
                                    <?php 
                                        $hasPending = isset($tenant['status']) && $tenant['status'] === 'pending';
                                        $hasRejected = isset($tenant['status']) && $tenant['status'] === 'rejected';
                                        $mustChange = !empty($tenant['u_force_password_change']);
                                        $noBadgesClass = (!$hasPending && !$hasRejected && !$mustChange) ? ' no-badges' : '';
                                    ?>
                                    <td class="tenant-name-cell<?php echo $noBadgesClass; ?>">
                                        <div class="tenant-name-wrap" style="display:flex;align-items:center;gap:10px;">
                                          <?php
                                            $tp = $tenant['profile_photo'] ?? '';
                                            $tInitials = strtoupper(substr($tenant['name'], 0, 2));
                                          ?>
                                          <?php if (!empty($tp)): ?>
                                            <img src="/uploads/profiles/<?php echo htmlspecialchars($tp) ?>" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(78,214,193,.35);" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#4ED6C1,#007DFE);display:none;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0;"><?php echo $tInitials ?></div>
                                          <?php else: ?>
                                            <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#4ED6C1,#007DFE);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#fff;flex-shrink:0;"><?php echo $tInitials ?></div>
                                          <?php endif; ?>
                                          <div>
                                            <strong class="tenant-name-text"><?php echo htmlspecialchars($tenant['name']); ?></strong>
                                            <?php if ($hasPending || $hasRejected || $mustChange): ?>
                                            <div class="tenant-name-badges">
                                                <?php if (isset($tenant['status']) && $tenant['status'] === 'pending'): ?><span class="badge bg-warning text-dark">Pending</span><?php endif; ?>
                                                <?php if (isset($tenant['status']) && $tenant['status'] === 'rejected'): ?><span class="badge bg-danger">Rejected</span><?php endif; ?>
                                                <?php if (!empty($tenant['u_force_password_change'])): ?><span class="badge bg-danger">Must change password</span><?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                          </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($tenant['unit_number']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($tenant['email']); ?></td>
                                    <td>
                                        <?php
                                            $contactRaw = trim($tenant['contact'] ?? '');
                                            if ($contactRaw === '') {
                                                echo '<span class="text-muted">—</span>';
                                            } else {
                                                $contact = preg_replace('/^\+?63/', '', $contactRaw);
                                                if (preg_match('/^\d{10}$/', $contact)) {
                                                    echo '+63' . htmlspecialchars($contact);
                                                } else {
                                                    echo htmlspecialchars($contactRaw);
                                                }
                                            }
                                        ?>
                                    </td>
                                    <td>₱<?php echo number_format($tenant['rent_amount'], 2); ?></td>
                                    <td>
                                        <code><?php echo htmlspecialchars($tenant['username']); ?></code>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info me-1" 
                                                onclick="viewTenantDetails(<?php echo htmlspecialchars(json_encode($tenant)); ?>)">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary me-1" 
                                                onclick="editTenant(<?php echo htmlspecialchars(json_encode($tenant)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="require_pw_change_user_id" value="<?php echo (int)$tenant['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning me-1" title="Require password change on next login">
                                                <i class="fa-solid fa-key"></i>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline reset-tenant-form">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="tenant_id" value="<?php echo (int)$tenant['tenant_id']; ?>">
                                            <input type="hidden" name="reset_tenant_password" value="1">
                                            <button type="button" class="btn btn-sm btn-outline-warning me-1 btn-reset-tenant" title="Reset tenant password" data-username="<?php echo htmlspecialchars($tenant['username'], ENT_QUOTES); ?>" data-bs-toggle="modal" data-bs-target="#confirmResetTenantModal">
                                                <i class="fa-solid fa-unlock-keyhole"></i>
                                            </button>
                                        </form>
                                        <?php if (isset($tenant['status']) && $tenant['status'] === 'pending'): ?>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="tenant_id" value="<?php echo $tenant['tenant_id']; ?>">
                                                <button type="submit" name="approve_tenant" class="btn btn-sm btn-success me-1">Approve</button>
                                                <button type="submit" name="reject_tenant" class="btn btn-sm btn-danger">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline delete-tenant-form">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="tenant_id" value="<?php echo $tenant['tenant_id']; ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-tenant" data-tenant-id="<?php echo (int)$tenant['tenant_id']; ?>" data-bs-toggle="modal" data-bs-target="#confirmDeleteTenantModal">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <input type="hidden" name="delete_tenant" value="1">
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5>No Tenants Found</h5>
                    <p class="text-muted">Add your first tenant to get started.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Tenant Modal -->
<!-- Confirm Reset Tenant Password Modal -->
<div class="modal fade" id="confirmResetTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-unlock-keyhole me-2"></i>Reset Tenant Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to reset the password for <strong id="crt_username"></strong>?</p>
                <p class="text-muted mb-0">A temporary password will be set: <code id="crt_temp">123</code>. They will be required to change it after login.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="crt_confirm_btn">Reset Password</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Delete Tenant Modal -->
<div class="modal fade" id="confirmDeleteTenantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete Tenant</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove this tenant? Their account will be deactivated and hidden from listings.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="dt_confirm_btn">Delete</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="addTenantModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Add New Tenant
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <?php echo csrf_input(); ?>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label <?php echo !empty($addFieldErrors['username']) ? 'text-danger fw-semibold' : ''; ?>">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?php echo !empty($addFieldErrors['username']) ? 'is-invalid' : ''; ?>" name="username" aria-invalid="<?php echo !empty($addFieldErrors['username']) ? 'true' : 'false'; ?>" value="<?php echo isset($addPrefill['username']) ? htmlspecialchars($addPrefill['username']) : ''; ?>" required>
                            <?php if (!empty($addFieldErrors['username'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['username']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password *</label>
                <div class="pw-toggle-wrap">
                <input type="password" class="form-control" name="password" id="add_tenant_password" required style="padding-right:2.5rem"
                    pattern="^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$"
                    title="Password must include at least one uppercase letter, one number, and one special character">
                <button type="button" class="pw-toggle-btn" tabindex="-1" aria-label="Toggle password visibility" onclick="this.previousElementSibling.type=this.previousElementSibling.type==='password'?'text':'password';this.querySelector('i').classList.toggle('fa-eye');this.querySelector('i').classList.toggle('fa-eye-slash');"><i class="fas fa-eye"></i></button>
                </div>
                            <div id="addTenantPwdHelp" class="form-text mt-1">Must include uppercase, number, and special character.</div>
                            <?php if (!empty($addFieldErrors['password'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['password']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label <?php echo !empty($addFieldErrors['name']) ? 'text-danger fw-semibold' : ''; ?>">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?php echo !empty($addFieldErrors['name']) ? 'is-invalid' : ''; ?>" name="name" aria-invalid="<?php echo !empty($addFieldErrors['name']) ? 'true' : 'false'; ?>" value="<?php echo isset($addPrefill['name']) ? htmlspecialchars($addPrefill['name']) : ''; ?>" required>
                            <?php if (!empty($addFieldErrors['name'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['name']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label <?php echo !empty($addFieldErrors['email']) ? 'text-danger fw-semibold' : ''; ?>">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control <?php echo !empty($addFieldErrors['email']) ? 'is-invalid' : ''; ?>" name="email" aria-invalid="<?php echo !empty($addFieldErrors['email']) ? 'true' : 'false'; ?>" value="<?php echo isset($addPrefill['email']) ? htmlspecialchars($addPrefill['email']) : ''; ?>" required>
                            <?php if (!empty($addFieldErrors['email'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['email']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="contact" class="form-label <?php echo !empty($addFieldErrors['contact']) ? 'text-danger fw-semibold' : ''; ?>">Contact</label>
                            <input type="tel" class="form-control <?php echo !empty($addFieldErrors['contact']) ? 'is-invalid' : ''; ?>" name="contact" aria-invalid="<?php echo !empty($addFieldErrors['contact']) ? 'true' : 'false'; ?>" placeholder="+63XXXXXXXXXX or 09XXXXXXXXX" pattern="^(\+63\d{10}|09\d{9})$" maxlength="13" title="Enter a valid Philippine mobile number (+63XXXXXXXXXX or 09XXXXXXXXX)" value="<?php echo isset($addPrefill['contact']) ? htmlspecialchars($addPrefill['contact']) : ''; ?>">
                            <?php if (!empty($addFieldErrors['contact'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['contact']); ?></div>
                            <?php endif; ?>
                            <small class="text-muted">Accepted: +63 followed by 10 digits, or 09 followed by 9 digits. We will save it in +63 format. Leave empty if not available.</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="unit_number" class="form-label <?php echo !empty($addFieldErrors['unit_number']) ? 'text-danger fw-semibold' : ''; ?>">Unit Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?php echo !empty($addFieldErrors['unit_number']) ? 'is-invalid' : ''; ?>" name="unit_number" aria-invalid="<?php echo !empty($addFieldErrors['unit_number']) ? 'true' : 'false'; ?>" value="<?php echo isset($addPrefill['unit_number']) ? htmlspecialchars($addPrefill['unit_number']) : ''; ?>" required>
                            <?php if (!empty($addFieldErrors['unit_number'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['unit_number']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="rent_amount" class="form-label <?php echo !empty($addFieldErrors['rent_amount']) ? 'text-danger fw-semibold' : ''; ?>">Monthly Rent <span class="text-danger">*</span></label>
                            <input type="number" class="form-control <?php echo !empty($addFieldErrors['rent_amount']) ? 'is-invalid' : ''; ?>" name="rent_amount" id="add_rent_amount" aria-invalid="<?php echo !empty($addFieldErrors['rent_amount']) ? 'true' : 'false'; ?>" step="0.01" min="0" value="<?php echo isset($addPrefill['rent_amount']) ? htmlspecialchars($addPrefill['rent_amount']) : '5000'; ?>" required>
                            <?php if (!empty($addFieldErrors['rent_amount'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['rent_amount']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Lease Start Date -->
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="lease_start_date" class="form-label <?php echo !empty($addFieldErrors['lease_start_date']) ? 'text-danger fw-semibold' : ''; ?>">Lease Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control <?php echo !empty($addFieldErrors['lease_start_date']) ? 'is-invalid' : ''; ?>" name="lease_start_date" id="add_lease_start_date" value="<?php echo isset($addPrefill['lease_start_date']) ? htmlspecialchars($addPrefill['lease_start_date']) : date('Y-m-d'); ?>" required>
                            <?php if (!empty($addFieldErrors['lease_start_date'])): ?>
                                <div class="invalid-feedback d-block"><?php echo htmlspecialchars($addFieldErrors['lease_start_date']); ?></div>
                            <?php endif; ?>
                            <small class="text-muted">The rent calendar will start from this date.</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="lease_end_date" class="form-label">Lease End Date</label>
                            <input type="date" class="form-control" name="lease_end_date" id="add_lease_end_date" value="<?php echo isset($addPrefill['lease_end_date']) ? htmlspecialchars($addPrefill['lease_end_date']) : ''; ?>">
                            <small class="text-muted">Optional. When the lease contract ends.</small>
                        </div>
                    </div>

                    <!-- Deposit and Advance Payment Section -->
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-money-bill-wave me-2"></i>Required Payments Upon Registration
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" class="form-check-input me-2" name="deposit_paid" id="add_deposit_paid" value="1" <?php echo (!empty($addPrefill['deposit_paid'])) ? 'checked' : ''; ?>>
                                        <label for="add_deposit_paid" class="form-label mb-0 <?php echo !empty($addFieldErrors['deposit_paid']) ? 'text-danger fw-semibold' : ''; ?>">
                                            3-Month Deposit <span class="text-danger">*</span>
                                        </label>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-info fs-6" id="deposit_display">₱<span id="deposit_amount_text">15,000.00</span></span>
                                    </div>
                                    <small class="text-muted">Equivalent to 3x monthly rent</small>
                                    <?php if (!empty($addFieldErrors['deposit_paid'])): ?>
                                        <div class="text-danger small mt-1"><?php echo htmlspecialchars($addFieldErrors['deposit_paid']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" class="form-check-input me-2" name="advance_paid" id="add_advance_paid" value="1" <?php echo (!empty($addPrefill['advance_paid'])) ? 'checked' : ''; ?>>
                                        <label for="add_advance_paid" class="form-label mb-0 <?php echo !empty($addFieldErrors['advance_paid']) ? 'text-danger fw-semibold' : ''; ?>">
                                            1-Month Advance <span class="text-danger">*</span>
                                        </label>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-info fs-6" id="advance_display">₱<span id="advance_amount_text">5,000.00</span></span>
                                    </div>
                                    <small class="text-muted">Equivalent to 1x monthly rent</small>
                                    <?php if (!empty($addFieldErrors['advance_paid'])): ?>
                                        <div class="text-danger small mt-1"><?php echo htmlspecialchars($addFieldErrors['advance_paid']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="alert alert-warning mb-0 py-2">
                                <i class="fas fa-info-circle me-1"></i>
                                <strong>Total due upon move-in:</strong> <span id="total_move_in">₱20,000.00</span>
                                <small class="d-block mt-1">(3-month deposit + 1-month advance = 4x monthly rent)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_tenant" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add Tenant
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Unified confirmation helper for destructive actions
function confirmDelete(message){
    try {
        return window.confirm(message || 'Are you sure you want to proceed?');
    } catch (e) {
        return true; // If dialogs are blocked, avoid breaking form submission
    }
}
</script>

<!-- Edit Tenant Modal -->
<div class="modal fade" id="editTenantModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-edit me-2"></i>Edit Tenant
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="needs-validation" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="tenant_id" id="edit_tenant_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_name" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" id="edit_email" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="edit_contact" class="form-label">Contact</label>
                            <input type="tel" class="form-control" name="contact" id="edit_contact" placeholder="+63XXXXXXXXXX or 09XXXXXXXXX" maxlength="15" title="Enter a valid Philippine mobile number (+63XXXXXXXXXX or 09XXXXXXXXX)">
                            <small class="text-muted">Accepted: +63 followed by 10 digits, or 09 followed by 9 digits. Saved as +63 format.</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_unit_number" class="form-label">Unit Number *</label>
                            <input type="text" class="form-control" name="unit_number" id="edit_unit_number" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_rent_amount" class="form-label">Monthly Rent *</label>
                            <input type="number" class="form-control" name="rent_amount" id="edit_rent_amount" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_lease_start_date" class="form-label">Lease Start Date</label>
                            <input type="date" class="form-control" name="lease_start_date" id="edit_lease_start_date">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_lease_end_date" class="form-label">Lease End Date</label>
                            <input type="date" class="form-control" name="lease_end_date" id="edit_lease_end_date">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_tenant" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Tenant
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
<?php if (!empty($showAddModal)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var modal = new bootstrap.Modal(document.getElementById('addTenantModal'));
    modal.show();
    // Restore password from sessionStorage if available (kept client-side only)
    try {
        var pwd = document.getElementById('add_tenant_password');
        var saved = sessionStorage.getItem('sw_add_tenant_pwd');
        if (pwd && saved) {
            pwd.value = saved;
            pwd.dispatchEvent(new Event('input'));
        }
    } catch (e) {}
});
<?php endif; ?>
// Tenant search filter
(function(){
    var search = document.getElementById('tenantSearch');
    var table = document.querySelector('.table');
    if (search && table) {
        search.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
})();
function exportTenantsCSV() {
    const rows = Array.from(document.querySelectorAll('table tbody tr'));
    let csv = 'Name,Unit,Email,Contact,Rent,Username\n';
    rows.forEach(row => {
        const cols = row.querySelectorAll('td');
        let data = [];
        for (let i = 0; i < 6; i++) {
            data.push('"' + (cols[i]?.textContent.trim().replace(/"/g, '""') || '') + '"');
        }
        csv += data.join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'tenants.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
function viewTenantDetails(tenant) {
    let modal = document.getElementById('tenantDetailsModal');
    modal.querySelector('#details_name').textContent = tenant.name;
    modal.querySelector('#details_email').textContent = tenant.email;
    modal.querySelector('#details_contact').textContent = tenant.contact;
    modal.querySelector('#details_unit').textContent = tenant.unit_number;
    modal.querySelector('#details_rent').textContent = '₱' + Number(tenant.rent_amount).toLocaleString(undefined, {minimumFractionDigits:2});
    modal.querySelector('#details_username').textContent = tenant.username;
    
    // Deposit/Advance/Lease info
    var lsEl = modal.querySelector('#details_lease_start');
    if (lsEl) lsEl.textContent = tenant.lease_start_date || 'Not set';
    var depEl = modal.querySelector('#details_deposit');
    if (depEl) {
        var depAmt = tenant.deposit_amount ? '₱' + Number(tenant.deposit_amount).toLocaleString(undefined, {minimumFractionDigits:2}) : 'N/A';
        var depStatus = tenant.deposit_paid == 1 ? ' ✅ Confirmed' : ' ❌ Not confirmed';
        depEl.innerHTML = depAmt + depStatus;
    }
    var advEl = modal.querySelector('#details_advance');
    if (advEl) {
        var advAmt = tenant.advance_amount ? '₱' + Number(tenant.advance_amount).toLocaleString(undefined, {minimumFractionDigits:2}) : 'N/A';
        var advStatus = tenant.advance_paid == 1 ? ' ✅ Confirmed' : ' ❌ Not confirmed';
        advEl.innerHTML = advAmt + advStatus;
    }
    
    new bootstrap.Modal(modal).show();
}
function editTenant(tenant) {
    document.getElementById('edit_tenant_id').value = tenant.tenant_id || '';
    document.getElementById('edit_name').value = tenant.name || '';
    document.getElementById('edit_email').value = tenant.email || '';
    document.getElementById('edit_contact').value = tenant.contact != null ? tenant.contact : '';
    document.getElementById('edit_unit_number').value = tenant.unit_number || '';
    document.getElementById('edit_rent_amount').value = tenant.rent_amount || '';
    document.getElementById('edit_lease_start_date').value = tenant.lease_start_date || '';
    document.getElementById('edit_lease_end_date').value = tenant.lease_end_date || '';
    new bootstrap.Modal(document.getElementById('editTenantModal')).show();
}
</script>
<script>
// Real-time password validation for Add Tenant modal
document.addEventListener('DOMContentLoaded', function(){
    const form = document.querySelector('#addTenantModal form');
    if (!form) return;
    const pwd = document.getElementById('add_tenant_password');
    const help = document.getElementById('addTenantPwdHelp');
    const submit = form.querySelector('button[type="submit"][name="add_tenant"]');
    function meetsRules(v){
        return /[A-Z]/.test(v) && /[0-9]/.test(v) && /[!@#$%^&*()_+\-=\[\]{};:'"\\|,.<>\/?]/.test(v);
    }
    function upd(){
        const v = pwd.value || '';
        const ok = v.length>0 && meetsRules(v);
        if (!v) { help.textContent = 'Must include uppercase, number, and special character.'; help.className='form-text mt-1'; }
        else if (ok) { help.textContent = 'Strong password.'; help.className='form-text mt-1 text-success'; }
        else { help.textContent = 'Weak password. Add uppercase, number, and special character.'; help.className='form-text mt-1 text-danger'; }
        submit.disabled = !ok;
            // Persist to sessionStorage so it survives a page reload on validation errors
            try { sessionStorage.setItem('sw_add_tenant_pwd', v); } catch (e) {}
    }
    submit.disabled = true;
    pwd.addEventListener('input', upd);
    upd();
});
</script>
    <?php if (empty($showAddModal)): ?>
    <script>
    // Clear any cached password when the Add Tenant modal isn't being shown (e.g., after success)
    document.addEventListener('DOMContentLoaded', function(){
        try { sessionStorage.removeItem('sw_add_tenant_pwd'); } catch (e) {}
    });
    </script>
    <?php endif; ?>

<script>
// Auto-calculate deposit and advance amounts when rent changes
document.addEventListener('DOMContentLoaded', function(){
    const rentInput = document.getElementById('add_rent_amount');
    const depositText = document.getElementById('deposit_amount_text');
    const advanceText = document.getElementById('advance_amount_text');
    const totalMoveIn = document.getElementById('total_move_in');
    
    function formatNumber(n) {
        return n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    
    function updateAmounts() {
        if (!rentInput) return;
        const rent = parseFloat(rentInput.value) || 0;
        const deposit = rent * 3;
        const advance = rent * 1;
        const total = deposit + advance;
        
        if (depositText) depositText.textContent = formatNumber(deposit);
        if (advanceText) advanceText.textContent = formatNumber(advance);
        if (totalMoveIn) totalMoveIn.textContent = '₱' + formatNumber(total);
    }
    
    if (rentInput) {
        rentInput.addEventListener('input', updateAmounts);
        rentInput.addEventListener('change', updateAmounts);
        updateAmounts(); // Initialize on page load
    }
});
</script>

<script>
// Custom confirmation for tenant password reset without native confirm dialogs
(function(){
    var modalEl = document.getElementById('confirmResetTenantModal');
    var nameNode = modalEl ? modalEl.querySelector('#crt_username') : null;
    var confirmBtn = modalEl ? modalEl.querySelector('#crt_confirm_btn') : null;
    var pendingForm = null;

    // When clicking the reset button, stash the form and fill username
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.btn-reset-tenant');
        if (!btn) return;
        pendingForm = btn.closest('form');
        var uname = btn.getAttribute('data-username') || '';
        if (nameNode) nameNode.textContent = uname;
    });

    // On modal confirm, submit the original form
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function(){
            if (pendingForm) {
                pendingForm.submit();
                pendingForm = null;
            }
        });
    }
})();

// Custom confirmation for tenant delete
(function(){
    var deleteModalEl = document.getElementById('confirmDeleteTenantModal');
    var deleteConfirmBtn = deleteModalEl ? deleteModalEl.querySelector('#dt_confirm_btn') : null;
    var pendingDeleteForm = null;

    document.addEventListener('click', function(e){
        var btn = e.target.closest('.btn-delete-tenant');
        if (!btn) return;
        pendingDeleteForm = btn.closest('form');
    });

    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', function(){
            if (pendingDeleteForm) {
                pendingDeleteForm.submit();
                pendingDeleteForm = null;
            }
        });
    }
})();
</script>

<?php include '../includes/footer.php'; ?>
<?php if (!empty($showEditModal) && !empty($editPrefill['tenant_id'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Prefill edit modal with server-provided values
    document.getElementById('edit_tenant_id').value = '<?php echo (int)$editPrefill['tenant_id']; ?>';
    document.getElementById('edit_name').value = '<?php echo htmlspecialchars($editPrefill['name'] ?? '', ENT_QUOTES); ?>';
    document.getElementById('edit_email').value = '<?php echo htmlspecialchars($editPrefill['email'] ?? '', ENT_QUOTES); ?>';
    document.getElementById('edit_contact').value = '<?php echo htmlspecialchars($editPrefill['contact'] ?? '', ENT_QUOTES); ?>';
    document.getElementById('edit_unit_number').value = '<?php echo htmlspecialchars($editPrefill['unit_number'] ?? '', ENT_QUOTES); ?>';
    document.getElementById('edit_rent_amount').value = '<?php echo htmlspecialchars($editPrefill['rent_amount'] ?? '', ENT_QUOTES); ?>';
    new bootstrap.Modal(document.getElementById('editTenantModal')).show();
});
</script>
<?php endif; ?>
<!-- Tenant Details Modal -->
<div class="modal fade" id="tenantDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i>Tenant Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Name:</strong> <span id="details_name"></span></p>
                <p><strong>Email:</strong> <span id="details_email"></span></p>
                <p><strong>Contact:</strong> <span id="details_contact"></span></p>
                <p><strong>Unit:</strong> <span id="details_unit"></span></p>
                <p><strong>Rent:</strong> <span id="details_rent"></span></p>
                <p><strong>Username:</strong> <span id="details_username"></span></p>
                <hr>
                <p><strong>Lease Start:</strong> <span id="details_lease_start"></span></p>
                <p><strong>Deposit (3 months):</strong> <span id="details_deposit"></span></p>
                <p><strong>Advance (1 month):</strong> <span id="details_advance"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
