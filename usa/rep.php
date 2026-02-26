<?php
/**
 * Rep Detail Page — Summary dashboard for a single representative
 * Usage: /usa/rep.php?id=698  (by official_id)
 *        /usa/rep.php?bioguide=B001277  (by bioguide_id)
 *
 * All dynamic from DB. Links out to digest + poll detail pages.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/congressional-overview.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$congress = 119;

// Find the rep
$repId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bioguide = trim($_GET['bioguide'] ?? '');

if ($bioguide) {
    $stmt = $pdo->prepare("SELECT * FROM elected_officials WHERE bioguide_id = ? AND is_current = 1 LIMIT 1");
    $stmt->execute([$bioguide]);
} elseif ($repId) {
    $stmt = $pdo->prepare("SELECT * FROM elected_officials WHERE official_id = ? AND is_current = 1 LIMIT 1");
    $stmt->execute([$repId]);
} else {
    header('Location: /usa/congressional-overview.php');
    exit;
}

$rep = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$rep) {
    header('Location: /usa/congressional-overview.php');
    exit;
}

$oid = $rep['official_id'];
$bid = $rep['bioguide_id'];
$partyInitial = substr($rep['party'], 0, 1);

// State name
$stmt = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
$stmt->execute([$rep['state_code']]);
$stateName = $stmt->fetchColumn() ?: $rep['state_code'];

// Chamber / district
$chamber = stripos($rep['title'], 'Senator') !== false ? 'Senate' : 'House';
$district = '';
if ($chamber === 'House' && preg_match('/District (\d+)/', $rep['office_name'], $m)) {
    $district = $m[1] === '0' ? 'At-Large' : 'District ' . $m[1];
}

// Photo
$photoUrl = $bid ? "https://bioguide.congress.gov/bioguide/photo/" . $bid[0] . "/$bid.jpg" : '';

// ── Scorecard ──
$stmt = $pdo->prepare("SELECT * FROM rep_scorecard WHERE official_id = ? AND congress = ?");
$stmt->execute([$oid, $congress]);
$scorecard = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Threat poll stats ──
$totalThreatPolls = (int)$pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND active = 1")->fetchColumn();

// Check if rep has a user account for poll voting
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE official_id = ? AND deleted_at IS NULL");
$stmt->execute([$oid]);
$repUser = $stmt->fetch(PDO::FETCH_ASSOC);
$repUserId = $repUser ? (int)$repUser['user_id'] : 0;

$threatsResponded = 0;
$yeas = 0;
$nays = 0;
if ($repUserId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as responded, SUM(CASE WHEN vote_choice='yea' THEN 1 ELSE 0 END) as yeas, SUM(CASE WHEN vote_choice='nay' THEN 1 ELSE 0 END) as nays FROM poll_votes WHERE user_id = ? AND is_rep_vote = 1");
    $stmt->execute([$repUserId]);
    $pv = $stmt->fetch(PDO::FETCH_ASSOC);
    $threatsResponded = (int)$pv['responded'];
    $yeas = (int)$pv['yeas'];
    $nays = (int)$pv['nays'];
}
$silenceRate = $totalThreatPolls > 0 ? round(($totalThreatPolls - $threatsResponded) / $totalThreatPolls * 100) : 100;

// ── Committees (parent only) ──
$stmt = $pdo->prepare("SELECT c.committee_id, c.name, cm.role FROM committee_memberships cm JOIN committees c ON cm.committee_id = c.committee_id WHERE cm.official_id = ? AND c.parent_id IS NULL ORDER BY cm.role DESC, c.name");
$stmt->execute([$oid]);
$committees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Bills sponsored (from tracked_bills) ──
$stmt = $pdo->prepare("SELECT bill_type, bill_number, short_title, title, last_action_date, status FROM tracked_bills WHERE sponsor_bioguide = ? AND congress = ? ORDER BY last_action_date DESC LIMIT 10");
$stmt->execute([$bid, $congress]);
$bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Recent votes (last 10) ──
$stmt = $pdo->prepare("SELECT rv.vote_id, rv.vote_question, rv.vote_date, rv.vote_result, mv.vote as member_vote, rv.chamber FROM member_votes mv JOIN roll_call_votes rv ON mv.vote_id = rv.vote_id WHERE mv.official_id = ? ORDER BY rv.vote_date DESC, rv.roll_call_number DESC LIMIT 10");
$stmt->execute([$oid]);
$recentVotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = htmlspecialchars($rep['full_name']) . ' — The People\'s Branch';

$pageStyles = <<<'CSS'
.rep-detail {
    max-width: 900px;
    margin: 0 auto;
    padding: 30px 20px;
}
.breadcrumb {
    font-size: 0.85em;
    color: #888;
    margin-bottom: 20px;
}
.breadcrumb a { color: #d4af37; text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }

/* Identity card */
.rep-identity {
    display: flex;
    gap: 24px;
    align-items: flex-start;
    margin-bottom: 30px;
    padding: 24px;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
}
.rep-identity .photo {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 8px;
    background: #2a2a2a;
    flex-shrink: 0;
}
.rep-identity .info { flex: 1; }
.rep-identity .info h1 {
    color: #e0e0e0;
    font-size: 1.5em;
    margin: 0 0 6px;
}
.party-tag {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 0.8em;
    font-weight: 700;
    color: #fff;
    margin-bottom: 10px;
}
.party-tag.D { background: #2563eb; }
.party-tag.R { background: #dc2626; }
.party-tag.I { background: #7c3aed; }
.rep-meta {
    color: #999;
    font-size: 0.9em;
    line-height: 1.8;
}
.rep-meta a { color: #d4af37; text-decoration: none; }
.rep-meta a:hover { text-decoration: underline; }

/* Section boxes */
.section-box {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
    padding: 20px 24px;
    margin-bottom: 24px;
}
.section-box h2 {
    color: #d4af37;
    font-size: 1.15em;
    margin: 0 0 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid #333;
}
.section-box .detail-link {
    display: inline-block;
    margin-top: 12px;
    color: #d4af37;
    font-size: 0.85em;
    text-decoration: none;
}
.section-box .detail-link:hover { text-decoration: underline; }

/* Stats grid */
.stats-grid {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.stat-card {
    flex: 1;
    min-width: 120px;
    text-align: center;
    padding: 12px;
    background: #0d0d1a;
    border-radius: 8px;
}
.stat-card .num {
    font-size: 1.5em;
    font-weight: 700;
    color: #d4af37;
}
.stat-card .label {
    font-size: 0.75em;
    color: #888;
    margin-top: 4px;
}
.silence-high { color: #f44336 !important; }
.silence-med { color: #ff9800 !important; }
.silence-low { color: #4caf50 !important; }

/* Committee list */
.committee-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.committee-list li {
    padding: 6px 0;
    border-bottom: 1px solid #222;
    font-size: 0.9em;
    color: #ccc;
}
.committee-list li:last-child { border-bottom: none; }
.committee-list .role {
    color: #d4af37;
    font-weight: 600;
    font-size: 0.8em;
}

/* Bill list */
.bill-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.bill-list li {
    padding: 8px 0;
    border-bottom: 1px solid #222;
    font-size: 0.85em;
    color: #ccc;
    line-height: 1.4;
}
.bill-list li:last-child { border-bottom: none; }
.bill-type { color: #d4af37; font-weight: 700; }
.bill-date { color: #666; font-size: 0.85em; }

/* Vote list */
.vote-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.vote-list li {
    padding: 8px 0;
    border-bottom: 1px solid #222;
    font-size: 0.85em;
    color: #ccc;
    display: flex;
    gap: 10px;
    align-items: baseline;
}
.vote-list li:last-child { border-bottom: none; }
.vote-position {
    font-weight: 700;
    font-size: 0.8em;
    padding: 2px 8px;
    border-radius: 4px;
    white-space: nowrap;
}
.vote-yea { background: #1a3a1a; color: #4caf50; }
.vote-nay { background: #3a1a1a; color: #f44336; }
.vote-other { background: #2a2a2a; color: #888; }
.vote-date { color: #666; font-size: 0.85em; white-space: nowrap; }
.vote-question { flex: 1; }

.empty-note { color: #666; font-style: italic; font-size: 0.9em; }

/* Responsive */
@media (max-width: 600px) {
    .rep-identity { flex-direction: column; align-items: center; text-align: center; }
    .rep-identity .photo { width: 120px; height: 120px; }
    .stats-grid { flex-direction: column; }
    .vote-list li { flex-direction: column; gap: 4px; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="rep-detail">
    <div class="breadcrumb">
        <a href="/usa/congressional-overview.php">Congressional Overview</a>
        &rsaquo; <a href="/usa/congressional-overview.php?state=<?= $rep['state_code'] ?>"><?= htmlspecialchars($stateName) ?></a>
        &rsaquo; <?= htmlspecialchars($rep['full_name']) ?>
    </div>

    <!-- Identity Card -->
    <div class="rep-identity">
        <?php if ($photoUrl): ?>
            <img class="photo" src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($rep['full_name']) ?>">
        <?php endif; ?>
        <div class="info">
            <h1><?= htmlspecialchars($rep['full_name']) ?></h1>
            <span class="party-tag <?= $partyInitial ?>"><?= htmlspecialchars($rep['party']) ?></span>
            <div class="rep-meta">
                <?= htmlspecialchars($stateName) ?> &middot; <?= $chamber ?><?= $district ? " &middot; $district" : '' ?><br>
                <?= htmlspecialchars($rep['title']) ?>
                <?php if ($rep['website']): ?>
                    <br><a href="<?= htmlspecialchars($rep['website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(preg_replace('#^https?://(www\.)?#', '', $rep['website'])) ?></a>
                <?php endif; ?>
                <?php if ($rep['phone']): ?>
                    <br><?= htmlspecialchars($rep['phone']) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- How They Represent You -->
    <div class="section-box">
        <h2>How They Represent You</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="num"><?= $threatsResponded ?>/<?= $totalThreatPolls ?></div>
                <div class="label">Threats Responded</div>
            </div>
            <div class="stat-card">
                <div class="num <?= $silenceRate >= 80 ? 'silence-high' : ($silenceRate >= 40 ? 'silence-med' : 'silence-low') ?>"><?= $silenceRate ?>%</div>
                <div class="label">Silence Rate</div>
            </div>
            <?php if ($scorecard): ?>
            <div class="stat-card">
                <div class="num"><?= $scorecard['participation_pct'] !== null ? round($scorecard['participation_pct']) . '%' : '—' ?></div>
                <div class="label">Votes Cast</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $scorecard['party_loyalty_pct'] !== null ? round($scorecard['party_loyalty_pct']) . '%' : '—' ?></div>
                <div class="label">Party Loyalty</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $scorecard['bipartisan_pct'] !== null ? round($scorecard['bipartisan_pct']) . '%' : '—' ?></div>
                <div class="label">Bipartisan</div>
            </div>
            <?php endif; ?>
        </div>
        <a class="detail-link" href="/poll/by-rep/?bioguide=<?= htmlspecialchars($bid) ?>">View full threat roll call &rarr;</a>
    </div>

    <!-- Scorecard -->
    <?php if ($scorecard): ?>
    <div class="section-box">
        <h2>119th Congress Scorecard</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="num"><?= (int)$scorecard['votes_cast'] ?></div>
                <div class="label">Votes Cast</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= (int)$scorecard['missed_votes'] ?></div>
                <div class="label">Missed Votes</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= (int)$scorecard['bills_sponsored'] ?></div>
                <div class="label">Bills Sponsored</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= (int)$scorecard['amendments_sponsored'] ?></div>
                <div class="label">Amendments</div>
            </div>
        </div>
        <a class="detail-link" href="/usa/digest.php?rep=<?= $oid ?>">View full voting record &rarr;</a>
    </div>
    <?php endif; ?>

    <!-- Committees -->
    <?php if ($committees): ?>
    <div class="section-box">
        <h2>Committees</h2>
        <ul class="committee-list">
            <?php foreach ($committees as $cm): ?>
            <li>
                <a href="/usa/digest.php?committee=<?= $cm['committee_id'] ?>" style="color:#ccc;text-decoration:none;"><?= htmlspecialchars($cm['name']) ?></a>
                <?php if ($cm['role'] && $cm['role'] !== 'Member'): ?>
                    <span class="role"><?= htmlspecialchars($cm['role']) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Bills Sponsored -->
    <div class="section-box">
        <h2>Bills Sponsored</h2>
        <?php if ($bills): ?>
        <ul class="bill-list">
            <?php foreach ($bills as $b): ?>
            <li>
                <a href="/usa/digest.php?bill=<?= urlencode($b['bill_type'] . '-' . $b['bill_number']) ?>" style="color:#ccc;text-decoration:none;">
                    <span class="bill-type"><?= strtoupper($b['bill_type']) ?> <?= $b['bill_number'] ?></span>
                    <?= htmlspecialchars($b['short_title'] ?: mb_strimwidth($b['title'], 0, 100, '...')) ?>
                </a>
                <span class="bill-date"><?= $b['last_action_date'] ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <a class="detail-link" href="/usa/digest.php?rep=<?= $oid ?>">View all bills &rarr;</a>
        <?php else: ?>
        <p class="empty-note">No bills sponsored in the 119th Congress yet.</p>
        <?php endif; ?>
    </div>

    <!-- Recent Votes -->
    <div class="section-box">
        <h2>Recent Votes</h2>
        <?php if ($recentVotes): ?>
        <ul class="vote-list">
            <?php foreach ($recentVotes as $v):
                $pos = strtolower($v['member_vote'] ?? '');
                $posClass = ($pos === 'yea' || $pos === 'aye') ? 'vote-yea' : (($pos === 'nay' || $pos === 'no') ? 'vote-nay' : 'vote-other');
                $posLabel = ucfirst($v['member_vote'] ?? 'N/A');
            ?>
            <li>
                <span class="vote-date"><?= $v['vote_date'] ?></span>
                <span class="vote-position <?= $posClass ?>"><?= $posLabel ?></span>
                <span class="vote-question"><?= htmlspecialchars(mb_strimwidth($v['vote_question'], 0, 120, '...')) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <a class="detail-link" href="/usa/digest.php?rep=<?= $oid ?>">View full voting record &rarr;</a>
        <?php else: ?>
        <p class="empty-note">No recorded votes yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
