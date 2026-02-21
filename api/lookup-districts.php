<?php
/**
 * TPB2 Lookup Districts API
 * =========================
 * Calls OpenStates geo API to get legislative districts for lat/lng
 * 
 * POST /api/lookup-districts.php
 * Body: {
 *   "latitude": 41.3083,
 *   "longitude": -72.9279
 * }
 * 
 * Returns: {
 *   "status": "success",
 *   "districts": {
 *     "us_congress_district": "2",
 *     "state_senate_district": "20",
 *     "state_house_district": "42"
 *   },
 *   "legislators": [...] // optional, full legislator data
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$lat = $input['latitude'] ?? null;
$lng = $input['longitude'] ?? null;

if (!$lat || !$lng) {
    echo json_encode(['status' => 'error', 'message' => 'latitude and longitude required']);
    exit();
}

// Validate coordinates
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid coordinates']);
    exit();
}

// OpenStates API config
$apiKey = 'dfbdcccc-5fc7-4630-a2b0-c21d8a310bd0';
$apiBase = 'https://v3.openstates.org';

// Call OpenStates geo endpoint
$url = "$apiBase/people.geo?lat=$lat&lng=$lng";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        "X-API-KEY: $apiKey",
        "Accept: application/json"
    ],
    CURLOPT_USERAGENT => 'TPB-Districts-Lookup/1.0'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    echo json_encode([
        'status' => 'error',
        'message' => 'OpenStates API error',
        'http_code' => $httpCode,
        'error' => $curlError
    ]);
    exit();
}

$data = json_decode($response, true);

if (!$data || !isset($data['results'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid response from OpenStates'
    ]);
    exit();
}

// Parse results to extract districts
$districts = [
    'us_congress_district' => null,
    'state_senate_district' => null,
    'state_house_district' => null
];

$legislators = [];

foreach ($data['results'] as $person) {
    $role = $person['current_role'] ?? null;
    if (!$role) continue;
    
    $divisionId = $role['division_id'] ?? '';
    $orgClass = $role['org_classification'] ?? '';
    $district = $role['district'] ?? '';
    
    // Parse OCD division ID to determine type
    // Federal: ocd-division/country:us/state:ct/cd:2
    // State upper: ocd-division/country:us/state:ct/sldu:20
    // State lower: ocd-division/country:us/state:ct/sldl:42
    
    if (strpos($divisionId, '/cd:') !== false) {
        // Congressional district
        $districts['us_congress_district'] = $district;
    } elseif (strpos($divisionId, '/sldu:') !== false || $orgClass === 'upper') {
        // State Senate
        $districts['state_senate_district'] = $district;
    } elseif (strpos($divisionId, '/sldl:') !== false || $orgClass === 'lower') {
        // State House
        $districts['state_house_district'] = $district;
    }
    
    // Collect legislator info
    $legislators[] = [
        'name' => $person['name'] ?? '',
        'party' => $person['party'] ?? '',
        'title' => $role['title'] ?? '',
        'district' => $district,
        'division_id' => $divisionId,
        'chamber' => $orgClass,
        'image' => $person['image'] ?? null
    ];
}

// Award civic points for district lookup
$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
if ($dbUser) {
    require_once __DIR__ . '/../includes/point-logger.php';
    PointLogger::init($pdo);
    PointLogger::award($dbUser['user_id'], 'district_lookup', 'civic', null, 'reps');
}

echo json_encode([
    'status' => 'success',
    'districts' => $districts,
    'legislators' => $legislators,
    'raw_count' => count($data['results'])
]);
