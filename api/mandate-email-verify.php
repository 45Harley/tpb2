<?php
/**
 * TPB2 Mandate Email Login API
 * ============================
 * Email-based login for the Constituent Mandate system.
 * Looks up verified email addresses and returns user data.
 * Does NOT set cookies or sessions — client stores in localStorage.
 *
 * POST /api/mandate-email-login.php
 * Body: { "email": "user@example.com" }
 *   or: { "email": "user@example.com", "name": "Harley" }
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

$email = trim($input['email'] ?? '');
$name = $input['name'] ?? null;

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
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

    // Look up by email in users table, exclude soft-deleted
    $sql = "
        SELECT u.user_id, u.first_name, s.abbreviation AS state_abbr,
               tw.town_name, u.us_congress_district AS district
        FROM users u
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        WHERE LOWER(u.email) = LOWER(?)
          AND u.deleted_at IS NULL
    ";

    $params = [$email];

    if ($name !== null && $name !== '') {
        $sql .= " AND LOWER(u.first_name) LIKE LOWER(?)";
        $params[] = $name . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    $count = count($matches);

    if ($count === 0) {
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
        echo json_encode(['success' => false, 'error' => 'still_ambiguous']);
    } else {
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
