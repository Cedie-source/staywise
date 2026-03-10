<?php
/**
 * StayWise Predictive Analytics Engine
 *
 * Analyses historical complaint / maintenance data to detect patterns,
 * generate predictions and produce actionable insights for landlords.
 *
 * This file exposes helper functions that can be called by:
 *   - The scheduled cron job  (tools/run_analysis.php)
 *   - The admin insights page (admin/ai_insights.php)
 *   - The enhanced chatbot    (api/chat_enhanced.php)
 */

if (!defined('STAYWISE_ROOT')) {
    define('STAYWISE_ROOT', dirname(__DIR__));
}
require_once STAYWISE_ROOT . '/config/db.php';

/* ================================================================
   TABLE AUTO-CREATION  – ensures all AI tables exist before queries
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
            break;                       // migration creates all tables at once
        }
    }
}

/* ================================================================
   CATEGORY CLASSIFIER  – maps complaint text → maintenance category
   ================================================================ */

function ai_classify_category(string $text): string {
    $text = strtolower($text);
    $map = [
        'plumbing'    => ['leak','pipe','faucet','drain','clog','toilet','water pressure','sewage','plumb'],
        'electrical'  => ['electric','wiring','outlet','switch','circuit','breaker','power','light','bulb','spark','voltage'],
        'structural'  => ['crack','wall','ceiling','floor','foundation','door','window','frame','roof','paint','tile','damp'],
        'appliance'   => ['aircon','ac unit','air condition','refrigerator','fridge','stove','oven','washer','dryer','heater','fan','appliance'],
        'pest'        => ['pest','cockroach','rat','mice','mouse','ant','termite','bug','insect','rodent'],
        'security'    => ['lock','key','gate','cctv','camera','alarm','security','theft','break-in'],
        'cleaning'    => ['clean','garbage','trash','waste','sanit','smell','odor','mold','mould'],
        'noise'       => ['noise','loud','music','party','disturbance','neighbor','neighbour'],
    ];
    foreach ($map as $cat => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) return $cat;
        }
    }
    return 'general';
}

/* ================================================================
   PATTERN DETECTION  – scans complaint history for recurring issues
   ================================================================ */

