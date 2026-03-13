<?php
require_once '../includes/security.php';
set_secure_session_cookies();
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php"); exit();
}

try {
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'force_password_change')) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid); $stmt->execute();
            $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
            if ($row && intval($row['force_password_change']) === 1) {
                $_SESSION['must_change_password'] = true;
                header('Location: ../change_password.php'); exit();
            }
        }
    }
} catch (Throwable $e) {}

define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';
require_once STAYWISE_ROOT . '/includes/logger.php';

$page_title = "AI Insights";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_analysis'])) {
    if (function_exists('verify_csrf_token') && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $analysisError = 'Invalid CSRF token.';
    } else {
        $conn->query("INSERT INTO ai_analysis_log (analysis_type, status) VALUES ('manual_full_analysis','running')");
        $logId = $conn->insert_id;
        $patternResults = ai_detect_patterns($conn);
        $totalRecords = $patternResults['patterns_found'] + $patternResults['predictions_created'];
        $details = json_encode(['patterns' => $patternResults]);
        $stmt = $conn->prepare("UPDATE ai_analysis_log SET status='completed', records_processed=?, insights_generated=0, details=?, completed_at=NOW() WHERE log_id=?");
        $stmt->bind_param('isi', $totalRecords, $details, $logId);
        $stmt->execute(); $stmt->close();
        logAdminAction($conn, $_SESSION['user_id'], 'ai_analysis_run', "Manual: {$patternResults['patterns_found']} patterns, {$patternResults['predictions_created']} predictions");
        $analysisSuccess = "{$patternResults['patterns_found']} patterns · {$patternResults['predictions_created']} predictions generated";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prediction'])) {
    if (function_exists('verify_csrf_token') && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $pId = (int)$_POST['prediction_id'];
        $newStatus = $_POST['new_status'];
        if (in_array($newStatus, ['acknowledged','resolved','dismissed'])) {
            $stmt = $conn->prepare("UPDATE ai_predictions SET status = ? WHERE prediction_id = ?");
            $stmt->bind_param('si', $newStatus, $pId); $stmt->execute(); $stmt->close();
            logAdminAction($conn, $_SESSION['user_id'], 'prediction_update', "Prediction #$pId → $newStatus");
        }
    }
}

$summary = ai_get_admin_summary($conn);
$catData = [];
foreach ($summary['patterns'] as $p) {
    $catData[$p['category']] = ($catData[$p['category']] ?? 0) + (int)$p['occurrence_count'];
}
arsort($catData);
$riskDist = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
foreach ($summary['predictions'] as $p) { $riskDist[$p['risk_level']]++; }
$lastRun = $conn->query("SELECT completed_at FROM ai_analysis_log WHERE status='completed' ORDER BY completed_at DESC LIMIT 1");
$lr = $lastRun ? $lastRun->fetch_assoc() : null;
$lastRunText = $lr ? date('M d, Y · H:i', strtotime($lr['completed_at'])) : 'Never';

include '../includes/header.php';

function catIcon(string $c): string {
    return match($c) {
        'plumbing'   => 'fa-faucet',
        'electrical' => 'fa-bolt',
        'structural' => 'fa-building',
        'appliance'  => 'fa-blender',
        'pest'       => 'fa-bug',
        'security'   => 'fa-shield-alt',
        'cleaning'   => 'fa-broom',
        'noise'      => 'fa-volume-up',
        default      => 'fa-wrench',
    };
}
function riskHex(string $r): string {
    return match($r) { 'critical'=>'#ff3b3b','high'=>'#ffaa00','medium'=>'#3b9eff',default=>'#64748b' };
}
function riskAlpha(string $r): string {
    return match($r) { 'critical'=>'rgba(255,59,59,.12)','high'=>'rgba(255,170,0,.12)','medium'=>'rgba(59,158,255,.12)',default=>'rgba(100,116,139,.1)' };
}
?>

<style>
/* ═══════════════════════════════════════════
   AI INSIGHTS  —  2025 High-Tech Theme
   Glassmorphism · Neon accents · Scanlines
   ═══════════════════════════════════════════ */

