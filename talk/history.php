<?php
/**
 * Talk History — /talk/history.php
 * View, filter, and promote your ideas
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';

$thoughts = [];
$error = null;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $dbUser = getUser($pdo);
    $currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

    // Filters
    $category = $_GET['category'] ?? 'all';
    $status   = $_GET['status']   ?? 'all';
    $session  = $_GET['session']  ?? '';
    $showAll  = isset($_GET['all']);
    $view     = $_GET['view']     ?? 'flat';  // flat or thread
    $threadId = (int)($_GET['thread'] ?? 0);   // single-thread focus

    $where = ['i.deleted_at IS NULL'];
    $params = [];

    // Single-thread focus: show one root and all its descendants
    if ($threadId) {
        // Fetch the root + all descendants via recursive walk
        $where[] = '(i.id = :thread_root OR i.parent_id = :thread_root2)';
        $params[':thread_root'] = $threadId;
        $params[':thread_root2'] = $threadId;
        // Also grab deeper descendants (up to 3 levels deep)
        // We'll do a broader fetch and filter in PHP for deep trees
    } else {
        // User-scoped by default (unless ?all or filtering by session)
        // Include AI child nodes (user_id IS NULL with parent_id pointing to user's thoughts)
        if ($currentUserId && !$showAll && !$session) {
            $where[] = '(i.user_id = :user_id OR (i.clerk_key IS NOT NULL AND i.parent_id IN (SELECT id FROM idea_log WHERE user_id = :user_id2)))';
            $params[':user_id'] = $currentUserId;
            $params[':user_id2'] = $currentUserId;
        }

        if ($category !== 'all') {
            $where[] = 'i.category = :category';
            $params[':category'] = $category;
        }

        if ($status !== 'all') {
            $where[] = 'i.status = :status';
            $params[':status'] = $status;
        }

        if ($session) {
            $where[] = 'i.session_id = :session_id';
            $params[':session_id'] = $session;
        }
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT i.*, g.name AS group_name,
               u.first_name AS user_first_name, u.last_name AS user_last_name,
               u.username AS user_username, u.show_first_name, u.show_last_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id AND c.deleted_at IS NULL) AS children_count,
               (SELECT COUNT(*) FROM idea_links l WHERE l.idea_id_a = i.id AND l.link_type = 'synthesizes') AS synth_link_count
        FROM idea_log i
        LEFT JOIN idea_groups g ON g.id = i.group_id
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at " . ($view === 'thread' || $threadId ? 'ASC' : 'DESC') . "
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $thoughts = $stmt->fetchAll();

    // For single-thread focus, fetch deeper descendants (levels 3+)
    if ($threadId && $thoughts) {
        $allIds = array_column($thoughts, 'id');
        $depth = 0;
        while ($depth < 5) {
            $placeholders = implode(',', array_fill(0, count($allIds), '?'));
            $deepStmt = $pdo->prepare("
                SELECT i.*, u.first_name AS user_first_name,
                       (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id AND c.deleted_at IS NULL) AS children_count
                FROM idea_log i
                LEFT JOIN users u ON i.user_id = u.user_id
                WHERE i.parent_id IN ({$placeholders}) AND i.id NOT IN ({$placeholders}) AND i.deleted_at IS NULL
            ");
            $deepStmt->execute(array_merge($allIds, $allIds));
            $deeper = $deepStmt->fetchAll();
            if (empty($deeper)) break;
            $thoughts = array_merge($thoughts, $deeper);
            $allIds = array_merge($allIds, array_column($deeper, 'id'));
            $depth++;
        }
    }

    // Build tree structure for threaded view
    $tree = [];
    $byId = [];
    if ($view === 'thread' || $threadId) {
        foreach ($thoughts as &$t) {
            $t['children'] = [];
            $byId[(int)$t['id']] = &$t;
        }
        unset($t);

        foreach ($byId as $id => &$t) {
            $pid = (int)($t['parent_id'] ?? 0);
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$t;
            } else {
                $tree[] = &$t;
            }
        }
        unset($t);
    }

} catch (PDOException $e) {
    $error = 'Database error';
}

$icons = [
    'idea' => '💡', 'decision' => '✅', 'todo' => '📋', 'note' => '📝',
    'question' => '❓', 'reaction' => '↩️', 'distilled' => '✨', 'digest' => '📊',
    'chat' => '💬'
];

$statusColors = [
    'raw' => '#888', 'refining' => '#4fc3f7', 'distilled' => '#4caf50',
    'actionable' => '#ffd700', 'archived' => '#666'
];

$statusOrder = ['raw' => 'refining', 'refining' => 'distilled', 'distilled' => 'actionable'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <title>Talk History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }

        .container { max-width: 700px; margin: 0 auto; }

        header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }

        h1 { font-size: 1.3rem; color: #4fc3f7; }

        .header-links { display: flex; gap: 1rem; font-size: 0.9rem; }
        .header-links a { color: #4fc3f7; text-decoration: none; }
        .header-links a:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; border-radius: 2px; }

        .user-status { font-size: 0.8rem; color: #81c784; text-align: right; margin-bottom: 0.75rem; }
        .user-status .dot { display: inline-block; width: 8px; height: 8px; background: #4caf50; border-radius: 50%; margin-right: 4px; }

        .filters, .status-filters {
            display: flex; gap: 8px; margin-bottom: 0.75rem; flex-wrap: wrap;
        }

        .filter-btn {
            padding: 6px 14px; border: 2px solid #333; border-radius: 20px;
            background: transparent; color: #aaa; font-size: 0.8rem;
            cursor: pointer; text-decoration: none; transition: all 0.3s;
        }
        .filter-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }
        .filter-btn.active { background: #4fc3f7; border-color: #4fc3f7; color: #1a1a2e; }

        .thought {
            background: rgba(255,255,255,0.08); border-radius: 12px;
            padding: 15px; margin-bottom: 12px; border-left: 4px solid #4fc3f7;
        }
        .thought.decision { border-left-color: #4caf50; }
        .thought.todo { border-left-color: #ff9800; }
        .thought.note { border-left-color: #9c27b0; }
        .thought.question { border-left-color: #e91e63; }
        .thought.reaction { border-left-color: #00bcd4; }

        .thought-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px; font-size: 0.8rem; color: #aaa; flex-wrap: wrap; gap: 4px;
        }

        .thought-meta { display: flex; align-items: center; gap: 8px; }

        .status-badge {
            padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;
            font-weight: 600; text-transform: uppercase;
        }

        .thought-content { font-size: 1rem; line-height: 1.5; word-break: break-word; overflow-wrap: break-word; }

        .thought-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 8px; font-size: 0.75rem; color: #999; flex-wrap: wrap; gap: 4px;
        }

        .thread-info { display: flex; gap: 10px; align-items: center; }
        .thread-info a { color: #4fc3f7; text-decoration: none; }
        .thread-info a:hover { text-decoration: underline; }
        .thread-info a:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; border-radius: 2px; }

        .promote-btn {
            background: none; border: 1px solid #555; color: #aaa;
            padding: 2px 8px; border-radius: 6px; font-size: 0.75rem;
            cursor: pointer; transition: all 0.3s;
        }
        .promote-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }
        .promote-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }

        .edit-btn, .delete-btn {
            background: none; border: 1px solid #555; color: #aaa;
            padding: 2px 8px; border-radius: 6px; font-size: 0.75rem;
            cursor: pointer; transition: all 0.3s;
        }
        .edit-btn:hover { border-color: #ff9800; color: #ff9800; }
        .delete-btn:hover { border-color: #e57373; color: #e57373; }

        .edited-tag { color: #ff9800; font-size: 0.7rem; margin-left: 6px; }

        .inline-edit { margin-top: 8px; }
        .inline-edit textarea {
            width: 100%; padding: 8px; border: 1px solid #4fc3f7; border-radius: 8px;
            background: rgba(255,255,255,0.08); color: #eee; font-family: inherit;
            font-size: 0.9rem; min-height: 60px; resize: vertical;
        }
        .inline-edit .edit-actions {
            display: flex; gap: 8px; margin-top: 6px; justify-content: flex-end;
        }

        .user-name { color: #4fc3f7; font-weight: 600; }

        .empty { text-align: center; padding: 3rem; color: #999; }
        .error { background: rgba(244,67,54,0.2); color: #e57373; padding: 15px; border-radius: 8px; margin-bottom: 1rem; z-index: 10; position: relative; }
        
        .filter-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }

        .thought.chat { border-left-color: #78909c; }

        .ai-response {
            margin-top: 10px;
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            font-size: 0.9rem;
            color: #bbb;
            line-height: 1.5;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .ai-response::before {
            content: 'AI: ';
            font-weight: 600;
            color: #4fc3f7;
        }

        .share-check {
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            color: #999;
            user-select: none;
        }

        .share-check input {
            cursor: pointer;
            accent-color: #81c784;
        }

        .share-check.active { color: #81c784; }

        /* AI clerk nodes */
        .thought.clerk-node { border-left-color: #7c4dff; background: rgba(124, 77, 255, 0.06); }

        .clerk-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(124, 77, 255, 0.2);
            color: #b388ff;
            margin-left: 4px;
        }

        /* Thread view */
        .view-toggle {
            display: flex; gap: 8px; margin-bottom: 0.75rem; align-items: center;
        }

        .view-toggle .label { font-size: 0.8rem; color: #999; margin-right: 4px; }

        .thread-depth-1 { margin-left: 24px; }
        .thread-depth-2 { margin-left: 48px; }
        .thread-depth-3 { margin-left: 72px; }
        .thread-depth-4 { margin-left: 96px; }

        .threaded .thought {
            position: relative;
        }

        .threaded .thought.has-parent::before {
            content: '';
            position: absolute;
            left: -16px;
            top: 0;
            width: 12px;
            height: 20px;
            border-left: 2px solid rgba(79, 195, 247, 0.25);
            border-bottom: 2px solid rgba(79, 195, 247, 0.25);
            border-bottom-left-radius: 8px;
        }

        .thread-focus-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1rem;
            padding: 10px 14px;
            background: rgba(79, 195, 247, 0.08);
            border-radius: 10px;
            font-size: 0.85rem;
            color: #aaa;
        }

        .thread-focus-header a { color: #4fc3f7; text-decoration: none; }
        .thread-focus-header a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div style="background:rgba(79,195,247,0.1);border:1px solid rgba(79,195,247,0.2);border-radius:8px;padding:8px 14px;margin-bottom:12px;font-size:0.85rem;text-align:center;color:#ccc;">
            New: <a href="index.php" style="color:#4fc3f7;">Try the unified Talk page</a> &mdash; ideas, AI, and groups in one place.
        </div>
        <header>
            <h1>📚 Thought History</h1>
            <div class="header-links">
                <?php if ($currentUserId && !$showAll): ?>
                    <a href="?all&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">Show all</a>
                <?php elseif ($currentUserId && $showAll): ?>
                    <a href="?category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">My ideas</a>
                <?php endif; ?>
                <a href="groups.php">👥 Groups</a>
                <a href="brainstorm.php">🧠 Brainstorm</a>
                <a href="index.php">← New thought</a>
                <a href="help.php">? Help</a>
                <a href="brainstorm.php?help">🤖 Ask AI</a>
            </div>
        </header>
<?php if ($dbUser): ?>
        <div class="user-status"><span class="dot"></span><?= htmlspecialchars(getDisplayName($dbUser)) ?></div>
<?php endif; ?>

        <!-- Category filters -->
        <div class="filters">
            <?php
            $catFilters = ['all' => 'All', 'idea' => '💡 Ideas', 'decision' => '✅ Decisions', 'todo' => '📋 Todos', 'note' => '📝 Notes', 'question' => '❓ Questions', 'chat' => '💬 Chat'];
            foreach ($catFilters as $val => $label):
                $active = ($category === $val) ? 'active' : '';
                $extra = ($status !== 'all') ? '&status=' . urlencode($status) : '';
                $extra .= $showAll ? '&all' : '';
                $extra .= $session ? '&session=' . urlencode($session) : '';
            ?>
                <a href="?category=<?= $val ?><?= $extra ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Status filters -->
        <div class="status-filters">
            <?php
            $statFilters = ['all' => 'All Status', 'raw' => 'Raw', 'refining' => 'Refining', 'distilled' => 'Distilled', 'actionable' => 'Actionable'];
            foreach ($statFilters as $val => $label):
                $active = ($status === $val) ? 'active' : '';
                $extra = ($category !== 'all') ? '&category=' . urlencode($category) : '';
                $extra .= $showAll ? '&all' : '';
                $extra .= $session ? '&session=' . urlencode($session) : '';
            ?>
                <a href="?status=<?= $val ?><?= $extra ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <!-- View toggle -->
        <?php if (!$threadId): ?>
        <div class="view-toggle">
            <span class="label">View:</span>
            <?php
            $viewExtra = ($category !== 'all' ? '&category=' . urlencode($category) : '')
                       . ($status !== 'all' ? '&status=' . urlencode($status) : '')
                       . ($showAll ? '&all' : '')
                       . ($session ? '&session=' . urlencode($session) : '');
            ?>
            <a href="?view=flat<?= $viewExtra ?>" class="filter-btn <?= $view === 'flat' ? 'active' : '' ?>">Flat</a>
            <a href="?view=thread<?= $viewExtra ?>" class="filter-btn <?= $view === 'thread' ? 'active' : '' ?>">Threaded</a>
        </div>
        <?php endif; ?>

        <?php if ($threadId): ?>
        <div class="thread-focus-header">
            <a href="?view=thread<?= $category !== 'all' ? '&category=' . urlencode($category) : '' ?><?= $status !== 'all' ? '&status=' . urlencode($status) : '' ?><?= $showAll ? '&all' : '' ?>">← Back to all</a>
            <span>Thread #<?= $threadId ?></span>
        </div>
        <?php endif; ?>

        <?php if ($currentUserId && !$showAll && !$session && !$threadId): ?>
        <div style="display:flex;gap:8px;margin-bottom:1rem;flex-wrap:wrap;align-items:center;">
            <span style="font-size:0.8rem;color:#999;">Personal:</span>
            <button class="promote-btn" id="gatherPersonalBtn" onclick="gatherPersonal()" style="padding:4px 12px;">📊 Gather</button>
            <button class="promote-btn" id="crystallizePersonalBtn" onclick="crystallizePersonal()" style="padding:4px 12px;">💎 Crystallize</button>
            <span id="personalToolStatus" style="font-size:0.75rem;color:#81c784;display:none;"></span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($thoughts)): ?>
            <div class="empty">
                <p>No thoughts yet.</p>
                <p><a href="index.php" style="color: #4fc3f7;">Add your first one →</a></p>
            </div>
        <?php elseif ($view === 'thread' || $threadId): ?>
            <!-- Threaded view -->
            <div class="threaded">
                <?php
                function renderThread($nodes, $depth, $icons, $statusColors, $statusOrder, $currentUserId, $view) {
                    foreach ($nodes as $t):
                        $isOwner = $currentUserId && ((int)($t['user_id'] ?? 0) === $currentUserId || empty($t['user_id']));
                        $displayName = $isOwner ? 'You' : getDisplayName([
                            'first_name' => $t['user_first_name'] ?? '',
                            'last_name' => $t['user_last_name'] ?? '',
                            'username' => $t['user_username'] ?? '',
                            'show_first_name' => $t['show_first_name'] ?? 0,
                            'show_last_name' => $t['show_last_name'] ?? 0,
                        ]);
                        $statusColor = $statusColors[$t['status'] ?? 'raw'] ?? '#888';
                        $nextStatus = $statusOrder[$t['status'] ?? 'raw'] ?? null;
                        $childCount = (int)($t['children_count'] ?? 0);
                        $depthClass = $depth > 0 ? ' thread-depth-' . min($depth, 4) : '';
                        $hasParent = $depth > 0 ? ' has-parent' : '';
                        $isClerk = !empty($t['clerk_key']);
                        $clerkClass = $isClerk ? ' clerk-node' : '';
                ?>
                    <div class="thought <?= htmlspecialchars($t['category'] ?? 'idea') ?><?= $depthClass ?><?= $hasParent ?><?= $clerkClass ?>" id="idea-<?= (int)$t['id'] ?>">
                        <div class="thought-header">
                            <div class="thought-meta">
                                <span>
                                    <?= $icons[$t['category'] ?? 'idea'] ?? '💭' ?>
                                    <span class="user-name"><?= $isClerk ? 'AI' : htmlspecialchars($displayName) ?></span>
                                    <?php if ($isClerk): ?><span class="clerk-badge"><?= htmlspecialchars($t['clerk_key']) ?></span><?php endif; ?>
                                </span>
                                <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>;">
                                    <?= htmlspecialchars($t['status'] ?? 'raw') ?>
                                </span>
                                <?php if (!empty($t['group_name'])): ?>
                                <span class="status-badge" style="background:rgba(79,195,247,0.15);color:#4fc3f7;font-size:0.6rem;">
                                    <?= htmlspecialchars($t['group_name']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <span>
                                <?= date('M j, g:ia', strtotime($t['created_at'])) ?>
                                <?php if ((int)($t['edit_count'] ?? 0) > 0): ?>
                                    <span class="edited-tag" title="Edited <?= (int)$t['edit_count'] ?> time(s), last: <?= date('M j, g:ia', strtotime($t['updated_at'])) ?>">(edited)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="thought-content">
                            <?= nl2br(htmlspecialchars($t['content'])) ?>
                        </div>
                        <?php if (!empty($t['ai_response'])): ?>
                            <div class="ai-response"><?= nl2br(htmlspecialchars($t['ai_response'])) ?></div>
                        <?php endif; ?>
                        <div class="thought-footer">
                            <div class="thread-info">
                                <?php if ($childCount > 0 && empty($t['children'])): ?>
                                    <a href="?thread=<?= (int)$t['id'] ?>"><?= $childCount ?> build<?= $childCount > 1 ? 's' : '' ?> →</a>
                                <?php elseif ($childCount > 0): ?>
                                    <span><?= $childCount ?> build<?= $childCount > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                                <span>via <?= htmlspecialchars($t['source'] ?? 'web') ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($isOwner && empty($t['clerk_key'])): ?>
                                    <button class="edit-btn" onclick="startEdit(<?= (int)$t['id'] ?>)" title="Edit">&#9998;</button>
                                    <button class="delete-btn" onclick="deleteIdea(<?= (int)$t['id'] ?>, <?= ((int)($t['synth_link_count'] ?? 0) > 0) ? 'true' : 'false' ?>)" title="Delete">&times;</button>
                                <?php endif; ?>
                                <label class="share-check <?= !empty($t['shareable']) ? 'active' : '' ?>">
                                    <input type="checkbox" <?= !empty($t['shareable']) ? 'checked' : '' ?>
                                           onchange="toggleShareable(<?= (int)$t['id'] ?>, this.checked, this.parentElement)"
                                           <?= !$isOwner ? 'disabled' : '' ?>>
                                    Share
                                </label>
                                <?php if ($isOwner && $nextStatus): ?>
                                    <button class="promote-btn" onclick="promote(<?= (int)$t['id'] ?>, '<?= $nextStatus ?>')">
                                        ⬆ <?= $nextStatus ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($t['children'])): ?>
                        <?php renderThread($t['children'], $depth + 1, $icons, $statusColors, $statusOrder, $currentUserId, $view); ?>
                    <?php endif; ?>
                <?php endforeach;
                }

                renderThread($tree ?: $thoughts, 0, $icons, $statusColors, $statusOrder, $currentUserId, $view);
                ?>
            </div>
        <?php else: ?>
            <!-- Flat view (default) -->
            <?php foreach ($thoughts as $t):
                $isOwner = $currentUserId && ((int)($t['user_id'] ?? 0) === $currentUserId || empty($t['user_id']));
                $displayName = $isOwner ? 'You' : getDisplayName([
                            'first_name' => $t['user_first_name'] ?? '',
                            'last_name' => $t['user_last_name'] ?? '',
                            'username' => $t['user_username'] ?? '',
                            'show_first_name' => $t['show_first_name'] ?? 0,
                            'show_last_name' => $t['show_last_name'] ?? 0,
                        ]);
                $statusColor = $statusColors[$t['status'] ?? 'raw'] ?? '#888';
                $nextStatus = $statusOrder[$t['status'] ?? 'raw'] ?? null;
                $childCount = (int)($t['children_count'] ?? 0);
            ?>
                <?php
                    $isClerk = !empty($t['clerk_key']);
                    $clerkClass = $isClerk ? ' clerk-node' : '';
                ?>
                <div class="thought <?= htmlspecialchars($t['category'] ?? 'idea') ?><?= $clerkClass ?>" id="idea-<?= (int)$t['id'] ?>">
                    <div class="thought-header">
                        <div class="thought-meta">
                            <span>
                                <?= $icons[$t['category'] ?? 'idea'] ?? '💭' ?>
                                <span class="user-name"><?= $isClerk ? 'AI' : htmlspecialchars($displayName) ?></span>
                                <?php if ($isClerk): ?><span class="clerk-badge"><?= htmlspecialchars($t['clerk_key']) ?></span><?php endif; ?>
                            </span>
                            <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>;">
                                <?= htmlspecialchars($t['status'] ?? 'raw') ?>
                            </span>
                            <?php if (!empty($t['group_name'])): ?>
                            <span class="status-badge" style="background:rgba(79,195,247,0.15);color:#4fc3f7;font-size:0.6rem;">
                                <?= htmlspecialchars($t['group_name']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <span>
                            <?= date('M j, g:ia', strtotime($t['created_at'])) ?>
                            <?php if ((int)($t['edit_count'] ?? 0) > 0): ?>
                                <span class="edited-tag" title="Edited <?= (int)$t['edit_count'] ?> time(s), last: <?= date('M j, g:ia', strtotime($t['updated_at'])) ?>">(edited)</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="thought-content">
                        <?= nl2br(htmlspecialchars($t['content'])) ?>
                    </div>
                    <?php if (!empty($t['ai_response'])): ?>
                        <div class="ai-response"><?= nl2br(htmlspecialchars($t['ai_response'])) ?></div>
                    <?php endif; ?>
                    <div class="thought-footer">
                        <div class="thread-info">
                            <?php if ($t['parent_id']): ?>
                                <span>builds on <a href="#idea-<?= (int)$t['parent_id'] ?>">#<?= (int)$t['parent_id'] ?></a></span>
                            <?php endif; ?>
                            <?php if ($childCount > 0): ?>
                                <a href="?thread=<?= (int)$t['id'] ?>"><?= $childCount ?> build<?= $childCount > 1 ? 's' : '' ?> →</a>
                            <?php endif; ?>
                            <span>via <?= htmlspecialchars($t['source'] ?? 'web') ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if ($isOwner && empty($t['clerk_key'])): ?>
                                <button class="edit-btn" onclick="startEdit(<?= (int)$t['id'] ?>)" title="Edit">&#9998;</button>
                                <button class="delete-btn" onclick="deleteIdea(<?= (int)$t['id'] ?>, <?= ((int)($t['synth_link_count'] ?? 0) > 0) ? 'true' : 'false' ?>)" title="Delete">&times;</button>
                            <?php endif; ?>
                            <label class="share-check <?= !empty($t['shareable']) ? 'active' : '' ?>">
                                <input type="checkbox" <?= !empty($t['shareable']) ? 'checked' : '' ?>
                                       onchange="toggleShareable(<?= (int)$t['id'] ?>, this.checked, this.parentElement)"
                                       <?= !$isOwner ? 'disabled' : '' ?>>
                                Share
                            </label>
                            <?php if ($isOwner && $nextStatus): ?>
                                <button class="promote-btn" onclick="promote(<?= (int)$t['id'] ?>, '<?= $nextStatus ?>')">
                                    ⬆ <?= $nextStatus ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
    async function toggleShareable(ideaId, checked, label) {
        try {
            const response = await fetch('api.php?action=toggle_shareable', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, shareable: checked ? 1 : 0 })
            });
            const data = await response.json();
            if (data.success) {
                label.classList.toggle('active', checked);
            } else {
                alert('Error: ' + data.error);
                label.querySelector('input').checked = !checked;
            }
        } catch (err) {
            alert('Network error');
            label.querySelector('input').checked = !checked;
        }
    }

    async function promote(ideaId, newStatus) {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '...';

        try {
            const response = await fetch('api.php?action=promote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, status: newStatus })
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
                btn.disabled = false;
                btn.textContent = '⬆ ' + newStatus;
            }
        } catch (err) {
            alert('Network error');
            btn.disabled = false;
            btn.textContent = '⬆ ' + newStatus;
        }
    }

    function startEdit(ideaId) {
        var card = document.getElementById('idea-' + ideaId);
        if (!card || card.querySelector('.inline-edit')) return;

        var contentEl = card.querySelector('.thought-content');
        var currentText = contentEl.textContent.trim();

        var form = document.createElement('div');
        form.className = 'inline-edit';
        form.innerHTML = '<textarea>' + escapeHtml(currentText) + '</textarea>' +
            '<div class="edit-actions">' +
            '<button class="promote-btn" onclick="cancelEdit(' + ideaId + ')">Cancel</button>' +
            '<button class="promote-btn" style="border-color:#4fc3f7;color:#4fc3f7;" onclick="saveEdit(' + ideaId + ')">Save</button>' +
            '</div>';

        contentEl.style.display = 'none';
        contentEl.parentNode.insertBefore(form, contentEl.nextSibling);
    }

    function cancelEdit(ideaId) {
        var card = document.getElementById('idea-' + ideaId);
        var form = card.querySelector('.inline-edit');
        if (form) form.remove();
        card.querySelector('.thought-content').style.display = '';
    }

    async function saveEdit(ideaId) {
        var card = document.getElementById('idea-' + ideaId);
        var textarea = card.querySelector('.inline-edit textarea');
        var newContent = textarea.value.trim();
        if (!newContent) { alert('Content cannot be empty'); return; }

        try {
            var response = await fetch('api.php?action=edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, content: newContent })
            });
            var data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        } catch (err) {
            alert('Network error');
        }
    }

    async function deleteIdea(ideaId, hasSynthLinks) {
        var msg = hasSynthLinks
            ? 'This idea was used in a gather/crystallize. It will be hidden but preserved for integrity. Delete?'
            : 'Delete this idea?';
        if (!confirm(msg)) return;

        var hard = false;
        if (!hasSynthLinks) {
            hard = confirm('Permanently delete? (OK = permanent, Cancel = soft delete)');
        }

        try {
            var response = await fetch('api.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, hard: hard })
            });
            var data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        } catch (err) {
            alert('Network error');
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    async function gatherPersonal() {
        var btn = document.getElementById('gatherPersonalBtn');
        var status = document.getElementById('personalToolStatus');
        btn.disabled = true;
        btn.textContent = 'Gathering...';
        status.style.display = 'inline';
        status.textContent = 'Running gatherer on your personal ideas...';
        status.style.color = '#4fc3f7';

        try {
            var response = await fetch('api.php?action=gather', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: 0 })
            });
            var data = await response.json();
            if (data.success) {
                status.textContent = 'Gathered! ' + (data.actions ? data.actions.length + ' actions' : '');
                status.style.color = '#81c784';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                status.textContent = data.error;
                status.style.color = '#e57373';
                btn.disabled = false;
                btn.textContent = '📊 Gather';
            }
        } catch (err) {
            status.textContent = 'Network error';
            status.style.color = '#e57373';
            btn.disabled = false;
            btn.textContent = '📊 Gather';
        }
    }

    async function crystallizePersonal() {
        var btn = document.getElementById('crystallizePersonalBtn');
        var status = document.getElementById('personalToolStatus');
        btn.disabled = true;
        btn.textContent = 'Crystallizing...';
        status.style.display = 'inline';
        status.textContent = 'Running crystallizer on your personal ideas...';
        status.style.color = '#4fc3f7';

        try {
            var response = await fetch('api.php?action=crystallize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: 0 })
            });
            var data = await response.json();
            if (data.success) {
                status.textContent = 'Crystallized!';
                status.style.color = '#81c784';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                status.textContent = data.error;
                status.style.color = '#e57373';
                btn.disabled = false;
                btn.textContent = '💎 Crystallize';
            }
        } catch (err) {
            status.textContent = 'Network error';
            status.style.color = '#e57373';
            btn.disabled = false;
            btn.textContent = '💎 Crystallize';
        }
    }
    </script>
</body>
</html>
