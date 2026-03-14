<?php
/**
 * Step 3: Parse Claude's JSON response and insert statements into DB.
 * Runs on the SERVER via SSH. Reads JSON from file argument.
 *
 * Usage: php collect-statements-step3-insert.php /path/to/claude-response.json
 */

$startTime = microtime(true);

// When SCP'd to ~/, __DIR__ won't find project files. Use absolute server path.
$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Logging
$logDir = $base . '/scripts/maintenance/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/collect-statements-local.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Step 3: Insert statements (local pipeline) ===");

// Read Claude's response
$inputFile = $argv[1] ?? '';
if (!$inputFile || !file_exists($inputFile)) {
    logMsg("ERROR: No input file provided or file not found: {$inputFile}");
    echo json_encode(['status' => 'error', 'message' => 'No input file']);
    exit(1);
}

$rawText = file_get_contents($inputFile);
logMsg("Read " . strlen($rawText) . " bytes from {$inputFile}");

// Parse JSON — strip code blocks if present
$jsonStr = $rawText;

if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}

// Find JSON object with "statements" key
if (preg_match('/\{[\s\S]*"statements"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['statements'])) {
    logMsg("ERROR: Could not parse JSON. Raw (first 2000 chars): " . substr($rawText, 0, 2000));
    echo json_encode(['status' => 'error', 'message' => 'JSON parse failed']);
    exit(1);
}

$statements = $parsed['statements'];
$searchSummary = $parsed['search_summary'] ?? 'No summary provided';
logMsg("Search summary: {$searchSummary}");

if (empty($statements)) {
    logMsg("No new statements found.");
    setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'statement_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'note' => 'No new statements found (local pipeline)',
        'elapsed' => round(microtime(true) - $startTime, 1)
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0]);
    exit(0);
}

logMsg("Found " . count($statements) . " candidate statements. Running dedup...");

// ─── Programmatic dedup ─────────────────────────────────────────────────
// Dedup by exact match on (official_id, statement_date, LEFT(content, 200)) + URL dedup
$existingStatements = $pdo->query("
    SELECT id, official_id, statement_date, LEFT(content, 200) AS content_start, source_url
    FROM rep_statements
")->fetchAll();

$existingUrls = [];
$existingKeys = [];
foreach ($existingStatements as $es) {
    if ($es['source_url']) {
        $existingUrls[] = strtolower(trim($es['source_url']));
    }
    // Build dedup key: official_id + date + first 200 chars of content (lowercased, trimmed)
    $key = $es['official_id'] . '|' . $es['statement_date'] . '|' . strtolower(trim($es['content_start']));
    $existingKeys[] = $key;
}

$dedupedStatements = [];
$skippedCount = 0;
foreach ($statements as $stmt) {
    $content = trim($stmt['content'] ?? '');
    $officialId = intval($stmt['official_id'] ?? 326);
    $stmtDate = trim($stmt['statement_date'] ?? date('Y-m-d'));
    $sourceUrl = strtolower(trim($stmt['source_url'] ?? ''));

    // URL dedup
    if ($sourceUrl && in_array($sourceUrl, $existingUrls)) {
        logMsg("DEDUP SKIP (same URL): " . substr($content, 0, 80));
        $skippedCount++;
        continue;
    }

    // Content dedup: official_id + date + first 200 chars
    $contentKey = $officialId . '|' . $stmtDate . '|' . strtolower(substr($content, 0, 200));
    if (in_array($contentKey, $existingKeys)) {
        logMsg("DEDUP SKIP (same content key): " . substr($content, 0, 80));
        $skippedCount++;
        continue;
    }

    $dedupedStatements[] = $stmt;
}

$statements = $dedupedStatements;
logMsg("After dedup: " . count($statements) . " new statements ({$skippedCount} duplicates skipped)");

if (empty($statements)) {
    logMsg("All statements were duplicates.");
    $elapsed = round(microtime(true) - $startTime, 1);
    setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'statement_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'skipped' => $skippedCount, 'note' => 'All duplicates (local pipeline)',
        'elapsed' => $elapsed
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0, 'skipped' => $skippedCount]);
    exit(0);
}

