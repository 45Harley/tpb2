<?php
/**
 * Backfill fec_races.held_by from incumbent candidate party data.
 * Run once after adding the held_by column. Safe to re-run.
 *
 * Usage: ea-php84 scripts/maintenance/backfill-race-held-by.php
 */

$config = require __DIR__ . '/../../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname=sandge5_election;charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$races = $pdo->query("
    SELECT r.race_id, r.state, r.district, r.office,
           c.party
    FROM fec_races r
    LEFT JOIN fec_candidates c ON c.race_id = r.race_id AND c.incumbent_challenge = 'I'
    WHERE r.held_by IS NULL AND r.is_active = 1
    ORDER BY r.state, r.district
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($races)) {
    echo "All races already have held_by set.\n";
    exit(0);
}

$update = $pdo->prepare("UPDATE fec_races SET held_by = ? WHERE race_id = ?");
$updated = 0;
$skipped = 0;

foreach ($races as $race) {
    $partyRaw = strtoupper($race['party'] ?? '');
    $label = $race['state'] . ($race['district'] ? '-' . $race['district'] : '') . ' ' . ($race['office'] === 'S' ? 'Senate' : 'House');

    $heldBy = null;
    if (str_contains($partyRaw, 'DEM')) {
        $heldBy = 'D';
    } elseif (str_contains($partyRaw, 'REP')) {
        $heldBy = 'R';
    }

    if ($heldBy) {
        $update->execute([$heldBy, $race['race_id']]);
        echo "SET $label => $heldBy (party: {$race['party']})\n";
        $updated++;
    } else {
        echo "SKIP $label — no incumbent found (set manually in admin)\n";
        $skipped++;
    }
}

echo "\nDone. Updated: $updated, Skipped (need manual): $skipped\n";
