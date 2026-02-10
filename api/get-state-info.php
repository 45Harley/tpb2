<?php
/**
 * Get State Info API
 * Returns state data for the hover modal
 * 
 * Usage: /api/get-state-info.php?state=CT
 *        /api/get-state-info.php?state=USA (for national totals)
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$stateCode = strtoupper($_GET['state'] ?? '');

if (empty($stateCode)) {
    http_response_code(400);
    echo json_encode(['error' => 'State code required']);
    exit;
}

// Get US total population for percentage calculation
$usPopStmt = $pdo->query("
    SELECT SUM(population) as total 
    FROM states 
    WHERE abbreviation NOT IN ('AS', 'GU', 'MP', 'PR', 'VI')
");
$usPopulation = (int)$usPopStmt->fetchColumn();

// Special case: USA totals
if ($stateCode === 'USA') {
    $stmt = $pdo->query("
        SELECT 
            SUM(population) as population,
            'Washington, D.C.' as capital_city,
            'New York City' as largest_city,
            8258035 as largest_city_population,
            SUM(voters_democrat) as voters_democrat,
            SUM(voters_republican) as voters_republican,
            SUM(voters_independent) as voters_independent
        FROM states
        WHERE abbreviation NOT IN ('DC', 'AS', 'GU', 'MP', 'PR', 'VI')
    ");
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($data) {
        // Convert string numbers to integers
        $data['population'] = (int)$data['population'];
        $data['largest_city_population'] = (int)$data['largest_city_population'];
        $data['voters_democrat'] = $data['voters_democrat'] ? (int)$data['voters_democrat'] : null;
        $data['voters_republican'] = $data['voters_republican'] ? (int)$data['voters_republican'] : null;
        $data['voters_independent'] = $data['voters_independent'] ? (int)$data['voters_independent'] : null;
        $data['population_pct'] = 100.0;
        $data['governor_name'] = 'Donald Trump';
        $data['governor_party'] = 'Republican';
        $data['governor_website'] = 'https://www.whitehouse.gov';
        
        echo json_encode($data);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Data not found']);
    }
    exit;
}

// Single state
$stmt = $pdo->prepare("
    SELECT 
        state_id,
        state_name,
        abbreviation,
        population,
        capital_city,
        largest_city,
        largest_city_population,
        voters_democrat,
        voters_republican,
        voters_independent,
        legislature_url
    FROM states
    WHERE abbreviation = ?
");
$stmt->execute([$stateCode]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($data) {
    // Convert to proper types
    $data['state_id'] = (int)$data['state_id'];
    $data['population'] = $data['population'] ? (int)$data['population'] : null;
    $data['largest_city_population'] = $data['largest_city_population'] ? (int)$data['largest_city_population'] : null;
    $data['voters_democrat'] = $data['voters_democrat'] ? (int)$data['voters_democrat'] : null;
    $data['voters_republican'] = $data['voters_republican'] ? (int)$data['voters_republican'] : null;
    $data['voters_independent'] = $data['voters_independent'] ? (int)$data['voters_independent'] : null;
    
    // Calculate population percentage
    if ($data['population'] && $usPopulation > 0) {
        $data['population_pct'] = round(($data['population'] / $usPopulation) * 100, 1);
    } else {
        $data['population_pct'] = null;
    }
    
    // Get governor
    $govStmt = $pdo->prepare("
        SELECT full_name, party, website
        FROM elected_officials
        WHERE title = 'Governor' 
          AND state_code = ?
          AND is_current = 1
        LIMIT 1
    ");
    $govStmt->execute([$stateCode]);
    $governor = $govStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($governor) {
        $data['governor_name'] = $governor['full_name'];
        // Shorten party name
        $partyMap = [
            'Democratic' => 'D',
            'Republican' => 'R',
            'Independent' => 'I',
            'Libertarian' => 'L',
            'Green' => 'G'
        ];
        $data['governor_party'] = $partyMap[$governor['party']] ?? $governor['party'];
        $data['governor_website'] = $governor['website'];
    } else {
        $data['governor_name'] = null;
        $data['governor_party'] = null;
        $data['governor_website'] = null;
    }
    
    echo json_encode($data);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'State not found']);
}
