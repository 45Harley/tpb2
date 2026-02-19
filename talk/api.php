<?php
/**
 * Talk API — /talk/api.php
 *
 * Action-based routing for the /talk brainstorming system.
 *
 * Actions:
 *   (none)/save       — POST: Save an idea
 *   history           — GET:  Read back ideas with filters
 *   promote           — POST: Advance idea status
 *   link              — POST: Set parent_id on an idea
 *   brainstorm        — POST: AI-assisted brainstorming via clerk
 *   toggle_shareable  — POST: Flip shareable flag
 *   create_link       — POST: Create thematic link between ideas (Phase 3)
 *   get_links         — GET:  Get links for an idea (Phase 3)
 *   create_group      — POST: Create a deliberation group (Phase 3)
 *   list_groups       — GET:  List groups (Phase 3)
 *   get_group         — GET:  Get group details (Phase 3)
 *   join_group        — POST: Join a group (Phase 3)
 *   leave_group       — POST: Leave a group (Phase 3)
 *   update_group      — POST: Update group settings (Phase 3)
 *   update_member     — POST: Change member role/status or remove member (Phase 3)
 *   add_member        — POST: Facilitator adds member by username/email (Phase 3)
 *   gather            — POST: Run gatherer clerk on group (Phase 3)
 *   crystallize       — POST: Produce crystallized proposal (Phase 3)
 *   invite_to_group   — POST: Send invites to email addresses (Phase 5)
 *   get_invites       — GET:  List invites for a group (Phase 5)
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
require_once __DIR__ . '/../includes/smtp-mail.php';
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

// Helper: determine public access level for non-member verified users
function getPublicAccess($group, $dbUser) {
    if (!$dbUser || (int)($dbUser['identity_level_id'] ?? 1) < 3) return null;
    if (!empty($group['public_voting'])) return 'vote';
    if (!empty($group['public_readable'])) return 'read';
    return null;
}

try {
    switch ($action) {
        case 'save':
            echo json_encode(handleSave($pdo, $input, $userId, $dbUser));
            break;
        case 'history':
            echo json_encode(handleHistory($pdo, $userId, $dbUser));
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
        case 'toggle_shareable':
            echo json_encode(handleToggleShareable($pdo, $input, $userId));
            break;
        case 'edit':
            echo json_encode(handleEdit($pdo, $input, $userId));
            break;
        case 'delete':
            echo json_encode(handleDelete($pdo, $input, $userId));
            break;

        // Phase 3: Idea Links
        case 'create_link':
            echo json_encode(handleCreateLink($pdo, $input, $userId));
            break;
        case 'get_links':
            echo json_encode(handleGetLinks($pdo));
            break;

        // Phase 3: Groups
        case 'create_group':
            echo json_encode(handleCreateGroup($pdo, $input, $userId));
            break;
        case 'list_groups':
            echo json_encode(handleListGroups($pdo, $userId));
            break;
        case 'get_group':
            echo json_encode(handleGetGroup($pdo, $userId, $dbUser));
            break;
        case 'join_group':
            echo json_encode(handleJoinGroup($pdo, $input, $userId));
            break;
        case 'leave_group':
            echo json_encode(handleLeaveGroup($pdo, $input, $userId));
            break;
        case 'update_group':
            echo json_encode(handleUpdateGroup($pdo, $input, $userId));
            break;
        case 'update_member':
            echo json_encode(handleUpdateMember($pdo, $input, $userId));
            break;
        case 'add_member':
            echo json_encode(handleAddMember($pdo, $input, $userId));
            break;

        // Phase 3: Gatherer + Crystallization
        case 'gather':
            echo json_encode(handleGather($pdo, $input, $userId));
            break;
        case 'crystallize':
            echo json_encode(handleCrystallize($pdo, $input, $userId));
            break;
        case 'check_staleness':
            echo json_encode(handleCheckStaleness($pdo, $userId));
            break;

        case 'vote':
            echo json_encode(handleVote($pdo, $input, $userId, $dbUser));
            break;

        // Phase 5: Group Invites
        case 'invite_to_group':
            echo json_encode(handleInviteToGroup($pdo, $input, $userId, $config));
            break;
        case 'get_invites':
            echo json_encode(handleGetInvites($pdo, $userId));
            break;

        // Phase 8: Geo-Streams
        case 'auto_create_standard_groups':
            echo json_encode(handleAutoCreateStandardGroups($pdo, $input, $userId));
            break;
        case 'get_access_status':
            echo json_encode(handleGetAccessStatus($dbUser));
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

function handleSave($pdo, $input, $userId, $dbUser = null) {
    // Access gate: level 2+ required to post
    if (!$dbUser || (int)($dbUser['identity_level_id'] ?? 1) < 2) {
        return ['success' => false, 'error' => 'Verify your email to post ideas', 'needs' => 'verify_email'];
    }
    // Access gate: location required for level 2+
    if (empty($dbUser['current_state_id'])) {
        return ['success' => false, 'error' => 'Set your town to participate', 'needs' => 'set_location'];
    }

    // Bot detection
    require_once __DIR__ . '/../api/bot-detect.php';
    $formLoadTime = isset($input['_form_load_time']) ? (int)$input['_form_load_time'] : null;
    $botCheck = checkForBot($pdo, 'talk_save', $input, $formLoadTime);
    if ($botCheck['is_bot']) {
        return ['success' => true, 'id' => 0, 'message' => 'Saved'];
    }

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
    $validCategories = ['idea', 'decision', 'todo', 'note', 'question', 'reaction', 'distilled', 'digest', 'chat'];
    if (!in_array($category, $validCategories)) {
        $category = 'idea';
    }

    // Validate source
    $validSources = ['web', 'voice', 'claude-web', 'claude-desktop', 'api', 'clerk-brainstorm', 'clerk-gatherer'];
    if (!in_array($source, $validSources)) {
        $source = 'web';
    }

    $shareable = (int)($input['shareable'] ?? 0);
    $clerkKey  = $input['clerk_key'] ?? null;

    // Validate group_id if provided
    $groupId = isset($input['group_id']) && $input['group_id'] !== '' ? (int)$input['group_id'] : null;
    if ($groupId) {
        if (!$userId) {
            return ['success' => false, 'error' => 'Login required to save to a group'];
        }
        $gStmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $gStmt->execute([$groupId, $userId]);
        $membership = $gStmt->fetch();
        if (!$membership || $membership['role'] === 'observer') {
            return ['success' => false, 'error' => 'You must be a group member to submit ideas to this group'];
        }
        $shareable = 1; // group ideas are inherently shareable
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

    // Auto-stamp with creator's geography
    $stateId = $dbUser['current_state_id'] ?? null;
    $townId  = $dbUser['current_town_id'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, parent_id, content, category, status, tags, source, shareable, clerk_key, group_id, state_id, town_id)
        VALUES (:user_id, :session_id, :parent_id, :content, :category, 'raw', :tags, :source, :shareable, :clerk_key, :group_id, :state_id, :town_id)
    ");

    $stmt->execute([
        ':user_id'    => $userId,
        ':session_id' => $sessionId,
        ':parent_id'  => $parentId,
        ':content'    => $content,
        ':category'   => $category,
        ':tags'       => $tags,
        ':source'     => $source,
        ':shareable'  => $shareable,
        ':clerk_key'  => $clerkKey,
        ':group_id'   => $groupId,
        ':state_id'   => $stateId,
        ':town_id'    => $townId
    ]);

    $id = (int)$pdo->lastInsertId();

    // Auto-classify via AI if requested
    $autoClassify = (bool)($input['auto_classify'] ?? false);
    if ($autoClassify && $content !== '') {
        try {
            require_once __DIR__ . '/../config-claude.php';
            $classifyPrompt = "You are a classifier for citizen ideas. Given this text, respond with ONLY a JSON object on a single line, nothing else:\n{\"category\": \"idea|decision|todo|note|question\", \"tags\": \"2-5 comma-separated keywords\"}";
            $classifyMessages = [['role' => 'user', 'content' => $content]];
            $classifyResponse = talkCallClaudeAPI($classifyPrompt, $classifyMessages, 'claude-haiku-4-5-20251001', false);

            if (!isset($classifyResponse['error'])) {
                $aiText = '';
                foreach (($classifyResponse['content'] ?? []) as $block) {
                    if ($block['type'] === 'text') $aiText .= $block['text'];
                }
                // Extract JSON from response (handle markdown code blocks)
                $aiText = trim($aiText);
                if (preg_match('/\{[^}]+\}/', $aiText, $jsonMatch)) {
                    $classified = json_decode($jsonMatch[0], true);
                    if ($classified) {
                        $newCategory = $classified['category'] ?? null;
                        $newTags = $classified['tags'] ?? null;
                        $validCategories2 = ['idea', 'decision', 'todo', 'note', 'question'];
                        if ($newCategory && in_array($newCategory, $validCategories2)) {
                            $category = $newCategory;
                        }
                        if ($newTags) {
                            $tags = $newTags;
                        }
                        $pdo->prepare("UPDATE idea_log SET category = ?, tags = ? WHERE id = ?")
                            ->execute([$category, $tags, $id]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Classification failed — idea is saved with defaults, continue
        }
    }

    // Build enriched response for stream rendering
    $authorDisplay = 'You';
    if ($userId) {
        $uStmt = $pdo->prepare("SELECT first_name, last_name, username, show_first_name, show_last_name FROM users WHERE user_id = ?");
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow) $authorDisplay = getDisplayName($uRow);
    }

    return [
        'success'    => true,
        'id'         => $id,
        'session_id' => $sessionId,
        'status'     => 'raw',
        'message'    => ucfirst($category) . ' #' . $id . ' saved (raw)',
        'idea'       => [
            'id'             => $id,
            'user_id'        => $userId,
            'content'        => $content,
            'category'       => $category,
            'tags'           => $tags,
            'status'         => 'raw',
            'source'         => $source,
            'group_id'       => $groupId,
            'shareable'      => $shareable,
            'clerk_key'      => $clerkKey,
            'created_at'     => date('Y-m-d H:i:s'),
            'author_display' => $authorDisplay,
            'children_count' => 0,
            'edit_count'     => 0,
            'agree_count'    => 0,
            'disagree_count' => 0
        ]
    ];
}


// ── History ────────────────────────────────────────────────────────

function handleHistory($pdo, $userId, $dbUser = null) {
    $sessionId  = $_GET['session_id'] ?? null;
    $category   = $_GET['category']   ?? null;
    $status     = $_GET['status']     ?? null;
    $limit      = min((int)($_GET['limit'] ?? 50), 200);
    $includeAi  = (bool)($_GET['include_ai'] ?? false);
    $groupId    = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;
    $geoStateId = isset($_GET['state_id']) && $_GET['state_id'] !== '' ? (int)$_GET['state_id'] : null;
    $geoTownId  = isset($_GET['town_id']) && $_GET['town_id'] !== '' ? (int)$_GET['town_id'] : null;
    $since      = $_GET['since']  ?? null;
    $before     = $_GET['before'] ?? null;
    $excludeChat = !isset($_GET['include_chat']);

    if ($limit < 1) $limit = 50;

    $where = ['i.deleted_at IS NULL'];
    $params = [];
    $userRole = null;

    // Group-scoped, geo-scoped, or personal-scoped
    if ($groupId !== null) {
        // Verify membership for closed groups
        if ($userId) {
            $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$groupId, $userId]);
            $membership = $stmt->fetch();
            $userRole = $membership ? $membership['role'] : null;
        }
        // Check group access
        $stmt = $pdo->prepare("SELECT access_level, public_readable, public_voting FROM idea_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
        $publicAccess = null;
        if ($group && $group['access_level'] === 'closed' && !$userRole) {
            $publicAccess = getPublicAccess($group, $dbUser);
            if (!$publicAccess) {
                return ['success' => false, 'error' => 'This group is closed'];
            }
        }
        if (!$userRole && !$publicAccess && $group) {
            $publicAccess = getPublicAccess($group, $dbUser);
        }

        $where[] = 'i.group_id = :group_id';
        $params[':group_id'] = $groupId;
    } elseif ($geoTownId !== null) {
        // Town-scoped stream: ungrouped ideas from this town
        $where[] = 'i.town_id = :town_id';
        $where[] = 'i.group_id IS NULL';
        $params[':town_id'] = $geoTownId;
    } elseif ($geoStateId !== null) {
        // State-scoped stream: ungrouped ideas from this state
        $where[] = 'i.state_id = :state_id';
        $where[] = 'i.group_id IS NULL';
        $params[':state_id'] = $geoStateId;
    } elseif ($sessionId) {
        $where[] = 'i.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    } else {
        // USA stream: all ungrouped ideas
        $where[] = 'i.group_id IS NULL';
    }

    if ($excludeChat) {
        $where[] = "i.category != 'chat'";
    }

    if ($category) {
        $where[] = 'i.category = :category';
        $params[':category'] = $category;
    }

    if ($status) {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }

    if ($since) {
        $where[] = 'i.created_at > :since';
        $params[':since'] = $since;
    }

    if ($before) {
        $where[] = 'i.created_at < :before';
        $params[':before'] = $before;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $orderDir = $since ? 'ASC' : 'DESC';

    $aiColumn = $includeAi ? ', i.ai_response' : '';

    $sql = "
        SELECT i.id, i.user_id, i.session_id, i.parent_id,
               i.content{$aiColumn}, i.category, i.status, i.tags, i.source,
               i.shareable, i.clerk_key, i.group_id, i.state_id, i.town_id, i.edit_count,
               i.agree_count, i.disagree_count,
               i.created_at, i.updated_at,
               u.first_name, u.last_name, u.username AS user_username,
               u.show_first_name, u.show_last_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id AND c.deleted_at IS NULL) AS children_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at {$orderDir}
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ideas = $stmt->fetchAll();

    // Compute display names + fix legacy AI rows missing user_id
    foreach ($ideas as &$idea) {
        $idea['author_display'] = $idea['clerk_key'] ? 'AI' : getDisplayName($idea);
        // Legacy AI responses have NULL user_id — inherit from parent
        if ($idea['clerk_key'] && !$idea['user_id'] && $idea['parent_id']) {
            $pStmt = $pdo->prepare("SELECT user_id FROM idea_log WHERE id = ?");
            $pStmt->execute([$idea['parent_id']]);
            $parent = $pStmt->fetch();
            if ($parent) $idea['user_id'] = $parent['user_id'];
        }
        // Lookup user's vote on this idea
        $idea['user_vote'] = null;
        if ($userId && !$idea['clerk_key'] && $idea['category'] !== 'digest') {
            $vStmt = $pdo->prepare("SELECT vote_type FROM idea_votes WHERE idea_id = ? AND user_id = ?");
            $vStmt->execute([$idea['id'], $userId]);
            $uv = $vStmt->fetch();
            if ($uv) $idea['user_vote'] = $uv['vote_type'];
        }
        unset($idea['first_name'], $idea['last_name'], $idea['user_username'], $idea['show_first_name'], $idea['show_last_name']);
    }
    unset($idea);

    $result = [
        'success' => true,
        'ideas'   => $ideas
    ];
    if ($groupId !== null) {
        $result['user_role'] = $userRole;
        $result['public_access'] = $publicAccess ?? null;
    }
    return $result;
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
    $stmt = $pdo->prepare("SELECT id, user_id, status, deleted_at FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }
    if ($idea['deleted_at']) {
        return ['success' => false, 'error' => 'Cannot promote a deleted idea'];
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
    $stmt = $pdo->prepare("SELECT id, user_id, parent_id, deleted_at FROM idea_log WHERE id IN (?, ?)");
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
    if ($idea['deleted_at'] || $parent['deleted_at']) return ['success' => false, 'error' => 'Cannot link deleted ideas'];

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


// ── Toggle Shareable ──────────────────────────────────────────────

function handleToggleShareable($pdo, $input, $userId) {
    $ideaId    = (int)($input['idea_id'] ?? 0);
    $shareable = (int)($input['shareable'] ?? 0);

    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }

    $stmt = $pdo->prepare("SELECT id, user_id, deleted_at, group_id FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }
    if ($idea['deleted_at']) {
        return ['success' => false, 'error' => 'Cannot modify a deleted idea'];
    }

    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    if ($idea['user_id'] && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only change your own ideas'];
    }

    // Group ideas are always shared — prevent un-sharing
    if (!empty($idea['group_id']) && !$shareable) {
        return ['success' => false, 'error' => 'Group ideas are always shared. Move to personal first.'];
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET shareable = ? WHERE id = ?");
    $stmt->execute([$shareable ? 1 : 0, $ideaId]);

    return [
        'success'   => true,
        'idea_id'   => $ideaId,
        'shareable' => $shareable ? 1 : 0
    ];
}


// ── Edit Idea ────────────────────────────────────────────────────

function handleEdit($pdo, $input, $userId) {
    $ideaId    = (int)($input['idea_id'] ?? 0);
    $newContent = trim($input['content'] ?? '');

    if (!$ideaId) return ['success' => false, 'error' => 'idea_id is required'];
    if ($newContent === '') return ['success' => false, 'error' => 'Content cannot be empty'];
    if (!$userId) return ['success' => false, 'error' => 'Login required'];

    $stmt = $pdo->prepare("SELECT id, user_id, content, deleted_at, clerk_key FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) return ['success' => false, 'error' => 'Idea not found'];
    if ($idea['deleted_at']) return ['success' => false, 'error' => 'Cannot edit a deleted idea'];
    if ($idea['clerk_key']) return ['success' => false, 'error' => 'Cannot edit AI-generated content'];
    if ($idea['user_id'] && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only edit your own ideas'];
    }
    if ($idea['content'] === $newContent) {
        return ['success' => true, 'idea_id' => $ideaId, 'message' => 'No changes'];
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET content = ?, edit_count = edit_count + 1 WHERE id = ?");
    $stmt->execute([$newContent, $ideaId]);

    return ['success' => true, 'idea_id' => $ideaId, 'content' => $newContent, 'message' => 'Idea updated'];
}


// ── Delete Idea ──────────────────────────────────────────────────

function handleDelete($pdo, $input, $userId) {
    $ideaId = (int)($input['idea_id'] ?? 0);
    $hard   = (bool)($input['hard'] ?? false);

    if (!$ideaId) return ['success' => false, 'error' => 'idea_id is required'];
    if (!$userId) return ['success' => false, 'error' => 'Login required'];

    $stmt = $pdo->prepare("SELECT id, user_id, parent_id, deleted_at, clerk_key FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) return ['success' => false, 'error' => 'Idea not found'];
    if ($idea['deleted_at']) return ['success' => false, 'error' => 'Already deleted'];
    if ($idea['clerk_key']) {
        // AI content: allow owner to delete (check user_id, or parent's user_id for legacy rows)
        $isOwner = $idea['user_id'] && (int)$idea['user_id'] === $userId;
        if (!$isOwner && $idea['parent_id']) {
            $pStmt = $pdo->prepare("SELECT user_id FROM idea_log WHERE id = ?");
            $pStmt->execute([$idea['parent_id']]);
            $parent = $pStmt->fetch();
            $isOwner = $parent && $parent['user_id'] && (int)$parent['user_id'] === $userId;
        }
        if (!$isOwner) {
            return ['success' => false, 'error' => 'Cannot delete AI-generated content'];
        }
    } elseif ($idea['user_id'] && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only delete your own ideas'];
    }

    // Check if idea was gathered/crystallized
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_links WHERE idea_id_a = ? AND link_type = 'synthesizes'");
    $stmt->execute([$ideaId]);
    $hasSynthLinks = (int)$stmt->fetch()['cnt'] > 0;

    if ($hard) {
        if ($hasSynthLinks) {
            return ['success' => false, 'error' => 'Cannot permanently delete: this idea was used in a gather or crystallization'];
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_log WHERE parent_id = ?");
        $stmt->execute([$ideaId]);
        if ((int)$stmt->fetch()['cnt'] > 0) {
            return ['success' => false, 'error' => 'Cannot permanently delete: other ideas build on this one'];
        }

        $pdo->prepare("DELETE FROM idea_links WHERE idea_id_a = ? OR idea_id_b = ?")->execute([$ideaId, $ideaId]);
        $pdo->prepare("DELETE FROM idea_log WHERE id = ?")->execute([$ideaId]);

        return ['success' => true, 'idea_id' => $ideaId, 'action' => 'hard_deleted'];
    }

    // Soft delete
    $pdo->prepare("UPDATE idea_log SET deleted_at = NOW() WHERE id = ?")->execute([$ideaId]);

    return ['success' => true, 'idea_id' => $ideaId, 'action' => 'soft_deleted', 'had_links' => $hasSynthLinks];
}


// -- Brainstorm (AI-assisted) ------------------------------------------

function handleBrainstorm($pdo, $input, $userId) {
    $message   = trim($input['message'] ?? '');
    $history   = $input['history'] ?? [];
    $sessionId = $input['session_id'] ?? null;
    $shareable = (int)($input['shareable'] ?? 0);
    $groupId   = isset($input['group_id']) ? (int)$input['group_id'] : null;

    if ($message === '') {
        return ['success' => false, 'error' => 'Message is required'];
    }

    // Validate group membership if group_id provided
    if ($groupId) {
        if (!$userId) {
            return ['success' => false, 'error' => 'Login required for group brainstorming'];
        }
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$groupId, $userId]);
        $membership = $stmt->fetch();
        if (!$membership) {
            return ['success' => false, 'error' => 'You are not a member of this group'];
        }
        if ($membership['role'] === 'observer') {
            return ['success' => false, 'error' => 'Observers cannot brainstorm in a group'];
        }
    }

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'brainstorm');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Brainstorm clerk not available'];
    }

    $systemPrompt = buildClerkPrompt($pdo, $clerk, ['brainstorm', 'talk']);

    // User identity context
    $aiContext = buildAIContext($pdo, $dbUser);
    if ($aiContext['text']) {
        $systemPrompt .= "\n\n" . $aiContext['text'];
    }

    // Idea activity stats for personalization
    if ($userId) {
        $statsStmt = $pdo->prepare("
            SELECT COUNT(*) AS total_ideas,
                   MIN(created_at) AS first_idea_at,
                   GROUP_CONCAT(DISTINCT tags ORDER BY id DESC SEPARATOR ', ') AS all_tags
            FROM idea_log
            WHERE user_id = ? AND category != 'chat' AND deleted_at IS NULL
        ");
        $statsStmt->execute([$userId]);
        $stats = $statsStmt->fetch();

        if ($stats && (int)$stats['total_ideas'] > 0) {
            $systemPrompt .= "\n\n## Idea History\n";
            $systemPrompt .= "- Ideas saved: {$stats['total_ideas']}\n";
            $systemPrompt .= "- First idea: {$stats['first_idea_at']}\n";

            // Extract top tags (most frequent)
            if ($stats['all_tags']) {
                $tagList = array_map('trim', explode(',', $stats['all_tags']));
                $tagCounts = array_count_values($tagList);
                arsort($tagCounts);
                $topTags = implode(', ', array_slice(array_keys($tagCounts), 0, 8));
                $systemPrompt .= "- Top topics: {$topTags}\n";
            }
        }
    }

    // Context injection: group-aware or personal
    if ($groupId) {
        // Group context: shareable ideas from all group members
        $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if ($group) {
            $systemPrompt .= "\n\n## Group Context\n";
            $systemPrompt .= "You are brainstorming within the group \"{$group['name']}\".\n";
            if ($group['description']) $systemPrompt .= "Group purpose: {$group['description']}\n";
            $systemPrompt .= "When you see connections between this user's thoughts and other group members' shareable ideas, highlight them.\n";
        }

        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.category, i.tags,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
            FROM idea_log i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.group_id = ? AND i.deleted_at IS NULL
            ORDER BY i.created_at DESC LIMIT 30
        ");
        $stmt->execute([$groupId]);
        $groupIdeas = $stmt->fetchAll();
        foreach ($groupIdeas as &$gi) { $gi['author'] = getDisplayName($gi); }
        unset($gi);

        if ($groupIdeas) {
            $systemPrompt .= "\n## Shareable ideas from group members\n";
            foreach ($groupIdeas as $idea) {
                $systemPrompt .= "- #{$idea['id']} [{$idea['category']}] ({$idea['author']}): {$idea['content']}";
                if ($idea['tags']) $systemPrompt .= " (tags: {$idea['tags']})";
                $systemPrompt .= "\n";
            }
        }
    } elseif ($sessionId) {
        // Personal context: recent session ideas
        $stmt = $pdo->prepare("SELECT id, content, category, tags FROM idea_log WHERE session_id = ? AND category != 'chat' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 20");
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

    // Help mode: inject /talk knowledge so AI can answer questions about the system
    $helpMode = (int)($input['help_mode'] ?? 0);
    if ($helpMode) {
        $systemPrompt .= "\n\n## Help Mode\n";
        $systemPrompt .= "The user is asking about how /talk works. You are a helpful guide.\n";
        $systemPrompt .= "Answer clearly and concisely. Here's what you know:\n\n";
        $systemPrompt .= "**Quick Capture** (/talk/) — Fastest way to save a thought. Tap mic or type, pick a category (Idea, Decision, Todo, Note, Question), hit Save. 10 seconds.\n";
        $systemPrompt .= "**Brainstorm** (/talk/brainstorm.php) — Chat with AI. It asks follow-ups, captures ideas automatically. Group dropdown lets you brainstorm in group context. Shareable toggle shares thoughts with your groups.\n";
        $systemPrompt .= "**History** (/talk/history.php) — Review all your thoughts. Filter by category/status. Promote ideas through maturity stages: Raw → Refining → Distilled → Actionable. Share individual thoughts to groups.\n";
        $systemPrompt .= "**Groups** (/talk/groups.php) — Create groups around topics. Members brainstorm + share ideas. Facilitator runs Gather (AI finds connections) then Crystallize (AI produces structured proposal). Roles: Facilitator, Member, Observer.\n";
        $systemPrompt .= "**Accounts** — Free at /join.php. Without an account, thoughts are tied to your browser tab and disappear when you close it. With an account, everything is saved permanently.\n";
        $systemPrompt .= "**Privacy** — Thoughts are private by default. Only shared when you explicitly toggle Shareable or share from History. Only your group members see shared thoughts.\n";
        $systemPrompt .= "**Cost** — Free to users. Each AI session costs TPB about one cent.\n";
        $systemPrompt .= "**Non-partisan** — Serves all citizens. AI describes, doesn't editorialize.\n\n";
        $systemPrompt .= "If the user's question isn't about /talk, that's fine — help them with whatever they need, including brainstorming. But default to guide mode.\n";
    }

    $systemPrompt .= "\n\n" . getBrainstormActionInstructions();

    $messages = [];
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Memory tool: enabled for logged-in users
    $tools = null;
    $betaHeader = null;
    if ($userId) {
        $tools = [['type' => 'memory_20250818', 'name' => 'memory']];
        $betaHeader = 'context-management-2025-06-27';
    }

    $response = talkCallClaudeAPI($systemPrompt, $messages, $clerk['model'], false, $tools, $betaHeader);

    if (isset($response['error'])) {
        return ['success' => false, 'error' => $response['error']];
    }

    // Agentic loop: process memory tool calls until Claude gives final text
    $maxLoops = 6;
    $loopCount = 0;
    while (($response['stop_reason'] ?? '') === 'tool_use' && $loopCount < $maxLoops) {
        $loopCount++;
        // Append assistant response to messages
        $messages[] = ['role' => 'assistant', 'content' => $response['content']];

        // Process each tool_use block
        $toolResults = [];
        foreach ($response['content'] as $block) {
            if ($block['type'] === 'tool_use' && $block['name'] === 'memory') {
                $result = handleMemoryTool($pdo, $userId, $block['input']);
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content'     => $result
                ];
            }
        }

        if (empty($toolResults)) break;

        // Send tool results back
        $messages[] = ['role' => 'user', 'content' => $toolResults];
        $response = talkCallClaudeAPI($systemPrompt, $messages, $clerk['model'], false, $tools, $betaHeader);

        if (isset($response['error'])) {
            return ['success' => false, 'error' => $response['error']];
        }
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
            $result = processBrainstormAction($pdo, $action, $userId, $sessionId, $shareable, $groupId);
            if ($result) $actionResults[] = $result;
        }
    }

    $cleanMessage = cleanBrainstormActionTags($claudeMessage);
    logClerkInteraction($pdo, $clerk['clerk_id']);

    // Log the exchange as two separate nodes (Phase 3: AI as first-class entity)
    // 1. User message
    $userStmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, content, category, status, source, shareable, group_id)
        VALUES (:user_id, :session_id, :content, 'chat', 'raw', 'web', :shareable, :group_id)
    ");
    $userStmt->execute([
        ':user_id'    => $userId,
        ':session_id' => $sessionId,
        ':content'    => $message,
        ':shareable'  => $shareable,
        ':group_id'   => $groupId
    ]);
    $userRowId = (int)$pdo->lastInsertId();

    // 2. AI response as child node (inherits shareable + group from user message)
    $aiStmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, parent_id, content, category, status, source, shareable, clerk_key, group_id)
        VALUES (:user_id, :session_id, :parent_id, :content, 'chat', 'raw', 'clerk-brainstorm', :shareable, 'brainstorm', :group_id)
    ");
    $aiStmt->execute([
        ':user_id'    => $userId,
        ':session_id' => $sessionId,
        ':parent_id'  => $userRowId,
        ':content'    => $cleanMessage,
        ':shareable'  => $shareable,
        ':group_id'   => $groupId
    ]);
    $aiRowId = (int)$pdo->lastInsertId();

    return [
        'success'  => true,
        'response' => $cleanMessage,
        'actions'  => $actionResults,
        'clerk'    => $clerk['clerk_name'],
        'usage'    => $response['usage'] ?? null,
        'ai_idea'  => [
            'id'             => $aiRowId,
            'user_id'        => $userId,
            'content'        => $cleanMessage,
            'category'       => 'chat',
            'status'         => 'raw',
            'clerk_key'      => 'brainstorm',
            'group_id'       => $groupId,
            'created_at'     => date('Y-m-d H:i:s'),
            'author_display' => 'AI',
            'edit_count'     => 0
        ]
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

### Summarize the session
When the user asks to summarize, wrap up, or create a digest of the brainstorm:
[ACTION: SUMMARIZE]
content: {a clear, concise summary of the key ideas and themes from this session}
tags: {comma-separated tags covering the main themes}

The summary is automatically marked as shareable. Write it as if presenting to a group — clear, organized, non-partisan.

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

function processBrainstormAction($pdo, $action, $userId, $sessionId, $shareable = 0, $groupId = null) {
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

            $actionShareable = $groupId ? 1 : $shareable;
            $stmt = $pdo->prepare("
                INSERT INTO idea_log (user_id, session_id, content, category, status, tags, source, shareable, clerk_key, group_id)
                VALUES (:user_id, :session_id, :content, :category, 'raw', :tags, 'clerk-brainstorm', :shareable, 'brainstorm', :group_id)
            ");
            $stmt->execute([
                ':user_id'    => $userId,
                ':session_id' => $sessionId,
                ':content'    => $content,
                ':category'   => $category,
                ':tags'       => $tags,
                ':shareable'  => $actionShareable,
                ':group_id'   => $groupId
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

            $where  = ['deleted_at IS NULL'];
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

            $whereClause = 'WHERE ' . implode(' AND ', $where);
            $stmt = $pdo->prepare("SELECT id, content, category, status, tags, created_at FROM idea_log {$whereClause} ORDER BY created_at DESC LIMIT 20");
            $stmt->execute($params);
            $ideas = $stmt->fetchAll();

            return [
                'action'  => 'READ_BACK',
                'success' => true,
                'ideas'   => $ideas,
                'count'   => count($ideas)
            ];

        case 'SUMMARIZE':
            $content = trim($action['params']['content'] ?? '');
            $tags    = trim($action['params']['tags'] ?? '') ?: null;

            if ($content === '') {
                return ['action' => 'SUMMARIZE', 'success' => false, 'error' => 'Empty summary'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO idea_log (user_id, session_id, content, category, status, tags, source, shareable, clerk_key)
                VALUES (:user_id, :session_id, :content, 'digest', 'raw', :tags, 'clerk-brainstorm', 1, 'brainstorm')
            ");
            $stmt->execute([
                ':user_id'    => $userId,
                ':session_id' => $sessionId,
                ':content'    => $content,
                ':tags'       => $tags
            ]);

            $id = (int)$pdo->lastInsertId();
            return [
                'action'  => 'SUMMARIZE',
                'success' => true,
                'id'      => $id,
                'message' => "Digest #{$id} saved (shareable)"
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
function talkCallClaudeAPI($systemPrompt, $messages, $model = null, $enableWebSearch = false, $tools = null, $betaHeader = null) {
    if (!$model) $model = CLAUDE_MODEL;

    $data = [
        'model'      => $model,
        'max_tokens' => 1024,
        'system'     => $systemPrompt,
        'messages'   => $messages
    ];

    if ($tools) {
        $data['tools'] = $tools;
    }

    if ($enableWebSearch) {
        $data['tools'] = $data['tools'] ?? [];
        $data['tools'][] = [
            'type'     => 'web_search_20250305',
            'name'     => 'web_search',
            'max_uses' => 3
        ];
    }

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01'
    ];
    if ($betaHeader) {
        $headers[] = 'anthropic-beta: ' . $betaHeader;
    }

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $headers
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


// -- Memory tool handler (per-user, DB-backed) -------------------------

/**
 * Process a memory tool call from Claude.
 * Stores memory files per-user in user_ai_memory table.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param array $input  The tool_use input from Claude
 * @return string  Response text to send back as tool_result
 */
