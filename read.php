<?php
/**
 * The People's Branch - Read
 * ===========================
 * Public thoughts. Anyone can see.
 * Prominent verify CTA at top.
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

// Check if user is verified on this device
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$isVerified = false;
$userName = '';

if ($sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.first_name, COALESCE(uis.email_verified, 0) as email_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch();
    
    if ($user && $user['email_verified']) {
        $isVerified = true;
        $userName = $user['first_name'];
    }
}

// Filter
$filter = $_GET['filter'] ?? 'all';

// Get thoughts
$query = "
    SELECT 
        t.thought_id,
        t.content,
        t.is_local,
        t.is_state,
        t.is_federal,
        t.is_legislative,
        t.is_executive,
        t.is_judicial,
        t.other_topic,
        t.created_at,
        t.upvotes,
        t.downvotes,
        c.category_name,
        c.icon,
        s.abbreviation as state_abbrev,
        tw.town_name,
        u.first_name,
        u.last_name,
        u.age_bracket,
        u.show_first_name,
        u.show_last_name,
        u.show_age_bracket
    FROM user_thoughts t
    LEFT JOIN thought_categories c ON t.category_id = c.category_id
    LEFT JOIN states s ON t.state_id = s.state_id
    LEFT JOIN towns tw ON t.town_id = tw.town_id
    LEFT JOIN users u ON t.user_id = u.user_id
    WHERE t.status = 'published'
";

if ($filter === 'local') {
    $query .= " AND t.is_local = 1";
} elseif ($filter === 'state') {
    $query .= " AND t.is_state = 1";
} elseif ($filter === 'federal') {
    $query .= " AND t.is_federal = 1";
}

$query .= " ORDER BY t.created_at DESC LIMIT 50";

$thoughts = $pdo->query($query)->fetchAll();

// Count
$totalCount = $pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'published'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Read | The People's Branch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Georgia', serif;
            background: #0a0a0a;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }
        
        .logo {
            font-size: 2em;
            margin-bottom: 5px;
        }
        
        h1 {
            color: #d4af37;
            font-size: 1.4em;
            margin-bottom: 5px;
        }
        
        .tagline {
            color: #888;
            font-size: 0.9em;
        }
        
        .count {
            color: #d4af37;
            margin-top: 10px;
            font-size: 0.9em;
        }
        
        /* CTA Box - Prominent at top */
        .cta-top {
            text-align: center;
            margin-bottom: 25px;
            padding: 20px;
            background: #1a1a1a;
            border-radius: 10px;
            border: 1px solid #333;
        }
        
        .cta-top.verified {
            background: #1a2a1a;
            border-color: #4caf50;
        }
        
        .cta-top p {
            color: #888;
            margin-bottom: 12px;
        }
        
        .cta-top .welcome {
            color: #4caf50;
            font-weight: bold;
        }
        
        /* Dark blue button - readable */
        .cta-btn {
            display: inline-block;
            background: #1a3a5c;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.2s;
            font-size: 1em;
        }
        
        .cta-btn:hover {
            background: #2a4a6c;
        }
        
        .cta-btn .subtext {
            display: block;
            font-size: 0.75em;
            font-weight: normal;
            margin-top: 4px;
            opacity: 0.9;
        }
        
        .speak-btn {
            background: #2e7d32;
        }
        
        .speak-btn:hover {
            background: #388e3c;
        }
        
        /* Filter */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .filter-btn {
            padding: 8px 16px;
            background: #1a1a1a;
            border: 1px solid #333;
            color: #888;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85em;
            transition: all 0.2s;
        }
        
        .filter-btn:hover {
            border-color: #d4af37;
            color: #d4af37;
        }
        
        .filter-btn.active {
            background: #1a3a5c;
            color: #fff;
            border-color: #1a3a5c;
        }
        
        /* Thoughts */
        .thought {
            background: #1a1a1a;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 3px solid #d4af37;
        }
        
        .thought-meta {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            font-size: 0.8em;
            color: #888;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .thought-location {
            color: #888;
        }
        
        .thought-category {
            background: #2a2a3a;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .thought-content {
            font-size: 1.05em;
            line-height: 1.7;
            margin-bottom: 12px;
        }
        
        .thought-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .badge {
            font-size: 0.7em;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .badge.jurisdiction {
            background: rgba(212, 175, 55, 0.2);
            color: #d4af37;
        }
        
        .badge.branch {
            background: rgba(52, 152, 219, 0.2);
            color: #5dade2;
        }
        
        .thought-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85em;
            color: #666;
        }
        
        .votes {
            display: flex;
            gap: 15px;
        }
        
        .votes span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Empty */
        .empty {
            text-align: center;
            padding: 50px 20px;
            color: #666;
        }
        
        .empty h3 {
            color: #d4af37;
            margin-bottom: 10px;
        }
        
        /* Bottom CTA */
        .cta-bottom {
            text-align: center;
            margin: 30px 0;
            padding: 25px;
            background: #1a1a1a;
            border-radius: 10px;
            border: 1px solid #333;
        }
        
        .cta-bottom p {
            color: #888;
            margin-bottom: 15px;
        }
        
        /* Footer */
        footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #333;
            color: #555;
            font-size: 0.85em;
        }
        
        footer a {
            color: #888;
            text-decoration: none;
        }
        
        footer a:hover {
            color: #d4af37;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">🏛️</div>
            <h1>The People's Branch</h1>
            <p class="tagline">What people are thinking</p>
            <p class="count"><?= $totalCount ?> civic thoughts</p>
        </header>
        
        <!-- CTA at top - changes based on verification status -->
        <?php if ($isVerified): ?>
        <div class="cta-top verified">
            <p class="welcome">Welcome back<?= $userName ? ', ' . htmlspecialchars($userName) : '' ?>!</p>
            <a href="thought.php" class="cta-btn speak-btn">
                📝 Share Your Thought
            </a>
        </div>
        <?php else: ?>
        <div class="cta-top">
            <p>Want to add your voice?</p>
            <a href="join.php" class="cta-btn">
                Continue with Email
                <span class="subtext">one-time verify per device</span>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Filter -->
        <div class="filter-bar">
            <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="?filter=local" class="filter-btn <?= $filter === 'local' ? 'active' : '' ?>">🏠 Local</a>
            <a href="?filter=state" class="filter-btn <?= $filter === 'state' ? 'active' : '' ?>">🗺️ State</a>
            <a href="?filter=federal" class="filter-btn <?= $filter === 'federal' ? 'active' : '' ?>">🇺🇸 Federal</a>
        </div>
        
        <!-- Thoughts -->
        <?php if (empty($thoughts)): ?>
        <div class="empty">
            <h3>No thoughts yet</h3>
            <p>Be the first to share what matters.</p>
        </div>
        <?php else: ?>
            <?php foreach ($thoughts as $thought): ?>
            <div class="thought">
                <div class="thought-meta">
                    <span class="thought-location">
                        <?php if ($thought['town_name']): ?>
                            <?= htmlspecialchars($thought['town_name']) ?>,
                        <?php endif; ?>
                        <?php if ($thought['state_abbrev']): ?>
                            <?= htmlspecialchars($thought['state_abbrev']) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($thought['category_name']): ?>
                    <span class="thought-category">
                        <?= htmlspecialchars($thought['icon'] . ' ' . $thought['category_name']) ?>
                        <?php if ($thought['other_topic']): ?>
                            : <?= htmlspecialchars($thought['other_topic']) ?>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <div class="thought-content">
                    <?= htmlspecialchars($thought['content']) ?>
                </div>
                
                <div class="thought-badges">
                    <?php if ($thought['is_local']): ?>
                        <span class="badge jurisdiction">🏠 Local</span>
                    <?php endif; ?>
                    <?php if ($thought['is_state']): ?>
                        <span class="badge jurisdiction">🗺️ State</span>
                    <?php endif; ?>
                    <?php if ($thought['is_federal']): ?>
                        <span class="badge jurisdiction">🇺🇸 Federal</span>
                    <?php endif; ?>
                    <?php if ($thought['is_legislative']): ?>
                        <span class="badge branch">⚖️ Legislative</span>
                    <?php endif; ?>
                    <?php if ($thought['is_executive']): ?>
                        <span class="badge branch">🏛️ Executive</span>
                    <?php endif; ?>
                    <?php if ($thought['is_judicial']): ?>
                        <span class="badge branch">👨‍⚖️ Judicial</span>
                    <?php endif; ?>
                </div>
                
                <div class="thought-footer">
                    <span>
                        <?= date('M j, Y', strtotime($thought['created_at'])) ?>
                        <?php
                        // Build author display name based on preferences
                        $authorParts = [];
                        $showFirst = $thought['show_first_name'] ?? 1;
                        $showLast = $thought['show_last_name'] ?? 0;
                        if ($showFirst && !empty($thought['first_name'])) {
                            $authorParts[] = $thought['first_name'];
                        }
                        if ($showLast && !empty($thought['last_name'])) {
                            $authorParts[] = $thought['last_name'];
                        }
                        $authorName = !empty($authorParts) ? implode(' ', $authorParts) : 'Anonymous';
                        $ageDisplay = (!empty($thought['show_age_bracket']) && !empty($thought['age_bracket'])) 
                            ? ' (' . htmlspecialchars($thought['age_bracket']) . ')' 
                            : '';
                        ?>
                        — <?= htmlspecialchars($authorName) ?><?= $ageDisplay ?>
                    </span>
                    <div class="votes">
                        <span>👍 <?= $thought['upvotes'] ?></span>
                        <span>👎 <?= $thought['downvotes'] ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Bottom CTA -->
        <?php if (!$isVerified): ?>
        <div class="cta-bottom">
            <p>Ready to add your voice?</p>
            <a href="join.php" class="cta-btn">
                Continue with Email
                <span class="subtext">one-time verify per device</span>
            </a>
        </div>
        <?php endif; ?>
        
        <footer>
            <a href="index.php">Full Platform</a> · 
            <a href="join.php">Join</a> · 
            <a href="thought.php">Share a thought</a>
        </footer>
    </div>
</body>
</html>
