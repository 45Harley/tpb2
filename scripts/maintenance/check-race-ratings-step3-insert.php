<?php
/**
 * Step 3: Parse claude -p response, validate + apply rating changes, email admin.
 * Runs on the SERVER via SSH.
 *
 * Usage: php check-race-ratings-step3-insert.php <response-file>
 */

$responseFile = $argv[1] ?? '';
if (!$responseFile || !file_exists($responseFile)) {
    echo "ERROR: Response file not found: {$responseFile}\n";
    exit(1);
}

$startTime = microtime(true);
$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';
require_once $base . '/includes/smtp-mail.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdoE = new PDO(
    "mysql:host={$config['host']};dbname=sandge5_election;charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Logging
$logDir = $base . '/scripts/maintenance/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/check-race-ratings.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Rating check (local pipeline) started ===");

// Read and parse claude response
$rawResponse = file_get_contents($responseFile);
logMsg("Response file: " . strlen($rawResponse) . " bytes");

// Extract JSON from response — claude -p may include extra text
if (preg_match('/```json\s*(.*?)\s*```/s', $rawResponse, $m)) {
    $jsonStr = $m[1];
} elseif (preg_match('/\{[\s\S]*"changes"\s*:\s*\[[\s\S]*\]\s*\}/s', $rawResponse, $m)) {
    $jsonStr = $m[0];
} elseif (preg_match('/\{\s*"changes"\s*:\s*\[\s*\]\s*\}/', $rawResponse, $m)) {
    $jsonStr = $m[0];
} else {
    // Legacy format support (bare array)
    if (preg_match('/\[\s*\{.*?\}\s*\]/s', $rawResponse, $m)) {
        $jsonStr = '{"changes": ' . $m[0] . '}';
    } elseif (stripos($rawResponse, 'no change') !== false || preg_match('/\[\s*\]/', $rawResponse)) {
        $jsonStr = '{"changes": []}';
    } else {
        $err = "Could not extract JSON from response: " . substr($rawResponse, 0, 300);
        logMsg("ERROR: {$err}");
        setSiteSetting($pdo, 'rating_check_last_result', json_encode([
            'status' => 'error', 'error' => $err, 'timestamp' => date('Y-m-d H:i:s')
        ]));
        exit(1);
    }
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['changes'])) {
    $err = "JSON parse failed: " . substr($jsonStr, 0, 300);
    logMsg("ERROR: {$err}");
    setSiteSetting($pdo, 'rating_check_last_result', json_encode([
        'status' => 'error', 'error' => $err, 'timestamp' => date('Y-m-d H:i:s')
    ]));
    exit(1);
}

$changes = $parsed['changes'];
logMsg("Found " . count($changes) . " rating change(s).");

// Load current races for validation
$races = $pdoE->query("
    SELECT race_id, state, district, office, rating, held_by
    FROM fec_races WHERE is_active = 1
")->fetchAll();

$raceMap = [];
foreach ($races as $r) $raceMap[$r['race_id']] = $r;

$validRatings = ['Solid R', 'Likely R', 'Lean R', 'Toss-Up', 'Lean D', 'Likely D', 'Solid D'];

$applied = 0;
$skipped = 0;
$changedRaces = [];

$histStmt = $pdoE->prepare("INSERT INTO fec_race_history (race_id, field_changed, old_value, new_value, source, source_date) VALUES (?, 'rating', ?, ?, ?, ?)");
$updateStmt = $pdoE->prepare("UPDATE fec_races SET rating = ? WHERE race_id = ?");

foreach ($changes as $change) {
    $raceId = (int)($change['race_id'] ?? 0);
    $newRating = $change['new_rating'] ?? '';
    $label = $change['race_label'] ?? "race #{$raceId}";
    $source = $change['source'] ?? 'unknown';
    $sourceDate = $change['source_date'] ?? null;
    $notes = $change['notes'] ?? '';

    if (!isset($raceMap[$raceId])) {
        logMsg("SKIP {$label}: race_id {$raceId} not found in active races.");
        $skipped++;
        continue;
    }

    if (!in_array($newRating, $validRatings)) {
        logMsg("SKIP {$label}: invalid rating '{$newRating}'.");
        $skipped++;
        continue;
    }

    $currentRating = $raceMap[$raceId]['rating'];
    if ($newRating === $currentRating) {
        logMsg("SKIP {$label}: rating unchanged ({$currentRating}).");
        $skipped++;
        continue;
    }

    $histStmt->execute([$raceId, $currentRating, $newRating, $source, $sourceDate]);
    $updateStmt->execute([$newRating, $raceId]);
    logMsg("UPDATED {$label}: {$currentRating} → {$newRating} (source: {$source})");
    $applied++;

    $changedRaces[] = [
        'label' => $label,
        'old' => $currentRating,
        'new' => $newRating,
        'source' => $source,
        'notes' => $notes
    ];
}

// Record success
$elapsed = round(microtime(true) - $startTime, 1);

setSiteSetting($pdo, 'rating_check_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'races_checked' => count($races),
    'changes_found' => count($changes),
    'changes_applied' => $applied,
    'skipped' => $skipped,
    'elapsed' => $elapsed,
    'method' => 'local_pipeline'
]);
setSiteSetting($pdo, 'rating_check_last_result', $result);

logMsg("Done. Checked: " . count($races) . ", Changed: {$applied}, Skipped: {$skipped}, Elapsed: {$elapsed}s");

// Email admin if anything changed
if (!empty($changedRaces)) {
    $adminEmail = $config['admin_email'] ?? null;
    if ($adminEmail) {
        $rows = '';
        foreach ($changedRaces as $cr) {
            $rows .= "<tr>"
                . "<td style='padding:6px 12px;border:1px solid #333;'>{$cr['label']}</td>"
                . "<td style='padding:6px 12px;border:1px solid #333;'>{$cr['old']}</td>"
                . "<td style='padding:6px 12px;border:1px solid #333;font-weight:bold;'>{$cr['new']}</td>"
                . "<td style='padding:6px 12px;border:1px solid #333;'>{$cr['source']}</td>"
                . "<td style='padding:6px 12px;border:1px solid #333;'>{$cr['notes']}</td>"
                . "</tr>";
        }

        $body = "<h2>Race Rating Shifts Detected</h2>"
            . "<p>" . count($changedRaces) . " rating(s) updated on " . date('M j, Y') . ":</p>"
            . "<table style='border-collapse:collapse;'>"
            . "<tr style='background:#1a1a2e;color:#d4af37;'>"
            . "<th style='padding:8px 12px;border:1px solid #333;'>Race</th>"
            . "<th style='padding:8px 12px;border:1px solid #333;'>Old</th>"
            . "<th style='padding:8px 12px;border:1px solid #333;'>New</th>"
            . "<th style='padding:8px 12px;border:1px solid #333;'>Source</th>"
            . "<th style='padding:8px 12px;border:1px solid #333;'>Notes</th>"
            . "</tr>{$rows}</table>"
            . "<p><a href='https://tpb2.sandgems.net/elections/races.php'>View Race Dashboard</a></p>";

        sendSmtpMail($config, $adminEmail,
            'TPB Race Rating SHIFT — ' . count($changedRaces) . ' race(s) moved — ' . date('M j'),
            $body, null, true
        );
        logMsg("Shift notification sent to {$adminEmail}.");
    }
}

logMsg("=== Rating check complete ===\n");