@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@600;700;800&display=swap');

:root {
  --t:    #4ED6C1;
  --t2:   #007DFE;
  --grd:  linear-gradient(135deg, #4ED6C1 0%, #007DFE 100%);
  --glow: 0 0 20px rgba(78,214,193,.25);
  --glow2:0 0 30px rgba(0,125,254,.2);
}

/* ── DARK-MODE page surface ── */
body.dark-mode .ai2025-wrap {
  --surf:   #0b0f17;
  --card:   rgba(255,255,255,.03);
  --cardb:  rgba(255,255,255,.07);
  --cardh:  rgba(255,255,255,.05);
  --txt:    #e2e8f0;
  --txt2:   #64748b;
  --txt3:   #334155;
  --rule:   rgba(255,255,255,.06);
}
body:not(.dark-mode) .ai2025-wrap {
  --surf:   #f0f4fa;
  --card:   rgba(255,255,255,.85);
  --cardb:  rgba(0,0,0,.09);
  --cardh:  rgba(255,255,255,.95);
  --txt:    #0f172a;
  --txt2:   #64748b;
  --txt3:   #cbd5e1;
  --rule:   rgba(0,0,0,.06);
}

.ai2025-wrap {
  padding: 24px 28px 80px;
  font-family: 'DM Sans', sans-serif;
  min-height: 100vh;
  background: var(--surf);
  position: relative;
  overflow: hidden;
}

/* Animated grid background */
.ai2025-wrap::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(78,214,193,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(78,214,193,.04) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events: none;
  z-index: 0;
}
body:not(.dark-mode) .ai2025-wrap::before {
  background-image:
    linear-gradient(rgba(0,125,254,.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,125,254,.05) 1px, transparent 1px);
}

/* Orb glows (decorative) */
.ai2025-wrap::after {
  content: '';
  position: fixed;
  top: -200px; right: -200px;
  width: 600px; height: 600px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(78,214,193,.07) 0%, transparent 70%);
  pointer-events: none;
  z-index: 0;
}

.ai2025-inner { position: relative; z-index: 1; max-width: 1400px; }

/* ── HEADER ── */
.ht {
  display: flex; align-items: flex-start;
  justify-content: space-between;
  gap: 20px; flex-wrap: wrap;
  margin-bottom: 36px;
}

.ht-left { display: flex; align-items: center; gap: 16px; }

.ht-icon {
  width: 52px; height: 52px;
  border-radius: 14px;
  background: var(--grd);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.25rem; color: #fff;
  box-shadow: var(--glow);
  position: relative;
  flex-shrink: 0;
}
.ht-icon::after {
  content: '';
  position: absolute; inset: -1px;
  border-radius: 15px;
  background: var(--grd);
  opacity: .3;
  filter: blur(8px);
  z-index: -1;
}

.ht-text h1 {
  font-family: 'Syne', sans-serif;
  font-size: 1.6rem; font-weight: 800;
  letter-spacing: -.03em; margin: 0 0 4px;
  color: var(--txt);
}
.ht-text h1 span {
  background: var(--grd);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.ht-meta {
  display: flex; align-items: center; gap: 16px;
  flex-wrap: wrap;
}
.ht-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .72rem; font-weight: 600;
  letter-spacing: .06em; text-transform: uppercase;
  padding: 4px 10px; border-radius: 20px;
  border: 1px solid var(--rule);
  color: var(--txt2);
  font-family: 'JetBrains Mono', monospace;
}
.ht-pill .dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--t);
  box-shadow: 0 0 6px var(--t);
  animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.4} }

