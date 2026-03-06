<?php
/**
 * Get Delegation — Federal + State officials for a user's location
 * ================================================================
 * Returns senators, house rep, governor, state senator, state rep.
 *
 * GET ?state=ct&district=CT-2
 *     (district is the us_congress_district code)
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stateAbbr = strtolower(trim($_GET['state'] ?? ''));
$district  = trim($_GET['district'] ?? '');

if (!preg_match('/^[a-z]{2}$/', $stateAbbr)) {
    echo json_encode(['error' => 'Invalid state']);
    exit;
}

$results = ['federal' => [], 'state' => []];

// ── Federal: 2 Senators ──
$stateOcd = "ocd-division/country:us/state:{$stateAbbr}";
$stmt = $pdo->prepare("
    SELECT official_id, full_name, title, party, phone, website, bioguide_id, office_name
    FROM elected_officials
    WHERE is_current = 1 AND title = 'U.S. Senator' AND ocd_id = ?
    ORDER BY full_name
");
$stmt->execute([$stateOcd]);
foreach ($stmt->fetchAll() as $row) {
    $results['federal'][] = formatOfficial($row);
}

// ── Federal: House Rep ──
if ($district && preg_match('/^[A-Z]{2}-(\d{1,2}|AL)$/i', $district)) {
    $parts = explode('-', $district);
    $distNum = strtoupper($parts[1]) === 'AL' ? '0' : ltrim($parts[1], '0');
    if ($distNum === '') $distNum = '0';

    $ocdPatterns = ["ocd-division/country:us/state:{$stateAbbr}/cd:{$distNum}"];
    if ($distNum === '0') {
        $ocdPatterns[] = "ocd-division/country:us/state:{$stateAbbr}/cd:1";
    }

    $ph = implode(',', array_fill(0, count($ocdPatterns), '?'));
    $stmt = $pdo->prepare("
        SELECT official_id, full_name, title, party, phone, website, bioguide_id, office_name
        FROM elected_officials
        WHERE is_current = 1 AND title = 'U.S. Representative' AND ocd_id IN ({$ph})
        LIMIT 1
    ");
    $stmt->execute($ocdPatterns);
    $rep = $stmt->fetch();
    if ($rep) {
        $results['federal'][] = formatOfficial($rep);
    }
}

// ── State: Governor ──
$stmt = $pdo->prepare("
    SELECT official_id, full_name, title, party, phone, website, bioguide_id, office_name
    FROM elected_officials
    WHERE is_current = 1 AND title = 'Governor' AND ocd_id = ?
    LIMIT 1
");
$stmt->execute([$stateOcd]);
$gov = $stmt->fetch();
if ($gov) {
    $results['state'][] = formatOfficial($gov);
}

// ── State: State Senator + State Rep (if we know the user's districts) ──
// These use sub-OCD IDs like state:ct/sldu:29 or state:ct/sldl:50
// For now, return all state-level officials tagged to this state
// TODO: narrow by user's state legislative districts when available
$stmt = $pdo->prepare("
    SELECT official_id, full_name, title, party, phone, website, bioguide_id, office_name
    FROM elected_officials
    WHERE is_current = 1 AND title IN ('State Senator', 'State Representative')
      AND ocd_id LIKE ?
    ORDER BY title, full_name
");
$stmt->execute([$stateOcd . '%']);
// Don't return all 150+ state reps — skip for now until we have district mapping

echo json_encode($results, JSON_UNESCAPED_UNICODE);

function formatOfficial($row) {
    $photoUrl = $row['bioguide_id']
        ? "https://bioguide.congress.gov/bioguide/photo/{$row['bioguide_id'][0]}/{$row['bioguide_id']}.jpg"
        : null;
    return [
        'id'      => (int)$row['official_id'],
        'name'    => $row['full_name'],
        'title'   => $row['title'],
        'party'   => $row['party'],
        'phone'   => $row['phone'] ?: null,
        'website' => $row['website'] ?: null,
        'photo'   => $photoUrl,
        'office'  => $row['office_name'],
    ];
}
