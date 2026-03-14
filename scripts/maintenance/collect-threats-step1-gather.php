<?php
/**
 * Step 1: Gather context from DB for threat collection prompt.
 * Runs on the SERVER via SSH. Outputs a JSON file with the prompt + context.
 *
 * Usage: php collect-threats-step1-gather.php
 * Output: /tmp/threat-prompt.json (on server)
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

// Check kill switch
$enabled = getSiteSetting($pdo, 'threat_collect_local_enabled', '0');
if ($enabled !== '1') {
    echo json_encode(['status' => 'disabled', 'message' => 'threat_collect_local_enabled is not 1']);
    exit(0);
}

// Adaptive date window
$lastSuccess = getSiteSetting($pdo, 'threat_collect_last_success', '');
if ($lastSuccess) {
    $daysSinceSuccess = (int)((time() - strtotime($lastSuccess)) / 86400);
    $lookbackDays = max(1, $daysSinceSuccess);
} else {
    $lookbackDays = 2;
}
if ($lookbackDays > 7) $lookbackDays = 7;

$today = date('Y-m-d');
$windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));

// Get existing threats for dedup
$recentThreats = $pdo->query("
    SELECT threat_id, threat_date, title, target, official_id, branch, severity_score
    FROM executive_threats ORDER BY threat_date DESC LIMIT 60
")->fetchAll();

$dedupLines = [];
foreach ($recentThreats as $t) {
    $dedupLines[] = "#{$t['threat_id']} ({$t['threat_date']}) [{$t['branch']}] {$t['title']} — target: {$t['target']}";
}
$dedupContext = implode("\n", $dedupLines);

// Get tags
$tags = $pdo->query("SELECT tag_id, tag_name, tag_label FROM threat_tags ORDER BY tag_id")->fetchAll();
$tagList = [];
foreach ($tags as $tag) {
    $tagList[] = "{$tag['tag_id']}: {$tag['tag_name']} ({$tag['tag_label']})";
}
$tagContext = implode("\n", $tagList);

// Officials
$officialContext = <<<'OFF'
Key official IDs (use these in official_id field):
Executive: 326=Trump, 9112=Vance, 3000=Noem(DHS), 9390=Bondi(AG), 9393=S.Miller(Policy), 9395=Musk(DOGE), 9397=Zeldin(EPA), 9398=K.Patel(FBI), 9399=Vought(OMB), 9401=Lutnick(Commerce), 9402=Hegseth(DoD), 9403=McMahon(Education), 9405=RFK Jr(HHS), 9408=Rubio(State), 9410=Bessent(Treasury)
Judicial: 328=Thomas, 329=Alito, 333=Kavanaugh, 349=Roberts
Congressional: Look up by name if needed. Use 326 (Trump) as fallback for executive actions where the specific official is unclear.
OFF;

// Build prompt
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

## Benefit Scoring (0-1000 Benefit Scale, Geometric)
Some actions have positive aspects even if harmful overall. Score the benefit independently:
- 0: No measurable citizen benefit
- 1-10: Minor Positive (small symbolic gesture)
- 11-30: Helpful (tangible benefit, limited scope)
- 31-70: Significant (meaningful improvement for a group)
- 71-150: Major Benefit (broad impact, lasting improvement)
- 151-300: Transformative (structural positive change)
- 301-500: Historic (generational-level benefit)
- 501-700: Landmark (reshapes institutions for the better)
- 701-900: Epochal (massive, irreversible positive change)
- 901-1000: Civilizational (existential-level improvement)
Most threats will have benefit_score of 0. But some actions are genuinely dual-natured — score both honestly.

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
      "benefit_score": 0,
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

$userMessage = "Search the news for threats to constitutional order from {$windowStart} to {$today}. Find new developments NOT in our existing database. Check AP, Reuters, NPR, PBS, major outlets. Return structured JSON.";

// Output everything step 2 needs
echo json_encode([
    'status' => 'ready',
    'system_prompt' => $systemPrompt,
    'user_message' => $userMessage,
    'lookback_days' => $lookbackDays,
    'window_start' => $windowStart,
    'today' => $today,
    'threat_count' => count($recentThreats),
    'tag_count' => count($tags)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
