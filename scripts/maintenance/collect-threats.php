<?php
/**
 * Automated Threat Collection — Daily Cron
 *
 * Uses Claude API with web search to research new threats to
 * constitutional order, dedup against existing threats, insert,
 * score, tag, and generate polls.
 *
 * Cron: 0 19 * * * (7:00 PM EST daily) — cPanel clock is EST.
 *
 * Requirements:
 *   - site_settings.threat_collect_enabled = '1'
 *   - config-claude.php has ANTHROPIC_API_KEY
 *   - executive_threats, threat_tags, threat_tag_map tables exist
 *
 * Usage:
 *   cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/collect-threats.php
 *
 * Logs:
 *   Appends to scripts/maintenance/logs/collect-threats.log
 */

$startTime = microtime(true);

// Bootstrap
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/site-settings.php';
require_once __DIR__ . '/../../config-claude.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Logging
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/collect-threats.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Threat collection started ===");

// Check kill switch
if (getSiteSetting($pdo, 'threat_collect_enabled', '0') !== '1') {
    logMsg("Threat collection is disabled (site_settings.threat_collect_enabled != '1'). Exiting.");
    exit(0);
}

// Load SMTP for failure notifications
require_once __DIR__ . '/../../includes/smtp-mail.php';

// ─── Adaptive date window ─────────────────────────────────────────────────
// Default: 1 day. If last success was >1 day ago, widen to cover the gap.
$lastSuccess = getSiteSetting($pdo, 'threat_collect_last_success', '');
if ($lastSuccess) {
    $daysSinceSuccess = (int)((time() - strtotime($lastSuccess)) / 86400);
    $lookbackDays = max(1, $daysSinceSuccess);
    if ($lookbackDays > 1) {
        logMsg("Last success was {$daysSinceSuccess} days ago ({$lastSuccess}). Widening window to {$lookbackDays} days.");
    }
} else {
    $lookbackDays = 2; // First run ever — look back 2 days
    logMsg("No previous success recorded. Using default 2-day window.");
}
// Cap at 7 days to avoid overwhelming the API
if ($lookbackDays > 7) {
    logMsg("WARNING: Last success was {$daysSinceSuccess} days ago. Capping lookback at 7 days.");
    $lookbackDays = 7;
}

// Helper to record result and exit on failure
function failAndNotify($pdo, $config, $errorMsg, $startTime) {
    $elapsed = round(microtime(true) - $startTime, 1);
    $result = json_encode([
        'status' => 'error',
        'error' => $errorMsg,
        'timestamp' => date('Y-m-d H:i:s'),
        'elapsed' => $elapsed
    ]);
    setSiteSetting($pdo, 'threat_collect_last_result', $result);
    logMsg("Saving failure result to site_settings.");

    // Email notification
    $adminEmail = $config['admin_email'] ?? null;
    if ($adminEmail) {
        sendSmtpMail($config, $adminEmail,
            'TPB Threat Collection FAILED — ' . date('M j'),
            "<p>The automated threat collection cron failed at " . date('g:i A') . ".</p>"
            . "<p><strong>Error:</strong> " . htmlspecialchars($errorMsg) . "</p>"
            . "<p>Check the log: <code>scripts/maintenance/logs/collect-threats.log</code></p>"
        );
        logMsg("Failure notification sent to {$adminEmail}.");
    }
}

// ─── Step 1: Get existing threats for dedup context ───────────────────────
logMsg("Step 1: Loading existing threats for dedup...");

$recentThreats = $pdo->query("
    SELECT threat_id, threat_date, title, target, official_id, branch, severity_score
    FROM executive_threats
    ORDER BY threat_date DESC
    LIMIT 60
")->fetchAll();

$threatCount = $pdo->query("SELECT COUNT(*) FROM executive_threats")->fetchColumn();
logMsg("Found {$threatCount} total threats. Using last 60 for dedup context.");

// Build dedup context string
$dedupLines = [];
foreach ($recentThreats as $t) {
    $dedupLines[] = "#{$t['threat_id']} ({$t['threat_date']}) [{$t['branch']}] {$t['title']} — target: {$t['target']}";
}
$dedupContext = implode("\n", $dedupLines);

// ─── Step 2: Get tag list ────────────────────────────────────────────────
$tags = $pdo->query("SELECT tag_id, tag_name, tag_label FROM threat_tags ORDER BY tag_id")->fetchAll();
$tagList = [];
foreach ($tags as $tag) {
    $tagList[] = "{$tag['tag_id']}: {$tag['tag_name']} ({$tag['tag_label']})";
}
$tagContext = implode("\n", $tagList);

// ─── Step 3: Get official IDs ────────────────────────────────────────────
$officialContext = <<<'OFF'
Key official IDs (use these in official_id field):
Executive: 326=Trump, 9112=Vance, 3000=Noem(DHS), 9390=Bondi(AG), 9393=S.Miller(Policy), 9395=Musk(DOGE), 9397=Zeldin(EPA), 9398=K.Patel(FBI), 9399=Vought(OMB), 9401=Lutnick(Commerce), 9402=Hegseth(DoD), 9403=McMahon(Education), 9405=RFK Jr(HHS), 9408=Rubio(State), 9410=Bessent(Treasury)
Judicial: 328=Thomas, 329=Alito, 333=Kavanaugh, 349=Roberts
Congressional: Look up by name if needed. Use 326 (Trump) as fallback for executive actions where the specific official is unclear.
OFF;

// ─── Step 4: Build the system prompt ─────────────────────────────────────
$today = date('Y-m-d');
$windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));
logMsg("Search window: {$windowStart} to {$today} ({$lookbackDays} day" . ($lookbackDays > 1 ? 's' : '') . ")");

