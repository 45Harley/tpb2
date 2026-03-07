<?php
/**
 * Ballot API — JSON endpoint for poll/ballot operations.
 *
 * Actions via ?action= parameter:
 *   create  (POST, login required)
 *   vote    (POST, login required)
 *   tally   (GET)
 *   get     (GET)
 *   list    (GET)
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
require_once __DIR__ . '/../includes/ballot.php';
require_once __DIR__ . '/../includes/point-logger.php';

$dbUser = getUser($pdo);
$action = $_GET['action'] ?? '';

switch ($action) {

    /* ------------------------------------------------------------------ */
    /*  CREATE                                                            */
    /* ------------------------------------------------------------------ */
    case 'create':
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

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body.']);
            exit;
        }

        $body['created_by'] = $dbUser['user_id'];
        $result = Ballot::create($pdo, $body);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        http_response_code(201);
        echo json_encode(['success' => true, 'poll_id' => $result['poll_id'], 'options' => $result['options']]);
        break;

    /* ------------------------------------------------------------------ */
    /*  VOTE                                                              */
    /* ------------------------------------------------------------------ */
    case 'vote':
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

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || empty($body['poll_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body or missing poll_id.']);
            exit;
        }

        $pollId = (int) $body['poll_id'];
        $isRep  = !empty($dbUser['official_id']);
        $result = Ballot::vote($pdo, $pollId, (int) $dbUser['user_id'], $body, $isRep);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        // Award civic points on first vote (action === 'created')
        if ($result['action'] === 'created') {
            PointLogger::init($pdo);
            PointLogger::award((int) $dbUser['user_id'], 'poll_voted', 'poll', $pollId);
        }

        echo json_encode(['success' => true, 'action' => $result['action']]);
        break;

    /* ------------------------------------------------------------------ */
    /*  TALLY                                                             */
    /* ------------------------------------------------------------------ */
    case 'tally':
        $pollId = isset($_GET['poll_id']) ? (int) $_GET['poll_id'] : 0;
        if (!$pollId) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id is required.']);
            exit;
        }

        $result = Ballot::tally($pdo, $pollId);

        if (isset($result['error'])) {
            http_response_code(404);
            echo json_encode($result);
            exit;
        }

        echo json_encode(['success' => true, 'tally' => $result]);
        break;

    /* ------------------------------------------------------------------ */
    /*  GET                                                               */
    /* ------------------------------------------------------------------ */
    case 'get':
        $pollId = isset($_GET['poll_id']) ? (int) $_GET['poll_id'] : 0;
        if (!$pollId) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id is required.']);
            exit;
        }

        $poll = Ballot::get($pdo, $pollId);
        if (!$poll) {
            http_response_code(404);
            echo json_encode(['error' => 'Poll not found.']);
            exit;
        }

        // Attach user's votes if logged in
        if ($dbUser) {
            $stmt = $pdo->prepare(
                "SELECT vote_choice, option_id, rank_position
                 FROM poll_votes
                 WHERE poll_id = :pid AND user_id = :uid"
            );
            $stmt->execute([':pid' => $pollId, ':uid' => $dbUser['user_id']]);
            $poll['user_vote'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'poll' => $poll]);
        break;

    /* ------------------------------------------------------------------ */
    /*  LIST                                                              */
    /* ------------------------------------------------------------------ */
    case 'list':
        $scopeType = $_GET['scope_type'] ?? 'national';
        $scopeId   = isset($_GET['scope_id']) ? (int) $_GET['scope_id'] : null;
        $active    = ($_GET['active'] ?? '1') === '1';

        $polls = Ballot::listByScope($pdo, $scopeType, $scopeId, $active);
        echo json_encode(['success' => true, 'polls' => $polls]);
        break;

    /* ------------------------------------------------------------------ */
    /*  DEFAULT                                                           */
    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Valid actions: create, vote, tally, get, list']);
        break;
}
