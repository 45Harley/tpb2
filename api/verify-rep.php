<?php
/**
 * Rep Verification API
 * ====================
 * Verifies a congressional member by matching bioguide_id + last_name + state_code
 * against the elected_officials table.
 *
 * POST /api/verify-rep.php
 * Body: {
 *   "bioguide_id": "B001277",
 *   "last_name": "Blumenthal",
 *   "state_code": "CT",
 *   "session_id": "civic_xxx"
 * }
 *
 * On match: creates/links user account with official_id set.
 * Returns: { status, official, user_id }
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$input = json_decode(file_get_contents('php://input'), true);
$bioguideId = strtoupper(trim($input['bioguide_id'] ?? ''));
$lastName = trim($input['last_name'] ?? '');
$stateCode = strtoupper(trim($input['state_code'] ?? ''));
$sessionId = $input['session_id'] ?? null;

if (!$bioguideId || !$lastName || !$stateCode || !$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required: bioguide_id, last_name, state_code, session_id']);
    exit();
}

// Match against elected_officials
$stmt = $pdo->prepare("
    SELECT official_id, full_name, title, party, state_code, bioguide_id
    FROM elected_officials
    WHERE bioguide_id = ?
      AND LOWER(SUBSTRING_INDEX(full_name, ' ', -1)) = LOWER(?)
      AND state_code = ?
      AND is_current = 1
");
$stmt->execute([$bioguideId, $lastName, $stateCode]);
$official = $stmt->fetch();

if (!$official) {
    echo json_encode(['status' => 'error', 'message' => 'No matching representative found. Please verify your Bioguide ID, last name, and state.']);
    exit();
}

// Check if another user already claimed this official
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE official_id = ? AND deleted_at IS NULL");
$stmt->execute([$official['official_id']]);
if ($stmt->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'This representative account has already been verified.']);
    exit();
}

// Check if session has an existing user
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

require_once __DIR__ . '/../includes/set-cookie.php';

if ($dbUser) {
    // Link existing user to official
    $stmt = $pdo->prepare("UPDATE users SET official_id = ? WHERE user_id = ?");
    $stmt->execute([$official['official_id'], $dbUser['user_id']]);
    $userId = $dbUser['user_id'];
} else {
    // Create new rep user account
    $username = 'rep_' . strtolower($bioguideId);

    $stmt = $pdo->prepare("
        INSERT INTO users (username, official_id, session_id, civic_points)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([$username, $official['official_id'], $sessionId]);
    $userId = $pdo->lastInsertId();

    // Link device
    $stmt = $pdo->prepare("
        INSERT INTO user_devices (user_id, device_session)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE user_id = ?, is_active = 1
    ");
    $stmt->execute([$userId, $sessionId, $userId]);

    // Set auth cookies
    tpbSetLoginCookies($userId, $sessionId);
}

echo json_encode([
    'status' => 'success',
    'message' => "Verified: {$official['full_name']} ({$official['title']}, {$official['state_code']})",
    'user_id' => $userId,
    'official' => [
        'official_id' => $official['official_id'],
        'full_name' => $official['full_name'],
        'title' => $official['title'],
        'party' => $official['party'],
        'state_code' => $official['state_code'],
        'bioguide_id' => $official['bioguide_id']
    ]
]);
