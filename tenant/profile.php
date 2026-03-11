<?php
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tenant') {
    header("Location: ../index.php");
    exit();
}

$page_title = "My Profile";

// Fetch user + tenant info
$stmt = $conn->prepare("
    SELECT u.username, u.full_name, u.email,
           t.unit_number, t.rent_amount, t.lease_start_date, t.lease_end_date,
           t.deposit_amount, t.advance_amount, t.deposit_paid, t.advance_paid,
           t.phone
    FROM users u
    LEFT JOIN tenants t ON t.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { header("Location: ../logout.php"); exit(); }

$success = $error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone'] ?? '');

    if (empty($full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $email, $_SESSION['user_id']);
        if ($stmt->execute()) {
            // Sync to tenants table
            $sync = $conn->prepare("UPDATE tenants SET name = ?, email = ?, phone = ? WHERE user_id = ?");
            if ($sync) { $sync->bind_param("sssi", $full_name, $email, $phone, $_SESSION['user_id']); $sync->execute(); $sync->close(); }
            $success = "Profile updated successfully.";
            $user['full_name'] = $full_name;
            $user['email']     = $email;
            $user['phone']     = $phone;
        } else {
            $error = "Failed to update profile.";
        }
        $stmt->close();
    }
}

// Initials for avatar
$initials = strtoupper(substr($user['full_name'] ?: $user['username'], 0, 1) . substr(explode(' ', $user['full_name'] ?: '')[1] ?? $user['username'], 0, 1));

include '../includes/header.php';
?>

<style>
.profile-page { max-width: 800px; margin: 0 auto; padding: 2rem 1rem 3rem; }

