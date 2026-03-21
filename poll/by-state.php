<?php
/**
 * TPB Poll — By State View
 * ========================
 * Landing: 50-state table with vote counts.
 * Detail: per-state citizen votes vs national for each threat.
 */

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/severity.php';

$dbUser = getUser($pdo);
$stateCode = strtoupper(trim($_GET['state'] ?? ''));

// All states for switcher
$allStates = $pdo->query("SELECT state_id, abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

// Town filter
$townFilter = trim($_GET['town'] ?? '');
$townInfo = null;

// If no state specified, default to logged-in user's state
if (!$stateCode && $dbUser && !empty($dbUser['current_state_id'])) {
    $stmt = $pdo->prepare("SELECT abbreviation FROM states WHERE state_id = ?");
    $stmt->execute([$dbUser['current_state_id']]);
    $stateCode = $stmt->fetchColumn() ?: '';
}
// If no town specified, default to logged-in user's town
if (!$townFilter && $dbUser && !empty($dbUser['current_town_id'])) {
    $townFilter = (string)$dbUser['current_town_id'];
}

// State detail mode
$state = null;
$stateThreats = [];
$nationalData = [];

if ($stateCode) {
    $stmt = $pdo->prepare("SELECT state_id, state_name, abbreviation FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateCode]);
    $state = $stmt->fetch();
}

// Town lookup
$stateTowns = [];
if ($state) {
    $stmt = $pdo->prepare("SELECT town_id, town_name FROM towns WHERE state_id = ? ORDER BY town_name");
    $stmt->execute([$state['state_id']]);
    $stateTowns = $stmt->fetchAll();

    if ($townFilter) {
        $stmt = $pdo->prepare("SELECT town_id, town_name FROM towns WHERE state_id = ? AND (town_name = ? OR town_id = ?)");
        $stmt->execute([$state['state_id'], $townFilter, (int)$townFilter]);
        $townInfo = $stmt->fetch();
    }
}

if ($state) {
    $geoLabel = $state['state_name'];
    if ($townInfo) {
        $geoLabel = $townInfo['town_name'] . ', ' . $state['abbreviation'];
    }

    // Only show polls scoped to this state (or town within it)
    $scopeWhere = "(p.scope_type = 'state' AND p.scope_id = ?)";
    $scopeParams = [$state['state_id']];
    if ($townInfo) {
        $scopeWhere = "(p.scope_type = 'town' AND p.scope_id = ?)";
        $scopeParams = [$townInfo['town_id']];
    }

    $stmt = $pdo->prepare("
        SELECT p.poll_id, p.slug, p.question, p.poll_type, p.scope_type, p.created_at,
               COUNT(pv.poll_vote_id) as total_votes,
               SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_count,
               SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_count
        FROM polls p
        LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id AND pv.is_rep_vote = 0
        WHERE {$scopeWhere} AND p.active = 1
        GROUP BY p.poll_id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute($scopeParams);
    $statePolls = $stmt->fetchAll();

}

// Landing mode: all states with vote counts
$stateList = [];
if (!$state) {
    $stateList = $pdo->query("
        SELECT s.abbreviation, s.state_name,
               COUNT(DISTINCT pv.poll_vote_id) as total_votes,
               COUNT(DISTINCT pv.user_id) as unique_voters
        FROM states s
        LEFT JOIN users u ON u.current_state_id = s.state_id AND u.deleted_at IS NULL
        LEFT JOIN poll_votes pv ON pv.user_id = u.user_id AND pv.is_rep_vote = 0
        LEFT JOIN polls p ON pv.poll_id = p.poll_id AND p.poll_type = 'threat'
        GROUP BY s.state_id
        ORDER BY s.state_name
    ")->fetchAll();
}

$pageTitle = $state ? "{$state['state_name']} — Poll Results" : 'Results By State — Polls';
$currentPage = 'poll';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <style>
        .polls-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }
        .page-header { margin-bottom: 1rem; }
        .page-header h1 { color: #d4af37; margin-bottom: 0.25rem; }
        .page-header .subtitle { color: #888; }
        .breadcrumb { font-size: 0.85rem; color: #888; margin-bottom: 1rem; }
        .breadcrumb a { color: #d4af37; text-decoration: none; }

        .intro-box {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #ccc;
            font-size: 0.9rem; line-height: 1.6;
        }
        .intro-box p { margin: 0 0 0.4rem; }
        .intro-box a { color: #d4af37; }

        .view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .view-links a {
            padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
            text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
        }
        .view-links a:hover { border-color: #d4af37; color: #d4af37; }
        .view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

        /* State table */
        .state-table { width: 100%; border-collapse: collapse; }
        .state-table th, .state-table td { padding: 0.6rem 0.75rem; text-align: left; border-bottom: 1px solid #333; }
        .state-table th { background: #0a0a0f; color: #d4af37; font-size: 0.85rem; }
        .state-table td { color: #e0e0e0; font-size: 0.9rem; }
        .state-table tr:hover td { background: rgba(212,175,55,0.05); }
        .state-table a { color: #d4af37; text-decoration: none; font-weight: 600; }

        /* Threat rows for state detail */
        .threat-row {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1rem; margin-bottom: 0.75rem;
        }
        .threat-row-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
        .severity-badge {
            display: inline-block; padding: 0.2rem 0.6rem; border-radius: 4px;
            font-size: 0.75rem; font-weight: 700; color: #fff; white-space: nowrap;
        }
        .threat-title { font-size: 0.95rem; font-weight: 600; color: #e0e0e0; flex: 1; }

        .comparison { display: flex; gap: 1rem; flex-wrap: wrap; }
        .comparison-col { flex: 1; min-width: 200px; }
        .comparison-col .col-label { font-size: 0.75rem; color: #888; margin-bottom: 0.3rem; font-weight: 600; }
        .results-bar {
            height: 20px; background: #2a2a3e; border-radius: 10px;
            overflow: hidden; display: flex; margin-bottom: 0.25rem;
        }
        .results-yea { background: #4caf50; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.7rem; padding: 0 0.3rem; }
        .results-nay { background: #f44336; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.7rem; padding: 0 0.3rem; }
        .results-abstain-seg { background: #666; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.7rem; padding: 0 0.3rem; }
        .results-text { font-size: 0.75rem; color: #888; }
        .no-votes { color: #555; font-size: 0.8rem; font-style: italic; }

        /* Geo filters */
        .geo-filters {
            display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .geo-filters label { color: #888; font-size: 0.85rem; font-weight: 600; }
        .geo-filters select {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #444;
            background: #1a1a2e; color: #e0e0e0; font-size: 0.85rem;
        }

        @media (max-width: 600px) {
            .threat-row-header { flex-direction: column; align-items: flex-start; }
            .comparison { flex-direction: column; }
            .geo-filters { flex-direction: column; align-items: flex-start; }
        }
    </style>

    <main class="polls-container">
        <?php if ($state): ?>
            <div class="breadcrumb"><a href="/poll/by-state/">By State</a> &rsaquo; <?= htmlspecialchars($state['state_name']) ?><?= $townInfo ? ' &rsaquo; ' . htmlspecialchars($townInfo['town_name']) : '' ?></div>
            <div class="page-header">
                <h1><?= $townInfo ? htmlspecialchars($townInfo['town_name']) . ', ' : '' ?><?= htmlspecialchars($state['state_name']) ?></h1>
                <p class="subtitle">How <?= htmlspecialchars($geoLabel) ?> citizens voted vs. the nation.</p>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h1>Results By State</h1>
                <p class="subtitle">See how each state responded.</p>
            </div>
        <?php endif; ?>

        <div class="view-links">
            <a href="/poll/">Vote</a>
            <a href="/poll/national/">National</a>
            <a href="/poll/by-state/<?= $stateCode ? '?state=' . urlencode($stateCode) . ($townInfo ? '&town=' . urlencode($townInfo['town_id']) : '') : '' ?>" class="active">By State</a>
            <a href="/poll/by-rep/<?= $stateCode ? '?state_filter=' . urlencode($stateCode) : '' ?>">By Rep</a>
        </div>

        <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>

        <?php if ($state): ?>
            <!-- Filters -->
            <div class="geo-filters">
                <label>State:</label>
                <select id="stateFilter" onchange="switchGeo()">
                    <?php foreach ($allStates as $s): ?>
                    <option value="<?= $s['abbreviation'] ?>" <?= $stateCode === $s['abbreviation'] ? 'selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($stateTowns)): ?>
                <label>Town:</label>
                <select id="townFilter" onchange="switchGeo()">
                    <option value="">All <?= htmlspecialchars($state['state_name']) ?></option>
                    <?php foreach ($stateTowns as $t): ?>
                    <option value="<?= (int)$t['town_id'] ?>" <?= ($townInfo && $townInfo['town_id'] == $t['town_id']) ? 'selected' : '' ?>><?= htmlspecialchars($t['town_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <!-- State/town polls -->
            <?php if (empty($statePolls)): ?>
                <div class="intro-box" style="text-align:center;">
                    <p style="color:#d4af37;font-weight:600;">No <?= htmlspecialchars($geoLabel) ?> polls yet</p>
                    <p>When polls are created for <?= htmlspecialchars($geoLabel) ?>, they'll appear here. Federal threat polls are on the <a href="/poll/">Vote</a> page.</p>
                </div>
            <?php else: ?>
                <div class="intro-box">
                    <p><?= count($statePolls) ?> poll<?= count($statePolls) !== 1 ? 's' : '' ?> for <?= htmlspecialchars($geoLabel) ?>.</p>
                </div>
                <?php foreach ($statePolls as $poll):
                    $pTotal = (int)$poll['total_votes'];
                    $pYeaPct = $pTotal > 0 ? round($poll['yea_count'] / $pTotal * 100, 1) : 0;
                    $pNayPct = $pTotal > 0 ? round($poll['nay_count'] / $pTotal * 100, 1) : 0;
                ?>
                <div class="threat-row">
                    <div class="threat-row-header">
                        <span class="threat-title"><?= htmlspecialchars($poll['question']) ?></span>
                        <span style="color:#666;font-size:0.75rem;margin-left:auto;"><?= date('M j, Y', strtotime($poll['created_at'])) ?></span>
                    </div>
                    <div class="comparison">
                        <div class="comparison-col">
                            <div class="col-label"><?= $pTotal ?> vote<?= $pTotal !== 1 ? 's' : '' ?></div>
                            <?php if ($pTotal > 0): ?>
                            <div class="results-bar">
                                <?php if ($pYeaPct > 0): ?><div class="results-yea" style="width:<?= $pYeaPct ?>%"><?= $pYeaPct ?>%</div><?php endif; ?>
                                <?php if ($pNayPct > 0): ?><div class="results-nay" style="width:<?= $pNayPct ?>%"><?= $pNayPct ?>%</div><?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="no-votes">No votes yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php else: ?>
            <?php if ($dbUser && empty($dbUser['current_state_id'])): ?>
            <!-- Profile nudge -->
            <div class="intro-box" style="border-color:#d4af37;text-align:center;">
                <p style="color:#d4af37;font-weight:600;">Add your location to see local polls</p>
                <p><a href="/profile.php">Update your profile</a> with your state and town to see polls for your area.</p>
            </div>
            <?php elseif (!$dbUser): ?>
            <div class="intro-box" style="border-color:#d4af37;text-align:center;">
                <p style="color:#d4af37;font-weight:600;">Join to see local polls</p>
                <p><a href="/join.php">Create an account</a> with your location to see polls for your state and town.</p>
            </div>
            <?php endif; ?>

            <!-- State picker -->
            <div class="geo-filters">
                <label>Jump to state:</label>
                <select id="stateFilter" onchange="switchGeo()">
                    <option value="">Choose a state...</option>
                    <?php foreach ($allStates as $s): ?>
                    <option value="<?= $s['abbreviation'] ?>"><?= htmlspecialchars($s['state_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Federal polls as default -->
            <div class="intro-box">
                <p>Showing <strong style="color:#d4af37">federal polls</strong> &mdash; select a state above to see state and town polls.</p>
            </div>

            <?php
            $federalPolls = $pdo->query("
                SELECT p.poll_id, p.question, p.threat_id, p.created_at as poll_created_at,
                       et.severity_score, et.title,
                       COUNT(pv.poll_vote_id) as total_votes,
                       SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_count,
                       SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_count
                FROM polls p
                LEFT JOIN executive_threats et ON p.threat_id = et.threat_id
                LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id AND pv.is_rep_vote = 0
                WHERE p.scope_type = 'federal' AND p.active = 1
                GROUP BY p.poll_id
                HAVING total_votes > 0
                ORDER BY p.created_at DESC
                LIMIT 50
            ")->fetchAll();
            ?>

            <?php if (empty($federalPolls)): ?>
                <div class="intro-box" style="text-align:center;">
                    <p style="color:#888;">No federal polls with votes yet. <a href="/poll/">Cast the first vote</a>.</p>
                </div>
            <?php else: ?>
                <?php foreach ($federalPolls as $fp):
                    $fpTotal = (int)$fp['total_votes'];
                    $fpYeaPct = $fpTotal > 0 ? round($fp['yea_count'] / $fpTotal * 100, 1) : 0;
                    $fpNayPct = $fpTotal > 0 ? round($fp['nay_count'] / $fpTotal * 100, 1) : 0;
                    $zone = $fp['severity_score'] ? getSeverityZone($fp['severity_score']) : ['color' => '#444', 'label' => ''];
                ?>
                <div class="threat-row">
                    <div class="threat-row-header">
                        <?php if ($fp['severity_score']): ?>
                        <span class="severity-badge" style="background:<?= $zone['color'] ?>"><?= $fp['severity_score'] ?></span>
                        <?php endif; ?>
                        <span class="threat-title"><?= htmlspecialchars($fp['question']) ?></span>
                        <span style="color:#666;font-size:0.75rem;margin-left:auto;"><?= date('M j, Y', strtotime($fp['poll_created_at'])) ?></span>
                    </div>
                    <div class="comparison">
                        <div class="comparison-col">
                            <div class="col-label">National (<?= $fpTotal ?> votes)</div>
                            <div class="results-bar">
                                <?php if ($fpYeaPct > 0): ?><div class="results-yea" style="width:<?= $fpYeaPct ?>%"><?= $fpYeaPct ?>%</div><?php endif; ?>
                                <?php if ($fpNayPct > 0): ?><div class="results-nay" style="width:<?= $fpNayPct ?>%"><?= $fpNayPct ?>%</div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<script>
function switchGeo() {
    var state = document.getElementById('stateFilter');
    var town = document.getElementById('townFilter');
    var stateVal = state ? state.value.toLowerCase() : '';
    if (!stateVal) { window.location.href = '/poll/by-state/'; return; }
    var url = '/poll/by-state/' + stateVal + '/';
    if (town && town.value) url += '?town=' + town.value;
    window.location.href = url;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
