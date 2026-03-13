<?php
/**
 * StayWise Predictive Analytics Engine
 *
 * Predictions are based SOLELY on complaint history.
 * A prediction is only created when the same complaint category
 * has occurred 3+ times in the same unit (raised from 2 to reduce false positives).
 *
 * ai_detect_patterns() should only be called from the cron job (tools/run_analysis.php)
 * or from the admin "Run Analysis" button — NOT on every page load.
 */

if (!defined('STAYWISE_ROOT')) {
    define('STAYWISE_ROOT', dirname(__DIR__));
}
require_once STAYWISE_ROOT . '/config/db.php';

/* ================================================================
   TABLE AUTO-CREATION
   ================================================================ */

function ai_ensure_tables($conn) {
    $tables = [
        'ai_predictions', 'ai_notifications', 'ai_insights',
        'maintenance_patterns', 'chat_history', 'ai_analysis_log'
    ];
    foreach ($tables as $t) {
        $res = $conn->query("SHOW TABLES LIKE '$t'");
        if ($res && $res->num_rows === 0) {
            $sql = file_get_contents(STAYWISE_ROOT . '/project/migrations/20260224_predictive_ai_tables.sql');
            if ($sql) { $conn->multi_query($sql); while ($conn->next_result()) {} }
            break;
        }
    }
}

/* ================================================================
   CATEGORY CLASSIFIER
   Maps complaint title/description text to a maintenance category.
   Only includes categories tenants actually complain about.
   ================================================================ */

function ai_classify_category(string $text): string {
    $text = strtolower($text);
    $map = [
        'plumbing'   => ['leak','pipe','faucet','drain','clog','toilet','water pressure','sewage','plumb','water','flood'],
        'electrical' => ['electric','wiring','outlet','switch','circuit','breaker','power','light','bulb','spark','voltage','blackout'],
        'structural' => ['crack','wall','ceiling','floor','foundation','door','window','frame','roof','paint','tile','damp','mold','mould'],
        'appliance'  => ['refrigerator','fridge','stove','oven','washer','dryer','appliance','microwave'],
        'pest'       => ['pest','cockroach','rat','mice','mouse','ant','termite','bug','insect','rodent'],
        'security'   => ['lock','key','gate','cctv','camera','alarm','security','theft','break-in'],
        'cleaning'   => ['clean','garbage','trash','waste','sanit','smell','odor'],
        'noise'      => ['noise','loud','music','party','disturbance','neighbor','neighbour'],
    ];
    foreach ($map as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) return $cat;
        }
    }
    return 'general';
}

/* ================================================================
   PATTERN DETECTION  – complaint history only
   Called by cron job or admin "Run Analysis" button ONLY.
   ================================================================ */

