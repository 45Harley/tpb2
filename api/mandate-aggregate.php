<?php
/**
 * Mandate Aggregation API
 * =======================
 * GET endpoint returning mandate items for a geographic scope.
 *
 * Parameters:
 *   level          — federal | state | town | mine | my-ideas | all (default: federal)
 *   district       — required if level=federal (e.g. "CT-2")
 *   state_id       — required if level=state (integer)
 *   town_id        — required if level=town (integer)
 *   user_id        — required if level=mine or my-ideas (integer)
 *   viewer_user_id — optional, for my_vote per item
 *
 * Response:
 *   { success: true, level, item_count, contributor_count, items: [...] }
 *   Each item: { id, user_id, content, tags, level, policy_topic, agree_count, disagree_count, my_vote, created_at }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$level = $_GET['level'] ?? 'federal';

// Validate level
$validLevels = ['federal', 'state', 'town', 'mine', 'my-ideas', 'all'];
if (!in_array($level, $validLevels, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid level']);
    exit();
}

// Determine category and required geo param
$categoryMap = [
    'federal' => 'mandate-federal',
    'state'   => 'mandate-state',
    'town'    => 'mandate-town',
];
$category = $categoryMap[$level] ?? null;

// Validate required parameters
$geoParam = null;
$userId = null;
switch ($level) {
    case 'mine':
    case 'my-ideas':
        $userId = $_GET['user_id'] ?? '';
        if ($userId === '' || !ctype_digit((string)$userId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'user_id parameter (integer) is required for ' . $level . ' level']);
            exit();
        }
        $userId = (int)$userId;
        break;
    case 'federal':
        $district = trim($_GET['district'] ?? '');
        if ($district === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'district parameter is required for federal level']);
            exit();
        }
        $geoParam = $district;
        break;
    case 'state':
        $stateId = $_GET['state_id'] ?? '';
        if ($stateId === '' || !ctype_digit((string)$stateId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'state_id parameter (integer) is required for state level']);
            exit();
        }
        $geoParam = (int)$stateId;
        break;
    case 'town':
        $townId = $_GET['town_id'] ?? '';
        if ($townId === '' || !ctype_digit((string)$townId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'town_id parameter (integer) is required for town level']);
            exit();
        }
        $geoParam = (int)$townId;
        break;
}

// Optional viewer_user_id for my_vote
$viewerUserId = $_GET['viewer_user_id'] ?? null;

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Optional auth for my_vote — use viewer_user_id param or cookie auth
    require_once __DIR__ . '/../includes/get-user.php';
    $dbUser = getUser($pdo);
    $currentUserId = $viewerUserId ? (int)$viewerUserId : ($dbUser ? (int)$dbUser['user_id'] : 0);

    if ($level === 'all') {
        // All mandates for this user's geographic area (federal + state + town combined)
        $district = trim($_GET['district'] ?? '');
        $stId     = $_GET['state_id'] ?? '';
        $twId     = $_GET['town_id'] ?? '';

        $whereParts = [];
        $params = [];

        if ($district) {
            $whereParts[] = "(i.category = 'mandate-federal' AND u.us_congress_district = ?)";
            $params[] = $district;
        }
        if ($stId && ctype_digit((string)$stId)) {
            $whereParts[] = "(i.category = 'mandate-state' AND u.current_state_id = ?)";
            $params[] = (int)$stId;
        }
        if ($twId && ctype_digit((string)$twId)) {
            $whereParts[] = "(i.category = 'mandate-town' AND u.current_town_id = ?)";
            $params[] = (int)$twId;
        }

        if (empty($whereParts)) {
            echo json_encode(['success' => true, 'level' => 'all', 'item_count' => 0, 'contributor_count' => 0, 'items' => []]);
            exit;
        }

        $geoFilter = '(' . implode(' OR ', $whereParts) . ')';

        $sql = "
            SELECT i.id, i.user_id, i.content, i.tags, i.category, i.policy_topic,
                   i.agree_count, i.disagree_count, i.created_at
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND i.category IN ('mandate-federal','mandate-state','mandate-town')
              AND {$geoFilter}
            ORDER BY i.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $items = [];
        $itemIds = [];
        foreach ($rows as $row) {
            $levelLabel = str_replace('mandate-', '', $row['category']);
            $itemIds[] = (int)$row['id'];
            $items[] = [
                'id'             => (int)$row['id'],
                'user_id'        => (int)$row['user_id'],
                'content'        => $row['content'],
                'tags'           => $row['tags'],
                'level'          => ucfirst($levelLabel),
                'policy_topic'   => $row['policy_topic'],
                'agree_count'    => (int)$row['agree_count'],
                'disagree_count' => (int)$row['disagree_count'],
                'my_vote'        => null,
                'created_at'     => $row['created_at'],
            ];
        }

        // Contributor count
        $cntSql = "
            SELECT COUNT(DISTINCT i.user_id)
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND i.category IN ('mandate-federal','mandate-state','mandate-town')
              AND {$geoFilter}
        ";
        $cntStmt = $pdo->prepare($cntSql);
        $cntStmt->execute($params);
        $contributorCount = (int)$cntStmt->fetchColumn();

    } else if ($level === 'my-ideas') {
        // My ideas — category='idea' for this user
        $sql = "
            SELECT i.id, i.user_id, i.content, i.tags, i.category, i.agree_count, i.disagree_count, i.created_at
            FROM idea_log i
            WHERE i.user_id = ? AND i.deleted_at IS NULL
              AND i.category = 'idea'
            ORDER BY i.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $items = [];
        $itemIds = [];
        foreach ($rows as $row) {
            $itemIds[] = (int)$row['id'];
            $items[] = [
                'id'             => (int)$row['id'],
                'user_id'        => (int)$row['user_id'],
                'content'        => $row['content'],
                'tags'           => $row['tags'],
                'level'          => 'Idea',
                'agree_count'    => (int)$row['agree_count'],
                'disagree_count' => (int)$row['disagree_count'],
                'my_vote'        => null,
                'created_at'     => $row['created_at'],
            ];
        }
        $contributorCount = count($items) > 0 ? 1 : 0;

    } else if ($level === 'mine') {
        // My mandates — all categories for this user
        $sql = "
            SELECT i.id, i.user_id, i.content, i.tags, i.category, i.policy_topic,
                   i.agree_count, i.disagree_count, i.created_at
            FROM idea_log i
            WHERE i.user_id = ? AND i.deleted_at IS NULL
              AND i.category IN ('mandate-federal','mandate-state','mandate-town')
            ORDER BY i.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $items = [];
        $itemIds = [];
        foreach ($rows as $row) {
            $levelLabel = str_replace('mandate-', '', $row['category']);
            $itemIds[] = (int)$row['id'];
            $items[] = [
                'id'             => (int)$row['id'],
                'user_id'        => (int)$row['user_id'],
                'content'        => $row['content'],
                'tags'           => $row['tags'],
                'level'          => ucfirst($levelLabel),
                'policy_topic'   => $row['policy_topic'],
                'agree_count'    => (int)$row['agree_count'],
                'disagree_count' => (int)$row['disagree_count'],
                'my_vote'        => null,
                'created_at'     => $row['created_at'],
            ];
        }
        $contributorCount = count($items) > 0 ? 1 : 0;

    } else {
        // Build geo filter
        switch ($level) {
            case 'federal':
                $geoWhere = 'u.us_congress_district = ?';
                break;
            case 'state':
                $geoWhere = 'u.current_state_id = ?';
                break;
            case 'town':
                $geoWhere = 'u.current_town_id = ?';
                break;
        }

        // Get items
        $sql = "
            SELECT i.id, i.user_id, i.content, i.tags, i.policy_topic,
                   i.agree_count, i.disagree_count, i.created_at
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND {$geoWhere}
            ORDER BY i.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$category, $geoParam]);
        $rows = $stmt->fetchAll();

        $items = [];
        $itemIds = [];
        foreach ($rows as $row) {
            $itemIds[] = (int)$row['id'];
            $items[] = [
                'id'             => (int)$row['id'],
                'user_id'        => (int)$row['user_id'],
                'content'        => $row['content'],
                'tags'           => $row['tags'],
                'policy_topic'   => $row['policy_topic'],
                'agree_count'    => (int)$row['agree_count'],
                'disagree_count' => (int)$row['disagree_count'],
                'my_vote'        => null,
                'created_at'     => $row['created_at'],
            ];
        }

        // Separate contributor count query (MariaDB compatible)
        $cntSql = "
            SELECT COUNT(DISTINCT i.user_id)
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND {$geoWhere}
        ";
        $cntStmt = $pdo->prepare($cntSql);
        $cntStmt->execute([$category, $geoParam]);
        $contributorCount = (int)$cntStmt->fetchColumn();
    }

    // Attach my_vote if viewer identified and there are items
    if ($currentUserId && !empty($itemIds)) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $vStmt = $pdo->prepare("SELECT idea_id, vote_type FROM idea_votes WHERE idea_id IN ({$placeholders}) AND user_id = ?");
        $vStmt->execute(array_merge($itemIds, [$currentUserId]));
        $myVotes = [];
        while ($v = $vStmt->fetch()) {
            $myVotes[(int)$v['idea_id']] = $v['vote_type'];
        }
        for ($i = 0; $i < count($items); $i++) {
            $items[$i]['my_vote'] = $myVotes[$items[$i]['id']] ?? null;
        }
    }

    echo json_encode([
        'success'           => true,
        'level'             => $level,
        'item_count'        => count($items),
        'contributor_count' => $contributorCount,
        'items'             => $items,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
