<?php
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

$page_title = "Admin Profile";

$stmt = $conn->prepare("SELECT username, full_name, middle_name, company_name, display_as_company, profile_photo, email FROM users WHERE id = ?");
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
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['profile_photo']['tmp_name']);
        $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        $ext = $extMap[$mime] ?? '';
        if (!$ext) { $error = "Invalid image type."; }
        elseif ($_FILES['profile_photo']['size'] > 3 * 1024 * 1024) { $error = "Image too large (max 3MB)."; }
        else {
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $filename)) {
                $upd = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $upd->bind_param("si", $filename, $_SESSION['user_id']);
                $upd->execute(); $upd->close();
                $success = "Profile photo updated.";
                $user['profile_photo'] = $filename;
            } else { $error = "Upload failed."; }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }
    $first      = trim($_POST['first_name']        ?? '');
    $middle     = trim($_POST['middle_name']        ?? '');
    $last       = trim($_POST['last_name']          ?? '');
    $email      = trim($_POST['email']              ?? '');
    $company    = trim($_POST['company_name']       ?? '');
    $as_company = isset($_POST['display_as_company']) ? 1 : 0;
    $full_name  = trim("$first $last");

    if (empty($first) || empty($last) || empty($email)) {
        $error = "First name, last name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, middle_name=?, email=?, company_name=?, display_as_company=? WHERE id=?");
        $stmt->bind_param("ssssii", $full_name, $middle, $email, $company, $as_company, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $success = "Profile updated successfully.";
            $user['full_name']          = $full_name;
            $user['middle_name']        = $middle;
            $user['email']              = $email;
            $user['company_name']       = $company;
            $user['display_as_company'] = $as_company;
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
.photo-wrap { display: flex; flex-direction: column; align-items: center; gap: .6rem; }
.photo-circle {
  width: 108px; height: 108px; border-radius: 50%; background: #64748b;
  display: flex; align-items: center; justify-content: center;
  position: relative; overflow: hidden; cursor: pointer; flex-shrink: 0;
}
.photo-circle img { width: 100%; height: 100%; object-fit: cover; }
.photo-circle-initials { font-size: 2.2rem; font-weight: 700; color: #fff; line-height: 1; }
.photo-overlay {
  position: absolute; inset: 0; background: rgba(0,0,0,.45);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .2s;
}
.photo-circle:hover .photo-overlay { opacity: 1; }
.photo-overlay i { color: #fff; font-size: 1.25rem; }
.photo-overlay span { color: #fff; font-size: .68rem; margin-top: 5px; font-weight: 600; }
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

  <!-- PROFILE -->
  <div class="tab-panel <?= $activeTab==='profile'?'active':'' ?>" id="tab-profile">
    <div class="row g-5">
      <div class="col-md-8">
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
            <div class="col-12">
              <label class="tc-label">Last name <span style="color:#ef4444">*</span></label>
              <input type="text" name="last_name" class="tc-input" required value="<?= htmlspecialchars($lastName) ?>">
            </div>
            <div class="col-12">
              <label class="tc-label">Company name</label>
              <input type="text" name="company_name" class="tc-input" placeholder="e.g. Acme Corporation" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>">
            </div>
            <div class="col-12">
              <div class="tc-check-row">
                <input type="checkbox" id="displayAsCompany" name="display_as_company" <?= !empty($user['display_as_company']) ? 'checked' : '' ?>>
                <label class="tc-check-label" for="displayAsCompany">Display as a company?</label>
              </div>
            </div>
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
              <input type="text" class="tc-input" disabled value="<?= isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin' ? 'Super Admin' : 'Admin' ?>">
            </div>
          </div>
          <div class="mt-4">
            <button type="submit" name="update_profile" class="btn-tc-save">Save changes</button>
          </div>
        </form>
      </div>

      <!-- Photo -->
      <div class="col-md-4 d-flex flex-column align-items-center align-items-md-end pt-md-2">
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_input() ?>
          <input type="hidden" name="update_photo" value="1">
          <input type="file" id="photoInput" name="profile_photo" accept="image/*" style="display:none" onchange="this.form.submit()">
          <div class="photo-wrap">
            <div class="photo-circle" onclick="document.getElementById('photoInput').click()" title="Update image">
              <?php if (!empty($profilePhoto) && file_exists('../uploads/profiles/' . $profilePhoto)): ?>
                <img src="../uploads/profiles/<?= htmlspecialchars($profilePhoto) ?>" alt="Profile photo">
              <?php else: ?>
                <div class="photo-circle-initials"><?= $initials ?></div>
              <?php endif; ?>
              <div class="photo-overlay">
                <i class="fas fa-camera"></i>
                <span>Update image</span>
              </div>
            </div>
            <div style="font-size:.75rem;color:#94a3b8;text-align:center;">Click to update photo</div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- SECURITY -->
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