/* Scan button */
.btn-scan {
  display: inline-flex; align-items: center; gap: 9px;
  padding: 11px 22px;
  border-radius: 11px;
  background: var(--grd);
  color: #fff; font-weight: 700;
  font-size: .83rem; letter-spacing: .02em;
  border: none; cursor: pointer;
  box-shadow: var(--glow), var(--glow2);
  transition: transform .2s, box-shadow .2s;
  position: relative; overflow: hidden;
}
.btn-scan::before {
  content: '';
  position: absolute; top: 0; left: -100%;
  width: 60%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.2), transparent);
  transition: left .5s ease;
}
.btn-scan:hover::before { left: 150%; }
.btn-scan:hover {
  transform: translateY(-2px);
  box-shadow: 0 0 32px rgba(78,214,193,.4), 0 0 48px rgba(0,125,254,.25);
}
.btn-scan:active { transform: scale(.97); }

/* ── ALERT ── */
.aip-banner {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 10px;
  font-size: .83rem; font-weight: 600;
  margin-bottom: 24px;
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: .02em;
  border: 1px solid;
}
.aip-ok  { background: rgba(78,214,193,.08); border-color: rgba(78,214,193,.25); color: var(--t); }
.aip-err { background: rgba(255,59,59,.07);  border-color: rgba(255,59,59,.2);  color: #ff3b3b; }

/* ── STAT CARDS ── */
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4,1fr);
  gap: 14px; margin-bottom: 20px;
}
@media(max-width:880px){ .stat-grid { grid-template-columns: repeat(2,1fr); } }

.sc {
  border-radius: 16px;
  padding: 22px 20px 18px;
  border: 1px solid var(--cardb);
  background: var(--card);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  position: relative; overflow: hidden;
  transition: transform .25s, box-shadow .25s, border-color .25s;
  cursor: default;
}
.sc:hover {
  transform: translateY(-3px);
  border-color: rgba(78,214,193,.35);
}
body.dark-mode .sc:hover { box-shadow: var(--glow); }

/* Corner bracket decoration */
.sc::before, .sc::after {
  content: '';
  position: absolute;
  width: 12px; height: 12px;
}
.sc::before {
  top: 8px; left: 8px;
  border-top: 1.5px solid var(--t);
  border-left: 1.5px solid var(--t);
  opacity: .5;
  border-radius: 2px 0 0 0;
}
.sc::after {
  bottom: 8px; right: 8px;
  border-bottom: 1.5px solid var(--t2);
  border-right: 1.5px solid var(--t2);
  opacity: .4;
  border-radius: 0 0 2px 0;
}

.sc-label {
  font-size: .68rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--txt2);
  font-family: 'JetBrains Mono', monospace;
  margin-bottom: 10px;
}
.sc-num {
  font-family: 'Syne', sans-serif;
  font-size: 2.6rem; font-weight: 800;
  line-height: 1; letter-spacing: -.05em;
  margin-bottom: 4px;
}
.sc-sub {
  font-size: .7rem; color: var(--txt2);
  font-family: 'JetBrains Mono', monospace;
}

/* Colored glow strip at top */
.sc-strip {
  position: absolute; top: 0; left: 0; right: 0;
  height: 2px; border-radius: 16px 16px 0 0;
}

/* ── PANELS ── */
.g2  { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.g32 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 16px; margin-bottom: 16px; }
@media(max-width:880px) { .g2,.g32 { grid-template-columns: 1fr; } }

.panel {
  border-radius: 16px;
  border: 1px solid var(--cardb);
  background: var(--card);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  overflow: hidden;
  transition: border-color .25s;
}
.panel:hover { border-color: rgba(78,214,193,.2); }

.ph {
  display: flex; align-items: center;
  justify-content: space-between;
  padding: 13px 18px;
  border-bottom: 1px solid var(--rule);
}
.ph-title {
  font-size: .7rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--txt2);
  font-family: 'JetBrains Mono', monospace;
  display: flex; align-items: center; gap: 8px;
}
.ph-title .ph-ic {
  width: 22px; height: 22px; border-radius: 6px;
  background: rgba(78,214,193,.1);
  display: flex; align-items: center; justify-content: center;
  font-size: .65rem; color: var(--t);
}
.cnt-badge {
  font-size: .65rem; font-weight: 700;
  padding: 2px 7px; border-radius: 20px;
  background: rgba(78,214,193,.1); color: var(--t);
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: .04em;
}

