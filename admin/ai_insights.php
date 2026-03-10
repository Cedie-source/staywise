<?php
/**
 * Admin AI Insights & Predictive Analytics Dashboard
 *
 * Provides landlords with data-driven insights, predictions,
 * maintenance pattern analysis, and recommended actions.
 */

require_once '../includes/security.php';
set_secure_session_cookies(); // Must be before session_start()
session_start();
require_once '../config/db.php';

// Admin-only access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../index.php");
    exit();
}

// Force password change guard
try {
    if (function_exists('db_column_exists') && db_column_exists($conn, 'users', 'force_password_change')) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id = ?");
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row && intval($row['force_password_change']) === 1) {
                $_SESSION['must_change_password'] = true;
                header('Location: ../change_password.php');
                exit();
            }
        }
    }
} catch (Throwable $e) { /* ignore */ }

define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';
require_once STAYWISE_ROOT . '/includes/logger.php';

$page_title = "AI Insights & Predictions";

// Handle manual analysis run
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_analysis'])) {
    if (function_exists('verify_csrf_token') && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $analysisError = 'Invalid CSRF token.';
    } else {
        // Log the analysis run start
        $conn->query("INSERT INTO ai_analysis_log (analysis_type, status) VALUES ('manual_full_analysis','running')");
        $logId = $conn->insert_id;

        $patternResults = ai_detect_patterns($conn);
        $insightResults = ai_generate_insights($conn);
        $notifCount = ai_generate_notifications($conn);
        $llmAnalysis = ai_llm_analyze_patterns($conn);

        // Store LLM analysis as an insight if available
        if ($llmAnalysis) {
            $llmTitle = "AI Deep Analysis - " . date('M d, Y');
            $conn->query("DELETE FROM ai_insights WHERE title LIKE 'AI Deep Analysis%' AND is_active = 1");
            $stmt = $conn->prepare(
                "INSERT INTO ai_insights (insight_type, category, title, description, severity, period_start, period_end)
                 VALUES ('recommendation','ai_analysis',?,?,'info',?,?)"
            );
            $now = date('Y-m-d');
            $ago = date('Y-m-d', strtotime('-60 days'));
            $stmt->bind_param('ssss', $llmTitle, $llmAnalysis, $ago, $now);
            $stmt->execute();
            $stmt->close();
            $insightResults['insights_created']++;
        }

        $totalInsights = $insightResults['insights_created'];
        $totalRecords = $patternResults['patterns_found'] + $patternResults['predictions_created'] + $totalInsights;
        $details = json_encode([
            'patterns' => $patternResults,
            'insights' => $insightResults,
            'notifications' => $notifCount,
            'llm_analysis' => $llmAnalysis ? 'generated' : 'skipped'
        ]);

        $stmt = $conn->prepare(
            "UPDATE ai_analysis_log SET status='completed', records_processed=?, insights_generated=?, details=?, completed_at=NOW()
             WHERE log_id=?"
        );
        $stmt->bind_param('iisi', $totalRecords, $totalInsights, $details, $logId);
        $stmt->execute();
        $stmt->close();

        logAdminAction($conn, $_SESSION['user_id'], 'ai_analysis_run', "Manual analysis: $totalRecords records, $totalInsights insights, $notifCount notifications");
        $analysisSuccess = "Analysis complete! Found {$patternResults['patterns_found']} patterns, created {$patternResults['predictions_created']} predictions, generated $totalInsights insights, and sent $notifCount notifications.";
    }
}

// Handle prediction status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prediction'])) {
    if (function_exists('verify_csrf_token') && verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $pId = (int)$_POST['prediction_id'];
        $newStatus = $_POST['new_status'];
        if (in_array($newStatus, ['acknowledged','resolved','dismissed'])) {
            $stmt = $conn->prepare("UPDATE ai_predictions SET status = ? WHERE prediction_id = ?");
            $stmt->bind_param('si', $newStatus, $pId);
            $stmt->execute();
            $stmt->close();
            logAdminAction($conn, $_SESSION['user_id'], 'prediction_update', "Prediction #$pId set to $newStatus");
        }
    }
}

// Fetch dashboard data
$summary = ai_get_admin_summary($conn);

// Category chart data
$catData = [];
foreach ($summary['patterns'] as $p) {
    $catData[$p['category']] = ($catData[$p['category']] ?? 0) + (int)$p['occurrence_count'];
}
arsort($catData);

// Risk level distribution
$riskDist = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
foreach ($summary['predictions'] as $p) {
    $riskDist[$p['risk_level']]++;
}