$systemPrompt = <<<PROMPT
You are a constitutional accountability researcher for The People's Branch (TPB).

Your job: Search the news for NEW threats to constitutional order from {$windowStart} to {$today} that are NOT already in our database.

## What Counts as a Threat
Actions by government officials that:
- Violate law or the Constitution
- Defy court orders
- Attack institutions or separation of powers
- Harm citizens through abuse of power
- Undermine oversight, transparency, or democratic norms

Cover all three branches:
- **Executive**: Executive orders, firings, agency dismantling, DOGE cuts, court defiance, immigration enforcement, attacks on press, military actions, political retribution
- **Congressional**: Blocking oversight, enabling overreach, insider trading, obstructing investigations, voter suppression
- **Judicial**: Ethics violations, partisan rulings, refusal to recuse, shadow docket abuse

## News Sources to Search
Search these (prioritize Tier 1):
- Tier 1: AP News, Reuters, NPR, PBS NewsHour, government sites (.gov)
- Tier 2: ProPublica, Intercept, Axios, NYT, Washington Post, CNN
- Tier 3: Politico, The Hill, HuffPost (verify with Tier 1/2)

## Existing Recent Threats (DO NOT DUPLICATE)
{$dedupContext}

## Deduplication Rules
- Skip if title is similar to an existing threat
- Skip if same date + target combo exists
- Skip if same source URL already captured
- A NEW development on an existing topic IS a new threat (e.g., court ruling on previously captured EO)
- When in doubt, include it — duplicates are easier to remove than missed threats

## Severity Scoring (0-1000 Criminality Scale, Geometric)
- 1-10: Questionable (gray area, poor judgment)
- 11-30: Misconduct (minor abuse)
- 31-70: Misdemeanor (impeachment bar starts at 31)
- 71-150: Felony (single serious violation)
- 151-300: Serious Felony (multiple victims, sustained pattern)
- 301-500: High Crime (becomes a poll question at 300+)
- 501-700: Atrocity (institutional destruction)
- 701-900: Crime Against Humanity (mass harm, deaths)
Score the ACT, not the person. Consider: measurable impact, reversibility, precedent, intent.

## Available Tags (assign 1-3 per threat)
{$tagContext}

## {$officialContext}

## Output Format
Return ONLY valid JSON. No markdown, no code blocks, no explanation outside the JSON.
If you find NO new threats, return: {"threats": []}

If you find threats, return:
{
  "threats": [
    {
      "threat_date": "YYYY-MM-DD",
      "title": "Factual headline, max 200 chars, name actor and action",
      "description": "2-4 sentences. What happened, who it affects, measurable impact. Factual, not editorial.",
      "threat_type": "tactical or strategic",
      "target": "What institution/right is threatened",
      "source_url": "https://primary-source-url",
      "action_script": "Contact your [who]. Ask: '[specific question].' Support [specific org/effort].",
      "official_id": 326,
      "severity_score": 350,
      "branch": "executive",
      "tags": ["tag_name_1", "tag_name_2"]
    }
  ],
  "search_summary": "Brief note on what sources you checked and date range covered"
}

## Writing Standards
- Titles: Factual, name actor + action. Good: "DOJ Drops Defense..." Bad: "Corrupt DOJ Caves"
- Descriptions: 2-4 sentences, include numbers/dates/dollars, factual
- Action scripts: Concrete — who to contact, what to ask, what to support
- Tags: 1-3 per threat, choose by TYPE OF HARM not just topic
PROMPT;

// ─── Step 5: Call Claude API with web search ─────────────────────────────
logMsg("Step 5: Calling Claude API with web search...");

