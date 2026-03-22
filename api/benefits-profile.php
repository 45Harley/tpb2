<?php
/**
 * Benefits Profile API — save/load user_profile data
 * ====================================================
 * GET  — returns current profile for logged-in user
 * POST — saves profile fields (partial updates OK)
 */

header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$userId = (int)$dbUser['user_id'];

// Valid columns (whitelist)
$validColumns = [
    'us_citizen', 'citizenship_type', 'gender', 'date_of_birth', 'living_situation',
    'voter_registered', 'party_affiliation', 'voting_frequency', 'primary_voter', 'first_time_voter',
    'education_level', 'employment_status', 'student_status', 'industry', 'household_income_range',
    'student_debt', 'public_service_employer',
    'health_insurance', 'veteran', 'veteran_branch',
    'household_size', 'children_under_18', 'caregiver',
    'marital_status', 'monthly_housing_cost', 'savings_range', 'current_benefits',
    'immigration_status', 'preferred_language', 'pays_utilities',
    'has_disability', 'disability_type', 'pregnant', 'single_parent',
    'criminal_record', 'domestic_violence',
    'benefits_match_optin'
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
    // Count filled fields
    $filled = 0;
    foreach ($validColumns as $col) {
        if (isset($profile[$col]) && $profile[$col] !== null && $profile[$col] !== '') $filled++;
    }
    echo json_encode(['profile' => $profile, 'filled' => $filled, 'total' => count($validColumns)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Filter to valid columns only
    $data = [];
    foreach ($input as $key => $value) {
        if (in_array($key, $validColumns)) {
            $data[$key] = ($value === '' || $value === null) ? null : $value;
        }
    }

    if (empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields']);
        exit;
    }

    // Upsert — INSERT or UPDATE
    $existing = $pdo->prepare("SELECT user_id FROM user_profile WHERE user_id = ?");
    $existing->execute([$userId]);

    if ($existing->fetch()) {
        // UPDATE
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $userId;
        $sql = "UPDATE user_profile SET " . implode(', ', $sets) . " WHERE user_id = ?";
        $pdo->prepare($sql)->execute($params);
    } else {
        // INSERT
        $data['user_id'] = $userId;
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO user_profile ($cols) VALUES ($placeholders)";
        $pdo->prepare($sql)->execute(array_values($data));
    }

    // Award civic points for newly filled fields
    require_once dirname(__DIR__) . '/includes/point-logger.php';
    // Count filled after save
    $stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ?");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    $filled = 0;
    foreach ($validColumns as $col) {
        if (isset($profile[$col]) && $profile[$col] !== null && $profile[$col] !== '') $filled++;
    }

    echo json_encode(['success' => true, 'filled' => $filled, 'total' => count($validColumns)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