include '../includes/header.php';
?>

<div class="container mt-4 admin-ui">
    <!-- Header -->
    <div class="p-4 mb-4 rounded-4 shadow-lg bg-gradient dashboard-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1 class="mb-2 fw-bold dashboard-title">
                    <i class="fas fa-brain me-3"></i>AI Insights & Predictive Analytics
                </h1>
                <p class="mb-1 fs-5 dashboard-title">Data-driven decision support for proactive property management</p>
                <span class="opacity-75 dashboard-desc">Predictions, patterns, and actionable recommendations powered by AI</span>
            </div>
            <div class="col-md-4 text-md-end">
                <form method="POST" class="d-inline">
                    <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                    <input type="hidden" name="run_analysis" value="1">
                    <button type="submit" class="btn btn-light btn-lg shadow-sm" id="runAnalysisBtn">
                        <i class="fas fa-sync-alt me-2"></i>Run Analysis
                    </button>
                </form>
                <div class="mt-2">
                    <small class="dashboard-desc">
                        <?php
                        $lastRun = $conn->query("SELECT completed_at FROM ai_analysis_log WHERE status='completed' ORDER BY completed_at DESC LIMIT 1");
                        $lr = $lastRun ? $lastRun->fetch_assoc() : null;
                        echo $lr ? 'Last run: ' . date('M d, g:i A', strtotime($lr['completed_at'])) : 'No analysis run yet';
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($analysisSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($analysisSuccess); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($analysisError)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($analysisError); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-crystal-ball fa-2x dashboard-title" style="font-size:1.8rem;"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $summary['stats']['active_predictions']; ?></h2>
                <span class="fs-6 dashboard-title">Active Predictions</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4" style="border-left: 4px solid #dc3545 !important;">
                <div class="mb-2"><i class="fas fa-exclamation-triangle fa-2x" style="color:#dc3545;"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $summary['stats']['high_risk']; ?></h2>
                <span class="fs-6 dashboard-title">High/Critical Risks</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-lightbulb fa-2x" style="color:#ffc107;"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $summary['stats']['active_insights']; ?></h2>
                <span class="fs-6 dashboard-title">Active Insights</span>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card hover-card border-0 rounded-4 text-center py-4">
                <div class="mb-2"><i class="fas fa-project-diagram fa-2x" style="color:#0d6efd;"></i></div>
                <h2 class="fw-bold mb-0 dashboard-title"><?php echo $summary['stats']['patterns_detected']; ?></h2>
                <span class="fs-6 dashboard-title">Patterns Detected</span>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-md-7">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar me-2"></i>Issue Categories Distribution
                </div>
                <div class="card-body">
                    <?php if (count($catData) > 0): ?>
                        <canvas id="categoryChart" height="250"></canvas>
                    <?php else: ?>
                        <p class="text-center no-record-message py-4">No pattern data yet. Run analysis to detect patterns.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-shield-alt me-2"></i>Risk Level Distribution
                </div>
                <div class="card-body">
                    <?php if (array_sum($riskDist) > 0): ?>
                        <canvas id="riskChart" height="250"></canvas>
                    <?php else: ?>
                        <p class="text-center no-record-message py-4">No predictions yet. Run analysis to generate predictions.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Predictions Table -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-chart-line me-2"></i>Active Predictions & Maintenance Forecast</span>
                    <span class="badge bg-primary"><?php echo count($summary['predictions']); ?> active</span>
                </div>
                <div class="card-body">
                    <?php if (count($summary['predictions']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Unit</th>
                                        <th>Category</th>
                                        <th>Risk</th>
                                        <th>Prediction</th>
                                        <th>Predicted Date</th>
                                        <th>Confidence</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summary['predictions'] as $pred): ?>
                                        <tr>
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($pred['unit_number']); ?></span></td>
                                            <td>
                                                <i class="fas <?php echo ai_category_icon($pred['category']); ?> me-1"></i>
                                                <?php echo ucfirst(htmlspecialchars($pred['category'])); ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo ai_risk_badge($pred['risk_level']); ?>">
                                                    <?php echo ucfirst($pred['risk_level']); ?>
                                                </span>
                                            </td>
                                            <td class="small"><?php echo htmlspecialchars(mb_strimwidth($pred['prediction_text'], 0, 120, '...')); ?></td>
                                            <td>
                                                <?php
                                                if ($pred['predicted_date']) {
                                                    $pd = strtotime($pred['predicted_date']);
                                                    $daysAway = (int)((($pd - time()) / 86400));
                                                    echo date('M d, Y', $pd);
                                                    if ($daysAway <= 7 && $daysAway >= 0) echo ' <span class="badge bg-warning text-dark">Soon</span>';
                                                    if ($daysAway < 0) echo ' <span class="badge bg-danger">Overdue</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="progress" style="height: 20px; min-width: 80px;">
                                                    <div class="progress-bar <?php echo $pred['confidence_score'] >= 75 ? 'bg-success' : ($pred['confidence_score'] >= 50 ? 'bg-warning' : 'bg-secondary'); ?>"
                                                         style="width: <?php echo $pred['confidence_score']; ?>%">
                                                        <?php echo round($pred['confidence_score']); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <form method="POST" class="d-inline">
                                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                                        <input type="hidden" name="update_prediction" value="1">
                                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                                        <input type="hidden" name="new_status" value="acknowledged">
                                                        <button type="submit" class="btn btn-outline-primary btn-sm" title="Acknowledge">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline">
                                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                                        <input type="hidden" name="update_prediction" value="1">
                                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                                        <input type="hidden" name="new_status" value="resolved">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" title="Mark Resolved">
                                                            <i class="fas fa-check-double"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline">
                                                        <?php if (function_exists('csrf_input')) echo csrf_input(); ?>
                                                        <input type="hidden" name="update_prediction" value="1">
                                                        <input type="hidden" name="prediction_id" value="<?php echo $pred['prediction_id']; ?>">
                                                        <input type="hidden" name="new_status" value="dismissed">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Dismiss">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php if (!empty($pred['recommended_action'])): ?>
                                            <tr class="table-light">
                                                <td colspan="7" class="small ps-4">
                                                    <i class="fas fa-lightbulb text-warning me-1"></i>
                                                    <strong>Recommended:</strong> <?php echo htmlspecialchars($pred['recommended_action']); ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-chart-line fa-3x mb-3" style="color: #ccc;"></i>
                            <p class="no-record-message">No active predictions. Click "Run Analysis" to analyze maintenance patterns.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Insights & Patterns Row -->
    <div class="row g-4 mb-4">
        <!-- AI Insights -->
        <div class="col-md-7">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-lightbulb me-2"></i>AI-Generated Insights
                </div>
                <div class="card-body">
                    <?php if (count($summary['insights']) > 0): ?>
                        <?php foreach ($summary['insights'] as $insight): ?>
                            <div class="alert alert-<?php echo $insight['severity'] === 'critical' ? 'danger' : ($insight['severity'] === 'warning' ? 'warning' : 'info'); ?> mb-3">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="alert-heading mb-1">
                                            <i class="fas <?php echo ai_insight_icon($insight['insight_type']); ?> me-1"></i>
                                            <?php echo htmlspecialchars($insight['title']); ?>
                                        </h6>
                                        <p class="mb-1 small"><?php echo nl2br(htmlspecialchars($insight['description'])); ?></p>
                                        <small class="text-muted">
                                            <?php echo ucfirst($insight['insight_type']); ?> •
                                            <?php echo date('M d, Y', strtotime($insight['created_at'])); ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?php echo $insight['severity'] === 'critical' ? 'danger' : ($insight['severity'] === 'warning' ? 'warning text-dark' : 'info'); ?>">
                                        <?php echo ucfirst($insight['severity']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center no-record-message py-4">No insights generated yet. Run analysis to discover trends.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Maintenance Patterns -->
        <div class="col-md-5">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-project-diagram me-2"></i>Recurring Patterns
                </div>
                <div class="card-body">
                    <?php if (count($summary['patterns']) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($summary['patterns'], 0, 8) as $pattern): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>
                                                <i class="fas <?php echo ai_category_icon($pattern['category']); ?> me-1"></i>
                                                <?php echo ucfirst(htmlspecialchars($pattern['category'])); ?>
                                            </strong>
                                            <?php if ($pattern['unit_number']): ?>
                                                <span class="badge bg-primary ms-1">Unit <?php echo htmlspecialchars($pattern['unit_number']); ?></span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo $pattern['occurrence_count']; ?> occurrences
                                                <?php if ($pattern['recurrence_interval_days']): ?>
                                                    • every ~<?php echo $pattern['recurrence_interval_days']; ?> days
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-<?php echo $pattern['severity'] === 'high' ? 'danger' : ($pattern['severity'] === 'medium' ? 'warning text-dark' : 'secondary'); ?>">
                                            <?php echo ucfirst($pattern['severity']); ?>
                                        </span>
                                    </div>
                                    <?php if ($pattern['next_predicted_date']): ?>
                                        <small class="text-info">
                                            <i class="fas fa-calendar-alt me-1"></i>Next predicted: <?php echo date('M d, Y', strtotime($pattern['next_predicted_date'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-center no-record-message py-4">No patterns detected yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Analysis History -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history me-2"></i>Analysis History
                </div>
                <div class="card-body">
                    <?php if (count($summary['analysis_logs']) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Records</th>
                                        <th>Insights</th>
                                        <th>Started</th>
                                        <th>Completed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summary['analysis_logs'] as $log): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($log['analysis_type']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $log['status'] === 'completed' ? 'success' : ($log['status'] === 'failed' ? 'danger' : 'warning'); ?>">
                                                    <?php echo ucfirst($log['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $log['records_processed']; ?></td>
                                            <td><?php echo $log['insights_generated']; ?></td>
                                            <td><small><?php echo date('M d, g:i A', strtotime($log['started_at'])); ?></small></td>
                                            <td><small><?php echo $log['completed_at'] ? date('M d, g:i A', strtotime($log['completed_at'])) : '-'; ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center no-record-message py-3">No analysis runs recorded.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
/* ---- UI helper functions ---- */
function ai_category_icon(string $cat): string {
    return match($cat) {
        'plumbing' => 'fa-faucet',
        'electrical' => 'fa-bolt',
        'structural' => 'fa-building',
        'appliance' => 'fa-blender',
        'pest' => 'fa-bug',
        'security' => 'fa-shield-alt',
        'cleaning' => 'fa-broom',
        'noise' => 'fa-volume-up',
        default => 'fa-wrench',
    };
}

function ai_risk_badge(string $level): string {
    return match($level) {
        'critical' => 'bg-danger',
        'high' => 'bg-warning text-dark',
        'medium' => 'bg-info text-dark',
        'low' => 'bg-secondary',
        default => 'bg-secondary',
    };
}

function ai_insight_icon(string $type): string {
    return match($type) {
        'trend' => 'fa-chart-line',
        'pattern' => 'fa-project-diagram',
        'anomaly' => 'fa-exclamation-triangle',
        'recommendation' => 'fa-lightbulb',
        default => 'fa-info-circle',
    };
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Category distribution chart
    var catCtx = document.getElementById('categoryChart');
    if (catCtx) {
        const catLabels = <?php echo json_encode(array_map('ucfirst', array_keys($catData))); ?>;
        const catValues = <?php echo json_encode(array_values($catData)); ?>;
        const catColors = [
            '#3b82f6', '#ef4444', '#f59e0b', '#10b981', '#8b5cf6',
            '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1'
        ];

        new Chart(catCtx, {
            type: 'bar',
            data: {
                labels: catLabels,
                datasets: [{
                    label: 'Occurrences',
                    data: catValues,
                    backgroundColor: catColors.slice(0, catLabels.length),
                    borderRadius: 6,
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    // Risk distribution chart
    var riskCtx = document.getElementById('riskChart');
    if (riskCtx) {
        new Chart(riskCtx, {
            type: 'doughnut',
            data: {
                labels: ['Low', 'Medium', 'High', 'Critical'],
                datasets: [{
                    data: [<?php echo implode(',', array_values($riskDist)); ?>],
                    backgroundColor: ['#6b7280', '#06b6d4', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // Animate run button
    const runBtn = document.getElementById('runAnalysisBtn');
    if (runBtn) {
        runBtn.closest('form').addEventListener('submit', function() {
            runBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Analyzing...';
            runBtn.disabled = true;
        });
    }
});
</script>

<style>
/* AI Insights page specific styles */
.admin-ui .progress {
    border-radius: 10px;
}
.admin-ui .progress-bar {
    font-size: 0.75rem;
    font-weight: 600;
}
body.dark-mode .table-light td {
    background: #1a1d1e !important;
    color: #e0e1dd !important;
}
body.dark-mode .alert-info {
    background: #0c2d48 !important;
    color: #cff4fc !important;
    border-color: #0f3d5e !important;
}
body.dark-mode .alert-warning {
    background: #332d00 !important;
    color: #fff3cd !important;
    border-color: #4d4300 !important;
}
body.dark-mode .alert-danger {
    background: #2c0b0e !important;
    color: #f8d7da !important;
    border-color: #450e14 !important;
}
body.dark-mode .list-group-item {
    border-color: #2b3035 !important;
}
</style>

<?php include '../includes/footer.php'; ?>