logMsg("Inserting " . count($statements) . " new statements...");

// Valid tense values
$validTenses = ['future', 'present', 'past'];

// Valid policy topics
$validTopics = [
    'Economy & Jobs', 'Healthcare', 'Education', 'Environment & Climate',
    'Immigration', 'National Security', 'Criminal Justice', 'Housing',
    'Infrastructure', 'Social Services', 'Tax Policy', 'Civil Rights',
    'Technology & Privacy', 'Foreign Policy', 'Agriculture', 'Government Reform'
];

// Insert statements
$insertStmt = $pdo->prepare("
    INSERT INTO rep_statements
    (official_id, source, source_url, content, summary, policy_topic, tense, severity_score, benefit_score, statement_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$today = date('Y-m-d');
$inserted = 0;

foreach ($statements as $stmt) {
    $officialId = intval($stmt['official_id'] ?? 326);
    $source = trim($stmt['source'] ?? 'Unknown');
    $sourceUrl = trim($stmt['source_url'] ?? '');
    $content = trim($stmt['content'] ?? '');
    $summary = trim($stmt['summary'] ?? '');
    $policyTopic = trim($stmt['policy_topic'] ?? '');
    $tense = trim($stmt['tense'] ?? '');
    $severityScore = intval($stmt['severity_score'] ?? 0);
    $benefitScore = intval($stmt['benefit_score'] ?? 0);
    $stmtDate = trim($stmt['statement_date'] ?? $today);

    // Validate required fields
    if (empty($content)) {
        logMsg("SKIP: Empty content — " . substr($summary, 0, 50));
        continue;
    }

    // Validate tense
    if (!in_array($tense, $validTenses)) {
        logMsg("WARNING: Invalid tense '{$tense}', defaulting to NULL");
        $tense = null;
    }

    // Validate policy topic (warn but still insert)
    if ($policyTopic && !in_array($policyTopic, $validTopics)) {
        logMsg("WARNING: Unknown policy topic '{$policyTopic}' — inserting as-is");
    }

    // Cap source length
    if (strlen($source) > 100) {
        $source = substr($source, 0, 100);
    }

    // Cap summary length
    if (strlen($summary) > 500) {
        $summary = substr($summary, 0, 497) . '...';
    }

    try {
        $insertStmt->execute([
            $officialId, $source, $sourceUrl ?: null, $content, $summary ?: null,
            $policyTopic ?: null, $tense, $severityScore, $benefitScore, $stmtDate
        ]);
        $statementId = $pdo->lastInsertId();
        $inserted++;
        logMsg("Inserted statement #{$statementId} (sev:{$severityScore} ben:{$benefitScore}): " . substr($content, 0, 80));
    } catch (PDOException $e) {
        logMsg("ERROR inserting statement: " . $e->getMessage() . " — " . substr($content, 0, 80));
    }
}

// Summary
$elapsed = round(microtime(true) - $startTime, 1);
$totalStatements = $pdo->query("SELECT COUNT(*) FROM rep_statements")->fetchColumn();

logMsg("=== Collection complete (local pipeline) ===");
logMsg("Statements inserted: {$inserted}");
logMsg("Duplicates skipped: {$skippedCount}");
logMsg("Total statements in DB: {$totalStatements}");
logMsg("Elapsed: {$elapsed}s");

setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'inserted' => $inserted,
    'skipped' => $skippedCount,
    'total_statements' => (int)$totalStatements,
    'elapsed' => $elapsed,
    'pipeline' => 'local'
]);
setSiteSetting($pdo, 'statement_collect_last_result', $result);
logMsg("Success recorded to site_settings.");

echo json_encode(['status' => 'success', 'inserted' => $inserted, 'skipped' => $skippedCount]);
