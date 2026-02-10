<?php
/**
 * TPB2 Delete Thought API
 * =======================
 * Allows users to delete their own thoughts
 * 
 * POST /api/delete-thought.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "thought_id": 123
 * }
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
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

$input = json_decode(file_get_contents('php://input'), true);

$sessionId = $input['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;
$thoughtId = (int)($input['thought_id'] ?? 0);

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit();
}

if (!$thoughtId) {
    echo json_encode(['status' => 'error', 'message' => 'Thought ID required']);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by session (via user_devices table)
    $stmt = $pdo->prepare("
        SELECT u.user_id 
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit();
    }

    // Check if thought exists AND belongs to this user (owner-only delete)
    $stmt = $pdo->prepare("SELECT user_id FROM user_thoughts WHERE thought_id = ? AND user_id = ?");
    $stmt->execute([$thoughtId, $user['user_id']]);
    $thought = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thought) {
        echo json_encode(['status' => 'error', 'message' => 'Thought not found or not yours']);
        exit();
    }

    // Delete votes on this thought first
    $stmt = $pdo->prepare("DELETE FROM user_thought_votes WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);

    // Delete the thought
    $stmt = $pdo->prepare("DELETE FROM user_thoughts WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Thought deleted'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
