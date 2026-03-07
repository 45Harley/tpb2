<?php
/**
 * Facilitator API — JSON endpoint for group deliberation actions.
 *
 * Actions via ?action= parameter:
 *   surface_option   (POST) — surface an idea_log entry as a ballot option
 *   call_vote        (POST) — create a ballot from options
 *   new_round        (POST) — start new voting round from previous ballot
 *   merge_options    (POST) — merge two options between rounds
 *   draft_declaration (POST) — draft a declaration from a winning ballot
 *   ratify           (POST) — ratify a declaration
 *   get_ballots      (GET)  — list group ballots
 *   get_declarations (GET)  — list group declarations
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
require_once __DIR__ . '/../includes/facilitator.php';

$dbUser = getUser($pdo);
$action = $_GET['action'] ?? '';

/* ------------------------------------------------------------------ */
/*  Helper: require POST + login + facilitator role                   */
/* ------------------------------------------------------------------ */
function requirePostAndFacilitator($dbUser, $pdo, $groupId) {
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
    if (!Facilitator::isFacilitator($pdo, $groupId, (int) $dbUser['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Facilitator role required.']);
        exit;
    }
}

function requireLogin($dbUser) {
    if (!$dbUser) {
        http_response_code(401);
        echo json_encode(['error' => 'Login required.']);
        exit;
    }
}

function getJsonBody() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        exit;
    }
    return $body;
}

/* ------------------------------------------------------------------ */

switch ($action) {

    /* ------------------------------------------------------------------ */
    /*  SURFACE OPTION                                                    */
    /* ------------------------------------------------------------------ */
    case 'surface_option':
        $body = getJsonBody();
        $groupId = (int) ($body['group_id'] ?? 0);
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id is required.']);
            exit;
        }
        requirePostAndFacilitator($dbUser, $pdo, $groupId);

        $ideaId = (int) ($body['idea_id'] ?? 0);
        $pollId = (int) ($body['poll_id'] ?? 0);
        if (!$ideaId || !$pollId) {
            http_response_code(400);
            echo json_encode(['error' => 'idea_id and poll_id are required.']);
            exit;
        }

        $result = Facilitator::surfaceOption($pdo, $groupId, $ideaId, $pollId);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  CALL VOTE                                                         */
    /* ------------------------------------------------------------------ */
    case 'call_vote':
        $body = getJsonBody();
        $groupId = (int) ($body['group_id'] ?? 0);
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id is required.']);
            exit;
        }
        requirePostAndFacilitator($dbUser, $pdo, $groupId);

        $body['userId'] = (int) $dbUser['user_id'];
        $result = Facilitator::callVote($pdo, $groupId, $body);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        http_response_code(201);
        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  NEW ROUND                                                         */
    /* ------------------------------------------------------------------ */
    case 'new_round':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            exit;
        }
        requireLogin($dbUser);

        $body = getJsonBody();
        $previousPollId = (int) ($body['poll_id'] ?? 0);
        if (!$previousPollId) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id is required.']);
            exit;
        }

        $result = Facilitator::newRound($pdo, $previousPollId, (int) $dbUser['user_id']);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        http_response_code(201);
        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  MERGE OPTIONS                                                     */
    /* ------------------------------------------------------------------ */
    case 'merge_options':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            exit;
        }
        requireLogin($dbUser);

        $body = getJsonBody();
        $keepId  = (int) ($body['keep_option_id'] ?? 0);
        $mergeId = (int) ($body['merge_option_id'] ?? 0);
        $newText = trim($body['new_text'] ?? '');

        if (!$keepId || !$mergeId || !$newText) {
            http_response_code(400);
            echo json_encode(['error' => 'keep_option_id, merge_option_id, and new_text are required.']);
            exit;
        }

        $result = Facilitator::mergeOptions($pdo, $keepId, $mergeId, $newText);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  DRAFT DECLARATION                                                 */
    /* ------------------------------------------------------------------ */
    case 'draft_declaration':
        $body = getJsonBody();
        $groupId = (int) ($body['group_id'] ?? 0);
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id is required.']);
            exit;
        }
        requirePostAndFacilitator($dbUser, $pdo, $groupId);

        $pollId = (int) ($body['poll_id'] ?? 0);
        $title  = trim($body['title'] ?? '');
        $bodyText = trim($body['body'] ?? '');

        if (!$pollId || !$title || !$bodyText) {
            http_response_code(400);
            echo json_encode(['error' => 'poll_id, title, and body are required.']);
            exit;
        }

        $result = Facilitator::draftDeclaration(
            $pdo, $groupId, $pollId, $title, $bodyText, (int) $dbUser['user_id']
        );

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        http_response_code(201);
        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  RATIFY                                                            */
    /* ------------------------------------------------------------------ */
    case 'ratify':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required.']);
            exit;
        }
        requireLogin($dbUser);

        $body = getJsonBody();
        $declarationId = (int) ($body['declaration_id'] ?? 0);
        if (!$declarationId) {
            http_response_code(400);
            echo json_encode(['error' => 'declaration_id is required.']);
            exit;
        }

        // Verify user is facilitator of the declaration's group
        $stmt = $pdo->prepare("SELECT group_id FROM declarations WHERE declaration_id = :id LIMIT 1");
        $stmt->execute([':id' => $declarationId]);
        $groupId = (int) $stmt->fetchColumn();

        if (!$groupId || !Facilitator::isFacilitator($pdo, $groupId, (int) $dbUser['user_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Facilitator role required.']);
            exit;
        }

        $result = Facilitator::ratifyDeclaration($pdo, $declarationId);

        if (isset($result['error'])) {
            http_response_code(400);
            echo json_encode($result);
            exit;
        }

        echo json_encode($result);
        break;

    /* ------------------------------------------------------------------ */
    /*  GET BALLOTS                                                       */
    /* ------------------------------------------------------------------ */
    case 'get_ballots':
        requireLogin($dbUser);

        $groupId = (int) ($_GET['group_id'] ?? 0);
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id is required.']);
            exit;
        }

        $ballots = Facilitator::getGroupBallots($pdo, $groupId);
        echo json_encode(['success' => true, 'ballots' => $ballots]);
        break;

    /* ------------------------------------------------------------------ */
    /*  GET DECLARATIONS                                                  */
    /* ------------------------------------------------------------------ */
    case 'get_declarations':
        requireLogin($dbUser);

        $groupId = (int) ($_GET['group_id'] ?? 0);
        if (!$groupId) {
            http_response_code(400);
            echo json_encode(['error' => 'group_id is required.']);
            exit;
        }

        $declarations = Facilitator::listDeclarations($pdo, $groupId);
        echo json_encode(['success' => true, 'declarations' => $declarations]);
        break;

    /* ------------------------------------------------------------------ */
    /*  DEFAULT                                                           */
    /* ------------------------------------------------------------------ */
    default:
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid action. Valid: surface_option, call_vote, new_round, merge_options, draft_declaration, ratify, get_ballots, get_declarations'
        ]);
        break;
}
