<?php
/**
 * Load federal judge data from CourtListener API
 * ================================================
 *
 * Source: CourtListener REST API v4 (Free Law Project)
 *         https://www.courtlistener.com/help/api/rest/
 *
 * Usage:
 *   php scripts/db/load-judicial-data.php [--step=all] [--dry-run]
 *
 * Steps: scotus, circuits, districts, all
 *
 * Tables: elected_officials (updated), circuit_states (seeded by SQL)
 *
 * Requirements:
 *   - config.php must have apis.courtlistener.token
 *   - Schema from scripts/db/create-judicial-tables.sql must be applied
 */

set_time_limit(0);
$opts = getopt('', ['step:', 'dry-run']);
$step = $opts['step'] ?? 'all';
$dryRun = isset($opts['dry-run']);

$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$token = $c['apis']['courtlistener']['token'] ?? null;
if (!$token) die("ERROR: No CourtListener API token in config.php\n");
$baseUrl = $c['apis']['courtlistener']['base_url'] ?? 'https://www.courtlistener.com/api/rest/v4';

// ─── Header ───
echo "\n";
echo "╔══════════════════════════════════════════════╗\n";
echo "║   Load Federal Judge Data — CourtListener    ║\n";
echo "╚══════════════════════════════════════════════╝\n";
echo "  Step:     $step\n";
echo "  Dry run:  " . ($dryRun ? 'YES (no DB writes)' : 'no') . "\n\n";

// ─── API helpers ───

function clGet(string $url, string $token): ?array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Token ' . $token,
        'User-Agent: TPB-Civic-Platform',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        echo "    [WARN] API request failed (HTTP $code): $url\n";
        if ($err) echo "    [WARN] curl error: $err\n";
        if ($resp && $code >= 400) echo "    [WARN] " . substr($resp, 0, 200) . "\n";
        return null;
    }
    return json_decode($resp, true);
}

// Judicial position types we care about (active federal judges)
function isJudicialPosition(string $posType): bool {
    static $types = [
        'jud', 'jus', 'c-jud', 'c-jus', 'ass-jus', 'ass-jud', 'ass-c-jud',
        'act-jud', 'act-jus', 'pres-jud', 'pres-jus',
        'ret-senior-jud', 'ret-c-jud', 'ret-act-jus', 'ret-ass-jud', 'ret-jus',
    ];
    return in_array($posType, $types);
}

function clGetAll(string $url, string $token, int $maxPages = 200): array {
    $all = [];
    $page = 0;
    while ($url && $page < $maxPages) {
        $data = clGet($url, $token);
        if (!$data || !isset($data['results'])) break;
        $all = array_merge($all, $data['results']);
        $url = $data['next'] ?? null;
        $page++;
        echo "    Fetched " . count($all) . " (page $page)\r";
        usleep(750000); // 750ms between requests (stays under 5k/hour)
    }
    echo PHP_EOL;
    return $all;
}

// ─── Upsert helper ───

function upsertJudge(PDO $pdo, array $fields, bool $dryRun): string {
    $clId = $fields['courtlistener_id'];

    // Check if exists by courtlistener_id
    $stmt = $pdo->prepare("SELECT official_id FROM elected_officials WHERE courtlistener_id = ?");
    $stmt->execute([$clId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        if ($dryRun) return 'update';
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if ($k === 'courtlistener_id') continue;
            $sets[] = "$k = ?";
            $params[] = $v;
        }
        $params[] = $clId;
        $pdo->prepare("UPDATE elected_officials SET " . implode(', ', $sets) . " WHERE courtlistener_id = ?")->execute($params);
        return 'update';
    } else {
        if ($dryRun) return 'insert';
        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO elected_officials ($cols) VALUES ($placeholders)")->execute(array_values($fields));
        return 'insert';
    }
}

// ─── Name builder ───

function buildName(array $person): string {
    $parts = [];
    if (!empty($person['name_first'])) $parts[] = $person['name_first'];
    if (!empty($person['name_middle'])) $parts[] = $person['name_middle'];
    if (!empty($person['name_last'])) $parts[] = $person['name_last'];
    $name = implode(' ', $parts);
    if (!empty($person['name_suffix'])) $name .= ' ' . $person['name_suffix'];
    return $name;
}

// ─── Resolve nested resource (person, appointer, court) ───

