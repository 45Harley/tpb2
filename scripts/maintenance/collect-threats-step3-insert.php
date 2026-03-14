<?php
/**
 * Step 3: Parse Claude's JSON response and insert threats into DB.
 * Runs on the SERVER via SSH. Reads JSON from stdin or file argument.
 *
 * Usage: php collect-threats-step3-insert.php /path/to/claude-response.json
 */

$startTime = microtime(true);

// When SCP'd to ~/, __DIR__ won't find project files. Use absolute server path.
$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';
require_once $base . '/includes/smtp-mail.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Logging
$logDir = $base . '/scripts/maintenance/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/collect-threats-local.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Step 3: Insert threats (local pipeline) ===");

// Read Claude's response
$inputFile = $argv[1] ?? '';
if (!$inputFile || !file_exists($inputFile)) {
    logMsg("ERROR: No input file provided or file not found: {$inputFile}");
    echo json_encode(['status' => 'error', 'message' => 'No input file']);
    exit(1);
}

$rawText = file_get_contents($inputFile);
logMsg("Read " . strlen($rawText) . " bytes from {$inputFile}");

// Parse JSON — same logic as original collect-threats.php
$jsonStr = $rawText;

// Strip code blocks
if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}

// Find JSON object
if (preg_match('/\{[\s\S]*"threats"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['threats'])) {
    logMsg("ERROR: Could not parse JSON. Raw (first 2000 chars): " . substr($rawText, 0, 2000));
    echo json_encode(['status' => 'error', 'message' => 'JSON parse failed']);
    exit(1);
}

$threats = $parsed['threats'];
$searchSummary = $parsed['search_summary'] ?? 'No summary provided';
logMsg("Search summary: {$searchSummary}");

if (empty($threats)) {
    logMsg("No new threats found.");
    setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'note' => 'No new threats found (local pipeline)',
        'elapsed' => round(microtime(true) - $startTime, 1)
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0]);
    exit(0);
}

logMsg("Found " . count($threats) . " candidate threats. Running dedup...");

// ─── Programmatic dedup ─────────────────────────────────────────────────
$existingTitles = $pdo->query("SELECT threat_id, title, source_url FROM executive_threats")->fetchAll();

function normalizeTitle($title) {
    $t = strtolower($title);
    $t = preg_replace('/[^a-z0-9\s]/', '', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

function titleSimilarity($a, $b) {
    $wordsA = explode(' ', normalizeTitle($a));
    $wordsB = explode(' ', normalizeTitle($b));
    $stop = ['the', 'a', 'an', 'of', 'to', 'in', 'for', 'and', 'on', 'is', 'at', 'by', 'as', 'after', 'from', 'with'];
    $wordsA = array_diff($wordsA, $stop);
    $wordsB = array_diff($wordsB, $stop);
    if (empty($wordsA) || empty($wordsB)) return 0;
    $overlap = count(array_intersect($wordsA, $wordsB));
    $maxLen = max(count($wordsA), count($wordsB));
    return $overlap / $maxLen;
}

$existingUrls = [];
foreach ($existingTitles as $et) {
    if ($et['source_url']) $existingUrls[] = strtolower(trim($et['source_url']));
}

$dedupedThreats = [];
$skippedCount = 0;
foreach ($threats as $threat) {
    $newTitle = $threat['title'] ?? '';
    $newUrl = strtolower(trim($threat['source_url'] ?? ''));

    if ($newUrl && in_array($newUrl, $existingUrls)) {
        logMsg("DEDUP SKIP (same URL): " . substr($newTitle, 0, 80));
        $skippedCount++;
        continue;
    }

    $isDuplicate = false;
    foreach ($existingTitles as $et) {
        $sim = titleSimilarity($newTitle, $et['title']);
        if ($sim > 0.6) {
            $pct = round($sim * 100);
            logMsg("DEDUP SKIP (title {$pct}% similar to #{$et['threat_id']}): " . substr($newTitle, 0, 80));
            $isDuplicate = true;
            $skippedCount++;
            break;
        }
    }

    if (!$isDuplicate) {
        $dedupedThreats[] = $threat;
    }
}

$threats = $dedupedThreats;
logMsg("After dedup: " . count($threats) . " new threats ({$skippedCount} duplicates skipped)");

if (empty($threats)) {
    logMsg("All threats were duplicates.");
    $elapsed = round(microtime(true) - $startTime, 1);
    setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'skipped' => $skippedCount, 'note' => 'All duplicates (local pipeline)',
        'elapsed' => $elapsed
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0, 'skipped' => $skippedCount]);
    exit(0);
}

logMsg("Inserting " . count($threats) . " new threats...");

// Tag lookup
$tags = $pdo->query("SELECT tag_id, tag_name FROM threat_tags ORDER BY tag_id")->fetchAll();
$tagLookup = [];
foreach ($tags as $tag) {
    $tagLookup[$tag['tag_name']] = $tag['tag_id'];
}

