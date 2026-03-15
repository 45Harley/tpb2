<?php
/**
 * Truthfulness Scoring Pipeline — Step 3: Parse + Insert
 * =======================================================
 * Runs on SERVER via SSH.
 * 1. Creates new clusters from assignments
 * 2. Links statements to clusters
 * 3. Inserts truthfulness scores into history
 * 4. Updates cluster snapshot fields
 */

$startTime = microtime(true);

$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$logDir = $base . '/scripts/maintenance/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/score-truthfulness-local.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Step 3: Truthfulness scoring (local pipeline) ===");

// Read Claude's response
$inputFile = $argv[1] ?? '';
if (!$inputFile || !file_exists($inputFile)) {
    logMsg("ERROR: No input file: {$inputFile}");
    exit(1);
}

$rawText = file_get_contents($inputFile);
logMsg("Read " . strlen($rawText) . " bytes");

// Parse JSON
$jsonStr = $rawText;
if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}
if (preg_match('/\{[\s\S]*"cluster_assignments"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed) {
    logMsg("ERROR: JSON parse failed. Raw (first 2000): " . substr($rawText, 0, 2000));
    exit(1);
}

$assignments = $parsed['cluster_assignments'] ?? [];
$scores = $parsed['truthfulness_scores'] ?? [];

logMsg("Assignments: " . count($assignments) . ", Scores: " . count($scores));

// ─── Phase 1: Process cluster assignments ───────────────────────────────

// Track new clusters by canonical_claim so we can map them
$newClusterMap = []; // canonical_claim => cluster_id

$insertCluster = $pdo->prepare("
    INSERT INTO statement_clusters (canonical_claim, policy_topic, first_seen, last_seen, repeat_count)
    VALUES (?, ?, ?, ?, 1)
");

$updateStmtCluster = $pdo->prepare("UPDATE rep_statements SET cluster_id = ? WHERE id = ?");

$clustered = 0;
$newClusters = 0;

foreach ($assignments as $a) {
    $stmtId = (int)($a['statement_id'] ?? 0);
    if (!$stmtId) continue;

    // Get statement date for cluster date range
    $stmtRow = $pdo->prepare("SELECT statement_date, policy_topic FROM rep_statements WHERE id = ?");
    $stmtRow->execute([$stmtId]);
    $stmtData = $stmtRow->fetch();
    if (!$stmtData) {
        logMsg("WARNING: Statement #{$stmtId} not found, skipping");
        continue;
    }

    $isNew = !empty($a['cluster_id_is_new']);

    if ($isNew) {
        $newCluster = $a['new_cluster'] ?? [];
        $canonicalClaim = trim($newCluster['canonical_claim'] ?? $a['canonical_claim'] ?? '');
        if (!$canonicalClaim) {
            logMsg("WARNING: New cluster for stmt #{$stmtId} has no canonical_claim, skipping");
            continue;
        }

        // Check if we already created this cluster in this run
        $claimKey = strtolower($canonicalClaim);
        if (isset($newClusterMap[$claimKey])) {
            $clusterId = $newClusterMap[$claimKey];
            // Update repeat count and last_seen
            $pdo->prepare("UPDATE statement_clusters SET repeat_count = repeat_count + 1, last_seen = GREATEST(last_seen, ?) WHERE id = ?")
                ->execute([$stmtData['statement_date'], $clusterId]);
        } else {
            $topic = $newCluster['policy_topic'] ?? $stmtData['policy_topic'] ?? '';
            $insertCluster->execute([$canonicalClaim, $topic, $stmtData['statement_date'], $stmtData['statement_date']]);
            $clusterId = (int)$pdo->lastInsertId();
            $newClusterMap[$claimKey] = $clusterId;
            $newClusters++;
            logMsg("New cluster #{$clusterId}: {$canonicalClaim}");
        }
    } else {
        $clusterId = (int)($a['cluster_id'] ?? 0);
        if (!$clusterId) continue;

        // Update repeat count and date range
        $pdo->prepare("
            UPDATE statement_clusters
            SET repeat_count = repeat_count + 1,
                first_seen = LEAST(first_seen, ?),
                last_seen = GREATEST(last_seen, ?)
            WHERE id = ?
        ")->execute([$stmtData['statement_date'], $stmtData['statement_date'], $clusterId]);
    }

    $updateStmtCluster->execute([$clusterId, $stmtId]);
    $clustered++;
}

logMsg("Clustered {$clustered} statements into {$newClusters} new + existing clusters");

// ─── Phase 2: Process truthfulness scores ───────────────────────────────

$insertHistory = $pdo->prepare("
    INSERT INTO truthfulness_history (cluster_id, score, delta, note, evidence_refs)
    VALUES (?, ?, ?, ?, ?)
");

$updateCluster = $pdo->prepare("
    UPDATE statement_clusters
    SET truthfulness_score = ?,
        truthfulness_avg = ?,
        truthfulness_direction = ?,
        truthfulness_delta = ?,
        truthfulness_note = ?
    WHERE id = ?
");

$scored = 0;

foreach ($scores as $s) {
    $score = (int)($s['score'] ?? 0);
    $note = trim($s['note'] ?? '');
    $evidenceRefs = trim($s['evidence_refs'] ?? '');
    $isNew = !empty($s['cluster_id_is_new']);

    // Resolve cluster_id
    if ($isNew) {
        $canonicalClaim = trim($s['canonical_claim'] ?? '');
        $claimKey = strtolower($canonicalClaim);
        $clusterId = $newClusterMap[$claimKey] ?? null;
        if (!$clusterId) {
            logMsg("WARNING: Can't find new cluster for claim: " . substr($canonicalClaim, 0, 80));
            continue;
        }
    } else {
        $clusterId = (int)($s['cluster_id'] ?? 0);
        if (!$clusterId) continue;
    }

    // Get previous score for delta
    $prevRow = $pdo->prepare("SELECT truthfulness_score FROM statement_clusters WHERE id = ?");
    $prevRow->execute([$clusterId]);
    $prevScore = $prevRow->fetchColumn();
    $prevScore = ($prevScore !== false && $prevScore !== null) ? (int)$prevScore : null;

    $delta = ($prevScore !== null) ? ($score - $prevScore) : 0;
    $direction = 'new';
    if ($prevScore !== null) {
        if ($delta > 10) $direction = 'up';
        elseif ($delta < -10) $direction = 'down';
        else $direction = 'stable';
    }

    // Insert history row
    $insertHistory->execute([$clusterId, $score, $delta, $note, $evidenceRefs]);

    // Calculate running average
    $avgRow = $pdo->prepare("SELECT AVG(score) FROM truthfulness_history WHERE cluster_id = ?");
    $avgRow->execute([$clusterId]);
    $avg = round((float)$avgRow->fetchColumn(), 1);

    // Update cluster snapshot
    $updateCluster->execute([$score, $avg, $direction, $delta, $note, $clusterId]);

    $dirArrow = $direction === 'up' ? '↑' : ($direction === 'down' ? '↓' : ($direction === 'stable' ? '→' : '★'));
    logMsg("Scored cluster #{$clusterId}: {$score} ({$dirArrow}{$delta}) avg:{$avg} — " . substr($note, 0, 80));
    $scored++;
}

// ─── Summary ────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 1);
$totalClusters = $pdo->query("SELECT COUNT(*) FROM statement_clusters")->fetchColumn();
$totalHistory = $pdo->query("SELECT COUNT(*) FROM truthfulness_history")->fetchColumn();

logMsg("=== Truthfulness scoring complete ===");
logMsg("Statements clustered: {$clustered}");
logMsg("New clusters created: {$newClusters}");
logMsg("Clusters scored: {$scored}");
logMsg("Total clusters: {$totalClusters}");
logMsg("Total history rows: {$totalHistory}");
logMsg("Elapsed: {$elapsed}s");

setSiteSetting($pdo, 'truthfulness_score_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'clustered' => $clustered,
    'new_clusters' => $newClusters,
    'scored' => $scored,
    'total_clusters' => (int)$totalClusters,
    'total_history' => (int)$totalHistory,
    'elapsed' => $elapsed,
    'pipeline' => 'local'
]);
setSiteSetting($pdo, 'truthfulness_score_last_result', $result);

echo json_encode(['status' => 'success', 'clustered' => $clustered, 'new_clusters' => $newClusters, 'scored' => $scored]);
