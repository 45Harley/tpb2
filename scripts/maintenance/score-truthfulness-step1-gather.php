<?php
/**
 * Truthfulness Scoring Pipeline — Step 1: Gather Context
 * =======================================================
 * Runs on SERVER via SSH. Outputs JSON with:
 *   - All unclustered statements (need clustering)
 *   - All existing clusters (need re-scoring)
 *   - Related actions/threats for evidence
 */

$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Kill switch
$enabled = getSiteSetting($pdo, 'truthfulness_score_local_enabled', '0');
if ($enabled !== '1') {
    echo json_encode(['status' => 'disabled', 'message' => 'truthfulness_score_local_enabled is not 1']);
    exit(0);
}

$today = date('Y-m-d');

// --- Unclustered statements ---
$unclustered = $pdo->query("
    SELECT id, content, summary, policy_topic, tense, source, statement_date,
           severity_score, benefit_score
    FROM rep_statements
    WHERE cluster_id IS NULL
    ORDER BY statement_date DESC
")->fetchAll();

// --- Existing clusters with their statements ---
$clusters = $pdo->query("
    SELECT sc.id, sc.canonical_claim, sc.policy_topic, sc.repeat_count,
           sc.truthfulness_score AS prev_score, sc.first_seen, sc.last_seen
    FROM statement_clusters sc
    ORDER BY sc.id
")->fetchAll();

$clusterStatements = [];
if (!empty($clusters)) {
    $clusterIds = array_column($clusters, 'id');
    $placeholders = implode(',', array_fill(0, count($clusterIds), '?'));
    $stmt = $pdo->prepare("
        SELECT id, cluster_id, content, summary, tense, statement_date
        FROM rep_statements
        WHERE cluster_id IN ($placeholders)
        ORDER BY cluster_id, statement_date
    ");
    $stmt->execute($clusterIds);
    while ($row = $stmt->fetch()) {
        $clusterStatements[$row['cluster_id']][] = $row;
    }
}

// --- Recent actions/threats for evidence ---
$threats = $pdo->query("
    SELECT threat_id, title, description, threat_date, target, branch,
           severity_score, benefit_score
    FROM executive_threats
    WHERE is_active = 1
    ORDER BY threat_date DESC
    LIMIT 80
")->fetchAll();

// --- Build cluster context for prompt ---
$clusterContext = [];
foreach ($clusters as $c) {
    $stmts = $clusterStatements[$c['id']] ?? [];
    $stmtSummaries = [];
    foreach ($stmts as $s) {
        $stmtSummaries[] = "[{$s['statement_date']}] {$s['summary']}";
    }
    $clusterContext[] = [
        'cluster_id' => (int)$c['id'],
        'canonical_claim' => $c['canonical_claim'],
        'policy_topic' => $c['policy_topic'],
        'repeat_count' => (int)$c['repeat_count'],
        'prev_score' => $c['prev_score'] !== null ? (int)$c['prev_score'] : null,
        'date_range' => $c['first_seen'] . ' to ' . $c['last_seen'],
        'statements' => $stmtSummaries,
    ];
}

// --- Build threat context ---
$threatContext = [];
foreach ($threats as $t) {
    $threatContext[] = "#{$t['threat_id']} ({$t['threat_date']}) [{$t['branch']}] {$t['title']} — {$t['target']}. Sev:{$t['severity_score']} Ben:{$t['benefit_score']}";
}

// --- Build unclustered context ---
$unclusteredContext = [];
foreach ($unclustered as $u) {
    $unclusteredContext[] = [
        'statement_id' => (int)$u['id'],
        'content' => $u['content'],
        'summary' => $u['summary'],
        'policy_topic' => $u['policy_topic'],
        'tense' => $u['tense'],
        'date' => $u['statement_date'],
    ];
}

// --- System prompt ---
$systemPrompt = <<<PROMPT
You are a truthfulness analyst for The People's Branch (TPB).

You have TWO jobs:

## Job 1: Cluster unclustered statements
Each unclustered statement should be assigned to an existing cluster OR create a new cluster.

Statements belong in the same cluster if they make the SAME core claim, promise, or assertion — even if worded differently or said at different events.

Examples of same cluster:
- "No tax on overtime" at a rally + "Your overtime pay is 100% tax-free" on Truth Social → same claim
- "We destroyed Kharg Island" in a press conference + "We obliterated their oil facility" on Truth Social → same claim

Examples of DIFFERENT clusters:
- "Economy is great" vs "No tax on overtime" — related topic but different claims
- "Iran is defeated" vs "We bombed Kharg Island" — related but one is a claim, one is a specific action

## Job 2: Score truthfulness for ALL clusters
For every cluster (existing + newly created), evaluate truthfulness against the evidence.

### Truthfulness Scale (0-1000)
- 0-100: **False** — claim directly contradicted by evidence, verifiably wrong
- 101-200: **Mostly False** — contains a kernel of truth but fundamentally misleading
- 201-300: **Misleading** — cherry-picked, out of context, exaggerated
- 301-400: **Half True** — partially accurate but missing key context
- 401-500: **Mixed** — some elements true, some false
- 501-600: **Mostly True** — substantially accurate with minor issues
- 601-700: **True** — accurate, supported by evidence
- 701-800: **Very True** — precise and well-documented
- 801-900: **Verified** — confirmed by multiple independent sources
- 901-1000: **Precisely True** — exact, sourced, no spin whatsoever

### Scoring rules:
- Score based on EVIDENCE AVAILABLE NOW — if a future promise hasn't been tested yet, score based on track record and feasibility (400-600 range typically)
- A "past" tense claim can often be fact-checked — score it firmly
- A "present" tense claim about ongoing action — check against threat/action evidence
- A "future" promise with no evidence yet — score 400-600 (uncertain) unless there's evidence for/against
- Be specific in your note about WHY you scored this way

## Existing Clusters to Re-Score
{CLUSTERS}

## Unclustered Statements to Assign
{UNCLUSTERED}

## Evidence: Recent Government Actions/Threats
{THREATS}

## Output Format
Return ONLY valid JSON:
{
  "cluster_assignments": [
    {
      "statement_id": 123,
      "cluster_id": 5,
      "cluster_id_is_new": false
    },
    {
      "statement_id": 456,
      "cluster_id": null,
      "cluster_id_is_new": true,
      "new_cluster": {
        "canonical_claim": "One-sentence summary of the core claim",
        "policy_topic": "Economy & Jobs"
      }
    }
  ],
  "truthfulness_scores": [
    {
      "cluster_id": 5,
      "cluster_id_is_new": false,
      "score": 350,
      "note": "2-3 sentence explanation of why this score, citing specific evidence",
      "evidence_refs": "threat #123, #456"
    },
    {
      "cluster_id": null,
      "cluster_id_is_new": true,
      "canonical_claim": "No tax on overtime",
      "score": 500,
      "note": "Promise made but no legislation introduced yet. Score reflects uncertainty.",
      "evidence_refs": ""
    }
  ]
}

For new clusters, use cluster_id: null and cluster_id_is_new: true. Include the canonical_claim so we can match them in the assignment and score sections.
PROMPT;

// Replace placeholders
$clusterJson = !empty($clusterContext) ? json_encode($clusterContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'None yet — all clusters will be new.';
$unclusteredJson = !empty($unclusteredContext) ? json_encode($unclusteredContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : 'None — all statements are already clustered.';
$threatText = !empty($threatContext) ? implode("\n", $threatContext) : 'No tracked actions/threats yet.';

$systemPrompt = str_replace('{CLUSTERS}', $clusterJson, $systemPrompt);
$systemPrompt = str_replace('{UNCLUSTERED}', $unclusteredJson, $systemPrompt);
$systemPrompt = str_replace('{THREATS}', $threatText, $systemPrompt);

$userMessage = "Analyze all statements. Cluster any unclustered ones. Then score truthfulness for every cluster based on current evidence. Return structured JSON.";

echo json_encode([
    'status' => 'ready',
    'system_prompt' => $systemPrompt,
    'user_message' => $userMessage,
    'today' => $today,
    'unclustered_count' => count($unclustered),
    'cluster_count' => count($clusters),
    'threat_count' => count($threats),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
