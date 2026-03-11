<?php
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
<!-- Favicon & PWA -->
<link rel="icon" type="image/svg+xml" href="<?php echo $base_url; ?>assets/favicon.svg"/>
<link rel="alternate icon" type="image/png" href="<?php echo $base_url; ?>assets/favicon-32.png"/>
<link rel="apple-touch-icon" href="<?php echo $base_url; ?>assets/icon-192.png"/>
<link rel="manifest" href="<?php echo $base_url; ?>site.webmanifest"/>
<meta name="theme-color" content="#4ED6C1"/>
<style>
/* ── Sidebar overlay ── */
#sidebarOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1039;backdrop-filter:blur(2px)}
#sidebarOverlay.active{display:block}
/* ── Sidebar ── */
.sidebar-custom{position:fixed;left:0;top:0;bottom:0;width:240px;z-index:1040;display:flex;flex-direction:column;transition:transform .28s cubic-bezier(.4,0,.2,1);overflow:hidden}
.sidebar-scroll{flex:1;overflow-y:auto;min-height:0;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.12) transparent}
.sidebar-scroll::-webkit-scrollbar{width:4px}
.sidebar-scroll::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12);border-radius:4px}
/* Desktop */
@media(min-width:769px){
  .sidebar-custom{transform:translateX(0)!important}
  #mainContent{margin-left:240px!important}
  #sidebarToggle{display:none!important}
  #sidebarOverlay{display:none!important}
  #mobileBottomNav{display:none!important}
}
/* Mobile */
@media(max-width:768px){
  .sidebar-custom{transform:translateX(-100%)}
  .sidebar-custom.open{transform:translateX(0)}
  #mainContent{margin-left:0!important;padding-bottom:70px!important}
  .admin-ui,.dashboard-ui{transform:none!important;width:100%!important;zoom:1!important}
  .container,.container-fluid{padding-left:12px!important;padding-right:12px!important}
  h1{font-size:1.35rem!important} h2{font-size:1.15rem!important}
  .table-responsive{font-size:.82rem}
}
/* Hamburger */
#sidebarToggle{position:fixed;top:12px;left:12px;z-index:1050;width:40px;height:40px;border-radius:10px;border:none;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 2px 8px rgba(0,0,0,.3);cursor:pointer}
body.dark-mode #sidebarToggle{background:#0D1B2A;color:#4ED6C1}
body:not(.dark-mode) #sidebarToggle{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
/* Notif bell */
#notifBellWrap{position:fixed;top:12px;right:12px;z-index:1050}
#notifPanel{width:min(360px,95vw);right:0;left:auto;border-radius:12px}
/* Mobile bottom nav */
#mobileBottomNav{display:none;position:fixed;bottom:0;left:0;right:0;height:62px;z-index:1038;padding:6px 0 4px}
body.dark-mode #mobileBottomNav{background:#1B263B;border-top:1px solid #2d3748}
body:not(.dark-mode) #mobileBottomNav{background:#f1f5f9;border-top:1px solid #cbd5e1}
#mobileBottomNav .mnav-item{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;text-decoration:none;font-size:.62rem;font-weight:500;padding:2px 4px;border-radius:8px;transition:color .2s}
body.dark-mode #mobileBottomNav .mnav-item{color:#94a3b8}
body:not(.dark-mode) #mobileBottomNav .mnav-item{color:#64748b}
#mobileBottomNav .mnav-item i{font-size:1.15rem}
body.dark-mode #mobileBottomNav .mnav-item.active,body.dark-mode #mobileBottomNav .mnav-item:hover{color:#4ED6C1}
body:not(.dark-mode) #mobileBottomNav .mnav-item.active,body:not(.dark-mode) #mobileBottomNav .mnav-item:hover{color:#0d9488}
@media(max-width:768px){#mobileBottomNav{display:flex!important}}
/* Topbar spacer */
.mobile-topbar-spacer{height:0}
@media(max-width:768px){.mobile-topbar-spacer{height:58px}}
</style>
</head>
<?php $initialDark = isset($_COOKIE['darkMode']) && $_COOKIE['darkMode'] === 'true'; ?>
<body class="<?php echo $initialDark ? 'dark-mode' : ''; ?>">

<div id="sidebarOverlay" onclick="closeSidebar()"></div>

<button id="sidebarToggle" onclick="toggleSidebar()" aria-label="Menu">
  <i class="fas fa-bars" id="toggleIcon"></i>
</button>

<?php if (isset($_SESSION['user_id'])): ?>
<div id="notifBellWrap">
  <button class="btn btn-outline-secondary position-relative" id="notifBellBtn" onclick="toggleNotifPanel()" title="Notifications">
    <i class="fas fa-bell"></i>
    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" id="notifBadge">0</span>
  </button>
  <div class="card shadow-lg position-absolute d-none" id="notifPanel" style="max-height:500px;z-index:2002;">
    <div class="card-header py-2">
      <strong><i class="fas fa-bell me-1"></i> Notifications</strong>
    </div>
    <div class="card-body p-0" style="max-height:400px;overflow-y:auto;" id="notifList">
      <p class="text-center text-muted py-4 mb-0" id="notifEmpty">No notifications</p>
    </div>
    <div class="card-footer text-center py-2 d-none" id="notifFooter">
      <a href="<?php echo $userRole==='admin' ? $base_url.'admin/ai_insights.php' : $base_url.'tenant/dashboard.php'; ?>" class="small">View all</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<nav class="sidebar-custom" id="sidebar">
  <div class="sidebar-scroll">
    <a class="navbar-brand d-block py-3 px-4 mb-1 border-bottom" href="<?php echo $base_url; ?>">
      <i class="fas fa-home me-2 sidebar-home-icon"></i><span class="brand-stay">Stay</span><span class="brand-wise">Wise</span>
    </a>
    <div class="px-3 mb-2">
      <button class="btn btn-sm btn-dark w-100" id="darkModeToggle" onclick="toggleDarkMode()">Dark Mode</button>
    </div>
    <ul class="nav flex-column mb-2 px-2">
      <?php if (isset($_SESSION['user_id'])): ?>
        <?php if ($userRole === 'admin'): ?>
          <?php if ($adminRoleNav === 'super_admin'): ?>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='super_dashboard.php'?'active':'';?>" href="<?php echo $base_url;?>admin/super_dashboard.php" onclick="closeSidebar()"><i class="fas fa-house me-2"></i>Super Dashboard</a></li>
          <?php else: ?>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='dashboard.php'?'active':'';?>" href="<?php echo $base_url;?>admin/dashboard.php" onclick="closeSidebar()"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payment_monitoring.php'?'active':'';?>" href="<?php echo $base_url;?>admin/payment_monitoring.php" onclick="closeSidebar()"><i class="fas fa-chart-line me-2"></i>Payment Tracker</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='reports.php'?'active':'';?>" href="<?php echo $base_url;?>admin/reports.php" onclick="closeSidebar()"><i class="fas fa-file-alt me-2"></i>Reports</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='profile.php'?'active':'';?>" href="<?php echo $base_url;?>admin/profile.php" onclick="closeSidebar()"><i class="fas fa-user-edit me-2"></i>Profile</a></li>
          <?php if ($adminRoleNav === 'super_admin'): ?>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='admin_logs.php'?'active':'';?>" href="<?php echo $base_url;?>admin/admin_logs.php" onclick="closeSidebar()"><i class="fas fa-clipboard-list me-2"></i>Admin Logs</a></li>
          <?php endif; ?>
          <?php if ($adminRoleNav === 'super_admin'): ?>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='admin_management.php'?'active':'';?>" href="<?php echo $base_url;?>admin/admin_management.php" onclick="closeSidebar()"><i class="fas fa-users-cog me-2"></i>Admin Management</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='role_manager.php'?'active':'';?>" href="<?php echo $base_url;?>admin/role_manager.php" onclick="closeSidebar()"><i class="fas fa-user-shield me-2"></i>Role Manager</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $currentPage==='system_settings.php'?'active':'';?>" href="<?php echo $base_url;?>admin/system_settings.php" onclick="closeSidebar()"><i class="fas fa-cogs me-2"></i>System Settings</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='tenants.php'?'active':'';?>" href="<?php echo $base_url;?>admin/tenants.php" onclick="closeSidebar()"><i class="fas fa-users me-2"></i>Tenants</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payments.php'?'active':'';?>" href="<?php echo $base_url;?>admin/payments.php" onclick="closeSidebar()"><i class="fas fa-credit-card me-2"></i>Payments</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payment_settings.php'?'active':'';?>" href="<?php echo $base_url;?>admin/payment_settings.php" onclick="closeSidebar()"><i class="fas fa-wallet me-2"></i>Payment Settings</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='complaints.php'?'active':'';?>" href="<?php echo $base_url;?>admin/complaints.php" onclick="closeSidebar()"><i class="fas fa-exclamation-triangle me-2"></i>Complaints</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='announcements.php'?'active':'';?>" href="<?php echo $base_url;?>admin/announcements.php" onclick="closeSidebar()"><i class="fas fa-bullhorn me-2"></i>Announcements</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='ai_insights.php'?'active':'';?>" href="<?php echo $base_url;?>admin/ai_insights.php" onclick="closeSidebar()"><i class="fas fa-brain me-2"></i>AI Insights</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='dashboard.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/dashboard.php" onclick="closeSidebar()"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='profile.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/profile.php" onclick="closeSidebar()"><i class="fas fa-user me-2"></i>Profile</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='payments.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/payments.php" onclick="closeSidebar()"><i class="fas fa-credit-card me-2"></i>Payments</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='complaints.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/complaints.php" onclick="closeSidebar()"><i class="fas fa-exclamation-triangle me-2"></i>Complaints</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='announcements.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/announcements.php" onclick="closeSidebar()"><i class="fas fa-bullhorn me-2"></i>Announcements</a></li>
          <li class="nav-item"><a class="nav-link <?php echo $currentPage==='chatbot.php'?'active':'';?>" href="<?php echo $base_url;?>tenant/chatbot.php" onclick="closeSidebar()"><i class="fas fa-robot me-2"></i>AI Assistant</a></li>
        <?php endif; ?>
      <?php endif; ?>
    </ul>
  </div>
  <div class="border-top p-3 flex-shrink-0">
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="dropup">
      <a class="d-flex align-items-center text-decoration-none dropdown-toggle" href="#" id="sidebarDropdown" data-bs-toggle="dropdown" style="color:inherit;">
        <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?php echo $base_url;?>logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</nav>

<!-- MOBILE BOTTOM NAV -->
<?php if (isset($_SESSION['user_id'])): ?>
<nav id="mobileBottomNav">
  <?php if ($userRole === 'admin'): ?>
    <a href="<?php echo $base_url;?>admin/<?php echo $adminRoleNav==='super_admin'?'super_dashboard':'dashboard';?>.php" class="mnav-item <?php echo in_array($currentPage,['dashboard.php','super_dashboard.php'])?'active':'';?>"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="<?php echo $base_url;?>admin/tenants.php" class="mnav-item <?php echo $currentPage==='tenants.php'?'active':'';?>"><i class="fas fa-users"></i><span>Tenants</span></a>
    <a href="<?php echo $base_url;?>admin/payments.php" class="mnav-item <?php echo $currentPage==='payments.php'?'active':'';?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
    <a href="<?php echo $base_url;?>admin/complaints.php" class="mnav-item <?php echo $currentPage==='complaints.php'?'active':'';?>"><i class="fas fa-exclamation-triangle"></i><span>Complaints</span></a>
    <a href="#" class="mnav-item" onclick="toggleSidebar();return false;"><i class="fas fa-ellipsis-h"></i><span>More</span></a>
  <?php else: ?>
    <a href="<?php echo $base_url;?>tenant/dashboard.php" class="mnav-item <?php echo $currentPage==='dashboard.php'?'active':'';?>"><i class="fas fa-home"></i><span>Home</span></a>
    <a href="<?php echo $base_url;?>tenant/payments.php" class="mnav-item <?php echo $currentPage==='payments.php'?'active':'';?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
    <a href="<?php echo $base_url;?>tenant/complaints.php" class="mnav-item <?php echo $currentPage==='complaints.php'?'active':'';?>"><i class="fas fa-exclamation-triangle"></i><span>Complaints</span></a>
    <a href="<?php echo $base_url;?>tenant/announcements.php" class="mnav-item <?php echo $currentPage==='announcements.php'?'active':'';?>"><i class="fas fa-bullhorn"></i><span>News</span></a>
    <a href="<?php echo $base_url;?>tenant/chatbot.php" class="mnav-item <?php echo $currentPage==='chatbot.php'?'active':'';?>"><i class="fas fa-robot"></i><span>AI Chat</span></a>
  <?php endif; ?>
</nav>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="flex-grow-1" id="mainContent">
<div class="mobile-topbar-spacer"></div>

<?php if (isset($_SESSION['user_id'])): ?>
<script>
let notifPanelOpen=false;
function toggleNotifPanel(){const p=document.getElementById('notifPanel');notifPanelOpen=!notifPanelOpen;p.classList.toggle('d-none',!notifPanelOpen);if(notifPanelOpen){loadNotifications();markAllNotificationsRead();}}
document.addEventListener('click',function(e){const w=document.getElementById('notifBellWrap');if(notifPanelOpen&&w&&!w.contains(e.target)){notifPanelOpen=false;document.getElementById('notifPanel').classList.add('d-none');}});
function loadNotifications(){fetch('<?php echo $base_url;?>api/notifications.php').then(r=>r.json()).then(data=>{const list=document.getElementById('notifList'),empty=document.getElementById('notifEmpty'),footer=document.getElementById('notifFooter');if(!data.notifications||!data.notifications.length){list.innerHTML='';list.appendChild(empty);empty.classList.remove('d-none');footer.classList.add('d-none');return;}empty.classList.add('d-none');footer.classList.remove('d-none');list.innerHTML=data.notifications.map(n=>`<div class="notif-item px-3 py-2 border-bottom${n.is_read==0?' notif-unread':''}" style="cursor:pointer" onclick="readNotification(${n.notification_id},'${n.action_url||''}')"><div class="d-flex align-items-start"><i class="fas fa-bell me-2 mt-1" style="color:#4ED6C1"></i><div><div class="fw-semibold small">${escHtml(n.title)}</div><div class="small text-muted text-truncate">${escHtml(n.message)}</div><small class="text-muted">${n.time_ago||''}</small></div></div></div>`).join('');}).catch(()=>{});}
function readNotification(id,url){fetch('<?php echo $base_url;?>api/notifications.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_read',notification_id:id})}).then(()=>{fetchNotifCount();if(url)window.location.href=url;});}
function markAllNotificationsRead(){fetch('<?php echo $base_url;?>api/notifications.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_all_read'})}).then(()=>{fetchNotifCount();loadNotifications();});}
function fetchNotifCount(){fetch('<?php echo $base_url;?>api/notifications.php?count=1').then(r=>r.json()).then(data=>{const b=document.getElementById('notifBadge');if(data.unread>0){b.textContent=data.unread>99?'99+':data.unread;b.classList.remove('d-none');}else b.classList.add('d-none');}).catch(()=>{});}
function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
fetchNotifCount();setInterval(fetchNotifCount,60000);
</script>
<?php endif; ?>

