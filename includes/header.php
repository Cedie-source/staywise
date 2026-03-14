<?php
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';

$base_url = '/';
$req_uri  = $_SERVER['REQUEST_URI'] ?? '';
$adminRoleNav = strtolower((string)($_SESSION['admin_role'] ?? ''));
$userRole     = $_SESSION['role'] ?? '';
$currentPage  = basename($_SERVER['PHP_SELF'] ?? '');

if (isset($_SESSION['user_id']) && empty($_SESSION['must_change_password'])) {
    try {
        if (isset($conn) && function_exists('db_column_exists') && db_column_exists($conn, 'users', 'force_password_change')) {
            $uid  = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid); $stmt->execute();
            $res  = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
            if ($row && !empty($row['force_password_change'])) $_SESSION['must_change_password'] = true;
        }
    } catch (Throwable $e) {}
}
if (!empty($_SESSION['must_change_password'])) {
    $self = basename($_SERVER['PHP_SELF'] ?? '');
    if (!in_array($self, ['change_password.php','logout.php','login.php','index.php'])) {
        header('Location: ' . $base_url . 'change_password.php'); exit();
    }
}

// Fetch display name and profile photo for header avatar
$_headerName = $_SESSION['username'] ?? '';
$_headerInitials = strtoupper(substr($_headerName, 0, 2));
$_headerPhoto = '';
if (isset($_SESSION['user_id'])) {
    try {
        $__ps = $conn->prepare("SELECT profile_photo FROM users WHERE id = ?");
        if ($__ps) {
            $__ps->bind_param("i", $_SESSION['user_id']);
            $__ps->execute();
            $__pr = $__ps->get_result()->fetch_assoc();
            $__ps->close();
            if (!empty($__pr['profile_photo'])) {
                $_headerPhoto = '/uploads/profiles/' . $__pr['profile_photo'];
            }
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1"/>
<title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'StayWise'; ?> - Rental Management</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
<link href="<?php echo $base_url; ?>assets/css/style.css?v=2026030602" rel="stylesheet"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/x-icon" href="<?php echo $base_url; ?>assets/favicon.ico"/>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $base_url; ?>assets/favicon-32.png"/>
<link rel="icon" type="image/png" sizes="64x64" href="<?php echo $base_url; ?>assets/favicon.png"/>
<link rel="apple-touch-icon" href="<?php echo $base_url; ?>assets/icon-192.png"/>
<link rel="manifest" href="<?php echo $base_url; ?>site.webmanifest"/>
<meta name="theme-color" content="#4ED6C1"/>
<style>
/* ── Variables ── */
:root {
  --topbar-h: 58px;
  --sidebar-w: 230px;
  --brand-teal: #4ED6C1;
  --brand-dark: #111111;
  --brand-blue: #007DFE;
  --nav-active-bg: rgba(78,214,193,.13);
  --nav-active-color: #4ED6C1;
}

/* ── Reset font ── */
body, button, input { font-family: 'DM Sans', sans-serif; }

/* ── TOP BAR ── */
#topBar {
  position: fixed; top: 0; left: 0; right: 0;
  height: var(--topbar-h);
  z-index: 1045;
  display: flex; align-items: center;
  padding: 0 16px;
  gap: 12px;
  border-bottom: 1px solid rgba(0,0,0,.07);
  transition: background .2s;
}
body:not(.dark-mode) #topBar {
  background: #fff;
  box-shadow: 0 1px 8px rgba(0,0,0,.06);
}
body.dark-mode #topBar {
  background: #111111;
  border-bottom-color: rgba(255,255,255,.08);
}

