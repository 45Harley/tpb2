<?php
/**
 * Create Task API
 * Creates a task directly (not from a seed)
 * For TPB internal/volunteer use
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

// Get session
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

// Get user
$stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE session_id = ?");
$stmt->execute([$sessionId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Check if volunteer
$stmt = $pdo->prepare("SELECT status FROM volunteer_applications WHERE user_id = ? ORDER BY applied_at DESC LIMIT 1");
$stmt->execute([$user['user_id']]);
$volApp = $stmt->fetch();

if (!$volApp || $volApp['status'] !== 'accepted') {
    echo json_encode(['status' => 'error', 'message' => 'Only approved volunteers can create tasks']);
    exit;
}

// Validate input
$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$parentTaskId = !empty($input['parent_task_id']) ? (int)$input['parent_task_id'] : null;
$priority = $input['priority'] ?? 'medium';
$skillSetId = !empty($input['skill_set_id']) ? (int)$input['skill_set_id'] : null;
$points = (int)($input['points'] ?? 25);

if (empty($title)) {
    echo json_encode(['status' => 'error', 'message' => 'Task title is required']);
    exit;
}

if (empty($description)) {
    echo json_encode(['status' => 'error', 'message' => 'Task description is required']);
    exit;
}

if (strlen($title) > 255) {
    echo json_encode(['status' => 'error', 'message' => 'Title too long (max 255 characters)']);
    exit;
}

// Validate priority
$validPriorities = ['low', 'medium', 'high', 'critical'];
if (!in_array($priority, $validPriorities)) {
    $priority = 'medium';
}

// Clamp points
$points = max(5, min(500, $points));

// If parent task specified, verify it exists
if ($parentTaskId) {
    $stmt = $pdo->prepare("SELECT task_id FROM tasks WHERE task_id = ?");
    $stmt->execute([$parentTaskId]);
    if (!$stmt->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Parent task not found']);
        exit;
    }
}

try {
    // Generate unique task key
    $taskKey = 'task-' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', substr($title, 0, 30))) . '-' . time();
    
    // Create task
    $stmt = $pdo->prepare("
        INSERT INTO tasks 
            (task_key, title, short_description, parent_task_id, priority, 
             skill_set_id, points, status, created_by_user_id, created_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, 'open', ?, NOW())
    ");
    $stmt->execute([
        $taskKey,
        $title,
        $description,
        $parentTaskId,
        $priority,
        $skillSetId,
        $points,
        $user['user_id']
    ]);
    
    $taskId = $pdo->lastInsertId();
    
    echo json_encode([
        'status' => 'success',
        'message' => "Task #$taskId created!",
        'task_id' => $taskId,
        'task_key' => $taskKey
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
