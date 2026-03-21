<?php
/**
 * Step 1: Gather context from DB for statement collection prompt.
 * Runs on the SERVER via SSH. Outputs a JSON file with the prompt + context.
 *
 * Usage: php collect-statements-step1-gather.php <official_id>
 * Output: JSON to stdout (redirected to file by .bat)
 */

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

// ─── Official configurations ────────────────────────────────────────────
$officials = [
    326 => [
        'name' => 'President Donald Trump',
        'short_name' => 'Trump',
        'title' => 'President',
        'sources_text' => <<<'SRC'
1. Truth Social (truthsocial.com/@realDonaldTrump, trumpstruth.org) — primary, highest volume
2. WhiteHouse.gov — official remarks, press briefings, executive order announcements
3. C-SPAN (c-span.org) — full transcripts of speeches, rallies, press conferences
4. Factbase (rollcall.com/factbase) — comprehensive transcript archive
Also check news outlets that quote him directly: AP, Reuters, The Hill, CNN, Fox News, NBC, CNBC
SRC,
        'source_types' => 'Truth Social | Press Conference | Interview | WH Statement | Rally',
        'search_instruction' => 'Check Truth Social, press conferences, interviews, White House statements, and media coverage.',
        'extra_rules' => "- Retweets/reposts of others are NOT presidential statements unless he adds commentary\n",
    ],
    374 => [
        'name' => 'U.S. Senator Richard Blumenthal (D-CT)',
        'short_name' => 'Blumenthal',
        'title' => 'U.S. Senator',
        'sources_text' => <<<'SRC'
1. blumenthal.senate.gov/newsroom/press/ — official press releases and statements (PRIMARY)
2. Twitter/X @SenBlumenthal — real-time reactions, short statements
3. Senate committee hearings — Judiciary, Commerce, Armed Services, Veterans' Affairs
4. C-SPAN (c-span.org) — floor speeches, hearing clips
Also check news outlets: CT Mirror, Hartford Courant, The Hill, CNN, AP, Reuters
SRC,
        'source_types' => 'Press Release | Twitter/X | Committee Hearing | Floor Speech | Interview | Press Conference',
        'search_instruction' => 'Check blumenthal.senate.gov press releases, Twitter/X, committee hearings, floor speeches, and CT media coverage.',
        'extra_rules' => '',
    ],
    441 => [
        'name' => 'U.S. Senator Christopher Murphy (D-CT)',
        'short_name' => 'Murphy',
        'title' => 'U.S. Senator',
        'sources_text' => <<<'SRC'
1. murphy.senate.gov/newsroom/press-releases — official press releases and statements (PRIMARY)
2. Twitter/X @ChrisMurphyCT — very active, lengthy policy threads
3. chrismurphy.substack.com — "Murphy's Law" weekly newsletter
4. Senate committee hearings — Foreign Relations, HELP, Appropriations
5. C-SPAN (c-span.org) — floor speeches, hearing clips
Also check news outlets: CT Mirror, Hartford Courant, The Hill, CNN, NPR, AP, Reuters
SRC,
        'source_types' => 'Press Release | Twitter/X | Newsletter | Committee Hearing | Floor Speech | Interview | Press Conference',
        'search_instruction' => 'Check murphy.senate.gov press releases, Twitter/X, Substack newsletter, committee hearings, floor speeches, and CT/national media coverage.',
        'extra_rules' => '',
    ],
];

// ─── Parse CLI arg ──────────────────────────────────────────────────────
$officialId = intval($argv[1] ?? 326);
if (!isset($officials[$officialId])) {
    echo json_encode(['status' => 'error', 'message' => "Unknown official_id: {$officialId}"]);
    exit(1);
}
$official = $officials[$officialId];

// Check kill switch (global — one switch for all)
$enabled = getSiteSetting($pdo, 'statement_collect_local_enabled', '0');
if ($enabled !== '1') {
    echo json_encode(['status' => 'disabled', 'message' => 'statement_collect_local_enabled is not 1']);
    exit(0);
}

// Adaptive date window (per-official)
$settingKey = "statement_collect_last_success_{$officialId}";
$lastSuccess = getSiteSetting($pdo, $settingKey, '');
if ($lastSuccess) {
    $daysSinceSuccess = (int)((time() - strtotime($lastSuccess)) / 86400);
    $lookbackDays = max(1, $daysSinceSuccess);
} else {
    $lookbackDays = 2;
}
if ($lookbackDays > 7) $lookbackDays = 7;

$today = date('Y-m-d');
$windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));

