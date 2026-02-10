<?php
/**
 * TPB Poll System - Main Page
 * ===========================
 * Vote on polls, see results
 */

// Load TPB config
$config = require __DIR__ . '/../config.php';

// Database connection
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

// Bot protection
$pageLoadTime = time();

// Get session and user
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$dbUser = null;
$canVote = false;
$minorNeedsConsent = false;

if ($sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.first_name, u.last_name, 
               u.current_state_id, u.current_town_id, u.civic_points, u.age_bracket,
               u.parent_consent,
               u.identity_level_id,
               s.abbreviation as state_abbrev, s.state_name,
               tw.town_name,
               il.level_name as identity_level_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $dbUser = $stmt->fetch();
    
    if ($dbUser) {
        // Check if can vote: email verified required
        if ($dbUser['email_verified']) {
            // Check minor consent
            if ($dbUser['age_bracket'] === '13-17') {
                if ($dbUser['parent_consent']) {
                    $canVote = true;
                } else {
                    $minorNeedsConsent = true;
                }
            } else {
                $canVote = true;
            }
        }
    }
}

// Handle vote submission
$voteMessage = '';
$voteError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canVote) {
    // Bot protection checks
    $honeypot = $_POST[$config['bot_detection']['honeypot_field']] ?? '';
    $loadTime = $_POST['load_time'] ?? 0;
    $timeDiff = time() - intval($loadTime);
    
    $isBot = false;
    if (!empty($honeypot)) {
        $isBot = true;
    }
    if ($timeDiff < $config['bot_detection']['min_submit_time']) {
        $isBot = true;
    }
    
    if ($isBot && $config['bot_detection']['enabled']) {
        // Log bot attempt
        $stmt = $pdo->prepare("
            INSERT INTO bot_attempts (ip_address, user_agent, attempt_type, details)
            VALUES (?, ?, 'poll_vote', ?)
        ");
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            json_encode(['honeypot' => !empty($honeypot), 'time_diff' => $timeDiff])
        ]);
        $voteError = 'Vote could not be processed.';
    } else {
        $pollId = intval($_POST['poll_id'] ?? 0);
        $voteChoice = $_POST['vote_choice'] ?? '';
        
        if ($pollId > 0 && in_array($voteChoice, ['yes', 'no'])) {
            // Check if poll is active
            $stmt = $pdo->prepare("SELECT poll_id, active FROM polls WHERE poll_id = ?");
            $stmt->execute([$pollId]);
            $poll = $stmt->fetch();
            
            if ($poll && $poll['active']) {
                // Check if user already voted
                $stmt = $pdo->prepare("SELECT poll_vote_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $stmt->execute([$pollId, $dbUser['user_id']]);
                $existingVote = $stmt->fetch();
                
                if ($existingVote) {
                    // Update existing vote
                    $stmt = $pdo->prepare("UPDATE poll_votes SET vote_choice = ?, updated_at = NOW() WHERE poll_vote_id = ?");
                    $stmt->execute([$voteChoice, $existingVote['poll_vote_id']]);
                    $voteMessage = 'Your vote has been updated.';
                } else {
                    // Insert new vote
                    $stmt = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, vote_choice) VALUES (?, ?, ?)");
                    $stmt->execute([$pollId, $dbUser['user_id'], $voteChoice]);
                    
                    // Award points (first vote only) via PointLogger
                    require_once __DIR__ . '/../includes/point-logger.php';
                    PointLogger::init($pdo);
                    $pointResult = PointLogger::award($dbUser['user_id'], 'poll_voted', 'poll', $pollId);
                    $pollPoints = $pointResult['points_earned'] ?? 0;
                    
                    $dbUser['civic_points'] += $pollPoints;
                    
                    $voteMessage = $pollPoints > 0 
                        ? "Your vote has been recorded. +{$pollPoints} civic points!"
                        : 'Your vote has been recorded.';
                }
            } else {
                $voteError = 'This poll is no longer active.';
            }
        }
    }
}

// Get active polls with results
$polls = [];
$stmt = $pdo->query("
    SELECT p.poll_id, p.slug, p.question, p.active, p.created_at,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yes' THEN 1 ELSE 0 END) as yes_votes,
           SUM(CASE WHEN pv.vote_choice = 'no' THEN 1 ELSE 0 END) as no_votes,
           ROUND(SUM(CASE WHEN pv.vote_choice = 'yes' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pv.poll_vote_id), 0), 1) as yes_percent,
           ROUND(SUM(CASE WHEN pv.vote_choice = 'no' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pv.poll_vote_id), 0), 1) as no_percent
    FROM polls p
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    WHERE p.active = 1
    GROUP BY p.poll_id
    ORDER BY p.created_at DESC
");
$polls = $stmt->fetchAll();

