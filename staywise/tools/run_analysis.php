<?php
/**
 * StayWise Automated Predictive Analysis
 *
 * This script should be run via cron/scheduler (e.g. daily at 2 AM):
 *   php C:\xampp\htdocs\StayWise\tools\run_analysis.php
 *
 * Or via Windows Task Scheduler:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\StayWise\tools\run_analysis.php
 *
 * Or run directly from the admin AI Insights dashboard.
 *
 * What it does:
 *  1. Detects recurring maintenance patterns from complaint history
 *  2. Generates predictions for upcoming maintenance needs
 *  3. Creates trend insights (complaint volume, categories, hotspots)
 *  4. Sends proactive notifications to admins and tenants
 *  5. Optionally runs LLM deep analysis via Groq
 */

define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/config/db.php';
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';

$isCli = php_sapi_name() === 'cli';
$output = function ($msg) use ($isCli) {
    if ($isCli) {
        echo $msg . "\n";
    }
};

$output("=== StayWise Predictive Analysis ===");
$output("Started at: " . date('Y-m-d H:i:s'));
$output("");

// Ensure tables exist
ai_ensure_tables($conn);

// Log the run
$conn->query("INSERT INTO ai_analysis_log (analysis_type, status) VALUES ('scheduled_analysis','running')");
$logId = $conn->insert_id;

$startTime = microtime(true);
$allResults = [];

// Step 1: Pattern Detection
$output("Step 1: Detecting maintenance patterns...");
$patternResults = ai_detect_patterns($conn);
$output("  Found {$patternResults['patterns_found']} patterns");
$output("  Created {$patternResults['predictions_created']} predictions");
$allResults['patterns'] = $patternResults;

// Step 2: Trend Analysis & Insights
$output("Step 2: Generating trend insights...");
$insightResults = ai_generate_insights($conn);
$output("  Generated {$insightResults['insights_created']} insights");
$allResults['insights'] = $insightResults;

// Step 3: Proactive Notifications
$output("Step 3: Sending proactive notifications...");
$notifCount = ai_generate_notifications($conn);
$output("  Sent $notifCount notifications");
$allResults['notifications'] = $notifCount;

// Step 4: LLM Deep Analysis (optional, only if API key is configured)
$output("Step 4: Running LLM deep analysis...");
$llmAnalysis = ai_llm_analyze_patterns($conn);
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
    $output("  LLM analysis generated and saved");
    $allResults['llm'] = 'generated';
} else {
    $output("  LLM analysis skipped (API key not configured or no data)");
    $allResults['llm'] = 'skipped';
}

// Finalize
$elapsed = round(microtime(true) - $startTime, 2);
$totalRecords = $patternResults['patterns_found'] + $patternResults['predictions_created'] + $insightResults['insights_created'];

$details = json_encode($allResults);
$stmt = $conn->prepare(
    "UPDATE ai_analysis_log SET status='completed', records_processed=?, insights_generated=?, details=?, completed_at=NOW()
     WHERE log_id=?"
);
$insightCount = $insightResults['insights_created'];
$stmt->bind_param('iisi', $totalRecords, $insightCount, $details, $logId);
$stmt->execute();
$stmt->close();

$output("");
$output("=== Analysis Complete ===");
$output("Total records processed: $totalRecords");
$output("Insights generated: $insightCount");
$output("Notifications sent: $notifCount");
$output("Time elapsed: {$elapsed}s");
$output("Completed at: " . date('Y-m-d H:i:s'));

// If not CLI, return JSON
if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'results' => $allResults,
        'elapsed' => $elapsed,
    ]);
}
