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
    SELECT u.username, u.full_name, u.email, u.profile_photo,
           u.middle_name,
           t.unit_number, t.rent_amount, t.lease_start_date, t.lease_end_date,
           t.deposit_amount, t.advance_amount, t.deposit_paid, t.advance_paid,
           t.contact, t.tenant_id
    FROM users u
    LEFT JOIN tenants t ON t.user_id = u.id
    WHERE u.id = ?
");
if (!$stmt) die('Database prepare failed: ' . $conn->error);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { header("Location: ../logout.php"); exit(); }

$success = $error = '';
$nameParts  = explode(' ', trim($user['full_name'] ?? ''), 2);
$firstName  = $nameParts[0] ?? '';
$lastName   = $nameParts[1] ?? '';
$middleName = $user['middle_name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === 0) {
        try {
            require_once '../includes/photo_helper.php';
            $dataUri = process_photo_to_base64($_FILES['profile_photo']);
            $upd = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            $upd->bind_param("si", $dataUri, $_SESSION['user_id']);
            $upd->execute(); $upd->close();
            $success = "Profile photo updated.";
            $user['profile_photo'] = $dataUri;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    $first      = trim($_POST['first_name']        ?? '');
    $middle     = trim($_POST['middle_name']        ?? '');
    $last       = trim($_POST['last_name']          ?? '');
    $email      = trim($_POST['email']              ?? '');
    $phone      = trim($_POST['phone']              ?? '');
    $full_name  = trim("$first $last");

    if (empty($first) || empty($last) || empty($email)) {
        $error = "First name, last name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, middle_name=?, email=? WHERE id=?");
        $stmt->bind_param("sssi", $full_name, $middle, $email, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $sync = $conn->prepare("UPDATE tenants SET name=?, email=?, contact=? WHERE user_id=?");
            if ($sync) { $sync->bind_param("sssi", $full_name, $email, $phone, $_SESSION['user_id']); $sync->execute(); $sync->close(); }
            $success = "Profile updated successfully.";
            $user['full_name']   = $full_name;
            $user['middle_name'] = $middle;
            $user['email']       = $email;
            $firstName = $first; $middleName = $middle; $lastName = $last;
        } else { $error = "Failed to update profile."; }
        $stmt->close();
    }
}


$profilePhoto = $user['profile_photo'] ?? '';
$initials = strtoupper(substr($firstName ?: $user['username'], 0, 1) . substr($lastName ?: ($user['username'] ?? ''), 0, 1));
$activeTab = $_GET['tab'] ?? 'profile';

include '../includes/header.php';
?>

