<?php
/**
 * Rep Statements — What Your Representatives Say
 * ================================================
 * Reverse-chronological stream of official statements.
 * Dual scoring (criminality + benefit), citizen agree/disagree voting.
 * Supports multiple officials with "My Delegation" view.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/severity.php';
require_once dirname(__DIR__) . '/includes/benefit-severity.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$userId = $dbUser ? (int)$dbUser['user_id'] : 0;
$currentPage = 'elections';

// --- Officials with statements ---
$trackedOfficials = [
    326 => ['name' => 'Donald Trump', 'short' => 'President', 'title' => 'President'],
    374 => ['name' => 'Richard Blumenthal', 'short' => 'Sen. Blumenthal', 'title' => 'U.S. Senator (CT)'],
    441 => ['name' => 'Christopher Murphy', 'short' => 'Sen. Murphy', 'title' => 'U.S. Senator (CT)'],
];

// CT federal delegation IDs (for "My Delegation" view)
$delegationIds = [374, 441]; // Blumenthal + Murphy

// --- View param ---
$view = $_GET['view'] ?? 'delegation';
$validViews = array_merge(['delegation', 'all'], array_map('strval', array_keys($trackedOfficials)));
if (!in_array($view, $validViews)) $view = 'delegation';

// --- Page titles per view ---
if ($view === 'delegation') {
    $pageTitle = 'My Delegation — Statements | TPB';
    $ogTitle = 'What My Senators Say — The People\'s Branch';
    $ogDescription = 'Track your CT federal delegation\'s statements scored on dual scales. Hold your representatives accountable.';
    $headingTitle = 'My Federal Delegation';
    $headingSubtitle = 'What your CT senators are saying — scored on harm, benefit, and truth.';
    $officialFilter = $delegationIds;
} elseif ($view === 'all') {
    $pageTitle = 'All Statements | TPB';
    $ogTitle = 'All Official Statements — The People\'s Branch';
    $ogDescription = 'Track official statements scored on dual scales. Agree or disagree. Hold your representatives accountable.';
    $headingTitle = 'All Officials';
    $headingSubtitle = 'Every tracked official\'s statements — scored on harm, benefit, and truth.';
    $officialFilter = array_keys($trackedOfficials);
} else {
    $oid = (int)$view;
    $off = $trackedOfficials[$oid];
    $pageTitle = $off['short'] . ' — Statements | TPB';
    $ogTitle = 'What ' . $off['name'] . ' Says — The People\'s Branch';
    $ogDescription = 'Track statements by ' . $off['name'] . ' scored on dual scales. Agree or disagree.';
    $headingTitle = $off['name'];
    $headingSubtitle = $off['title'] . ' — statements scored on harm, benefit, and truth.';
    $officialFilter = [$oid];
}

// --- Filter params ---
$filterTopic = $_GET['topic'] ?? '';
$filterTense = $_GET['tense'] ?? '';
$filterSource = $_GET['source'] ?? '';

// --- Policy topics ---
$policyTopics = [
    'Economy & Jobs', 'Healthcare', 'Education', 'Environment & Climate',
    'Immigration', 'National Security', 'Criminal Justice', 'Housing',
    'Infrastructure', 'Social Services', 'Tax Policy', 'Civil Rights',
    'Technology & Privacy', 'Foreign Policy', 'Agriculture', 'Government Reform'
];

// --- Build query ---
$placeholders = implode(',', array_fill(0, count($officialFilter), '?'));
$where = ["rs.official_id IN ($placeholders)"];
$params = array_values($officialFilter);

if ($filterTopic) {
    $where[] = 'rs.policy_topic = ?';
    $params[] = $filterTopic;
}
if ($filterTense) {
    $where[] = 'rs.tense = ?';
    $params[] = $filterTense;
}
if ($filterSource) {
    $where[] = 'rs.source = ?';
    $params[] = $filterSource;
}

$whereClause = implode(' AND ', $where);
$sql = "
    SELECT rs.*, eo.full_name AS official_name,
           sc.canonical_claim, sc.repeat_count, sc.truthfulness_score AS truth_score,
           sc.truthfulness_avg AS truth_avg, sc.truthfulness_direction AS truth_dir,
           sc.truthfulness_delta AS truth_delta, sc.truthfulness_note AS truth_note,
           sc.first_seen AS cluster_first, sc.last_seen AS cluster_last
    FROM rep_statements rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    LEFT JOIN statement_clusters sc ON rs.cluster_id = sc.id
    WHERE $whereClause
    ORDER BY rs.statement_date DESC, rs.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$statements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Viewer's votes ---
$userVotes = [];
if ($userId) {
    $voteStmt = $pdo->prepare("SELECT statement_id, vote_type FROM rep_statement_votes WHERE user_id = ?");
    $voteStmt->execute([$userId]);
    while ($row = $voteStmt->fetch(PDO::FETCH_ASSOC)) {
        $userVotes[(int)$row['statement_id']] = $row['vote_type'];
    }
}

// --- Distinct sources for filter dropdown (scoped to current view) ---
$srcStmt = $pdo->prepare("
    SELECT DISTINCT source FROM rep_statements WHERE official_id IN ($placeholders) ORDER BY source
");
$srcStmt->execute(array_values($officialFilter));
$sources = $srcStmt->fetchAll(PDO::FETCH_COLUMN);

$hasFilters = $filterTopic || $filterTense || $filterSource;

// Truthfulness zone helper
function getTruthZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#444', 'text' => '#888'];
    if ($score <= 100) return ['label' => 'False', 'color' => '#b71c1c', 'text' => '#fff'];
    if ($score <= 200) return ['label' => 'Mostly False', 'color' => '#c62828', 'text' => '#fff'];
    if ($score <= 300) return ['label' => 'Misleading', 'color' => '#e65100', 'text' => '#fff'];
    if ($score <= 400) return ['label' => 'Half True', 'color' => '#f9a825', 'text' => '#000'];
    if ($score <= 500) return ['label' => 'Mixed', 'color' => '#fdd835', 'text' => '#000'];
    if ($score <= 600) return ['label' => 'Mostly True', 'color' => '#9ccc65', 'text' => '#000'];
    if ($score <= 700) return ['label' => 'True', 'color' => '#66bb6a', 'text' => '#000'];
    if ($score <= 800) return ['label' => 'Very True', 'color' => '#43a047', 'text' => '#fff'];
    if ($score <= 900) return ['label' => 'Verified', 'color' => '#2e7d32', 'text' => '#fff'];
    return ['label' => 'Precisely True', 'color' => '#1b5e20', 'text' => '#fff'];
}

$pageStyles = <<<'CSS'
.stream-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

/* Official selector tabs */
.official-tabs {
    display: flex; gap: 0.4rem; margin-bottom: 1.5rem; flex-wrap: wrap;
    justify-content: center;
}
.official-tab {
    padding: 0.5rem 1.2rem; border: 1px solid #333; border-radius: 20px;
    color: #b0b0b0; text-decoration: none; font-size: 0.9rem; font-weight: 500;
    transition: all 0.2s;
}
.official-tab:hover { color: #e0e0e0; border-color: #555; background: rgba(255,255,255,0.03); }
.official-tab.active {
    color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); font-weight: 600;
}