<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),toggleIcon=document.getElementById('toggleIcon');
let sidebarOpen=false;
function openSidebar(){sidebarOpen=true;sidebar.classList.add('open');overlay.classList.add('active');toggleIcon.className='fas fa-times';document.body.style.overflow='hidden';}
function closeSidebar(){sidebarOpen=false;sidebar.classList.remove('open');overlay.classList.remove('active');toggleIcon.className='fas fa-bars';document.body.style.overflow='';}
function toggleSidebar(){sidebarOpen?closeSidebar():openSidebar();}
let txStart=0;
sidebar.addEventListener('touchstart',e=>{txStart=e.touches[0].clientX;},{passive:true});
sidebar.addEventListener('touchend',e=>{if(e.changedTouches[0].clientX-txStart<-60)closeSidebar();},{passive:true});
(function(){try{const sc=sidebar.querySelector('.sidebar-scroll'),sv=sessionStorage.getItem('sidebarScroll');if(sc&&sv)sc.scrollTop=parseInt(sv,10)||0;}catch(_){}})();

function applyDarkModeState(isDark){
    document.body.classList.toggle('dark-mode',isDark);
    sidebar.classList.toggle('dark-mode',isDark);
    const btn=document.getElementById('darkModeToggle');
    if(btn){btn.textContent=isDark?'Light Mode':'Dark Mode';btn.className=isDark?'btn btn-sm btn-light w-100':'btn btn-sm btn-dark w-100';}
    try{localStorage.setItem('darkMode',String(isDark));}catch(_){}
    try{document.cookie='darkMode='+(isDark?'true':'false')+'; path=/; max-age=31536000';}catch(_){}
}
function toggleDarkMode(){applyDarkModeState(!document.body.classList.contains('dark-mode'));}
window.addEventListener('DOMContentLoaded',function(){applyDarkModeState(document.body.classList.contains('dark-mode'));});
</script>