// Get existing statements for this official (dedup context)
$stmt = $pdo->prepare("
    SELECT id, statement_date, LEFT(content, 120) AS content_preview, source, source_url, policy_topic
    FROM rep_statements WHERE official_id = ? ORDER BY statement_date DESC LIMIT 60
");
$stmt->execute([$officialId]);
$recentStatements = $stmt->fetchAll();

$dedupLines = [];
foreach ($recentStatements as $s) {
    $dedupLines[] = "#{$s['id']} ({$s['statement_date']}) [{$s['source']}] {$s['content_preview']}";
}
$dedupContext = implode("\n", $dedupLines);

// Policy topics
$policyTopics = 'Economy & Jobs, Healthcare, Education, Environment & Climate, Immigration, National Security, Criminal Justice, Housing, Infrastructure, Social Services, Tax Policy, Civil Rights, Technology & Privacy, Foreign Policy, Agriculture, Government Reform';

// Build prompt
$systemPrompt = <<<PROMPT
You are a civic researcher for The People's Branch (TPB).

Your job: Search for NEW public statements by {$official['name']} from {$windowStart} to {$today} that are NOT already in our database.

## What Counts as a Statement
Public words by this official that:
- State a position on policy
- Make a promise or announcement about future action
- Claim results or credit for past actions
- React to events, court rulings, or criticism
- Introduce or co-sponsor legislation
- Attack or praise institutions, individuals, or groups

## Sources to Search (ALL of these, in priority order)
{$official['sources_text']}

## Existing Recent Statements (DO NOT DUPLICATE)
{$dedupContext}

## Deduplication Rules
- Skip if content is substantially the same as an existing statement
- Skip if same source URL already captured
- Multiple statements on different topics from the same day are SEPARATE entries
{$official['extra_rules']}
## Tense Tagging
Each statement gets a tense:
- "future" — promises, intentions, "we're going to...", "I will..."
- "present" — current actions, "I am ordering...", "today we are..."
- "past" — retrospective claims, "we saved...", "last week I..."

## Policy Topic Tagging
Assign exactly ONE topic from: {$policyTopics}
Choose the BEST fit. If unclear, use the most specific match.

## Dual Scoring

### Severity Score (Criminality Scale, 0-1000)
How harmful is this statement? Does it threaten institutions, rights, or democratic norms?
- 0: No harm
- 1-30: Misleading or divisive rhetoric
- 31-70: Attacks on institutions or rule of law
- 71-150: Incitement or threats against individuals/groups
- 151-300: Calls for unconstitutional action
- 301+: Direct incitement to violence or insurrection
Most statements will score 0-70. Reserve high scores for genuinely dangerous rhetoric.

### Benefit Score (Benefit Scale, 0-1000)
How much does this statement signal positive citizen impact?
- 0: No benefit
- 1-30: Minor positive gesture
- 31-70: Meaningful commitment to help a group
- 71-150: Broad positive impact announced
- 151-300: Structural positive change promised or announced
- 301+: Historic level positive commitment
Score based on the CONTENT of what's promised/announced, not whether you believe it will happen.

## Official ID: {$officialId} = {$official['short_name']} ({$official['title']}). Use {$officialId} for all statements.

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
If you find NO new statements, return: {"statements": []}

If you find statements, return:
{
  "statements": [
    {
      "statement_date": "YYYY-MM-DD",
      "content": "Full quote or statement text. Use exact words when possible.",
      "summary": "One-sentence summary of position taken.",
      "source": "{$official['source_types']}",
      "source_url": "https://link-to-source-if-available",
      "policy_topic": "One of the 16 topics",
      "tense": "future | present | past",
      "official_id": {$officialId},
      "severity_score": 0,
      "benefit_score": 0
    }
  ],
  "search_summary": "Brief note on what sources you checked"
}

## Writing Standards
- Content: Use exact quotes when possible. If paraphrasing, note "[paraphrased]"
- Summary: One sentence, factual, no editorializing
- Source: Pick the best category from the list
- Scores: Be calibrated. Most statements are 0-70 on both scales. High scores are rare.
PROMPT;

$userMessage = "Search for public statements by {$official['name']} from {$windowStart} to {$today}. Find new statements NOT in our existing database. {$official['search_instruction']} Return structured JSON.";

// Output everything step 2 needs
echo json_encode([
    'status' => 'ready',
    'official_id' => $officialId,
    'official_name' => $official['short_name'],
    'system_prompt' => $systemPrompt,
    'user_message' => $userMessage,
    'lookback_days' => $lookbackDays,
    'window_start' => $windowStart,
    'today' => $today,
    'statement_count' => count($recentStatements)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
