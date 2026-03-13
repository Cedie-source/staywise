<?php
/**
 * Admin AI Insights & Predictive Analytics Dashboard
 */

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

// Handle manual analysis run
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
        logAdminAction($conn, $_SESSION['user_id'], 'ai_analysis_run', "Manual analysis: {$patternResults['patterns_found']} patterns, {$patternResults['predictions_created']} predictions");
        $analysisSuccess = "Found {$patternResults['patterns_found']} patterns · Created {$patternResults['predictions_created']} predictions";
    }
}

// Handle prediction status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prediction'])) {
    if (function_exists('verify_csrf_token') && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $pId = (int)$_POST['prediction_id'];
        $newStatus = $_POST['new_status'];
        if (in_array($newStatus, ['acknowledged','resolved','dismissed'])) {
            $stmt = $conn->prepare("UPDATE ai_predictions SET status = ? WHERE prediction_id = ?");
            $stmt->bind_param('si', $newStatus, $pId); $stmt->execute(); $stmt->close();
            logAdminAction($conn, $_SESSION['user_id'], 'prediction_update', "Prediction #$pId set to $newStatus");
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
$lastRunText = $lr ? date('M d, g:i A', strtotime($lr['completed_at'])) : 'Never';

include '../includes/header.php';

function ai_category_icon(string $cat): string {
    return match($cat) {
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
function ai_risk_class(string $level): string {
    return match($level) {
        'critical' => 'risk-critical',
        'high'     => 'risk-high',
        'medium'   => 'risk-medium',
        default    => 'risk-low',
    };
}
function ai_risk_label(string $level): string {
    return match($level) {
        'critical' => 'Critical',
        'high'     => 'High',
        'medium'   => 'Medium',
        default    => 'Low',
    };
}
?>

<style>
/* ── AI Insights Page ── */
.ai-page { padding: 24px; max-width: 1400px; }

/* Page header */
.ai-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.ai-page-header-left h2 {
    font-size: 1.45rem;
    font-weight: 700;
    margin: 0 0 4px;
    letter-spacing: -.02em;
}
.ai-page-header-left p {
    font-size: .84rem;
    margin: 0;
}
body:not(.dark-mode) .ai-page-header-left h2 { color: #0f172a; }
body:not(.dark-mode) .ai-page-header-left p  { color: #64748b; }
body.dark-mode .ai-page-header-left h2 { color: #f1f5f9; }
body.dark-mode .ai-page-header-left p  { color: #64748b; }

/* Run analysis button */
.btn-run-analysis {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    font-size: .85rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all .2s;
    background: linear-gradient(135deg, #4ED6C1, #007DFE);
    color: #fff;
    box-shadow: 0 2px 12px rgba(78,214,193,.3);
    white-space: nowrap;
}
.btn-run-analysis:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 18px rgba(78,214,193,.4);
    color: #fff;
}
.btn-run-analysis:active { transform: translateY(0); }

.last-run-text {
    font-size: .75rem;
    text-align: right;
    margin-top: 6px;
}
body:not(.dark-mode) .last-run-text { color: #94a3b8; }
body.dark-mode .last-run-text { color: #475569; }

/* Alert banners */
.ai-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: .85rem;
    font-weight: 500;
    margin-bottom: 20px;
    border: 1px solid transparent;
}
.ai-alert-success {
    background: rgba(16,185,129,.08);
    border-color: rgba(16,185,129,.2);
    color: #059669;
}
.ai-alert-error {
    background: rgba(239,68,68,.08);
    border-color: rgba(239,68,68,.2);
    color: #dc2626;
}
body.dark-mode .ai-alert-success { color: #34d399; }
body.dark-mode .ai-alert-error   { color: #f87171; }

/* ── Stats row ── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) { .stats-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 500px) { .stats-row { grid-template-columns: 1fr 1fr; } }

.stat-card {
    border-radius: 14px;
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
    cursor: default;
}
.stat-card:hover { transform: translateY(-2px); }
body:not(.dark-mode) .stat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}
body.dark-mode .stat-card {
    background: #1a1a1a;
    border: 1px solid rgba(255,255,255,.07);
}
.stat-card-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .95rem;
    margin-bottom: 14px;
}
.stat-card-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 4px;
    letter-spacing: -.03em;
}
.stat-card-label {
    font-size: .78rem;
    font-weight: 500;
}
body:not(.dark-mode) .stat-card-value { color: #0f172a; }
body:not(.dark-mode) .stat-card-label { color: #94a3b8; }
body.dark-mode .stat-card-value { color: #f1f5f9; }
body.dark-mode .stat-card-label { color: #475569; }

/* Stat card accent variants */
.stat-teal  .stat-card-icon { background: rgba(78,214,193,.12); color: #4ED6C1; }
.stat-red   .stat-card-icon { background: rgba(239,68,68,.1);   color: #ef4444; }
.stat-blue  .stat-card-icon { background: rgba(59,130,246,.1);  color: #3b82f6; }
.stat-amber .stat-card-icon { background: rgba(245,158,11,.1);  color: #f59e0b; }
.stat-teal  .stat-card-value { color: #4ED6C1 !important; }
.stat-red   .stat-card-value { color: #ef4444 !important; }

/* ── Charts row ── */
.charts-row {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) { .charts-row { grid-template-columns: 1fr; } }

/* ── Card base ── */
.ai-card {
    border-radius: 14px;
    overflow: hidden;
}
body:not(.dark-mode) .ai-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}
body.dark-mode .ai-card {
    background: #1a1a1a;
    border: 1px solid rgba(255,255,255,.07);
}
.ai-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    font-size: .85rem;
    font-weight: 700;
    border-bottom: 1px solid transparent;
    gap: 8px;
}
body:not(.dark-mode) .ai-card-header {
    color: #0f172a;
    border-bottom-color: #f1f5f9;
}
body.dark-mode .ai-card-header {
    color: #e2e8f0;
    border-bottom-color: rgba(255,255,255,.06);
}
.ai-card-header i { color: #4ED6C1; }
.ai-card-body { padding: 20px; }
.ai-card-body-flush { padding: 0; }

/* Badge count */
.ai-badge-count {
    font-size: .72rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    background: rgba(78,214,193,.15);
    color: #4ED6C1;
}

/* ── Risk badges ── */
.risk-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.risk-critical { background: rgba(239,68,68,.12);   color: #ef4444; }
.risk-high      { background: rgba(245,158,11,.12);  color: #f59e0b; }
.risk-medium    { background: rgba(59,130,246,.12);  color: #3b82f6; }
.risk-low       { background: rgba(100,116,139,.1);  color: #64748b; }

/* ── Predictions table ── */
.pred-table { width: 100%; border-collapse: collapse; }
.pred-table th {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 10px 16px;
    text-align: left;
    white-space: nowrap;
}
.pred-table td {
    padding: 14px 16px;
    font-size: .84rem;
    vertical-align: middle;
}
body:not(.dark-mode) .pred-table th {
    color: #94a3b8;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}
body:not(.dark-mode) .pred-table td {
    color: #374151;
    border-bottom: 1px solid #f8fafc;
}
body:not(.dark-mode) .pred-table tr:hover td { background: #f8fafc; }
body.dark-mode .pred-table th {
    color: #475569;
    border-bottom: 1px solid rgba(255,255,255,.05);
    background: rgba(255,255,255,.02);
}
body.dark-mode .pred-table td {
    color: #cbd5e1;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
body.dark-mode .pred-table tr:hover td { background: rgba(255,255,255,.02); }

.pred-table .rec-row td {
    font-size: .78rem;
    padding: 6px 16px 12px;
}
body:not(.dark-mode) .pred-table .rec-row td { color: #64748b; background: #fafbfc; border-bottom: 1px solid #f1f5f9; }
body.dark-mode .pred-table .rec-row td { color: #64748b; background: rgba(255,255,255,.01); }

/* Unit badge */
.unit-badge {
    display: inline-block;
    font-size: .75rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 7px;
    background: rgba(0,125,254,.1);
    color: #007DFE;
}
body.dark-mode .unit-badge { background: rgba(0,125,254,.15); color: #60a5fa; }

/* Category chip */
.cat-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: .8rem;
    font-weight: 500;
}
body:not(.dark-mode) .cat-chip { color: #374151; }
body.dark-mode .cat-chip { color: #cbd5e1; }
.cat-chip i { color: #4ED6C1; font-size: .75rem; }

/* Confidence bar */
.conf-bar-wrap { width: 80px; }
.conf-bar-track {
    height: 6px;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 2px;
}
body:not(.dark-mode) .conf-bar-track { background: #e2e8f0; }
body.dark-mode .conf-bar-track { background: rgba(255,255,255,.08); }
.conf-bar-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, #4ED6C1, #007DFE);
    transition: width .6s ease;
}
.conf-bar-pct {
    font-size: .7rem;
    font-weight: 600;
}
body:not(.dark-mode) .conf-bar-pct { color: #94a3b8; }
body.dark-mode .conf-bar-pct { color: #475569; }

/* Date display */
.pred-date {
    font-size: .82rem;
    font-weight: 600;
    white-space: nowrap;
}
body:not(.dark-mode) .pred-date { color: #374151; }
body.dark-mode .pred-date { color: #cbd5e1; }
.pred-date-soon { color: #f59e0b !important; }
.pred-date-overdue { color: #ef4444 !important; }

/* Action buttons */
.pred-actions { display: flex; gap: 4px; }
.pred-action-btn {
    width: 28px; height: 28px;
    border-radius: 7px;
    border: 1px solid transparent;
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem;
    cursor: pointer;
    transition: all .15s;
    background: none;
}
body:not(.dark-mode) .pred-action-btn { border-color: #e2e8f0; color: #64748b; }
body:not(.dark-mode) .pred-action-btn:hover { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
body.dark-mode .pred-action-btn { border-color: rgba(255,255,255,.08); color: #64748b; }
body.dark-mode .pred-action-btn:hover { background: rgba(255,255,255,.07); color: #e2e8f0; }
.pred-action-btn.resolve:hover { border-color: #4ED6C1; color: #4ED6C1; }
.pred-action-btn.dismiss:hover { border-color: #ef4444; color: #ef4444; }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 48px 24px;
}
.empty-state-icon {
    width: 56px; height: 56px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    margin: 0 auto 16px;
    background: rgba(78,214,193,.08);
    color: #4ED6C1;
}
.empty-state h6 { font-size: .9rem; font-weight: 600; margin-bottom: 6px; }
.empty-state p  { font-size: .82rem; margin: 0; }
body:not(.dark-mode) .empty-state h6 { color: #374151; }
body:not(.dark-mode) .empty-state p  { color: #94a3b8; }
body.dark-mode .empty-state h6 { color: #cbd5e1; }
body.dark-mode .empty-state p  { color: #475569; }

/* ── Patterns list ── */
.pattern-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid transparent;
    transition: background .12s;
}
body:not(.dark-mode) .pattern-item { border-bottom-color: #f1f5f9; }
body:not(.dark-mode) .pattern-item:hover { background: #f8fafc; }
body.dark-mode .pattern-item { border-bottom-color: rgba(255,255,255,.04); }
body.dark-mode .pattern-item:hover { background: rgba(255,255,255,.02); }
.pattern-item:last-child { border-bottom: none; }
.pattern-icon {
    width: 36px; height: 36px; flex-shrink: 0;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem;
    background: rgba(78,214,193,.1);
    color: #4ED6C1;
}
.pattern-name { font-size: .85rem; font-weight: 600; }
.pattern-meta { font-size: .75rem; margin-top: 2px; }
body:not(.dark-mode) .pattern-name { color: #0f172a; }
body:not(.dark-mode) .pattern-meta { color: #94a3b8; }
body.dark-mode .pattern-name { color: #e2e8f0; }
body.dark-mode .pattern-meta { color: #475569; }

/* ── History table ── */
.hist-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.hist-table th {
    font-size: .7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; padding: 10px 16px; text-align: left;
}
.hist-table td { padding: 10px 16px; }
body:not(.dark-mode) .hist-table th { color: #94a3b8; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
body:not(.dark-mode) .hist-table td { color: #374151; border-bottom: 1px solid #f8fafc; }
body.dark-mode .hist-table th { color: #475569; border-bottom: 1px solid rgba(255,255,255,.05); background: rgba(255,255,255,.02); }
body.dark-mode .hist-table td { color: #94a3b8; border-bottom: 1px solid rgba(255,255,255,.04); }

.status-pill {
    display: inline-block;
    font-size: .68rem; font-weight: 700; padding: 2px 8px;
    border-radius: 5px; text-transform: uppercase; letter-spacing: .04em;
}
.status-completed { background: rgba(16,185,129,.1); color: #10b981; }
.status-running   { background: rgba(245,158,11,.1);  color: #f59e0b; }
.status-failed    { background: rgba(239,68,68,.1);    color: #ef4444; }

/* 2-col layout */
.bottom-row {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 900px) { .bottom-row { grid-template-columns: 1fr; } }
</style>

<div class="ai-page">

    <!-- Page Header -->
    <div class="ai-page-header">
        <div class="ai-page-header-left">
            <h2><i class="fas fa-brain me-2" style="color:#4ED6C1;"></i>AI Insights</h2>
            <p>Complaint-based maintenance predictions · <?php echo count($summary['predictions']); ?> active</p>
        </div>
        <div style="text-align:right;">
            <form method="POST" class="d-inline" id="analysisForm">
                <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                <input type="hidden" name="run_analysis" value="1">
                <button type="submit" class="btn-run-analysis" id="runAnalysisBtn">
                    <i class="fas fa-sync-alt" id="runIcon"></i> Run Analysis
                </button>
            </form>
            <div class="last-run-text"><i class="fas fa-clock me-1"></i>Last run: <?php echo $lastRunText; ?></div>
        </div>
    </div>

    <?php if (!empty($analysisSuccess)): ?>
    <div class="ai-alert ai-alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($analysisSuccess); ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($analysisError)): ?>
    <div class="ai-alert ai-alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($analysisError); ?>
    </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card stat-teal">
            <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-card-value"><?php echo $summary['stats']['active_predictions']; ?></div>
            <div class="stat-card-label">Active Predictions</div>
        </div>
        <div class="stat-card stat-red">
            <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-card-value"><?php echo $summary['stats']['high_risk']; ?></div>
            <div class="stat-card-label">High / Critical</div>
        </div>
        <div class="stat-card stat-blue">
            <div class="stat-card-icon"><i class="fas fa-project-diagram"></i></div>
            <div class="stat-card-value"><?php echo $summary['stats']['patterns_detected']; ?></div>
            <div class="stat-card-label">Patterns Found</div>
        </div>
        <div class="stat-card stat-amber">
            <div class="stat-card-icon"><i class="fas fa-history"></i></div>
            <div class="stat-card-value"><?php echo count($summary['analysis_logs']); ?></div>
            <div class="stat-card-label">Analysis Runs</div>
        </div>
    </div>

    <!-- Charts Row -->
    <?php if (count($catData) > 0 || array_sum($riskDist) > 0): ?>
    <div class="charts-row" style="margin-bottom:24px;">
        <div class="ai-card">
            <div class="ai-card-header">
                <span><i class="fas fa-chart-bar"></i> Complaint Categories</span>
            </div>
            <div class="ai-card-body">
                <canvas id="categoryChart" style="max-height:220px;"></canvas>
            </div>
        </div>
        <div class="ai-card">
            <div class="ai-card-header">
                <span><i class="fas fa-shield-alt"></i> Risk Distribution</span>
            </div>
            <div class="ai-card-body">
                <canvas id="riskChart" style="max-height:220px;"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Predictions Table -->
    <div class="ai-card" style="margin-bottom:24px;">
        <div class="ai-card-header">
            <span><i class="fas fa-crystal-ball"></i> Active Predictions</span>
            <span class="ai-badge-count"><?php echo count($summary['predictions']); ?></span>
        </div>
        <div class="ai-card-body-flush">
            <?php if (count($summary['predictions']) > 0): ?>
            <div style="overflow-x:auto;">
                <table class="pred-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Category</th>
                            <th>Risk</th>
                            <th>Prediction</th>
                            <th>Predicted Date</th>
                            <th>Confidence</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['predictions'] as $pred): ?>
                        <?php
                            $pd = $pred['predicted_date'] ? strtotime($pred['predicted_date']) : null;
                            $daysAway = $pd ? (int)(($pd - time()) / 86400) : null;
                            $dateClass = '';
                            if ($daysAway !== null) {
                                if ($daysAway < 0) $dateClass = 'pred-date-overdue';
                                elseif ($daysAway <= 7) $dateClass = 'pred-date-soon';
                            }
                        ?>
                        <tr>
                            <td><span class="unit-badge"><?php echo htmlspecialchars($pred['unit_number']); ?></span></td>
                            <td>
                                <span class="cat-chip">
                                    <i class="fas <?php echo ai_category_icon($pred['category']); ?>"></i>
                                    <?php echo ucfirst(htmlspecialchars($pred['category'])); ?>
                                </span>
                            </td>
                            <td><span class="risk-badge <?php echo ai_risk_class($pred['risk_level']); ?>"><?php echo ai_risk_label($pred['risk_level']); ?></span></td>
                            <td style="max-width:300px;">
                                <span style="font-size:.82rem;"><?php echo htmlspecialchars(mb_strimwidth($pred['prediction_text'], 0, 110, '…')); ?></span>
                            </td>
                            <td>
                                <?php if ($pd): ?>
                                <span class="pred-date <?php echo $dateClass; ?>">
                                    <?php echo date('M d, Y', $pd); ?>
                                    <?php if ($daysAway !== null && $daysAway < 0): ?>
                                        <br><span style="font-size:.7rem;font-weight:500;">Overdue</span>
                                    <?php elseif ($daysAway !== null && $daysAway <= 7): ?>
                                        <br><span style="font-size:.7rem;font-weight:500;">Soon</span>
                                    <?php endif; ?>
                                </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <div class="conf-bar-wrap">
                                    <div class="conf-bar-track">
                                        <div class="conf-bar-fill" style="width:<?php echo round($pred['confidence_score']); ?>%"></div>
                                    </div>
                                    <div class="conf-bar-pct"><?php echo round($pred['confidence_score']); ?>%</div>
                                </div>
                            </td>
                            <td>
                                <div class="pred-actions">
                                    <form method="POST" class="d-inline">
                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                        <input type="hidden" name="update_prediction" value="1">
                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                        <input type="hidden" name="new_status" value="acknowledged">
                                        <button type="submit" class="pred-action-btn" title="Acknowledge"><i class="fas fa-check"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                        <input type="hidden" name="update_prediction" value="1">
                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                        <input type="hidden" name="new_status" value="resolved">
                                        <button type="submit" class="pred-action-btn resolve" title="Mark Resolved"><i class="fas fa-check-double"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                        <input type="hidden" name="update_prediction" value="1">
                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                        <input type="hidden" name="new_status" value="dismissed">
                                        <button type="submit" class="pred-action-btn dismiss" title="Dismiss"><i class="fas fa-times"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php if (!empty($pred['recommended_action'])): ?>
                        <tr class="rec-row">
                            <td colspan="7">
                                <i class="fas fa-lightbulb me-1" style="color:#f59e0b;"></i>
                                <strong>Recommended:</strong> <?php echo htmlspecialchars($pred['recommended_action']); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-chart-line"></i></div>
                <h6>No active predictions</h6>
                <p>Click "Run Analysis" to detect patterns from complaint history</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Patterns + History -->
    <div class="bottom-row">

        <!-- Recurring Patterns -->
        <div class="ai-card">
            <div class="ai-card-header">
                <span><i class="fas fa-project-diagram"></i> Recurring Patterns</span>
                <span class="ai-badge-count"><?php echo count($summary['patterns']); ?></span>
            </div>
            <?php if (count($summary['patterns']) > 0): ?>
            <div class="ai-card-body-flush">
                <?php foreach (array_slice($summary['patterns'], 0, 10) as $pat): ?>
                <div class="pattern-item">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div class="pattern-icon"><i class="fas <?php echo ai_category_icon($pat['category']); ?>"></i></div>
                        <div>
                            <div class="pattern-name">
                                <?php echo ucfirst(htmlspecialchars($pat['category'])); ?>
                                <span class="unit-badge ms-1" style="font-size:.68rem;">Unit <?php echo htmlspecialchars($pat['unit_number']); ?></span>
                            </div>
                            <div class="pattern-meta">
                                <?php echo $pat['occurrence_count']; ?> complaints
                                <?php if ($pat['recurrence_interval_days']): ?>
                                · every ~<?php echo $pat['recurrence_interval_days']; ?> days
                                <?php endif; ?>
                                <?php if ($pat['next_predicted_date']): ?>
                                · next: <?php echo date('M d', strtotime($pat['next_predicted_date'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <span class="risk-badge <?php echo ai_risk_class($pat['severity']); ?>"><?php echo ucfirst($pat['severity']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-project-diagram"></i></div>
                <h6>No patterns detected</h6>
                <p>Patterns appear when 3+ complaints of the same type occur in a unit</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Analysis History -->
        <div class="ai-card">
            <div class="ai-card-header">
                <span><i class="fas fa-history"></i> Analysis History</span>
            </div>
            <?php if (count($summary['analysis_logs']) > 0): ?>
            <div class="ai-card-body-flush">
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['analysis_logs'] as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', $log['analysis_type'])); ?></td>
                            <td>
                                <span class="status-pill status-<?php echo $log['status']; ?>">
                                    <?php echo ucfirst($log['status']); ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php echo $log['completed_at'] ? date('M d, g:i A', strtotime($log['completed_at'])) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fas fa-history"></i></div>
                <h6>No runs yet</h6>
                <p>Analysis history will appear here</p>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.body.classList.contains('dark-mode');
    const gridColor  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.05)';
    const tickColor  = isDark ? '#475569' : '#94a3b8';
    const labelColor = isDark ? '#94a3b8' : '#64748b';

    // Category chart
    const catCtx = document.getElementById('categoryChart');
    if (catCtx) {
        const labels = <?php echo json_encode(array_map('ucfirst', array_keys($catData))); ?>;
        const values = <?php echo json_encode(array_values($catData)); ?>;
        new Chart(catCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Complaints',
                    data: values,
                    backgroundColor: 'rgba(78,214,193,.7)',
                    borderColor: '#4ED6C1',
                    borderWidth: 1,
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { color: tickColor, stepSize: 1 }, grid: { color: gridColor } },
                    y: { ticks: { color: tickColor }, grid: { display: false } }
                }
            }
        });
    }

    // Risk doughnut
    const riskCtx = document.getElementById('riskChart');
    if (riskCtx) {
        new Chart(riskCtx, {
            type: 'doughnut',
            data: {
                labels: ['Low','Medium','High','Critical'],
                datasets: [{
                    data: [<?php echo implode(',', array_values($riskDist)); ?>],
                    backgroundColor: ['#64748b','#3b82f6','#f59e0b','#ef4444'],
                    borderWidth: 0,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: labelColor, padding: 12, font: { size: 11 } } }
                }
            }
        });
    }

    // Animate run button
    document.getElementById('analysisForm')?.addEventListener('submit', function() {
        const btn = document.getElementById('runAnalysisBtn');
        const icon = document.getElementById('runIcon');
        btn.disabled = true;
        icon.className = 'fas fa-spinner fa-spin';
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing…';
    });
});
</script>

<?php include '../includes/footer.php'; ?>