/* Official name badge (shown in multi-official views) */
.official-badge {
    display: inline-block; padding: 2px 10px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 600; background: rgba(100,181,246,0.15);
    color: #64b5f6; border: 1px solid rgba(100,181,246,0.3);
}

/* View links */
.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.4rem 1rem; border: 1px solid #333; border-radius: 6px;
    color: #888; text-decoration: none; font-size: 0.9rem; transition: all 0.2s;
}
.view-links a:hover { color: #e0e0e0; border-color: #555; }
.view-links a.active { color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); }

/* Page heading */
.page-heading {
    font-size: 1.8rem; font-weight: 700; color: #e0e0e0;
    margin-bottom: 0.5rem; text-align: center;
}
.page-subheading {
    color: #b0b0b0; text-align: center; margin-bottom: 1.5rem; font-size: 0.95rem;
}

/* Statement filters */
.statement-filters {
    display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;
    margin-bottom: 1.5rem; padding: 1rem; background: #1a1a2e;
    border-radius: 8px; border: 1px solid #333;
}
.statement-filters label { color: #b0b0b0; font-size: 0.85rem; }
.statement-filters select {
    background: #0a0a0f; border: 1px solid #444; color: #e0e0e0;
    padding: 0.4rem 0.6rem; border-radius: 6px; font-size: 0.85rem;
}

/* Statement cards */
.statement-card {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem; margin-bottom: 1rem; transition: border-color 0.3s;
}
.statement-card:hover { border-color: #555; }

.statement-meta {
    display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
    margin-bottom: 0.75rem;
}

.source-badge {
    display: inline-block; padding: 2px 10px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 600; background: rgba(212,175,55,0.15);
    color: #d4af37; border: 1px solid rgba(212,175,55,0.3);
}

.tense-badge {
    display: inline-block; padding: 2px 10px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 600;
}
.tense-future { background: rgba(33,150,243,0.15); color: #64b5f6; border: 1px solid rgba(33,150,243,0.3); }
.tense-present { background: rgba(212,175,55,0.15); color: #d4af37; border: 1px solid rgba(212,175,55,0.3); }
.tense-past { background: rgba(158,158,158,0.15); color: #b0b0b0; border: 1px solid rgba(158,158,158,0.3); }

.topic-tag {
    display: inline-block; padding: 2px 10px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 600; background: rgba(76,175,80,0.15);
    color: #81c784; border: 1px solid rgba(76,175,80,0.3);
}

.statement-date { color: #888; font-size: 0.8rem; margin-left: auto; }

/* Blockquote */
.statement-content blockquote {
    border-left: 3px solid #d4af37; margin: 0 0 0.75rem 0; padding: 0.75rem 1rem;
    font-style: italic; color: #ccc; font-size: 0.95rem; line-height: 1.6;
    background: rgba(212,175,55,0.03);
}

.statement-summary {
    color: #b0b0b0; font-size: 0.9rem; margin-bottom: 0.75rem; line-height: 1.5;
}

.statement-source-link {
    display: inline-block; margin-bottom: 0.75rem;
}
.statement-source-link a {
    color: #64b5f6; font-size: 0.85rem; text-decoration: none;
}
.statement-source-link a:hover { text-decoration: underline; }

/* Dual scores */
.dual-scores {
    display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem;
}
.score-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 3px 10px; border-radius: 4px; font-weight: 700;
    font-family: 'Courier New', monospace; font-size: 0.85rem;
    white-space: nowrap;
}

/* Vote row */
.vote-row {
    display: flex; gap: 0.5rem; align-items: center;
}
.vote-btn {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.3rem 0.75rem; border-radius: 6px; font-size: 0.85rem;
    border: 1px solid #333; background: transparent; color: #888;
    cursor: pointer; transition: all 0.2s;
}
.vote-btn:hover { color: #e0e0e0; border-color: #555; }
.vote-btn.vote-active.agree { background: #1b5e20; border-color: #2e7d32; color: #a5d6a7; }
.vote-btn.vote-active.disagree { background: #b71c1c; border-color: #c62828; color: #ef9a9a; }
.vote-btn .vote-count { font-weight: 700; }

/* Spectrum bars */
.score-spectrum {
    display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem;
}
.spectrum-row {
    display: flex; align-items: center; gap: 0.5rem;
    position: relative;
}
.spectrum-label {
    font-size: 0.75rem; color: #888; width: 48px; text-align: right; flex-shrink: 0;
}
.spectrum-bar {
    flex: 1; max-width: 200px; height: 12px; border-radius: 6px; position: relative;
    overflow: visible; cursor: help;
}
.spectrum-bar-harm {
    background: linear-gradient(90deg, #2e7d32 0%, #66bb6a 15%, #fdd835 35%, #ff9800 55%, #c62828 75%, #4a0000 100%);
}
.spectrum-bar-benefit {
    background: linear-gradient(90deg, #333 0%, #66bb6a 30%, #2e7d32 60%, #1b5e20 100%);
}
.spectrum-bar-truth {
    background: linear-gradient(90deg, #b71c1c 0%, #c62828 10%, #e65100 20%, #f9a825 35%, #fdd835 50%, #9ccc65 60%, #66bb6a 70%, #43a047 80%, #2e7d32 90%, #1b5e20 100%);
}
.spectrum-marker {
    position: absolute; top: -2px; width: 3px; height: 16px;
    background: #fff; border-radius: 2px; box-shadow: 0 0 4px rgba(0,0,0,0.8);
    transform: translateX(-1.5px);
}
.spectrum-value {
    font-size: 0.75rem; font-weight: 700; color: #e0e0e0; width: 100px; flex-shrink: 0;
    white-space: nowrap;
}
.spectrum-ends {
    display: flex; justify-content: space-between; flex: 1;
    font-size: 0.65rem; color: #666; margin-top: -2px; padding: 0 2px;
}
.spectrum-tooltip {
    display: none; position: absolute; bottom: 28px; left: 50%;
    transform: translateX(-50%); background: #0a0a0f; border: 1px solid #555;
    border-radius: 6px; padding: 6px 10px; font-size: 0.75rem; color: #ccc;
    white-space: nowrap; z-index: 10; pointer-events: none;
}
.spectrum-bar:hover .spectrum-tooltip { display: block; }

/* Empty state */
.empty-state {
    text-align: center; padding: 3rem 1rem; color: #b0b0b0; font-size: 1.1rem;
}

/* Truthfulness */
.truth-row {
    display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
    margin-bottom: 0.75rem; padding: 0.6rem 0.75rem;
    background: rgba(255,255,255,0.03); border-radius: 6px;
    border: 1px solid rgba(255,255,255,0.06);
}
.truth-score-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 3px 12px; border-radius: 4px; font-weight: 700;
    font-family: 'Courier New', monospace; font-size: 0.85rem;
}
.truth-direction {
    font-size: 0.85rem; font-weight: 700;
}
.truth-direction.up { color: #66bb6a; }
.truth-direction.down { color: #ef5350; }
.truth-direction.stable { color: #888; }
.truth-direction.new { color: #64b5f6; }
.truth-note {
    font-size: 0.8rem; color: #b0b0b0; line-height: 1.4;
    flex: 1 1 100%;
}
.cluster-info {
    font-size: 0.75rem; color: #888;
}
.cluster-info .repeat-count {
    color: #d4af37; font-weight: 600;
}

/* Responsive */
@media (max-width: 600px) {
    .statement-meta { flex-direction: column; align-items: flex-start; }
    .statement-date { margin-left: 0; }
    .dual-scores { flex-direction: column; }
    .truth-row { flex-direction: column; align-items: flex-start; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<main class="stream-container">

    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php">Reps Actions</a>
        <a href="/elections/statements.php" class="active">Reps Statements</a>
        <a href="/elections/races.php">Races</a>
        <a href="/elections/impeachment-vote.php">Impeachment #1</a>
    </div>

    <?php require_once dirname(__DIR__) . '/includes/criminality-scale.php'; ?>
    <?php require_once dirname(__DIR__) . '/includes/benefit-scale.php'; ?>

    <!-- Official selector tabs -->
    <div class="official-tabs">
        <a href="?view=delegation" class="official-tab <?= $view === 'delegation' ? 'active' : '' ?>">My Delegation</a>
        <?php foreach ($trackedOfficials as $oid => $off): ?>
        <a href="?view=<?= $oid ?>" class="official-tab <?= $view === (string)$oid ? 'active' : '' ?>"><?= htmlspecialchars($off['short']) ?></a>
        <?php endforeach; ?>
        <a href="?view=all" class="official-tab <?= $view === 'all' ? 'active' : '' ?>">All</a>
    </div>

    <h1 class="page-heading"><?= htmlspecialchars($headingTitle) ?></h1>
    <p class="page-subheading"><?= htmlspecialchars($headingSubtitle) ?></p>

    <!-- Filters -->
    <div class="statement-filters">
        <label>Topic:</label>
        <select onchange="updateFilter('topic', this.value)">
            <option value="">All Topics</option>
            <?php foreach ($policyTopics as $topic): ?>
            <option value="<?= htmlspecialchars($topic) ?>" <?= $filterTopic === $topic ? 'selected' : '' ?>><?= htmlspecialchars($topic) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Tense:</label>
        <select onchange="updateFilter('tense', this.value)">
            <option value="">All</option>
            <option value="future" <?= $filterTense === 'future' ? 'selected' : '' ?>>Future</option>
            <option value="present" <?= $filterTense === 'present' ? 'selected' : '' ?>>Present</option>
            <option value="past" <?= $filterTense === 'past' ? 'selected' : '' ?>>Past</option>
        </select>

        <label>Source:</label>
        <select onchange="updateFilter('source', this.value)">
            <option value="">All Sources</option>
            <?php foreach ($sources as $src): ?>
            <option value="<?= htmlspecialchars($src) ?>" <?= $filterSource === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($hasFilters): ?>
        <a href="?view=<?= htmlspecialchars($view) ?>" style="color:#d4af37;font-size:0.85rem;text-decoration:none;">Clear filters</a>
        <?php endif; ?>
    </div>

    <!-- Statements -->
    <?php if (empty($statements)): ?>
        <div class="empty-state">
            <?= $hasFilters ? 'No statements found matching your filters.' : 'No statements found yet.' ?>
        </div>
    <?php else: ?>
        <?php foreach ($statements as $s):
            $sid = (int)$s['id'];
            $harmScore = $s['severity_score'];
            $benefitScore = $s['benefit_score'];
            $harmZone = getSeverityZone($harmScore);
            $benefitZone = getBenefitZone($benefitScore);
            $harmTextColor = ($harmScore !== null && (int)$harmScore > 500) ? '#fff' : '#000';
            $benefitTextColor = '#000';
            $tenseClass = $s['tense'] ? 'tense-' . $s['tense'] : '';
            $userVote = $userVotes[$sid] ?? null;
            $truthScore = $s['truth_score'] ?? null;
            $truthZone = getTruthZone($truthScore !== null ? (int)$truthScore : null);
            $truthDir = $s['truth_dir'] ?? null;
            $truthDelta = (int)($s['truth_delta'] ?? 0);
            $repeatCount = (int)($s['repeat_count'] ?? 0);
        ?>
        <div class="statement-card">
            <div class="statement-meta">
                <?php if (count($officialFilter) > 1): ?>
                <span class="official-badge"><?= htmlspecialchars($s['official_name']) ?></span>
                <?php endif; ?>
                <span class="source-badge"><?= htmlspecialchars($s['source']) ?></span>
                <?php if ($s['tense']): ?>
                <span class="tense-badge <?= $tenseClass ?>"><?= ucfirst($s['tense']) ?></span>
                <?php endif; ?>
                <?php if ($s['policy_topic']): ?>
                <span class="topic-tag"><?= htmlspecialchars($s['policy_topic']) ?></span>
                <?php endif; ?>
                <span class="statement-date"><?= date('M j, Y', strtotime($s['statement_date'])) ?></span>
            </div>

            <div class="statement-content">
                <blockquote><?= nl2br(htmlspecialchars($s['content'])) ?></blockquote>
            </div>

            <?php if ($s['summary']): ?>
            <div class="statement-summary"><?= htmlspecialchars($s['summary']) ?></div>
            <?php endif; ?>

            <?php if ($s['source_url']): ?>
            <div class="statement-source-link">
                <a href="<?= htmlspecialchars($s['source_url']) ?>" target="_blank" rel="noopener">View source &rarr;</a>
            </div>
            <?php endif; ?>

            <div class="score-spectrum">
                <div class="spectrum-row">
                    <span class="spectrum-label">Harm</span>
                    <div class="spectrum-bar spectrum-bar-harm" title="Clean (0) ← <?= (int)$harmScore ?> → Genocide (1000)">
                        <div class="spectrum-marker" style="left: <?= min(100, ((int)$harmScore / 1000) * 100) ?>%"></div>
                        <div class="spectrum-tooltip">Clean (0) &larr; <?= (int)$harmScore ?> &rarr; Genocide (1000)</div>
                    </div>
                    <span class="spectrum-value"><?= $harmZone['label'] ?> (<?= (int)$harmScore ?>)</span>
                </div>
                <div class="spectrum-row">
                    <span class="spectrum-label">Benefit</span>
                    <div class="spectrum-bar spectrum-bar-benefit" title="None (0) ← <?= (int)$benefitScore ?> → Historic (1000)">
                        <div class="spectrum-marker" style="left: <?= min(100, ((int)$benefitScore / 1000) * 100) ?>%"></div>
                        <div class="spectrum-tooltip">None (0) &larr; <?= (int)$benefitScore ?> &rarr; Historic (1000)</div>
                    </div>
                    <span class="spectrum-value"><?= $benefitZone['label'] ?> (<?= (int)$benefitScore ?>)</span>
                </div>
                <?php if ($truthScore !== null): ?>
                <div class="spectrum-row">
                    <span class="spectrum-label">Truth</span>
                    <div class="spectrum-bar spectrum-bar-truth" title="Lie (0) ← <?= (int)$truthScore ?> → Truth (1000)">
                        <div class="spectrum-marker" style="left: <?= min(100, ((int)$truthScore / 1000) * 100) ?>%"></div>
                        <div class="spectrum-tooltip">Lie (0) &larr; <?= (int)$truthScore ?> &rarr; Truth (1000)</div>
                    </div>
                    <span class="spectrum-value"><?= $truthZone['label'] ?> (<?= (int)$truthScore ?>)<?php if ($truthDir && $truthDir !== 'new'): ?> <span class="truth-direction <?= $truthDir ?>"><?= $truthDir === 'up' ? '&#9650;' : ($truthDir === 'down' ? '&#9660;' : '&#9654;') ?><?= $truthDelta > 0 ? '+' . $truthDelta : $truthDelta ?></span><?php endif; ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($truthScore !== null): ?>
            <div class="truth-row">
                <?php if ($repeatCount > 1): ?>
                <span class="cluster-info">Said <span class="repeat-count"><?= $repeatCount ?>x</span> across sources</span>
                <?php endif; ?>
                <?php if ($s['truth_note']): ?>
                <span class="truth-note"><?= htmlspecialchars($s['truth_note']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="vote-row">
                <button class="vote-btn <?= $userVote === 'agree' ? 'vote-active agree' : '' ?>"
                        data-id="<?= $sid ?>" data-type="agree"
                        onclick="voteStatement(this)">
                    &#x1F44D; Agree <span class="vote-count"><?= (int)$s['agree_count'] ?></span>
                </button>
                <button class="vote-btn <?= $userVote === 'disagree' ? 'vote-active disagree' : '' ?>"
                        data-id="<?= $sid ?>" data-type="disagree"
                        onclick="voteStatement(this)">
                    &#x1F44E; Disagree <span class="vote-count"><?= (int)$s['disagree_count'] ?></span>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

<script>
function updateFilter(key, value) {
    const url = new URL(window.location.href);
    if (value) {
        url.searchParams.set(key, value);
    } else {
        url.searchParams.delete(key);
    }
    // Always preserve view param
    if (!url.searchParams.has('view')) {
        url.searchParams.set('view', '<?= htmlspecialchars($view) ?>');
    }
    window.location.href = url.toString();
}

function voteStatement(btn) {
    const statementId = btn.dataset.id;
    const voteType = btn.dataset.type;

    fetch('/api/vote-statement.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ statement_id: parseInt(statementId), vote_type: voteType })
    })
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            alert(data.message || 'Could not vote.');
            return;
        }

        // Find the vote row (parent of the clicked button)
        const row = btn.closest('.vote-row');
        const agreeBtn = row.querySelector('[data-type="agree"]');
        const disagreeBtn = row.querySelector('[data-type="disagree"]');

        // Update counts
        agreeBtn.querySelector('.vote-count').textContent = data.agree_count;
        disagreeBtn.querySelector('.vote-count').textContent = data.disagree_count;

        // Update active state
        agreeBtn.classList.remove('vote-active', 'agree');
        disagreeBtn.classList.remove('vote-active', 'disagree');

        if (data.user_vote === 'agree') {
            agreeBtn.classList.add('vote-active', 'agree');
        } else if (data.user_vote === 'disagree') {
            disagreeBtn.classList.add('vote-active', 'disagree');
        }

        // Update nav points if earned
        if (data.points_earned > 0 && typeof window.tpbUpdateNavPoints === 'function') {
            window.tpbUpdateNavPoints(data.total_points);
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
