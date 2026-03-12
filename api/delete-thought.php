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

$config = require __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

$thoughtId = (int)($input['thought_id'] ?? 0);

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

    // Centralized auth
    require_once __DIR__ . '/../includes/get-user.php';
    $dbUser = getUser($pdo);
    $user = $dbUser;
    $cookieUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
        exit();
    }

    // Check if thought exists AND belongs to this user (owner-only delete)
    $stmt = $pdo->prepare("SELECT user_id FROM idea_log WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$thoughtId, $user['user_id']]);
    $thought = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thought) {
        echo json_encode(['status' => 'error', 'message' => 'Thought not found or not yours']);
        exit();
    }

    // Soft-delete the thought (idea_log uses deleted_at)
    $stmt = $pdo->prepare("UPDATE idea_log SET deleted_at = NOW() WHERE id = ?");
    $stmt->execute([$thoughtId]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Thought deleted'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
