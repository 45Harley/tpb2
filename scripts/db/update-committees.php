<?php
/**
 * Update committee data from open data sources
 * =============================================
 *
 * Sources:
 *   - Committee definitions: unitedstates/congress-legislators (GitHub YAML)
 *   - Subcommittee names:    Congress.gov API v3 (data.gov key)
 *   - Member assignments:    unitedstates/congress-legislators (GitHub YAML)
 *
 * Usage:
 *   php scripts/db/update-committees.php [--congress=119] [--dry-run]
 *
 * Tables affected:
 *   - committees            (truncated and rebuilt for the given congress)
 *   - committee_memberships (truncated and rebuilt for the given congress)
 *
 * Requirements:
 *   - config.php must have apis.congress_gov.key set
 *   - Internet access to GitHub raw files and api.congress.gov
 *
 * Run frequency: Once at the start of each new Congress (every 2 years, January)
 *   or whenever committee assignments change mid-session.
 */

// Parse CLI args
$opts = getopt('', ['congress:', 'dry-run']);
$congress = (int)($opts['congress'] ?? 119);
$dryRun = isset($opts['dry-run']);

$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiKey = $c['apis']['congress_gov']['key'] ?? null;
if (!$apiKey) {
    die("ERROR: No Congress.gov API key in config.php (apis.congress_gov.key)\n" .
        "Get a free key at: https://api.data.gov/signup/\n");
}

$ctx = stream_context_create(['http' => [
    'timeout' => 30,
    'header' => "User-Agent: TPB-Civic-Platform\r\nAccept: application/json\r\n"
]]);

// ============================================
// YAML Parsers
// ============================================
function parseCommitteesYaml($yaml) {
    $committees = [];
    $current = null;
    $inSubcommittees = false;
    $currentSub = null;

    foreach (explode("\n", $yaml) as $line) {
        if (preg_match('/^- type: (.+)/', $line, $m)) {
            if ($currentSub && $current) $current['subcommittees'][] = $currentSub;
            if ($current) $committees[] = $current;
            $current = ['type' => trim($m[1]), 'subcommittees' => []];
            $inSubcommittees = false;
            $currentSub = null;
        } elseif ($current && preg_match('/^  subcommittees:/', $line)) {
            $inSubcommittees = true;
        } elseif ($inSubcommittees && preg_match('/^  - name: (.+)/', $line, $m)) {
            if ($currentSub) $current['subcommittees'][] = $currentSub;
            $currentSub = ['name' => trim($m[1])];
        } elseif ($inSubcommittees && $currentSub && preg_match('/^    (\w+): (.+)/', $line, $m)) {
            $currentSub[trim($m[1])] = trim($m[2], " '\"");
        } elseif (!$inSubcommittees && $current && preg_match('/^  (\w+): (.+)/', $line, $m)) {
            $current[trim($m[1])] = trim($m[2], " '\"");
        }
    }
    if ($currentSub && $current) $current['subcommittees'][] = $currentSub;
    if ($current) $committees[] = $current;
    return $committees;
}

function parseMembershipYaml($yaml) {
    $memberships = [];
    $currentCode = null;
    $currentMember = null;
    foreach (explode("\n", $yaml) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9]+):$/', $line, $m)) {
            if ($currentCode && $currentMember) $memberships[$currentCode][] = $currentMember;
            $currentCode = $m[1];
            $currentMember = null;
            if (!isset($memberships[$currentCode])) $memberships[$currentCode] = [];
        } elseif (preg_match('/^- name: (.+)/', $line, $m)) {
            if ($currentCode && $currentMember) $memberships[$currentCode][] = $currentMember;
            $currentMember = ['name' => trim($m[1])];
        } elseif ($currentMember && preg_match('/^  (\w+): (.+)/', $line, $m)) {
            $currentMember[trim($m[1])] = trim($m[2], " '\"");
        }
    }
    if ($currentCode && $currentMember) $memberships[$currentCode][] = $currentMember;
    return $memberships;
}

function apiGet($url, $apiKey, $ctx) {
    $sep = strpos($url, '?') !== false ? '&' : '?';
    $full = $url . $sep . "api_key=$apiKey&format=json";
    $resp = @file_get_contents($full, false, $ctx);
    if ($resp === false) return null;
    return json_decode($resp, true);
}

