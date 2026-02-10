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

$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

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

    // Get points from points_log
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(points_earned), 0) as points FROM points_log WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $clickPoints = (int) $stmt->fetchColumn();

    // Check if session is linked to a user
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.civic_points, COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

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
