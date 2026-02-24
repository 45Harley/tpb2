<?php
/**
 * Migrate executive branch data from election DB (sandge5_election) to TPB2 (sandge5_tpb2).
 *
 * Usage:
 *   php migrate-election-data.php --step=officials|threats|all [--dry-run]
 *
 * Steps:
 *   officials — Insert cabinet officials into elected_officials with reports_to
 *   threats   — Copy trump_threats → executive_threats with remapped official_ids
 *   all       — Both steps in order
 */

$opts = getopt('', ['step:', 'dry-run']);
$step = $opts['step'] ?? 'all';
$dryRun = isset($opts['dry-run']);

if (!in_array($step, ['officials', 'threats', 'all'])) {
    echo "Usage: php migrate-election-data.php --step=officials|threats|all [--dry-run]\n";
    exit(1);
}

// Connect to both databases — resolve config path whether run from project or /tmp
$configPath = file_exists(dirname(__DIR__, 2) . '/config.php')
    ? dirname(__DIR__, 2) . '/config.php'
    : '/home/sandge5/tpb2.sandgems.net/config.php';
$c = require $configPath;
$elec = new PDO('mysql:host='.$c['host'].';dbname=sandge5_election', $c['username'], $c['password']);
$elec->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tpb2 = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$tpb2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($dryRun) echo "=== DRY RUN — no changes will be made ===\n\n";

// ID mapping: election official_id → TPB2 official_id
// These two match between databases:
$idMap = [
    326 => 326,   // Trump
    9112 => 9112, // Vance
];

// Known TPB2 matches by name:
$nameMap = [
    'Kristi Noem' => 3000,
    'Tom Homan' => 9204,
];