// ============================================
// STEP 1: Download YAML data
// ============================================
echo "=== STEP 1: Download data ===" . PHP_EOL;
$ghBase = 'https://raw.githubusercontent.com/unitedstates/congress-legislators/main/';

echo "  Downloading committees-current.yaml..." . PHP_EOL;
$committeesYaml = @file_get_contents($ghBase . 'committees-current.yaml', false, $ctx);
if (!$committeesYaml) die("Failed to download committees YAML\n");
echo "    " . strlen($committeesYaml) . " bytes" . PHP_EOL;

echo "  Downloading committee-membership-current.yaml..." . PHP_EOL;
$membershipYaml = @file_get_contents($ghBase . 'committee-membership-current.yaml', false, $ctx);
if (!$membershipYaml) die("Failed to download membership YAML\n");
echo "    " . strlen($membershipYaml) . " bytes" . PHP_EOL;

// ============================================
// STEP 2: Parse data
// ============================================
echo PHP_EOL . "=== STEP 2: Parse YAML ===" . PHP_EOL;
$committees = parseCommitteesYaml($committeesYaml);
$memberships = parseMembershipYaml($membershipYaml);
echo "  Committees: " . count($committees) . PHP_EOL;
echo "  Membership groups: " . count($memberships) . PHP_EOL;

if ($dryRun) {
    echo PHP_EOL . "DRY RUN — no database changes made." . PHP_EOL;
    exit(0);
}

// ============================================
// STEP 3: Import committees
// ============================================
echo PHP_EOL . "=== STEP 3: Import committees (congress=$congress) ===" . PHP_EOL;
$pdo->exec("DELETE FROM committee_memberships WHERE congress = $congress");
$pdo->exec("DELETE FROM committees WHERE congress = $congress");
echo "  Cleared existing data for congress $congress" . PHP_EOL;

$chamberMap = ['house' => 'House', 'senate' => 'Senate', 'joint' => 'Joint'];
$insertCommittee = $pdo->prepare("INSERT INTO committees (system_code, name, chamber, committee_type, parent_id, congress) VALUES (?,?,?,?,?,?)");

$parentCount = 0;
$subCount = 0;

foreach ($committees as $cm) {
    $chamber = $chamberMap[$cm['type']] ?? 'Joint';
    $code = $cm['thomas_id'] ?? '';
    $name = $cm['name'] ?? '';
    if (!$code || !$name) continue;

    $insertCommittee->execute([$code, $name, $chamber, 'Standing', null, $congress]);
    $parentId = $pdo->lastInsertId();
    $parentCount++;

    foreach ($cm['subcommittees'] ?? [] as $sub) {
        $subCode = $code . ($sub['thomas_id'] ?? '');
        $subName = $sub['name'] ?? '';
        if (!$subName || !$subCode) continue;
        $insertCommittee->execute([$subCode, $subName, $chamber, 'Subcommittee', $parentId, $congress]);
        $subCount++;
    }
}
echo "  Inserted: $parentCount parent + $subCount sub = " . ($parentCount + $subCount) . " committees" . PHP_EOL;

// ============================================
// STEP 4: Find unmatched subcommittees in membership data, create them via API
// ============================================
echo PHP_EOL . "=== STEP 4: Resolve unmatched subcommittees ===" . PHP_EOL;
$committeeLookup = [];
$r = $pdo->query("SELECT committee_id, system_code FROM committees WHERE congress = $congress");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $committeeLookup[$row['system_code']] = $row['committee_id'];

$parentLookup = [];
$r = $pdo->query("SELECT committee_id, system_code, chamber FROM committees WHERE congress = $congress AND parent_id IS NULL");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $parentLookup[$row['system_code']] = $row;

$unmatchedCodes = array_diff(array_keys($memberships), array_keys($committeeLookup));
echo "  Unmatched codes: " . count($unmatchedCodes) . PHP_EOL;

$insertSub = $pdo->prepare("INSERT INTO committees (system_code, name, chamber, committee_type, parent_id, congress) VALUES (?,?,?,'Subcommittee',?,?)");
$apiCalls = 0;

