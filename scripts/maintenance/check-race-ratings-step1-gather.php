<?php
/**
 * Step 1: Gather active races from DB for race rating check prompt.
 * Runs on the SERVER via SSH. Outputs JSON with the prompt + context.
 *
 * Usage: php check-race-ratings-step1-gather.php
 */

$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

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

// Check kill switch
$enabled = getSiteSetting($pdo, 'rating_check_local_enabled', '0');
if ($enabled !== '1') {
    echo json_encode(['status' => 'disabled', 'message' => 'rating_check_local_enabled is not 1']);
    exit(0);
}

// Load active races
$races = $pdoE->query("
    SELECT race_id, state, district, office, rating, held_by
    FROM fec_races WHERE is_active = 1
    ORDER BY state, office, district
")->fetchAll();

$raceCount = count($races);
if ($raceCount === 0) {
    echo json_encode(['status' => 'no_races', 'message' => 'No active races found']);
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

$systemPrompt = <<<PROMPT
You check competitive race ratings for U.S. midterm elections.

Search for the CURRENT ratings from Cook Political Report, Sabato's Crystal Ball, or Inside Elections (in that priority order).

Valid ratings (least to most competitive):
- Solid R / Solid D (safe seat)
- Likely R / Likely D
- Lean R / Lean D
- Toss-Up

Return ONLY valid JSON. No markdown, no code blocks, no explanation outside the JSON.

If NO ratings changed, return: {"changes": []}

If ratings changed, return:
{
  "changes": [
    {
      "race_id": 25,
      "race_label": "TX Senate",
      "old_rating": "Likely R",
      "new_rating": "Lean R",
      "source": "Cook Political Report",
      "source_date": "2026-01-15",
      "notes": "Shifted after Paxton won primary"
    }
  ],
  "search_summary": "Brief note on sources checked"
}

Rules:
- Only include races where the rating ACTUALLY CHANGED
- Use EXACT rating strings: "Solid R", "Likely R", "Lean R", "Toss-Up", "Lean D", "Likely D", "Solid D"
- Include the source (which outlet reported the change)
- Include source_date as YYYY-MM-DD
- Search for the LATEST ratings — they may have changed recently
- If you can't find a current rating for a race, skip it (don't guess)
PROMPT;

$userMessage = "Here are the races I'm tracking with their current ratings:\n\n{$raceContext}\n\nSearch for the most recent ratings from Cook Political Report, Sabato's Crystal Ball, and Inside Elections. Return JSON with ONLY the races where the rating has CHANGED.";

echo json_encode([
    'status' => 'ready',
    'system_prompt' => $systemPrompt,
    'user_message' => $userMessage,
    'race_count' => $raceCount
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
