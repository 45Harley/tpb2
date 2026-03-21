<?php
/**
 * Civic Positions API — "Where I Need My Government"
 * GET  — load user's positions (all levels)
 * POST — save user's positions (one level at a time or all)
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$userId = $dbUser['user_id'];

// Load categories
$categories = $pdo->query("SELECT * FROM civic_categories WHERE is_active = 1 ORDER BY display_order")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Return all positions for this user + categories
    $stmt = $pdo->prepare("
        SELECT cp.*, cc.name as std_name, cc.hint, cc.levels as cat_levels
        FROM civic_positions cp
        LEFT JOIN civic_categories cc ON cp.category_id = cc.id
        WHERE cp.user_id = ?
        ORDER BY cp.level, cp.rank_order
    ");
    $stmt->execute([$userId]);
    $positions = $stmt->fetchAll();

    // Group by level
    $byLevel = ['town' => [], 'state' => [], 'federal' => []];
    foreach ($positions as $p) {
        $byLevel[$p['level']][] = $p;
    }

    echo json_encode([
        'categories' => $categories,
        'positions' => $byLevel,
        'bio' => $dbUser['bio'] ?? ''
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $level = $input['level'] ?? '';
    $items = $input['positions'] ?? [];

    if (!in_array($level, ['town', 'state', 'federal'])) {
        echo json_encode(['error' => 'Invalid level']);
        exit;
    }

    // Delete existing positions for this user+level
    $del = $pdo->prepare("DELETE FROM civic_positions WHERE user_id = ? AND level = ?");
    $del->execute([$userId, $level]);

    // Insert new positions
    $ins = $pdo->prepare("
        INSERT INTO civic_positions (user_id, level, category_id, category_name, rank_order, is_ranked, comment)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $i => $item) {
        $catId = (int)($item['category_id'] ?? 0);
        $catName = $item['custom'] ? trim($item['category_name'] ?? '') : null;
        $rankOrder = $item['is_ranked'] ? ($i + 1) : 0;
        $isRanked = $item['is_ranked'] ? 1 : 0;
        $comment = trim($item['comment'] ?? '');

        // Skip empty custom categories with no comment
        if ($catId === 0 && !$catName && !$comment) continue;

        $ins->execute([
            $userId, $level, $catId, $catName, $rankOrder, $isRanked, $comment ?: null
        ]);
    }

    // Update bio if provided
    if (isset($input['bio'])) {
        $bioStmt = $pdo->prepare("UPDATE users SET bio = ? WHERE user_id = ?");
        $bioStmt->execute([trim($input['bio']), $userId]);
    }

    echo json_encode(['ok' => true, 'saved' => count($items)]);
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