/* Hero card */
.profile-hero {
  border-radius: 18px; padding: 2rem;
  margin-bottom: 1.5rem; position: relative; overflow: hidden;
  background: linear-gradient(135deg, #0D1B2A 0%, #1a3a4a 50%, #0d3d2e 100%);
  color: #fff;
}
.profile-hero::before {
  content: ''; position: absolute; top: -60px; right: -60px;
  width: 220px; height: 220px; border-radius: 50%;
  background: rgba(78,214,193,.08); pointer-events: none;
}
.profile-hero::after {
  content: ''; position: absolute; bottom: -40px; left: 20%;
  width: 150px; height: 150px; border-radius: 50%;
  background: rgba(0,125,254,.06); pointer-events: none;
}
.profile-avatar-lg {
  width: 72px; height: 72px; border-radius: 50%;
  background: linear-gradient(135deg, #4ED6C1, #007DFE);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; font-weight: 800; color: #fff;
  border: 3px solid rgba(255,255,255,.2); flex-shrink: 0;
}
.profile-hero-name { font-size: 1.3rem; font-weight: 700; line-height: 1.2; }
.profile-hero-sub  { font-size: .82rem; color: rgba(255,255,255,.6); margin-top: 2px; }
.profile-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 12px; border-radius: 20px; font-size: .75rem; font-weight: 600;
  background: rgba(78,214,193,.15); color: #4ED6C1; border: 1px solid rgba(78,214,193,.25);
}

/* Info grid */
.info-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: .75rem; margin-top: 1.25rem;
}
.info-chip {
  background: rgba(255,255,255,.06); border-radius: 10px;
  padding: .6rem .85rem; border: 1px solid rgba(255,255,255,.08);
}
.info-chip-label { font-size: .67rem; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: .06em; }
.info-chip-value { font-size: .9rem; font-weight: 600; color: #fff; margin-top: 2px; }

/* Form card */
.profile-card {
  border-radius: 16px; border: none;
  box-shadow: 0 2px 16px rgba(0,0,0,.06);
  overflow: hidden; margin-bottom: 1.25rem;
}
body.dark-mode .profile-card { box-shadow: 0 2px 16px rgba(0,0,0,.3); }
.profile-card-header {
  padding: .9rem 1.25rem; font-weight: 700; font-size: .88rem;
  border-bottom: 1px solid rgba(0,0,0,.06);
  display: flex; align-items: center; gap: 8px;
}
body:not(.dark-mode) .profile-card-header { background: #fff; }
body.dark-mode .profile-card-header { background: #1B263B; border-bottom-color: rgba(255,255,255,.06); }
.profile-card-body { padding: 1.25rem; }
body:not(.dark-mode) .profile-card-body { background: #fff; }
body.dark-mode .profile-card-body { background: #1B263B; }

/* Form fields */
.profile-field-label {
  font-size: .78rem; font-weight: 600; margin-bottom: 5px;
  display: flex; align-items: center; gap: 6px;
}
body:not(.dark-mode) .profile-field-label { color: #374151; }
body.dark-mode .profile-field-label { color: #94a3b8; }
.profile-input {
  border-radius: 10px !important; font-size: .88rem !important;
  padding: .55rem .85rem !important;
  border: 1.5px solid #e2e8f0 !important;
  transition: border-color .15s, box-shadow .15s !important;
}
.profile-input:focus {
  border-color: #4ED6C1 !important;
  box-shadow: 0 0 0 3px rgba(78,214,193,.12) !important;
}
body.dark-mode .profile-input {
  background: #243044 !important; color: #e2e8f0 !important;
  border-color: #334155 !important;
}
body.dark-mode .profile-input:focus { border-color: #4ED6C1 !important; }
.profile-input:disabled {
  background: #f8fafc !important; color: #94a3b8 !important; cursor: not-allowed;
}
body.dark-mode .profile-input:disabled { background: #1a2435 !important; color: #475569 !important; }

/* Save button */
.btn-profile-save {
  background: linear-gradient(135deg, #4ED6C1, #007DFE);
  border: none; color: #fff; font-weight: 700;
  padding: .6rem 1.75rem; border-radius: 10px; font-size: .88rem;
  transition: opacity .15s, transform .1s;
}
.btn-profile-save:hover { opacity: .9; transform: translateY(-1px); color: #fff; }

/* Security card */
.security-item {
  display: flex; align-items: center; justify-content-between;
  padding: .85rem 0; border-bottom: 1px solid rgba(0,0,0,.05);
}
.security-item:last-child { border-bottom: none; }
body.dark-mode .security-item { border-bottom-color: rgba(255,255,255,.05); }
</style>

<div class="profile-page container-fluid">

  <?php if ($success): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3">
    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-3">
    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- ── HERO ── -->
  <div class="profile-hero">
    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="profile-avatar-lg"><?= $initials ?></div>
      <div>
        <div class="profile-hero-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="profile-hero-sub"><?= htmlspecialchars($user['email']) ?></div>
        <div class="mt-2">
          <span class="profile-pill"><i class="fas fa-home" style="font-size:.7rem;"></i> Unit <?= htmlspecialchars($user['unit_number'] ?? '—') ?></span>
        </div>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-chip">
        <div class="info-chip-label">Monthly Rent</div>
        <div class="info-chip-value">₱<?= number_format((float)($user['rent_amount'] ?? 0), 2) ?></div>
      </div>
      <div class="info-chip">
        <div class="info-chip-label">Lease Start</div>
        <div class="info-chip-value"><?= $user['lease_start_date'] ? date('M d, Y', strtotime($user['lease_start_date'])) : '—' ?></div>
      </div>
      <div class="info-chip">
        <div class="info-chip-label">Lease End</div>
        <div class="info-chip-value"><?= $user['lease_end_date'] ? date('M d, Y', strtotime($user['lease_end_date'])) : 'Ongoing' ?></div>
      </div>
      <div class="info-chip">
        <div class="info-chip-label">Deposit</div>
        <div class="info-chip-value" style="color:<?= $user['deposit_paid'] ? '#4ED6C1' : '#f59e0b'; ?>">
          <?= $user['deposit_paid'] ? '✓ Paid' : 'Pending' ?>
          <span style="font-size:.75rem;font-weight:400;opacity:.7;"> · ₱<?= number_format((float)($user['deposit_amount'] ?? 0), 0) ?></span>
        </div>
      </div>
    </div>
  </div>

  <!-- ── EDIT PROFILE ── -->
  <div class="profile-card card">
    <div class="profile-card-header">
      <i class="fas fa-user-edit" style="color:#4ED6C1;"></i> Personal Information
    </div>
    <div class="profile-card-body">
      <form method="POST" class="needs-validation" novalidate>
        <?= csrf_input() ?>
        <div class="row g-3">
          <div class="col-sm-6">
            <label class="profile-field-label"><i class="fas fa-at" style="color:#94a3b8;font-size:.75rem;"></i> Username</label>
            <input type="text" class="form-control profile-input" value="<?= htmlspecialchars($user['username']) ?>" disabled>
            <div class="form-text" style="font-size:.72rem;">Username cannot be changed</div>
          </div>
          <div class="col-sm-6">
            <label class="profile-field-label" for="full_name"><i class="fas fa-user" style="color:#94a3b8;font-size:.75rem;"></i> Full Name <span class="text-danger">*</span></label>
            <input type="text" name="full_name" id="full_name" class="form-control profile-input" required
                   value="<?= htmlspecialchars($user['full_name']) ?>">
            <div class="invalid-feedback">Please enter your full name.</div>
          </div>
          <div class="col-sm-6">
            <label class="profile-field-label" for="email"><i class="fas fa-envelope" style="color:#94a3b8;font-size:.75rem;"></i> Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" id="email" class="form-control profile-input" required
                   value="<?= htmlspecialchars($user['email']) ?>">
            <div class="invalid-feedback">Please enter a valid email.</div>
          </div>
          <div class="col-sm-6">
            <label class="profile-field-label" for="phone"><i class="fas fa-phone" style="color:#94a3b8;font-size:.75rem;"></i> Phone Number</label>
            <input type="tel" name="phone" id="phone" class="form-control profile-input"
                   placeholder="e.g. 09XX XXX XXXX"
                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
          </div>
        </div>
        <div class="d-flex justify-content-end mt-4">
          <button type="submit" name="update_profile" class="btn btn-profile-save">
            <i class="fas fa-save me-2"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── SECURITY ── -->
  <div class="profile-card card">
    <div class="profile-card-header">
      <i class="fas fa-shield-alt" style="color:#4ED6C1;"></i> Security
    </div>
    <div class="profile-card-body">
      <div class="security-item d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <div style="width:38px;height:38px;border-radius:10px;background:rgba(78,214,193,.1);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-key" style="color:#4ED6C1;font-size:.9rem;"></i>
          </div>
          <div>
            <div style="font-weight:600;font-size:.88rem;">Password</div>
            <div style="font-size:.76rem;color:#94a3b8;">Changes require email verification</div>
          </div>
        </div>
        <a href="../change_password.php" class="btn btn-sm btn-outline-secondary rounded-3" style="font-size:.78rem;">
          <i class="fas fa-pen me-1"></i>Change
        </a>
      </div>
      <div class="security-item d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <div style="width:38px;height:38px;border-radius:10px;background:rgba(239,68,68,.08);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-sign-out-alt" style="color:#ef4444;font-size:.9rem;"></i>
          </div>
          <div>
            <div style="font-weight:600;font-size:.88rem;">Sign Out</div>
            <div style="font-size:.76rem;color:#94a3b8;">Log out of your account</div>
          </div>
        </div>
        <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-3" style="font-size:.78rem;">
          <i class="fas fa-sign-out-alt me-1"></i>Logout
        </a>
      </div>
    </div>
  </div>

</div>

<script>
(() => {
  'use strict';
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php include '../includes/footer.php'; ?>
