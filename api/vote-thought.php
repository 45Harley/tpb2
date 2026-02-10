<?php
/**
 * TPB2 Vote Thought API
 * =====================
 * Records a vote on a thought (requires verified email)
 * 
 * POST /api/vote-thought.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "thought_id": 123,
 *   "vote_type": "up" or "down"
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

$sessionId = $input['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;
$cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;
$thoughtId = (int) ($input['thought_id'] ?? 0);
$voteType = $input['vote_type'] ?? null;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit();
}

if (!$thoughtId) {
    echo json_encode(['status' => 'error', 'message' => 'Thought ID required']);
    exit();
}

if (!in_array($voteType, ['up', 'down'])) {
    echo json_encode(['status' => 'error', 'message' => 'Vote type must be "up" or "down"']);
    exit();
}

// Map to database enum values
$dbVoteType = ($voteType === 'up') ? 'upvote' : 'downvote';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by session - must be verified
    $stmt = $pdo->prepare("
        SELECT u.user_id, COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email first']);
        exit();
    }

    if (!$user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email to vote']);
        exit();
    }

    // Check if thought exists
    $stmt = $pdo->prepare("SELECT thought_id, upvotes, downvotes FROM user_thoughts WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $thought = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thought) {
        echo json_encode(['status' => 'error', 'message' => 'Thought not found']);
        exit();
    }

    // Check if already voted
    $stmt = $pdo->prepare("
        SELECT vote_id, vote_type 
        FROM user_thought_votes 
        WHERE thought_id = ? AND user_id = ?
    ");
    $stmt->execute([$thoughtId, $user['user_id']]);
    $existingVote = $stmt->fetch(PDO::FETCH_ASSOC);

    $userVote = null;  // Track final vote state
    $points = 0;

    if ($existingVote) {
        if ($existingVote['vote_type'] === $dbVoteType) {
            // Same vote - REMOVE it (toggle off)
            $stmt = $pdo->prepare("DELETE FROM user_thought_votes WHERE vote_id = ?");
            $stmt->execute([$existingVote['vote_id']]);
            
            // Update counts
            if ($voteType === 'up') {
                $stmt = $pdo->prepare("UPDATE user_thoughts SET upvotes = upvotes - 1 WHERE thought_id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE user_thoughts SET downvotes = downvotes - 1 WHERE thought_id = ?");
            }
            $stmt->execute([$thoughtId]);
            
            $message = 'Vote removed';
            $userVote = null;
        } else {
            // Different vote - CHANGE it
            $stmt = $pdo->prepare("UPDATE user_thought_votes SET vote_type = ? WHERE vote_id = ?");
            $stmt->execute([$dbVoteType, $existingVote['vote_id']]);

            // Update counts on thought
            if ($voteType === 'up') {
                $stmt = $pdo->prepare("UPDATE user_thoughts SET upvotes = upvotes + 1, downvotes = downvotes - 1 WHERE thought_id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE user_thoughts SET upvotes = upvotes - 1, downvotes = downvotes + 1 WHERE thought_id = ?");
            }
            $stmt->execute([$thoughtId]);

            $message = 'Vote changed';
            $userVote = $voteType;
        }
    } else {
        // New vote
        $stmt = $pdo->prepare("
            INSERT INTO user_thought_votes (thought_id, user_id, vote_type, voted_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$thoughtId, $user['user_id'], $dbVoteType]);

        // Update count on thought
        if ($voteType === 'up') {
            $stmt = $pdo->prepare("UPDATE user_thoughts SET upvotes = upvotes + 1 WHERE thought_id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE user_thoughts SET downvotes = downvotes + 1 WHERE thought_id = ?");
        }
        $stmt->execute([$thoughtId]);

        // Award points for voting via PointLogger
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        $pointResult = PointLogger::award($user['user_id'], 'vote_cast', 'vote', $thoughtId);
        $points = $pointResult['points_earned'] ?? 0;

        $message = 'Vote recorded';
        $userVote = $voteType;
    }

    // Get updated counts
    $stmt = $pdo->prepare("SELECT upvotes, downvotes FROM user_thoughts WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $updatedThought = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get new total points
    $stmt = $pdo->prepare("SELECT civic_points FROM users WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $newPoints = (int) $stmt->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'thought_id' => $thoughtId,
        'vote_type' => $voteType,
        'user_vote' => $userVote,  // null, 'up', or 'down'
        'upvotes' => (int) $updatedThought['upvotes'],
        'downvotes' => (int) $updatedThought['downvotes'],
        'points_earned' => $points,
        'total_points' => $newPoints
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
