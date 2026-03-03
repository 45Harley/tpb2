<?php
/**
 * Automated Race Rating Checker — Weekly Cron
 *
 * Uses Claude API with web search to check current competitive race
 * ratings from Cook Political Report, Sabato's Crystal Ball, and
 * Inside Elections. Compares against DB, updates changes, logs history,
 * and emails admin if any ratings shifted.
 *
 * Cron: 0 10 * * 0 (Sunday 10:00 AM EST) — cPanel clock is EST.
 *
 * Requirements:
 *   - site_settings.rating_check_enabled = '1'
 *   - config-claude.php has ANTHROPIC_API_KEY
 *   - fec_races, fec_race_history tables exist in sandge5_election
 *
 * Usage:
 *   cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/check-race-ratings.php
 */

$startTime = microtime(true);

// Bootstrap
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/site-settings.php';
require_once __DIR__ . '/../../config-claude.php';
require_once __DIR__ . '/../../includes/smtp-mail.php';

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
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/check-race-ratings.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Rating check started ===");

// Kill switch
if (getSiteSetting($pdo, 'rating_check_enabled', '0') !== '1') {
    logMsg("Rating check disabled (site_settings.rating_check_enabled != '1'). Exiting.");
    exit(0);
}

// Failure helper
function failAndNotify($pdo, $config, $errorMsg, $startTime) {
    $elapsed = round(microtime(true) - $startTime, 1);
    $result = json_encode([
        'status' => 'error', 'error' => $errorMsg,
        'timestamp' => date('Y-m-d H:i:s'), 'elapsed' => $elapsed
    ]);
    setSiteSetting($pdo, 'rating_check_last_result', $result);
    logMsg("FAILED: {$errorMsg}");

    $adminEmail = $config['admin_email'] ?? null;
    if ($adminEmail) {
        sendSmtpMail($config, $adminEmail,
            'TPB Rating Check FAILED — ' . date('M j'),
            "<p>The automated rating check failed at " . date('g:i A') . ".</p>"
            . "<p><strong>Error:</strong> " . htmlspecialchars($errorMsg) . "</p>"
            . "<p>Check: <code>scripts/maintenance/logs/check-race-ratings.log</code></p>"
        );
    }
}

// ─── Step 1: Load current races from DB ──────────────────────────────────
logMsg("Step 1: Loading active races...");

$races = $pdoE->query("
    SELECT race_id, state, district, office, rating, held_by
    FROM fec_races WHERE is_active = 1
    ORDER BY state, office, district
")->fetchAll();

$raceCount = count($races);
logMsg("Found {$raceCount} active races.");

if ($raceCount === 0) {
    logMsg("No active races. Exiting.");
    exit(0);
}

// Build race list for prompt
$raceLines = [];
foreach ($races as $r) {
    $label = $r['state'];
    if ($r['office'] === 'H' && $r['district']) $label .= '-' . $r['district'];
    $label .= ' ' . ($r['office'] === 'S' ? 'Senate' : 'House');
    $raceLines[] = "{$r['race_id']}: {$label} — current rating: {$r['rating']}";
}
$raceContext = implode("\n", $raceLines);

// ─── Step 2: Call Claude API with web search ─────────────────────────────
logMsg("Step 2: Calling Claude API with web search...");

$prompt = <<<PROMPT
I need you to check the CURRENT competitive race ratings for the 2026 U.S. midterm elections.

Here are the races I'm tracking with their current ratings in my database:

{$raceContext}

For EACH race, search for the most recent rating from Cook Political Report, Sabato's Crystal Ball, or Inside Elections (in that priority order).

The valid ratings are (from least to most competitive):
- Solid R / Solid D (safe seat)
- Likely R / Likely D
- Lean R / Lean D
- Toss-Up

Return a JSON array of ONLY the races where the rating has CHANGED from what I have. If nothing changed, return an empty array.

Format:
```json
[
  {
    "race_id": 25,
    "race_label": "TX Senate",
    "old_rating": "Likely R",
    "new_rating": "Lean R",
    "source": "Cook Political Report",
    "source_date": "2026-01-15",
    "notes": "Shifted after Paxton won primary"
  }
]
```

IMPORTANT:
- Only include races where the rating ACTUALLY CHANGED
- Use the EXACT rating strings: "Solid R", "Likely R", "Lean R", "Toss-Up", "Lean D", "Likely D", "Solid D"
- Include the source (which outlet reported the change)
- Include source_date as YYYY-MM-DD — the date the outlet published the rating change
- Search for the LATEST ratings — they may have changed recently
- If you can't find a current rating for a race, skip it (don't guess)
PROMPT;