foreach ($unmatchedCodes as $code) {
    $parentCode = substr($code, 0, 4);
    $parent = $parentLookup[$parentCode] ?? null;
    $chamber = 'Senate';
    if (substr($code, 0, 1) === 'H') $chamber = 'House';
    if (substr($code, 0, 1) === 'J') $chamber = 'Joint';

    // Try Congress.gov API for the name
    $name = "Subcommittee $code";
    $apiCode = strtolower($code);
    $chamberPath = strtolower($chamber);
    $data = apiGet("https://api.congress.gov/v3/committee/$chamberPath/$apiCode", $apiKey, $ctx);
    $apiCalls++;

    if ($data && isset($data['committee']['history'])) {
        foreach ($data['committee']['history'] as $h) {
            if (isset($h['officialName'])) { $name = $h['officialName']; break; }
            if (isset($h['libraryOfCongressName'])) { $name = $h['libraryOfCongressName']; break; }
        }
    }

    $insertSub->execute([$code, $name, $chamber, $parent ? $parent['committee_id'] : null, $congress]);
    $committeeLookup[$code] = $pdo->lastInsertId();
    usleep(150000); // API throttle
}
echo "  Added " . count($unmatchedCodes) . " subcommittees ($apiCalls API calls)" . PHP_EOL;

// ============================================
// STEP 5: Import memberships
// ============================================
echo PHP_EOL . "=== STEP 5: Import memberships ===" . PHP_EOL;

$officialLookup = [];
$r = $pdo->query("SELECT official_id, bioguide_id FROM elected_officials WHERE bioguide_id IS NOT NULL AND is_current = 1");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $officialLookup[$row['bioguide_id']] = $row['official_id'];

$insertMembership = $pdo->prepare("INSERT INTO committee_memberships (official_id, committee_id, role, congress)
    VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)");

$total = 0;
$skippedCommittee = 0;
$skippedMember = 0;

foreach ($memberships as $code => $members) {
    $committeeId = $committeeLookup[$code] ?? null;
    if (!$committeeId) { $skippedCommittee += count($members); continue; }

    foreach ($members as $member) {
        $bioguide = $member['bioguide'] ?? null;
        if (!$bioguide) continue;
        $officialId = $officialLookup[$bioguide] ?? null;
        if (!$officialId) { $skippedMember++; continue; }

        $role = $member['title'] ?? 'Member';
        $insertMembership->execute([$officialId, $committeeId, $role, $congress]);
        $total++;
    }
}

echo "  Inserted: $total assignments" . PHP_EOL;
if ($skippedCommittee) echo "  Skipped (no committee match): $skippedCommittee" . PHP_EOL;
if ($skippedMember) echo "  Skipped (member not in DB): $skippedMember" . PHP_EOL;

// ============================================
// SUMMARY
// ============================================
$totalCommittees = $pdo->query("SELECT COUNT(*) FROM committees WHERE congress = $congress")->fetchColumn();
$parentCommittees = $pdo->query("SELECT COUNT(*) FROM committees WHERE congress = $congress AND parent_id IS NULL")->fetchColumn();
$totalMemberships = $pdo->query("SELECT COUNT(*) FROM committee_memberships WHERE congress = $congress")->fetchColumn();
$uniqueMembers = $pdo->query("SELECT COUNT(DISTINCT official_id) FROM committee_memberships WHERE congress = $congress")->fetchColumn();
$totalFederal = $pdo->query("SELECT COUNT(*) FROM elected_officials WHERE title IN ('U.S. Senator','U.S. Representative') AND is_current = 1")->fetchColumn();

echo PHP_EOL . "=== SUMMARY ===" . PHP_EOL;
echo "Congress: $congress" . PHP_EOL;
echo "Committees: $totalCommittees ($parentCommittees parent + " . ($totalCommittees - $parentCommittees) . " sub)" . PHP_EOL;
echo "Memberships: $totalMemberships" . PHP_EOL;
echo "Members covered: $uniqueMembers / $totalFederal federal officials" . PHP_EOL;
echo "API calls used: $apiCalls" . PHP_EOL;
echo "Done." . PHP_EOL;
