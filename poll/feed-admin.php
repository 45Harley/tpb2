<?php
/**
 * Feed Admin — Civic Engine feed management.
 *
 * Shows sync controls, stats, and recent auto-generated ballots.
 * Requires admin role.
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

// Auth: getUser + admin check
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

$isAdmin = false;
if ($dbUser) {
    $adminCheck = $pdo->prepare(
        "SELECT 1 FROM user_role_membership WHERE user_id = :uid AND role_id = 1 LIMIT 1"
    );
    $adminCheck->execute([':uid' => $dbUser['user_id']]);
    $isAdmin = (bool) $adminCheck->fetchColumn();
}

if (!$isAdmin) {
    header('Location: /poll/');
    exit;
}

// Load FeedEngine for server-side stats
require_once __DIR__ . '/../includes/feed-engine.php';

// Get stats
$stats = FeedEngine::getStats($pdo);

// Get unsynced threat count
$unsyncedStmt = $pdo->query("
    SELECT COUNT(*) FROM executive_threats et
    LEFT JOIN polls p1 ON p1.source_type = 'threat' AND p1.source_id = et.threat_id
    LEFT JOIN polls p2 ON p2.threat_id = et.threat_id AND p2.poll_type = 'threat'
    WHERE et.severity_score >= 300
      AND p1.poll_id IS NULL
      AND p2.poll_id IS NULL
");
$unsyncedThreats = (int) $unsyncedStmt->fetchColumn();

// Get recent auto-generated polls
$recentStmt = $pdo->query("
    SELECT p.poll_id, p.question, p.source_type, p.source_id, p.scope_type,
           p.active, p.created_at,
           COUNT(DISTINCT pv.user_id) AS total_votes
    FROM polls p
    LEFT JOIN poll_votes pv ON pv.poll_id = p.poll_id
    WHERE p.source_type IS NOT NULL AND p.source_type != 'manual'
    GROUP BY p.poll_id
    ORDER BY p.created_at DESC
    LIMIT 25
");
$recentPolls = $recentStmt->fetchAll();

$pageTitle = 'Feed Admin - Civic Engine';
$currentPage = 'poll';

// Nav variables
$trustLevel = 'Admin';
$points = $dbUser ? (int) $dbUser['civic_points'] : 0;
$userTrustLevel = 4;
$userEmail = $dbUser['email'] ?? '';
$userTownName = '';
$userTownSlug = '';
$userStateAbbr = '';
$userStateDisplay = '';
$isLoggedIn = true;

// Try to get town/state for nav
if (!empty($dbUser['current_state_id'])) {
    $stStmt = $pdo->prepare("SELECT abbreviation FROM states WHERE state_id = :sid LIMIT 1");
    $stStmt->execute([':sid' => $dbUser['current_state_id']]);
    $abbr = $stStmt->fetchColumn();
    if ($abbr) {
        $userStateAbbr = strtolower($abbr);
        $userStateDisplay = strtoupper($abbr);
    }
}
if (!empty($dbUser['current_town_id'])) {
    $twStmt = $pdo->prepare("SELECT town_name FROM towns WHERE town_id = :tid LIMIT 1");
    $twStmt->execute([':tid' => $dbUser['current_town_id']]);
    $tn = $twStmt->fetchColumn();
    if ($tn) {
        $userTownName = $tn;
        $userTownSlug = strtolower(str_replace(' ', '-', $tn));
    }
}
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
    <link rel="stylesheet" href="/assets/feed.css">
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <main class="feed-admin-container">
        <div class="feed-header">
            <h1>Civic Engine Feed</h1>
            <p class="feed-subtitle">Auto-generated ballots from threats, bills, executive orders, and declarations</p>
        </div>

        <!-- Status messages (populated by JS) -->
        <div id="feedMessage" class="feed-message" style="display: none;"></div>

        <!-- Stats Dashboard -->
        <div class="feed-section">
            <h2>Feed Statistics</h2>
            <div class="feed-stats-grid">
                <div class="feed-stat-card">
                    <span class="feed-stat-number" style="color: #e74c3c;"><?= $stats['threat'] ?></span>
                    <span class="feed-stat-label">Threat Polls</span>
                </div>
                <div class="feed-stat-card">
                    <span class="feed-stat-number" style="color: #3498db;"><?= $stats['bill'] ?></span>
                    <span class="feed-stat-label">Bill Polls</span>
                </div>
                <div class="feed-stat-card">
                    <span class="feed-stat-number" style="color: #e67e22;"><?= $stats['executive_order'] ?></span>
                    <span class="feed-stat-label">Exec Order Polls</span>
                </div>
                <div class="feed-stat-card">
                    <span class="feed-stat-number" style="color: #9b59b6;"><?= $stats['group'] ?></span>
                    <span class="feed-stat-label">Group Polls</span>
                </div>
                <div class="feed-stat-card">
                    <span class="feed-stat-number" style="color: #d4af37;"><?= $stats['total'] ?></span>
                    <span class="feed-stat-label">Total Polls</span>
                </div>
            </div>
        </div>

        <!-- Sync Controls -->
        <div class="feed-section">
            <h2>Sync Controls</h2>
            <div class="feed-sync-grid">
                <div class="feed-sync-card">
                    <div class="feed-sync-header">
                        <h3>Threats</h3>
                        <?php if ($unsyncedThreats > 0): ?>
                            <span class="feed-badge feed-badge-alert"><?= $unsyncedThreats ?> unsynced</span>
                        <?php else: ?>
                            <span class="feed-badge feed-badge-ok">all synced</span>
                        <?php endif; ?>
                    </div>
                    <p class="feed-sync-desc">Severity 300+ threats from executive_threats</p>
                    <button class="btn btn-sync" onclick="feedSync('threats')" id="syncThreatsBtn">Sync Threats</button>
                </div>

                <div class="feed-sync-card">
                    <div class="feed-sync-header">
                        <h3>Bills</h3>
                        <span class="feed-badge feed-badge-stub">stub</span>
                    </div>
                    <p class="feed-sync-desc">Bills table not yet created</p>
                    <button class="btn btn-sync" onclick="feedSync('bills')" id="syncBillsBtn">Sync Bills</button>
                </div>

                <div class="feed-sync-card">
                    <div class="feed-sync-header">
                        <h3>Executive Orders</h3>
                        <span class="feed-badge feed-badge-stub">stub</span>
                    </div>
                    <p class="feed-sync-desc">Executive orders table not yet created</p>
                    <button class="btn btn-sync" onclick="feedSync('executive_orders')" id="syncEOBtn">Sync Orders</button>
                </div>

                <div class="feed-sync-card">
                    <div class="feed-sync-header">
                        <h3>Declarations</h3>
                        <span class="feed-badge feed-badge-stub">stub</span>
                    </div>
                    <p class="feed-sync-desc">Ratified declarations escalate to parent scope</p>
                    <button class="btn btn-sync" onclick="feedSync('declarations')" id="syncDeclBtn">Sync Declarations</button>
                </div>
            </div>

            <div style="margin-top: 1.5rem; text-align: center;">
                <button class="btn btn-primary btn-sync-all" onclick="feedSyncAll()" id="syncAllBtn">Sync All Sources</button>
            </div>
        </div>

        <!-- Recent Auto-Generated Polls -->
        <div class="feed-section">
            <h2>Recent Auto-Generated Ballots</h2>
            <?php if (empty($recentPolls)): ?>
                <p class="feed-empty">No auto-generated polls yet. Run a sync to create them.</p>
            <?php else: ?>
                <div class="feed-table-wrapper">
                    <table class="feed-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Question</th>
                                <th>Scope</th>
                                <th>Votes</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPolls as $poll): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $badgeClass = 'feed-source-' . ($poll['source_type'] ?? 'manual');
                                        $sourceLabel = $poll['source_type'] ?? 'manual';
                                        ?>
                                        <span class="feed-source-badge <?= $badgeClass ?>"><?= htmlspecialchars($sourceLabel) ?></span>
                                    </td>
                                    <td class="feed-question-cell">
                                        <?= htmlspecialchars(mb_strimwidth($poll['question'], 0, 100, '...')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($poll['scope_type']) ?></td>
                                    <td><?= (int) $poll['total_votes'] ?></td>
                                    <td>
                                        <?php if ($poll['active']): ?>
                                            <span class="feed-status-active">Active</span>
                                        <?php else: ?>
                                            <span class="feed-status-closed">Closed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="feed-date-cell"><?= date('M j, Y', strtotime($poll['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <p class="feed-back-link"><a href="/poll/admin.php">Back to Poll Admin</a></p>
    </main>

    <script src="/assets/feed.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