$apiPayload = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 2000,
    'tools' => [
        ['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 10]
    ],
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ]
];

$ch = curl_init(ANTHROPIC_API_URL);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($apiPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    failAndNotify($pdo, $config, "cURL error: {$curlError}", $startTime);
    exit(1);
}
if ($httpCode !== 200) {
    $errBody = substr($response, 0, 500);
    failAndNotify($pdo, $config, "API returned HTTP {$httpCode}: {$errBody}", $startTime);
    exit(1);
}

$data = json_decode($response, true);
if (!$data || !isset($data['content'])) {
    failAndNotify($pdo, $config, "Invalid API response structure", $startTime);
    exit(1);
}

// Extract usage
$usage = $data['usage'] ?? [];
$inputTokens = $usage['input_tokens'] ?? 0;
$outputTokens = $usage['output_tokens'] ?? 0;
logMsg("API call complete. Tokens: {$inputTokens} input, {$outputTokens} output.");

// ─── Step 3: Parse response ──────────────────────────────────────────────
logMsg("Step 3: Parsing response...");

// Extract text from content blocks
$fullText = '';
foreach ($data['content'] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $fullText .= $block['text'];
    }
}

// Extract JSON from response
if (preg_match('/```json\s*(.*?)\s*```/s', $fullText, $m)) {
    $jsonStr = $m[1];
} elseif (preg_match('/\[\s*\{.*?\}\s*\]/s', $fullText, $m)) {
    $jsonStr = $m[0];
} elseif (preg_match('/\[\s*\]/s', $fullText, $m)) {
    $jsonStr = '[]';
} else {
    // Maybe the response is just text saying "no changes"
    if (stripos($fullText, 'no change') !== false || stripos($fullText, 'empty array') !== false) {
        $jsonStr = '[]';
    } else {
        failAndNotify($pdo, $config, "Could not extract JSON from response: " . substr($fullText, 0, 300), $startTime);
        exit(1);
    }
}

$changes = json_decode($jsonStr, true);
if (!is_array($changes)) {
    failAndNotify($pdo, $config, "JSON parse failed: " . substr($jsonStr, 0, 300), $startTime);
    exit(1);
}

logMsg("Found " . count($changes) . " rating change(s).");

// ─── Step 4: Validate and apply changes ──────────────────────────────────
$validRatings = ['Solid R', 'Likely R', 'Lean R', 'Toss-Up', 'Lean D', 'Likely D', 'Solid D'];
$raceMap = [];
foreach ($races as $r) $raceMap[$r['race_id']] = $r;

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

    // Validate race exists
    if (!isset($raceMap[$raceId])) {
        logMsg("SKIP {$label}: race_id {$raceId} not found in active races.");
        $skipped++;
        continue;
    }

    // Validate rating string
    if (!in_array($newRating, $validRatings)) {
        logMsg("SKIP {$label}: invalid rating '{$newRating}'.");
        $skipped++;
        continue;
    }

    $currentRating = $raceMap[$raceId]['rating'];

    // Skip if no actual change
    if ($newRating === $currentRating) {
        logMsg("SKIP {$label}: rating unchanged ({$currentRating}).");
        $skipped++;
        continue;
    }

    // Apply change
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

// ─── Step 5: Record success ──────────────────────────────────────────────
$elapsed = round(microtime(true) - $startTime, 1);

setSiteSetting($pdo, 'rating_check_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'races_checked' => $raceCount,
    'changes_found' => count($changes),
    'changes_applied' => $applied,
    'skipped' => $skipped,
    'elapsed' => $elapsed,
    'input_tokens' => $inputTokens,
    'output_tokens' => $outputTokens
]);
setSiteSetting($pdo, 'rating_check_last_result', $result);

logMsg("Done. Checked: {$raceCount}, Changed: {$applied}, Skipped: {$skipped}, Elapsed: {$elapsed}s");

// ─── Step 6: Email if anything changed ───────────────────────────────────
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
            $body
        );
        logMsg("Shift notification sent to {$adminEmail}.");
    }
}

logMsg("=== Rating check complete ===\n");
