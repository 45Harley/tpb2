<?php
/**
 * Scan Submit — queue a benefits scan request
 * Browser calls this to submit, gets back a scan_id to poll for results.
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
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$userId = (int)$dbUser['user_id'];

// Load profile
$stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();
if (!$profile || empty($profile['benefits_match_optin'])) {
    echo json_encode(['error' => 'No profile or opt-in not enabled']);
    exit;
}

// Build request data (profile + location context)
$requestData = json_encode([
    'profile' => $profile,
    'state' => $dbUser['state_abbrev'] ?? 'CT',
    'state_name' => $dbUser['state_name'] ?? 'Connecticut',
    'town' => $dbUser['town_name'] ?? '',
    'date_of_birth' => $profile['date_of_birth'] ?? ''
]);

// Insert into queue
$stmt = $pdo->prepare("INSERT INTO scan_queue (user_id, status, request_data) VALUES (?, 'pending', ?)");
$stmt->execute([$userId, $requestData]);
$scanId = $pdo->lastInsertId();

echo json_encode(['scan_id' => (int)$scanId, 'status' => 'pending']);
