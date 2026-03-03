<?php
/**
 * Get Representative by Congressional District
 * ==============================================
 * Returns contact info for a House rep given a district code like "CT-05".
 * Used by the impeachment vote table hover popover.
 *
 * GET ?district=CT-05
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$district = $_GET['district'] ?? '';
if (!preg_match('/^[A-Z]{2}-(\d{1,2}|AL)$/', $district)) {
    echo json_encode(['error' => 'Invalid district format']);
    exit;
}

// Parse state and district number from format like "CT-05" or "AK-AL"
$parts = explode('-', $district);
$state = strtolower($parts[0]);
$distNum = $parts[1] === 'AL' ? '0' : ltrim($parts[1], '0');
if ($distNum === '') $distNum = '0';

// At-large districts: some stored as cd:0, some as cd:1 — try both
$ocdPatterns = ["ocd-division/country:us/state:{$state}/cd:{$distNum}"];
if ($distNum === '0') {
    $ocdPatterns[] = "ocd-division/country:us/state:{$state}/cd:1";
}

$placeholders = implode(',', array_fill(0, count($ocdPatterns), '?'));
$stmt = $pdo->prepare("
    SELECT official_id, full_name, party, phone, website, bioguide_id, office_name
    FROM elected_officials
    WHERE title = 'U.S. Representative'
      AND is_current = 1
      AND ocd_id IN ({$placeholders})
    LIMIT 1
");
$stmt->execute($ocdPatterns);
$rep = $stmt->fetch();

if (!$rep) {
    echo json_encode(['found' => false]);
    exit;
}

// Build photo URL from bioguide_id
$photoUrl = $rep['bioguide_id']
    ? "https://bioguide.congress.gov/bioguide/photo/{$rep['bioguide_id'][0]}/{$rep['bioguide_id']}.jpg"
    : null;

echo json_encode([
    'found' => true,
    'name' => $rep['full_name'],
    'party' => $rep['party'],
    'phone' => $rep['phone'] ?: null,
    'website' => $rep['website'] ?: null,
    'photo' => $photoUrl,
    'office' => $rep['office_name'],
    'detail_url' => '/usa/rep.php?id=' . $rep['official_id']
]);