function handleMemoryTool($pdo, $userId, $input) {
    $command = $input['command'] ?? '';
    $path    = $input['path'] ?? '';

    // Security: all paths must start with /memories
    if ($path && !str_starts_with($path, '/memories')) {
        return "Error: All paths must start with /memories";
    }
    // Block traversal
    if ($path && (str_contains($path, '..') || str_contains($path, '%2e'))) {
        return "Error: Invalid path";
    }

    switch ($command) {
        case 'view':
            return memoryView($pdo, $userId, $path, $input['view_range'] ?? null);
        case 'create':
            return memoryCreate($pdo, $userId, $path, $input['file_text'] ?? '');
        case 'str_replace':
            return memoryStrReplace($pdo, $userId, $path, $input['old_str'] ?? '', $input['new_str'] ?? '');
        case 'insert':
            return memoryInsert($pdo, $userId, $path, (int)($input['insert_line'] ?? 0), $input['insert_text'] ?? '');
        case 'delete':
            return memoryDelete($pdo, $userId, $path);
        default:
            return "Error: Unknown memory command: {$command}";
    }
}

function memoryView($pdo, $userId, $path, $viewRange = null) {
    if ($path === '/memories' || $path === '/memories/') {
        // List directory
        $stmt = $pdo->prepare("SELECT file_path, LENGTH(content) AS size FROM user_ai_memory WHERE user_id = ? ORDER BY file_path");
        $stmt->execute([$userId]);
        $files = $stmt->fetchAll();

        $out = "Here're the files and directories up to 2 levels deep in /memories, excluding hidden items and node_modules:\n";
        $totalSize = 0;
        foreach ($files as $f) { $totalSize += $f['size']; }
        $out .= formatSize($totalSize) . "\t/memories\n";
        foreach ($files as $f) {
            $out .= formatSize($f['size']) . "\t" . $f['file_path'] . "\n";
        }
        return $out;
    }

    // View specific file
    $stmt = $pdo->prepare("SELECT content FROM user_ai_memory WHERE user_id = ? AND file_path = ?");
    $stmt->execute([$userId, $path]);
    $row = $stmt->fetch();
    if (!$row) {
        return "The path {$path} does not exist. Please provide a valid path.";
    }

    $lines = explode("\n", $row['content']);
    if ($viewRange && is_array($viewRange) && count($viewRange) === 2) {
        $start = max(1, $viewRange[0]);
        $end = min(count($lines), $viewRange[1]);
    } else {
        $start = 1;
        $end = count($lines);
    }

    $out = "Here's the content of {$path} with line numbers:\n";
    for ($i = $start; $i <= $end; $i++) {
        $out .= sprintf("%6d", $i) . "\t" . $lines[$i - 1] . "\n";
    }
    return $out;
}

