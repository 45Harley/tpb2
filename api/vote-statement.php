<?php
/**
 * TPB2 Vote Statement API
 * =======================
 * Records a vote on a rep statement (requires verified email)
 *
 * POST /api/vote-statement.php
 * Body: {
 *   "statement_id": 123,
 *   "vote_type": "agree" or "disagree"
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

$statementId = (int) ($input['statement_id'] ?? 0);
$voteType = $input['vote_type'] ?? null;

if (!$statementId) {
    echo json_encode(['status' => 'error', 'message' => 'Statement ID required']);
    exit();
}

if (!in_array($voteType, ['agree', 'disagree'])) {
    echo json_encode(['status' => 'error', 'message' => 'Vote type must be "agree" or "disagree"']);
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

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email first']);
        exit();
    }

    if (!$user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email to vote']);
        exit();
    }

    // Check if statement exists
    $stmt = $pdo->prepare("SELECT id, agree_count, disagree_count FROM rep_statements WHERE id = ?");
    $stmt->execute([$statementId]);
    $statement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$statement) {
        echo json_encode(['status' => 'error', 'message' => 'Statement not found']);
        exit();
    }

    // Check if already voted
    $stmt = $pdo->prepare("
        SELECT id, vote_type
        FROM rep_statement_votes
        WHERE statement_id = ? AND user_id = ?
    ");
    $stmt->execute([$statementId, $user['user_id']]);
    $existingVote = $stmt->fetch(PDO::FETCH_ASSOC);

    $userVote = null;  // Track final vote state
    $points = 0;

    if ($existingVote) {
        if ($existingVote['vote_type'] === $voteType) {
            // Same vote - REMOVE it (toggle off)
            $stmt = $pdo->prepare("DELETE FROM rep_statement_votes WHERE id = ?");
            $stmt->execute([$existingVote['id']]);

            // Update counts
            if ($voteType === 'agree') {
                $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count - 1 WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE rep_statements SET disagree_count = disagree_count - 1 WHERE id = ?");
            }
            $stmt->execute([$statementId]);

            $message = 'Vote removed';
            $userVote = null;
        } else {
            // Different vote - CHANGE it
            $stmt = $pdo->prepare("UPDATE rep_statement_votes SET vote_type = ? WHERE id = ?");
            $stmt->execute([$voteType, $existingVote['id']]);

            // Update counts - swap
            if ($voteType === 'agree') {
                $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count + 1, disagree_count = disagree_count - 1 WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count - 1, disagree_count = disagree_count + 1 WHERE id = ?");
            }
            $stmt->execute([$statementId]);

            $message = 'Vote changed';
            $userVote = $voteType;
        }
    } else {
        // New vote
        $stmt = $pdo->prepare("
            INSERT INTO rep_statement_votes (statement_id, user_id, vote_type, voted_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$statementId, $user['user_id'], $voteType]);

        // Update count on statement
        if ($voteType === 'agree') {
            $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count + 1 WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE rep_statements SET disagree_count = disagree_count + 1 WHERE id = ?");
        }
        $stmt->execute([$statementId]);

        // Award points for voting via PointLogger
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        $pointResult = PointLogger::award($user['user_id'], 'vote_cast', 'statement_vote', $statementId);
        $points = $pointResult['points_earned'] ?? 0;

        $message = 'Vote recorded';
        $userVote = $voteType;
    }

    // Get updated counts
    $stmt = $pdo->prepare("SELECT agree_count, disagree_count FROM rep_statements WHERE id = ?");
    $stmt->execute([$statementId]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get new total points
    $stmt = $pdo->prepare("SELECT civic_points FROM users WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);
    $newPoints = (int) $stmt->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'statement_id' => $statementId,
        'vote_type' => $voteType,
        'user_vote' => $userVote,  // null, 'agree', or 'disagree'
        'agree_count' => (int) $updated['agree_count'],
        'disagree_count' => (int) $updated['disagree_count'],
        'points_earned' => $points,
        'total_points' => $newPoints
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