function resolveResource(?string $url, string $token): ?array {
    if (!$url) return null;
    // CourtListener v4 returns URLs for related objects; fetch them
    return clGet($url . '?format=json', $token);
}

// ─── Court type mapper ───

function mapCourtType(string $courtId, ?string $jurisdiction): ?string {
    if ($courtId === 'scotus') return 'supreme';
    if ($jurisdiction === 'F') return 'circuit';
    if ($jurisdiction === 'FD') return 'district';
    if (in_array($jurisdiction, ['FS', 'FB', 'FBP'])) return 'special';
    return null;
}

// ─── Title builder ───

function buildTitle(string $courtType, bool $isChief, bool $isSenior, string $courtName): string {
    $prefix = $isSenior ? 'Senior ' : '';
    switch ($courtType) {
        case 'supreme':
            return $isChief ? 'Chief Justice of the United States' : 'Associate Justice, Supreme Court';
        case 'circuit':
            $label = $isChief ? 'Chief Judge' : 'Circuit Judge';
            return $prefix . $label;
        case 'district':
            $label = $isChief ? 'Chief Judge' : 'District Judge';
            return $prefix . $label;
        default:
            return $isChief ? 'Chief Judge' : 'Judge';
    }
}

// ─── State code from district court_id ───

function stateFromCourtId(string $courtId): ?string {
    // CourtListener district court IDs: 2-letter state + direction suffix
    // e.g., nysd, cacd, dcd, txnd, flmd, ctd, vtd
    // Some are 3+ chars: nysd (NY), cacd (CA), dcd (DC), paed (PA)
    $map = [
        'dcd' => 'DC', 'gud' => 'GU', 'vid' => 'VI', 'prd' => 'PR', 'nmd' => 'NM',
    ];
    if (isset($map[$courtId])) return $map[$courtId];

    // Most follow pattern: first 2 chars = state abbreviation
    $prefix = strtoupper(substr($courtId, 0, 2));
    // Validate it's a real 2-letter state
    static $validStates = ['AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','PR','GU','VI'];
    if (in_array($prefix, $validStates)) return $prefix;
    return null;
}

// ─── Match existing SCOTUS justices by last name ───

function matchExistingScotus(PDO $pdo): array {
    $existing = [];
    $r = $pdo->query("
        SELECT official_id, full_name FROM elected_officials
        WHERE (office_name IN ('U.S. Supreme Court', 'Supreme Court of the United States')
               OR court_type = 'supreme')
          AND is_current = 1
          AND courtlistener_id IS NULL
    ");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        // Normalize: extract last name (last word before any suffix)
        $parts = explode(' ', $row['full_name']);
        $last = strtolower(end($parts));
        $existing[$last] = (int)$row['official_id'];
    }
    return $existing;
}

// ─── Process a batch of positions into judge records ───

