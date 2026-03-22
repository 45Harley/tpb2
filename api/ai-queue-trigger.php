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

    // Inline step1-gather logic from collect-statements-step1-gather.php
    require_once dirname(__DIR__) . '/scripts/maintenance/collect-statements-step1-gather-q.php';
    $gatherResult = gatherStatementPrompt($pdo, $officialId);
    if (!$gatherResult) {
        echo json_encode(['error' => "Unknown official_id: {$officialId}"]);
        exit;
    }

    $requestData = json_encode($gatherResult);
    $stmt = $pdo->prepare("INSERT INTO ai_queue (job_type, user_id, status, request_data) VALUES ('statement_collect', 0, 'pending', ?)");
    $stmt->execute([$requestData]);
    echo json_encode(['status' => 'queued', 'job_id' => (int)$pdo->lastInsertId(), 'official_id' => $officialId]);

} elseif ($jobType === 'truthfulness') {
    $enabled = getSiteSetting($pdo, 'truthfulness_score_local_enabled', '0');
    if ($enabled !== '1') {
        echo json_encode(['status' => 'disabled']);
        exit;
    }

    // Inline step1-gather logic from score-truthfulness-step1-gather
    $today = date('Y-m-d');

    $unclustered = $pdo->query("SELECT id, content, summary, policy_topic, tense, source, statement_date, severity_score, benefit_score FROM rep_statements WHERE cluster_id IS NULL ORDER BY statement_date DESC")->fetchAll();
    $clusters = $pdo->query("SELECT sc.id, sc.canonical_claim, sc.policy_topic, sc.repeat_count, sc.truthfulness_score AS prev_score, sc.first_seen, sc.last_seen FROM statement_clusters sc ORDER BY sc.id")->fetchAll();

    $clusterStatements = [];
    if (!empty($clusters)) {
        $cids = array_column($clusters, 'id');
        $ph = implode(',', array_fill(0, count($cids), '?'));
        $cs = $pdo->prepare("SELECT id, cluster_id, content, summary, tense, statement_date FROM rep_statements WHERE cluster_id IN ($ph) ORDER BY cluster_id, statement_date");
        $cs->execute($cids);
        while ($r = $cs->fetch()) $clusterStatements[$r['cluster_id']][] = $r;
    }

    $threats = $pdo->query("SELECT threat_id, title, description, threat_date, target, branch, severity_score, benefit_score FROM executive_threats WHERE is_active = 1 ORDER BY threat_date DESC LIMIT 80")->fetchAll();

    $clusterContext = [];
    foreach ($clusters as $c) {
        $stmts = $clusterStatements[$c['id']] ?? [];
        $ss = [];
        foreach ($stmts as $s) $ss[] = "[{$s['statement_date']}] {$s['summary']}";
        $clusterContext[] = ['cluster_id' => (int)$c['id'], 'canonical_claim' => $c['canonical_claim'], 'policy_topic' => $c['policy_topic'], 'repeat_count' => (int)$c['repeat_count'], 'prev_score' => $c['prev_score'] !== null ? (int)$c['prev_score'] : null, 'date_range' => $c['first_seen'] . ' to ' . $c['last_seen'], 'statements' => $ss];
    }

    $threatContext = [];
    foreach ($threats as $t) $threatContext[] = "#{$t['threat_id']} ({$t['threat_date']}) [{$t['branch']}] {$t['title']} — {$t['target']}. Sev:{$t['severity_score']} Ben:{$t['benefit_score']}";

    $unclusteredContext = [];
    foreach ($unclustered as $u) $unclusteredContext[] = ['statement_id' => (int)$u['id'], 'content' => $u['content'], 'summary' => $u['summary'], 'policy_topic' => $u['policy_topic'], 'tense' => $u['tense'], 'date' => $u['statement_date']];

    $systemPrompt = buildTruthfulnessPrompt(
        !empty($clusterContext) ? json_encode($clusterContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'None yet',
        !empty($unclusteredContext) ? json_encode($unclusteredContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'None',
        !empty($threatContext) ? implode("\n", $threatContext) : 'No tracked actions yet.'
    );

    $requestData = json_encode([
        'system_prompt' => $systemPrompt,
        'user_message' => 'Analyze all statements. Cluster any unclustered ones. Then score truthfulness for every cluster based on current evidence. Return structured JSON.'
    ]);

    $stmt = $pdo->prepare("INSERT INTO ai_queue (job_type, user_id, status, request_data) VALUES ('truthfulness', 0, 'pending', ?)");
    $stmt->execute([$requestData]);
    echo json_encode(['status' => 'queued', 'job_id' => (int)$pdo->lastInsertId(), 'unclustered' => count($unclustered), 'clusters' => count($clusters)]);

} else {
    echo json_encode(['error' => "Unknown job type: {$jobType}"]);
}

// === Truthfulness prompt builder ===
function buildTruthfulnessPrompt($clusterJson, $unclusteredJson, $threatText) {
    return <<<PROMPT
You are a truthfulness analyst for The People's Branch (TPB).

You have TWO jobs:

## Job 1: Cluster unclustered statements
Assign each to an existing cluster OR create a new one. Same cluster = same core claim/promise.

## Job 2: Score truthfulness for ALL clusters (0-1000)
0-100: False  101-200: Mostly False  201-300: Misleading  301-400: Half True
401-500: Mixed  501-600: Mostly True  601-700: True  701-800: Very True
801-900: Verified  901-1000: Precisely True

## Existing Clusters
{$clusterJson}

## Unclustered Statements
{$unclusteredJson}

## Evidence: Recent Government Actions
{$threatText}

## Output Format
Return ONLY valid JSON:
{"cluster_assignments":[{"statement_id":123,"cluster_id":5,"cluster_id_is_new":false},{"statement_id":456,"cluster_id":null,"cluster_id_is_new":true,"new_cluster":{"canonical_claim":"claim","policy_topic":"topic"}}],"truthfulness_scores":[{"cluster_id":5,"cluster_id_is_new":false,"score":350,"note":"explanation","evidence_refs":"threat #123"},{"cluster_id":null,"cluster_id_is_new":true,"canonical_claim":"claim","score":500,"note":"explanation","evidence_refs":""}]}
PROMPT;
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