/* Brand */
.topbar-brand {
  display: flex; align-items: center; gap: 8px;
  text-decoration: none; flex-shrink: 0;
  font-weight: 700; font-size: 1.15rem; letter-spacing: -.01em;
}
.topbar-brand .brand-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, #4ED6C1, #007DFE);
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: .85rem;
}
body:not(.dark-mode) .topbar-brand { color: #0f172a; }
body.dark-mode .topbar-brand { color: #fff; }

/* Divider */
.topbar-divider {
  width: 1px; height: 22px; background: rgba(0,0,0,.1); flex-shrink: 0;
}
body.dark-mode .topbar-divider { background: rgba(255,255,255,.1); }

/* Page title area */
.topbar-title {
  font-size: .88rem; font-weight: 600; flex: 1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
body:not(.dark-mode) .topbar-title { color: #374151; }
body.dark-mode .topbar-title { color: #e2e8f0; }

/* Right controls */
.topbar-actions { display: flex; align-items: center; gap: 8px; margin-left: auto; }

/* Icon buttons */
.topbar-btn {
  width: 36px; height: 36px; border-radius: 9px; border: none;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; cursor: pointer; position: relative;
  transition: background .15s;
}
body:not(.dark-mode) .topbar-btn { background: #f1f5f9; color: #475569; }
body:not(.dark-mode) .topbar-btn:hover { background: #e2e8f0; }
body.dark-mode .topbar-btn { background: rgba(255,255,255,.07); color: #94a3b8; }
body.dark-mode .topbar-btn:hover { background: rgba(255,255,255,.12); }

/* Avatar button */
.topbar-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: linear-gradient(135deg, #4ED6C1, #007DFE);
  color: #fff; font-weight: 700; font-size: .75rem;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; border: 2px solid transparent;
  transition: border-color .15s;
  flex-shrink: 0; overflow: hidden;
}
.topbar-avatar:hover { border-color: #4ED6C1; }
.topbar-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

/* Dropdown menu */
.topbar-dropdown {
  position: absolute; top: calc(100% + 10px); right: 0;
  min-width: 200px; border-radius: 12px;
  padding: 6px;
  z-index: 2000;
  display: none;
}
body:not(.dark-mode) .topbar-dropdown {
  background: #fff; border: 1px solid #e2e8f0;
  box-shadow: 0 8px 32px rgba(0,0,0,.12);
}
body.dark-mode .topbar-dropdown {
  background: #1a1a1a; border: 1px solid rgba(255,255,255,.08);
  box-shadow: 0 8px 32px rgba(0,0,0,.4);
}
.topbar-dropdown.open { display: block; }
.topbar-dropdown-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; border-radius: 8px;
  text-decoration: none; font-size: .84rem; font-weight: 500;
  transition: background .12s; cursor: pointer; border: none; width: 100%;
  background: transparent; text-align: left;
}
body:not(.dark-mode) .topbar-dropdown-item { color: #374151; }
body:not(.dark-mode) .topbar-dropdown-item:hover { background: #f1f5f9; }
body.dark-mode .topbar-dropdown-item { color: #e2e8f0; }
body.dark-mode .topbar-dropdown-item:hover { background: rgba(255,255,255,.06); }
.topbar-dropdown-item.danger { color: #ef4444 !important; }
.topbar-dropdown-header {
  padding: 10px 12px 6px; font-size: .75rem; font-weight: 600;
  text-transform: uppercase; letter-spacing: .06em;
}
body:not(.dark-mode) .topbar-dropdown-header { color: #94a3b8; }
body.dark-mode .topbar-dropdown-header { color: #64748b; }
.topbar-dropdown hr { margin: 4px 0; border-color: rgba(0,0,0,.06); }
body.dark-mode .topbar-dropdown hr { border-color: rgba(255,255,255,.06); }

/* Notif badge */
.topbar-badge {
  position: absolute; top: 4px; right: 4px;
  width: 8px; height: 8px; border-radius: 50%;
  background: #ef4444; border: 2px solid #fff;
  display: none;
}
body.dark-mode .topbar-badge { border-color: #111111; }
.topbar-badge.visible { display: block; }

/* Notif panel */
#notifPanel {
  position: absolute; top: calc(100% + 10px); right: 0;
  width: min(360px, 95vw); border-radius: 14px;
  z-index: 2001; display: none;
  max-height: 480px; overflow: hidden;
  flex-direction: column;
}
body:not(.dark-mode) #notifPanel {
  background: #fff; border: 1px solid #e2e8f0;
  box-shadow: 0 8px 32px rgba(0,0,0,.12);
}
body.dark-mode #notifPanel {
  background: #1a1a1a; border: 1px solid rgba(255,255,255,.08);
  box-shadow: 0 8px 32px rgba(0,0,0,.4);
}
#notifPanel.open { display: flex; }
.notif-header {
  padding: 14px 16px 10px; font-weight: 700; font-size: .9rem;
  border-bottom: 1px solid rgba(0,0,0,.06); flex-shrink: 0;
  display: flex; align-items: center; justify-content: space-between;
}
body.dark-mode .notif-header { border-bottom-color: rgba(255,255,255,.06); }
.notif-body { overflow-y: auto; flex: 1; }
.notif-item {
  padding: 10px 16px; border-bottom: 1px solid rgba(0,0,0,.04);
  cursor: pointer; transition: background .12s;
}
.notif-item:hover { background: rgba(0,0,0,.02); }
body.dark-mode .notif-item:hover { background: rgba(255,255,255,.03); }
body.dark-mode .notif-item { border-bottom-color: rgba(255,255,255,.04); }
.notif-unread { background: rgba(78,214,193,.05); }

/* ── LEFT SIDEBAR ── */
#sidebar {
  position: fixed; left: 0; top: var(--topbar-h); bottom: 0;
  width: var(--sidebar-w); z-index: 1040;
  display: flex; flex-direction: column;
  transition: transform .25s cubic-bezier(.4,0,.2,1);
  overflow: hidden;
  background: #ffffff;
  border-right: 1px solid #e2e8f0;
}
body.dark-mode #sidebar {
  background: #111111;
  border-right: 1px solid rgba(255,255,255,.06);
}

.sidebar-scroll {
  flex: 1; overflow-y: auto; padding: 10px 8px;
  scrollbar-width: thin; scrollbar-color: rgba(0,0,0,.1) transparent;
}
body.dark-mode .sidebar-scroll {
  scrollbar-color: rgba(255,255,255,.08) transparent;
}

/* Nav links */
.nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; border-radius: 9px;
  font-size: .84rem; font-weight: 500;
  transition: background .15s, color .15s;
  white-space: nowrap;
  color: #475569;
}
body.dark-mode .nav-link { color: #94a3b8; }
.nav-link:hover { background: rgba(0,0,0,.05); color: #0f172a; }
body.dark-mode .nav-link:hover { background: rgba(255,255,255,.05); color: #e2e8f0; }
.nav-link.active {
  background: var(--nav-active-bg);
  color: var(--nav-active-color);
  font-weight: 600;
  border-left: 3px solid var(--nav-active-color);
  padding-left: 9px;
}
body:not(.dark-mode) .nav-link.active { border-left-color: #0d9488; color: #0d9488; background: rgba(13,148,136,.08); }
.nav-link i { width: 18px; text-align: center; font-size: .88rem; flex-shrink: 0; }

/* Nav section label */
.nav-section-label {
  font-size: .67rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .09em; padding: 14px 12px 4px;
  color: #94a3b8;
}
body.dark-mode .nav-section-label { color: #475569; }

/* Sidebar footer */
.sidebar-footer {
  padding: 10px 8px; border-top: 1px solid #e2e8f0; flex-shrink: 0;
}
body.dark-mode .sidebar-footer { border-top: 1px solid rgba(255,255,255,.06); }

/* Dark mode toggle */
.dark-toggle {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 12px; border-radius: 9px; cursor: pointer;
  font-size: .84rem; font-weight: 500; border: none; width: 100%;
  transition: background .15s;
  background: rgba(0,0,0,.04); color: #475569;
}
body.dark-mode .dark-toggle { background: rgba(255,255,255,.07); color: #94a3b8; }
.dark-toggle:hover { background: rgba(0,0,0,.08); }
body.dark-mode .dark-toggle:hover { background: rgba(255,255,255,.12); }

/* Toggle pill */
.dark-toggle-pill {
  width: 32px; height: 18px; border-radius: 9px; position: relative;
  transition: background .2s; flex-shrink: 0;
}
body:not(.dark-mode) .dark-toggle-pill { background: #cbd5e1; }
body.dark-mode .dark-toggle-pill { background: #4ED6C1; }
.dark-toggle-pill::after {
  content: ''; position: absolute; top: 2px; left: 2px;
  width: 14px; height: 14px; border-radius: 50%; background: #fff;
  transition: transform .2s;
}
body.dark-mode .dark-toggle-pill::after { transform: translateX(14px); }

/* ── MAIN CONTENT ── */
#mainContent {
  margin-left: var(--sidebar-w);
  padding-top: var(--topbar-h);
  min-height: 100vh;
}

/* ── OVERLAY ── */
#sidebarOverlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.5); z-index: 1039;
  backdrop-filter: blur(2px);
}
#sidebarOverlay.active { display: block; }

/* ── MOBILE BOTTOM NAV ── */
#mobileBottomNav {
  display: none; position: fixed; bottom: 0; left: 0; right: 0;
  height: 60px; z-index: 1038;
  padding: 4px 8px 4px;
}
body:not(.dark-mode) #mobileBottomNav { background: #fff; border-top: 1px solid #e2e8f0; }
body.dark-mode #mobileBottomNav { background: #111111; border-top: 1px solid rgba(255,255,255,.07); }
#mobileBottomNav .mnav-item {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 2px;
  text-decoration: none; font-size: .62rem; font-weight: 500;
  padding: 2px 4px; border-radius: 8px; transition: color .15s;
}
body:not(.dark-mode) #mobileBottomNav .mnav-item { color: #94a3b8; }
body.dark-mode #mobileBottomNav .mnav-item { color: #64748b; }
#mobileBottomNav .mnav-item i { font-size: 1.1rem; }
body:not(.dark-mode) #mobileBottomNav .mnav-item.active,
body:not(.dark-mode) #mobileBottomNav .mnav-item:hover { color: #0d9488; }
body.dark-mode #mobileBottomNav .mnav-item.active,
body.dark-mode #mobileBottomNav .mnav-item:hover { color: #4ED6C1; }

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
  #sidebar { transform: translateX(-100%); top: 0; }
  #sidebar.open { transform: translateX(0); }
  #mainContent { margin-left: 0 !important; padding-bottom: 68px !important; }
  #mobileBottomNav { display: flex !important; }
  .topbar-title { display: none; }
  .topbar-divider { display: none; }
}
@media (min-width: 769px) {
  #sidebarOverlay { display: none !important; }
  #mobileBottomNav { display: none !important; }
}
</style>
</head>
<?php $initialDark = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'true'; ?>
<body class="<?php echo $initialDark ? 'dark-mode' : ''; ?>">

<div id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ══════════════ TOP BAR ══════════════ -->
<header id="topBar">

  <!-- Hamburger (mobile only) -->
  <button class="topbar-btn d-md-none" onclick="toggleSidebar()" aria-label="Menu" id="sidebarToggle">
    <i class="fas fa-bars" id="toggleIcon"></i>
  </button>

  <!-- Brand -->
  <a href="<?php echo $base_url; ?>" class="topbar-brand">
    <div class="brand-icon" style="background:none;border:none;box-shadow:none;padding:0;overflow:hidden;"><img src="<?php echo $base_url; ?>assets/icon-192.png" alt="StayWise" style="width:100%;height:100%;object-fit:cover;border-radius:8px;"></div>
    <span><span style="color:#4ED6C1;">Stay</span><span>Wise</span></span>
  </a>

  <div class="topbar-divider"></div>

  <!-- Page title -->
  <span class="topbar-title"><?php echo isset($page_title) ? htmlspecialchars($page_title) : ''; ?></span>

  <!-- Right actions -->
  <div class="topbar-actions">

    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Notifications -->
    <div style="position:relative;">
      <button class="topbar-btn" id="notifBellBtn" onclick="toggleNotifPanel()" title="Notifications">
        <i class="fas fa-bell"></i>
        <span class="topbar-badge" id="notifBadge"></span>
      </button>
      <div id="notifPanel">
        <div class="notif-header">
          <span><i class="fas fa-bell me-2" style="color:#4ED6C1;"></i>Notifications</span>
          <button onclick="markAllNotificationsRead()" style="font-size:.75rem;background:none;border:none;color:#4ED6C1;cursor:pointer;font-weight:600;">Mark all read</button>
        </div>
        <div class="notif-body" id="notifList">
          <p class="text-center py-4 mb-0" id="notifEmpty" style="font-size:.84rem;color:#94a3b8;">No notifications</p>
        </div>
      </div>
    </div>

    <!-- User avatar dropdown -->
    <div style="position:relative;">
      <div class="topbar-avatar" id="avatarBtn" onclick="toggleAvatarDropdown()" title="Account">
        <?php if ($_headerPhoto): ?>
          <img src="<?php echo htmlspecialchars($_headerPhoto); ?>" alt="avatar">
        <?php else: ?>
          <?php echo $_headerInitials; ?>
        <?php endif; ?>
      </div>
      <div class="topbar-dropdown" id="avatarDropdown">
        <div class="topbar-dropdown-header"><?php echo htmlspecialchars($_headerName); ?></div>
        <hr>
        <?php if ($userRole === 'tenant'): ?>
        <a href="<?php echo $base_url; ?>tenant/profile.php" class="topbar-dropdown-item">
          <i class="fas fa-user" style="color:#4ED6C1;width:16px;"></i> My Profile
        </a>
        <?php else: ?>
        <a href="<?php echo $base_url; ?>admin/profile.php" class="topbar-dropdown-item">
          <i class="fas fa-user" style="color:#4ED6C1;width:16px;"></i> My Profile
        </a>
        <?php endif; ?>
        <button class="topbar-dropdown-item" onclick="applyDarkModeState(!document.body.classList.contains('dark-mode'))">
          <i class="fas fa-moon" style="color:#94a3b8;width:16px;" id="dropdownDarkIcon"></i>
          <span id="dropdownDarkLabel">Dark Mode</span>
        </button>
        <hr>
        <a href="<?php echo $base_url; ?>logout.php" class="topbar-dropdown-item danger">
          <i class="fas fa-sign-out-alt" style="width:16px;"></i> Logout
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</header>

<!-- ══════════════ SIDEBAR ══════════════ -->
<nav id="sidebar">
  <div class="sidebar-scroll">
    <ul class="nav flex-column mb-2">
      <?php if (isset($_SESSION['user_id'])): ?>
        <?php if ($userRole === 'admin'): ?>
          <li><span class="nav-section-label">Overview</span></li>
          <?php if ($adminRoleNav === 'super_admin'): ?>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='super_dashboard.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/super_dashboard.php" onclick="closeSidebar()"><i class="fas fa-house"></i>Super Dashboard</a></li>
          <?php else: ?>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='dashboard.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/dashboard.php" onclick="closeSidebar()"><i class="fas fa-tachometer-alt"></i>Dashboard</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payment_monitoring.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/payment_monitoring.php" onclick="closeSidebar()"><i class="fas fa-chart-line"></i>Payment Tracker</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='reports.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/reports.php" onclick="closeSidebar()"><i class="fas fa-file-alt"></i>Reports</a></li>

          <li><span class="nav-section-label">Manage</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='tenants.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/tenants.php" onclick="closeSidebar()"><i class="fas fa-users"></i>Tenants</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payments.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/payments.php" onclick="closeSidebar()"><i class="fas fa-credit-card"></i>Payments</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payment_settings.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/payment_settings.php" onclick="closeSidebar()"><i class="fas fa-wallet"></i>Payment Settings</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='complaints.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/complaints.php" onclick="closeSidebar()"><i class="fas fa-exclamation-triangle"></i>Complaints</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='announcements.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/announcements.php" onclick="closeSidebar()"><i class="fas fa-bullhorn"></i>Announcements</a></li>

          <li><span class="nav-section-label">Intelligence</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='ai_insights.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/ai_insights.php" onclick="closeSidebar()"><i class="fas fa-brain"></i>AI Insights</a></li>

          <?php if ($adminRoleNav === 'super_admin'): ?>
          <li><span class="nav-section-label">System</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='admin_management.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/admin_management.php" onclick="closeSidebar()"><i class="fas fa-users-cog"></i>Admin Management</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='role_manager.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/role_manager.php" onclick="closeSidebar()"><i class="fas fa-user-shield"></i>Role Manager</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='admin_logs.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/admin_logs.php" onclick="closeSidebar()"><i class="fas fa-clipboard-list"></i>Admin Logs</a></li>

          <?php endif; ?>

          <li><span class="nav-section-label">Account</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='profile.php'?'active':''; ?>" href="<?php echo $base_url; ?>admin/profile.php" onclick="closeSidebar()"><i class="fas fa-user-edit"></i>Profile</a></li>

        <?php else: ?>
          <li><span class="nav-section-label">Home</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='dashboard.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/dashboard.php" onclick="closeSidebar()"><i class="fas fa-tachometer-alt"></i>Dashboard</a></li>

          <li><span class="nav-section-label">My Unit</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payments.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/payments.php" onclick="closeSidebar()"><i class="fas fa-credit-card"></i>Payments</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='complaints.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/complaints.php" onclick="closeSidebar()"><i class="fas fa-exclamation-triangle"></i>Complaints</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='announcements.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/announcements.php" onclick="closeSidebar()"><i class="fas fa-bullhorn"></i>Announcements</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='chatbot.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/chatbot.php" onclick="closeSidebar()"><i class="fas fa-robot"></i>AI Assistant</a></li>

          <li><span class="nav-section-label">Account</span></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='profile.php'?'active':''; ?>" href="<?php echo $base_url; ?>tenant/profile.php" onclick="closeSidebar()"><i class="fas fa-user"></i>Profile</a></li>
        <?php endif; ?>
      <?php endif; ?>
    </ul>
  </div>

  <!-- Dark mode toggle in sidebar -->
  <div class="sidebar-footer">
    <button class="dark-toggle" onclick="applyDarkModeState(!document.body.classList.contains('dark-mode'))">
      <span style="display:flex;align-items:center;gap:8px;">
        <i class="fas fa-moon" style="font-size:.85rem;"></i>
        <span id="sidebarDarkLabel">Dark Mode</span>
      </span>
      <div class="dark-toggle-pill" id="darkTogglePill"></div>
    </button>
  </div>
</nav>

<!-- MOBILE BOTTOM NAV -->
<?php if (isset($_SESSION['user_id'])): ?>
<nav id="mobileBottomNav">
  <?php if ($userRole === 'admin'): ?>
    <a href="<?php echo $base_url; ?>admin/<?php echo $adminRoleNav==='super_admin'?'super_dashboard':'dashboard'; ?>.php" class="mnav-item <?php echo in_array($currentPage,['dashboard.php','super_dashboard.php'])?'active':''; ?>"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="<?php echo $base_url; ?>admin/tenants.php" class="mnav-item <?php echo $currentPage==='tenants.php'?'active':''; ?>"><i class="fas fa-users"></i><span>Tenants</span></a>
    <a href="<?php echo $base_url; ?>admin/payments.php" class="mnav-item <?php echo $currentPage==='payments.php'?'active':''; ?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
    <a href="<?php echo $base_url; ?>admin/complaints.php" class="mnav-item <?php echo $currentPage==='complaints.php'?'active':''; ?>"><i class="fas fa-exclamation-triangle"></i><span>Issues</span></a>
    <a href="#" class="mnav-item" onclick="toggleSidebar();return false;"><i class="fas fa-ellipsis-h"></i><span>More</span></a>
  <?php else: ?>
    <a href="<?php echo $base_url; ?>tenant/dashboard.php" class="mnav-item <?php echo $currentPage==='dashboard.php'?'active':''; ?>"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="<?php echo $base_url; ?>tenant/payments.php" class="mnav-item <?php echo $currentPage==='payments.php'?'active':''; ?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
    <a href="<?php echo $base_url; ?>tenant/complaints.php" class="mnav-item <?php echo $currentPage==='complaints.php'?'active':''; ?>"><i class="fas fa-exclamation-triangle"></i><span>Issues</span></a>
    <a href="<?php echo $base_url; ?>tenant/announcements.php" class="mnav-item <?php echo $currentPage==='announcements.php'?'active':''; ?>"><i class="fas fa-bullhorn"></i><span>News</span></a>
    <a href="<?php echo $base_url; ?>tenant/profile.php" class="mnav-item <?php echo $currentPage==='profile.php'?'active':''; ?>"><i class="fas fa-user"></i><span>Profile</span></a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<!-- MAIN CONTENT WRAPPER -->
<div class="flex-grow-1" id="mainContent">

<?php if (isset($_SESSION['user_id'])): ?>
<script>
// ── Notifications ──────────────────────────────────────────────
let notifPanelOpen = false;
function toggleNotifPanel() {
  notifPanelOpen = !notifPanelOpen;
  document.getElementById('notifPanel').classList.toggle('open', notifPanelOpen);
  if (notifPanelOpen) { loadNotifications(); markAllNotificationsRead(); }
}
document.addEventListener('click', function(e) {
  const bellWrap = document.getElementById('notifBellBtn')?.closest('div');
  if (notifPanelOpen && bellWrap && !bellWrap.contains(e.target)) {
    notifPanelOpen = false;
    document.getElementById('notifPanel').classList.remove('open');
  }
});
function loadNotifications() {
  fetch('<?php echo $base_url; ?>api/notifications.php').then(r => r.json()).then(data => {
    const list = document.getElementById('notifList'), empty = document.getElementById('notifEmpty');
    if (!data.notifications || !data.notifications.length) {
      list.innerHTML = ''; list.appendChild(empty); empty.style.display = 'block'; return;
    }
    empty.style.display = 'none';
    list.innerHTML = data.notifications.map(n => `
      <div class="notif-item${n.is_read==0?' notif-unread':''}" onclick="readNotification(${n.notification_id},'${n.action_url||''}')">
        <div style="display:flex;align-items:flex-start;gap:10px;">
          <i class="fas fa-bell mt-1" style="color:#4ED6C1;font-size:.8rem;flex-shrink:0;"></i>
          <div>
            <div style="font-weight:600;font-size:.83rem;">${escHtml(n.title)}</div>
            <div style="font-size:.78rem;color:#94a3b8;margin-top:1px;">${escHtml(n.message)}</div>
            <div style="font-size:.72rem;color:#cbd5e1;margin-top:2px;">${n.time_ago||''}</div>
          </div>
        </div>
      </div>`).join('');
  }).catch(() => {});
}
function readNotification(id, url) {
  fetch('<?php echo $base_url; ?>api/notifications.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'mark_read',notification_id:id}) })
    .then(() => { fetchNotifCount(); if (url) window.location.href = url; });
}
function markAllNotificationsRead() {
  fetch('<?php echo $base_url; ?>api/notifications.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'mark_all_read'}) })
    .then(() => { fetchNotifCount(); loadNotifications(); });
}
function fetchNotifCount() {
  fetch('<?php echo $base_url; ?>api/notifications.php?count=1').then(r => r.json()).then(data => {
    const b = document.getElementById('notifBadge');
    if (data.unread > 0) { b.textContent = data.unread > 99 ? '99+' : data.unread; b.classList.add('visible'); b.style.width=''; b.style.height=''; }
    else b.classList.remove('visible');
  }).catch(() => {});
}
function escHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
fetchNotifCount(); setInterval(fetchNotifCount, 60000);

// ── Avatar dropdown ──────────────────────────────────────────────
let avatarDropOpen = false;
function toggleAvatarDropdown() {
  avatarDropOpen = !avatarDropOpen;
  document.getElementById('avatarDropdown').classList.toggle('open', avatarDropOpen);
}
document.addEventListener('click', function(e) {
  const avatarWrap = document.getElementById('avatarBtn')?.closest('div');
  if (avatarDropOpen && avatarWrap && !avatarWrap.contains(e.target)) {
    avatarDropOpen = false;
    document.getElementById('avatarDropdown').classList.remove('open');
  }
});
</script>
<?php endif; ?>

<script>
// ── Sidebar ──────────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
let sidebarOpen = false;
function openSidebar()  { sidebarOpen=true;  sidebar.classList.add('open');    overlay.classList.add('active');    document.getElementById('toggleIcon').className='fas fa-times'; document.body.style.overflow='hidden'; }
function closeSidebar() { sidebarOpen=false; sidebar.classList.remove('open'); overlay.classList.remove('active'); document.getElementById('toggleIcon').className='fas fa-bars';  document.body.style.overflow=''; }
function toggleSidebar() { sidebarOpen ? closeSidebar() : openSidebar(); }
sidebar.addEventListener('touchstart', e => { window._txStart = e.touches[0].clientX; }, {passive:true});
sidebar.addEventListener('touchend',   e => { if (e.changedTouches[0].clientX - (window._txStart||0) < -60) closeSidebar(); }, {passive:true});

// ── Dark mode ────────────────────────────────────────────────────
function applyDarkModeState(isDark) {
  document.body.classList.toggle('dark-mode', isDark);
  sidebar.classList.toggle('dark-mode', isDark);
  // Update labels
  const sLabel = document.getElementById('sidebarDarkLabel');
  const dLabel = document.getElementById('dropdownDarkLabel');
  const dIcon  = document.getElementById('dropdownDarkIcon');
  if (sLabel) sLabel.textContent = isDark ? 'Light Mode' : 'Dark Mode';
  if (dLabel) dLabel.textContent = isDark ? 'Light Mode' : 'Dark Mode';
  if (dIcon)  dIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
  if (dIcon)  dIcon.style.color = isDark ? '#f59e0b' : '#94a3b8';
  try { localStorage.setItem('darkMode', String(isDark)); } catch(_) {}
  try { document.cookie = 'darkMode=' + (isDark?'true':'false') + '; path=/; max-age=31536000'; } catch(_) {}
}
window.addEventListener('DOMContentLoaded', function() {
  const isDark = document.body.classList.contains('dark-mode');
  applyDarkModeState(isDark);
});
</script>
