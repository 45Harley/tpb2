<?php
/**
 * Knockout Action API
 * POST /api/knockout-action.php
 * Body: { "knockout_id": 1, "checked": 1 }
 * Toggle a knockout on/off for the logged-in user.
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
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$knockoutId = intval($input['knockout_id'] ?? 0);
$checked = intval($input['checked'] ?? 0);
$userId = $dbUser['user_id'];

if ($knockoutId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid knockout']);
    exit;
}

$pointsEarned = 0;

if ($checked) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_knockouts (user_id, knockout_id) VALUES (?, ?)");
    $stmt->execute([$userId, $knockoutId]);

    if ($stmt->rowCount() > 0) {
        require_once dirname(__DIR__) . '/includes/point-logger.php';
        PointLogger::init($pdo);
        $result = PointLogger::award($userId, 'knockout_achieved', 'knockout', $knockoutId);
        $pointsEarned = $result['points_earned'] ?? 0;
    }
} else {
    $stmt = $pdo->prepare("DELETE FROM user_knockouts WHERE user_id = ? AND knockout_id = ?");
    $stmt->execute([$userId, $knockoutId]);
}

echo json_encode(['success' => true, 'points_earned' => $pointsEarned]);