function ai_detect_patterns($conn): array {
    ai_ensure_tables($conn);

    $results = ['patterns_found' => 0, 'predictions_created' => 0];

    // Fetch all complaints with tenant info
    $sql = "SELECT c.complaint_id, c.tenant_id, c.title, c.description,
                   c.complaint_date, c.status, c.created_at,
                   t.unit_number, t.name AS tenant_name
            FROM complaints c
            JOIN tenants t ON c.tenant_id = t.tenant_id
            ORDER BY t.unit_number, c.complaint_date ASC";
    $res = $conn->query($sql);
    if (!$res) return $results;

    // Group complaints by (unit, category)
    $buckets = [];
    while ($row = $res->fetch_assoc()) {
        $cat = ai_classify_category($row['title'] . ' ' . $row['description']);
        $key = $row['unit_number'] . '::' . $cat;
        $buckets[$key][] = $row + ['category' => $cat];
    }

    // Clear old data and expire all stale predictions before regenerating
    $conn->query("DELETE FROM maintenance_patterns");
    $conn->query("UPDATE ai_predictions SET status = 'expired' WHERE status = 'active'");

    foreach ($buckets as $key => $complaints) {
        [$unit, $cat] = explode('::', $key, 2);
        $count = count($complaints);

        // Require at least 3 complaints of the same type in the same unit
        // (3 = a real pattern, 2 could just be coincidence)
        if ($count < 3) continue;

        // Calculate average interval between complaints (in days)
        $intervals = [];
        for ($i = 1; $i < $count; $i++) {
            $d1 = strtotime($complaints[$i - 1]['complaint_date'] ?? $complaints[$i - 1]['created_at']);
            $d2 = strtotime($complaints[$i]['complaint_date']     ?? $complaints[$i]['created_at']);
            if ($d1 && $d2 && $d2 > $d1) {
                $intervals[] = ($d2 - $d1) / 86400;
            }
        }
        $avgInterval = count($intervals) ? array_sum($intervals) / count($intervals) : null;

        // Find date of most recent complaint
        $lastDate = null;
        foreach (array_reverse($complaints) as $c) {
            if (!empty($c['complaint_date'])) { $lastDate = $c['complaint_date']; break; }
            if (!empty($c['created_at']))     { $lastDate = date('Y-m-d', strtotime($c['created_at'])); break; }
        }

        // Predict next occurrence based on average interval
        $nextPredicted = null;
        if ($avgInterval && $lastDate) {
            $nextPredicted = date('Y-m-d', strtotime($lastDate . ' + ' . round($avgInterval) . ' days'));
        }

        $severity = 'low';
        if ($count >= 6)     $severity = 'high';
        elseif ($count >= 4) $severity = 'medium';

        // Build complaint IDs list to show which complaints triggered this
        $triggerIds    = array_column($complaints, 'complaint_id');
        $triggerSample = array_slice($triggerIds, -3); // last 3 complaint IDs

        $desc = ucfirst($cat) . " issues reported $count times for Unit $unit.";
        if ($avgInterval) $desc .= " Average recurrence every " . round($avgInterval) . " days.";

        // Save the detected pattern
        $stmt = $conn->prepare(
            "INSERT INTO maintenance_patterns
             (category, unit_number, occurrence_count, avg_resolution_days, last_occurrence,
              pattern_description, recurrence_interval_days, next_predicted_date, severity)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)"
        );
        $roundedInterval = $avgInterval ? round($avgInterval) : null;
        $stmt->bind_param('ssississ', $cat, $unit, $count, $lastDate, $desc, $roundedInterval, $nextPredicted, $severity);
        $stmt->execute();
        $stmt->close();
        $results['patterns_found']++;

        // Only generate a prediction if next date is within 60 days
        if ($nextPredicted && strtotime($nextPredicted) <= strtotime('+60 days')) {
            $predText = "Based on $count previous " . $cat . " complaints in Unit $unit, "
                      . "a similar issue is predicted around " . date('M d, Y', strtotime($nextPredicted)) . ". "
                      . "(Triggered by complaint IDs: " . implode(', ', $triggerSample) . ")";
            $action   = "Schedule a preventive " . $cat . " inspection for Unit $unit before " . date('M d', strtotime($nextPredicted)) . ".";
            $conf     = min(95, 40 + ($count * 8) + ($avgInterval && $avgInterval < 90 ? 15 : 0));
            $risk     = 'low';
            if ($severity === 'medium' || $conf >= 60) $risk = 'medium';
            if ($severity === 'high'   || $conf >= 80) $risk = 'high';
            if ($count >= 7            && $conf >= 85) $risk = 'critical';

            $basedOn = json_encode($triggerIds);
            $tid     = $complaints[0]['tenant_id'] ?? null;

            $ins = $conn->prepare(
                "INSERT INTO ai_predictions
                 (unit_number, tenant_id, category, risk_level, prediction_text,
                  confidence_score, based_on, recommended_action, predicted_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->bind_param('sisssdsss', $unit, $tid, $cat, $risk, $predText, $conf, $basedOn, $action, $nextPredicted);
            $ins->execute();
            $ins->close();
            $results['predictions_created']++;
        }
    }

    return $results;
}

/* ================================================================
   STUB FUNCTIONS  – kept so existing callers don't break
   ================================================================ */

function ai_generate_insights($conn): array    { return ['insights_created' => 0]; }
function ai_generate_notifications($conn): int  { return 0; }
function ai_llm_analyze_patterns($conn): ?string { return null; }

/* ================================================================
   GET TENANT CONTEXT  – for the chatbot
   Reads from already-computed predictions, does NOT re-run analysis.
   ================================================================ */

function ai_get_tenant_context($conn, int $userId): array {
    ai_ensure_tables($conn);
    $ctx = [];

    $stmt = $conn->prepare("SELECT * FROM tenants WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tenant) return $ctx;

    $ctx['tenant_name'] = $tenant['name'];
    $ctx['unit_number'] = $tenant['unit_number'];
    $ctx['rent_amount'] = (float)$tenant['rent_amount'];
    $tenantId = (int)$tenant['tenant_id'];

    // Recent complaints for this tenant
    $stmt = $conn->prepare(
        "SELECT title, description, status, complaint_date FROM complaints
         WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    $stmt->bind_param('i', $tenantId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ctx['recent_complaints'] = [];
    while ($row = $res->fetch_assoc()) $ctx['recent_complaints'][] = $row;
    $stmt->close();

    // Payment status this month
    $month = date('Y-m');
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount),0) AS paid FROM payments
         WHERE tenant_id = ? AND status = 'verified' AND DATE_FORMAT(payment_date,'%Y-%m') = ?"
    );
    $stmt->bind_param('is', $tenantId, $month);
    $stmt->execute();
    $pRow = $stmt->get_result()->fetch_assoc();
    $ctx['paid_this_month'] = (float)($pRow['paid'] ?? 0);
    $ctx['payment_status']  = $ctx['paid_this_month'] >= $ctx['rent_amount'] ? 'Paid' : 'Unpaid';
    $stmt->close();

    // Active predictions for this unit — read only, no re-analysis
    $stmt = $conn->prepare(
        "SELECT category, risk_level, prediction_text, predicted_date
         FROM ai_predictions WHERE unit_number = ? AND status = 'active'
         ORDER BY predicted_date ASC LIMIT 3"
    );
    $stmt->bind_param('s', $tenant['unit_number']);
    $stmt->execute();
    $res = $stmt->get_result();
    $ctx['predictions'] = [];
    while ($row = $res->fetch_assoc()) $ctx['predictions'][] = $row;
    $stmt->close();

    // Unread notifications
    $stmt = $conn->prepare(
        "SELECT title, message, type, priority FROM ai_notifications
         WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ctx['notifications'] = [];
    while ($row = $res->fetch_assoc()) $ctx['notifications'][] = $row;
    $stmt->close();

    return $ctx;
}

/* ================================================================
   ADMIN SUMMARY DATA
   ================================================================ */

function ai_get_admin_summary($conn): array {
    ai_ensure_tables($conn);
    $summary = [];

    $r = $conn->query("SELECT * FROM ai_predictions WHERE status = 'active' ORDER BY risk_level DESC, predicted_date ASC");
    $summary['predictions'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['predictions'][] = $row; }

    $r = $conn->query("SELECT * FROM maintenance_patterns ORDER BY occurrence_count DESC");
    $summary['patterns'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['patterns'][] = $row; }

    $r = $conn->query("SELECT * FROM ai_analysis_log ORDER BY started_at DESC LIMIT 5");
    $summary['analysis_logs'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['analysis_logs'][] = $row; }

    // Insights stub kept for template compatibility
    $summary['insights'] = [];

    $summary['stats'] = [
        'active_predictions' => count($summary['predictions']),
        'high_risk'          => count(array_filter($summary['predictions'], fn($p) => in_array($p['risk_level'], ['high', 'critical']))),
        'active_insights'    => 0,
        'patterns_detected'  => count($summary['patterns']),
    ];

    return $summary;
}
