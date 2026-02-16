<?php
/**
 * TPB2 Get Points API
 * ===================
 * Returns total points for a session from database
 * 
 * GET /api/get-points.php?session_id=xxx
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$config = require __DIR__ . '/../config.php';

// session_id from GET params is used for points_log lookup (browser session)
$sessionId = $_GET['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'No session_id provided', 'points' => 0]);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Centralized auth
    require_once __DIR__ . '/../includes/get-user.php';
    $dbUser = getUser($pdo);
    $user = $dbUser;
    $cookieUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

    // Get points from points_log (uses browser session_id from GET params)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) as points FROM points_log WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $clickPoints = (int) $stmt->fetchColumn();

    $totalPoints = $clickPoints;
    if ($user) {
        $totalPoints = max($clickPoints, (int) $user['civic_points']);
    }

    echo json_encode([
        'status' => 'success',
        'session_id' => $sessionId,
        'points' => $totalPoints,
        'user_id' => $user['user_id'] ?? null,
        'email_verified' => (bool) ($user['email_verified'] ?? false)
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error', 'points' => 0]);
}
