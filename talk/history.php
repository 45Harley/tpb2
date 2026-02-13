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

    $where = [];
    $params = [];

    // User-scoped by default (unless ?all or filtering by session)
    if ($currentUserId && !$showAll && !$session) {
        $where[] = 'i.user_id = :user_id';
        $params[':user_id'] = $currentUserId;
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

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT i.*,
               u.first_name AS user_first_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id) AS children_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at DESC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $thoughts = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error';
}

$icons = [
    'idea' => '💡', 'decision' => '✅', 'todo' => '📋', 'note' => '📝',
    'question' => '❓', 'reaction' => '↩️', 'distilled' => '✨', 'digest' => '📊'
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

        .filters, .status-filters {
            display: flex; gap: 8px; margin-bottom: 0.75rem; flex-wrap: wrap;
        }

        .filter-btn {
            padding: 6px 14px; border: 2px solid #333; border-radius: 20px;
            background: transparent; color: #888; font-size: 0.8rem;
            cursor: pointer; text-decoration: none; transition: all 0.3s;
        }
        .filter-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }
        .filter-btn.active { background: #4fc3f7; border-color: #4fc3f7; color: #1a1a2e; }

        .thought {
            background: rgba(255,255,255,0.05); border-radius: 12px;
            padding: 15px; margin-bottom: 12px; border-left: 4px solid #4fc3f7;
        }
        .thought.decision { border-left-color: #4caf50; }
        .thought.todo { border-left-color: #ff9800; }
        .thought.note { border-left-color: #9c27b0; }
        .thought.question { border-left-color: #e91e63; }
        .thought.reaction { border-left-color: #00bcd4; }

        .thought-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px; font-size: 0.8rem; color: #888; flex-wrap: wrap; gap: 4px;
        }

        .thought-meta { display: flex; align-items: center; gap: 8px; }

        .status-badge {
            padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;
            font-weight: 600; text-transform: uppercase;
        }

        .thought-content { font-size: 1rem; line-height: 1.5; word-break: break-word; overflow-wrap: break-word; }

        .thought-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 8px; font-size: 0.75rem; color: #666; flex-wrap: wrap; gap: 4px;
        }

        .thread-info { display: flex; gap: 10px; align-items: center; }
        .thread-info a { color: #4fc3f7; text-decoration: none; }
        .thread-info a:hover { text-decoration: underline; }
        .thread-info a:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; border-radius: 2px; }

        .promote-btn {
            background: none; border: 1px solid #555; color: #888;
            padding: 2px 8px; border-radius: 6px; font-size: 0.75rem;
            cursor: pointer; transition: all 0.3s;
        }
        .promote-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }
        .promote-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }

        .user-name { color: #4fc3f7; font-weight: 600; }

        .empty { text-align: center; padding: 3rem; color: #666; }
        .error { background: rgba(244,67,54,0.2); color: #e57373; padding: 15px; border-radius: 8px; margin-bottom: 1rem; z-index: 10; position: relative; }
        
        .filter-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📚 Thought History</h1>
            <div class="header-links">
                <?php if ($currentUserId && !$showAll): ?>
                    <a href="?all&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">Show all</a>
                <?php elseif ($currentUserId && $showAll): ?>
                    <a href="?category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">My ideas</a>
                <?php endif; ?>
                <a href="brainstorm.php">🧠 Brainstorm</a>
                <a href="index.php">← New thought</a>
            </div>
        </header>

        <!-- Category filters -->
        <div class="filters">
            <?php
            $catFilters = ['all' => 'All', 'idea' => '💡 Ideas', 'decision' => '✅ Decisions', 'todo' => '📋 Todos', 'note' => '📝 Notes', 'question' => '❓ Questions'];
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

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($thoughts)): ?>
            <div class="empty">
                <p>No thoughts yet.</p>
                <p><a href="index.php" style="color: #4fc3f7;">Add your first one →</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($thoughts as $t):
                $isOwner = $currentUserId && (int)($t['user_id'] ?? 0) === $currentUserId;
                $displayName = $isOwner ? 'You' : ($t['user_first_name'] ?? 'Anonymous');
                $statusColor = $statusColors[$t['status'] ?? 'raw'] ?? '#888';
                $nextStatus = $statusOrder[$t['status'] ?? 'raw'] ?? null;
                $childCount = (int)($t['children_count'] ?? 0);
            ?>
                <div class="thought <?= htmlspecialchars($t['category'] ?? 'idea') ?>" id="idea-<?= (int)$t['id'] ?>">
                    <div class="thought-header">
                        <div class="thought-meta">
                            <span>
                                <?= $icons[$t['category'] ?? 'idea'] ?? '💭' ?>
                                <span class="user-name"><?= htmlspecialchars($displayName) ?></span>
                            </span>
                            <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>;">
                                <?= htmlspecialchars($t['status'] ?? 'raw') ?>
                            </span>
                        </div>
                        <span><?= date('M j, g:ia', strtotime($t['created_at'])) ?></span>
                    </div>
                    <div class="thought-content">
                        <?= nl2br(htmlspecialchars($t['content'])) ?>
                    </div>
                    <div class="thought-footer">
                        <div class="thread-info">
                            <?php if ($t['parent_id']): ?>
                                <span>builds on <a href="#idea-<?= (int)$t['parent_id'] ?>">#<?= (int)$t['parent_id'] ?></a></span>
                            <?php endif; ?>
                            <?php if ($childCount > 0): ?>
                                <span><?= $childCount ?> build<?= $childCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <span>via <?= htmlspecialchars($t['source'] ?? 'web') ?></span>
                        </div>
                        <div>
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
    </script>
</body>
</html>
