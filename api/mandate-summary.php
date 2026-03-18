<?php
/**
 * Mandate Summary API
 * ===================
 * Returns topic-grouped aggregation for a geographic scope.
 *
 * GET parameters:
 *   scope       — federal | state | town (required)
 *   scope_value — district code, state_id, or town_id (required)
 *   period      — all | month | week (default: all)
 *   format      — json | csv (default: json)
 *
 * Response: { success, scope, scope_value, mandate_count, contributor_count,
 *             topics: [{policy_topic, count, pct, citizen_voices}],
 *             recent_activity: {this_week, last_week, trend},
 *             top_mandates: [{id, content, policy_topic, created_at}] }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$scope      = $_GET['scope'] ?? '';
$scopeValue = trim($_GET['scope_value'] ?? '');
$period     = $_GET['period'] ?? 'all';
$format     = $_GET['format'] ?? 'json';

// Validate
if (!in_array($scope, ['federal', 'state', 'town'], true) || $scopeValue === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scope (federal|state|town) and scope_value are required']);
    exit;
}
if (!in_array($period, ['all', 'month', 'week'], true)) {
    $period = 'all';
}

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Build scope filter
$categoryMap = ['federal' => 'mandate-federal', 'state' => 'mandate-state', 'town' => 'mandate-town'];
$category = $categoryMap[$scope];

$geoMap = ['federal' => 'u.us_congress_district', 'state' => 'u.current_state_id', 'town' => 'u.current_town_id'];
$geoCol = $geoMap[$scope];

$baseWhere = "i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL AND {$geoCol} = ?";
$baseParams = [$category, $scopeValue];

// Period filter
$periodWhere = '';
if ($period === 'month') {
    $periodWhere = ' AND i.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($period === 'week') {
    $periodWhere = ' AND i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
}

try {
    // 1. Total counts
    $sql = "SELECT COUNT(*) as cnt, COUNT(DISTINCT i.user_id) as contributors
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $counts = $stmt->fetch();
    $mandateCount     = (int)$counts['cnt'];
    $contributorCount = (int)$counts['contributors'];

    // 2. Topic breakdown
    $sql = "SELECT i.policy_topic, COUNT(*) as cnt
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere} AND i.policy_topic IS NOT NULL
            GROUP BY i.policy_topic
            ORDER BY cnt DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $topicRows = $stmt->fetchAll();

    $topics = [];
    foreach ($topicRows as $tr) {
        $pct = $mandateCount > 0 ? round(($tr['cnt'] / $mandateCount) * 100, 1) : 0;

        // Get citizen_voices for this topic (distinct, limit 5)
        $vSql = "SELECT DISTINCT i.citizen_summary
                 FROM idea_log i JOIN users u ON i.user_id = u.user_id
                 WHERE {$baseWhere}{$periodWhere} AND i.policy_topic = ? AND i.citizen_summary IS NOT NULL
                 LIMIT 5";
        $vStmt = $pdo->prepare($vSql);
        $vStmt->execute(array_merge($baseParams, [$tr['policy_topic']]));
        $voices = array_column($vStmt->fetchAll(), 'citizen_summary');

        $topics[] = [
            'policy_topic'   => $tr['policy_topic'],
            'count'          => (int)$tr['cnt'],
            'pct'            => $pct,
            'citizen_voices' => $voices,
        ];
    }

    // 3. Recent activity (always absolute, ignoring period filter)
    $thisWeekSql = "SELECT COUNT(*) FROM idea_log i JOIN users u ON i.user_id = u.user_id
                    WHERE {$baseWhere} AND i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $pdo->prepare($thisWeekSql);
    $stmt->execute($baseParams);
    $thisWeek = (int)$stmt->fetchColumn();

    $lastWeekSql = "SELECT COUNT(*) FROM idea_log i JOIN users u ON i.user_id = u.user_id
                    WHERE {$baseWhere}
                      AND i.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      AND i.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $pdo->prepare($lastWeekSql);
    $stmt->execute($baseParams);
    $lastWeek = (int)$stmt->fetchColumn();

    $trend = $thisWeek > $lastWeek ? 'up' : ($thisWeek < $lastWeek ? 'down' : 'flat');

    // 4. Top mandates
    $mandateLimit = ($format === 'csv') ? 1000 : 10;
    $sql = "SELECT i.id, i.content, i.policy_topic, i.citizen_summary, i.created_at,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name, u.age_bracket, u.show_age_bracket
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere}
            ORDER BY i.created_at DESC LIMIT {$mandateLimit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $topMandates = [];
    foreach ($stmt->fetchAll() as $row) {
        $authorDisplay = getDisplayName($row);
        $ageDisplay = (!empty($row['show_age_bracket']) && !empty($row['age_bracket'])) ? $row['age_bracket'] : null;
        $topMandates[] = [
            'id'              => (int)$row['id'],
            'content'         => $row['content'],
            'policy_topic'    => $row['policy_topic'],
            'citizen_summary' => $row['citizen_summary'],
            'created_at'      => $row['created_at'],
            'author_display'  => $authorDisplay,
            'age_bracket'     => $ageDisplay,
        ];
    }

    // CSV export
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="mandate-summary-' . $scope . '-' . $scopeValue . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Content', 'Policy Topic', 'Citizen Summary', 'Created At']);
        foreach ($topMandates as $row) {
            fputcsv($out, [$row['id'], $row['content'], $row['policy_topic'], $row['citizen_summary'], $row['created_at']]);
        }
        fclose($out);
        exit;
    }

    echo json_encode([
        'success'           => true,
        'scope'             => $scope,
        'scope_value'       => $scopeValue,
        'period'            => $period,
        'mandate_count'     => $mandateCount,
        'contributor_count' => $contributorCount,
        'topics'            => $topics,
        'recent_activity'   => ['this_week' => $thisWeek, 'last_week' => $lastWeek, 'trend' => $trend],
        'top_mandates'      => $topMandates,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
