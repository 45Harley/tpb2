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
 *   brainstorm   — POST: AI-assisted brainstorming via clerk
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
        case 'brainstorm':
            echo json_encode(handleBrainstorm($pdo, $input, $userId));
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


// -- Brainstorm (AI-assisted) ------------------------------------------

function handleBrainstorm($pdo, $input, $userId) {
    $message   = trim($input['message'] ?? '');
    $history   = $input['history'] ?? [];
    $sessionId = $input['session_id'] ?? null;

    if ($message === '') {
        return ['success' => false, 'error' => 'Message is required'];
    }

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'brainstorm');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Brainstorm clerk not available'];
    }

    $systemPrompt = buildClerkPrompt($pdo, $clerk, ['brainstorm', 'talk']);

    // Inject recent session ideas
    if ($sessionId) {
        $stmt = $pdo->prepare("SELECT id, content, category, tags FROM idea_log WHERE session_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$sessionId]);
        $recentIdeas = $stmt->fetchAll();
        if ($recentIdeas) {
            $systemPrompt .= "\n\n## Ideas captured this session\n";
            foreach ($recentIdeas as $idea) {
                $systemPrompt .= "- #{$idea['id']} [{$idea['category']}] {$idea['content']}";
                if ($idea['tags']) $systemPrompt .= " (tags: {$idea['tags']})";
                $systemPrompt .= "\n";
            }
        }
    }

    $systemPrompt .= "\n\n" . getBrainstormActionInstructions();

    $messages = [];
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $response = talkCallClaudeAPI($systemPrompt, $messages, $clerk['model'], false);

    if (isset($response['error'])) {
        return ['success' => false, 'error' => $response['error']];
    }

    $claudeMessage = '';
    foreach (($response['content'] ?? []) as $block) {
        if ($block['type'] === 'text') $claudeMessage .= $block['text'];
    }
    if (empty($claudeMessage)) {
        $claudeMessage = $response['content'][0]['text'] ?? 'No response';
    }

    $actions = parseBrainstormActions($claudeMessage);
    $actionResults = [];
    foreach ($actions as $action) {
        if (clerkCan($clerk, strtolower($action['type']))) {
            $result = processBrainstormAction($pdo, $action, $userId, $sessionId);
            if ($result) $actionResults[] = $result;
        }
    }

    $cleanMessage = cleanBrainstormActionTags($claudeMessage);
    logClerkInteraction($pdo, $clerk['clerk_id']);

    return [
        'success'  => true,
        'response' => $cleanMessage,
        'actions'  => $actionResults,
        'clerk'    => $clerk['clerk_name'],
        'usage'    => $response['usage'] ?? null
    ];
}


// -- Brainstorm Action Instructions ------------------------------------

function getBrainstormActionInstructions() {
    return <<<'INSTRUCTIONS'
## Action Tags

When the user message implies saving, tagging, or reading back ideas, include the appropriate action tag at the END of your response (after your conversational reply).

### Save an idea
When the user shares an idea, insight, or decision worth capturing:
[ACTION: SAVE_IDEA]
content: {the idea text, cleaned up}
category: {idea|decision|todo|note|question|reaction}
tags: {comma-separated tags, optional}

### Tag an existing idea
When the user wants to add or change tags on a previously saved idea:
[ACTION: TAG_IDEA]
idea_id: {the idea number}
tags: {comma-separated tags}

### Read back ideas
When the user asks to review, list, or read back their ideas:
[ACTION: READ_BACK]
category: {optional category filter}
status: {optional status filter}

## Rules
- Only include action tags when the intent is clear.
- You can include multiple action tags in one response.
- Always put your conversational response BEFORE any action tags.
- For SAVE_IDEA, clean up the idea text but preserve the meaning.
- Default category is "idea" if unclear.
INSTRUCTIONS;
}


// -- Brainstorm Action Parser (local) ----------------------------------

function parseBrainstormActions($message) {
    $actions = [];
    preg_match_all('/\[ACTION:\s*(\w+)\](.*?)(?=\[ACTION:|$)/s', $message, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $action = ['type' => $match[1], 'params' => []];
        preg_match_all('/(\w+):\s*(.+?)(?=\n\w+:|$)/s', $match[2], $params, PREG_SET_ORDER);
        foreach ($params as $param) {
            $action['params'][trim($param[1])] = trim($param[2]);
        }
        $actions[] = $action;
    }
    return $actions;
}

