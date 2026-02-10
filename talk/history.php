<?php
/**
 * Quick Thought History - /qt/history.php
 * View recent logged thoughts
 */

// Database configuration
$db_host = 'localhost';
$db_name = 'sandge5_tpb2';
$db_user = 'sandge5_tpb2';
$db_pass = '.YeO6kSJAHh5';

$thoughts = [];
$error = null;

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Get filter
    $category = $_GET['category'] ?? 'all';
    
    if ($category === 'all') {
        $stmt = $pdo->query("SELECT * FROM idea_log ORDER BY created_at DESC LIMIT 50");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM idea_log WHERE category = :category ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([':category' => $category]);
    }
    
    $thoughts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$icons = [
    'idea' => '💡',
    'decision' => '✅',
    'todo' => '📋',
    'note' => '📝'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <title>QT History</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        h1 {
            font-size: 1.3rem;
            color: #4fc3f7;
        }
        
        .back-link {
            color: #4fc3f7;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 6px 14px;
            border: 2px solid #333;
            border-radius: 20px;
            background: transparent;
            color: #888;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .filter-btn:hover {
            border-color: #4fc3f7;
            color: #4fc3f7;
        }
        
        .filter-btn.active {
            background: #4fc3f7;
            border-color: #4fc3f7;
            color: #1a1a2e;
        }
        
        .thought {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 12px;
            border-left: 4px solid #4fc3f7;
        }
        
        .thought.decision { border-left-color: #4caf50; }
        .thought.todo { border-left-color: #ff9800; }
        .thought.note { border-left-color: #9c27b0; }
        
        .thought-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 0.8rem;
            color: #888;
        }
        
        .thought-category {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .thought-content {
            font-size: 1rem;
            line-height: 1.5;
        }
        
        .thought-source {
            font-size: 0.75rem;
            color: #666;
            margin-top: 8px;
        }
        
        .empty {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        
        .error {
            background: rgba(244, 67, 54, 0.2);
            color: #e57373;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📚 Thought History</h1>
            <a href="index.php" class="back-link">← New thought</a>
        </header>
        
        <div class="filters">
            <a href="?category=all" class="filter-btn <?= $category === 'all' ? 'active' : '' ?>">All</a>
            <a href="?category=idea" class="filter-btn <?= $category === 'idea' ? 'active' : '' ?>">💡 Ideas</a>
            <a href="?category=decision" class="filter-btn <?= $category === 'decision' ? 'active' : '' ?>">✅ Decisions</a>
            <a href="?category=todo" class="filter-btn <?= $category === 'todo' ? 'active' : '' ?>">📋 Todos</a>
            <a href="?category=note" class="filter-btn <?= $category === 'note' ? 'active' : '' ?>">📝 Notes</a>
        </div>
        
        <?php if ($error): ?>
            <div class="error">Database error: <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if (empty($thoughts)): ?>
            <div class="empty">
                <p>No thoughts yet.</p>
                <p><a href="index.php" class="back-link">Add your first one →</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($thoughts as $t): ?>
                <div class="thought <?= htmlspecialchars($t['category']) ?>">
                    <div class="thought-header">
                        <span class="thought-category">
                            <?= $icons[$t['category']] ?? '💭' ?>
                            <?= ucfirst(htmlspecialchars($t['category'])) ?>
                        </span>
                        <span><?= date('M j, g:ia', strtotime($t['created_at'])) ?></span>
                    </div>
                    <div class="thought-content">
                        <?= nl2br(htmlspecialchars($t['content'])) ?>
                    </div>
                    <div class="thought-source">
                        via <?= htmlspecialchars($t['source']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
