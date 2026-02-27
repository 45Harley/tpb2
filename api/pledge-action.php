<?php
/**
 * Pledge Action API
 * POST /api/pledge-action.php
 * Body: { "pledge_id": 1, "checked": 1 }
 * Toggle a pledge on/off for the logged-in user.
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
$pledgeId = intval($input['pledge_id'] ?? 0);
$checked = intval($input['checked'] ?? 0);
$userId = $dbUser['user_id'];

if ($pledgeId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid pledge']);
    exit;
}

$pointsEarned = 0;

if ($checked) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_pledges (user_id, pledge_id) VALUES (?, ?)");
    $stmt->execute([$userId, $pledgeId]);

    if ($stmt->rowCount() > 0) {
        require_once dirname(__DIR__) . '/includes/point-logger.php';
        PointLogger::init($pdo);
        $result = PointLogger::award($userId, 'pledge_made', 'pledge', $pledgeId);
        $pointsEarned = $result['points_earned'] ?? 0;
    }
} else {
    $stmt = $pdo->prepare("DELETE FROM user_pledges WHERE user_id = ? AND pledge_id = ?");
    $stmt->execute([$userId, $pledgeId]);
}

echo json_encode(['success' => true, 'points_earned' => $pointsEarned]);