function cleanBrainstormActionTags($message) {
    return trim(preg_replace('/\[ACTION:\s*\w+\].*?(?=\n\n|$)/s', '', $message));
}


// -- Process Brainstorm Actions ----------------------------------------

function processBrainstormAction($pdo, $action, $userId, $sessionId) {
    switch ($action['type']) {
        case 'SAVE_IDEA':
            $content  = trim($action['params']['content'] ?? '');
            $category = trim($action['params']['category'] ?? 'idea');
            $tags     = trim($action['params']['tags'] ?? '') ?: null;

            if ($content === '') {
                return ['action' => 'SAVE_IDEA', 'success' => false, 'error' => 'Empty content'];
            }

            $validCategories = ['idea', 'decision', 'todo', 'note', 'question', 'reaction'];
            if (!in_array($category, $validCategories)) {
                $category = 'idea';
            }

            $stmt = $pdo->prepare("
                INSERT INTO idea_log (user_id, session_id, content, category, status, tags, source)
                VALUES (:user_id, :session_id, :content, :category, 'raw', :tags, 'claude-web')
            ");
            $stmt->execute([
                ':user_id'    => $userId,
                ':session_id' => $sessionId,
                ':content'    => $content,
                ':category'   => $category,
                ':tags'       => $tags
            ]);

            $id = (int)$pdo->lastInsertId();
            return [
                'action'  => 'SAVE_IDEA',
                'success' => true,
                'id'      => $id,
                'message' => ucfirst($category) . " #{$id} saved"
            ];

        case 'TAG_IDEA':
            $ideaId = (int)($action['params']['idea_id'] ?? 0);
            $tags   = trim($action['params']['tags'] ?? '');

            if (!$ideaId || $tags === '') {
                return ['action' => 'TAG_IDEA', 'success' => false, 'error' => 'idea_id and tags required'];
            }

            // Verify idea exists and belongs to user (or session)
            $where = 'id = ?';
            $params = [$ideaId];
            if ($userId) {
                $where .= ' AND user_id = ?';
                $params[] = $userId;
            } elseif ($sessionId) {
                $where .= ' AND session_id = ?';
                $params[] = $sessionId;
            }

            $stmt = $pdo->prepare("SELECT id FROM idea_log WHERE {$where}");
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return ['action' => 'TAG_IDEA', 'success' => false, 'error' => 'Idea not found or not yours'];
            }

            $stmt = $pdo->prepare("UPDATE idea_log SET tags = ? WHERE id = ?");
            $stmt->execute([$tags, $ideaId]);

            return [
                'action'  => 'TAG_IDEA',
                'success' => true,
                'idea_id' => $ideaId,
                'tags'    => $tags
            ];

        case 'READ_BACK':
            $category = $action['params']['category'] ?? null;
            $status   = $action['params']['status'] ?? null;

            $where  = [];
            $params = [];

            if ($userId) {
                $where[]  = 'user_id = ?';
                $params[] = $userId;
            } elseif ($sessionId) {
                $where[]  = 'session_id = ?';
                $params[] = $sessionId;
            }

            if ($category) {
                $where[]  = 'category = ?';
                $params[] = $category;
            }
            if ($status) {
                $where[]  = 'status = ?';
                $params[] = $status;
            }

            $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $stmt = $pdo->prepare("SELECT id, content, category, status, tags, created_at FROM idea_log {$whereClause} ORDER BY created_at DESC LIMIT 20");
            $stmt->execute($params);
            $ideas = $stmt->fetchAll();

            return [
                'action'  => 'READ_BACK',
                'success' => true,
                'ideas'   => $ideas,
                'count'   => count($ideas)
            ];

        default:
            return ['action' => $action['type'], 'success' => false, 'error' => 'Unknown brainstorm action'];
    }
}


// -- Claude API caller (local to talk) ---------------------------------

/**
 * Call Anthropic Claude API -- simplified version for talk/brainstorm.
 * No web search by default (brainstorm clerk does not need it).
 */
function talkCallClaudeAPI($systemPrompt, $messages, $model = null, $enableWebSearch = false) {
    if (!$model) $model = CLAUDE_MODEL;

    $data = [
        'model'      => $model,
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages
    ];

    if ($enableWebSearch) {
        $data['tools'] = [[
            'type'     => 'web_search_20250305',
            'name'     => 'web_search',
            'max_uses' => 3
        ]];
    }

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        return ['error' => $error['error']['message'] ?? 'API call failed (HTTP ' . $httpCode . ')'];
    }

    return json_decode($response, true);
}
