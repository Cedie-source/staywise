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

$stmt = $conn->prepare("
    SELECT u.username, u.full_name, u.email,
           t.unit_number, t.contact, t.tenant_id
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { http_response_code(400); die('Invalid token.'); }

    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');

    if (empty($full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $email, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $sync = $conn->prepare("UPDATE tenants SET name = ?, email = ?, contact = ? WHERE user_id = ?");
            if ($sync) { $sync->bind_param("sssi", $full_name, $email, $phone, $_SESSION['user_id']); $sync->execute(); $sync->close(); }
            $success = "Profile updated successfully.";
            $user['full_name'] = $full_name;
            $user['email']     = $email;
            $user['contact']   = $phone;
        } else {
            $error = "Failed to update profile.";
        }
        $stmt->close();
    }
}

$initials  = strtoupper(substr($user['full_name'] ?? $user['username'], 0, 2));
$activeTab = $_GET['tab'] ?? 'profile';

include '../includes/header.php';
?>
<style>
.profile-wrap { max-width: 860px; margin: 0 auto; padding: 1.75rem 1.25rem 3rem; }
.acct-heading { font-size: 1.45rem; font-weight: 700; margin-bottom: 1.5rem; }
body:not(.dark-mode) .acct-heading { color: #111827; }
body.dark-mode .acct-heading { color: #f1f5f9; }
.profile-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 2rem; }
body.dark-mode .profile-tabs { border-bottom-color: #2d3748; }
.profile-tab { padding: .7rem 1.35rem; font-size: .88rem; font-weight: 600; border: none; background: none; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: color .15s, border-color .15s; color: #64748b; white-space: nowrap; }
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
.tc-input { width: 100%; padding: .65rem .9rem; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: .88rem; color: #111827; background: #fff; transition: border-color .15s, box-shadow .15s; outline: none; font-family: inherit; }
.tc-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.1); }
.tc-input:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
body.dark-mode .tc-input { background: #1e293b; border-color: #374151; color: #e2e8f0; }
body.dark-mode .tc-input:focus { border-color: #4ED6C1; box-shadow: 0 0 0 3px rgba(78,214,193,.1); }
body.dark-mode .tc-input:disabled { background: #111827; color: #4b5563; }
.profile-avatar { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, #4ED6C1, #007DFE); display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700; color: #fff; flex-shrink: 0; }
.btn-tc-save { background: #16a34a; color: #fff; border: none; padding: .6rem 1.75rem; border-radius: 8px; font-weight: 600; font-size: .88rem; cursor: pointer; font-family: inherit; transition: background .15s; }
.btn-tc-save:hover { background: #15803d; }
.sec-row { display: flex; align-items: center; justify-content: space-between; padding: 1.1rem 0; border-bottom: 1px solid #f1f5f9; }
.sec-row:last-child { border-bottom: none; }
body.dark-mode .sec-row { border-bottom-color: #1e293b; }
.sec-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.btn-outline-tc { border: 1.5px solid #d1d5db; background: none; color: #374151; padding: .4rem 1rem; border-radius: 8px; font-weight: 600; font-size: .8rem; cursor: pointer; font-family: inherit; text-decoration: none; transition: border-color .15s, color .15s; display: inline-block; }
.btn-outline-tc:hover { border-color: #16a34a; color: #16a34a; }
body.dark-mode .btn-outline-tc { border-color: #374151; color: #94a3b8; }
body.dark-mode .btn-outline-tc:hover { border-color: #4ED6C1; color: #4ED6C1; }
.btn-danger-tc { border: 1.5px solid #fecaca; background: none; color: #dc2626; padding: .4rem 1rem; border-radius: 8px; font-weight: 600; font-size: .8rem; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-block; transition: background .15s; }
.btn-danger-tc:hover { background: #fef2f2; }
.tog { width: 42px; height: 24px; border-radius: 12px; position: relative; cursor: pointer; border: none; flex-shrink: 0; transition: background .2s; }
.tog.on { background: #16a34a; }
.tog.off { background: #d1d5db; }
body.dark-mode .tog.off { background: #374151; }
.tog::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.tog.on::after { transform: translateX(18px); }
.notif-row { display: flex; align-items: center; justify-content: space-between; padding: 1rem 0; border-bottom: 1px solid #f1f5f9; }
.notif-row:last-child { border-bottom: none; }
body.dark-mode .notif-row { border-bottom-color: #1e293b; }
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
    <button class="profile-tab <?= $activeTab==='notifications'?'active':'' ?>" data-tab="notifications">Notifications</button>
  </div>

  <!-- PROFILE -->
  <div class="tab-panel <?= $activeTab==='profile'?'active':'' ?>" id="tab-profile">
    <div class="row g-5 align-items-start">
      <div class="col-md-8">
        <div class="section-title">Profile details</div>
        <div class="section-sub">Your profile is visible to your connected users.</div>
        <form method="POST">
          <?= csrf_input() ?>
          <div class="row g-3">
            <div class="col-12">
              <label class="tc-label">Username</label>
              <input type="text" class="tc-input" disabled value="<?= htmlspecialchars($user['username']) ?>">
            </div>
            <div class="col-12">
              <label class="tc-label">Full name <span style="color:#ef4444">*</span></label>
              <input type="text" name="full_name" class="tc-input" required value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="tc-label">Email address <span style="color:#ef4444">*</span></label>
              <input type="email" name="email" class="tc-input" required value="<?= htmlspecialchars($user['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="tc-label">Phone number</label>
              <input type="tel" name="phone" class="tc-input" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($user['contact'] ?? '') ?>">
            </div>
          </div>
          <div class="mt-4">
            <button type="submit" name="update_profile" class="btn-tc-save">Save changes</button>
          </div>
        </form>
      </div>
      <div class="col-md-4 d-flex flex-column align-items-center pt-md-3">
        <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
        <div style="font-size:.78rem;color:#94a3b8;margin-top:.6rem;"><?= htmlspecialchars($user['username']) ?></div>
        <?php if (!empty($user['unit_number'])): ?>
        <div style="font-size:.75rem;color:#4ED6C1;margin-top:.2rem;font-weight:600;">Unit <?= htmlspecialchars($user['unit_number']) ?></div>
        <?php endif; ?>
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
          <div style="font-size:.78rem;color:#94a3b8;"><?= htmlspecialchars($user['email'] ?? '') ?></div>
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

  <!-- NOTIFICATIONS -->
  <div class="tab-panel <?= $activeTab==='notifications'?'active':'' ?>" id="tab-notifications">
    <div class="section-title">Notifications</div>
    <div class="section-sub">Choose what updates you want to be notified about.</div>
    <?php
    $notifItems = [
      ['Payment verified',  'When admin verifies your payment',    true],
      ['Payment rejected',  'When admin rejects your payment',     true],
      ['New announcements', 'When admin posts a new announcement', true],
      ['Complaint updates', 'When your complaint status changes',  true],
      ['Payment reminders', 'Remind me before rent is due',       false],
    ];
    foreach ($notifItems as $n): ?>
    <div class="notif-row">
      <div>
        <div style="font-weight:600;font-size:.88rem;"><?= $n[0] ?></div>
        <div style="font-size:.78rem;color:#94a3b8;"><?= $n[1] ?></div>
      </div>
      <button class="tog <?= $n[2]?'on':'off' ?>" onclick="this.classList.toggle('on');this.classList.toggle('off');"></button>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
document.querySelectorAll('.profile-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.profile-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    this.classList.add('active');
    document.getElementById('tab-' + this.dataset.tab).classList.add('active');
  });
});
</script>

<?php include '../includes/footer.php'; ?>