function memoryCreate($pdo, $userId, $path, $fileText) {
    // Check if exists
    $stmt = $pdo->prepare("SELECT memory_id FROM user_ai_memory WHERE user_id = ? AND file_path = ?");
    $stmt->execute([$userId, $path]);
    if ($stmt->fetch()) {
        return "Error: File {$path} already exists";
    }

    $stmt = $pdo->prepare("INSERT INTO user_ai_memory (user_id, file_path, content) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $path, $fileText]);
    return "File created successfully at: {$path}";
}

function memoryStrReplace($pdo, $userId, $path, $oldStr, $newStr) {
    $stmt = $pdo->prepare("SELECT content FROM user_ai_memory WHERE user_id = ? AND file_path = ?");
    $stmt->execute([$userId, $path]);
    $row = $stmt->fetch();
    if (!$row) {
        return "Error: The path {$path} does not exist. Please provide a valid path.";
    }

    $content = $row['content'];
    $count = substr_count($content, $oldStr);
    if ($count === 0) {
        return "No replacement was performed, old_str `{$oldStr}` did not appear verbatim in {$path}.";
    }
    if ($count > 1) {
        // Find line numbers
        $lines = explode("\n", $content);
        $lineNums = [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, $oldStr)) $lineNums[] = $i + 1;
        }
        return "No replacement was performed. Multiple occurrences of old_str `{$oldStr}` in lines: " . implode(', ', $lineNums) . ". Please ensure it is unique";
    }

    $newContent = str_replace($oldStr, $newStr, $content);
    $pdo->prepare("UPDATE user_ai_memory SET content = ? WHERE user_id = ? AND file_path = ?")->execute([$newContent, $userId, $path]);
    return "The memory file has been edited.";
}

function memoryInsert($pdo, $userId, $path, $insertLine, $insertText) {
    $stmt = $pdo->prepare("SELECT content FROM user_ai_memory WHERE user_id = ? AND file_path = ?");
    $stmt->execute([$userId, $path]);
    $row = $stmt->fetch();
    if (!$row) {
        return "Error: The path {$path} does not exist";
    }

    $lines = explode("\n", $row['content']);
    if ($insertLine < 0 || $insertLine > count($lines)) {
        return "Error: Invalid `insert_line` parameter: {$insertLine}. It should be within the range of lines of the file: [0, " . count($lines) . "]";
    }

    array_splice($lines, $insertLine, 0, explode("\n", $insertText));
    $newContent = implode("\n", $lines);
    $pdo->prepare("UPDATE user_ai_memory SET content = ? WHERE user_id = ? AND file_path = ?")->execute([$newContent, $userId, $path]);
    return "The file {$path} has been edited.";
}

function memoryDelete($pdo, $userId, $path) {
    // Delete exact file or all files under a directory prefix
    $stmt = $pdo->prepare("DELETE FROM user_ai_memory WHERE user_id = ? AND (file_path = ? OR file_path LIKE ?)");
    $stmt->execute([$userId, $path, $path . '/%']);
    if ($stmt->rowCount() === 0) {
        return "Error: The path {$path} does not exist";
    }
    return "Successfully deleted {$path}";
}

function formatSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . 'K';
    return $bytes . 'B';
}


// ── Vote ──────────────────────────────────────────────────────────

