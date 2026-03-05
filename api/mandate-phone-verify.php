<?php
/**
 * TPB2 Mandate Phone Login API
 * ============================
 * Phone-based login for the Constituent Mandate system.
 * Looks up verified phone numbers and returns user data.
 * Does NOT set cookies or sessions — client stores in localStorage.
 *
 * POST /api/mandate-phone-login.php
 * Body: { "phone": "8039841827" }
 *   or: { "phone": "8039841827", "name": "Harley" }
 *
 * Responses:
 *   Single match  → { success: true, user: { user_id, first_name, state_abbr, town_name, district } }
 *   Multiple      → { success: false, error: "multiple_matches", count: N, hint: "What is your first name?" }
 *   Disambiguated → single match returns user, still ambiguous → { success: false, error: "still_ambiguous" }
 *   No match      → { success: false, error: "no_match" }
 *   Invalid phone → { success: false, error: "Invalid phone number" }
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$phoneRaw = $input['phone'] ?? '';
$name = $input['name'] ?? null;

// Strip non-digits from phone input
$phone = preg_replace('/\D/', '', $phoneRaw);

// Validate: must be at least 10 digits
if (strlen($phone) < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid phone number']);
    exit();
}

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Look up verified phone, join users (exclude soft-deleted), states, towns
    $sql = "
        SELECT u.user_id, u.first_name, s.abbreviation AS state_abbr,
               tw.town_name, u.us_congress_district AS district
        FROM user_identity_status uis
        INNER JOIN users u ON uis.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        WHERE uis.phone = ?
          AND uis.phone_verified = 1
          AND u.deleted_at IS NULL
    ";

    $params = [$phone];

    // If a name was provided, filter by first_name (case-insensitive)
    if ($name !== null && $name !== '') {
        $sql .= " AND LOWER(u.first_name) LIKE LOWER(?)";
        $params[] = $name . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    $count = count($matches);

    if ($count === 0) {
        // If name was provided and got zero results, it's still no_match
        echo json_encode(['success' => false, 'error' => 'no_match']);
        exit();
    }

    if ($count === 1) {
        $user = $matches[0];
        echo json_encode([
            'success' => true,
            'user' => [
                'user_id'    => (int)$user['user_id'],
                'first_name' => $user['first_name'],
                'state_abbr' => $user['state_abbr'],
                'town_name'  => $user['town_name'],
                'district'   => $user['district'],
            ]
        ]);
        exit();
    }

    // Multiple matches
    if ($name !== null && $name !== '') {
        // Name was already provided but still ambiguous
        echo json_encode(['success' => false, 'error' => 'still_ambiguous']);
    } else {
        // First attempt — ask for name to disambiguate
        echo json_encode([
            'success' => false,
            'error'   => 'multiple_matches',
            'count'   => $count,
            'hint'    => 'What is your first name?'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
