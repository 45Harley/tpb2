<?php
/**
 * Opinion API — JSON endpoint for public opinion operations.
 *
 * Actions via ?action= parameter:
 *   submit    (POST, identity_level >= 2) — submit or update an opinion
 *   get       (GET, login required)       — get user's opinion on a target
 *   sentiment (GET, public)               — get aggregate sentiment for a target
 *   comments  (GET, public)               — get opinions with comments for a target
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=' . ($config['charset'] ?? 'utf8mb4'),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/opinion.php';

$dbUser = getUser($pdo);
$action = $_GET['action'] ?? '';

switch ($action) {

    /* ------------------------------------------------------------------ */
    /*  SUBMIT                                                             */
    /* ------------------------------------------------------------------ */
    case 'submit':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            exit;
        }
        if (!$dbUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required.']);
            exit;
        }
        if (($dbUser['identity_level_id'] ?? 1) < 2) {
            http_response_code(403);
            echo json_encode(['error' => 'Email verification required to submit opinions.']);
            exit;
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body.']);
            exit;
        }

        $targetType = $body['target_type'] ?? '';
        $targetId   = (int) ($body['target_id'] ?? 0);
        $stance     = $body['stance'] ?? '';
        $comment    = $body['comment'] ?? null;
        $scopeType  = $body['scope_type'] ?? null;
        $scopeId    = $body['scope_id'] ?? null;

        if (!$targetType || !$targetId || !$stance) {
            http_response_code(400);
            echo json_encode(['error' => 'target_type, target_id, and stance are required.']);
            exit;
        }

        $result = Opinion::submit(
            $pdo,
            (int) $dbUser['user_id'],
            $targetType,
            $targetId,
            $stance,
            $comment,
            $scopeType,
            $scopeId
        );

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        // Award civic points on first opinion
        if ($result['action'] === 'created') {
            require_once __DIR__ . '/../includes/point-logger.php';
            PointLogger::init($pdo);
            PointLogger::award(
                (int) $dbUser['user_id'],
                'opinion_submitted',
                $targetType,
                $targetId
            );
        }

        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  GET (user's opinion)                                               */
    /* ------------------------------------------------------------------ */
    case 'get':
        if (!$dbUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Login required.']);
            exit;
        }

        $targetType = $_GET['target_type'] ?? '';
        $targetId   = (int) ($_GET['target_id'] ?? 0);

        if (!$targetType || !$targetId) {
            http_response_code(400);
            echo json_encode(['error' => 'target_type and target_id are required.']);
            exit;
        }

        $opinion = Opinion::getUserOpinion($pdo, (int) $dbUser['user_id'], $targetType, $targetId);
        echo json_encode(['success' => true, 'opinion' => $opinion]);
        break;

    /* ------------------------------------------------------------------ */
    /*  SENTIMENT                                                          */
    /* ------------------------------------------------------------------ */
    case 'sentiment':
        $targetType = $_GET['target_type'] ?? '';
        $targetId   = (int) ($_GET['target_id'] ?? 0);

        if (!$targetType || !$targetId) {
            http_response_code(400);
            echo json_encode(['error' => 'target_type and target_id are required.']);
            exit;
        }

        $sentiment = Opinion::getSentiment($pdo, $targetType, $targetId);
        echo json_encode(['success' => true, 'sentiment' => $sentiment]);
        break;

    /* ------------------------------------------------------------------ */
    /*  COMMENTS                                                           */
    /* ------------------------------------------------------------------ */
    case 'comments':
        $targetType = $_GET['target_type'] ?? '';
        $targetId   = (int) ($_GET['target_id'] ?? 0);
        $limit      = min((int) ($_GET['limit'] ?? 20), 100);
        $offset     = max((int) ($_GET['offset'] ?? 0), 0);

        if (!$targetType || !$targetId) {
            http_response_code(400);
            echo json_encode(['error' => 'target_type and target_id are required.']);
            exit;
        }

        $comments = Opinion::getComments($pdo, $targetType, $targetId, $limit, $offset);
        echo json_encode(['success' => true, 'comments' => $comments]);
        break;

    /* ------------------------------------------------------------------ */
    /*  DEFAULT                                                            */
    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid action. Valid actions: submit, get, sentiment, comments'
        ]);
        break;
}
