<?php
/**
 * AI Queue Trigger — run step1 (gather) and queue the AI job
 * ============================================================
 * Called by server cron or manually. Runs the gather logic for a job_type,
 * builds the prompt, inserts into ai_queue.
 *
 * Usage: GET ?type=threat_collect&key=xxx
 *        GET ?type=statement_collect&official_id=326&key=xxx
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
require_once dirname(__DIR__) . '/includes/site-settings.php';

// Auth
$pollerKey = $config['poller_key'] ?? '';
$sentKey = $_GET['key'] ?? '';
if (!$pollerKey || $sentKey !== $pollerKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$jobType = $_GET['type'] ?? '';

if ($jobType === 'threat_collect') {
    // Check kill switch
    $enabled = getSiteSetting($pdo, 'threat_collect_local_enabled', '0');
    if ($enabled !== '1') {
        echo json_encode(['status' => 'disabled']);
        exit;
    }

    // Run step1 gather logic inline
    $lastSuccess = getSiteSetting($pdo, 'threat_collect_last_success', '');
    if ($lastSuccess) {
        $daysSince = (int)((time() - strtotime($lastSuccess)) / 86400);
        $lookbackDays = max(1, $daysSince);
    } else {
        $lookbackDays = 2;
    }
    if ($lookbackDays > 7) $lookbackDays = 7;

    $today = date('Y-m-d');
    $windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));

    // Dedup context
    $recentThreats = $pdo->query("SELECT threat_id, threat_date, title, target, official_id, branch, severity_score FROM executive_threats ORDER BY threat_date DESC LIMIT 60")->fetchAll();
    $dedupLines = [];
    foreach ($recentThreats as $t) {
        $dedupLines[] = "#{$t['threat_id']} ({$t['threat_date']}) [{$t['branch']}] {$t['title']} — target: {$t['target']}";
    }

    // Tags
    $tags = $pdo->query("SELECT tag_id, tag_name, tag_label FROM threat_tags ORDER BY tag_id")->fetchAll();
    $tagList = [];
    foreach ($tags as $tag) $tagList[] = "{$tag['tag_id']}: {$tag['tag_name']} ({$tag['tag_label']})";

    $requestData = json_encode([
        'system_prompt' => buildThreatPrompt($windowStart, $today, implode("\n", $dedupLines), implode("\n", $tagList)),
        'user_message' => "Search the news for threats to constitutional order from {$windowStart} to {$today}. Find new developments NOT in our existing database. Check AP, Reuters, NPR, PBS, major outlets. Return structured JSON.",
        'window_start' => $windowStart,
        'today' => $today
    ]);

    $stmt = $pdo->prepare("INSERT INTO ai_queue (job_type, user_id, status, request_data) VALUES ('threat_collect', 0, 'pending', ?)");
    $stmt->execute([$requestData]);
    echo json_encode(['status' => 'queued', 'job_id' => (int)$pdo->lastInsertId()]);

} elseif ($jobType === 'statement_collect') {
    $officialId = intval($_GET['official_id'] ?? 326);

    $enabled = getSiteSetting($pdo, 'statement_collect_local_enabled', '0');
    if ($enabled !== '1') {
        echo json_encode(['status' => 'disabled']);
        exit;
    }

    // Include step1-gather to build prompt
    // Pass official_id via the request
    $requestData = json_encode([
        'official_id' => $officialId,
        'gather_on_server' => true
    ]);

    $stmt = $pdo->prepare("INSERT INTO ai_queue (job_type, user_id, status, request_data) VALUES ('statement_collect', 0, 'pending', ?)");
    $stmt->execute([$requestData]);
    echo json_encode(['status' => 'queued', 'job_id' => (int)$pdo->lastInsertId()]);

} else {
    echo json_encode(['error' => "Unknown job type: {$jobType}"]);
}

// === Threat prompt builder ===
function buildThreatPrompt($windowStart, $today, $dedupContext, $tagContext) {
    $officialContext = <<<'OFF'
Key official IDs (use these in official_id field):
Executive: 326=Trump, 9112=Vance, 3000=Noem(DHS), 9390=Bondi(AG), 9393=S.Miller(Policy), 9395=Musk(DOGE), 9397=Zeldin(EPA), 9398=K.Patel(FBI), 9399=Vought(OMB), 9401=Lutnick(Commerce), 9402=Hegseth(DoD), 9403=McMahon(Education), 9405=RFK Jr(HHS), 9408=Rubio(State), 9410=Bessent(Treasury)
Judicial: 328=Thomas, 329=Alito, 333=Kavanaugh, 349=Roberts
Congressional: Look up by name if needed. Use 326 (Trump) as fallback for executive actions where the specific official is unclear.
OFF;

    return <<<PROMPT
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
- Tier 1: AP News, Reuters, NPR, PBS NewsHour, government sites (.gov)
- Tier 2: ProPublica, Intercept, Axios, NYT, Washington Post, CNN
- Tier 3: Politico, The Hill, HuffPost (verify with Tier 1/2)

## Existing Recent Threats (DO NOT DUPLICATE)
{$dedupContext}

## Deduplication Rules
- Skip if title is similar to an existing threat
- Skip if same date + target combo exists
- Skip if same source URL already captured
- A NEW development on an existing topic IS a new threat

## Severity Scoring (0-1000 Criminality Scale)
- 1-10: Questionable  11-30: Misconduct  31-70: Misdemeanor
- 71-150: Felony  151-300: Serious Felony  301-500: High Crime
- 501-700: Atrocity  701-900: Crime Against Humanity

## Benefit Scoring (0-1000)
- 0: No benefit  1-30: Helpful  31-70: Significant  71-150: Major
Most threats will score 0. Score both honestly.

## Available Tags (assign 1-3 per threat)
{$tagContext}

## {$officialContext}

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
{"threats":[{"threat_date":"YYYY-MM-DD","title":"max 200 chars","description":"2-4 sentences","threat_type":"tactical|strategic","target":"institution/right","source_url":"url","action_script":"Contact...","official_id":326,"severity_score":0,"benefit_score":0,"branch":"executive","tags":["tag1"]}],"search_summary":"what you searched"}
PROMPT;
}
