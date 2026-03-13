<?php
/**
 * StayWise Scheduled Analysis – run via cron ONLY
 *
 * Railway cron setup (in railway.json or dashboard):
 *   Command: php /app/tools/run_analysis.php
 *   Schedule: 0 2 * * *   (daily at 2 AM)
 *
 * Or trigger manually from the admin AI Insights page.
 */

define('STAYWISE_ROOT', dirname(__DIR__));
require_once STAYWISE_ROOT . '/config/db.php';
require_once STAYWISE_ROOT . '/includes/predictive_analytics.php';

$isCli = php_sapi_name() === 'cli';
$log   = fn($msg) => $isCli ? print($msg . "\n") : null;

$log("=== StayWise Analysis === " . date('Y-m-d H:i:s'));

ai_ensure_tables($conn);

// Log the run
$conn->query("INSERT INTO ai_analysis_log (analysis_type, status) VALUES ('scheduled_analysis','running')");
$logId     = $conn->insert_id;
$startTime = microtime(true);

// Run complaint-based pattern detection
$log("Detecting patterns from complaints...");
$results = ai_detect_patterns($conn);
$log("  Patterns found: {$results['patterns_found']}");
$log("  Predictions created: {$results['predictions_created']}");

// Finalize log
$elapsed = round(microtime(true) - $startTime, 2);
$details = json_encode($results);
$total   = $results['patterns_found'] + $results['predictions_created'];

$stmt = $conn->prepare(
    "UPDATE ai_analysis_log
     SET status='completed', records_processed=?, insights_generated=0, details=?, completed_at=NOW()
     WHERE log_id=?"
);
$stmt->bind_param('isi', $total, $details, $logId);
$stmt->execute();
$stmt->close();

$log("Done in {$elapsed}s");

if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'results' => $results, 'elapsed' => $elapsed]);
}
