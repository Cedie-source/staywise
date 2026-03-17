<?php
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'tenant') {
    header("Location: ../index.php");
    exit();
}

$base_url = '/';

if (!defined('STAYWISE_ROOT')) define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';
ai_ensure_tables($conn);

$userId = (int)$_SESSION['user_id'];
$tenantAlerts = [];
$tenantPredictions = [];

// Handle clear chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_chat'])) {
    if (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $del = $conn->prepare("DELETE FROM chat_history WHERE user_id = ?");
        $del->bind_param('i', $userId);
        $del->execute();
        $del->close();
    }
    header("Location: chatbot.php");
    exit();
}

$nStmt = $conn->prepare(
    "SELECT title, message, type, priority, created_at FROM ai_notifications
     WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 3"
);
$nStmt->bind_param('i', $userId);
$nStmt->execute();
$nRes = $nStmt->get_result();
while ($row = $nRes->fetch_assoc()) $tenantAlerts[] = $row;
$nStmt->close();

$tStmt = $conn->prepare("SELECT unit_number FROM tenants WHERE user_id = ?");
$tStmt->bind_param('i', $userId);
$tStmt->execute();
$tRow = $tStmt->get_result()->fetch_assoc();
$tStmt->close();

if ($tRow) {
    $pStmt = $conn->prepare(
        "SELECT category, risk_level, prediction_text, predicted_date, recommended_action
         FROM ai_predictions WHERE unit_number = ? AND status = 'active'
         ORDER BY predicted_date ASC LIMIT 3"
    );
    $pStmt->bind_param('s', $tRow['unit_number']);
    $pStmt->execute();
    $pRes = $pStmt->get_result();
    while ($row = $pRes->fetch_assoc()) $tenantPredictions[] = $row;
    $pStmt->close();
}

// Get next due date for quick reply context
$tenantInfo = null;
$tInfoStmt = $conn->prepare("SELECT lease_start_date, rent_amount, due_day FROM tenants WHERE user_id = ?");
$tInfoStmt->bind_param('i', $userId);
$tInfoStmt->execute();
$tenantInfo = $tInfoStmt->get_result()->fetch_assoc();
$tInfoStmt->close();

$nextDueDisplay = 'contact your admin';
if ($tenantInfo && !empty($tenantInfo['lease_start_date'])) {
    $lsd = new DateTime(substr($tenantInfo['lease_start_date'], 0, 10));
    $dueDay = isset($tenantInfo['due_day']) && (int)$tenantInfo['due_day'] > 0 ? (int)$tenantInfo['due_day'] : (int)$lsd->format('d');
    $firstDue = (clone $lsd)->modify('+2 months');
    $maxDay = (int)$firstDue->format('t');
    $firstDue->setDate((int)$firstDue->format('Y'), (int)$firstDue->format('m'), min($dueDay, $maxDay));
    $today = new DateTime('today');
    if ($today < $firstDue) {
        $nextDueDisplay = $firstDue->format('M d, Y');
    } else {
        $nd = new DateTime($today->format('Y-m-') . str_pad(min($dueDay, (int)$today->format('t')), 2, '0', STR_PAD_LEFT));
        if ($today > $nd) $nd->modify('+1 month');
        $nextDueDisplay = $nd->format('M d, Y');
    }
}

$page_title = "AI Assistant";
include '../includes/header.php';
?>