// ============================================================
// STEP 1: OFFICIALS
// ============================================================
if ($step === 'officials' || $step === 'all') {
    echo "=== Migrating Cabinet Officials ===\n";

    // Get all cabinet members from election DB (reports_to = 326)
    $r = $elec->query("
        SELECT DISTINCT official_id, full_name, title, office_name, party, state_code,
               email, phone, website, photo_url, appointment_type, term_start, term_end, reports_to
        FROM elected_officials
        WHERE reports_to = 326
        ORDER BY title
    ");
    $cabinet = $r->fetchAll(PDO::FETCH_ASSOC);

    // Also get Trump himself (to set reports_to)
    $r = $elec->query("SELECT * FROM elected_officials WHERE official_id = 326");
    $trump = $r->fetch(PDO::FETCH_ASSOC);

    // Set reports_to on Trump in TPB2 (NULL — he's the top)
    if (!$dryRun) {
        $tpb2->exec("UPDATE elected_officials SET reports_to = NULL WHERE official_id = 326");
    }

    // Set reports_to on Vance in TPB2
    if (!$dryRun) {
        $tpb2->exec("UPDATE elected_officials SET reports_to = 326 WHERE official_id = 9112");
    }
    echo "  Updated Trump (326) reports_to=NULL\n";
    echo "  Updated Vance (9112) reports_to=326\n";

    $inserted = 0;
    $updated = 0;

    foreach ($cabinet as $m) {
        $elecId = $m['official_id'];
        $name = $m['full_name'];

        // Skip Trump and Vance (already mapped)
        if ($elecId == 326 || $elecId == 9112) continue;

        // Check name map first
        if (isset($nameMap[$name])) {
            $tpb2Id = $nameMap[$name];
            $idMap[$elecId] = $tpb2Id;

            // Update existing record with new title/office
            if (!$dryRun) {
                $stmt = $tpb2->prepare("
                    UPDATE elected_officials
                    SET title = ?, office_name = ?, party = ?, appointment_type = ?,
                        email = ?, phone = ?, website = ?, photo_url = ?,
                        term_start = ?, reports_to = 326, is_current = 1
                    WHERE official_id = ?
                ");
                $stmt->execute([
                    $m['title'], $m['office_name'], $m['party'], $m['appointment_type'] ?? 'appointed',
                    $m['email'], $m['phone'], $m['website'], $m['photo_url'],
                    $m['term_start'], $tpb2Id
                ]);
            }
            echo "  Updated: {$name} (TPB2 id={$tpb2Id}) → {$m['title']}\n";
            $updated++;
            continue;
        }

        // Insert new official
        if (!$dryRun) {
            $stmt = $tpb2->prepare("
                INSERT INTO elected_officials
                    (full_name, title, office_name, party, state_code,
                     email, phone, website, photo_url, appointment_type,
                     term_start, term_end, is_current, reports_to)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 326)
            ");
            $stmt->execute([
                $m['full_name'], $m['title'], $m['office_name'], $m['party'], $m['state_code'],
                $m['email'], $m['phone'], $m['website'], $m['photo_url'],
                $m['appointment_type'] ?? 'appointed',
                $m['term_start'], $m['term_end']
            ]);
            $newId = $tpb2->lastInsertId();
        } else {
            $newId = '(new)';
        }

        $idMap[$elecId] = $newId;
        echo "  Inserted: {$name} → {$m['title']} (election id={$elecId} → TPB2 id={$newId})\n";
        $inserted++;
    }

    echo "\nOfficials: {$inserted} inserted, {$updated} updated\n";
    echo "ID mapping (" . count($idMap) . " entries):\n";
    foreach ($idMap as $old => $new) {
        echo "  election {$old} → TPB2 {$new}\n";
    }
    echo "\n";
}

// ============================================================
// STEP 2: THREATS
// ============================================================
if ($step === 'threats' || $step === 'all') {
    echo "=== Migrating Threats ===\n";

    // If running threats-only, we need to rebuild the ID map from TPB2
    if ($step === 'threats') {
        // Rebuild map by matching names between election and TPB2
        $r = $elec->query("SELECT official_id, full_name FROM elected_officials WHERE reports_to = 326 OR official_id = 326");
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            $r2 = $tpb2->prepare("SELECT official_id FROM elected_officials WHERE full_name = ? AND is_current = 1 ORDER BY official_id DESC LIMIT 1");
            $r2->execute([$row['full_name']]);
            $match = $r2->fetch();
            if ($match) {
                $idMap[$row['official_id']] = $match['official_id'];
            }
        }
        echo "  Rebuilt ID map: " . count($idMap) . " mappings\n";
    }

    // Get all threats from election DB
    $r = $elec->query("SELECT * FROM trump_threats ORDER BY threat_id ASC");
    $threats = $r->fetchAll(PDO::FETCH_ASSOC);

    // Check for existing threats in TPB2 (by title + date for dedup)
    $existing = [];
    $r2 = $tpb2->query("SELECT threat_date, title FROM executive_threats");
    while ($row = $r2->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['threat_date'] . '|' . $row['title']] = true;
    }

    $inserted = 0;
    $skipped = 0;
    $unmapped = 0;

    foreach ($threats as $t) {
        // Dedup check
        $key = $t['threat_date'] . '|' . $t['title'];
        if (isset($existing[$key])) {
            $skipped++;
            continue;
        }

        // Remap official_id
        $newOfficialId = null;
        if ($t['official_id'] && isset($idMap[$t['official_id']])) {
            $newOfficialId = $idMap[$t['official_id']];
        } elseif ($t['official_id']) {
            echo "  WARNING: No mapping for election official_id={$t['official_id']} on threat '{$t['title']}'\n";
            $unmapped++;
        }

        if (!$dryRun) {
            $stmt = $tpb2->prepare("
                INSERT INTO executive_threats
                    (threat_date, title, description, threat_type, target,
                     source_url, action_script, official_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $t['threat_date'], $t['title'], $t['description'], $t['threat_type'],
                $t['target'], $t['source_url'], $t['action_script'],
                $newOfficialId, $t['is_active']
            ]);
        }
        $inserted++;
    }

    echo "\nThreats: {$inserted} inserted, {$skipped} skipped (duplicates), {$unmapped} unmapped officials\n";
}

echo "\n=== Migration complete" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";
