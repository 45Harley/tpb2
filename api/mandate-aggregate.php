<?php
/**
 * Mandate Aggregation API
 * =======================
 * GET endpoint returning mandate items for a geographic scope.
 *
 * Parameters:
 *   level    — federal | state | town (default: federal)
 *   district — required if level=federal (e.g. "CT-2")
 *   state_id — required if level=state (integer)
 *   town_id  — required if level=town (integer)
 *
 * Response:
 *   { success: true, level, item_count, contributor_count, items: [...] }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$level = $_GET['level'] ?? 'federal';

// Validate level
$validLevels = ['federal', 'state', 'town', 'mine', 'all'];
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
$category = $categoryMap[$level];

// Validate required parameters
$geoParam = null;
$userId = null;
switch ($level) {
    case 'mine':
        $userId = $_GET['user_id'] ?? '';
        if ($userId === '' || !ctype_digit((string)$userId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'user_id parameter (integer) is required for mine level']);
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

$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

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
            SELECT i.id, i.content, i.tags, i.category, i.created_at
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND i.category IN ('mandate-federal','mandate-state','mandate-town')
              AND {$geoFilter}
            ORDER BY i.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $levelLabel = str_replace('mandate-', '', $row['category']);
            $items[] = [
                'id'         => (int)$row['id'],
                'content'    => $row['content'],
                'tags'       => $row['tags'],
                'level'      => ucfirst($levelLabel),
                'created_at' => $row['created_at'],
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

    } else if ($level === 'mine') {
        // My mandates — all categories for this user
        $sql = "
            SELECT i.id, i.content, i.tags, i.category, i.created_at
            FROM idea_log i
            WHERE i.user_id = ? AND i.deleted_at IS NULL
              AND i.category IN ('mandate-federal','mandate-state','mandate-town')
            ORDER BY i.created_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $levelLabel = str_replace('mandate-', '', $row['category']);
            $items[] = [
                'id'         => (int)$row['id'],
                'content'    => $row['content'],
                'tags'       => $row['tags'],
                'level'      => ucfirst($levelLabel),
                'created_at' => $row['created_at'],
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
            SELECT i.id, i.content, i.tags, i.created_at
            FROM idea_log i
            JOIN users u ON i.user_id = u.user_id
            WHERE i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL
              AND {$geoWhere}
            ORDER BY i.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$category, $geoParam]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id'         => (int)$row['id'],
                'content'    => $row['content'],
                'tags'       => $row['tags'],
                'created_at' => $row['created_at'],
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
