<?php
/**
 * The People's Branch - Task Detail View
 * =======================================
 * Full task details with scripts, prompt, resources
 */

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Get task key from URL
$taskKey = $_GET['key'] ?? null;

if (!$taskKey) {
    header('Location: ./');
    exit;
}

// Get task from database
$stmt = $pdo->prepare("
    SELECT 
        t.*,
        ss.set_name as skill_name,
        u.username as claimed_by_username,
        cu.username as created_by_username
    FROM tasks t
    LEFT JOIN skill_sets ss ON t.skill_set_id = ss.skill_set_id
    LEFT JOIN users u ON t.claimed_by_user_id = u.user_id
    LEFT JOIN users cu ON t.created_by_user_id = cu.user_id
    WHERE t.task_key = ?
");
$stmt->execute([$taskKey]);
$task = $stmt->fetch();

if (!$task) {
    header('Location: ./');
    exit;
}

// Load user — standard method
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Load user data
$isVolunteer = false;

if (!$dbUser && $sessionId) {
    // Fallback: session-based lookup

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.civic_points
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $dbUser = $stmt->fetch();
    
    if ($dbUser) {
        $stmt = $pdo->prepare("SELECT status FROM volunteer_applications WHERE user_id = ? AND status = 'accepted'");
        $stmt->execute([$dbUser['user_id']]);
        $isVolunteer = $stmt->fetch() ? true : false;
    }
}

// Priority colors
$priorityColors = [
    'critical' => '#e74c3c',
    'high' => '#f39c12',
    'medium' => '#3498db',
    'low' => '#2ecc71'
];

// Simple markdown-like parsing for full_content
function parseContent($text) {
    // Headers
    $text = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $text);
    
    // Bold
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    
    // Lists
    $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $text);
    
    // Checkmarks
    $text = str_replace('✓', '<span style="color: #2ecc71;">✓</span>', $text);
    
    // Line breaks
    $text = nl2br($text);
    
    return $text;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($task['title']) ?> | TPB Volunteer</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Source+Sans+Pro:wght@300;400;600;700&display=swap');
        
        :root {
            --gold: #d4af37;
            --gold-light: #ffdb58;
            --gold-dark: #b8960c;
            --bg-dark: #0d0d0d;
            --bg-card: #1a1a1a;
            --bg-hover: #252525;
            --border: #2a2a2a;
            --text: #e0e0e0;
            --text-dim: #888;
            --success: #2ecc71;
            --warning: #f39c12;
            --critical: #e74c3c;
            --info: #3498db;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Source Sans Pro', sans-serif;
            background: var(--bg-dark);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #0d0d0d 100%);
            border-bottom: 2px solid var(--gold);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-family: 'Cinzel', serif;
            font-size: 1.5rem;
            color: var(--gold);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Main Container */
        .main-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.9rem;
            margin-bottom: 20px;
            transition: color 0.2s;
        }
        
        .back-link:hover { color: var(--gold); }
        
        /* Task Header */
        .task-header-card {
            background: var(--bg-card);
            border: 1px solid var(--gold);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
        }
        
        .task-meta {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .task-badge {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .task-title {
            font-family: 'Cinzel', serif;
            font-size: 1.8rem;
            color: var(--gold);
            margin-bottom: 10px;
        }
        
        .task-points-big {
            font-size: 1.5rem;
            color: var(--gold);
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .task-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            font-family: inherit;
        }
        
        .btn-primary {
            background: var(--gold);
            color: var(--bg-dark);
        }
        
        .btn-primary:hover { background: var(--gold-light); }
        
        .btn-secondary {
            background: transparent;
            color: var(--gold);
            border: 1px solid var(--gold);
        }
        
        .btn-secondary:hover {
            background: var(--gold);
            color: var(--bg-dark);
        }
        
        /* Content Sections */
        .content-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-family: 'Cinzel', serif;
            font-size: 1.2rem;
            color: var(--gold);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        
        .section-content {
            color: var(--text);
            line-height: 1.8;
        }
        
        .section-content h3 {
            color: var(--gold);
            margin: 20px 0 10px;
            font-size: 1.1rem;
        }
        
        .section-content h4 {
            color: var(--text);
            margin: 15px 0 8px;
            font-size: 1rem;
        }
        
        .section-content ul {
            margin-left: 20px;
            margin-bottom: 15px;
        }
        
        .section-content li {
            margin-bottom: 5px;
        }
        
        /* Starter Prompt Box */
        .prompt-box {
            background: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            position: relative;
        }
        
        .prompt-box pre {
            white-space: pre-wrap;
            font-family: 'Source Sans Pro', sans-serif;
            font-size: 0.95rem;
            color: var(--text-dim);
        }
        
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 6px 12px;
            background: var(--gold);
            color: var(--bg-dark);
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .copy-btn:hover { background: var(--gold-light); }
        
        /* Assigned Notice */
        .assigned-notice {
            background: rgba(243, 156, 18, 0.1);
            border: 1px solid var(--warning);
            border-radius: 8px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .assigned-notice .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--warning);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: var(--bg-dark);
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            .task-header-card { padding: 20px; }
            .task-title { font-size: 1.4rem; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <a href="/" class="logo">🏛️ TPB</a>
    </header>
    
    <div class="main-container">
        <a href="./" class="back-link">← Back to Tasks</a>
        
        <!-- Task Header Card -->
        <div class="task-header-card">
            <div class="task-meta">
                <span class="task-badge" style="background: rgba(<?= $task['priority'] === 'high' ? '243,156,18' : '52,152,219' ?>, 0.2); color: <?= $priorityColors[$task['priority']] ?>;">
                    <?= $task['priority'] === 'high' || $task['priority'] === 'critical' ? '🔥 ' : '' ?><?= ucfirst($task['priority']) ?> Priority
                </span>
                <span class="task-badge" style="background: rgba(<?= $task['status'] === 'open' ? '46,204,113' : '243,156,18' ?>, 0.2); color: <?= $task['status'] === 'open' ? '#2ecc71' : '#f39c12' ?>;">
                    <?= ucfirst($task['status']) ?>
                </span>
                <?php if ($task['skill_name']): ?>
                <span class="task-badge" style="background: rgba(52,152,219,0.2); color: var(--info);">
                    <?= htmlspecialchars($task['skill_name']) ?>
                </span>
                <?php endif; ?>
            </div>
            
            <h1 class="task-title"><?= htmlspecialchars($task['title']) ?></h1>
            
            <div class="task-points-big">+<?= $task['points'] ?> points</div>
            
            <?php if ($task['status'] !== 'open' && $task['claimed_by_username']): ?>
            <div class="assigned-notice">
                <div class="avatar"><?= strtoupper(substr($task['claimed_by_username'], 0, 1)) ?></div>
                <div>
                    <strong>Assigned to @<?= htmlspecialchars($task['claimed_by_username']) ?></strong>
                    <?php if ($task['claimed_at']): ?>
                    <div style="color: var(--text-dim); font-size: 0.85rem;">
                        Since <?= date('M j, Y', strtotime($task['claimed_at'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($task['status'] === 'open' && $isVolunteer): ?>
            <div class="task-actions">
                <form action="../api/claim.php" method="POST">
                    <input type="hidden" name="task_id" value="<?= $task['task_id'] ?>">
                    <button type="submit" class="btn btn-primary">🙋 I'll Take This Task</button>
                </form>
            </div>
            <?php elseif (!$dbUser): ?>
            <p style="color: var(--text-dim);">Sign in and apply as a volunteer to claim tasks.</p>
            <?php elseif (!$isVolunteer): ?>
            <a href="apply.php" class="btn btn-secondary">Apply to Volunteer</a>
            <?php endif; ?>
        </div>
        
        <!-- Full Content -->
        <?php if ($task['full_content']): ?>
        <div class="content-section">
            <h2 class="section-title">📋 Task Details</h2>
            <div class="section-content">
                <?= parseContent(htmlspecialchars($task['full_content'])) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Starter Prompt -->
        <?php if ($task['starter_prompt']): ?>
        <div class="content-section">
            <h2 class="section-title">🤖 Starter Prompt for Claude</h2>
            <p style="color: var(--text-dim); margin-bottom: 15px;">Copy this prompt to start working with Claude AI:</p>
            <div class="prompt-box">
                <button class="copy-btn" onclick="copyPrompt()">Copy</button>
                <pre id="starterPrompt"><?= htmlspecialchars($task['starter_prompt']) ?></pre>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Scripts -->
        <?php if ($task['scripts']): ?>
        <div class="content-section">
            <h2 class="section-title">📝 Scripts</h2>
            <div class="section-content">
                <?= parseContent(htmlspecialchars($task['scripts'])) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Resources -->
        <?php if ($task['resources']): ?>
        <div class="content-section">
            <h2 class="section-title">🔗 Resources</h2>
            <div class="section-content">
                <?= parseContent(htmlspecialchars($task['resources'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function copyPrompt() {
        const text = document.getElementById('starterPrompt').textContent;
        navigator.clipboard.writeText(text).then(() => {
            const btn = document.querySelector('.copy-btn');
            btn.textContent = 'Copied!';
            setTimeout(() => btn.textContent = 'Copy', 2000);
        });
    }
    </script>
</body>
</html>