$messages = [
    [
        'role' => 'user',
        'content' => "Search the news for threats to constitutional order from {$windowStart} to {$today}. Find new developments NOT in our existing database. Check AP, Reuters, NPR, PBS, major outlets. Return structured JSON."
    ]
];

// Build API request — higher limits than chat widget
$data = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 4096,
    'system' => $systemPrompt,
    'messages' => $messages,
    'tools' => [
        [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => 15,
            'user_location' => [
                'type' => 'approximate',
                'city' => 'Washington',
                'region' => 'District of Columbia',
                'country' => 'US',
                'timezone' => 'America/New_York'
            ]
        ]
    ]
];

$headers = [
    'Content-Type: application/json',
    'x-api-key: ' . ANTHROPIC_API_KEY,
    'anthropic-version: 2023-06-01'
];

$ch = curl_init(ANTHROPIC_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    logMsg("ERROR: curl failed — {$curlError}");
    failAndNotify($pdo, $config, "curl failed: {$curlError}", $startTime);
    exit(1);
}

if ($httpCode !== 200) {
    $error = json_decode($response, true);
    $errMsg = $error['error']['message'] ?? "HTTP {$httpCode}";
    logMsg("ERROR: API returned {$httpCode} — {$errMsg}");
    failAndNotify($pdo, $config, "API {$httpCode}: {$errMsg}", $startTime);
    exit(1);
}

$apiResponse = json_decode($response, true);

// Extract text content from response (may have tool_use blocks mixed in)
$textContent = '';
if (isset($apiResponse['content'])) {
    foreach ($apiResponse['content'] as $block) {
        if ($block['type'] === 'text') {
            $textContent .= $block['text'];
        }
    }
}

$usage = $apiResponse['usage'] ?? [];
logMsg("API response received. Input tokens: " . ($usage['input_tokens'] ?? '?') . ", Output tokens: " . ($usage['output_tokens'] ?? '?'));

// ─── Step 6: Parse JSON response ─────────────────────────────────────────
logMsg("Step 6: Parsing threat data...");

// Try to extract JSON from the response (Claude may wrap it in text)
$jsonStr = $textContent;

// If wrapped in code blocks, extract
if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}

// Try to find JSON object
if (preg_match('/\{[\s\S]*"threats"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['threats'])) {
    logMsg("ERROR: Could not parse JSON from response. Raw text saved to log.");
    logMsg("RAW: " . substr($textContent, 0, 2000));
    failAndNotify($pdo, $config, "Failed to parse JSON from API response", $startTime);
    exit(1);
}

$threats = $parsed['threats'];
$searchSummary = $parsed['search_summary'] ?? 'No summary provided';
logMsg("Search summary: {$searchSummary}");

if (empty($threats)) {
    logMsg("No new threats found. Collection complete.");
    setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'note' => 'No new threats found',
        'window_days' => $lookbackDays,
        'input_tokens' => $usage['input_tokens'] ?? 0,
        'output_tokens' => $usage['output_tokens'] ?? 0
    ]));
    exit(0);
}

logMsg("Found " . count($threats) . " candidate threats. Running programmatic dedup...");

// ─── Step 6b: Programmatic dedup (title similarity + source URL) ─────────
// Claude's prompt-level dedup isn't reliable enough — double-check here
$existingTitles = $pdo->query("SELECT threat_id, title, source_url FROM executive_threats")->fetchAll();

function normalizeTitle($title) {
    // Lowercase, strip punctuation, collapse whitespace
    $t = strtolower($title);
    $t = preg_replace('/[^a-z0-9\s]/', '', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

function titleSimilarity($a, $b) {
    $wordsA = explode(' ', normalizeTitle($a));
    $wordsB = explode(' ', normalizeTitle($b));
    // Remove common stop words
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

    // Check source URL match
    if ($newUrl && in_array($newUrl, $existingUrls)) {
        logMsg("DEDUP SKIP (same URL): " . substr($newTitle, 0, 80));
        $skippedCount++;
        continue;
    }

    // Check title similarity (>60% word overlap = likely duplicate)
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
    logMsg("All threats were duplicates. Nothing new to insert.");
    $elapsed = round(microtime(true) - $startTime, 1);
    logMsg("Elapsed: {$elapsed}s");
    setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'threat_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'skipped' => $skippedCount, 'note' => 'All duplicates',
        'window_days' => $lookbackDays, 'elapsed' => $elapsed,
        'input_tokens' => $usage['input_tokens'] ?? 0,
        'output_tokens' => $usage['output_tokens'] ?? 0
    ]));
    exit(0);
}

logMsg("Inserting " . count($threats) . " new threats...");