<?php
$advisoryCount = count($tenantPredictions) + count($tenantAlerts);
$cat_icons = [
    'plumbing'   => 'fa-faucet',
    'electrical' => 'fa-bolt',
    'hvac'       => 'fa-fan',
    'structural' => 'fa-building',
    'pest'       => 'fa-bug',
    'appliance'  => 'fa-blender',
    'general'    => 'fa-wrench',
    'payment'    => 'fa-file-invoice-dollar',
];
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">

            <?php if ($advisoryCount > 0): ?>
            <div class="notif-dropdown" id="cbAdvDropdown">
                <div class="notif-dropdown-header">
                    <i class="fas fa-shield-alt me-2" style="color:#4ED6C1;"></i>
                    <span class="fw-bold">Proactive Advisories</span>
                </div>
                <div class="notif-dropdown-body">
                    <?php foreach ($tenantPredictions as $pred): ?>
                        <?php
                            $ck = strtolower($pred['category']);
                            $ci = $cat_icons[$ck] ?? 'fa-exclamation-circle';
                            $rl = $pred['risk_level'];
                            $rc = ($rl === 'high' || $rl === 'critical') ? 'danger' : ($rl === 'medium' ? 'warning' : 'info');
                        ?>
                        <div class="notif-item">
                            <div class="notif-item-icon notif-icon-<?php echo $rc; ?>"><i class="fas <?php echo $ci; ?>"></i></div>
                            <div class="notif-item-content">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="notif-item-title"><?php echo ucfirst(htmlspecialchars($pred['category'])); ?></span>
                                    <span class="notif-item-badge notif-badge-<?php echo $rc; ?>"><?php echo ucfirst($rl); ?></span>
                                </div>
                                <p class="notif-item-msg"><?php echo htmlspecialchars($pred['prediction_text']); ?></p>
                                <?php if ($pred['predicted_date']): ?>
                                    <span class="notif-item-date"><i class="fas fa-calendar-day me-1"></i><?php echo date('M d, Y', strtotime($pred['predicted_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($tenantAlerts as $alert): ?>
                        <?php $ac = $alert['priority'] === 'high' ? 'danger' : ($alert['priority'] === 'medium' ? 'warning' : 'info'); ?>
                        <div class="notif-item">
                            <div class="notif-item-icon notif-icon-<?php echo $ac; ?>"><i class="fas fa-bell"></i></div>
                            <div class="notif-item-content">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="notif-item-title"><?php echo htmlspecialchars($alert['title']); ?></span>
                                    <span class="notif-item-badge notif-badge-<?php echo $ac; ?>"><?php echo ucfirst($alert['type']); ?></span>
                                </div>
                                <p class="notif-item-msg"><?php echo htmlspecialchars(mb_strimwidth($alert['message'], 0, 120, '...')); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="chatbot-card tenant-ui">
                <div class="chatbot-container">
                    <div class="chatbot-header d-flex align-items-center justify-content-between">
                        <div>
                            <i class="fas fa-robot me-2"></i>
                            <span class="fw-semibold">AI Assistant</span>
                            <span class="chatbot-subtitle d-none d-sm-inline ms-2">— StayWise Helper</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($advisoryCount > 0): ?>
                            <button class="notif-bell-btn" id="cbAdvBellBtn" title="Proactive Advisories">
                                <i class="fas fa-bell"></i>
                                <span class="notif-badge"><?php echo $advisoryCount; ?></span>
                            </button>
                            <?php endif; ?>
                            <!-- Clear chat button -->
                            <button class="clear-chat-btn" id="clearChatBtn" title="Clear conversation">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                            <span class="badge bg-success" id="statusBadge"><i class="fas fa-circle me-1" style="font-size:.5em;vertical-align:middle;"></i>Online</span>
                        </div>
                    </div>

                    <div class="chatbot-body" id="chat-body">
                        <div id="chat-messages" class="chat-messages"></div>
                    </div>

                    <!-- Quick reply suggestions -->
                    <div class="quick-replies" id="quickReplies">
                        <button class="qr-btn" onclick="sendQuick('When is my next due date?')"><i class="fas fa-calendar me-1"></i>Next due date</button>
                        <button class="qr-btn" onclick="sendQuick('What is my current balance?')"><i class="fas fa-peso-sign me-1"></i>My balance</button>
                        <button class="qr-btn" onclick="sendQuick('How do I submit a complaint?')"><i class="fas fa-tools me-1"></i>Submit complaint</button>
                        <button class="qr-btn" onclick="sendQuick('What are the house rules?')"><i class="fas fa-book me-1"></i>House rules</button>
                    </div>

                    <div class="chatbot-footer">
                        <form id="chat-form" class="d-flex gap-2" onsubmit="return false;">
                            <input type="text" class="form-control chatbot-input" id="chat-input" placeholder="Type your message..." autocomplete="off">
                            <button class="btn chatbot-send-btn" type="submit" id="chat-send">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Clear chat hidden form -->
            <form id="clearChatForm" method="POST" style="display:none;">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="clear_chat" value="1">
            </form>

            <!-- FAQ Section -->
            <div class="faq-section mt-4 mb-4">
                <h5 class="mb-3"><i class="fas fa-question-circle me-2 text-primary"></i>Frequently Asked Questions</h5>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="faq-card h-100">
                            <div class="faq-icon"><i class="fas fa-credit-card"></i></div>
                            <h6 class="faq-title">How do I submit a rent payment?</h6>
                            <p class="faq-content">Go to the Payments section and upload your payment proof. Our admin will verify within 24 hours.</p>
                            <a href="payments.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>View Payments</a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="faq-card h-100">
                            <div class="faq-icon"><i class="fas fa-tools"></i></div>
                            <h6 class="faq-title">How do I report a maintenance issue?</h6>
                            <p class="faq-content">Visit Complaints section and submit a detailed description. Our maintenance team will respond promptly.</p>
                            <a href="complaints.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>Submit Issue</a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="faq-card h-100">
                            <div class="faq-icon"><i class="fas fa-calendar-alt"></i></div>
                            <h6 class="faq-title">When is rent due each month?</h6>
                            <p class="faq-content">Rent is due on the same day every month as your lease start date. Check your dashboard for your specific due date.</p>
                            <a href="dashboard.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>View Dashboard</a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="faq-card h-100">
                            <div class="faq-icon"><i class="fas fa-phone"></i></div>
                            <h6 class="faq-title">How can I contact the property manager?</h6>
                            <p class="faq-content">Submit complaints through this system, call during business hours, or email. Emergencies: use the lease contact number.</p>
                            <a href="complaints.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right me-1"></i>Contact Support</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.chatbot-card { border-radius:16px;overflow:visible;box-shadow:0 4px 24px rgba(0,0,0,0.08);border:1px solid #e2e8f0;background:#ffffff; }
.chatbot-card .chatbot-container { height:520px;border-radius:0;background:transparent;overflow:visible; }
.chatbot-card .chatbot-header { background:linear-gradient(135deg,#0d6efd 0%,#0050d0 100%);color:#ffffff;padding:.85rem 1.15rem;border-radius:16px 16px 0 0;font-size:.95rem;position:relative;z-index:10; }
.chatbot-subtitle { opacity:.8;font-size:.82em; }
.chatbot-card .chatbot-body { background:#f0f4f8;flex:1;overflow:hidden; }
.chatbot-card .chat-messages { padding:.75rem; }
.chatbot-card .chat-bubble.from-assistant .bubble-inner { background:#ffffff;color:#1e293b;border:1px solid #d1d9e6;box-shadow:0 1px 4px rgba(0,0,0,0.06); }
.chatbot-card .chat-bubble.from-user .bubble-inner { background:linear-gradient(135deg,#0d6efd,#3b82f6);color:#ffffff;border:none;box-shadow:0 2px 8px rgba(13,110,253,0.25); }
.chatbot-card .chatbot-footer { background:#ffffff;padding:.5rem 1rem;border-top:1px solid #e2e8f0; }
.chatbot-input { border:1px solid #cbd5e1;border-radius:24px !important;padding:.5rem 1rem;font-size:.9rem;background:#f8fafc;transition:border-color .2s,box-shadow .2s; }
.chatbot-input:focus { border-color:#0d6efd;box-shadow:0 0 0 3px rgba(13,110,253,0.15);background:#fff; }
.chatbot-send-btn { border-radius:50% !important;width:42px;height:42px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0d6efd,#3b82f6);color:#fff;border:none;flex-shrink:0;transition:transform .15s,box-shadow .2s; }
.chatbot-send-btn:hover { transform:scale(1.08);box-shadow:0 4px 12px rgba(13,110,253,0.35);color:#fff; }

/* Clear chat button */
.clear-chat-btn { background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);color:#fff;width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.8rem;cursor:pointer;transition:background .2s,transform .15s; }
.clear-chat-btn:hover { background:rgba(239,68,68,0.3);transform:scale(1.08); }

/* Quick replies */
.quick-replies { display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px;background:#f8fafc;border-top:1px solid #e2e8f0; }
.qr-btn { font-size:.75rem;padding:4px 10px;border-radius:20px;border:1px solid #cbd5e1;background:#fff;color:#374151;cursor:pointer;transition:all .15s;white-space:nowrap; }
.qr-btn:hover { background:#0d6efd;color:#fff;border-color:#0d6efd; }

/* Notification bell */
.notif-bell-btn { background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.2);color:#fff;width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem;cursor:pointer;position:relative;transition:background .2s,transform .15s; }
.notif-bell-btn:hover { background:rgba(255,255,255,0.25);transform:scale(1.08); }
.notif-bell-btn .notif-badge { position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;border-radius:9px;background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 4px;line-height:1;box-shadow:0 1px 4px rgba(239,68,68,0.4); }
.notif-dropdown { display:none;position:fixed;width:340px;max-height:420px;background:#ffffff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,0.15);border:1px solid #e2e8f0;z-index:9999;overflow:hidden; }
.notif-dropdown.show { display:block;animation:notifSlideIn .2s ease-out; }
@keyframes notifSlideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.notif-dropdown-header { padding:.7rem .9rem;border-bottom:1px solid #e2e8f0;font-size:.85rem;background:#f8fafc; }
.notif-dropdown-body { overflow-y:auto;max-height:350px;padding:.5rem; }
.notif-item { display:flex;align-items:flex-start;gap:.65rem;padding:.6rem .55rem;border-radius:10px;margin-bottom:.35rem;transition:background .15s;cursor:default; }
.notif-item:last-child { margin-bottom:0; }
.notif-item:hover { background:#f1f5f9; }
.notif-item-icon { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0;margin-top:2px; }
.notif-icon-danger { background:#fee2e2;color:#dc2626; }
.notif-icon-warning { background:#fef3c7;color:#d97706; }
.notif-icon-info { background:#dbeafe;color:#2563eb; }
.notif-item-content { flex:1;min-width:0; }
.notif-item-title { font-weight:600;font-size:.8rem;color:#1e293b; }
.notif-item-badge { font-size:.65rem;font-weight:600;padding:2px 7px;border-radius:6px;flex-shrink:0; }
.notif-badge-danger { background:#fecaca;color:#991b1b; }
.notif-badge-warning { background:#fde68a;color:#92400e; }
.notif-badge-info { background:#bfdbfe;color:#1e40af; }
.notif-item-msg { margin:3px 0 2px;font-size:.78rem;color:#64748b;line-height:1.35; }
.notif-item-date { font-size:.72rem;color:#0d9488;font-weight:500; }

/* FAQ */
.faq-card { border:1px solid #e2e8f0;border-radius:12px;padding:20px;transition:all .25s ease;display:flex;flex-direction:column;background:#ffffff;box-shadow:0 1px 4px rgba(0,0,0,0.04); }
.faq-card:hover { border-color:#93c5fd;box-shadow:0 6px 20px rgba(13,110,253,0.12);transform:translateY(-3px); }
.faq-icon { font-size:30px;color:#3b82f6;margin-bottom:12px; }
.faq-title { font-weight:600;color:#1e293b;margin-bottom:8px;line-height:1.4; }
.faq-content { color:#64748b;font-size:.875rem;margin-bottom:15px;flex-grow:1; }
.faq-card .btn { align-self:flex-start;margin-top:auto; }

/* Typing indicator */
.typing-indicator .bubble-inner { color:#64748b !important;font-style:italic; }

/* Dark mode */
body.dark-mode .chatbot-card { background:#141820;border-color:#2a3040;box-shadow:0 4px 24px rgba(0,0,0,0.35); }
body.dark-mode .chatbot-card .chatbot-header { background:linear-gradient(135deg,#1a2640 0%,#0f1a30 100%);color:#e7e7ee; }
body.dark-mode .chatbot-card .chatbot-body { background:#0d1117; }
body.dark-mode .chatbot-card .chat-bubble.from-assistant .bubble-inner { background:#1c2130;color:#e2e8f0;border-color:rgba(255,255,255,0.08);box-shadow:0 1px 4px rgba(0,0,0,0.2); }
body.dark-mode .chatbot-card .chat-bubble.from-user .bubble-inner { background:linear-gradient(135deg,#4ED6C1,#38b2a0);color:#0b1320;box-shadow:0 2px 8px rgba(78,214,193,0.25); }
body.dark-mode .chatbot-card .chatbot-footer { background:#141820;border-color:#2a3040; }
body.dark-mode .chatbot-input { background:#1c2130;border-color:#2a3040;color:#e2e8f0; }
body.dark-mode .chatbot-input:focus { border-color:#4ED6C1;box-shadow:0 0 0 3px rgba(78,214,193,0.15);background:#1c2130; }
body.dark-mode .chatbot-send-btn { background:linear-gradient(135deg,#4ED6C1,#38b2a0);color:#0b1320; }
body.dark-mode .chatbot-send-btn:hover { box-shadow:0 4px 12px rgba(78,214,193,0.35);color:#0b1320; }
body.dark-mode .quick-replies { background:#141820;border-color:#2a3040; }
body.dark-mode .qr-btn { background:#1c2130;border-color:#2a3040;color:#94a3b8; }
body.dark-mode .qr-btn:hover { background:#4ED6C1;color:#0b1320;border-color:#4ED6C1; }
body.dark-mode .typing-indicator .bubble-inner { color:#94a3b8 !important; }
body.dark-mode .notif-bell-btn { background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.15); }
body.dark-mode .notif-bell-btn:hover { background:rgba(255,255,255,0.2); }
body.dark-mode .notif-dropdown { background:#1c2130;border-color:#2a3040;box-shadow:0 8px 30px rgba(0,0,0,0.4); }
body.dark-mode .notif-dropdown-header { background:#141820;border-color:#2a3040;color:#e2e8f0; }
body.dark-mode .notif-item:hover { background:#232a38; }
body.dark-mode .notif-item-title { color:#e2e8f0; }
body.dark-mode .notif-item-msg { color:#94a3b8; }
body.dark-mode .notif-item-date { color:#4ED6C1; }
body.dark-mode .notif-icon-danger { background:rgba(220,38,38,0.15);color:#fca5a5; }
body.dark-mode .notif-icon-warning { background:rgba(217,119,6,0.15);color:#fcd34d; }
body.dark-mode .notif-icon-info { background:rgba(37,99,235,0.15);color:#93c5fd; }
body.dark-mode .notif-badge-danger { background:rgba(254,202,202,0.15);color:#fca5a5; }
body.dark-mode .notif-badge-warning { background:rgba(253,230,138,0.15);color:#fcd34d; }
body.dark-mode .notif-badge-info { background:rgba(191,219,254,0.15);color:#93c5fd; }
body.dark-mode .faq-card { border-color:#2a3040;background:#1c2130;box-shadow:0 1px 4px rgba(0,0,0,0.2); }
body.dark-mode .faq-card:hover { border-color:#4ED6C1;box-shadow:0 6px 20px rgba(78,214,193,0.15); }
body.dark-mode .faq-icon { color:#4ED6C1; }
body.dark-mode .faq-title { color:#e2e8f0; }
body.dark-mode .faq-content { color:#94a3b8; }
body.dark-mode .faq-section h5 { color:#e2e8f0; }
body.dark-mode .faq-section .text-primary { color:#4ED6C1 !important; }
@media (max-width:575.98px) { .notif-dropdown { width:290px;right:-10px; } .quick-replies { gap:4px; } .qr-btn { font-size:.7rem;padding:3px 8px; } }
</style>

<script>
// Notification bell
(function() {
    const btn = document.getElementById('cbAdvBellBtn');
    const dropdown = document.getElementById('cbAdvDropdown');
    if (!btn || !dropdown) return;
    function positionDropdown() {
        const rect = btn.getBoundingClientRect();
        dropdown.style.top = (rect.bottom + 8) + 'px';
        dropdown.style.left = Math.max(8, rect.right - 340) + 'px';
    }
    btn.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        dropdown.classList.contains('show') ? dropdown.classList.remove('show') : (positionDropdown(), dropdown.classList.add('show'));
    });
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) dropdown.classList.remove('show');
    });
    window.addEventListener('scroll', function() { if (dropdown.classList.contains('show')) positionDropdown(); }, {passive:true});
    window.addEventListener('resize', function() { if (dropdown.classList.contains('show')) positionDropdown(); });
})();

const messagesEl = document.getElementById('chat-messages');
const inputEl    = document.getElementById('chat-input');
const formEl     = document.getElementById('chat-form');
const sendBtn    = document.getElementById('chat-send');
const statusBadge = document.getElementById('statusBadge');
let history = [];
let isWaiting = false;

function setStatus(online) {
    if (online) {
        statusBadge.className = 'badge bg-success';
        statusBadge.innerHTML = '<i class="fas fa-circle me-1" style="font-size:.5em;vertical-align:middle;"></i>Online';
    } else {
        statusBadge.className = 'badge bg-danger';
        statusBadge.innerHTML = '<i class="fas fa-circle me-1" style="font-size:.5em;vertical-align:middle;"></i>Groq AI is down';
    }
}

function addMessage(role, content, quickAction = null, proactiveAlerts = null) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-bubble ' + (role === 'user' ? 'from-user' : 'from-assistant');
    const inner = document.createElement('div');
    inner.className = 'bubble-inner';
    inner.innerHTML = content;
    wrapper.appendChild(inner);
    if (quickAction && role === 'assistant') {
        const actionBtn = document.createElement('a');
        actionBtn.href = quickAction.url;
        actionBtn.className = 'btn btn-sm btn-outline-primary mt-2';
        actionBtn.style.cssText = 'display:inline-block;max-width:200px;';
        actionBtn.innerHTML = '<i class="fas fa-arrow-right me-1"></i>' + quickAction.label;
        wrapper.appendChild(actionBtn);
    }
    if (proactiveAlerts && proactiveAlerts.length > 0 && role === 'assistant') {
        const alertDiv = document.createElement('div');
        alertDiv.className = 'mt-2 p-2 rounded';
        alertDiv.style.cssText = 'background:rgba(78,214,193,0.1);border:1px solid rgba(78,214,193,0.3);font-size:.85em;';
        alertDiv.innerHTML = '<strong><i class="fas fa-shield-alt me-1" style="color:#4ED6C1;"></i>Active advisories for your unit:</strong><br>';
        proactiveAlerts.forEach(a => {
            alertDiv.innerHTML += `<span class="badge bg-${a.risk==='high'||a.risk==='critical'?'danger':'warning'} me-1">${a.risk}</span> ${a.category}: ${a.text}<br>`;
        });
        wrapper.appendChild(alertDiv);
    }
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function addQuickReplySuggestions(suggestions) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-bubble from-assistant';
    wrapper.id = 'inlineSuggestions';
    const inner = document.createElement('div');
    inner.className = 'bubble-inner';
    inner.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;padding:6px;background:transparent;border:none;box-shadow:none;';
    suggestions.forEach(s => {
        const btn = document.createElement('button');
        btn.className = 'qr-btn';
        btn.textContent = s;
        btn.onclick = () => { wrapper.remove(); sendQuick(s); };
        inner.appendChild(btn);
    });
    wrapper.appendChild(inner);
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;
}

function sendQuick(text) {
    inputEl.value = text;
    sendChatMessage();
}

// Clear chat
document.getElementById('clearChatBtn').addEventListener('click', function() {
    if (confirm('Clear all conversation history?')) {
        document.getElementById('clearChatForm').submit();
    }
});

// Greeting on load
document.addEventListener('DOMContentLoaded', function() {
    let greeting = "Hello! I'm your StayWise AI Assistant. I can help with payments, maintenance, house rules, and more.";
    <?php if (!empty($tenantPredictions)): ?>
    greeting += "\n\n<strong>📋 I have some proactive updates for your unit:</strong>";
    <?php foreach ($tenantPredictions as $pred): ?>
    greeting += "\n• <strong><?php echo ucfirst(htmlspecialchars($pred['category'])); ?></strong>: <?php echo htmlspecialchars(addslashes($pred['prediction_text'])); ?>";
    <?php endforeach; ?>
    greeting += "\n\nFeel free to ask me about any of these or anything else!";
    <?php else: ?>
    greeting += " How can I assist you today?";
    <?php endif; ?>
    addMessage('assistant', greeting);
    // Show inline quick reply suggestions after greeting
    setTimeout(() => {
        addQuickReplySuggestions(['When is my next due date?', 'What is my balance?', 'House rules?']);
    }, 400);
});

async function sendChatMessage() {
    if (isWaiting) return;
    const text = inputEl.value.trim();
    if (!text) return;
    inputEl.value = '';
    // Remove inline suggestions if present
    const sugg = document.getElementById('inlineSuggestions');
    if (sugg) sugg.remove();
    addMessage('user', text);
    isWaiting = true;
    sendBtn.disabled = true;
    inputEl.disabled = true;

    // Typing indicator
    const typingEl = document.createElement('div');
    typingEl.className = 'chat-bubble from-assistant typing-indicator';
    typingEl.innerHTML = '<div class="bubble-inner"><i class="fas fa-circle-notch fa-spin me-2"></i>Thinking...</div>';
    messagesEl.appendChild(typingEl);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    try {
        const res = await fetch('<?php echo $base_url; ?>api/chat_enhanced.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({message: text, history})
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
        const reply = data.reply || 'Sorry, I could not generate a response.';
        setStatus(true);
        const isFirst = history.length === 0;
        addMessage('assistant', reply, data.quick_action, isFirst ? data.proactive_alerts : null);
        history.push({role:'user', content:text});
        history.push({role:'assistant', content:reply});
        // Show follow-up suggestions after every 2 exchanges
        if (history.length % 4 === 0) {
            setTimeout(() => addQuickReplySuggestions(['Tell me more', 'What should I do next?', 'Contact admin']), 300);
        }
    } catch (err) {
        setStatus(false);
        addMessage('assistant',
            '<i class="fas fa-exclamation-triangle me-2" style="color:#f59e0b;"></i>' +
            '<strong>Groq AI is currently down.</strong> The AI service is temporarily unavailable.<br>' +
            '<small style="opacity:.8;">Please try again in a few minutes or contact your admin directly.</small>'
        );
    } finally {
        const typingBubble = messagesEl.querySelector('.typing-indicator');
        if (typingBubble) typingBubble.remove();
        isWaiting = false;
        sendBtn.disabled = false;
        inputEl.disabled = false;
        inputEl.focus();
    }
}

// Rate limit check
let msgCount = 0;
const MSG_LIMIT = 30;
async function sendChatMessageWithRateLimit() {
    if (msgCount >= MSG_LIMIT) {
        addMessage('assistant', '<i class="fas fa-clock me-2" style="color:#f59e0b;"></i>You\'re sending messages too fast. Please wait a moment before trying again.');
        return;
    }
    msgCount++;
    await sendChatMessage();
}

formEl.addEventListener('submit', (e) => { e.preventDefault(); sendChatMessageWithRateLimit(); });
inputEl.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatMessageWithRateLimit(); } });
</script>

<?php include '../includes/footer.php'; ?>
