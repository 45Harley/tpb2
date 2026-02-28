<?php
/**
 * FEC Data Sync — Nightly Cron
 *
 * Pulls candidate and contributor data from OpenFEC API
 * into local cache tables for the race dashboard.
 *
 * Cron: every 6 hours (0 0,6,12,18 * * *) — cPanel clock is EST.
 * With DEMO_KEY: syncs 1 race per run (least recently synced first).
 * With real key (1000 req/hr): syncs all races in one run.
 *
 * Requirements:
 *   - site_settings.fec_sync_enabled = '1'
 *   - config.php has 'fec_api_key' (falls back to DEMO_KEY)
 *   - fec_races table has at least one active race
 *
 * Usage:
 *   cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/sync-fec-data.php
 */

$startTime = microtime(true);

// Bootstrap
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/site-settings.php';

// TPB2 DB for site_settings check
$pdoTpb = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Check kill switch
if (getSiteSetting($pdoTpb, 'fec_sync_enabled', '0') !== '1') {
    echo "FEC sync is disabled. Exiting.\n";
    exit(0);
}

// Election DB for FEC tables
$pdo = new PDO(
    "mysql:host={$config['host']};dbname=sandge5_election;charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$apiKey = $config['fec_api_key'] ?? 'DEMO_KEY';
$apiBase = 'https://api.open.fec.gov/v1';

// Get active races — rotate: pick the one least recently synced
// With DEMO_KEY (30 req/hr), we can only handle ~1 race per run.
// With a real key (1000 req/hr), set MAX_RACES_PER_RUN higher.
$maxRaces = ($apiKey !== 'DEMO_KEY') ? 999 : 1;

$races = $pdo->query("
    SELECT r.race_id, r.cycle, r.office, r.state, r.district
    FROM fec_races r
    LEFT JOIN (
        SELECT race_id, MAX(last_synced_at) as last_sync
        FROM fec_candidates GROUP BY race_id
    ) c ON r.race_id = c.race_id
    WHERE r.is_active = 1
    ORDER BY c.last_sync ASC, r.race_id ASC
    LIMIT {$maxRaces}
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($races)) {
    echo "No active races to sync. Exiting.\n";
    exit(0);
}

// Prepared statements
$upsertCandidate = $pdo->prepare("
    INSERT INTO fec_candidates (fec_candidate_id, race_id, committee_id, name, party, incumbent_challenge,
                                total_receipts, total_disbursements, cash_on_hand, last_filing_date, last_synced_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        race_id = VALUES(race_id),
        committee_id = VALUES(committee_id),
        name = VALUES(name),
        party = VALUES(party),
        incumbent_challenge = VALUES(incumbent_challenge),
        total_receipts = VALUES(total_receipts),
        total_disbursements = VALUES(total_disbursements),
        cash_on_hand = VALUES(cash_on_hand),
        last_filing_date = VALUES(last_filing_date),
        last_synced_at = NOW()
");

$deleteContributors = $pdo->prepare("DELETE FROM fec_top_contributors WHERE fec_candidate_id = ?");
$insertContributor = $pdo->prepare("
    INSERT INTO fec_top_contributors (fec_candidate_id, contributor_name, contributor_type, total_amount, employer, last_synced_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

$totalCandidates = 0;
$totalContributors = 0;
$errors = 0;

foreach ($races as $race) {
    $officeParam = $race['office'] === 'H' ? 'house' : 'senate';
    $params = [
        'api_key' => $apiKey,
        'cycle' => $race['cycle'],
        'office' => $officeParam,
        'state' => $race['state'],
        'per_page' => 20,
    ];
    if ($race['office'] === 'H' && $race['district']) {
        $params['district'] = $race['district'];
    }

    // Fetch candidates for this race
    $url = $apiBase . '/elections/?' . http_build_query($params);
    $response = @file_get_contents($url);
    usleep(500000); // 500ms throttle

    if ($response === false) {
        error_log("FEC sync: failed to fetch {$race['state']}-{$race['district']} ({$race['office']})");
        $errors++;
        continue;
    }

    $data = json_decode($response, true);
    $results = $data['results'] ?? [];

    foreach ($results as $cand) {
        $candId = $cand['candidate_id'] ?? null;
        if (!$candId) continue;

        $upsertCandidate->execute([
            $candId,
            $race['race_id'],
            $cand['candidate_pcc_id'] ?? null,
            $cand['candidate_name'] ?? 'Unknown',
            $cand['party_full'] ?? $cand['party'] ?? null,
            isset($cand['incumbent_challenge_full']) ? substr($cand['incumbent_challenge_full'], 0, 1) : null,
            $cand['total_receipts'] ?? 0,
            $cand['total_disbursements'] ?? 0,
            $cand['cash_on_hand_end_period'] ?? 0,
            $cand['coverage_end_date'] ?? null,
        ]);
        $totalCandidates++;

        // Fetch top contributors if we have a committee ID
        $committeeId = $cand['candidate_pcc_id'] ?? null;
        if ($committeeId) {
            $contribUrl = $apiBase . '/schedules/schedule_a/?' . http_build_query([
                'api_key' => $apiKey,
                'committee_id' => $committeeId,
                'two_year_transaction_period' => $race['cycle'],
                'sort' => '-contribution_receipt_amount',
                'per_page' => 10,
                'is_individual' => 'true',
            ]);
            $contribResponse = @file_get_contents($contribUrl);
            usleep(500000); // 500ms throttle

            if ($contribResponse !== false) {
                $contribData = json_decode($contribResponse, true);
                $contributions = $contribData['results'] ?? [];

                // Replace old contributors
                $deleteContributors->execute([$candId]);

                foreach ($contributions as $contrib) {
                    $insertContributor->execute([
                        $candId,
                        $contrib['contributor_name'] ?? 'Unknown',
                        ($contrib['entity_type'] ?? '') === 'IND' ? 'individual' : 'pac',
                        $contrib['contribution_receipt_amount'] ?? 0,
                        $contrib['contributor_employer'] ?? null,
                    ]);
                    $totalContributors++;
                }
            }
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "FEC sync complete. Races: " . count($races) . ", Candidates: {$totalCandidates}, Contributors: {$totalContributors}, Errors: {$errors}, Time: {$elapsed}s\n";