/* ── PREDICTIONS TABLE ── */
.pt { width: 100%; border-collapse: collapse; }
.pt th {
  padding: 10px 14px;
  font-size: .63rem; font-weight: 700;
  text-transform: uppercase; letter-spacing: .1em;
  color: var(--txt2); text-align: left; white-space: nowrap;
  font-family: 'JetBrains Mono', monospace;
  border-bottom: 1px solid var(--rule);
  background: rgba(0,0,0,.03);
}
body.dark-mode .pt th { background: rgba(255,255,255,.02); }
.pt td { padding: 12px 14px; font-size: .82rem; vertical-align: middle; border-bottom: 1px solid var(--rule); color: var(--txt); }
.pt tr.dr:hover td { background: var(--cardh); }
.pt tr.rr td { padding: 4px 14px 10px; font-size: .76rem; color: var(--txt2); border-bottom: 1px solid var(--rule); }

/* Unit tag */
.utag {
  font-size: .7rem; font-weight: 700;
  padding: 3px 8px; border-radius: 6px;
  background: rgba(0,125,254,.1); color: #007DFE;
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: .04em;
}
body.dark-mode .utag { background: rgba(0,125,254,.15); color: #60a5fa; }

/* Category */
.ccip {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: .81rem; font-weight: 500;
}
.ccip i { color: var(--t); font-size: .72rem; width: 13px; }

/* Risk pill — neon glow on dark */
.rp {
  display: inline-flex; align-items: center;
  font-size: .67rem; font-weight: 700;
  padding: 3px 8px; border-radius: 6px;
  text-transform: uppercase; letter-spacing: .06em;
  font-family: 'JetBrains Mono', monospace;
  border: 1px solid;
  transition: box-shadow .2s;
}
body.dark-mode .rp { box-shadow: inset 0 0 8px rgba(0,0,0,.3); }

/* Confidence bar */
.cb { width: 70px; }
.cb-track { height: 4px; border-radius: 2px; overflow: hidden; margin-bottom: 3px; background: var(--rule); }
.cb-fill { height: 100%; border-radius: 2px; background: var(--grd); transition: width .8s cubic-bezier(.4,0,.2,1); }
.cb-pct { font-size: .66rem; font-weight: 600; color: var(--txt2); font-family: 'JetBrains Mono', monospace; }

/* Date */
.pd { font-size: .78rem; font-weight: 600; white-space: nowrap; line-height: 1.5; color: var(--txt); }
.pd-soon     { color: #ffaa00 !important; }
.pd-overdue  { color: #ff3b3b !important; }
.pd-tag { font-size: .63rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; font-family: 'JetBrains Mono', monospace; }

/* Action buttons */
.pas { display: flex; gap: 3px; }
.pa {
  width: 26px; height: 26px; border-radius: 7px;
  border: 1px solid var(--cardb);
  display: flex; align-items: center; justify-content: center;
  font-size: .67rem; cursor: pointer;
  transition: all .15s; background: none; color: var(--txt2);
}
.pa:hover { background: var(--cardh); color: var(--txt); border-color: rgba(78,214,193,.3); }
.pa.ok:hover  { border-color: var(--t) !important; color: var(--t) !important; box-shadow: 0 0 8px rgba(78,214,193,.2); }
.pa.del:hover { border-color: #ff3b3b !important; color: #ff3b3b !important; }

/* ── PATTERNS LIST ── */
.pi {
  display: flex; align-items: center;
  justify-content: space-between; gap: 12px;
  padding: 12px 18px;
  border-bottom: 1px solid var(--rule);
  transition: background .12s;
}
.pi:hover { background: var(--cardh); }
.pi:last-child { border-bottom: none; }
.pi-ico {
  width: 34px; height: 34px; flex-shrink: 0;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: .78rem;
  background: rgba(78,214,193,.08); color: var(--t);
  border: 1px solid rgba(78,214,193,.15);
}
.pi-name { font-size: .83rem; font-weight: 600; color: var(--txt); }
.pi-meta { font-size: .71rem; color: var(--txt2); margin-top: 1px; font-family: 'JetBrains Mono', monospace; }

/* ── HISTORY ── */
.ht2 { width: 100%; border-collapse: collapse; font-size: .78rem; }
.ht2 th { padding: 10px 16px; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--txt2); border-bottom: 1px solid var(--rule); background: rgba(0,0,0,.02); font-family: 'JetBrains Mono', monospace; }
body.dark-mode .ht2 th { background: rgba(255,255,255,.02); }
.ht2 td { padding: 10px 16px; color: var(--txt); border-bottom: 1px solid var(--rule); }
.spill {
  display: inline-block; font-size: .63rem; font-weight: 700;
  padding: 2px 7px; border-radius: 5px;
  text-transform: uppercase; letter-spacing: .05em;
  font-family: 'JetBrains Mono', monospace;
}
.s-ok   { background: rgba(78,214,193,.1); color: var(--t); border: 1px solid rgba(78,214,193,.2); }
.s-run  { background: rgba(255,170,0,.1);  color: #ffaa00;  border: 1px solid rgba(255,170,0,.2); }
.s-fail { background: rgba(255,59,59,.1);  color: #ff3b3b;  border: 1px solid rgba(255,59,59,.2); }

/* ── EMPTY STATE ── */
.empty {
  text-align: center; padding: 48px 24px;
}
.e-ico {
  width: 50px; height: 50px; border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; color: var(--t);
  background: rgba(78,214,193,.08);
  border: 1px solid rgba(78,214,193,.15);
  margin: 0 auto 14px;
}
.empty h6 { font-size: .87rem; font-weight: 700; margin-bottom: 5px; color: var(--txt); }
.empty p  { font-size: .79rem; margin: 0; color: var(--txt2); }

/* ── STAGGER ENTRY ANIMATIONS ── */
@keyframes fadeup {
  from { opacity: 0; transform: translateY(14px); }
  to   { opacity: 1; transform: translateY(0); }
}
.ai2025-inner > * { animation: fadeup .4s ease both; }
.ai2025-inner > *:nth-child(1) { animation-delay: .04s; }
.ai2025-inner > *:nth-child(2) { animation-delay: .10s; }
.ai2025-inner > *:nth-child(3) { animation-delay: .16s; }
.ai2025-inner > *:nth-child(4) { animation-delay: .22s; }
.ai2025-inner > *:nth-child(5) { animation-delay: .28s; }
.ai2025-inner > *:nth-child(6) { animation-delay: .34s; }
.ai2025-inner > *:nth-child(7) { animation-delay: .38s; }

/* ── SCAN LINE animation on run button ── */
@keyframes spin { to { transform: rotate(360deg); } }
.fa-spin-fast { animation: spin .6s linear infinite; }
</style>

<div class="ai2025-wrap">
<div class="ai2025-inner">

    <!-- ═══ HEADER ═══ -->
    <div class="ht">
        <div class="ht-left">
            <div class="ht-icon"><i class="fas fa-brain"></i></div>
            <div class="ht-text">
                <h1>AI <span>Insights</span></h1>
                <div class="ht-meta">
                    <span class="ht-pill"><span class="dot"></span>Live</span>
                    <span class="ht-pill"><i class="fas fa-clock me-1"></i><?php echo $lastRunText; ?></span>
                    <span class="ht-pill"><?php echo count($summary['predictions']); ?> predictions active</span>
                </div>
            </div>
        </div>
        <form method="POST" id="aForm">
            <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
            <input type="hidden" name="run_analysis" value="1">
            <button type="submit" class="btn-scan" id="runBtn">
                <i class="fas fa-microchip" id="runIco"></i>
                <span id="runTxt">Run Analysis</span>
            </button>
        </form>
    </div>

    <?php if (!empty($analysisSuccess)): ?>
    <div class="aip-banner aip-ok"><i class="fas fa-circle-check"></i>&nbsp;<?php echo htmlspecialchars($analysisSuccess); ?></div>
    <?php endif; ?>
    <?php if (!empty($analysisError)): ?>
    <div class="aip-banner aip-err"><i class="fas fa-circle-xmark"></i>&nbsp;<?php echo htmlspecialchars($analysisError); ?></div>
    <?php endif; ?>

    <!-- ═══ STAT STRIP ═══ -->
    <div class="stat-grid">
    <?php
    $stats = [
        ['n'=>$summary['stats']['active_predictions'], 'l'=>'Active Predictions', 's'=>'predictions running', 'c'=>'#4ED6C1', 'bg'=>'var(--grd)'],
        ['n'=>$summary['stats']['high_risk'],          'l'=>'High / Critical',    's'=>'require attention',  'c'=>'#ff3b3b', 'bg'=>'linear-gradient(135deg,#ff3b3b,#dc2626)'],
        ['n'=>$summary['stats']['patterns_detected'],  'l'=>'Patterns Found',     's'=>'recurring issues',   'c'=>'#007DFE', 'bg'=>'linear-gradient(135deg,#3b9eff,#007DFE)'],
        ['n'=>count($summary['analysis_logs']),        'l'=>'Analysis Runs',      's'=>'total executions',   'c'=>'#ffaa00', 'bg'=>'linear-gradient(135deg,#ffaa00,#f59e0b)'],
    ];
    foreach ($stats as $i => $s): ?>
    <div class="sc">
        <div class="sc-strip" style="background:<?php echo $s['bg']; ?>"></div>
        <div class="sc-label"><?php echo $s['l']; ?></div>
        <div class="sc-num" style="color:<?php echo $s['c']; ?>"><?php echo $s['n']; ?></div>
        <div class="sc-sub"><?php echo $s['s']; ?></div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- ═══ CHARTS ═══ -->
    <?php if (count($catData) > 0 || array_sum($riskDist) > 0): ?>
    <div class="g2">
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="ph-ic"><i class="fas fa-chart-bar"></i></div>Complaint Categories</div>
            </div>
            <div style="padding:18px;"><canvas id="catChart" style="max-height:190px;"></canvas></div>
        </div>
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="ph-ic"><i class="fas fa-shield-alt"></i></div>Risk Distribution</div>
            </div>
            <div style="padding:18px;"><canvas id="riskChart" style="max-height:190px;"></canvas></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ PREDICTIONS TABLE ═══ -->
    <div class="panel" style="margin-bottom:16px;">
        <div class="ph">
            <div class="ph-title"><div class="ph-ic"><i class="fas fa-wand-sparkles"></i></div>Active Predictions</div>
            <span class="cnt-badge"><?php echo count($summary['predictions']); ?></span>
        </div>
        <?php if (count($summary['predictions']) > 0): ?>
        <div style="overflow-x:auto;">
            <table class="pt">
                <thead>
                    <tr><th>Unit</th><th>Category</th><th>Risk</th><th>Prediction</th><th>Due Date</th><th>Confidence</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($summary['predictions'] as $pred):
                    $pd = $pred['predicted_date'] ? strtotime($pred['predicted_date']) : null;
                    $days = $pd ? (int)(($pd - time()) / 86400) : null;
                    $dc = $days !== null ? ($days < 0 ? 'pd-overdue' : ($days <= 7 ? 'pd-soon' : '')) : '';
                    $rc = riskHex($pred['risk_level']);
                    $ra = riskAlpha($pred['risk_level']);
                ?>
                <tr class="dr">
                    <td><span class="utag"><?php echo htmlspecialchars($pred['unit_number']); ?></span></td>
                    <td>
                        <span class="ccip">
                            <i class="fas <?php echo catIcon($pred['category']); ?>"></i>
                            <?php echo ucfirst(htmlspecialchars($pred['category'])); ?>
                        </span>
                    </td>
                    <td>
                        <span class="rp" style="background:<?php echo $ra; ?>;color:<?php echo $rc; ?>;border-color:<?php echo $rc; ?>33;">
                            <?php echo ucfirst($pred['risk_level']); ?>
                        </span>
                    </td>
                    <td style="max-width:250px;">
                        <span style="font-size:.8rem;color:var(--txt);"><?php echo htmlspecialchars(mb_strimwidth($pred['prediction_text'], 0, 95, '…')); ?></span>
                    </td>
                    <td>
                        <?php if ($pd): ?>
                        <div class="pd <?php echo $dc; ?>">
                            <?php echo date('M d, Y', $pd); ?>
                            <?php if ($days < 0): ?><br><span class="pd-tag">Overdue</span>
                            <?php elseif ($days <= 7): ?><br><span class="pd-tag">Soon</span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?><span style="color:var(--txt2);">—</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="cb">
                            <div class="cb-track"><div class="cb-fill" style="width:<?php echo round($pred['confidence_score']); ?>%"></div></div>
                            <div class="cb-pct"><?php echo round($pred['confidence_score']); ?>%</div>
                        </div>
                    </td>
                    <td>
                        <div class="pas">
                            <form method="POST" class="d-inline"><?php if(function_exists('csrf_input'))echo csrf_input();?>
                                <input type="hidden" name="update_prediction" value="1">
                                <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                <input type="hidden" name="new_status" value="acknowledged">
                                <button class="pa" title="Acknowledge"><i class="fas fa-check"></i></button>
                            </form>
                            <form method="POST" class="d-inline"><?php if(function_exists('csrf_input'))echo csrf_input();?>
                                <input type="hidden" name="update_prediction" value="1">
                                <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                <input type="hidden" name="new_status" value="resolved">
                                <button class="pa ok" title="Resolve"><i class="fas fa-check-double"></i></button>
                            </form>
                            <form method="POST" class="d-inline"><?php if(function_exists('csrf_input'))echo csrf_input();?>
                                <input type="hidden" name="update_prediction" value="1">
                                <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                <input type="hidden" name="new_status" value="dismissed">
                                <button class="pa del" title="Dismiss"><i class="fas fa-times"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php if (!empty($pred['recommended_action'])): ?>
                <tr class="rr">
                    <td colspan="7">
                        <i class="fas fa-lightbulb me-1" style="color:#ffaa00;font-size:.72rem;"></i>
                        <strong style="color:var(--txt2);">Recommended:</strong>&nbsp;<?php echo htmlspecialchars($pred['recommended_action']); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty">
            <div class="e-ico"><i class="fas fa-wand-sparkles"></i></div>
            <h6>No predictions yet</h6>
            <p>Click Run Analysis to scan complaint history for patterns</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══ PATTERNS + HISTORY ═══ -->
    <div class="g32">

        <!-- Patterns -->
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="ph-ic"><i class="fas fa-project-diagram"></i></div>Recurring Patterns</div>
                <span class="cnt-badge"><?php echo count($summary['patterns']); ?></span>
            </div>
            <?php if (count($summary['patterns']) > 0): ?>
            <div>
                <?php foreach (array_slice($summary['patterns'], 0, 12) as $pat): ?>
                <div class="pi">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="pi-ico"><i class="fas <?php echo catIcon($pat['category']); ?>"></i></div>
                        <div>
                            <div class="pi-name">
                                <?php echo ucfirst(htmlspecialchars($pat['category'])); ?>
                                <span class="utag ms-1" style="font-size:.62rem;padding:1px 6px;">U-<?php echo htmlspecialchars($pat['unit_number']); ?></span>
                            </div>
                            <div class="pi-meta">
                                <?php echo $pat['occurrence_count']; ?> complaints
                                <?php if ($pat['recurrence_interval_days']): ?> · ~<?php echo $pat['recurrence_interval_days']; ?>d<?php endif; ?>
                                <?php if ($pat['next_predicted_date']): ?> · next <?php echo date('M d', strtotime($pat['next_predicted_date'])); ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="rp" style="background:<?php echo riskAlpha($pat['severity']); ?>;color:<?php echo riskHex($pat['severity']); ?>;border-color:<?php echo riskHex($pat['severity']); ?>33;"><?php echo ucfirst($pat['severity']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty">
                <div class="e-ico"><i class="fas fa-project-diagram"></i></div>
                <h6>No patterns detected</h6>
                <p>Needs 3+ complaints of same type in one unit</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- History -->
        <div class="panel">
            <div class="ph">
                <div class="ph-title"><div class="ph-ic"><i class="fas fa-terminal"></i></div>Analysis Log</div>
            </div>
            <?php if (count($summary['analysis_logs']) > 0): ?>
            <div style="overflow-x:auto;">
                <table class="ht2">
                    <thead><tr><th>Job</th><th>Status</th><th>Completed</th></tr></thead>
                    <tbody>
                    <?php foreach ($summary['analysis_logs'] as $log): ?>
                    <tr>
                        <td style="font-family:'JetBrains Mono',monospace;font-size:.72rem;"><?php echo htmlspecialchars(str_replace('_',' ',$log['analysis_type'])); ?></td>
                        <td>
                            <span class="spill s-<?php echo $log['status']==='completed'?'ok':($log['status']==='running'?'run':'fail'); ?>">
                                <?php echo $log['status']==='completed'?'done':ucfirst($log['status']); ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap;font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--txt2);">
                            <?php echo $log['completed_at'] ? date('M d · H:i', strtotime($log['completed_at'])) : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty" style="padding:30px 20px;">
                <div class="e-ico"><i class="fas fa-terminal"></i></div>
                <h6>No log entries</h6>
                <p>Run analysis to create entries</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

</div><!-- /inner -->
</div><!-- /wrap -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dark   = document.body.classList.contains('dark-mode');
    const grid   = dark ? 'rgba(255,255,255,.05)' : 'rgba(0,0,0,.05)';
    const tick   = dark ? '#475569' : '#94a3b8';
    const lbl    = dark ? '#64748b' : '#94a3b8';
    const teal   = '#4ED6C1';
    const blue   = '#007DFE';

    /* ── Category bar chart ── */
    const catCtx = document.getElementById('catChart');
    if (catCtx) {
        new Chart(catCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map('ucfirst', array_keys($catData))); ?>,
                datasets: [{
                    label: 'Complaints',
                    data: <?php echo json_encode(array_values($catData)); ?>,
                    backgroundColor: function(ctx) {
                        const g = ctx.chart.ctx.createLinearGradient(0,0,200,0);
                        g.addColorStop(0, 'rgba(78,214,193,.7)');
                        g.addColorStop(1, 'rgba(0,125,254,.7)');
                        return g;
                    },
                    borderColor: teal,
                    borderWidth: 0,
                    borderRadius: 5,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { color: tick, stepSize: 1, font:{size:11} }, grid: { color: grid } },
                    y: { ticks: { color: tick, font:{size:11} }, grid: { display: false } }
                }
            }
        });
    }

    /* ── Risk doughnut ── */
    const riskCtx = document.getElementById('riskChart');
    if (riskCtx) {
        new Chart(riskCtx, {
            type: 'doughnut',
            data: {
                labels: ['Low','Medium','High','Critical'],
                datasets: [{
                    data: [<?php echo implode(',', array_values($riskDist)); ?>],
                    backgroundColor: ['#334155','#3b9eff','#ffaa00','#ff3b3b'],
                    borderWidth: 0,
                    hoverOffset: 8,
                    spacing: 3,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '72%',
                plugins: { legend: { position:'bottom', labels:{ color:lbl, padding:14, font:{size:11}, boxWidth:10, boxHeight:10, borderRadius:3 } } }
            }
        });
    }

    /* ── Run button animation ── */
    document.getElementById('aForm')?.addEventListener('submit', () => {
        const btn = document.getElementById('runBtn');
        const ico = document.getElementById('runIco');
        const txt = document.getElementById('runTxt');
        btn.disabled = true;
        ico.className = 'fas fa-circle-notch fa-spin-fast';
        txt.textContent = 'Scanning…';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
