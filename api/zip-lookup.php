<?php
/**
 * Zip Code Lookup API Endpoint
 * 
 * Replaces Nominatim API calls with local database queries.
 * 
 * Endpoints:
 *   POST /api/zip-lookup.php
 *     action=lookup_zip   - Look up location by zip code
 *     action=search_towns - Search towns by name (autocomplete)
 *     action=get_coords   - Get coordinates for a town
 * 
 * @package TPB
 * @since 2025-12-22
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Allow CORS for same-origin and development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST or GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'message' => 'Method not allowed'));
    exit;
}

require_once __DIR__ . '/../includes/location-lookup.php';

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'Database connection failed'));
    exit;
}

// Get request data from POST body or GET params
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
} else {
    $input = $_GET;
}

$action = isset($input['action']) ? $input['action'] : '';

switch ($action) {
    case 'lookup_zip':
        handleZipLookup($pdo, $input);
        break;
        
    case 'search_towns':
        handleTownSearch($pdo, $input);
        break;
        
    case 'get_coords':
        handleGetCoords($pdo, $input);
        break;
    
    case 'get_state_towns':
        handleGetStateTowns($pdo, $input);
        break;
    
    case 'get_town_zips':
        handleGetTownZips($pdo, $input);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(array(
            'status' => 'error', 
            'message' => 'Invalid action. Use: lookup_zip, search_towns, or get_coords'
        ));
        break;
}

/**
 * Handle zip code lookup
 */
function handleZipLookup($pdo, $input) {
    $zip = isset($input['zip_code']) ? $input['zip_code'] : (isset($input['zip']) ? $input['zip'] : '');
    
    if (empty($zip)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Zip code required'));
        return;
    }
    
    $result = completeZipLookup($pdo, $zip);
    
    if (!$result) {
        echo json_encode(array(
            'status' => 'not_found',
            'message' => 'Zip code not found',
            'zip_code' => $zip
        ));
        return;
    }
    
    echo json_encode(array(
        'status' => 'success',
        'data' => $result
    ));
}

/**
 * Handle town search (autocomplete)
 */
function handleTownSearch($pdo, $input) {
    $query = isset($input['query']) ? $input['query'] : (isset($input['q']) ? $input['q'] : '');
    $stateCode = isset($input['state_code']) ? $input['state_code'] : (isset($input['state']) ? $input['state'] : null);
    $limit = isset($input['limit']) ? (int)$input['limit'] : 10;
    $limit = min(max($limit, 1), 20);
    
    if (strlen(trim($query)) < 2) {
        echo json_encode(array(
            'status' => 'success',
            'data' => array(),
            'message' => 'Query must be at least 2 characters'
        ));
        return;
    }
    
    $results = searchTowns($pdo, $query, $stateCode, $limit);
    
    echo json_encode(array(
        'status' => 'success',
        'data' => $results,
        'count' => count($results)
    ));
}

/**
 * Handle getting coordinates for a town
 */
function handleGetCoords($pdo, $input) {
    $townName = isset($input['town_name']) ? $input['town_name'] : (isset($input['town']) ? $input['town'] : '');
    $stateCode = isset($input['state_code']) ? $input['state_code'] : (isset($input['state']) ? $input['state'] : '');
    
    if (empty($townName) || empty($stateCode)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Town name and state code required'));
        return;
    }
    
    $coords = getTownCoordinates($pdo, $townName, $stateCode);
    
    if (!$coords) {
        echo json_encode(array(
            'status' => 'not_found',
            'message' => 'Coordinates not found for this town',
            'town' => $townName,
            'state' => $stateCode
        ));
        return;
    }
    
    // Also get the town_id while we're at it
    $townId = findTownId($pdo, $townName, $stateCode);
    
    echo json_encode(array(
        'status' => 'success',
        'data' => array(
            'town_name' => $townName,
            'state_code' => $stateCode,
            'town_id' => $townId,
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude']
        )
    ));
}

/**
 * Handle getting all towns for a state (from zip_codes table)
 */
function handleGetStateTowns($pdo, $input) {
    $stateCode = isset($input['state_code']) ? strtoupper($input['state_code']) : '';
    
    if (empty($stateCode) || strlen($stateCode) !== 2) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Valid 2-letter state code required'));
        return;
    }
    
    // Get distinct towns from zip_codes for this state
    $stmt = $pdo->prepare("
        SELECT DISTINCT place as town_name
        FROM zip_codes 
        WHERE state_code = ?
        ORDER BY place
    ");
    $stmt->execute(array($stateCode));
    $towns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(array(
        'status' => 'success',
        'state_code' => $stateCode,
        'data' => $towns,
        'count' => count($towns)
    ));
}

/**
 * Handle getting all zip codes for a specific town
 */
function handleGetTownZips($pdo, $input) {
    $townName = isset($input['town_name']) ? $input['town_name'] : (isset($input['town']) ? $input['town'] : '');
    $stateCode = isset($input['state_code']) ? strtoupper($input['state_code']) : '';
    
    if (empty($townName) || empty($stateCode)) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Town name and state code required'));
        return;
    }
    
    // Get all zip codes for this town
    $stmt = $pdo->prepare("
        SELECT zip_code, county, latitude, longitude
        FROM zip_codes 
        WHERE place = ? AND state_code = ?
        ORDER BY zip_code
    ");
    $stmt->execute(array($townName, $stateCode));
    $zips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(array(
        'status' => 'success',
        'town_name' => $townName,
        'state_code' => $stateCode,
        'data' => $zips,
        'count' => count($zips)
    ));
}