function handleVote($pdo, $input, $userId, $dbUser = null) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Log in to vote'];
    }

    $ideaId = (int)($input['idea_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';

    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }
    if (!in_array($voteType, ['agree', 'disagree'])) {
        return ['success' => false, 'error' => 'vote_type must be "agree" or "disagree"'];
    }

    // Verify idea exists and is votable (not AI-generated, not digest/crystal)
    $stmt = $pdo->prepare("SELECT id, clerk_key, category, group_id FROM idea_log WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();
    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }
    if ($idea['clerk_key']) {
        return ['success' => false, 'error' => 'Cannot vote on AI-generated content'];
    }
    if ($idea['category'] === 'digest') {
        return ['success' => false, 'error' => 'Cannot vote on digests'];
    }

    // Check group-level vote permission for non-members
    if ($idea['group_id']) {
        $mStmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $mStmt->execute([$idea['group_id'], $userId]);
        if (!$mStmt->fetch()) {
            // Non-member: check if public_voting is enabled
            $gStmt = $pdo->prepare("SELECT public_voting FROM idea_groups WHERE id = ?");
            $gStmt->execute([$idea['group_id']]);
            $votingGroup = $gStmt->fetch();
            if (!$votingGroup || !$votingGroup['public_voting']) {
                return ['success' => false, 'error' => 'You must be a group member to vote on this idea'];
            }
            if (!$dbUser || (int)($dbUser['identity_level_id'] ?? 1) < 3) {
                return ['success' => false, 'error' => 'Verified account required to vote on public group ideas'];
            }
        }
    }

    // Check existing vote
    $stmt = $pdo->prepare("SELECT vote_id, vote_type FROM idea_votes WHERE idea_id = ? AND user_id = ?");
    $stmt->execute([$ideaId, $userId]);
    $existing = $stmt->fetch();

    $userVote = null;

    if ($existing) {
        if ($existing['vote_type'] === $voteType) {
            // Same vote — toggle off
            $pdo->prepare("DELETE FROM idea_votes WHERE vote_id = ?")->execute([$existing['vote_id']]);
            $col = $voteType === 'agree' ? 'agree_count' : 'disagree_count';
            $pdo->prepare("UPDATE idea_log SET {$col} = GREATEST({$col} - 1, 0) WHERE id = ?")->execute([$ideaId]);
            $userVote = null;
        } else {
            // Different vote — switch
            $pdo->prepare("UPDATE idea_votes SET vote_type = ?, voted_at = NOW() WHERE vote_id = ?")->execute([$voteType, $existing['vote_id']]);
            if ($voteType === 'agree') {
                $pdo->prepare("UPDATE idea_log SET agree_count = agree_count + 1, disagree_count = GREATEST(disagree_count - 1, 0) WHERE id = ?")->execute([$ideaId]);
            } else {
                $pdo->prepare("UPDATE idea_log SET disagree_count = disagree_count + 1, agree_count = GREATEST(agree_count - 1, 0) WHERE id = ?")->execute([$ideaId]);
            }
            $userVote = $voteType;
        }
    } else {
        // New vote
        $pdo->prepare("INSERT INTO idea_votes (idea_id, user_id, vote_type) VALUES (?, ?, ?)")->execute([$ideaId, $userId, $voteType]);
        $col = $voteType === 'agree' ? 'agree_count' : 'disagree_count';
        $pdo->prepare("UPDATE idea_log SET {$col} = {$col} + 1 WHERE id = ?")->execute([$ideaId]);
        $userVote = $voteType;
    }

    // Return updated counts
    $stmt = $pdo->prepare("SELECT agree_count, disagree_count FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $counts = $stmt->fetch();

    return [
        'success' => true,
        'idea_id' => $ideaId,
        'user_vote' => $userVote,
        'agree_count' => (int)$counts['agree_count'],
        'disagree_count' => (int)$counts['disagree_count']
    ];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 3: Idea Links
// ═══════════════════════════════════════════════════════════════════

function handleCreateLink($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $ideaIdA  = (int)($input['idea_id_a'] ?? 0);
    $ideaIdB  = (int)($input['idea_id_b'] ?? 0);
    $linkType = trim($input['link_type'] ?? 'related');

    if (!$ideaIdA || !$ideaIdB) {
        return ['success' => false, 'error' => 'idea_id_a and idea_id_b are required'];
    }
    if ($ideaIdA === $ideaIdB) {
        return ['success' => false, 'error' => 'Cannot link an idea to itself'];
    }

    $validTypes = ['related', 'supports', 'challenges', 'synthesizes', 'builds_on'];
    if (!in_array($linkType, $validTypes)) {
        return ['success' => false, 'error' => 'Invalid link_type. Valid: ' . implode(', ', $validTypes)];
    }

    // Verify both ideas exist
    $stmt = $pdo->prepare("SELECT id FROM idea_log WHERE id IN (?, ?)");
    $stmt->execute([$ideaIdA, $ideaIdB]);
    if ($stmt->rowCount() < 2) {
        return ['success' => false, 'error' => 'One or both ideas not found'];
    }

    // Normalize order (smaller ID first) to prevent duplicate reverse links
    if ($ideaIdA > $ideaIdB && $linkType === 'related') {
        [$ideaIdA, $ideaIdB] = [$ideaIdB, $ideaIdA];
    }

    $stmt = $pdo->prepare("
        INSERT INTO idea_links (idea_id_a, idea_id_b, link_type, created_by)
        VALUES (:a, :b, :type, :user)
        ON DUPLICATE KEY UPDATE id = id
    ");
    $stmt->execute([
        ':a'    => $ideaIdA,
        ':b'    => $ideaIdB,
        ':type' => $linkType,
        ':user' => $userId
    ]);

    return [
        'success' => true,
        'link_id' => (int)$pdo->lastInsertId(),
        'idea_id_a' => $ideaIdA,
        'idea_id_b' => $ideaIdB,
        'link_type' => $linkType
    ];
}

function handleGetLinks($pdo) {
    $ideaId = (int)($_GET['idea_id'] ?? 0);
    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }

    $stmt = $pdo->prepare("
        SELECT l.id, l.idea_id_a, l.idea_id_b, l.link_type, l.clerk_key, l.created_at,
               CASE WHEN l.idea_id_a = :id THEN l.idea_id_b ELSE l.idea_id_a END AS linked_idea_id,
               i.content AS linked_content, i.category AS linked_category, i.status AS linked_status,
               u.first_name AS created_by_name
        FROM idea_links l
        JOIN idea_log i ON i.id = CASE WHEN l.idea_id_a = :id2 THEN l.idea_id_b ELSE l.idea_id_a END
        LEFT JOIN users u ON l.created_by = u.user_id
        WHERE l.idea_id_a = :id3 OR l.idea_id_b = :id4
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([':id' => $ideaId, ':id2' => $ideaId, ':id3' => $ideaId, ':id4' => $ideaId]);

    return [
        'success' => true,
        'links'   => $stmt->fetchAll()
    ];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 3: Groups
// ═══════════════════════════════════════════════════════════════════

function handleCreateGroup($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $name        = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '') ?: null;
    $tags        = trim($input['tags'] ?? '') ?: null;
    $accessLevel = $input['access_level'] ?? 'observable';
    $parentId    = isset($input['parent_group_id']) ? (int)$input['parent_group_id'] : null;
    $scope       = $input['scope'] ?? null;
    $stateId     = isset($input['state_id']) ? (int)$input['state_id'] : null;
    $townId      = isset($input['town_id']) ? (int)$input['town_id'] : null;

    if ($name === '') {
        return ['success' => false, 'error' => 'Group name is required'];
    }
    if (strlen($name) > 100) {
        return ['success' => false, 'error' => 'Group name must be 100 characters or less'];
    }

    $validAccess = ['open', 'closed', 'observable'];
    if (!in_array($accessLevel, $validAccess)) {
        $accessLevel = 'observable';
    }

    $publicReadable = !empty($input['public_readable']) ? 1 : 0;
    $publicVoting   = !empty($input['public_voting']) ? 1 : 0;
    if ($publicVoting) $publicReadable = 1;

    // Validate scope
    $validScopes = ['town', 'state', 'federal'];
    if ($scope && !in_array($scope, $validScopes)) {
        return ['success' => false, 'error' => 'scope must be town, state, or federal'];
    }

    // Validate geo-scope consistency
    if ($scope === 'town' && !$townId) {
        return ['success' => false, 'error' => 'town_id is required for town-scoped groups'];
    }
    if ($scope === 'state' && !$stateId) {
        return ['success' => false, 'error' => 'state_id is required for state-scoped groups'];
    }
    if ($scope === 'town' && !$stateId) {
        // Look up state from town
        $stmt = $pdo->prepare("SELECT state_id FROM towns WHERE town_id = ?");
        $stmt->execute([$townId]);
        $townRow = $stmt->fetch();
        if (!$townRow) {
            return ['success' => false, 'error' => 'town_id not found'];
        }
        $stateId = (int)$townRow['state_id'];
    }
    if (!$scope) {
        $stateId = null;
        $townId = null;
    }

    // Validate parent group if provided
    if ($parentId) {
        $stmt = $pdo->prepare("
            SELECT g.id FROM idea_groups g
            JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ? AND m.role = 'facilitator' AND m.status = 'active'
            WHERE g.id = ?
        ");
        $stmt->execute([$userId, $parentId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Parent group not found or you are not a facilitator'];
        }
    }

    // Create the group
    $stmt = $pdo->prepare("
        INSERT INTO idea_groups (parent_group_id, scope, state_id, town_id, name, description, tags, access_level, public_readable, public_voting, created_by)
        VALUES (:parent, :scope, :state_id, :town_id, :name, :desc, :tags, :access, :pub_read, :pub_vote, :user)
    ");
    $stmt->execute([
        ':parent'   => $parentId,
        ':scope'    => $scope,
        ':state_id' => $stateId,
        ':town_id'  => $townId,
        ':name'     => $name,
        ':desc'     => $description,
        ':tags'     => $tags,
        ':access'   => $accessLevel,
        ':pub_read' => $publicReadable,
        ':pub_vote' => $publicVoting,
        ':user'     => $userId
    ]);
    $groupId = (int)$pdo->lastInsertId();

    // Creator becomes facilitator
    $stmt = $pdo->prepare("
        INSERT INTO idea_group_members (group_id, user_id, role) VALUES (?, ?, 'facilitator')
    ");
    $stmt->execute([$groupId, $userId]);

    return [
        'success'  => true,
        'group_id' => $groupId,
        'name'     => $name,
        'scope'    => $scope,
        'state_id' => $stateId,
        'town_id'  => $townId,
        'role'     => 'facilitator'
    ];
}

function handleListGroups($pdo, $userId) {
    $mine    = (bool)($_GET['mine'] ?? false);
    $scope   = $_GET['scope'] ?? null;
    $stateId = isset($_GET['state_id']) ? (int)$_GET['state_id'] : null;
    $townId  = isset($_GET['town_id']) ? (int)$_GET['town_id'] : null;

    if ($mine && !$userId) {
        return ['success' => false, 'error' => 'Login required for my groups'];
    }

    // Build geo-filter clause
    $geoWhere = '';
    $geoParams = [];
    if ($scope) {
        $geoWhere .= ' AND g.scope = ?';
        $geoParams[] = $scope;
    }
    if ($stateId) {
        $geoWhere .= ' AND g.state_id = ?';
        $geoParams[] = $stateId;
    }
    if ($townId) {
        $geoWhere .= ' AND g.town_id = ?';
        $geoParams[] = $townId;
    }

    // Resolve location names for display
    $locationSelect = ",
        s.state_name, s.abbreviation AS state_abbrev,
        tw.town_name,
        sc.description AS sic_description";
    $locationJoin = "
        LEFT JOIN states s ON g.state_id = s.state_id
        LEFT JOIN towns tw ON g.town_id = tw.town_id
        LEFT JOIN sic_codes sc ON g.sic_code = sc.sic_code";

    if ($mine) {
        $stmt = $pdo->prepare("
            SELECT g.*, m.role AS user_role,
                   (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id AND status = 'active') AS member_count
                   {$locationSelect}
            FROM idea_groups g
            JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ?
            {$locationJoin}
            WHERE 1=1 {$geoWhere}
            ORDER BY g.created_at DESC
        ");
        $stmt->execute(array_merge([$userId], $geoParams));
    } else {
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT g.*,
                       m.role AS user_role,
                       (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id AND status = 'active') AS member_count
                       {$locationSelect}
                FROM idea_groups g
                LEFT JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ?
                {$locationJoin}
                WHERE (g.access_level IN ('open', 'observable') OR m.user_id IS NOT NULL OR g.public_readable = 1) {$geoWhere}
                ORDER BY g.created_at DESC
            ");
            $stmt->execute(array_merge([$userId], $geoParams));
        } else {
            $stmt = $pdo->prepare("
                SELECT g.*, NULL AS user_role,
                       (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id AND status = 'active') AS member_count
                       {$locationSelect}
                FROM idea_groups g
                {$locationJoin}
                WHERE g.access_level IN ('open', 'observable') {$geoWhere}
                ORDER BY g.created_at DESC
            ");
            $stmt->execute($geoParams);
        }
    }

    $groups = $stmt->fetchAll();

    // Attach local department names for standard groups with template_id
    $templateIds = array_filter(array_column($groups, 'template_id'));
    if ($templateIds) {
        $deptPlaceholders = implode(',', array_fill(0, count($templateIds), '?'));
        // Build scope-appropriate WHERE clause
        if ($townId) {
            $deptWhere = 'town_id = ?';
            $deptParams = array_merge([$townId], $templateIds);
        } elseif ($stateId) {
            $deptWhere = 'state_id = ? AND town_id IS NULL';
            $deptParams = array_merge([$stateId], $templateIds);
        } else {
            $deptWhere = 'state_id IS NULL AND town_id IS NULL';
            $deptParams = $templateIds;
        }
        $deptStmt = $pdo->prepare("
            SELECT template_id, local_name, contact_url
            FROM town_department_map
            WHERE $deptWhere AND template_id IN ($deptPlaceholders)
            ORDER BY local_name
        ");
        $deptStmt->execute($deptParams);
        $deptMap = [];
        while ($d = $deptStmt->fetch(PDO::FETCH_ASSOC)) {
            $deptMap[$d['template_id']][] = [
                'name' => $d['local_name'],
                'url'  => $d['contact_url']
            ];
        }
        foreach ($groups as &$g) {
            if ($g['template_id'] && isset($deptMap[$g['template_id']])) {
                $g['local_departments'] = $deptMap[$g['template_id']];
            }
        }
        unset($g);
    }

    return [
        'success' => true,
        'groups'  => $groups
    ];
}

function handleGetGroup($pdo, $userId, $dbUser = null) {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Fetch group with location names
    $stmt = $pdo->prepare("
        SELECT g.*, s.state_name, s.abbreviation AS state_abbrev, tw.town_name
        FROM idea_groups g
        LEFT JOIN states s ON g.state_id = s.state_id
        LEFT JOIN towns tw ON g.town_id = tw.town_id
        WHERE g.id = ?
    ");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return ['success' => false, 'error' => 'Group not found'];
    }

    // Check access for closed groups
    $userRole = null;
    if ($userId) {
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$groupId, $userId]);
        $membership = $stmt->fetch();
        $userRole = $membership ? $membership['role'] : null;
    }

    $publicAccess = null;
    if ($group['access_level'] === 'closed' && !$userRole) {
        $publicAccess = getPublicAccess($group, $dbUser);
        if (!$publicAccess) {
            return ['success' => false, 'error' => 'This group is closed'];
        }
    }
    if (!$userRole && !$publicAccess) {
        $publicAccess = getPublicAccess($group, $dbUser);
    }

    // Members
    $stmt = $pdo->prepare("
        SELECT m.user_id, m.role, m.joined_at, m.status,
               u.first_name, u.last_name, u.username,
               u.show_first_name, u.show_last_name
        FROM idea_group_members m
        JOIN users u ON u.user_id = m.user_id
        WHERE m.group_id = ?
        ORDER BY m.role = 'facilitator' DESC, m.joined_at ASC
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll();

    // Compute display names respecting privacy flags
    foreach ($members as &$m) {
        $m['display_name'] = getDisplayName($m);
        // Strip raw name fields from response
        unset($m['first_name'], $m['last_name'], $m['username'], $m['show_first_name'], $m['show_last_name']);
    }
    unset($m);

    // Recent group ideas (scoped by group_id)
    $stmt = $pdo->prepare("
        SELECT i.id, i.content, i.category, i.status, i.tags, i.source, i.clerk_key,
               i.created_at, u.first_name, u.last_name, u.username AS user_username,
               u.show_first_name, u.show_last_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id AND c.deleted_at IS NULL) AS children_count,
               (SELECT COUNT(*) FROM idea_links l WHERE l.idea_id_a = i.id OR l.idea_id_b = i.id) AS link_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        WHERE i.group_id = ? AND i.deleted_at IS NULL
        ORDER BY i.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$groupId]);
    $ideas = $stmt->fetchAll();

    // Compute display names for idea authors
    foreach ($ideas as &$idea) {
        $idea['author_display'] = $idea['clerk_key'] ? 'AI' : getDisplayName($idea);
        unset($idea['first_name'], $idea['last_name'], $idea['user_username'], $idea['show_first_name'], $idea['show_last_name']);
    }
    unset($idea);

    // Sub-groups
    $stmt = $pdo->prepare("
        SELECT id, name, status, tags,
               (SELECT COUNT(*) FROM idea_group_members WHERE group_id = idea_groups.id AND status = 'active') AS member_count
        FROM idea_groups
        WHERE parent_group_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$groupId]);
    $subGroups = $stmt->fetchAll();

    return [
        'success'       => true,
        'group'         => $group,
        'user_role'     => $userRole,
        'public_access' => $publicAccess,
        'members'       => $members,
        'ideas'         => $ideas,
        'sub_groups'    => $subGroups
    ];
}

function handleJoinGroup($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Fetch group
    $stmt = $pdo->prepare("SELECT id, access_level, status FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return ['success' => false, 'error' => 'Group not found'];
    }

    if ($group['status'] === 'archived') {
        return ['success' => false, 'error' => 'Cannot join an archived group'];
    }

    // Determine role based on access level
    $role = 'member';
    if ($group['access_level'] === 'closed') {
        return ['success' => false, 'error' => 'This group is closed. Ask a facilitator to add you.'];
    }
    if ($group['access_level'] === 'observable') {
        $role = 'observer';
    }

    // Check if already a member
    $stmt = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'You are already in this group'];
    }

    $stmt = $pdo->prepare("INSERT INTO idea_group_members (group_id, user_id, role) VALUES (?, ?, ?)");
    $stmt->execute([$groupId, $userId, $role]);

    return [
        'success'  => true,
        'group_id' => $groupId,
        'role'     => $role
    ];
}

function handleLeaveGroup($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Check membership
    $stmt = $pdo->prepare("SELECT id, role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $membership = $stmt->fetch();

    if (!$membership) {
        return ['success' => false, 'error' => 'You are not in this group'];
    }

    // If leaving facilitator is the last one, promote oldest member
    if ($membership['role'] === 'facilitator') {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_group_members WHERE group_id = ? AND role = 'facilitator' AND status = 'active'");
        $stmt->execute([$groupId]);
        $facCount = (int)$stmt->fetch()['cnt'];

        if ($facCount === 1) {
            // Promote oldest non-facilitator member
            $stmt = $pdo->prepare("
                SELECT id FROM idea_group_members
                WHERE group_id = ? AND user_id != ? AND role != 'facilitator' AND status = 'active'
                ORDER BY joined_at ASC LIMIT 1
            ");
            $stmt->execute([$groupId, $userId]);
            $next = $stmt->fetch();
            if ($next) {
                $pdo->prepare("UPDATE idea_group_members SET role = 'facilitator' WHERE id = ?")->execute([$next['id']]);
            }
        }
    }

    $pdo->prepare("DELETE FROM idea_group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $userId]);

    return ['success' => true, 'group_id' => $groupId];
}

function handleUpdateGroup($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $membership = $stmt->fetch();

    if (!$membership || $membership['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can update group settings'];
    }

    // Fetch current group
    $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return ['success' => false, 'error' => 'Group not found'];
    }

    // Build update fields
    $updates = [];
    $params = [];

    if (isset($input['name']) && trim($input['name']) !== '') {
        $updates[] = 'name = ?';
        $params[] = trim($input['name']);
    }
    if (array_key_exists('description', $input)) {
        $updates[] = 'description = ?';
        $params[] = trim($input['description'] ?? '') ?: null;
    }
    if (array_key_exists('tags', $input)) {
        $updates[] = 'tags = ?';
        $params[] = trim($input['tags'] ?? '') ?: null;
    }
    if (isset($input['access_level'])) {
        $validAccess = ['open', 'closed', 'observable'];
        if (in_array($input['access_level'], $validAccess)) {
            $updates[] = 'access_level = ?';
            $params[] = $input['access_level'];
        }
    }
    if (array_key_exists('public_readable', $input)) {
        $updates[] = 'public_readable = ?';
        $params[] = !empty($input['public_readable']) ? 1 : 0;
    }
    if (array_key_exists('public_voting', $input)) {
        $pubVote = !empty($input['public_voting']) ? 1 : 0;
        $updates[] = 'public_voting = ?';
        $params[] = $pubVote;
        if ($pubVote && !in_array('public_readable = ?', $updates)) {
            $updates[] = 'public_readable = ?';
            $params[] = 1;
        }
    }
    if (isset($input['status'])) {
        $validStatuses = ['forming', 'active', 'crystallizing', 'crystallized', 'archived'];
        if (in_array($input['status'], $validStatuses)) {
            // Validate status transition
            $statusOrder = ['forming' => 0, 'active' => 1, 'crystallizing' => 2, 'crystallized' => 3, 'archived' => 99];
            $currentRank = $statusOrder[$group['status']] ?? 0;
            $newRank     = $statusOrder[$input['status']] ?? 0;

            if ($newRank > $currentRank || $input['status'] === 'archived' || $input['status'] === 'active') {
                $updates[] = 'status = ?';
                $params[] = $input['status'];
            } else {
                return ['success' => false, 'error' => 'Invalid status transition: ' . $group['status'] . ' → ' . $input['status']];
            }
        }
    }

    if (empty($updates)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }

    $params[] = $groupId;
    $sql = "UPDATE idea_groups SET " . implode(', ', $updates) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);

    // Return updated group
    $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);

    return [
        'success' => true,
        'group'   => $stmt->fetch()
    ];
}

function handleUpdateMember($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId   = (int)($input['group_id'] ?? 0);
    $targetId  = (int)($input['user_id'] ?? 0);
    $newRole   = $input['role'] ?? null;
    $newStatus = $input['status'] ?? null;
    $remove    = (bool)($input['remove'] ?? false);

    if (!$groupId || !$targetId) {
        return ['success' => false, 'error' => 'group_id and user_id are required'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $myRole = $stmt->fetch();
    if (!$myRole || $myRole['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can manage members'];
    }

    // Can't modify yourself through this endpoint
    if ($targetId === $userId) {
        return ['success' => false, 'error' => 'Use leave_group to manage your own membership'];
    }

    // Check target exists in group (including inactive)
    $stmt = $pdo->prepare("SELECT id, role, status FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        return ['success' => false, 'error' => 'User is not in this group'];
    }

    if ($remove) {
        $pdo->prepare("DELETE FROM idea_group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $targetId]);
        return ['success' => true, 'action' => 'removed', 'user_id' => $targetId];
    }

    if ($newStatus) {
        if (!in_array($newStatus, ['active', 'inactive'])) {
            return ['success' => false, 'error' => 'Invalid status. Valid: active, inactive'];
        }
        $pdo->prepare("UPDATE idea_group_members SET status = ? WHERE group_id = ? AND user_id = ?")->execute([$newStatus, $groupId, $targetId]);
        return ['success' => true, 'action' => 'status_changed', 'user_id' => $targetId, 'status' => $newStatus];
    }

    if ($newRole) {
        $validRoles = ['member', 'facilitator', 'observer'];
        if (!in_array($newRole, $validRoles)) {
            return ['success' => false, 'error' => 'Invalid role. Valid: ' . implode(', ', $validRoles)];
        }
        $pdo->prepare("UPDATE idea_group_members SET role = ? WHERE group_id = ? AND user_id = ?")->execute([$newRole, $groupId, $targetId]);
        return ['success' => true, 'action' => 'role_changed', 'user_id' => $targetId, 'role' => $newRole];
    }

    return ['success' => false, 'error' => 'Provide role, status, or remove=true'];
}


// ── Add Member (facilitator adds by username or email) ────────────

function handleAddMember($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId  = (int)($input['group_id'] ?? 0);
    $lookup   = trim($input['username'] ?? $input['email'] ?? '');
    $role     = $input['role'] ?? 'member';

    if (!$groupId || !$lookup) {
        return ['success' => false, 'error' => 'group_id and username or email required'];
    }

    $validRoles = ['member', 'facilitator', 'observer'];
    if (!in_array($role, $validRoles)) {
        return ['success' => false, 'error' => 'Invalid role'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $myRole = $stmt->fetch();
    if (!$myRole || $myRole['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can add members'];
    }

    // Find the target user by username or email
    $stmt = $pdo->prepare("SELECT user_id, username, first_name, last_name, show_first_name, show_last_name FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$lookup, $lookup]);
    $targetUser = $stmt->fetch();
    if (!$targetUser) {
        return ['success' => false, 'error' => 'User not found: ' . $lookup];
    }

    $targetId = (int)$targetUser['user_id'];

    // Check if already a member
    $stmt = $pdo->prepare("SELECT id, status FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $targetId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'active') {
            return ['success' => false, 'error' => 'Already an active member'];
        }
        // Reactivate inactive member
        $pdo->prepare("UPDATE idea_group_members SET status = 'active', role = ? WHERE group_id = ? AND user_id = ?")
            ->execute([$role, $groupId, $targetId]);
        return ['success' => true, 'action' => 'reactivated', 'user_id' => $targetId, 'role' => $role,
                'display_name' => getDisplayName($targetUser)];
    }

    // Insert new member
    $pdo->prepare("INSERT INTO idea_group_members (group_id, user_id, role, status) VALUES (?, ?, ?, 'active')")
        ->execute([$groupId, $targetId, $role]);
    return ['success' => true, 'action' => 'added', 'user_id' => $targetId, 'role' => $role,
            'display_name' => getDisplayName($targetUser)];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 4: Staleness Detection
// ═══════════════════════════════════════════════════════════════════

function handleCheckStaleness($pdo, $userId) {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $isPersonal = ($groupId === 0);

    if (!$isPersonal && !$groupId) return ['success' => false, 'error' => 'group_id is required'];
    if ($isPersonal && !$userId) return ['success' => false, 'error' => 'Login required'];

    // Find all digest nodes linked via synthesizes to this group's/user's ideas
    if ($isPersonal) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id AS digest_id, d.clerk_key, d.status, d.created_at AS digest_created_at
            FROM idea_log d
            JOIN idea_links l ON l.idea_id_b = d.id AND l.link_type = 'synthesizes'
            JOIN idea_log src ON src.id = l.idea_id_a AND src.group_id IS NULL AND src.user_id = ?
            WHERE d.category = 'digest'
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id AS digest_id, d.clerk_key, d.status, d.created_at AS digest_created_at
            FROM idea_log d
            JOIN idea_links l ON l.idea_id_b = d.id AND l.link_type = 'synthesizes'
            JOIN idea_log src ON src.id = l.idea_id_a AND src.group_id = ?
            WHERE d.category = 'digest'
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$groupId]);
    }
    $digests = $stmt->fetchAll();

    if (empty($digests)) {
        return ['success' => true, 'stale' => false, 'digests' => []];
    }

    // For each digest, check if source ideas changed after it was created
    $results = [];
    foreach ($digests as $digest) {
        $stmt = $pdo->prepare("
            SELECT src.id,
                   CASE
                       WHEN src.deleted_at IS NOT NULL AND src.deleted_at > ? THEN 'deleted'
                       WHEN src.edit_count > 0 AND src.updated_at > ? THEN 'edited'
                       ELSE 'unchanged'
                   END AS change_type
            FROM idea_links l
            JOIN idea_log src ON src.id = l.idea_id_a
            WHERE l.idea_id_b = ? AND l.link_type = 'synthesizes'
              AND (
                  (src.deleted_at IS NOT NULL AND src.deleted_at > ?)
                  OR (src.edit_count > 0 AND src.updated_at > ?)
              )
        ");
        $dc = $digest['digest_created_at'];
        $stmt->execute([$dc, $dc, $digest['digest_id'], $dc, $dc]);
        $changed = $stmt->fetchAll();

        $isStale = count($changed) > 0;
        $label = $digest['clerk_key'] === 'gatherer' ? 'gather' : 'crystallize';

        $results[] = [
            'digest_id'     => (int)$digest['digest_id'],
            'type'          => $label,
            'created_at'    => $digest['digest_created_at'],
            'is_stale'      => $isStale,
            'edited_count'  => count(array_filter($changed, fn($c) => $c['change_type'] === 'edited')),
            'deleted_count' => count(array_filter($changed, fn($c) => $c['change_type'] === 'deleted'))
        ];
    }

    $anyStale = array_reduce($results, fn($carry, $item) => $carry || $item['is_stale'], false);

    return ['success' => true, 'stale' => $anyStale, 'digests' => $results];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 3: Gatherer Clerk
// ═══════════════════════════════════════════════════════════════════

function handleGather($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    $isPersonal = ($groupId === 0); // 0 = personal ideas (null group)

    if ($isPersonal) {
        // Personal gathering — user's own ideas
        $group = ['name' => 'Personal Ideas', 'description' => 'Your personal idea collection', 'tags' => null];
    } else {
        // Group gathering — facilitator check
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$groupId, $userId]);
        $membership = $stmt->fetch();
        if (!$membership || $membership['role'] !== 'facilitator') {
            return ['success' => false, 'error' => 'Only facilitators can run the gatherer'];
        }

        // Fetch group info
        $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();
    }

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'gatherer');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Gatherer clerk not available'];
    }

    // Fetch ideas (exclude gatherer's own digests to prevent feedback loop)
    if ($isPersonal) {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.category, i.status, i.tags, i.created_at,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name,
                   i.clerk_key
            FROM idea_log i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.group_id IS NULL AND i.user_id = ? AND i.deleted_at IS NULL
              AND i.category != 'chat'
              AND (i.clerk_key IS NULL OR i.clerk_key != 'gatherer')
            ORDER BY i.created_at ASC
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.category, i.status, i.tags, i.created_at,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name,
                   i.clerk_key
            FROM idea_log i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.group_id = ? AND i.deleted_at IS NULL
              AND (i.clerk_key IS NULL OR i.clerk_key != 'gatherer')
            ORDER BY i.created_at ASC
        ");
        $stmt->execute([$groupId]);
    }
    $ideas = $stmt->fetchAll();
    foreach ($ideas as &$gi) { $gi['author'] = getDisplayName($gi); }
    unset($gi);

    if (count($ideas) < 2) {
        return ['success' => false, 'error' => 'Need at least 2 shareable ideas to gather'];
    }

    // Extract "re: #xxx" reply references and auto-create reply_to links
    $ideaIdSet = array_flip(array_column($ideas, 'id'));
    $replyLinks = [];
    foreach ($ideas as $idea) {
        if (preg_match_all('/\bre:\s*#(\d+)/i', $idea['content'], $matches)) {
            foreach ($matches[1] as $refId) {
                $refId = (int)$refId;
                if ($refId > 0 && isset($ideaIdSet[$refId]) && $refId !== $idea['id']) {
                    $replyLinks[] = ['from' => $idea['id'], 'to' => $refId];
                    // Auto-create the link in DB (idempotent)
                    $pdo->prepare("
                        INSERT INTO idea_links (idea_id_a, idea_id_b, link_type, created_by)
                        VALUES (?, ?, 'reply_to', ?)
                        ON DUPLICATE KEY UPDATE id = id
                    ")->execute([$idea['id'], $refId, $userId]);
                }
            }
        }
    }

    // Fetch existing links to avoid duplicates
    $ideaIds = array_column($ideas, 'id');
    $placeholders = implode(',', array_fill(0, count($ideaIds), '?'));
    $stmt = $pdo->prepare("
        SELECT idea_id_a, idea_id_b, link_type FROM idea_links
        WHERE idea_id_a IN ($placeholders) OR idea_id_b IN ($placeholders)
    ");
    $stmt->execute(array_merge($ideaIds, $ideaIds));
    $existingLinks = $stmt->fetchAll();

    // Fetch previous gatherer digests for this group (via synthesizes links to group member ideas)
    $stmt = $pdo->prepare("
        SELECT DISTINCT d.id, d.content, d.tags, d.created_at
        FROM idea_log d
        WHERE d.clerk_key = 'gatherer' AND d.category = 'digest'
          AND EXISTS (
              SELECT 1 FROM idea_links l
              WHERE l.idea_id_b = d.id AND l.link_type = 'synthesizes'
                AND l.idea_id_a IN ($placeholders)
          )
        ORDER BY d.created_at ASC
    ");
    $stmt->execute($ideaIds);
    $previousDigests = $stmt->fetchAll();

    // Find which idea IDs were already gathered (linked as sources to existing digests)
    $alreadyGatheredIds = [];
    if ($previousDigests) {
        $digestIds = array_column($previousDigests, 'id');
        $dPlaceholders = implode(',', array_fill(0, count($digestIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT idea_id_a FROM idea_links
            WHERE idea_id_b IN ($dPlaceholders) AND link_type = 'synthesizes'
        ");
        $stmt->execute($digestIds);
        $alreadyGatheredIds = array_column($stmt->fetchAll(), 'idea_id_a');
    }
    $alreadyGatheredSet = array_flip($alreadyGatheredIds);

    // Build system prompt
    $systemPrompt = buildClerkPrompt($pdo, $clerk, ['gatherer', 'talk']);
    $systemPrompt .= "\n\n## Group Context\n";
    $systemPrompt .= "Group: {$group['name']}\n";
    if ($group['description']) $systemPrompt .= "Purpose: {$group['description']}\n";

    // Show previous digests so gatherer builds on its own work
    if ($previousDigests) {
        $systemPrompt .= "\n## Your Previous Digests\n";
        $systemPrompt .= "You have gathered this group before. Here are your previous summaries:\n";
        foreach ($previousDigests as $d) {
            $systemPrompt .= "- Digest #{$d['id']}: {$d['content']}";
            if ($d['tags']) $systemPrompt .= " [tags: {$d['tags']}]";
            $systemPrompt .= "\n";
        }
        $systemPrompt .= "\nBuild on these — don't re-summarize the same clusters. Focus on NEW ideas and new connections.\n";
    }

    // Mark ideas as new vs already-gathered
    $newCount = 0;
    $systemPrompt .= "\n## Shareable Ideas from Group Members\n";
    foreach ($ideas as $idea) {
        $isNew = !isset($alreadyGatheredSet[$idea['id']]);
        if ($isNew) $newCount++;
        $marker = $isNew ? '🆕' : '';
        $systemPrompt .= "- #{$idea['id']} {$marker} [{$idea['category']}] ({$idea['author']}): {$idea['content']}";
        if ($idea['tags']) $systemPrompt .= " [tags: {$idea['tags']}]";
        $systemPrompt .= "\n";
    }

    if ($previousDigests && $newCount === 0) {
        return ['success' => false, 'error' => 'No new ideas since last gather'];
    }

    if ($replyLinks) {
        $systemPrompt .= "\n## Explicit Reply Connections (users linked these themselves)\n";
        $systemPrompt .= "These are direct replies — treat them as strong, confirmed connections:\n";
        foreach ($replyLinks as $rl) {
            $systemPrompt .= "- #{$rl['from']} replies to #{$rl['to']}\n";
        }
    }

    if ($existingLinks) {
        $systemPrompt .= "\n## Existing Links (do not duplicate)\n";
        foreach ($existingLinks as $link) {
            $systemPrompt .= "- #{$link['idea_id_a']} ←{$link['link_type']}→ #{$link['idea_id_b']}\n";
        }
    }

    $systemPrompt .= "\n\n" . getGathererActionInstructions();

    $userPrompt = $previousDigests
        ? "This is a follow-up gather. " . $newCount . " new ideas (marked 🆕) have been added since your last analysis. Focus on connections involving new ideas, and create new summaries only for clusters that include new material. You can also link new ideas to existing clusters if they fit."
        : 'Analyze these shareable ideas. Find thematic connections and create a summary of the key clusters.';

    $messages = [['role' => 'user', 'content' => $userPrompt]];

    $response = talkCallClaudeAPI($systemPrompt, $messages, $clerk['model'], false);

    if (isset($response['error'])) {
        return ['success' => false, 'error' => $response['error']];
    }

    $claudeMessage = '';
    foreach (($response['content'] ?? []) as $block) {
        if ($block['type'] === 'text') $claudeMessage .= $block['text'];
    }

    $actions = parseBrainstormActions($claudeMessage);
    $actionResults = [];
    foreach ($actions as $action) {
        $result = processGathererAction($pdo, $action, $userId, $groupId);
        if ($result) $actionResults[] = $result;
    }

    logClerkInteraction($pdo, $clerk['clerk_id']);

    return [
        'success'  => true,
        'actions'  => $actionResults,
        'analysis' => cleanBrainstormActionTags($claudeMessage),
        'usage'    => $response['usage'] ?? null
    ];
}

function getGathererActionInstructions() {
    return <<<'INSTRUCTIONS'
## Action Tags

After your analysis, include action tags to create links and summaries.

### Link two related ideas
[ACTION: LINK]
idea_id_a: {first idea number}
idea_id_b: {second idea number}
link_type: {related|supports|challenges|builds_on|reply_to}
reason: {brief explanation of the connection}

### Summarize a cluster of related ideas
[ACTION: SUMMARIZE]
content: {clear summary of the thematic cluster}
tags: {comma-separated theme tags}
source_ids: {comma-separated idea numbers that form this cluster}

## Rules
- Only link ideas that have genuine thematic connections.
- Do not duplicate existing links.
- Explicit reply connections (re: #xxx) are already linked — use them as anchors when clustering.
- Create one SUMMARIZE for each distinct cluster you identify.
- A cluster needs at least 2 ideas.
- Write summaries as if presenting to the group — clear, non-partisan, civic-focused.
INSTRUCTIONS;
}

function processGathererAction($pdo, $action, $userId, $groupId) {
    switch ($action['type']) {
        case 'LINK':
            $ideaIdA  = (int)($action['params']['idea_id_a'] ?? 0);
            $ideaIdB  = (int)($action['params']['idea_id_b'] ?? 0);
            $linkType = trim($action['params']['link_type'] ?? 'related');

            if (!$ideaIdA || !$ideaIdB || $ideaIdA === $ideaIdB) {
                return ['action' => 'LINK', 'success' => false, 'error' => 'Invalid idea IDs'];
            }

            $validTypes = ['related', 'supports', 'challenges', 'synthesizes', 'builds_on', 'reply_to'];
            if (!in_array($linkType, $validTypes)) $linkType = 'related';

            // Normalize order for symmetric types
            if ($ideaIdA > $ideaIdB && $linkType === 'related') {
                [$ideaIdA, $ideaIdB] = [$ideaIdB, $ideaIdA];
            }

            $stmt = $pdo->prepare("
                INSERT INTO idea_links (idea_id_a, idea_id_b, link_type, created_by, clerk_key)
                VALUES (?, ?, ?, ?, 'gatherer')
                ON DUPLICATE KEY UPDATE id = id
            ");
            $stmt->execute([$ideaIdA, $ideaIdB, $linkType, $userId]);

            return [
                'action'  => 'LINK',
                'success' => true,
                'idea_id_a' => $ideaIdA,
                'idea_id_b' => $ideaIdB,
                'link_type' => $linkType
            ];

        case 'SUMMARIZE':
            $content   = trim($action['params']['content'] ?? '');
            $tags      = trim($action['params']['tags'] ?? '') ?: null;
            $sourceIds = trim($action['params']['source_ids'] ?? '');

            if ($content === '') {
                return ['action' => 'SUMMARIZE', 'success' => false, 'error' => 'Empty summary'];
            }

            // Insert digest node
            $stmt = $pdo->prepare("
                INSERT INTO idea_log (user_id, content, category, status, tags, source, shareable, clerk_key, group_id)
                VALUES (:user_id, :content, 'digest', 'raw', :tags, 'clerk-gatherer', 1, 'gatherer', :group_id)
            ");
            $stmt->execute([
                ':user_id'  => $userId,
                ':content'  => $content,
                ':tags'     => $tags,
                ':group_id' => $groupId ?: null  // 0 → NULL for personal
            ]);
            $digestId = (int)$pdo->lastInsertId();

            // Create synthesizes links to source ideas
            if ($sourceIds) {
                $ids = array_map('intval', explode(',', $sourceIds));
                $linkStmt = $pdo->prepare("
                    INSERT INTO idea_links (idea_id_a, idea_id_b, link_type, clerk_key)
                    VALUES (?, ?, 'synthesizes', 'gatherer')
                    ON DUPLICATE KEY UPDATE id = id
                ");
                foreach ($ids as $srcId) {
                    if ($srcId > 0) {
                        $linkStmt->execute([$srcId, $digestId]);
                    }
                }
            }

            return [
                'action'  => 'SUMMARIZE',
                'success' => true,
                'id'      => $digestId,
                'message' => "Digest #{$digestId} created with " . count(explode(',', $sourceIds)) . " source ideas"
            ];

        default:
            return ['action' => $action['type'], 'success' => false, 'error' => 'Unknown gatherer action'];
    }
}


// ═══════════════════════════════════════════════════════════════════
// Phase 3: Crystallization
// ═══════════════════════════════════════════════════════════════════

function handleCrystallize($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    $isPersonal = ($groupId === 0);

    if ($isPersonal) {
        $group = ['name' => 'Personal Ideas', 'description' => 'Your personal idea collection', 'tags' => null, 'status' => 'active'];
    } else {
        // Facilitator check
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$groupId, $userId]);
        $membership = $stmt->fetch();
        if (!$membership || $membership['role'] !== 'facilitator') {
            return ['success' => false, 'error' => 'Only facilitators can crystallize'];
        }

        // Fetch group
        $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch();

        if (!$group) {
            return ['success' => false, 'error' => 'Group not found'];
        }
        if ($group['status'] === 'archived') {
            return ['success' => false, 'error' => 'Group is archived. Reopen it first to re-crystallize.'];
        }
        if ($group['status'] === 'forming') {
            return ['success' => false, 'error' => 'Group must be active before crystallizing.'];
        }

        // Update status to crystallizing
        $pdo->prepare("UPDATE idea_groups SET status = 'crystallizing' WHERE id = ?")->execute([$groupId]);
    }

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'brainstorm');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Brainstorm clerk not available'];
    }

    // Fetch ideas
    if ($isPersonal) {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.category, i.status, i.tags, i.created_at,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
            FROM idea_log i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.group_id IS NULL AND i.user_id = ? AND i.deleted_at IS NULL
              AND i.category != 'chat'
            ORDER BY i.created_at ASC
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.category, i.status, i.tags, i.created_at,
                   u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
            FROM idea_log i
            LEFT JOIN users u ON i.user_id = u.user_id
            WHERE i.group_id = ? AND i.deleted_at IS NULL
            ORDER BY i.created_at ASC
        ");
        $stmt->execute([$groupId]);
    }
    $ideas = $stmt->fetchAll();
    foreach ($ideas as &$gi) { $gi['author'] = getDisplayName($gi); }
    unset($gi);

    // Fetch existing digests (from gatherer) for this group
    if ($isPersonal) {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.tags FROM idea_log i
            WHERE i.clerk_key = 'gatherer' AND i.category = 'digest'
            AND EXISTS (
                SELECT 1 FROM idea_links l
                JOIN idea_log src ON src.id = l.idea_id_a
                WHERE l.idea_id_b = i.id AND l.link_type = 'synthesizes'
                  AND src.group_id IS NULL AND src.user_id = ?
            )
            ORDER BY i.created_at DESC LIMIT 10
        ");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT i.id, i.content, i.tags FROM idea_log i
            WHERE i.clerk_key = 'gatherer' AND i.category = 'digest'
            AND EXISTS (
                SELECT 1 FROM idea_links l
                JOIN idea_log src ON src.id = l.idea_id_a
                WHERE l.idea_id_b = i.id AND l.link_type = 'synthesizes'
                  AND src.group_id = ?
            )
            ORDER BY i.created_at DESC LIMIT 10
        ");
        $stmt->execute([$groupId]);
    }
    $digests = $stmt->fetchAll();

    // Fetch members (display names)
    if ($isPersonal) {
        // Personal mode — just the user
        $stmt = $pdo->prepare("SELECT first_name, last_name, username, show_first_name, show_last_name FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $memberNames = array_map('getDisplayName', $stmt->fetchAll());
    } else {
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
            FROM idea_group_members m
            JOIN users u ON u.user_id = m.user_id
            WHERE m.group_id = ? AND m.role != 'observer'
        ");
        $stmt->execute([$groupId]);
        $memberNames = array_map('getDisplayName', $stmt->fetchAll());
    }

    // Fetch previous crystallization digests (our own prior output for this group)
    $prevCrystallizations = [];
    $ideaIds = array_column($ideas, 'id');
    if ($ideaIds) {
        $placeholders = implode(',', array_fill(0, count($ideaIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.id, d.content, d.created_at
            FROM idea_log d
            WHERE d.clerk_key = 'brainstorm' AND d.category = 'digest' AND d.status = 'distilled'
              AND EXISTS (
                  SELECT 1 FROM idea_links l
                  WHERE l.idea_id_b = d.id AND l.link_type = 'synthesizes'
                    AND l.idea_id_a IN ($placeholders)
              )
            ORDER BY d.created_at DESC LIMIT 3
        ");
        $stmt->execute($ideaIds);
        $prevCrystallizations = $stmt->fetchAll();
    }
    $isRecrystallize = !empty($prevCrystallizations);

    // Compute metrics for weighting at parent/state level
    $uniqueAuthors = array_unique(array_filter(array_column($ideas, 'author')));
    $linkCount = 0;
    if ($ideaIds) {
        $placeholders2 = implode(',', array_fill(0, count($ideaIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_links WHERE idea_id_a IN ($placeholders2) OR idea_id_b IN ($placeholders2)");
        $stmt->execute(array_merge($ideaIds, $ideaIds));
        $linkCount = (int)$stmt->fetch()['cnt'];
    }
    $subGroupCount = 0;
    if (!$isPersonal) {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_groups WHERE parent_group_id = ?");
        $stmt->execute([$groupId]);
        $subGroupCount = (int)$stmt->fetch()['cnt'];
    }

    $metrics = [
        'contributors'  => count($uniqueAuthors),
        'ideas'         => count($ideas),
        'digests'       => count($digests),
        'links'         => $linkCount,
        'members'       => count($memberNames),
        'sub_groups'    => $subGroupCount,
        'crystallized_at' => date('Y-m-d H:i:s'),
        'group_id'      => $groupId ?: null,
        'group_name'    => $group['name']
    ];

    // Build crystallization prompt
    $systemPrompt = buildClerkPrompt($pdo, $clerk, ['brainstorm', 'talk']);
    $systemPrompt .= "\n\n## Crystallization Task\n";
    $systemPrompt .= "You are producing a structured proposal for the group \"{$group['name']}\".\n";
    if ($group['description']) $systemPrompt .= "Group purpose: {$group['description']}\n";
    $systemPrompt .= "Contributing members: " . implode(', ', $memberNames) . "\n";
    $systemPrompt .= "Metrics: {$metrics['contributors']} contributors, {$metrics['ideas']} ideas, {$metrics['links']} connections, {$metrics['digests']} digests\n";

    $systemPrompt .= "\n## All Shareable Ideas\n";
    foreach ($ideas as $idea) {
        $systemPrompt .= "- #{$idea['id']} [{$idea['category']}] ({$idea['author']}): {$idea['content']}";
        if ($idea['tags']) $systemPrompt .= " [tags: {$idea['tags']}]";
        $systemPrompt .= "\n";
    }

    if ($digests) {
        $systemPrompt .= "\n## Existing Digests (from gatherer analysis)\n";
        foreach ($digests as $d) {
            $systemPrompt .= "- Digest #{$d['id']}: {$d['content']}\n";
        }
    }

    // Fetch crystallized child group proposals (for state-level aggregation)
    $childCrystallizations = [];
    if ($subGroupCount > 0) {
        $stmt = $pdo->prepare("
            SELECT g.id AS group_id, g.name AS group_name, g.tags,
                   i.id AS idea_id, i.content, i.created_at
            FROM idea_groups g
            JOIN idea_log i ON i.clerk_key = 'brainstorm' AND i.category = 'digest' AND i.status = 'distilled'
            WHERE g.parent_group_id = ? AND g.status IN ('crystallized', 'archived')
              AND EXISTS (
                  SELECT 1 FROM idea_links l
                  JOIN idea_log src ON src.id = l.idea_id_a
                  WHERE l.idea_id_b = i.id AND l.link_type = 'synthesizes'
                    AND src.group_id = g.id
              )
            ORDER BY i.created_at DESC
        ");
        $stmt->execute([$groupId]);
        $childRows = $stmt->fetchAll();
        // Group by child group, take most recent crystallization per group
        $seen = [];
        foreach ($childRows as $row) {
            if (!isset($seen[$row['group_id']])) {
                $seen[$row['group_id']] = true;
                $childCrystallizations[] = $row;
            }
        }
    }

    if ($prevCrystallizations) {
        $systemPrompt .= "\n## Previous Crystallization Draft\n";
        $systemPrompt .= "This group has been crystallized before. Here is the most recent draft:\n\n";
        $systemPrompt .= $prevCrystallizations[0]['content'] . "\n\n";
        $systemPrompt .= "Improve on this draft — incorporate any new ideas, fix any gaps, and produce a better version.\n";
    }

    if ($childCrystallizations) {
        $systemPrompt .= "\n## Child Group Proposals\n";
        $systemPrompt .= "This is a parent-level group. The following sub-groups have produced crystallized proposals.\n";
        $systemPrompt .= "Weight each sub-group's input by its metrics (contributors, ideas) — a proposal backed by 30 people carries more weight than one from 3.\n\n";
        foreach ($childCrystallizations as $cc) {
            $systemPrompt .= "### {$cc['group_name']}\n";
            $systemPrompt .= $cc['content'] . "\n\n";
        }
    }

    $userPrompt = $isRecrystallize
        ? "Re-crystallize this group. Build on the previous draft, incorporating any new ideas and improving the proposal."
        : "Produce a new structured proposal from scratch.";

    if ($childCrystallizations) {
        $userPrompt .= " This is a parent-level synthesis — integrate the child group proposals, weighting by their contributor/idea counts.";
    }

    $metricsLine = "Contributors: {$metrics['contributors']} | Ideas: {$metrics['ideas']} | Connections: {$metrics['links']} | Digests: {$metrics['digests']} | Members: {$metrics['members']}";

    $messages = [['role' => 'user', 'content' => <<<PROMPT
{$userPrompt}

Use this markdown format:

# Proposal: {$group['name']}

## Summary
(2-3 sentence overview of what the group concluded)

## Key Findings
(Bullet points of main insights, attributed to contributors)

## Proposed Actions
(Numbered list of concrete next steps)

## Contributing Thoughts
(List each source idea with attribution: "- #ID Author: quote")

## Sources
(Any external references mentioned in the ideas)

## Metrics
{$metricsLine}

Write it as a real civic proposal — clear, non-partisan, actionable. Attribute every claim to the person who said it.
The Metrics section MUST be included exactly as shown — it is machine-readable for state-level aggregation.
PROMPT]];

    $response = talkCallClaudeAPI($systemPrompt, $messages, $clerk['model'], false);

    if (isset($response['error'])) {
        // Revert status
        if (!$isPersonal) {
            $pdo->prepare("UPDATE idea_groups SET status = 'active' WHERE id = ?")->execute([$groupId]);
        }
        return ['success' => false, 'error' => $response['error']];
    }

    $markdown = '';
    foreach (($response['content'] ?? []) as $block) {
        if ($block['type'] === 'text') $markdown .= $block['text'];
    }

    // Save digest to idea_log
    $stmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, content, category, status, tags, source, shareable, clerk_key, group_id)
        VALUES (:user_id, :content, 'digest', 'distilled', :tags, 'clerk-brainstorm', 1, 'brainstorm', :group_id)
    ");
    $stmt->execute([
        ':user_id'  => $userId,
        ':content'  => $markdown,
        ':tags'     => $group['tags'],
        ':group_id' => $groupId ?: null
    ]);
    $digestId = (int)$pdo->lastInsertId();

    // Create synthesizes links to all source ideas
    $linkStmt = $pdo->prepare("
        INSERT INTO idea_links (idea_id_a, idea_id_b, link_type, clerk_key)
        VALUES (?, ?, 'synthesizes', 'brainstorm')
        ON DUPLICATE KEY UPDATE id = id
    ");
    foreach ($ideas as $idea) {
        $linkStmt->execute([(int)$idea['id'], $digestId]);
    }

    // Append machine-readable metrics block to markdown
    $metricsYaml = "\n\n---\n<!-- METRICS\n";
    foreach ($metrics as $k => $v) {
        $metricsYaml .= "$k: $v\n";
    }
    $metricsYaml .= "-->\n";
    $markdownWithMetrics = $markdown . $metricsYaml;

    // Write .md file (versioned + latest copy)
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($group['name']));
    $slug = trim($slug, '-');
    $filePrefix = $isPersonal ? "personal-u{$userId}" : "group-{$groupId}";

    // Compute version number by counting existing versioned files
    $existingVersions = glob($outputDir . "/{$filePrefix}-*-v*-u*.md");
    $existingVersions = array_filter($existingVersions, function($f) {
        return !str_ends_with(basename($f), '-latest.md');
    });
    $version = count($existingVersions) + 1;

    $timestamp = date('Y-m-d-His');
    $versionedFile = "{$filePrefix}-{$slug}-v{$version}-u{$userId}-{$timestamp}.md";
    $latestFile = "{$filePrefix}-{$slug}-latest.md";
    file_put_contents($outputDir . '/' . $versionedFile, $markdownWithMetrics);
    file_put_contents($outputDir . '/' . $latestFile, $markdownWithMetrics);
    $filePath = "output/{$latestFile}";

    if (!$isPersonal) {
        // Update group status to crystallized
        $pdo->prepare("UPDATE idea_groups SET status = 'crystallized' WHERE id = ?")->execute([$groupId]);

        // If parent group exists, insert digest as shareable idea in parent context
        if ($group['parent_group_id'] ?? null) {
            // The digest is already shareable and linked to the user who triggered it
            // It will appear in parent group's feed through membership
        }
    }

    logClerkInteraction($pdo, $clerk['clerk_id']);

    return [
        'success'   => true,
        'idea_id'   => $digestId,
        'file_path' => $filePath,
        'markdown'  => $markdown,
        'metrics'   => $metrics,
        'usage'     => $response['usage'] ?? null
    ];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 5: Group Invites
// ═══════════════════════════════════════════════════════════════════

function handleInviteToGroup($pdo, $input, $userId, $config) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($input['group_id'] ?? 0);
    $emailsRaw = trim($input['emails'] ?? '');

    if (!$groupId || !$emailsRaw) {
        return ['success' => false, 'error' => 'group_id and emails are required'];
    }

    // Verify caller is facilitator
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $myRole = $stmt->fetch();
    if (!$myRole || $myRole['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can send invites'];
    }

    // Check group exists and is not archived
    $stmt = $pdo->prepare("SELECT id, name, description, status FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        return ['success' => false, 'error' => 'Group not found'];
    }
    if ($group['status'] === 'archived') {
        return ['success' => false, 'error' => 'Cannot invite to an archived group'];
    }

    // Get facilitator display name for the email
    $stmt = $pdo->prepare("SELECT first_name, last_name, username, show_first_name, show_last_name FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $facilitator = $stmt->fetch();
    $facilitatorName = getDisplayName($facilitator);

    // Parse emails (comma, newline, or semicolon separated)
    $emails = preg_split('/[\s,;]+/', $emailsRaw, -1, PREG_SPLIT_NO_EMPTY);
    $emails = array_unique(array_map('strtolower', array_map('trim', $emails)));

    $results = [];
    $invitedCount = 0;
    $errorCount = 0;
    $baseUrl = $config['base_url'];
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    foreach ($emails as $email) {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $results[] = ['email' => $email, 'status' => 'invalid_email'];
            $errorCount++;
            continue;
        }

        // Look up user by email with verified status
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email
            FROM users u
            JOIN user_identity_status uis ON uis.user_id = u.user_id
            WHERE LOWER(u.email) = ? AND uis.email_verified = 1
        ");
        $stmt->execute([$email]);
        $invitee = $stmt->fetch();

        $inviteeId = null;
        $isNewUser = false;

        if (!$invitee) {
            // Check if user exists but isn't verified
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            $unverified = $stmt->fetch();
            if ($unverified) {
                $results[] = ['email' => $email, 'status' => 'not_verified'];
                $errorCount++;
                continue;
            }
            // No account — invite anyway (will auto-create on accept)
            $isNewUser = true;
        } else {
            $inviteeId = (int)$invitee['user_id'];

            // Check if already a member
            $stmt = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$groupId, $inviteeId]);
            if ($stmt->fetch()) {
                $results[] = ['email' => $email, 'status' => 'already_member'];
                $errorCount++;
                continue;
            }
        }

        // Check for existing pending invite (by user_id or email)
        if ($inviteeId) {
            $stmt = $pdo->prepare("
                SELECT id FROM group_invites
                WHERE group_id = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW()
            ");
            $stmt->execute([$groupId, $inviteeId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id FROM group_invites
                WHERE group_id = ? AND LOWER(email) = ? AND status = 'pending' AND expires_at > NOW()
            ");
            $stmt->execute([$groupId, $email]);
        }
        if ($stmt->fetch()) {
            $results[] = ['email' => $email, 'status' => 'already_invited'];
            $errorCount++;
            continue;
        }

        // Generate tokens
        $acceptToken = bin2hex(random_bytes(32));
        $declineToken = bin2hex(random_bytes(32));

        // Insert invite
        $stmt = $pdo->prepare("
            INSERT INTO group_invites (group_id, user_id, invited_by, email, accept_token, decline_token, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$groupId, $inviteeId, $userId, $email, $acceptToken, $declineToken, $expiresAt]);

        // Build email
        $acceptUrl = "{$baseUrl}/talk/groups.php?invite_action=accept&token={$acceptToken}";
        $declineUrl = "{$baseUrl}/talk/groups.php?invite_action=decline&token={$declineToken}";
        $groupName = htmlspecialchars($group['name']);
        $groupDesc = htmlspecialchars($group['description'] ?? '');
        $facName = htmlspecialchars($facilitatorName);

        $subject = "You're invited to join \"{$group['name']}\" on The People's Branch";

        $descBlock = $groupDesc
            ? "<p style='color: #444; background: #f5f5f5; padding: 12px 16px; border-left: 3px solid #1a3a5c; border-radius: 4px; font-size: 0.95em; margin: 16px 0;'>{$groupDesc}</p>"
            : "";

        $htmlBody = "
        <div style='font-family: Georgia, serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
            <h2 style='color: #1a3a5c;'>You're Invited!</h2>
            <p><strong>{$facName}</strong> has invited you to join the group <strong>\"{$groupName}\"</strong> on The People's Branch.</p>
            {$descBlock}
            <p>Would you like to join this group?</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$acceptUrl}' style='background: #2e7d32; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; margin-right: 15px;'>
                    Yes, I'll Join
                </a>
                &nbsp;&nbsp;
                <a href='{$declineUrl}' style='background: #757575; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                    No Thanks
                </a>
            </div>
            " . ($isNewUser
                ? "<p style='color: #2e7d32; font-size: 0.9em;'><strong>New to TPB?</strong> Clicking \"Yes, I'll Join\" will automatically create your free account — no extra steps needed.</p>"
                : "<p style='color: #444; font-size: 0.9em;'>You can also <a href='{$baseUrl}/login.php' style='color: #1a3a5c;'>log in</a> to manage your groups.</p>"
            ) . "
            <p style='color: #666; font-size: 0.9em;'>This invitation expires in 7 days.</p>
            <p style='color: #666; font-size: 0.9em;'>If you didn't expect this, you can safely ignore this email.</p>
            <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
            <p style='color: #888; font-size: 0.85em;'><a href='{$baseUrl}/talk/help.php' style='color: #1a3a5c;'>Learn more about TPB Groups</a></p>
            <p style='color: #888; font-size: 0.85em;'>The People's Branch &mdash; Your voice, aggregated</p>
        </div>
        ";

        $mailSent = sendSmtpMail($config, $email, $subject, $htmlBody, null, true);

        $results[] = ['email' => $email, 'status' => 'invited', 'mail_sent' => $mailSent, 'new_user' => $isNewUser];
        $invitedCount++;
    }

    return [
        'success' => true,
        'results' => $results,
        'invited_count' => $invitedCount,
        'error_count' => $errorCount
    ];
}

function handleGetInvites($pdo, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Login required'];
    }

    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Verify caller is member or facilitator (not observer)
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$groupId, $userId]);
    $myRole = $stmt->fetch();
    if (!$myRole || $myRole['role'] === 'observer') {
        return ['success' => false, 'error' => 'Members and facilitators only'];
    }

    // Batch-expire stale invites
    $pdo->prepare("
        UPDATE group_invites SET status = 'expired'
        WHERE group_id = ? AND status = 'pending' AND expires_at < NOW()
    ")->execute([$groupId]);

    // Fetch invites with inviter display name
    $stmt = $pdo->prepare("
        SELECT gi.id, gi.email, gi.status, gi.created_at, gi.responded_at, gi.expires_at,
               u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
        FROM group_invites gi
        JOIN users u ON u.user_id = gi.invited_by
        WHERE gi.group_id = ?
        ORDER BY gi.created_at DESC
    ");
    $stmt->execute([$groupId]);
    $invites = $stmt->fetchAll();

    foreach ($invites as &$inv) {
        $inv['invited_by_name'] = getDisplayName($inv);
        unset($inv['first_name'], $inv['last_name'], $inv['username'], $inv['show_first_name'], $inv['show_last_name']);
    }
    unset($inv);

    return [
        'success' => true,
        'invites' => $invites
    ];
}


// ── Phase 8: Auto-Create Standard Groups ──────────────────────────

function handleAutoCreateStandardGroups($pdo, $input, $userId) {
    $scope   = $input['scope']    ?? $_GET['scope']    ?? null;
    $stateId = $input['state_id'] ?? $_GET['state_id'] ?? null;
    $townId  = $input['town_id']  ?? $_GET['town_id']  ?? null;

    if ($stateId) $stateId = (int)$stateId;
    if ($townId)  $townId  = (int)$townId;

    // Determine scope from geo params if not explicit
    if (!$scope) {
        if ($townId)       $scope = 'town';
        elseif ($stateId)  $scope = 'state';
        else               $scope = 'federal';
    }

    // Federal groups are pre-seeded with proper names (not template-derived)
    // They don't cascade from town/state templates — see scripts/db/redo-federal-groups.php
    if ($scope === 'federal') {
        $count = $pdo->query("SELECT COUNT(*) FROM idea_groups WHERE is_standard = 1 AND scope = 'federal'")->fetchColumn();
        return ['success' => true, 'created' => 0, 'scope' => 'federal', 'message' => "Federal groups are pre-seeded ($count exist)"];
    }

    // Map scope to template filter: town gets town-level, state gets town+state
    $scopeFilter = ['town'];
    if ($scope === 'state') $scopeFilter = ['town', 'state'];

    $placeholders = implode(',', array_fill(0, count($scopeFilter), '?'));
    $stmt = $pdo->prepare("SELECT id, name, sic_codes, min_scope, sort_order FROM standard_group_templates WHERE min_scope IN ($placeholders) ORDER BY sort_order");
    $stmt->execute($scopeFilter);
    $templates = $stmt->fetchAll();

    $created = 0;
    foreach ($templates as $tpl) {
        // Check if standard group already exists for this template+scope+geo
        $checkSql = "SELECT id FROM idea_groups WHERE is_standard = 1 AND template_id = ? AND scope = ?";
        $checkParams = [$tpl['id'], $scope];

        if ($stateId) {
            $checkSql .= " AND state_id = ?";
            $checkParams[] = $stateId;
        } else {
            $checkSql .= " AND state_id IS NULL";
        }
        if ($townId) {
            $checkSql .= " AND town_id = ?";
            $checkParams[] = $townId;
        } else {
            $checkSql .= " AND town_id IS NULL";
        }

        $check = $pdo->prepare($checkSql);
        $check->execute($checkParams);
        if ($check->fetch()) continue;

        // Create the standard group from template
        $ins = $pdo->prepare("
            INSERT INTO idea_groups (name, description, access_level, scope, state_id, town_id, sic_code, is_standard, template_id, created_by, public_readable, public_voting)
            VALUES (?, ?, 'open', ?, ?, ?, ?, 1, ?, NULL, 1, 1)
        ");
        $ins->execute([
            $tpl['name'],
            'Standard civic topic: ' . $tpl['name'],
            $scope,
            $stateId,
            $townId,
            $tpl['sic_codes'],
            $tpl['id']
        ]);
        $created++;
    }

    return [
        'success' => true,
        'created' => $created,
        'scope'   => $scope,
        'state_id' => $stateId,
        'town_id'  => $townId
    ];
}


// ── Phase 8: Access Status ────────────────────────────────────────

function handleGetAccessStatus($dbUser) {
    if (!$dbUser) {
        return [
            'success'  => true,
            'can_post'  => false,
            'needs'     => 'verify_email',
            'identity_level' => 0,
            'has_location'   => false
        ];
    }

    $level = (int)($dbUser['identity_level_id'] ?? 1);
    $hasLocation = !empty($dbUser['current_state_id']);

    if ($level < 2) {
        return [
            'success'  => true,
            'can_post'  => false,
            'needs'     => 'verify_email',
            'identity_level' => $level,
            'has_location'   => $hasLocation
        ];
    }

    if (!$hasLocation) {
        return [
            'success'  => true,
            'can_post'  => false,
            'needs'     => 'set_location',
            'identity_level' => $level,
            'has_location'   => false
        ];
    }

    return [
        'success'  => true,
        'can_post'  => true,
        'needs'     => null,
        'identity_level' => $level,
        'has_location'   => true,
        'state_id' => (int)$dbUser['current_state_id'],
        'town_id'  => $dbUser['current_town_id'] ? (int)$dbUser['current_town_id'] : null
    ];
}