<style>
.profile-wrap { max-width: 920px; margin: 0 auto; padding: 1.75rem 1.25rem 3rem; }
.acct-heading { font-size: 1.45rem; font-weight: 700; margin-bottom: 1.5rem; }
body:not(.dark-mode) .acct-heading { color: #111827; }
body.dark-mode .acct-heading { color: #f1f5f9; }

.profile-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 2rem; }
body.dark-mode .profile-tabs { border-bottom-color: #2d3748; }
.profile-tab {
  padding: .7rem 1.35rem; font-size: .88rem; font-weight: 600;
  border: none; background: none; cursor: pointer;
  border-bottom: 3px solid transparent; margin-bottom: -2px;
  transition: color .15s, border-color .15s; color: #64748b; white-space: nowrap;
}
.profile-tab:hover { color: #0f172a; }
.profile-tab.active { color: #16a34a; border-bottom-color: #16a34a; }
body.dark-mode .profile-tab { color: #94a3b8; }
body.dark-mode .profile-tab:hover { color: #e2e8f0; }
body.dark-mode .profile-tab.active { color: #4ED6C1; border-bottom-color: #4ED6C1; }

.tab-panel { display: none; }
.tab-panel.active { display: block; }

.section-title { font-weight: 700; font-size: 1rem; margin-bottom: .2rem; }
.section-sub { font-size: .82rem; color: #94a3b8; margin-bottom: 1.75rem; }
body:not(.dark-mode) .section-title { color: #111827; }
body.dark-mode .section-title { color: #f1f5f9; }

/* Profile header row */
.profile-header-row { display: flex; align-items: center; gap: 20px; margin-bottom: 1.5rem; }
.profile-header-info { flex: 1; }
.profile-header-name { font-size: 1.2rem; font-weight: 700; line-height: 1.2; }
body:not(.dark-mode) .profile-header-name { color: #111827; }
body.dark-mode .profile-header-name { color: #f1f5f9; }
.profile-header-meta { font-size: .8rem; color: #94a3b8; margin-top: 3px; }

/* Divider */
.profile-divider { border: none; border-top: 1px solid #e2e8f0; margin: 1.25rem 0; }
body.dark-mode .profile-divider { border-top-color: #2d3748; }

/* Photo circle */
.photo-circle {
  width: 88px; height: 88px; border-radius: 50%; background: #64748b;
  display: flex; align-items: center; justify-content: center;
  position: relative; overflow: hidden; cursor: pointer; flex-shrink: 0;
}
.photo-circle img { width: 100%; height: 100%; object-fit: cover; }
.photo-circle-initials { font-size: 1.8rem; font-weight: 700; color: #fff; line-height: 1; }
.photo-overlay {
  position: absolute; inset: 0; background: rgba(0,0,0,.45);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .2s;
}
.photo-circle:hover .photo-overlay { opacity: 1; }
.photo-overlay i { color: #fff; font-size: 1.1rem; }
.photo-overlay span { color: #fff; font-size: .65rem; margin-top: 4px; font-weight: 600; }

/* Inputs */
.tc-label { display: block; font-size: .78rem; font-weight: 600; color: #6b7280; margin-bottom: 5px; }
body.dark-mode .tc-label { color: #94a3b8; }
.tc-input {
  width: 100%; padding: .65rem .9rem; border: 1.5px solid #d1d5db; border-radius: 8px;
  font-size: .88rem; color: #111827; background: #fff;
  transition: border-color .15s, box-shadow .15s; outline: none; font-family: inherit;
}
.tc-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.1); }
.tc-input:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
body.dark-mode .tc-input { background: #1e293b; border-color: #374151; color: #e2e8f0; }
body.dark-mode .tc-input:focus { border-color: #4ED6C1; box-shadow: 0 0 0 3px rgba(78,214,193,.1); }
body.dark-mode .tc-input:disabled { background: #111827; color: #4b5563; }
.tc-check-row { display: flex; align-items: center; gap: 10px; padding: .5rem 0; }
.tc-check-row input[type=checkbox] { width: 16px; height: 16px; accent-color: #16a34a; cursor: pointer; flex-shrink: 0; }
.tc-check-label { font-size: .88rem; font-weight: 500; cursor: pointer; }
body:not(.dark-mode) .tc-check-label { color: #374151; }
body.dark-mode .tc-check-label { color: #d1d5db; }

.btn-tc-save {
  background: #16a34a; color: #fff; border: none; padding: .6rem 1.75rem; border-radius: 8px;
  font-weight: 600; font-size: .88rem; cursor: pointer; font-family: inherit; transition: background .15s;
}
.btn-tc-save:hover { background: #15803d; }

/* Security rows */
.sec-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1.1rem 0; border-bottom: 1px solid #f1f5f9;
}
.sec-row:last-child { border-bottom: none; }
body.dark-mode .sec-row { border-bottom-color: #1e293b; }
.sec-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.btn-outline-tc {
  border: 1.5px solid #d1d5db; background: none; color: #374151; padding: .4rem 1rem; border-radius: 8px;
  font-weight: 600; font-size: .8rem; cursor: pointer; font-family: inherit; text-decoration: none;
  transition: border-color .15s, color .15s; display: inline-block;
}
.btn-outline-tc:hover { border-color: #16a34a; color: #16a34a; }
body.dark-mode .btn-outline-tc { border-color: #374151; color: #94a3b8; }
body.dark-mode .btn-outline-tc:hover { border-color: #4ED6C1; color: #4ED6C1; }
.btn-danger-tc {
  border: 1.5px solid #fecaca; background: none; color: #dc2626; padding: .4rem 1rem; border-radius: 8px;
  font-weight: 600; font-size: .8rem; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block;
  transition: background .15s;
}
.btn-danger-tc:hover { background: #fef2f2; }

/* Tenant photos grid */
.tenant-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 16px; margin-top: 1rem; }
.tenant-card {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 16px 10px; border-radius: 12px; text-align: center;
  border: 1.5px solid #e2e8f0;
}
body.dark-mode .tenant-card { border-color: #2d3748; background: #1e293b; }
.tenant-avatar {
  width: 60px; height: 60px; border-radius: 50%; background: #64748b;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0;
}
.tenant-avatar img { width: 100%; height: 100%; object-fit: cover; }
.tenant-card-name { font-size: .8rem; font-weight: 600; line-height: 1.2; }
body:not(.dark-mode) .tenant-card-name { color: #111827; }
body.dark-mode .tenant-card-name { color: #e2e8f0; }
.tenant-card-unit { font-size: .72rem; color: #94a3b8; }
</style>

<div class="profile-wrap">

  <div class="acct-heading">Account settings</div>

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

  <div class="profile-tabs">
    <button class="profile-tab <?= $activeTab==='profile'?'active':'' ?>" data-tab="profile">Profile</button>
    <button class="profile-tab <?= $activeTab==='security'?'active':'' ?>" data-tab="security">Security</button>
  </div>

  <!-- ══ PROFILE ══ -->
  <div class="tab-panel <?= $activeTab==='profile'?'active':'' ?>" id="tab-profile">

    <!-- Photo + name header -->
    <div class="profile-header-row">
      <form method="POST" enctype="multipart/form-data" style="flex-shrink:0;">
        <?= csrf_input() ?>
        <input type="hidden" name="update_photo" value="1">
        <input type="file" id="photoInput" name="profile_photo" accept="image/*" style="display:none" onchange="this.form.submit()">
        <div class="photo-circle" onclick="document.getElementById('photoInput').click()" title="Update image">
          <?php if (!empty($profilePhoto)): ?>
            <?php
              $photoSrc = str_starts_with($profilePhoto, 'data:')
                ? $profilePhoto
                : '../uploads/profiles/' . htmlspecialchars($profilePhoto);
            ?>
            <img src="<?= $photoSrc ?>" alt="Profile photo" onerror="this.style.display='none';document.querySelector('.photo-circle-initials').style.display='flex';">
          <?php endif; ?>
          <div class="photo-circle-initials" <?= !empty($profilePhoto) ? 'style="display:none;"' : '' ?>><?= $initials ?></div>
          <div class="photo-overlay"><i class="fas fa-camera"></i><span>Update</span></div>
        </div>
      </form>
      <div class="profile-header-info">
        <div class="profile-header-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></div>
        <div class="profile-header-meta"><?= htmlspecialchars($user['email']) ?> &nbsp;·&nbsp; Tenant</div>
        <div style="font-size:.72rem;color:#94a3b8;margin-top:4px;">Click photo to update</div>
      </div>
    </div>

    <div class="profile-divider"></div>

    <div class="section-title">Profile details</div>
    <div class="section-sub">Your profile is visible to your connected users.</div>

    <form method="POST">
      <?= csrf_input() ?>
      <div class="row g-3">
        <div class="col-sm-6">
          <label class="tc-label">First name <span style="color:#ef4444">*</span></label>
          <input type="text" name="first_name" class="tc-input" required value="<?= htmlspecialchars($firstName) ?>">
        </div>
        <div class="col-sm-6">
          <label class="tc-label">Middle name</label>
          <input type="text" name="middle_name" class="tc-input" value="<?= htmlspecialchars($middleName) ?>">
        </div>
        <div class="col-12" style="margin-bottom:.5rem;">
          <label class="tc-label">Last name <span style="color:#ef4444">*</span></label>
          <input type="text" name="last_name" class="tc-input" required value="<?= htmlspecialchars($lastName) ?>">
        </div>

        <div class="col-12"><div class="profile-divider" style="margin:.25rem 0 .5rem;"></div></div>

        <div class="col-12">
          <label class="tc-label">Email address <span style="color:#ef4444">*</span></label>
          <input type="email" name="email" class="tc-input" required value="<?= htmlspecialchars($user['email']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="tc-label">Username</label>
          <input type="text" class="tc-input" disabled value="<?= htmlspecialchars($user['username']) ?>">
        </div>
        <div class="col-sm-6">
          <label class="tc-label">Role</label>
          <input type="text" class="tc-input" disabled value="Tenant">
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" name="update_profile" class="btn-tc-save">Save changes</button>
      </div>
    </form>
  </div>

  <!-- ══ SECURITY ══ -->
  <div class="tab-panel <?= $activeTab==='security'?'active':'' ?>" id="tab-security">
    <div class="section-title">Security</div>
    <div class="section-sub">Manage your password and account access.</div>

    <div class="sec-row">
      <div class="d-flex align-items-center gap-3">
        <div class="sec-icon" style="background:#f0fdf4;"><i class="fas fa-key" style="color:#16a34a;"></i></div>
        <div>
          <div style="font-weight:600;font-size:.9rem;">Password</div>
          <div style="font-size:.78rem;color:#94a3b8;">Change your password via email verification</div>
        </div>
      </div>
      <a href="../change_password.php" class="btn-outline-tc">Change password</a>
    </div>

    <div class="sec-row">
      <div class="d-flex align-items-center gap-3">
        <div class="sec-icon" style="background:#eff6ff;"><i class="fas fa-envelope" style="color:#2563eb;"></i></div>
        <div>
          <div style="font-weight:600;font-size:.9rem;">Email address</div>
          <div style="font-size:.78rem;color:#94a3b8;"><?= htmlspecialchars($user['email']) ?></div>
        </div>
      </div>
      <button class="btn-outline-tc" onclick="document.querySelector('[data-tab=profile]').click()">Update</button>
    </div>

    <div class="sec-row">
      <div class="d-flex align-items-center gap-3">
        <div class="sec-icon" style="background:#fef2f2;"><i class="fas fa-sign-out-alt" style="color:#dc2626;"></i></div>
        <div>
          <div style="font-weight:600;font-size:.9rem;">Sign out</div>
          <div style="font-size:.78rem;color:#94a3b8;">Log out of your StayWise account</div>
        </div>
      </div>
      <a href="../logout.php" class="btn-danger-tc">Sign out</a>
    </div>
  </div>

</div>

<script>
document.querySelectorAll('.profile-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.profile-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});
</script>

<?php include '../includes/footer.php'; ?>
