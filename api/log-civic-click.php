<?php
/**
 * TPB2 Civic Click Tracking API
 * ==============================
 * Log civic participation from landing page
 * Every click counts. You showed up. You matter.
 * 
 * NOW ROUTES THROUGH PointLogger (unified points engine)
 * Point values come from point_actions table, not hardcoded.
 * 
 * POST /api/log-civic-click.php
 * 
 * Body: {
 *   "action_type": "page_visit|skill_interest|cta_click|media_play|scroll_depth|section_view",
 *   "page_name": "landing_page",
 *   "element_id": "contribution_box_3",
 *   "session_id": "civic_abc123_1234567890",
 *   "skill_type": "tech",  // optional
 *   ... other context data
 * }
 * 
 * Place this file at: tpb2.sandgems.net/api/log-civic-click.php
 */

// ============================================================
// CORS & Headers
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
// Database Configuration
// ============================================================
$config = require __DIR__ . '/../config.php';

// ============================================================
// Helper Functions
// ============================================================
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function errorResponse($message, $statusCode = 400) {
    jsonResponse(['status' => 'error', 'message' => $message], $statusCode);
}

// ============================================================
// Main Logic
// ============================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    errorResponse('Invalid JSON input', 400);
}

// Required fields
$actionType = $input['action_type'] ?? null;
$pageName = $input['page_name'] ?? 'landing_page';

if (empty($actionType)) {
    errorResponse('Missing required field: action_type', 400);
}

// Optional fields
$elementId = $input['element_id'] ?? null;
$sessionId = $input['session_id'] ?? null;
$skillType = $input['skill_type'] ?? null;
$extraData = $input;
unset($extraData['action_type'], $extraData['page_name'], $extraData['element_id'], $extraData['session_id'], $extraData['skill_type']);

// Include skill_type in extra_data if provided
if ($skillType) {
    $extraData['skill_type'] = $skillType;
}

try {
    // Connect to database
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    // --------------------------------------------------------
    // Map legacy action names to point_actions names
    // --------------------------------------------------------
    $actionMap = [
        'thought_vote'     => 'vote_cast',
        'thought_submitted'=> 'thought_posted',
    ];
    $mappedAction = $actionMap[$actionType] ?? $actionType;

    // --------------------------------------------------------
    // Check if this session belongs to a logged-in user
    // --------------------------------------------------------
    $userId = null;
    if ($sessionId) {
        $stmt = $pdo->prepare("
            SELECT u.user_id 
            FROM user_devices ud 
            INNER JOIN users u ON ud.user_id = u.user_id 
            WHERE ud.device_session = ? AND ud.is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        if ($row) {
            $userId = (int)$row['user_id'];
        }
    }

    // --------------------------------------------------------
    // Route through PointLogger
    // --------------------------------------------------------
    require_once __DIR__ . '/../includes/point-logger.php';
    PointLogger::init($pdo);

    $extraJson = !empty($extraData) ? json_encode($extraData) : null;

    if ($userId) {
        // Logged-in user — award directly to user_id (updates users.civic_points)
        $result = PointLogger::award(
            $userId,
            $mappedAction,
            $actionType,     // context_type = original action name
            $elementId,      // context_id
            $pageName,
            $extraJson
        );
    } else {
        // Anonymous — award to session
        $result = PointLogger::awardSession(
            $sessionId,
            $mappedAction,
            $pageName,
            $elementId,
            $actionType,     // preserve original action_type as context_type
            $extraJson
        );
    }

    $pointsEarned = $result['points_earned'] ?? 0;

    // --------------------------------------------------------
    // Get aggregate stats for this session/user
    // --------------------------------------------------------
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_actions,
                SUM(points_earned) as total_points
            FROM points_log 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_actions,
                SUM(points_earned) as total_points
            FROM points_log 
            WHERE session_id = :session_id
        ");
        $stmt->execute(['session_id' => $sessionId]);
    }
    $sessionStats = $stmt->fetch();

    // --------------------------------------------------------
    // Get today's totals (for social proof)
    // --------------------------------------------------------
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT session_id) as participants_today,
            COUNT(*) as actions_today,
            SUM(points_earned) as points_today
        FROM points_log 
        WHERE DATE(earned_at) = CURDATE()
    ");
    $todayStats = $stmt->fetch();

    // Success response
    jsonResponse([
        'status' => 'success',
        'message' => 'Civic action logged. You count.',
        'log_id' => $result['log_id'] ?? null,
        'points_earned' => $pointsEarned,
        'session' => [
            'total_actions' => (int)$sessionStats['total_actions'],
            'total_points' => (int)$sessionStats['total_points']
        ],
        'today' => [
            'participants' => (int)$todayStats['participants_today'],
            'actions' => (int)$todayStats['actions_today'],
            'civic_points' => (int)$todayStats['points_today']
        ]
    ]);

} catch (PDOException $e) {
    errorResponse('Database error: ' . $e->getMessage(), 500);
}