// Get user's votes
$userVotes = [];
if ($dbUser) {
    $stmt = $pdo->prepare("SELECT poll_id, vote_choice FROM poll_votes WHERE user_id = ?");
    $stmt->execute([$dbUser['user_id']]);
    while ($row = $stmt->fetch()) {
        $userVotes[$row['poll_id']] = $row['vote_choice'];
    }
}

// Page title for header
$pageTitle = 'Polls';
$currentPage = 'poll';

// Nav variables via helper
require_once __DIR__ . '/../includes/get-user.php';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
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
        }
        .poll-question {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #e0e0e0;
        }
        .vote-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .vote-btn {
            flex: 1;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            border: 2px solid;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .vote-btn.yes {
            background: transparent;
            border-color: #4caf50;
            color: #4caf50;
        }
        .vote-btn.yes:hover, .vote-btn.yes.selected {
            background: #4caf50;
            color: #fff;
        }
        .vote-btn.no {
            background: transparent;
            border-color: #f44336;
            color: #f44336;
        }
        .vote-btn.no:hover, .vote-btn.no.selected {
            background: #f44336;
            color: #fff;
        }
        .vote-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
            min-width: fit-content;
            padding: 0 0.5rem;
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
            min-width: fit-content;
            padding: 0 0.5rem;
        }
        .results-text {
            font-size: 0.9rem;
            color: #888;
        }
        .your-vote {
            font-size: 0.9rem;
            color: #d4af37;
            font-weight: 500;
            margin-top: 0.5rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
        }
        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            border: 1px solid #4caf50;
        }
        .alert-error {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
            border: 1px solid #f44336;
        }
        .alert-warning {
            background: rgba(255, 152, 0, 0.2);
            color: #ff9800;
            border: 1px solid #ff9800;
        }
        .alert a {
            color: #d4af37;
        }
        .login-prompt {
            text-align: center;
            padding: 2rem;
            background: #1a1a2e;
            border-radius: 8px;
            border: 1px solid #333;
        }
        .login-prompt a {
            color: #d4af37;
            font-weight: 600;
        }
        /* Honeypot */
        .hp-field { position: absolute; left: -9999px; }
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            color: #d4af37;
            margin-bottom: 0.5rem;
        }
        .page-header p {
            color: #888;
        }
    </style>

    <main class="polls-container">
        <div class="page-header">
            <h1>Active Polls</h1>
            <p>Have your voice heard on the issues that matter.</p>
        </div>
        
        <?php if ($voteMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($voteMessage) ?></div>
        <?php endif; ?>
        
        <?php if ($voteError): ?>
            <div class="alert alert-error"><?= htmlspecialchars($voteError) ?></div>
        <?php endif; ?>
        
        <?php if (!$dbUser): ?>
            <div class="login-prompt">
                <p>You need to <a href="/profile.php">create a profile</a> and verify your email to vote.</p>
            </div>
        <?php elseif (!$dbUser['email_verified']): ?>
            <div class="alert alert-warning">
                Please <a href="/profile.php">verify your email</a> to vote on polls.
            </div>
        <?php elseif ($minorNeedsConsent): ?>
            <div class="alert alert-warning">
                Parental consent is required for users aged 13-17. Please <a href="/profile.php">complete the consent process</a>.
            </div>
        <?php endif; ?>
        
        <?php foreach ($polls as $poll): ?>
            <div class="poll-card">
                <div class="poll-question"><?= htmlspecialchars($poll['question']) ?></div>
                
                <?php if ($canVote): ?>
                    <form method="POST" class="vote-form">
                        <input type="hidden" name="poll_id" value="<?= $poll['poll_id'] ?>">
                        <input type="hidden" name="load_time" value="<?= $pageLoadTime ?>">
                        <input type="text" name="<?= $config['bot_detection']['honeypot_field'] ?>" class="hp-field" tabindex="-1" autocomplete="off">
                        
                        <div class="vote-buttons">
                            <button type="submit" name="vote_choice" value="yes" 
                                    class="vote-btn yes <?= ($userVotes[$poll['poll_id']] ?? '') === 'yes' ? 'selected' : '' ?>">
                                👍 Yes
                            </button>
                            <button type="submit" name="vote_choice" value="no" 
                                    class="vote-btn no <?= ($userVotes[$poll['poll_id']] ?? '') === 'no' ? 'selected' : '' ?>">
                                👎 No
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
                
                <?php if (isset($userVotes[$poll['poll_id']]) || !$canVote): ?>
                    <!-- Show results -->
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
                <?php endif; ?>
                
                <?php if (isset($userVotes[$poll['poll_id']])): ?>
                    <div class="your-vote">
                        ✓ You voted: <?= ucfirst($userVotes[$poll['poll_id']]) ?>
                        <span style="color: #666; font-weight: normal;">(click to change)</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($polls)): ?>
            <div class="alert alert-warning">No active polls at this time.</div>
        <?php endif; ?>
        
        <p style="text-align: center; margin-top: 2rem;">
            <a href="/poll/closed/" style="color: #d4af37;">View closed polls →</a>
        </p>
    </main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
