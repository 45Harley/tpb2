<?php
/**
 * Rep Statements — What the President Says
 * =========================================
 * Reverse-chronological stream of presidential statements.
 * Dual scoring (criminality + benefit), citizen agree/disagree voting.
 * POC: President only (official_id = 326).
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
$pageTitle = 'Statements | TPB';
$ogTitle = 'What the President Says — The People\'s Branch';
$ogDescription = 'Track presidential statements scored on dual scales. Agree or disagree. Hold your representatives accountable.';

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
$where = ['rs.official_id = 326'];
$params = [];

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
    SELECT rs.*, eo.full_name AS official_name
    FROM rep_statements rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
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

// --- Distinct sources for filter dropdown ---
$sources = $pdo->query("
    SELECT DISTINCT source FROM rep_statements WHERE official_id = 326 ORDER BY source
")->fetchAll(PDO::FETCH_COLUMN);

$hasFilters = $filterTopic || $filterTense || $filterSource;

$pageStyles = <<<'CSS'
.stream-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

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

/* Empty state */
.empty-state {
    text-align: center; padding: 3rem 1rem; color: #b0b0b0; font-size: 1.1rem;
}

/* Responsive */
@media (max-width: 600px) {
    .statement-meta { flex-direction: column; align-items: flex-start; }
    .statement-date { margin-left: 0; }
    .dual-scores { flex-direction: column; }
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
        <a href="/elections/threats.php">Threats</a>
        <a href="/elections/statements.php" class="active">Statements</a>
        <a href="/elections/races.php">Races</a>
        <a href="/elections/impeachment-vote.php">Impeachment #1</a>
    </div>

    <h1 class="page-heading">What the President Says</h1>
    <p class="page-subheading">Presidential statements scored on dual scales — criminality and benefit. You decide if you agree.</p>

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
        ?>
        <div class="statement-card">
            <div class="statement-meta">
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

            <div class="dual-scores">
                <span class="score-badge" style="background:<?= $harmZone['color'] ?>;color:<?= $harmTextColor ?>">
                    Harm: <?= $harmScore !== null ? (int)$harmScore : '—' ?> <?= $harmZone['label'] ?>
                </span>
                <span class="score-badge" style="background:<?= $benefitZone['color'] ?>;color:<?= $benefitTextColor ?>">
                    Benefit: <?= $benefitScore !== null ? (int)$benefitScore : '—' ?> <?= $benefitZone['label'] ?>
                </span>
            </div>

            <div class="vote-row">
                <button class="vote-btn <?= $userVote === 'agree' ? 'vote-active agree' : '' ?>"
                        data-id="<?= $sid ?>" data-type="agree"
                        onclick="voteStatement(this)">
                    Agree <span class="vote-count"><?= (int)$s['agree_count'] ?></span>
                </button>
                <button class="vote-btn <?= $userVote === 'disagree' ? 'vote-active disagree' : '' ?>"
                        data-id="<?= $sid ?>" data-type="disagree"
                        onclick="voteStatement(this)">
                    Disagree <span class="vote-count"><?= (int)$s['disagree_count'] ?></span>
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
