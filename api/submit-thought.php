<?php
/**
 * TPB2 Submit Thought API
 * =======================
 * Creates a new civic thought (requires verified email)
 * 
 * POST /api/submit-thought.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "content": "The thought text...",
 *   "category_id": 1,                    // optional
 *   "other_topic": "Topic name",         // required if category = Other (11)
 *   "is_local": true,                    // at least one jurisdiction required
 *   "is_state": false,
 *   "is_federal": true,
 *   "is_legislative": true,              // optional branch flags
 *   "is_executive": false,
 *   "is_judicial": false,
 *   "website_url": "",                   // honeypot - should be empty
 *   "_form_load_time": 1234567890        // timestamp when form loaded
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

require_once __DIR__ . '/bot-detect.php';
require_once __DIR__ . '/../includes/get-user.php';

$config = require __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

// Bot detection - check before processing
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $formLoadTime = isset($input['_form_load_time']) ? (int)$input['_form_load_time'] : null;
    $botCheck = checkForBot($pdo, 'submit_thought', $input, $formLoadTime);
    
    if ($botCheck['is_bot']) {
        // Silent rejection - don't tell bots why they failed
        echo json_encode(['status' => 'success', 'message' => 'Thank you for your submission.']);
        exit();
    }
} catch (PDOException $e) {
    // Continue anyway if bot check fails
}

$content = trim($input['content'] ?? '');
$categoryId = $input['category_id'] ?? null;
$otherTopic = trim($input['other_topic'] ?? '');

// Jurisdiction flags (at least one required)
$isLocal = !empty($input['is_local']) ? 1 : 0;
$isState = !empty($input['is_state']) ? 1 : 0;
$isFederal = !empty($input['is_federal']) ? 1 : 0;

// Branch flags (optional)
$isLegislative = !empty($input['is_legislative']) ? 1 : 0;
$isExecutive = !empty($input['is_executive']) ? 1 : 0;
$isJudicial = !empty($input['is_judicial']) ? 1 : 0;

// Legacy jurisdiction_level for backward compatibility
$jurisdictionLevel = 'federal';
if ($isLocal) $jurisdictionLevel = 'town';
elseif ($isState) $jurisdictionLevel = 'state';

if (empty($content)) {
    echo json_encode(['status' => 'error', 'message' => 'Content required']);
    exit();
}

if (strlen($content) < 10) {
    echo json_encode(['status' => 'error', 'message' => 'Civic thought must be at least 10 characters']);
    exit();
}

if (strlen($content) > 1000) {
    echo json_encode(['status' => 'error', 'message' => 'Civic thought must be under 1000 characters']);
    exit();
}

// At least one jurisdiction required
if (!$isLocal && !$isState && !$isFederal) {
    echo json_encode(['status' => 'error', 'message' => 'Please select at least one jurisdiction (Local, State, or Federal)']);
    exit();
}

// If category is "Other" (id=11), other_topic is required
if ($categoryId == 11 && empty($otherTopic)) {
    echo json_encode(['status' => 'error', 'message' => 'Please specify the topic when selecting "Other" category']);
    exit();
}

// Limit other_topic to 100 chars
if (strlen($otherTopic) > 100) {
    $otherTopic = substr($otherTopic, 0, 100);
}

try {
    // Reuse PDO from bot detection, or create if not exists
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    // Find user via centralized auth
    $user = getUser($pdo);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email first']);
        exit();
    }

    if (!$user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email to submit civic thoughts']);
        exit();
    }
    
    // Check if minor (13-17) needs parent consent
    if ($user['age_bracket'] === '13-17' && !$user['parent_consent']) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Waiting for parent/guardian approval. Ask them to check their email!',
            'needs_parent_consent' => true
        ]);
        exit();
    }

    // Check if TPB category - requires full profile
    if ($categoryId) {
        $stmt = $pdo->prepare("SELECT is_volunteer_only FROM thought_categories WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cat && $cat['is_volunteer_only']) {
            // TPB category - require first_name, last_name, phone
            $missing = [];
            if (empty($user['first_name'])) $missing[] = 'first name';
            if (empty($user['last_name'])) $missing[] = 'last name';
            if (empty($user['phone'])) $missing[] = 'phone';
            
            if (!empty($missing)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'TPB feedback requires a complete profile. Please add: ' . implode(', ', $missing),
                    'needs_profile' => true,
                    'missing_fields' => $missing
                ]);
                exit();
            }
        }
    }

    // Insert thought with new fields
    $stmt = $pdo->prepare("
        INSERT INTO user_thoughts 
            (user_id, content, category_id, other_topic, jurisdiction_level,
             is_local, is_state, is_federal,
             is_legislative, is_executive, is_judicial,
             town_id, state_id, status, upvotes, downvotes, created_at)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', 0, 0, NOW())
    ");
    $stmt->execute([
        $user['user_id'],
        $content,
        $categoryId ?: null,
        $otherTopic ?: null,
        $jurisdictionLevel,
        $isLocal,
        $isState,
        $isFederal,
        $isLegislative,
        $isExecutive,
        $isJudicial,
        $user['current_town_id'],
        $user['current_state_id']
    ]);

    $thoughtId = $pdo->lastInsertId();
    $taskCreated = false;

    // Check if TPB category (is_volunteer_only = 1) → auto-create task
    if ($categoryId) {
        $stmt = $pdo->prepare("SELECT is_volunteer_only, category_name FROM thought_categories WHERE category_id = ?");
        $stmt->execute([$categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($category && $category['is_volunteer_only']) {
            // Create task in triage status
            $taskTitle = substr($content, 0, 100);
            if (strlen($content) > 100) $taskTitle .= '...';
            
            $taskKey = 'tpb_' . $thoughtId . '_' . time();
            
            $stmt = $pdo->prepare("
                INSERT INTO tasks 
                    (source_thought_id, task_key, title, short_description, 
                     status, priority, created_by_user_id, created_at)
                VALUES 
                    (?, ?, ?, ?, 'open', 'medium', ?, NOW())
            ");
            $stmt->execute([
                $thoughtId,
                $taskKey,
                $taskTitle,
                $content,
                $user['user_id']
            ]);
            $taskCreated = true;
        }
    }

    // Award points via PointLogger
    require_once __DIR__ . '/../includes/point-logger.php';
    PointLogger::init($pdo);
    $pointResult = PointLogger::award($user['user_id'], 'thought_posted', 'thought', $thoughtId);
    $points = $pointResult['points_earned'] ?? 0;

    // Get new total points
    $stmt = $pdo->prepare("SELECT civic_points FROM users WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $newPoints = (int) $stmt->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'message' => $taskCreated 
            ? 'TPB feedback submitted and queued for review! Thank you.' 
            : 'Civic thought submitted! Thank you for sharing.',
        'thought_id' => $thoughtId,
        'task_created' => $taskCreated,
        'points_earned' => $points,
        'total_points' => $newPoints
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
