<?php
/**
 * TPB Supervisor Daily Report
 * ============================
 * Runs on SERVER via SSH. Checks all pipeline statuses, DB counts, and health.
 * Called by supervisor-report-local.bat
 */

$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

echo "============================================================\n";
echo "  TPB SUPERVISOR DAILY REPORT\n";
echo "  Generated: {$now}\n";
echo "============================================================\n\n";

// --- Pipeline Status ---
echo "=== PIPELINE STATUS ===\n";

$settings = [];
$r = $p->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE '%collect%' OR setting_key LIKE '%enabled%' OR setting_key LIKE '%rating%' OR setting_key LIKE '%bulletin%' OR setting_key LIKE '%statement%' OR setting_key LIKE '%claudia%'");
while ($row = $r->fetch()) $settings[$row['setting_key']] = $row['setting_value'];

$pipelines = [
    ['Threat Collection (local)',  'threat_collect_local_enabled',    'threat_collect_last_success',    'threat_collect_last_result'],
    ['Statement Collection',       'statement_collect_local_enabled', 'statement_collect_last_success', 'statement_collect_last_result'],
    ['Race Rating Check',          'rating_check_local_enabled',      'rating_check_last_success',      'rating_check_last_result'],
    ['Threat Bulletin',            'threat_bulletin_enabled',         'threat_bulletin_last_success',    'threat_bulletin_last_result'],
    ['FEC Sync',                   'fec_sync_enabled',                'fec_sync_last_success',           'fec_sync_last_result'],
    ['Claudia Local Tunnel',       'claudia_local_enabled',           null,                              null],
];

foreach ($pipelines as $pl) {
    $name = $pl[0];
    $enabledKey = $pl[1];
    $successKey = $pl[2];
    $resultKey = $pl[3];

    $enabled = ($settings[$enabledKey] ?? '0') === '1' ? 'ON' : 'OFF';
    $lastSuccess = $settings[$successKey] ?? 'never';
    $lastResult = $settings[$resultKey] ?? null;

    echo "\n  {$name}\n";
    echo "    Enabled: {$enabled}\n";

    if ($successKey) {
        echo "    Last success: {$lastSuccess}\n";

        // Age check
        if ($lastSuccess !== 'never') {
            $age = (time() - strtotime($lastSuccess)) / 3600;
            $ageStr = round($age, 1) . 'h ago';
            if ($age > 48) $ageStr .= ' ** STALE **';
            elseif ($age > 26) $ageStr .= ' * late *';
            echo "    Age: {$ageStr}\n";
        }
    }

    if ($lastResult) {
        $decoded = json_decode($lastResult, true);
        if ($decoded) {
            $status = $decoded['status'] ?? '?';
            $inserted = $decoded['inserted'] ?? null;
            $skipped = $decoded['skipped'] ?? null;
            $elapsed = $decoded['elapsed'] ?? null;
            $line = "    Result: {$status}";
            if ($inserted !== null) $line .= ", inserted={$inserted}";
            if ($skipped !== null) $line .= ", skipped={$skipped}";
            if ($elapsed !== null) $line .= ", {$elapsed}s";
            echo $line . "\n";
        }
    }
}

// --- DB Counts ---
echo "\n\n=== DATABASE COUNTS ===\n";

$counts = [
    ['Threats (active)',    "SELECT COUNT(*) FROM executive_threats WHERE is_active = 1"],
    ['Threats (total)',     "SELECT COUNT(*) FROM executive_threats"],
    ['Threats today',      "SELECT COUNT(*) FROM executive_threats WHERE created_at >= '{$today}'"],
    ['Statements',         "SELECT COUNT(*) FROM rep_statements"],
    ['Statements today',   "SELECT COUNT(*) FROM rep_statements WHERE created_at >= '{$today}'"],
    ['Polls (threat)',     "SELECT COUNT(*) FROM polls WHERE poll_type = 'threat'"],
    ['Polls (total)',      "SELECT COUNT(*) FROM polls"],
    ['Users',              "SELECT COUNT(*) FROM users WHERE deleted_at IS NULL"],
    ['Users (verified)',   "SELECT COUNT(*) FROM users WHERE identity_level_id >= 2 AND deleted_at IS NULL"],
    ['Votes (threats)',    "SELECT COUNT(*) FROM poll_votes"],
    ['Votes (statements)', "SELECT COUNT(*) FROM rep_statement_votes"],
    ['Ideas',              "SELECT COUNT(*) FROM idea_log"],
];

foreach ($counts as $c2) {
    try {
        $val = $p->query($c2[1])->fetchColumn();
        printf("  %-22s %s\n", $c2[0], number_format($val));
    } catch (Exception $e) {
        printf("  %-22s ERROR\n", $c2[0]);
    }
}

// --- Score Distribution ---
echo "\n\n=== THREAT SCORE DISTRIBUTION ===\n";
$r = $p->query("SELECT
    CASE
        WHEN severity_score >= 800 THEN 'Critical (800+)'
        WHEN severity_score >= 500 THEN 'High (500-799)'
        WHEN severity_score >= 300 THEN 'Medium (300-499)'
        WHEN severity_score >= 100 THEN 'Low (100-299)'
        ELSE 'Minimal (0-99)'
    END as tier,
    COUNT(*) as cnt
    FROM executive_threats WHERE is_active = 1
    GROUP BY tier ORDER BY MIN(severity_score) DESC");
while ($row = $r->fetch()) printf("  %-20s %s\n", $row['tier'], $row['cnt']);

echo "\n=== BENEFIT SCORE DISTRIBUTION ===\n";
$r = $p->query("SELECT
    CASE
        WHEN benefit_score >= 800 THEN 'High (800+)'
        WHEN benefit_score >= 500 THEN 'Moderate (500-799)'
        WHEN benefit_score >= 100 THEN 'Low (100-499)'
        ELSE 'None/Minimal (0-99)'
    END as tier,
    COUNT(*) as cnt
    FROM executive_threats WHERE is_active = 1
    GROUP BY tier ORDER BY MIN(benefit_score) DESC");
while ($row = $r->fetch()) printf("  %-20s %s\n", $row['tier'], $row['cnt']);

// --- Statement Tense Breakdown ---
echo "\n=== STATEMENT TENSE BREAKDOWN ===\n";
$r = $p->query("SELECT COALESCE(tense, 'untagged') as tense, COUNT(*) as cnt FROM rep_statements GROUP BY tense");
while ($row = $r->fetch()) printf("  %-20s %s\n", $row['tense'], $row['cnt']);

// --- Recent Activity (last 24h) ---
echo "\n=== RECENT ACTIVITY (24h) ===\n";
$recent = [
    ['New threats',    "SELECT COUNT(*) FROM executive_threats WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"],
    ['New statements', "SELECT COUNT(*) FROM rep_statements WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"],
    ['New users',      "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND deleted_at IS NULL"],
    ['New ideas',      "SELECT COUNT(*) FROM idea_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"],
    ['New votes',      "SELECT COUNT(*) FROM poll_votes WHERE voted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"],
    ['Stmt votes',     "SELECT COUNT(*) FROM rep_statement_votes WHERE voted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"],
];
foreach ($recent as $r2) {
    try {
        $val = $p->query($r2[1])->fetchColumn();
        printf("  %-20s %s\n", $r2[0], $val);
    } catch (Exception $e) {
        printf("  %-20s ERROR\n", $r2[0]);
    }
}

echo "\n============================================================\n";
echo "  END OF REPORT\n";
echo "============================================================\n";
