<?php
/**
 * Talk API — /talk/api.php
 *
 * Action-based routing for the /talk brainstorming system.
 *
 * Actions:
 *   (none)/save  — POST: Save an idea
 *   history      — GET:  Read back ideas with filters
 *   promote      — POST: Advance idea status
 *   link         — POST: Set parent_id on an idea
 *
 * User identified server-side via getUser() from cookies.
 * Session ID provided by client (JS crypto.randomUUID()).
 *
 * Backward compatible: old GET-based ?content=X&category=Y&source=Z still works.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database
$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// User identification
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$userId = $dbUser ? (int)$dbUser['user_id'] : null;

// Determine action
$action = $_GET['action'] ?? '';

// Backward compatibility: old GET-based saves (?content=X&category=Y&source=Z)
if (!$action && isset($_GET['content'])) {
    $action = 'save';
}

// For POST requests without action, default to save
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = 'save';
}

// Parse JSON body for POST requests
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

try {
    switch ($action) {
        case 'save':
            echo json_encode(handleSave($pdo, $input, $userId));
            break;
        case 'history':
            echo json_encode(handleHistory($pdo, $userId));
            break;
        case 'promote':
            echo json_encode(handlePromote($pdo, $input, $userId));
            break;
        case 'link':
            echo json_encode(handleLink($pdo, $input, $userId));
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}


// ── Save ───────────────────────────────────────────────────────────

function handleSave($pdo, $input, $userId) {
    // Support both JSON body and GET params (backward compat)
    $content   = trim($input['content']   ?? $_GET['content']   ?? '');
    $category  = $input['category']  ?? $_GET['category']  ?? 'idea';
    $source    = $input['source']    ?? $_GET['source']    ?? 'web';
    $sessionId = $input['session_id'] ?? null;
    $parentId  = $input['parent_id']  ?? null;
    $tags      = $input['tags']       ?? null;

    if ($content === '') {
        return ['success' => false, 'error' => 'Content is required'];
    }

    // Validate category
    $validCategories = ['idea', 'decision', 'todo', 'note', 'question', 'reaction', 'distilled', 'digest'];
    if (!in_array($category, $validCategories)) {
        $category = 'idea';
    }

    // Validate source
    $validSources = ['web', 'voice', 'claude-web', 'claude-desktop', 'api'];
    if (!in_array($source, $validSources)) {
        $source = 'web';
    }

    // Validate parent_id exists if provided
    if ($parentId !== null) {
        $parentId = (int)$parentId;
        $check = $pdo->prepare("SELECT id FROM idea_log WHERE id = ?");
        $check->execute([$parentId]);
        if (!$check->fetch()) {
            return ['success' => false, 'error' => 'Parent idea not found'];
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, parent_id, content, category, status, tags, source)
        VALUES (:user_id, :session_id, :parent_id, :content, :category, 'raw', :tags, :source)
    ");

    $stmt->execute([
        ':user_id'    => $userId,
        ':session_id' => $sessionId,
        ':parent_id'  => $parentId,
        ':content'    => $content,
        ':category'   => $category,
        ':tags'       => $tags,
        ':source'     => $source
    ]);

    $id = (int)$pdo->lastInsertId();

    return [
        'success'    => true,
        'id'         => $id,
        'session_id' => $sessionId,
        'status'     => 'raw',
        'message'    => ucfirst($category) . ' #' . $id . ' saved (raw)'
    ];
}


// ── History ────────────────────────────────────────────────────────

function handleHistory($pdo, $userId) {
    $sessionId = $_GET['session_id'] ?? null;
    $category  = $_GET['category']   ?? null;
    $status    = $_GET['status']     ?? null;
    $limit     = min((int)($_GET['limit'] ?? 50), 200);
    $includeAi = (bool)($_GET['include_ai'] ?? false);

    if ($limit < 1) $limit = 50;

    $where = [];
    $params = [];

    // User-scoped by default if logged in
    if ($userId && !$sessionId) {
        $where[] = 'i.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    if ($sessionId) {
        $where[] = 'i.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    if ($category) {
        $where[] = 'i.category = :category';
        $params[':category'] = $category;
    }

    if ($status) {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $aiColumn = $includeAi ? ', i.ai_response' : '';

    $sql = "
        SELECT i.id, i.user_id, i.session_id, i.parent_id,
               i.content{$aiColumn}, i.category, i.status, i.tags, i.source,
               i.created_at, i.updated_at,
               u.first_name AS user_first_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id) AS children_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ideas = $stmt->fetchAll();

    return [
        'success' => true,
        'ideas'   => $ideas
    ];
}


// ── Promote ────────────────────────────────────────────────────────

function handlePromote($pdo, $input, $userId) {
    $ideaId    = (int)($input['idea_id'] ?? 0);
    $newStatus = $input['status'] ?? '';

    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }

    $validStatuses = ['raw', 'refining', 'distilled', 'actionable', 'archived'];
    if (!in_array($newStatus, $validStatuses)) {
        return ['success' => false, 'error' => 'Invalid status: ' . $newStatus];
    }

    // Fetch the idea
    $stmt = $pdo->prepare("SELECT id, user_id, status FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }

    // Owner check (skip if idea has no owner or user not logged in)
    if ($idea['user_id'] && $userId && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only promote your own ideas'];
    }

    // Forward-only check (archived allowed from any state)
    $statusOrder = ['raw' => 0, 'refining' => 1, 'distilled' => 2, 'actionable' => 3, 'archived' => 99];
    $currentRank = $statusOrder[$idea['status']] ?? 0;
    $newRank     = $statusOrder[$newStatus] ?? 0;

    if ($newRank <= $currentRank && $newStatus !== 'archived') {
        return ['success' => false, 'error' => 'Status can only advance forward. Current: ' . $idea['status']];
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $ideaId]);

    return [
        'success'    => true,
        'idea_id'    => $ideaId,
        'old_status' => $idea['status'],
        'new_status' => $newStatus
    ];
}


// ── Link ───────────────────────────────────────────────────────────

function handleLink($pdo, $input, $userId) {
    $ideaId   = (int)($input['idea_id']   ?? 0);
    $parentId = (int)($input['parent_id'] ?? 0);

    if (!$ideaId || !$parentId) {
        return ['success' => false, 'error' => 'idea_id and parent_id are required'];
    }

    if ($ideaId === $parentId) {
        return ['success' => false, 'error' => 'An idea cannot be its own parent'];
    }

    // Fetch both ideas
    $stmt = $pdo->prepare("SELECT id, user_id, parent_id FROM idea_log WHERE id IN (?, ?)");
    $stmt->execute([$ideaId, $parentId]);
    $rows = $stmt->fetchAll();

    $idea = null;
    $parent = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $ideaId)   $idea = $row;
        if ((int)$row['id'] === $parentId) $parent = $row;
    }

    if (!$idea)   return ['success' => false, 'error' => 'Idea not found'];
    if (!$parent) return ['success' => false, 'error' => 'Parent idea not found'];

    // Owner check
    if ($idea['user_id'] && $userId && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only link your own ideas'];
    }

    // Circular reference check: walk up from parent to ensure we don't hit ideaId
    $current = $parentId;
    $visited = [];
    while ($current) {
        if ($current === $ideaId) {
            return ['success' => false, 'error' => 'Circular reference detected'];
        }
        if (in_array($current, $visited)) break;
        $visited[] = $current;
        $check = $pdo->prepare("SELECT parent_id FROM idea_log WHERE id = ?");
        $check->execute([$current]);
        $row = $check->fetch();
        $current = $row ? (int)$row['parent_id'] : 0;
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET parent_id = ? WHERE id = ?");
    $stmt->execute([$parentId, $ideaId]);

    return [
        'success'   => true,
        'idea_id'   => $ideaId,
        'parent_id' => $parentId
    ];
}