// Insert threats
$insertStmt = $pdo->prepare("
    INSERT INTO executive_threats
    (threat_date, title, description, threat_type, target, source_url, action_script, official_id, is_active, severity_score, benefit_score, branch)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
");
$tagStmt = $pdo->prepare("INSERT IGNORE INTO threat_tag_map (threat_id, tag_id) VALUES (?, ?)");

$today = date('Y-m-d');
$inserted = 0;
$tagged = 0;
$sqlLines = [];
$sqlLines[] = "-- ============================================================";
$sqlLines[] = "-- Automated Threat Collection (LOCAL pipeline) — {$today}";
$sqlLines[] = "-- Search summary: {$searchSummary}";
$sqlLines[] = "-- ============================================================";
$sqlLines[] = "";

foreach ($threats as $threat) {
    $title = trim($threat['title'] ?? '');
    $date = trim($threat['threat_date'] ?? $today);
    $desc = trim($threat['description'] ?? '');
    $type = in_array($threat['threat_type'] ?? '', ['tactical', 'strategic']) ? $threat['threat_type'] : 'tactical';
    $target = trim($threat['target'] ?? '');
    $sourceUrl = trim($threat['source_url'] ?? '');
    $actionScript = trim($threat['action_script'] ?? '');
    $officialId = intval($threat['official_id'] ?? 326);
    $score = intval($threat['severity_score'] ?? 200);
    $benefitScore = isset($threat['benefit_score']) ? intval($threat['benefit_score']) : 0;
    $branch = in_array($threat['branch'] ?? '', ['executive', 'congressional', 'judicial']) ? $threat['branch'] : 'executive';
    $threatTags = $threat['tags'] ?? [];

    if (empty($title) || empty($desc)) {
        logMsg("SKIP: Empty title or description — " . substr($title, 0, 50));
        continue;
    }

    if (strlen($title) > 200) {
        $title = substr($title, 0, 197) . '...';
    }

    try {
        $insertStmt->execute([$date, $title, $desc, $type, $target, $sourceUrl, $actionScript, $officialId, $score, $benefitScore, $branch]);
        $threatId = $pdo->lastInsertId();
        $inserted++;
        logMsg("Inserted threat #{$threatId} (sev:{$score} ben:{$benefitScore}): {$title}");

        foreach ($threatTags as $tagName) {
            if (isset($tagLookup[$tagName])) {
                $tagStmt->execute([$threatId, $tagLookup[$tagName]]);
                $tagged++;
            } else {
                logMsg("WARNING: Unknown tag '{$tagName}' for threat #{$threatId}");
            }
        }

        $titleEsc = str_replace("'", "''", $title);
        $descEsc = str_replace("'", "''", $desc);
        $targetEsc = str_replace("'", "''", $target);
        $urlEsc = str_replace("'", "''", $sourceUrl);
        $actionEsc = str_replace("'", "''", $actionScript);
        $sqlLines[] = "-- Threat #{$threatId}: {$title}";
        $sqlLines[] = "-- Score: {$score}, Tags: " . implode(', ', $threatTags);
        $sqlLines[] = "('$date', '$titleEsc', '$descEsc', '$type', '$targetEsc', '$urlEsc', '$actionEsc', $officialId, 1, $score, '$branch'),";
        $sqlLines[] = "";

    } catch (PDOException $e) {
        logMsg("ERROR inserting threat: " . $e->getMessage() . " — " . substr($title, 0, 80));
    }
}

// Generate polls for 300+ threats
logMsg("Generating polls for 300+ threats...");
$newPolls = $pdo->query("
    SELECT et.threat_id, et.title, et.severity_score
    FROM executive_threats et
    LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat'
    WHERE et.severity_score >= 300
      AND et.is_active = 1
      AND p.poll_id IS NULL
    ORDER BY et.severity_score DESC
")->fetchAll();

$pollStmt = $pdo->prepare("
    INSERT INTO polls (slug, question, poll_type, threat_id, active, created_by)
    VALUES (?, ?, 'threat', ?, 1, NULL)
");

$pollCount = 0;
foreach ($newPolls as $t) {
    $slug = 'threat-' . $t['threat_id'];
    $pollStmt->execute([$slug, $t['title'], $t['threat_id']]);
    $pollCount++;
    logMsg("Created poll for threat #{$t['threat_id']} (severity {$t['severity_score']})");
}

// Save audit SQL
if ($inserted > 0) {
    $sqlDir = $base . '/scripts/db';
    $sqlFile = $sqlDir . "/threats-{$today}-local.sql";
    file_put_contents($sqlFile, implode("\n", $sqlLines));
    logMsg("Audit SQL saved to scripts/db/threats-{$today}-local.sql");
}

// Summary
$elapsed = round(microtime(true) - $startTime, 1);
$totalThreats = $pdo->query("SELECT COUNT(*) FROM executive_threats")->fetchColumn();
$totalPolls = $pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat'")->fetchColumn();

logMsg("=== Collection complete (local pipeline) ===");
logMsg("Threats inserted: {$inserted}");
logMsg("Tags applied: {$tagged}");
logMsg("Polls created: {$pollCount}");
logMsg("Total threats in DB: {$totalThreats}");
logMsg("Total threat polls: {$totalPolls}");
logMsg("Elapsed: {$elapsed}s");

setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'inserted' => $inserted,
    'skipped' => $skippedCount,
    'polls_created' => $pollCount,
    'tags_applied' => $tagged,
    'total_threats' => (int)$totalThreats,
    'total_polls' => (int)$totalPolls,
    'elapsed' => $elapsed,
    'pipeline' => 'local'
]);
setSiteSetting($pdo, 'threat_collect_last_result', $result);
logMsg("Success recorded to site_settings.");

echo json_encode(['status' => 'success', 'inserted' => $inserted, 'polls' => $pollCount, 'skipped' => $skippedCount]);
