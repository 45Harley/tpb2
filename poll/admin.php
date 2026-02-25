<?php
/**
 * TPB Poll System - Admin
 * ========================
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

// Get session and check admin
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$isAdmin = false;

if (!$dbUser && $sessionId) {
    // Fallback: session-based lookup

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.first_name, u.civic_points,
               u.current_town_id, u.current_state_id,
               s.abbreviation as state_abbrev,
               tw.town_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN user_role_membership urm ON u.user_id = urm.user_id
        LEFT JOIN user_roles ur ON urm.role_id = ur.role_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1 AND ur.role_name = 'Admin'
    ");
    $stmt->execute([$sessionId]);
    $dbUser = $stmt->fetch();
    if ($dbUser) $isAdmin = true;
}

if (!$isAdmin) {
    header('Location: /poll/');
    exit;
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $question = trim($_POST['question'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        
        if ($question && $slug) {
            $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
            $stmt = $pdo->prepare("INSERT INTO polls (question, slug, created_by) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$question, $slug, $dbUser['user_id']]);
                $message = 'Poll created successfully.';
            } catch (PDOException $e) {
                $error = 'Slug already exists or error creating poll.';
            }
        } else {
            $error = 'Question and slug are required.';
        }
    }
    
    if ($action === 'edit') {
        $pollId = intval($_POST['poll_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        if ($pollId && $question) {
            $stmt = $pdo->prepare("UPDATE polls SET question = ? WHERE poll_id = ?");
            $stmt->execute([$question, $pollId]);
            $message = 'Poll updated.';
        }
    }
    
    if ($action === 'close') {
        $pollId = intval($_POST['poll_id'] ?? 0);
        if ($pollId) {
            $stmt = $pdo->prepare("UPDATE polls SET active = 0, closed_at = NOW() WHERE poll_id = ?");
            $stmt->execute([$pollId]);
            $message = 'Poll closed.';
        }
    }
    
    if ($action === 'reopen') {
        $pollId = intval($_POST['poll_id'] ?? 0);
        if ($pollId) {
            $stmt = $pdo->prepare("UPDATE polls SET active = 1, closed_at = NULL WHERE poll_id = ?");
            $stmt->execute([$pollId]);
            $message = 'Poll reopened.';
        }
    }

    if ($action === 'sync_threats') {
        // Create polls for any new 300+ threats that don't have one yet
        $stmt = $pdo->query("
            SELECT et.threat_id, et.title
            FROM executive_threats et
            LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat'
            WHERE et.severity_score >= 300 AND p.poll_id IS NULL
        ");
        $newThreats = $stmt->fetchAll();
        $count = 0;
        foreach ($newThreats as $threat) {
            $slug = 'threat-' . $threat['threat_id'];
            $ins = $pdo->prepare("INSERT INTO polls (question, slug, threat_id, poll_type, created_by) VALUES (?, ?, ?, 'threat', ?)");
            $ins->execute([$threat['title'], $slug, $threat['threat_id'], $dbUser['user_id']]);
            $count++;
        }
        $message = $count > 0 ? "Synced {$count} new threat polls." : 'All threat polls are already synced.';
    }
}

// Get all polls with results
$stmt = $pdo->query("
    SELECT p.poll_id, p.slug, p.question, p.active, p.closed_at, p.created_at, p.poll_type,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice IN ('yes','yea') THEN 1 ELSE 0 END) as yes_votes,
           SUM(CASE WHEN pv.vote_choice IN ('no','nay') THEN 1 ELSE 0 END) as no_votes,
           ROUND(SUM(CASE WHEN pv.vote_choice IN ('yes','yea') THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(pv.poll_vote_id), 0), 1) as yes_percent
    FROM polls p
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    GROUP BY p.poll_id
    ORDER BY p.active DESC, p.created_at DESC
");
$polls = $stmt->fetchAll();

// Threat poll stats
$threatStats = $pdo->query("
    SELECT COUNT(DISTINCT p.poll_id) as threat_polls,
           COUNT(pv.poll_vote_id) as threat_votes,
           COUNT(DISTINCT CASE WHEN et.severity_score >= 300 AND p.threat_id IS NULL THEN et.threat_id END) as unsynced
    FROM executive_threats et
    LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat'
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    WHERE et.severity_score >= 300
")->fetch();

// Get state breakdown for selected poll
$stateBreakdown = [];
$selectedPollId = intval($_GET['breakdown'] ?? 0);
$selectedPollQuestion = '';
if ($selectedPollId) {
    $stmt = $pdo->prepare("
        SELECT s.abbreviation as state, s.state_name,
               COUNT(pv.poll_vote_id) as total_votes,
               SUM(CASE WHEN pv.vote_choice = 'yes' THEN 1 ELSE 0 END) as yes_votes,
               SUM(CASE WHEN pv.vote_choice = 'no' THEN 1 ELSE 0 END) as no_votes
        FROM poll_votes pv
        JOIN users u ON pv.user_id = u.user_id
        JOIN states s ON u.current_state_id = s.state_id
        WHERE pv.poll_id = ?
        GROUP BY s.state_id
        ORDER BY s.state_name
    ");
    $stmt->execute([$selectedPollId]);
    $stateBreakdown = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT question FROM polls WHERE poll_id = ?");
    $stmt->execute([$selectedPollId]);
    $row = $stmt->fetch();
    $selectedPollQuestion = $row['question'] ?? '';
}

$pageTitle = 'Poll Admin';
$currentPage = 'poll';

// Nav variables (admin is already verified)
$trustLevel = 'Admin';
$points = $dbUser ? (int)$dbUser['civic_points'] : 0;
$userTrustLevel = 4; // Admin is highest trust
$userEmail = $dbUser ? ($dbUser['email'] ?? '') : '';
$userTownName = $dbUser ? ($dbUser['town_name'] ?? '') : '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = $dbUser ? strtolower($dbUser['state_abbrev'] ?? '') : '';
$userStateDisplay = $dbUser ? ($dbUser['state_abbrev'] ?? '') : '';
$isLoggedIn = (bool)$dbUser;
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <style>
        .admin-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        .section {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section h2 {
            margin: 0 0 1rem 0;
            color: #d4af37;
            border-bottom: 1px solid #333;
            padding-bottom: 0.5rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #e0e0e0;
        }
        .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #444;
            border-radius: 4px;
            font-size: 1rem;
            background: #0a0a0f;
            color: #e0e0e0;
        }
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-primary { background: #d4af37; color: #000; }
        .btn-danger { background: #f44336; color: #fff; }
        .btn-success { background: #4caf50; color: #fff; }
        .btn-secondary { background: #666; color: #fff; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        th { background: #0a0a0f; color: #d4af37; }
        td { color: #e0e0e0; }
        .status-active { color: #4caf50; font-weight: 600; }
        .status-closed { color: #888; }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }
        .alert-error {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
        }
        .back-link { color: #d4af37; }
        .page-header h1 { color: #d4af37; margin-bottom: 1rem; }
    </style>

    <main class="admin-container">
        <div class="page-header">
            <h1>Poll Administration</h1>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Threat Poll Stats -->
        <div class="section">
            <h2>Threat Polls</h2>
            <div style="display: flex; gap: 2rem; flex-wrap: wrap; margin-bottom: 1rem;">
                <div><span style="font-size: 1.5rem; font-weight: 700; color: #d4af37;"><?= $threatStats['threat_polls'] ?></span><br><small style="color: #888;">Threat Polls</small></div>
                <div><span style="font-size: 1.5rem; font-weight: 700; color: #d4af37;"><?= number_format($threatStats['threat_votes']) ?></span><br><small style="color: #888;">Total Votes</small></div>
                <div><span style="font-size: 1.5rem; font-weight: 700; color: <?= $threatStats['unsynced'] > 0 ? '#f44336' : '#4caf50' ?>;"><?= $threatStats['unsynced'] ?></span><br><small style="color: #888;">Unsynced Threats</small></div>
            </div>
            <?php if ($threatStats['unsynced'] > 0): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="sync_threats">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Create polls for <?= $threatStats['unsynced'] ?> new threat(s)?')">Sync New Threats</button>
                </form>
            <?php else: ?>
                <span style="color: #4caf50; font-size: 0.9rem;">All 300+ threats have polls.</span>
            <?php endif; ?>
        </div>

        <!-- Create Poll -->
        <div class="section">
            <h2>Create New Poll</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label for="question">Question</label>
                    <textarea name="question" id="question" rows="2" required placeholder="Do you support...?"></textarea>
                </div>
                <div class="form-group">
                    <label for="slug">Slug (URL-friendly ID)</label>
                    <input type="text" name="slug" id="slug" required placeholder="my-poll-topic" pattern="[a-z0-9-]+">
                </div>
                <button type="submit" class="btn btn-primary">Create Poll</button>
            </form>
        </div>
        
        <!-- Poll List -->
        <div class="section">
            <h2>All Polls</h2>
            <table>
                <thead>
                    <tr>
                        <th>Question</th>
                        <th>Status</th>
                        <th>Votes</th>
                        <th>Yes %</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($polls as $poll): ?>
                        <tr>
                            <td>
                                <?php if ($poll['poll_type'] === 'threat'): ?>
                                    <span style="display: inline-block; background: #8B0000; color: #fff; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.4rem; border-radius: 3px; vertical-align: middle; margin-right: 0.3rem;">threat</span>
                                <?php else: ?>
                                    <span style="display: inline-block; background: #555; color: #fff; font-size: 0.7rem; font-weight: 700; padding: 0.15rem 0.4rem; border-radius: 3px; vertical-align: middle; margin-right: 0.3rem;">general</span>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($poll['question']) ?></strong><br>
                                <small style="color: #666;"><?= htmlspecialchars($poll['slug']) ?></small>
                            </td>
                            <td>
                                <?php if ($poll['active']): ?>
                                    <span class="status-active">Active</span>
                                <?php else: ?>
                                    <span class="status-closed">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $poll['total_votes'] ?? 0 ?></td>
                            <td><?= $poll['yes_percent'] ?? 0 ?>%</td>
                            <td>
                                <a href="?breakdown=<?= $poll['poll_id'] ?>" class="btn btn-secondary">Stats</a>
                                <?php if ($poll['active']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="close">
                                        <input type="hidden" name="poll_id" value="<?= $poll['poll_id'] ?>">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Close this poll?')">Close</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="reopen">
                                        <input type="hidden" name="poll_id" value="<?= $poll['poll_id'] ?>">
                                        <button type="submit" class="btn btn-success">Reopen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- State Breakdown -->
        <?php if ($selectedPollId): ?>
            <div class="section">
                <h2>State Breakdown: <?= htmlspecialchars($selectedPollQuestion) ?></h2>
                <?php if ($stateBreakdown): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>State</th>
                                <th>Total</th>
                                <th>Yes</th>
                                <th>No</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stateBreakdown as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['state_name']) ?> (<?= $row['state'] ?>)</td>
                                    <td><?= $row['total_votes'] ?></td>
                                    <td><?= $row['yes_votes'] ?></td>
                                    <td><?= $row['no_votes'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="color: #888;">No votes with state data yet.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <p><a href="/poll/" class="back-link">← Back to polls</a></p>
    </main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