function processPositions(array $positions, string $token, PDO $pdo, bool $dryRun, string $label, array $existingScotus = []): array {
    $counts = ['insert' => 0, 'update' => 0, 'skip' => 0];
    $seen = []; // track courtlistener_id to avoid dupes within batch

    foreach ($positions as $i => $pos) {
        $posType = $pos['position_type'] ?? '';

        // Skip non-judicial positions (clerks, staff attorneys, etc.)
        if ($posType && !isJudicialPosition($posType)) {
            $counts['skip']++;
            continue;
        }

        // Skip terminated positions (no longer active)
        if (!empty($pos['date_termination'])) {
            $counts['skip']++;
            continue;
        }

        // Position data — may be nested or URL
        $person = $pos['person'] ?? null;
        $court = $pos['court'] ?? null;

        // If person/court are URLs, resolve them
        if (is_string($person)) {
            $person = resolveResource($person, $token);
        }
        if (is_string($court)) {
            $court = resolveResource($court, $token);
        }

        if (!$person || !isset($person['id'])) {
            $counts['skip']++;
            continue;
        }

        $clId = (int)$person['id'];
        if (isset($seen[$clId])) {
            // Already processed this person (they may have multiple positions)
            $counts['skip']++;
            continue;
        }
        $seen[$clId] = true;

        $courtId = $court['id'] ?? '';
        $courtName = $court['full_name'] ?? $court['short_name'] ?? $court['name'] ?? '';
        $jurisdiction = $court['jurisdiction'] ?? '';
        $courtType = mapCourtType($courtId, $jurisdiction);

        if (!$courtType) {
            $counts['skip']++;
            continue;
        }

        $isChief = in_array($posType, ['c-jud', 'c-jus']);
        $isSenior = !empty($pos['date_retirement']) && empty($pos['date_termination']);
        $fullName = buildName($person);
        $title = buildTitle($courtType, $isChief, $isSenior, $courtName);

        // Appointer name
        $appointerName = null;
        $appointer = $pos['appointer'] ?? null;
        if (is_string($appointer)) {
            $appointer = resolveResource($appointer, $token);
        }
        if ($appointer) {
            $aPerson = $appointer['person'] ?? null;
            if (is_string($aPerson)) {
                $aPerson = resolveResource($aPerson, $token);
            }
            if ($aPerson) {
                $appointerName = trim(($aPerson['name_first'] ?? '') . ' ' . ($aPerson['name_last'] ?? ''));
            }
        }

        // Photo URL
        $photoUrl = null;
        $clSlug = $person['slug'] ?? null;
        if (!empty($person['has_photo']) && $clSlug) {
            $photoUrl = "https://portraits.free.law/v2/256/$clSlug.jpeg";
        }

        // State code for district judges
        $stateCode = null;
        if ($courtType === 'district') {
            $stateCode = $pos['location_state'] ?? stateFromCourtId($courtId);
        }

        // Check if this is an existing SCOTUS justice we should update by official_id
        $lastName = strtolower($person['name_last'] ?? '');
        if ($courtType === 'supreme' && isset($existingScotus[$lastName])) {
            // Update existing record with CourtListener data
            $existingId = $existingScotus[$lastName];
            if (!$dryRun) {
                $pdo->prepare("
                    UPDATE elected_officials SET
                        courtlistener_id = ?, court_id = ?, court_name = ?, court_type = ?,
                        date_nominated = ?, date_confirmed = ?, votes_yes = ?, votes_no = ?,
                        appointer_name = ?, senior_status = ?, chief_judge = ?,
                        date_of_birth = ?, gender = ?, fjc_id = ?, cl_slug = ?,
                        photo_url = COALESCE(?, photo_url), title = ?, appointment_type = 'appointed'
                    WHERE official_id = ?
                ")->execute([
                    $clId, $courtId, $courtName, $courtType,
                    $pos['date_nominated'] ?? null, $pos['date_confirmation'] ?? null,
                    $pos['votes_yes'] ?? null, $pos['votes_no'] ?? null,
                    $appointerName, $isSenior ? 1 : 0, $isChief ? 1 : 0,
                    $person['date_dob'] ?? null, $person['gender'] ?? null,
                    $person['fjc_id'] ?? null, $clSlug,
                    $photoUrl, $title, $existingId,
                ]);
            }
            $counts['update']++;
            echo "    [$label] UPDATE #$existingId $fullName (matched existing)\n";
            continue;
        }

        // Build fields for upsert
        $fields = [
            'courtlistener_id' => $clId,
            'full_name' => $fullName,
            'title' => $title,
            'org_id' => 1, // United States Federal Government
            'branch_id' => 9, // Judicial (matches existing SCOTUS)
            'appointment_type' => 'appointed',
            'is_current' => 1,
            'court_id' => $courtId,
            'court_name' => $courtName,
            'court_type' => $courtType,
            'office_name' => $courtName,
            'date_nominated' => $pos['date_nominated'] ?? null,
            'date_confirmed' => $pos['date_confirmation'] ?? null,
            'term_start' => $pos['date_start'] ?? null,
            'votes_yes' => $pos['votes_yes'] ?? null,
            'votes_no' => $pos['votes_no'] ?? null,
            'appointer_name' => $appointerName,
            'senior_status' => $isSenior ? 1 : 0,
            'chief_judge' => $isChief ? 1 : 0,
            'date_of_birth' => $person['date_dob'] ?? null,
            'gender' => $person['gender'] ?? null,
            'fjc_id' => $person['fjc_id'] ?? null,
            'cl_slug' => $clSlug,
            'photo_url' => $photoUrl,
            'state_code' => $stateCode,
        ];

        $action = upsertJudge($pdo, $fields, $dryRun);
        $counts[$action]++;

        if (($i + 1) % 25 === 0) {
            echo "    [$label] Processed " . ($i + 1) . " / " . count($positions) . "\n";
        }
    }

    return $counts;
}