function ai_detect_patterns($conn): array {
    ai_ensure_tables($conn);

    $results = ['patterns_found' => 0, 'predictions_created' => 0];

    // Gather complaints with dates
    $sql = "SELECT c.complaint_id, c.tenant_id, c.title, c.description,
                   c.complaint_date, c.status, c.created_at,
                   t.unit_number, t.name AS tenant_name
            FROM complaints c
            JOIN tenants t ON c.tenant_id = t.tenant_id
            ORDER BY t.unit_number, c.complaint_date ASC";
    $res = $conn->query($sql);
    if (!$res) return $results;

    // Bucket by (unit, category)
    $buckets = [];
    while ($row = $res->fetch_assoc()) {
        $cat = ai_classify_category($row['title'] . ' ' . $row['description']);
        $key = $row['unit_number'] . '::' . $cat;
        $buckets[$key][] = $row + ['category' => $cat];
    }

    // Clear old patterns before regenerating
    $conn->query("DELETE FROM maintenance_patterns");

    foreach ($buckets as $key => $complaints) {
        [$unit, $cat] = explode('::', $key, 2);
        $count = count($complaints);
        if ($count < 2) continue;   // need at least 2 occurrences for a pattern

        // Calculate average interval
        $intervals = [];
        for ($i = 1; $i < $count; $i++) {
            $d1 = strtotime($complaints[$i - 1]['complaint_date'] ?? $complaints[$i - 1]['created_at']);
            $d2 = strtotime($complaints[$i]['complaint_date'] ?? $complaints[$i]['created_at']);
            if ($d1 && $d2 && $d2 > $d1) {
                $intervals[] = ($d2 - $d1) / 86400; // days
            }
        }
        $avgInterval = count($intervals) ? array_sum($intervals) / count($intervals) : null;

        // Resolution times
        $resTimes = [];
        foreach ($complaints as $c) {
            if ($c['status'] === 'resolved' && !empty($c['complaint_date'])) {
                // approximate: use days between complaint and latest reply or assume 7 days
                $resTimes[] = 7;
            }
        }
        $avgRes = count($resTimes) ? array_sum($resTimes) / count($resTimes) : null;

        $lastDate = null;
        foreach (array_reverse($complaints) as $c) {
            if (!empty($c['complaint_date'])) { $lastDate = $c['complaint_date']; break; }
            if (!empty($c['created_at'])) { $lastDate = date('Y-m-d', strtotime($c['created_at'])); break; }
        }

        $nextPredicted = null;
        if ($avgInterval && $lastDate) {
            $nextPredicted = date('Y-m-d', strtotime($lastDate . ' + ' . round($avgInterval) . ' days'));
        }

        $severity = 'low';
        if ($count >= 5) $severity = 'high';
        elseif ($count >= 3) $severity = 'medium';

        $desc = ucfirst($cat) . " issues reported $count times for Unit $unit.";
        if ($avgInterval) $desc .= " Average recurrence every " . round($avgInterval) . " days.";

        $stmt = $conn->prepare(
            "INSERT INTO maintenance_patterns
             (category, unit_number, occurrence_count, avg_resolution_days, last_occurrence,
              pattern_description, recurrence_interval_days, next_predicted_date, severity)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $roundedInterval = $avgInterval ? round($avgInterval) : null;
        $stmt->bind_param(
            'ssidssiss',
            $cat, $unit, $count, $avgRes, $lastDate,
            $desc, $roundedInterval, $nextPredicted, $severity
        );
        $stmt->execute();
        $stmt->close();
        $results['patterns_found']++;

        // Generate a prediction if next date is within the next 60 days
        if ($nextPredicted && strtotime($nextPredicted) <= strtotime('+60 days')) {
            $predText = "Based on $count previous " . $cat . " complaints in Unit $unit, "
                      . "a similar issue is predicted around " . date('M d, Y', strtotime($nextPredicted)) . ".";
            $action = "Schedule preventive " . $cat . " inspection for Unit $unit before " . date('M d', strtotime($nextPredicted)) . ".";
            $conf = min(95, 40 + ($count * 10) + ($avgInterval && $avgInterval < 90 ? 15 : 0));
            $risk = 'medium';
            if ($severity === 'high' || $conf >= 80) $risk = 'high';
            if ($count >= 6 && $conf >= 85) $risk = 'critical';

            $basedOn = json_encode(array_column($complaints, 'complaint_id'));

            // Avoid duplicate active predictions for same unit+category+date
            $chk = $conn->prepare(
                "SELECT prediction_id FROM ai_predictions
                 WHERE unit_number = ? AND category = ? AND predicted_date = ? AND status = 'active' LIMIT 1"
            );
            $chk->bind_param('sss', $unit, $cat, $nextPredicted);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if (!$exists) {
                $tid = $complaints[0]['tenant_id'] ?? null;
                $ins = $conn->prepare(
                    "INSERT INTO ai_predictions
                     (unit_number, tenant_id, category, risk_level, prediction_text,
                      confidence_score, based_on, recommended_action, predicted_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $ins->bind_param(
                    'sisssdsss',
                    $unit, $tid, $cat, $risk, $predText,
                    $conf, $basedOn, $action, $nextPredicted
                );
                $ins->execute();
                $ins->close();
                $results['predictions_created']++;
            }
        }
    }

    return $results;
}

/* ================================================================
   TREND ANALYSIS  – generates high-level insights for the dashboard
   ================================================================ */

function ai_generate_insights($conn): array {
    ai_ensure_tables($conn);
    $results = ['insights_created' => 0];

    $now = date('Y-m-d');
    $thirtyAgo = date('Y-m-d', strtotime('-30 days'));
    $sixtyAgo  = date('Y-m-d', strtotime('-60 days'));

    // Deactivate old insights
    $conn->query("UPDATE ai_insights SET is_active = 0 WHERE created_at < '$thirtyAgo'");

    // --- Trend 1: Complaint volume comparison (last 30 vs previous 30 days) ---
    $r1 = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE created_at >= '$thirtyAgo'");
    $recent30 = (int)($r1->fetch_assoc()['cnt'] ?? 0);
    $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM complaints WHERE created_at >= '$sixtyAgo' AND created_at < '$thirtyAgo'");
    $prev30 = (int)($r2->fetch_assoc()['cnt'] ?? 0);

    $change = $prev30 > 0 ? round((($recent30 - $prev30) / $prev30) * 100) : ($recent30 > 0 ? 100 : 0);
    $sev = 'info';
    if ($change > 30) $sev = 'warning';
    if ($change > 60) $sev = 'critical';

    $trendTitle = $change >= 0
        ? "Complaint volume up {$change}% in the last 30 days"
        : "Complaint volume down " . abs($change) . "% in the last 30 days";
    $trendDesc = "There were $recent30 complaints in the last 30 days compared to $prev30 in the prior period.";
    if ($change > 30) $trendDesc .= " This significant increase warrants attention.";

    $data = json_encode(['recent_30' => $recent30, 'previous_30' => $prev30, 'change_pct' => $change]);

    $conn->query("DELETE FROM ai_insights WHERE title LIKE 'Complaint volume%' AND is_active = 1");
    $stmt = $conn->prepare(
        "INSERT INTO ai_insights (insight_type, category, title, description, data_json, severity, period_start, period_end)
         VALUES ('trend','complaints',?,?,?,?,?,?)"
    );
    $stmt->bind_param('ssssss', $trendTitle, $trendDesc, $data, $sev, $sixtyAgo, $now);
    $stmt->execute();
    $stmt->close();
    $results['insights_created']++;

    // --- Trend 2: Top complaint categories ---
    $catQuery = $conn->query(
        "SELECT c.title, c.description FROM complaints c WHERE c.created_at >= '$sixtyAgo'"
    );
    $catCounts = [];
    if ($catQuery) {
        while ($row = $catQuery->fetch_assoc()) {
            $cat = ai_classify_category($row['title'] . ' ' . $row['description']);
            $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
        }
    }
    arsort($catCounts);
    if (count($catCounts) > 0) {
        $topCat = array_key_first($catCounts);
        $topCnt = $catCounts[$topCat];
        $catData = json_encode($catCounts);
        $catTitle = "Top maintenance category: " . ucfirst($topCat) . " ($topCnt reports)";
        $catDesc = ucfirst($topCat) . " is the most reported issue type with $topCnt complaints in the last 60 days. "
                 . "Consider scheduling a property-wide " . $topCat . " inspection.";
        $catSev = $topCnt >= 10 ? 'warning' : 'info';

        $conn->query("DELETE FROM ai_insights WHERE title LIKE 'Top maintenance category%' AND is_active = 1");
        $stmt = $conn->prepare(
            "INSERT INTO ai_insights (insight_type, category, title, description, data_json, severity, period_start, period_end)
             VALUES ('pattern',?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('sssssss', $topCat, $catTitle, $catDesc, $catData, $catSev, $sixtyAgo, $now);
        $stmt->execute();
        $stmt->close();
        $results['insights_created']++;
    }

    // --- Trend 3: Units with most issues (hotspot detection) ---
    $unitQuery = $conn->query(
        "SELECT t.unit_number, COUNT(*) AS cnt
         FROM complaints c JOIN tenants t ON c.tenant_id = t.tenant_id
         WHERE c.created_at >= '$sixtyAgo'
         GROUP BY t.unit_number ORDER BY cnt DESC LIMIT 5"
    );
    $hotspots = [];
    if ($unitQuery) {
        while ($row = $unitQuery->fetch_assoc()) {
            $hotspots[] = $row;
        }
    }
    if (count($hotspots) > 0 && (int)$hotspots[0]['cnt'] >= 2) {
        $hsData = json_encode($hotspots);
        $hsTitle = "Hotspot unit: " . $hotspots[0]['unit_number'] . " ({$hotspots[0]['cnt']} complaints)";
        $hsDesc = "Unit " . $hotspots[0]['unit_number'] . " has the highest complaint volume with "
                . $hotspots[0]['cnt'] . " reports in the last 60 days. A comprehensive inspection is recommended.";
        $hsSev = (int)$hotspots[0]['cnt'] >= 5 ? 'critical' : ((int)$hotspots[0]['cnt'] >= 3 ? 'warning' : 'info');

        $conn->query("DELETE FROM ai_insights WHERE title LIKE 'Hotspot unit%' AND is_active = 1");
        $stmt = $conn->prepare(
            "INSERT INTO ai_insights (insight_type, category, title, description, data_json, severity, period_start, period_end)
             VALUES ('anomaly','unit_hotspot',?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssssss', $hsTitle, $hsDesc, $hsData, $hsSev, $sixtyAgo, $now);
        $stmt->execute();
        $stmt->close();
        $results['insights_created']++;
    }

    // --- Trend 4: Payment patterns / late payments ---
    $lateQuery = $conn->query(
        "SELECT COUNT(*) AS cnt FROM payments WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $latePayments = (int)($lateQuery->fetch_assoc()['cnt'] ?? 0);
    if ($latePayments > 0) {
        $payTitle = "$latePayments payments pending for more than 7 days";
        $payDesc = "There are $latePayments payment submissions still pending verification for over a week. "
                 . "Prompt review helps maintain tenant satisfaction and cash flow.";
        $paySev = $latePayments >= 5 ? 'warning' : 'info';
        $payData = json_encode(['late_payments' => $latePayments]);

        $conn->query("DELETE FROM ai_insights WHERE title LIKE '%payments pending%' AND is_active = 1");
        $stmt = $conn->prepare(
            "INSERT INTO ai_insights (insight_type, category, title, description, data_json, severity, period_start, period_end)
             VALUES ('recommendation','payments',?,?,?,?,?,?)"
        );
        $stmt->bind_param('ssssss', $payTitle, $payDesc, $payData, $paySev, $thirtyAgo, $now);
        $stmt->execute();
        $stmt->close();
        $results['insights_created']++;
    }

    // --- Trend 5: Urgent complaint ratio ---
    if (function_exists('db_column_exists') && db_column_exists($conn, 'complaints', 'urgent')) {
        $urgentR = $conn->query(
            "SELECT
                SUM(CASE WHEN urgent = 1 THEN 1 ELSE 0 END) AS urgent_cnt,
                COUNT(*) AS total
             FROM complaints WHERE created_at >= '$thirtyAgo'"
        );
        $urgRow = $urgentR ? $urgentR->fetch_assoc() : null;
        if ($urgRow && (int)$urgRow['total'] > 0) {
            $urgentCnt = (int)$urgRow['urgent_cnt'];
            $totalCnt  = (int)$urgRow['total'];
            $urgentPct = round(($urgentCnt / $totalCnt) * 100);
            if ($urgentPct >= 20) {
                $urgTitle = "$urgentPct% of recent complaints marked urgent";
                $urgDesc = "$urgentCnt out of $totalCnt complaints in the last 30 days are marked urgent. "
                         . "This high urgency rate suggests systemic issues that need immediate attention.";
                $urgSev = $urgentPct >= 40 ? 'critical' : 'warning';
                $urgData = json_encode(['urgent' => $urgentCnt, 'total' => $totalCnt, 'pct' => $urgentPct]);

                $conn->query("DELETE FROM ai_insights WHERE title LIKE '%complaints marked urgent%' AND is_active = 1");
                $stmt = $conn->prepare(
                    "INSERT INTO ai_insights (insight_type, category, title, description, data_json, severity, period_start, period_end)
                     VALUES ('anomaly','urgency',?,?,?,?,?,?)"
                );
                $stmt->bind_param('ssssss', $urgTitle, $urgDesc, $urgData, $urgSev, $thirtyAgo, $now);
                $stmt->execute();
                $stmt->close();
                $results['insights_created']++;
            }
        }
    }

    return $results;
}

/* ================================================================
   PROACTIVE NOTIFICATION GENERATOR
   ================================================================ */

function ai_generate_notifications($conn): int {
    ai_ensure_tables($conn);
    $created = 0;

    // 1. Notify admins about high/critical predictions
    $preds = $conn->query(
        "SELECT p.*, t.user_id AS tenant_user_id
         FROM ai_predictions p
         LEFT JOIN tenants t ON p.tenant_id = t.tenant_id
         WHERE p.status = 'active'
           AND p.risk_level IN ('high','critical')
           AND p.prediction_id NOT IN (
               SELECT related_id FROM ai_notifications WHERE related_id IS NOT NULL AND type = 'prediction'
           )"
    );
    // Get all admin user IDs
    $admins = [];
    $aRes = $conn->query("SELECT id FROM users WHERE role = 'admin'");
    if ($aRes) { while ($a = $aRes->fetch_assoc()) $admins[] = (int)$a['id']; }

    if ($preds) {
        while ($p = $preds->fetch_assoc()) {
            foreach ($admins as $adminId) {
                $title = "AI Alert: " . ucfirst($p['risk_level']) . " risk " . $p['category'] . " issue predicted for Unit " . $p['unit_number'];
                $msg = $p['prediction_text'] . "\n\nRecommended: " . $p['recommended_action'];
                $prio = $p['risk_level'] === 'critical' ? 'high' : 'medium';
                $url = '/StayWise/admin/ai_insights.php';
                $relId = (int)$p['prediction_id'];

                $stmt = $conn->prepare(
                    "INSERT INTO ai_notifications (user_id, type, title, message, priority, action_url, related_id)
                     VALUES (?, 'prediction', ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issssi', $adminId, $title, $msg, $prio, $url, $relId);
                $stmt->execute();
                $stmt->close();
                $created++;
            }

            // Also notify the tenant if applicable
            if (!empty($p['tenant_user_id'])) {
                $tTitle = "Maintenance Advisory: Upcoming " . $p['category'] . " check for your unit";
                $tMsg = "Our system has detected a pattern of " . $p['category'] . " issues in your unit. "
                      . "We're scheduling a preventive inspection to address this proactively. No action needed from you.";
                $tUrl = '/StayWise/tenant/dashboard.php';
                $relId = (int)$p['prediction_id'];
                $tUserId = (int)$p['tenant_user_id'];

                $stmt = $conn->prepare(
                    "INSERT INTO ai_notifications (user_id, type, title, message, priority, action_url, related_id)
                     VALUES (?, 'maintenance', ?, ?, 'medium', ?, ?)"
                );
                $stmt->bind_param('isssi', $tUserId, $tTitle, $tMsg, $tUrl, $relId);
                $stmt->execute();
                $stmt->close();
                $created++;
            }
        }
    }

    // 2. Payment reminder notifications for tenants with overdue rent
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    $tenants = $conn->query(
        "SELECT t.tenant_id, t.user_id, t.name, t.unit_number, t.rent_amount, t.due_day,
                COALESCE(
                    (SELECT SUM(p.amount) FROM payments p
                     WHERE p.tenant_id = t.tenant_id AND p.status = 'verified'
                       AND DATE_FORMAT(p.payment_date,'%Y-%m') = '$currentMonth'),
                0) AS paid_this_month
         FROM tenants t
         WHERE t.deleted_at IS NULL AND t.rent_amount > 0"
    );
    if ($tenants) {
        while ($t = $tenants->fetch_assoc()) {
            $dueDay = max(1, min((int)($t['due_day'] ?: 1), 28));
            $dueDate = date('Y-m-') . str_pad($dueDay, 2, '0', STR_PAD_LEFT);
            $paid = (float)$t['paid_this_month'];
            $rent = (float)$t['rent_amount'];

            // Only notify if unpaid and within 3 days of due date or overdue
            $daysUntilDue = (strtotime($dueDate) - strtotime($today)) / 86400;
            if ($paid < $rent && $daysUntilDue <= 3 && $daysUntilDue >= -7) {
                // Check no recent payment notification in last 3 days
                $uid = (int)$t['user_id'];
                $chk = $conn->query(
                    "SELECT notification_id FROM ai_notifications
                     WHERE user_id = $uid AND type = 'payment'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) LIMIT 1"
                );
                if ($chk && $chk->num_rows === 0) {
                    $remaining = $rent - $paid;
                    if ($daysUntilDue < 0) {
                        $nTitle = "Payment Overdue: ₱" . number_format($remaining, 2) . " remaining";
                        $nMsg = "Your rent payment for this month is overdue. Outstanding balance: ₱"
                              . number_format($remaining, 2) . ". Please settle as soon as possible.";
                        $nPrio = 'high';
                    } else {
                        $nTitle = "Rent Due Soon: " . ($daysUntilDue == 0 ? "Today" : "in " . round($daysUntilDue) . " days");
                        $nMsg = "Your rent of ₱" . number_format($rent, 2) . " is due "
                              . ($daysUntilDue == 0 ? "today" : "in " . round($daysUntilDue) . " day(s)")
                              . ". Outstanding: ₱" . number_format($remaining, 2) . ".";
                        $nPrio = 'medium';
                    }
                    $nUrl = '/StayWise/tenant/payments.php';

                    $stmt = $conn->prepare(
                        "INSERT INTO ai_notifications (user_id, type, title, message, priority, action_url)
                         VALUES (?, 'payment', ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('issss', $uid, $nTitle, $nMsg, $nPrio, $nUrl);
                    $stmt->execute();
                    $stmt->close();
                    $created++;
                }
            }
        }
    }

    // 3. Advisory notifications from insights
    $insights = $conn->query(
        "SELECT * FROM ai_insights
         WHERE is_active = 1 AND severity IN ('warning','critical')
           AND insight_id NOT IN (
               SELECT related_id FROM ai_notifications WHERE related_id IS NOT NULL AND type = 'advisory'
           )"
    );
    if ($insights) {
        while ($ins = $insights->fetch_assoc()) {
            foreach ($admins as $adminId) {
                $aTitle = "AI Insight: " . $ins['title'];
                $aMsg = $ins['description'];
                $aPrio = $ins['severity'] === 'critical' ? 'high' : 'medium';
                $aUrl = '/StayWise/admin/ai_insights.php';
                $relId = (int)$ins['insight_id'];

                $stmt = $conn->prepare(
                    "INSERT INTO ai_notifications (user_id, type, title, message, priority, action_url, related_id)
                     VALUES (?, 'advisory', ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issssi', $adminId, $aTitle, $aMsg, $aPrio, $aUrl, $relId);
                $stmt->execute();
                $stmt->close();
                $created++;
            }
        }
    }

    return $created;
}

/* ================================================================
   AI-POWERED PREDICTION USING GROQ  – enhanced with LLM analysis
   ================================================================ */

function ai_llm_analyze_patterns($conn): ?string {
    ai_ensure_tables($conn);

    // Collect pattern data for LLM analysis
    $patterns = $conn->query("SELECT * FROM maintenance_patterns ORDER BY occurrence_count DESC LIMIT 10");
    if (!$patterns || $patterns->num_rows === 0) return null;

    $patternText = "Here are the detected maintenance patterns across our rental property:\n\n";
    while ($p = $patterns->fetch_assoc()) {
        $patternText .= "- Unit {$p['unit_number']}: {$p['category']} issues ({$p['occurrence_count']} occurrences";
        if ($p['recurrence_interval_days']) $patternText .= ", recurring every ~{$p['recurrence_interval_days']} days";
        if ($p['next_predicted_date']) $patternText .= ", next predicted: {$p['next_predicted_date']}";
        $patternText .= ")\n";
    }

    // Get recent complaints summary
    $recent = $conn->query(
        "SELECT t.unit_number, c.title, c.description, c.status, c.complaint_date
         FROM complaints c JOIN tenants t ON c.tenant_id = t.tenant_id
         ORDER BY c.created_at DESC LIMIT 15"
    );
    $recentText = "\nRecent complaints:\n";
    if ($recent) {
        while ($r = $recent->fetch_assoc()) {
            $recentText .= "- Unit {$r['unit_number']}: {$r['title']} ({$r['status']}, {$r['complaint_date']})\n";
        }
    }

    // Call Groq LLM for deeper analysis
    @include STAYWISE_ROOT . '/config/groq.php';
    $apiKey = getenv('GROQ_API_KEY');
    $model = getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile';
    $apiUrl = getenv('GROQ_API_URL') ?: 'https://api.groq.com/openai/v1/chat/completions';

    if (!$apiKey || $apiKey === 'YOUR_KEY_HERE') return null;

    $currentDate = date('F d, Y');
    $systemPrompt = "You are an AI property maintenance analyst for StayWise rental management system.\n"
        . "Analyze the maintenance patterns and recent complaints provided. Generate:\n"
        . "1. A brief executive summary (2-3 sentences)\n"
        . "2. Top 3 priority concerns with risk levels\n"
        . "3. Specific preventive action recommendations\n"
        . "4. Predicted issues for the next 30 days\n\n"
        . "Be concise, data-driven, and actionable. Use bullet points.\n"
        . "Current date: $currentDate";

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $patternText . $recentText],
        ],
        'temperature' => 0.4,
        'max_tokens' => 1024,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $json = json_decode($response, true);
    return $json['choices'][0]['message']['content'] ?? null;
}

/* ================================================================
   GET TENANT CONTEXT  – for context-aware chatbot
   ================================================================ */

function ai_get_tenant_context($conn, int $userId): array {
    ai_ensure_tables($conn);
    $ctx = [];

    // Get tenant info
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

    // Recent complaints
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
    $ctx['payment_status'] = $ctx['paid_this_month'] >= $ctx['rent_amount'] ? 'Paid' : 'Unpaid';
    $stmt->close();

    // Active predictions for this unit
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
   ADMIN SUMMARY DATA  – for the admin ai_insights dashboard
   ================================================================ */

function ai_get_admin_summary($conn): array {
    ai_ensure_tables($conn);
    $summary = [];

    // Active predictions
    $r = $conn->query("SELECT * FROM ai_predictions WHERE status = 'active' ORDER BY risk_level DESC, predicted_date ASC");
    $summary['predictions'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['predictions'][] = $row; }

    // Active insights
    $r = $conn->query("SELECT * FROM ai_insights WHERE is_active = 1 ORDER BY severity DESC, created_at DESC");
    $summary['insights'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['insights'][] = $row; }

    // Patterns
    $r = $conn->query("SELECT * FROM maintenance_patterns ORDER BY occurrence_count DESC");
    $summary['patterns'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['patterns'][] = $row; }

    // Recent analysis runs
    $r = $conn->query("SELECT * FROM ai_analysis_log ORDER BY started_at DESC LIMIT 5");
    $summary['analysis_logs'] = [];
    if ($r) { while ($row = $r->fetch_assoc()) $summary['analysis_logs'][] = $row; }

    // Quick stats
    $summary['stats'] = [
        'active_predictions' => count($summary['predictions']),
        'high_risk' => count(array_filter($summary['predictions'], fn($p) => in_array($p['risk_level'], ['high','critical']))),
        'active_insights' => count($summary['insights']),
        'patterns_detected' => count($summary['patterns']),
    ];

    return $summary;
}