// ─── Step 7: Build tag lookup ────────────────────────────────────────────
$tagLookup = [];
foreach ($tags as $tag) {
    $tagLookup[$tag['tag_name']] = $tag['tag_id'];
}

// ─── Step 8: Insert threats ──────────────────────────────────────────────
$insertStmt = $pdo->prepare("
    INSERT INTO executive_threats
    (threat_date, title, description, threat_type, target, source_url, action_script, official_id, is_active, severity_score, branch)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
");

$tagStmt = $pdo->prepare("INSERT IGNORE INTO threat_tag_map (threat_id, tag_id) VALUES (?, ?)");

$inserted = 0;
$tagged = 0;
$sqlLines = [];
$sqlLines[] = "-- ============================================================";
$sqlLines[] = "-- Automated Threat Collection — {$today}";
$sqlLines[] = "-- Collected by scripts/maintenance/collect-threats.php";
$sqlLines[] = "-- Search summary: {$searchSummary}";
$sqlLines[] = "-- ============================================================";
$sqlLines[] = "";

foreach ($threats as $threat) {
    // Validate required fields
    $title = trim($threat['title'] ?? '');
    $date = trim($threat['threat_date'] ?? $today);
    $desc = trim($threat['description'] ?? '');
    $type = in_array($threat['threat_type'] ?? '', ['tactical', 'strategic']) ? $threat['threat_type'] : 'tactical';
    $target = trim($threat['target'] ?? '');
    $sourceUrl = trim($threat['source_url'] ?? '');
    $actionScript = trim($threat['action_script'] ?? '');
    $officialId = intval($threat['official_id'] ?? 326);
    $score = intval($threat['severity_score'] ?? 200);
    $branch = in_array($threat['branch'] ?? '', ['executive', 'congressional', 'judicial']) ? $threat['branch'] : 'executive';
    $threatTags = $threat['tags'] ?? [];

    if (empty($title) || empty($desc)) {
        logMsg("SKIP: Empty title or description — " . substr($title, 0, 50));
        continue;
    }

    // Truncate title to 200 chars
    if (strlen($title) > 200) {
        $title = substr($title, 0, 197) . '...';
    }

    try {
        $insertStmt->execute([$date, $title, $desc, $type, $target, $sourceUrl, $actionScript, $officialId, $score, $branch]);
        $threatId = $pdo->lastInsertId();
        $inserted++;
        logMsg("Inserted threat #{$threatId} (score {$score}): {$title}");

        // Tag the threat
        foreach ($threatTags as $tagName) {
            if (isset($tagLookup[$tagName])) {
                $tagStmt->execute([$threatId, $tagLookup[$tagName]]);
                $tagged++;
            } else {
                logMsg("WARNING: Unknown tag '{$tagName}' for threat #{$threatId}");
            }
        }

        // Build SQL audit line
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

// ─── Step 9: Generate polls for 300+ threats ─────────────────────────────
logMsg("Step 9: Generating polls for 300+ threats...");

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

// ─── Step 10: Save audit SQL file ────────────────────────────────────────
if ($inserted > 0) {
    $sqlDir = __DIR__ . '/../db';
    $sqlFile = $sqlDir . "/threats-{$today}-auto.sql";
    file_put_contents($sqlFile, implode("\n", $sqlLines));
    logMsg("Audit SQL saved to scripts/db/threats-{$today}-auto.sql");
}

// ─── Summary & Success Tracking ──────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 1);
$totalThreats = $pdo->query("SELECT COUNT(*) FROM executive_threats")->fetchColumn();
$totalPolls = $pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat'")->fetchColumn();

logMsg("=== Collection complete ===");
logMsg("Threats inserted: {$inserted}");
logMsg("Tags applied: {$tagged}");
logMsg("Polls created: {$pollCount}");
logMsg("Total threats in DB: {$totalThreats}");
logMsg("Total threat polls: {$totalPolls}");
logMsg("Elapsed: {$elapsed}s");
logMsg("===============================");

// Record success
setSiteSetting($pdo, 'threat_collect_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'inserted' => $inserted,
    'skipped' => $skippedCount ?? 0,
    'polls_created' => $pollCount,
    'tags_applied' => $tagged,
    'total_threats' => (int)$totalThreats,
    'total_polls' => (int)$totalPolls,
    'window_days' => $lookbackDays,
    'elapsed' => $elapsed,
    'input_tokens' => $usage['input_tokens'] ?? 0,
    'output_tokens' => $usage['output_tokens'] ?? 0
]);
setSiteSetting($pdo, 'threat_collect_last_result', $result);
logMsg("Success recorded to site_settings.");