// ══════════════════════════════════════════════════
// STEP 1: SCOTUS
// ══════════════════════════════════════════════════
$totals = ['insert' => 0, 'update' => 0, 'skip' => 0];

if ($step === 'all' || $step === 'scotus') {
    echo "── Step 1: Supreme Court ──\n";

    // Match existing justices before loading
    $existingScotus = matchExistingScotus($pdo);
    echo "  Found " . count($existingScotus) . " existing SCOTUS justices to match\n";

    $url = "$baseUrl/positions/?court__id=scotus&format=json";
    echo "  Fetching SCOTUS positions (filtering active in PHP)...\n";
    $positions = clGetAll($url, $token);
    echo "  Got " . count($positions) . " positions\n";

    $counts = processPositions($positions, $token, $pdo, $dryRun, 'SCOTUS', $existingScotus);
    echo "  SCOTUS: {$counts['insert']} inserted, {$counts['update']} updated, {$counts['skip']} skipped\n\n";
    foreach ($counts as $k => $v) $totals[$k] += $v;
}

// ══════════════════════════════════════════════════
// STEP 2: Circuit Courts
// ══════════════════════════════════════════════════
if ($step === 'all' || $step === 'circuits') {
    echo "── Step 2: Circuit Courts ──\n";

    $url = "$baseUrl/positions/?court__jurisdiction=F&format=json";
    echo "  Fetching circuit court positions (filtering active in PHP)...\n";
    $positions = clGetAll($url, $token);

    // Filter out SCOTUS (jurisdiction F includes scotus)
    $positions = array_filter($positions, function($pos) {
        $court = $pos['court'] ?? null;
        if (is_array($court) && ($court['id'] ?? '') === 'scotus') return false;
        return true;
    });
    $positions = array_values($positions);
    echo "  Got " . count($positions) . " circuit positions (excluding SCOTUS)\n";

    $counts = processPositions($positions, $token, $pdo, $dryRun, 'CIRCUIT');
    echo "  Circuits: {$counts['insert']} inserted, {$counts['update']} updated, {$counts['skip']} skipped\n\n";
    foreach ($counts as $k => $v) $totals[$k] += $v;
}

// ══════════════════════════════════════════════════
// STEP 3: District Courts
// ══════════════════════════════════════════════════
if ($step === 'all' || $step === 'districts') {
    echo "── Step 3: District Courts ──\n";

    $url = "$baseUrl/positions/?court__jurisdiction=FD&format=json";
    echo "  Fetching district court positions (filtering active in PHP)...\n";
    $positions = clGetAll($url, $token);
    echo "  Got " . count($positions) . " district positions\n";

    $counts = processPositions($positions, $token, $pdo, $dryRun, 'DISTRICT');
    echo "  Districts: {$counts['insert']} inserted, {$counts['update']} updated, {$counts['skip']} skipped\n\n";
    foreach ($counts as $k => $v) $totals[$k] += $v;
}

// ══════════════════════════════════════════════════
// Summary
// ══════════════════════════════════════════════════
echo "╔══════════════════════════════════════════════╗\n";
echo "║  SUMMARY" . ($dryRun ? ' (DRY RUN)' : '') . str_repeat(' ', $dryRun ? 28 : 37) . "║\n";
echo "╠══════════════════════════════════════════════╣\n";
printf("║  Inserted:  %-32s║\n", number_format($totals['insert']));
printf("║  Updated:   %-32s║\n", number_format($totals['update']));
printf("║  Skipped:   %-32s║\n", number_format($totals['skip']));
echo "╠══════════════════════════════════════════════╣\n";

if (!$dryRun) {
    $r = $pdo->query("SELECT court_type, COUNT(*) as cnt FROM elected_officials WHERE court_type IS NOT NULL GROUP BY court_type ORDER BY FIELD(court_type, 'supreme','circuit','district','special')");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        printf("║  %-10s %-33s║\n", ucfirst($row['court_type']) . ':', number_format($row['cnt']) . ' judges');
    }
    $total = $pdo->query("SELECT COUNT(*) FROM elected_officials WHERE court_type IS NOT NULL")->fetchColumn();
    echo "╠══════════════════════════════════════════════╣\n";
    printf("║  TOTAL:     %-32s║\n", number_format($total) . ' federal judges');
}
echo "╚══════════════════════════════════════════════╝\n\n";
