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
 *   update_member     — POST: Change member role or remove member (Phase 3)
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
            echo json_encode(handleGetGroup($pdo, $userId));
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

        // Phase 5: Group Invites
        case 'invite_to_group':
            echo json_encode(handleInviteToGroup($pdo, $input, $userId, $config));
            break;
        case 'get_invites':
            echo json_encode(handleGetInvites($pdo, $userId));
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
        $gStmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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

    $stmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, parent_id, content, category, status, tags, source, shareable, clerk_key, group_id)
        VALUES (:user_id, :session_id, :parent_id, :content, :category, 'raw', :tags, :source, :shareable, :clerk_key, :group_id)
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
        ':group_id'   => $groupId
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

    $where = ['i.deleted_at IS NULL'];
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
               i.shareable, i.clerk_key,
               i.created_at, i.updated_at,
               u.first_name AS user_first_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id AND c.deleted_at IS NULL) AS children_count
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

    $stmt = $pdo->prepare("SELECT id, user_id, deleted_at, clerk_key FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) return ['success' => false, 'error' => 'Idea not found'];
    if ($idea['deleted_at']) return ['success' => false, 'error' => 'Already deleted'];
    if ($idea['clerk_key']) return ['success' => false, 'error' => 'Cannot delete AI-generated content'];
    if ($idea['user_id'] && (int)$idea['user_id'] !== $userId) {
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
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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
        INSERT INTO idea_log (session_id, parent_id, content, category, status, source, shareable, clerk_key, group_id)
        VALUES (:session_id, :parent_id, :content, 'chat', 'raw', 'clerk-brainstorm', :shareable, 'brainstorm', :group_id)
    ");
    $aiStmt->execute([
        ':session_id' => $sessionId,
        ':parent_id'  => $userRowId,
        ':content'    => $cleanMessage,
        ':shareable'  => $shareable,
        ':group_id'   => $groupId
    ]);

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

    // Validate parent group if provided
    if ($parentId) {
        $stmt = $pdo->prepare("
            SELECT g.id FROM idea_groups g
            JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ? AND m.role = 'facilitator'
            WHERE g.id = ?
        ");
        $stmt->execute([$userId, $parentId]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Parent group not found or you are not a facilitator'];
        }
    }

    // Create the group
    $stmt = $pdo->prepare("
        INSERT INTO idea_groups (parent_group_id, name, description, tags, access_level, created_by)
        VALUES (:parent, :name, :desc, :tags, :access, :user)
    ");
    $stmt->execute([
        ':parent' => $parentId,
        ':name'   => $name,
        ':desc'   => $description,
        ':tags'   => $tags,
        ':access' => $accessLevel,
        ':user'   => $userId
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
        'role'     => 'facilitator'
    ];
}

function handleListGroups($pdo, $userId) {
    $mine = (bool)($_GET['mine'] ?? false);

    if ($mine && !$userId) {
        return ['success' => false, 'error' => 'Login required for my groups'];
    }

    if ($mine) {
        // Groups the user belongs to
        $stmt = $pdo->prepare("
            SELECT g.*, m.role AS user_role,
                   (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id) AS member_count
            FROM idea_groups g
            JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ?
            ORDER BY g.created_at DESC
        ");
        $stmt->execute([$userId]);
    } else {
        // All discoverable groups: open + observable + user's closed groups
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT g.*,
                       m.role AS user_role,
                       (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id) AS member_count
                FROM idea_groups g
                LEFT JOIN idea_group_members m ON m.group_id = g.id AND m.user_id = ?
                WHERE g.access_level IN ('open', 'observable') OR m.user_id IS NOT NULL
                ORDER BY g.created_at DESC
            ");
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->query("
                SELECT g.*, NULL AS user_role,
                       (SELECT COUNT(*) FROM idea_group_members WHERE group_id = g.id) AS member_count
                FROM idea_groups g
                WHERE g.access_level IN ('open', 'observable')
                ORDER BY g.created_at DESC
            ");
        }
    }

    return [
        'success' => true,
        'groups'  => $stmt->fetchAll()
    ];
}

function handleGetGroup($pdo, $userId) {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Fetch group
    $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    if (!$group) {
        return ['success' => false, 'error' => 'Group not found'];
    }

    // Check access for closed groups
    $userRole = null;
    if ($userId) {
        $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $userId]);
        $membership = $stmt->fetch();
        $userRole = $membership ? $membership['role'] : null;
    }

    if ($group['access_level'] === 'closed' && !$userRole) {
        return ['success' => false, 'error' => 'This group is closed'];
    }

    // Members
    $stmt = $pdo->prepare("
        SELECT m.user_id, m.role, m.joined_at,
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
               (SELECT COUNT(*) FROM idea_group_members WHERE group_id = idea_groups.id) AS member_count
        FROM idea_groups
        WHERE parent_group_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$groupId]);
    $subGroups = $stmt->fetchAll();

    return [
        'success'    => true,
        'group'      => $group,
        'user_role'  => $userRole,
        'members'    => $members,
        'ideas'      => $ideas,
        'sub_groups' => $subGroups
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
    $stmt = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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
    $stmt = $pdo->prepare("SELECT id, role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);
    $membership = $stmt->fetch();

    if (!$membership) {
        return ['success' => false, 'error' => 'You are not in this group'];
    }

    // If leaving facilitator is the last one, promote oldest member
    if ($membership['role'] === 'facilitator') {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_group_members WHERE group_id = ? AND role = 'facilitator'");
        $stmt->execute([$groupId]);
        $facCount = (int)$stmt->fetch()['cnt'];

        if ($facCount === 1) {
            // Promote oldest non-facilitator member
            $stmt = $pdo->prepare("
                SELECT id FROM idea_group_members
                WHERE group_id = ? AND user_id != ? AND role != 'facilitator'
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
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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

    $groupId  = (int)($input['group_id'] ?? 0);
    $targetId = (int)($input['user_id'] ?? 0);
    $newRole  = $input['role'] ?? null;
    $remove   = (bool)($input['remove'] ?? false);

    if (!$groupId || !$targetId) {
        return ['success' => false, 'error' => 'group_id and user_id are required'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);
    $myRole = $stmt->fetch();
    if (!$myRole || $myRole['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can manage members'];
    }

    // Can't modify yourself through this endpoint
    if ($targetId === $userId) {
        return ['success' => false, 'error' => 'Use leave_group to manage your own membership'];
    }

    // Check target exists in group
    $stmt = $pdo->prepare("SELECT id, role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        return ['success' => false, 'error' => 'User is not in this group'];
    }

    if ($remove) {
        $pdo->prepare("DELETE FROM idea_group_members WHERE group_id = ? AND user_id = ?")->execute([$groupId, $targetId]);
        return ['success' => true, 'action' => 'removed', 'user_id' => $targetId];
    }

    if ($newRole) {
        $validRoles = ['member', 'facilitator', 'observer'];
        if (!in_array($newRole, $validRoles)) {
            return ['success' => false, 'error' => 'Invalid role. Valid: ' . implode(', ', $validRoles)];
        }
        $pdo->prepare("UPDATE idea_group_members SET role = ? WHERE group_id = ? AND user_id = ?")->execute([$newRole, $groupId, $targetId]);
        return ['success' => true, 'action' => 'role_changed', 'user_id' => $targetId, 'role' => $newRole];
    }

    return ['success' => false, 'error' => 'Provide role or remove=true'];
}


// ═══════════════════════════════════════════════════════════════════
// Phase 4: Staleness Detection
// ═══════════════════════════════════════════════════════════════════

function handleCheckStaleness($pdo, $userId) {
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) return ['success' => false, 'error' => 'group_id is required'];

    // Find all digest nodes linked via synthesizes to this group's ideas
    $stmt = $pdo->prepare("
        SELECT DISTINCT d.id AS digest_id, d.clerk_key, d.status, d.created_at AS digest_created_at
        FROM idea_log d
        JOIN idea_links l ON l.idea_id_b = d.id AND l.link_type = 'synthesizes'
        JOIN idea_log src ON src.id = l.idea_id_a AND src.group_id = ?
        WHERE d.category = 'digest'
        ORDER BY d.created_at DESC
    ");
    $stmt->execute([$groupId]);
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
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
    $stmt->execute([$groupId, $userId]);
    $membership = $stmt->fetch();
    if (!$membership || $membership['role'] !== 'facilitator') {
        return ['success' => false, 'error' => 'Only facilitators can run the gatherer'];
    }

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'gatherer');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Gatherer clerk not available'];
    }

    // Fetch group info
    $stmt = $pdo->prepare("SELECT * FROM idea_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();

    // Fetch group ideas (exclude gatherer's own digests to prevent feedback loop)
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
    $ideas = $stmt->fetchAll();
    foreach ($ideas as &$gi) { $gi['author'] = getDisplayName($gi); }
    unset($gi);

    if (count($ideas) < 2) {
        return ['success' => false, 'error' => 'Need at least 2 shareable ideas to gather'];
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
link_type: {related|supports|challenges|builds_on}
reason: {brief explanation of the connection}

### Summarize a cluster of related ideas
[ACTION: SUMMARIZE]
content: {clear summary of the thematic cluster}
tags: {comma-separated theme tags}
source_ids: {comma-separated idea numbers that form this cluster}

## Rules
- Only link ideas that have genuine thematic connections.
- Do not duplicate existing links.
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

            $validTypes = ['related', 'supports', 'challenges', 'synthesizes', 'builds_on'];
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
                ':group_id' => $groupId
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
    if (!$groupId) {
        return ['success' => false, 'error' => 'group_id is required'];
    }

    // Facilitator check
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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

    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'brainstorm');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Brainstorm clerk not available'];
    }

    // Fetch all group ideas
    $stmt = $pdo->prepare("
        SELECT i.id, i.content, i.category, i.status, i.tags, i.created_at,
               u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        WHERE i.group_id = ? AND i.deleted_at IS NULL
        ORDER BY i.created_at ASC
    ");
    $stmt->execute([$groupId]);
    $ideas = $stmt->fetchAll();
    foreach ($ideas as &$gi) { $gi['author'] = getDisplayName($gi); }
    unset($gi);

    // Fetch existing digests (from gatherer) for this group
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
    $digests = $stmt->fetchAll();

    // Fetch members (display names)
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, u.username, u.show_first_name, u.show_last_name
        FROM idea_group_members m
        JOIN users u ON u.user_id = m.user_id
        WHERE m.group_id = ? AND m.role != 'observer'
    ");
    $stmt->execute([$groupId]);
    $memberNames = array_map('getDisplayName', $stmt->fetchAll());

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
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM idea_groups WHERE parent_group_id = ?");
    $stmt->execute([$groupId]);
    $subGroupCount = (int)$stmt->fetch()['cnt'];

    $metrics = [
        'contributors'  => count($uniqueAuthors),
        'ideas'         => count($ideas),
        'digests'       => count($digests),
        'links'         => $linkCount,
        'members'       => count($memberNames),
        'sub_groups'    => $subGroupCount,
        'crystallized_at' => date('Y-m-d H:i:s'),
        'group_id'      => $groupId,
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
        $pdo->prepare("UPDATE idea_groups SET status = 'active' WHERE id = ?")->execute([$groupId]);
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
        ':group_id' => $groupId
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
    // Filename convention: group-{id}-{slug}-v{version}-u{userId}-{timestamp}.md
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($group['name']));
    $slug = trim($slug, '-');

    // Compute version number by counting existing versioned files for this group
    $existingVersions = glob($outputDir . "/group-{$groupId}-*-v*-u*.md");
    $existingVersions = array_filter($existingVersions, function($f) {
        return !str_ends_with(basename($f), '-latest.md');
    });
    $version = count($existingVersions) + 1;

    $timestamp = date('Y-m-d-His');
    $versionedFile = "group-{$groupId}-{$slug}-v{$version}-u{$userId}-{$timestamp}.md";
    $latestFile = "group-{$groupId}-{$slug}-latest.md";
    file_put_contents($outputDir . '/' . $versionedFile, $markdownWithMetrics);
    file_put_contents($outputDir . '/' . $latestFile, $markdownWithMetrics);
    $filePath = "output/{$latestFile}";

    // Update group status to crystallized
    $pdo->prepare("UPDATE idea_groups SET status = 'crystallized' WHERE id = ?")->execute([$groupId]);

    // If parent group exists, insert digest as shareable idea in parent context
    if ($group['parent_group_id']) {
        // The digest is already shareable and linked to the user who triggered it
        // It will appear in parent group's feed through membership
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
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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

        if (!$invitee) {
            // Check if user exists but isn't verified
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $results[] = ['email' => $email, 'status' => 'not_verified'];
            } else {
                $results[] = ['email' => $email, 'status' => 'not_found'];
            }
            $errorCount++;
            continue;
        }

        $inviteeId = (int)$invitee['user_id'];

        // Check if already a member
        $stmt = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$groupId, $inviteeId]);
        if ($stmt->fetch()) {
            $results[] = ['email' => $email, 'status' => 'already_member'];
            $errorCount++;
            continue;
        }

        // Check for existing pending invite
        $stmt = $pdo->prepare("
            SELECT id FROM group_invites
            WHERE group_id = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW()
        ");
        $stmt->execute([$groupId, $inviteeId]);
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
            <p style='color: #444; font-size: 0.9em;'>To be part of this group you'll need to <a href='{$baseUrl}/join.php' style='color: #1a3a5c;'>create an account</a> or <a href='{$baseUrl}/login.php' style='color: #1a3a5c;'>log in</a> if you haven't already.</p>
            <p style='color: #666; font-size: 0.9em;'>This invitation expires in 7 days.</p>
            <p style='color: #666; font-size: 0.9em;'>If you didn't expect this, you can safely ignore this email.</p>
            <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
            <p style='color: #888; font-size: 0.85em;'><a href='{$baseUrl}/talk/help.php' style='color: #1a3a5c;'>Learn more about TPB Groups</a></p>
            <p style='color: #888; font-size: 0.85em;'>The People's Branch &mdash; Your voice, aggregated</p>
        </div>
        ";

        $mailSent = sendSmtpMail($config, $email, $subject, $htmlBody, null, true);

        $results[] = ['email' => $email, 'status' => 'invited', 'mail_sent' => $mailSent];
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
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ?");
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
