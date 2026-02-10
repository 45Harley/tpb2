<?php
/**
 * TPB2 Expand Task API
 * ====================
 * Creates a task card from a seed thought (Open Task category)
 * 
 * POST /api/expand-task.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "thought_id": 123,
 *   "title": "Task title",
 *   "priority": "medium",           // low, medium, high, critical
 *   "skill_set_id": 1,              // optional
 *   "points": 50                    // optional, default 25
 * }
 * 
 * Only approved volunteers can expand tasks.
 * 🌱 Seed → 🌿 Task Card
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
$cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;
$thoughtId = intval($input['thought_id'] ?? 0);
$title = trim($input['title'] ?? '');
$priority = $input['priority'] ?? 'medium';
$skillSetId = intval($input['skill_set_id'] ?? 0) ?: null;
$points = intval($input['points'] ?? 25);

// Validation
if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit();
}

if (!$thoughtId) {
    echo json_encode(['status' => 'error', 'message' => 'Thought ID required']);
    exit();
}

if (empty($title)) {
    echo json_encode(['status' => 'error', 'message' => 'Task title required']);
    exit();
}

if (strlen($title) > 255) {
    echo json_encode(['status' => 'error', 'message' => 'Title must be under 255 characters']);
    exit();
}

// Validate priority
$validPriorities = ['low', 'medium', 'high', 'critical'];
if (!in_array($priority, $validPriorities)) {
    $priority = 'medium';
}

// Ensure points is reasonable
if ($points < 5) $points = 5;
if ($points > 500) $points = 500;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by session
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit();
    }

    // Check if user is approved volunteer
    $stmt = $pdo->prepare("
        SELECT status FROM volunteer_applications 
        WHERE user_id = ? AND status = 'accepted'
    ");
    $stmt->execute([$user['user_id']]);
    $volunteer = $stmt->fetch();

    if (!$volunteer) {
        echo json_encode(['status' => 'error', 'message' => 'Only approved volunteers can expand tasks']);
        exit();
    }

    // Get the source thought
    $stmt = $pdo->prepare("
        SELECT thought_id, content, task_id, category_id 
        FROM user_thoughts 
        WHERE thought_id = ?
    ");
    $stmt->execute([$thoughtId]);
    $thought = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thought) {
        echo json_encode(['status' => 'error', 'message' => 'Thought not found']);
        exit();
    }

    // Check if already expanded
    if ($thought['task_id']) {
        echo json_encode(['status' => 'error', 'message' => 'This thought has already been expanded to a task']);
        exit();
    }

    // Generate unique task key
    $taskKey = 'task_' . strtolower(substr(md5(uniqid()), 0, 8));

    // Create task card
    $stmt = $pdo->prepare("
        INSERT INTO tasks 
            (source_thought_id, task_key, title, short_description, priority, 
             skill_set_id, points, status, created_by_user_id, created_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, 'open', ?, NOW())
    ");
    $stmt->execute([
        $thoughtId,
        $taskKey,
        $title,
        $thought['content'],  // Use thought content as initial description
        $priority,
        $skillSetId,
        $points,
        $user['user_id']
    ]);

    $taskId = $pdo->lastInsertId();

    // Link thought to task
    $stmt = $pdo->prepare("UPDATE user_thoughts SET task_id = ? WHERE thought_id = ?");
    $stmt->execute([$taskId, $thoughtId]);

    echo json_encode([
        'status' => 'success',
        'message' => '🌱 → 🌿 Seed expanded to task card!',
        'task_id' => $taskId,
        'task_key' => $taskKey
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
