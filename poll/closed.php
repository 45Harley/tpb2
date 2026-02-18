<?php
/**
 * TPB Poll System - Closed Polls
 * ===============================
 */

$config = require __DIR__ . '/../config.php';

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

// Get closed polls with results
$stmt = $pdo->query("
    SELECT p.poll_id, p.slug, p.question, p.active, p.closed_at,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yes' THEN 1 ELSE 0 END) as yes_votes,
           SUM(CASE WHEN pv.vote_choice = 'no' THEN 1 ELSE 0 END) as no_votes,
           ROUND(SUM(CASE WHEN pv.vote_choice = 'yes' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pv.poll_vote_id), 0), 1) as yes_percent,
           ROUND(SUM(CASE WHEN pv.vote_choice = 'no' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pv.poll_vote_id), 0), 1) as no_percent
    FROM polls p
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    WHERE p.active = 0
    GROUP BY p.poll_id
    ORDER BY p.closed_at DESC
");
$polls = $stmt->fetchAll();

// Get user data
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$pageTitle = 'Closed Polls';
$currentPage = 'poll';
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <style>
        .polls-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .poll-card {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            opacity: 0.85;
        }
        .poll-question {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #e0e0e0;
        }
        .closed-badge {
            display: inline-block;
            background: #666;
            color: #fff;
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            margin-left: 0.5rem;
            vertical-align: middle;
        }
        .results-bar {
            height: 30px;
            background: #2a2a3e;
            border-radius: 15px;
            overflow: hidden;
            display: flex;
            margin-bottom: 0.5rem;
        }
        .results-yes {
            background: #4caf50;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .results-no {
            background: #f44336;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .results-text {
            font-size: 0.9rem;
            color: #888;
        }
        .closed-date {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        .no-polls {
            text-align: center;
            padding: 2rem;
            background: #1a1a2e;
            border-radius: 8px;
            color: #888;
        }
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            color: #d4af37;
            margin-bottom: 0.5rem;
        }
        .back-link {
            color: #d4af37;
            margin-bottom: 1rem;
            display: inline-block;
        }
    </style>

    <main class="polls-container">
        <a href="/poll/" class="back-link">← Back to active polls</a>
        
        <div class="page-header">
            <h1>Closed Polls</h1>
            <p style="color: #888;">Final results from past polls.</p>
        </div>
        
        <?php foreach ($polls as $poll): ?>
            <div class="poll-card">
                <div class="poll-question">
                    <?= htmlspecialchars($poll['question']) ?>
                    <span class="closed-badge">CLOSED</span>
                </div>
                
                <div class="results-bar">
                    <?php if ($poll['total_votes'] > 0): ?>
                        <div class="results-yes" style="width: <?= $poll['yes_percent'] ?: 0 ?>%">
                            <?= $poll['yes_percent'] ?: 0 ?>%
                        </div>
                        <div class="results-no" style="width: <?= $poll['no_percent'] ?: 0 ?>%">
                            <?= $poll['no_percent'] ?: 0 ?>%
                        </div>
                    <?php endif; ?>
                </div>
                <div class="results-text">
                    <?= $poll['total_votes'] ?> vote<?= $poll['total_votes'] != 1 ? 's' : '' ?>
                    (<?= $poll['yes_votes'] ?: 0 ?> yes, <?= $poll['no_votes'] ?: 0 ?> no)
                </div>
                
                <?php if ($poll['closed_at']): ?>
                    <div class="closed-date">
                        Closed: <?= date('M j, Y', strtotime($poll['closed_at'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($polls)): ?>
            <div class="no-polls">No closed polls yet.</div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
