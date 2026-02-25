<?php
/**
 * TPB Poll — By Rep View
 * ======================
 * Landing: all reps with vote record summary, silence rate.
 * Detail: full roll call — every threat with rep position vs state citizen vote.
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
$bioguide = trim($_GET['bioguide'] ?? '');

$totalThreatPolls = (int)$pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND active = 1")->fetchColumn();

// Rep detail mode
$rep = null;
$repUser = null;
$rollCall = [];

if ($bioguide) {
    $stmt = $pdo->prepare("
        SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code, eo.bioguide_id
        FROM elected_officials eo
        WHERE eo.bioguide_id = ? AND eo.is_current = 1
    ");
    $stmt->execute([$bioguide]);
    $rep = $stmt->fetch();
}

if ($rep) {
    // Get rep's user account
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE official_id = ? AND deleted_at IS NULL");
    $stmt->execute([$rep['official_id']]);
    $repUser = $stmt->fetch();
    $repUserId = $repUser ? $repUser['user_id'] : 0;

    // Get state_id for citizen vote comparison
    $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
    $stmt->execute([$rep['state_code']]);
    $stateId = $stmt->fetchColumn();

    // All threats with rep's vote + state citizen votes
    $stmt = $pdo->prepare("
        SELECT p.poll_id, p.threat_id,
               et.title, et.severity_score, et.threat_date,
               rep_vote.vote_choice as rep_position,
               COUNT(citizen_vote.poll_vote_id) as state_votes,
               SUM(CASE WHEN citizen_vote.vote_choice = 'yea' THEN 1 ELSE 0 END) as state_yea,
               SUM(CASE WHEN citizen_vote.vote_choice = 'nay' THEN 1 ELSE 0 END) as state_nay,
               SUM(CASE WHEN citizen_vote.vote_choice = 'abstain' THEN 1 ELSE 0 END) as state_abstain
        FROM polls p
        JOIN executive_threats et ON p.threat_id = et.threat_id
        LEFT JOIN poll_votes rep_vote ON p.poll_id = rep_vote.poll_id
            AND rep_vote.user_id = ? AND rep_vote.is_rep_vote = 1
        LEFT JOIN poll_votes citizen_vote ON p.poll_id = citizen_vote.poll_id
            AND citizen_vote.is_rep_vote = 0
            AND citizen_vote.user_id IN (
                SELECT user_id FROM users
                WHERE current_state_id = ? AND deleted_at IS NULL
            )
        WHERE p.poll_type = 'threat' AND p.active = 1
        GROUP BY p.poll_id, rep_vote.vote_choice
        ORDER BY et.severity_score DESC
    ");
    $stmt->execute([$repUserId, $stateId ?: 0]);
    $rollCall = $stmt->fetchAll();
}

// Landing mode: all reps with stats
$reps = [];
$stateFilter = strtoupper(trim($_GET['state_filter'] ?? ''));
$chamberFilter = trim($_GET['chamber'] ?? '');
$partyFilter = trim($_GET['party'] ?? '');

if (!$rep) {
    $reps = $pdo->query("
        SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code, eo.bioguide_id,
               u.user_id,
               COUNT(pv.poll_vote_id) as threats_responded,
               SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_count,
               SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_count,
               SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_count
        FROM elected_officials eo
        LEFT JOIN users u ON u.official_id = eo.official_id AND u.deleted_at IS NULL
        LEFT JOIN poll_votes pv ON pv.user_id = u.user_id AND pv.is_rep_vote = 1
        WHERE eo.is_current = 1
          AND eo.bioguide_id IS NOT NULL
        GROUP BY eo.official_id
        ORDER BY eo.state_code, eo.full_name
    ")->fetchAll();

    // Get all states for filter
    $states = $pdo->query("SELECT DISTINCT abbreviation FROM states ORDER BY abbreviation")->fetchAll(PDO::FETCH_COLUMN);
}

$pageTitle = $rep ? "{$rep['full_name']} — Roll Call" : 'Results By Rep — Polls';
$currentPage = 'poll';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <style>
        .polls-container { max-width: 1000px; margin: 0 auto; padding: 2rem 1rem; }
        .page-header { margin-bottom: 1rem; }
        .page-header h1 { color: #d4af37; margin-bottom: 0.25rem; }
        .page-header .subtitle { color: #888; }
        .breadcrumb { font-size: 0.85rem; color: #888; margin-bottom: 1rem; }
        .breadcrumb a { color: #d4af37; text-decoration: none; }

        .view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .view-links a {
            padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
            text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
        }
        .view-links a:hover { border-color: #d4af37; color: #d4af37; }
        .view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

        /* Rep info card */
        .rep-card {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1.25rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;
        }
        .rep-card .rep-name { font-size: 1.2rem; font-weight: 700; color: #e0e0e0; }
        .rep-card .rep-details { font-size: 0.9rem; color: #888; }
        .rep-card .party-badge {
            display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px;
            font-size: 0.75rem; font-weight: 700; color: #fff;
        }
        .party-R { background: #c0392b; }
        .party-D { background: #2980b9; }
        .party-I { background: #8e44ad; }
        .rep-stats { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .rep-stat { text-align: center; }
        .rep-stat .num { font-size: 1.3rem; font-weight: 700; color: #d4af37; }
        .rep-stat .label { font-size: 0.7rem; color: #888; }

        /* Filters */
        .controls { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .controls select {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #444;
            background: #1a1a2e; color: #e0e0e0; font-size: 0.85rem;
        }

        /* Rep table */
        .rep-table { width: 100%; border-collapse: collapse; }
        .rep-table th, .rep-table td { padding: 0.5rem 0.6rem; text-align: left; border-bottom: 1px solid #333; font-size: 0.85rem; }
        .rep-table th { background: #0a0a0f; color: #d4af37; }
        .rep-table td { color: #e0e0e0; }
        .rep-table tr:hover td { background: rgba(212,175,55,0.05); }
        .rep-table a { color: #d4af37; text-decoration: none; font-weight: 600; }
        .silence-high { color: #f44336; font-weight: 600; }
        .silence-low { color: #4caf50; }

        /* Roll call table */
        .roll-call { width: 100%; border-collapse: collapse; }
        .roll-call th, .roll-call td { padding: 0.6rem; text-align: left; border-bottom: 1px solid #333; font-size: 0.85rem; }
        .roll-call th { background: #0a0a0f; color: #d4af37; }
        .roll-call td { color: #e0e0e0; }
        .severity-badge {
            display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
            font-size: 0.7rem; font-weight: 700; color: #fff; white-space: nowrap;
        }
        .position-yea { color: #4caf50; font-weight: 600; }
        .position-nay { color: #f44336; font-weight: 600; }
        .position-abstain { color: #888; font-weight: 600; }
        .position-none { color: #555; font-style: italic; }
        .gap-col { font-weight: 700; }
        .gap-high { color: #f44336; }
        .gap-low { color: #4caf50; }
        .gap-neutral { color: #888; }

        /* Results mini bar */
        .mini-bar {
            height: 14px; background: #2a2a3e; border-radius: 7px;
            overflow: hidden; display: flex; width: 100px; display: inline-flex;
        }
        .mini-yea { background: #4caf50; height: 100%; }
        .mini-nay { background: #f44336; height: 100%; }

        @media (max-width: 768px) {
            .rep-table, .roll-call { font-size: 0.75rem; }
            .rep-table th, .rep-table td, .roll-call th, .roll-call td { padding: 0.4rem; }
            .rep-card { flex-direction: column; }
        }
    </style>

    <main class="polls-container">
        <?php if ($rep): ?>
            <div class="breadcrumb"><a href="/poll/by-rep/">By Rep</a> &rsaquo; <?= htmlspecialchars($rep['full_name']) ?></div>
            <div class="page-header">
                <h1>Roll Call: <?= htmlspecialchars($rep['full_name']) ?></h1>
                <p class="subtitle">Position on <?= $totalThreatPolls ?> documented executive threats scored 300+</p>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h1>Results By Rep</h1>
                <p class="subtitle">How each member of Congress responded to documented threats.</p>
            </div>
        <?php endif; ?>

        <div class="view-links">
            <a href="/poll/">Vote</a>
            <a href="/poll/national/">National</a>
            <a href="/poll/by-state/">By State</a>
            <a href="/poll/by-rep/" class="active">By Rep</a>
        </div>

        <?php if ($rep): ?>
            <!-- Rep detail -->
            <?php
                $responded = 0; $yeaC = 0; $nayC = 0; $abstainC = 0;
                foreach ($rollCall as $r) {
                    if ($r['rep_position']) { $responded++; }
                    if ($r['rep_position'] === 'yea') $yeaC++;
                    if ($r['rep_position'] === 'nay') $nayC++;
                    if ($r['rep_position'] === 'abstain') $abstainC++;
                }
                $silenceRate = $totalThreatPolls > 0 ? round(($totalThreatPolls - $responded) / $totalThreatPolls * 100) : 100;
            ?>
            <div class="rep-card">
                <div>
                    <div class="rep-name"><?= htmlspecialchars($rep['full_name']) ?></div>
                    <div class="rep-details">
                        <?= htmlspecialchars($rep['title']) ?> &middot; <?= $rep['state_code'] ?>
                        &middot; <span class="party-badge party-<?= $rep['party'] ?>"><?= $rep['party'] ?></span>
                    </div>
                </div>
                <div class="rep-stats">
                    <div class="rep-stat"><div class="num"><?= $responded ?>/<?= $totalThreatPolls ?></div><div class="label">Responded</div></div>
                    <div class="rep-stat"><div class="num"><?= $silenceRate ?>%</div><div class="label">Silence Rate</div></div>
                    <div class="rep-stat"><div class="num" style="color:#4caf50"><?= $yeaC ?></div><div class="label">Yea</div></div>
                    <div class="rep-stat"><div class="num" style="color:#f44336"><?= $nayC ?></div><div class="label">Nay</div></div>
                    <div class="rep-stat"><div class="num" style="color:#888"><?= $abstainC ?></div><div class="label">Abstain</div></div>
                </div>
            </div>

            <table class="roll-call">
                <thead>
                    <tr>
                        <th>Severity</th>
                        <th>Threat</th>
                        <th>Rep Position</th>
                        <th>State Citizens</th>
                        <th>Gap</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rollCall as $r):
                    $zone = getSeverityZone($r['severity_score']);
                    $sTotal = (int)$r['state_votes'];
                    $sNayPct = $sTotal > 0 ? round($r['state_nay'] / $sTotal * 100) : 0;

                    // Gap: if citizens say "nay" (not acceptable) and rep says yea or no response
                    $gap = 0;
                    $gapClass = 'gap-neutral';
                    if ($sTotal > 0 && $r['rep_position'] !== 'nay') {
                        $gap = $sNayPct;
                        $gapClass = $gap >= 50 ? 'gap-high' : ($gap >= 25 ? 'gap-neutral' : 'gap-low');
                    }
                    if ($r['rep_position'] === 'nay') {
                        $gapClass = 'gap-low';
                        $gap = 0;
                    }
                ?>
                    <tr>
                        <td><span class="severity-badge" style="background:<?= $zone['color'] ?>"><?= $r['severity_score'] ?></span></td>
                        <td><?= htmlspecialchars(mb_strimwidth($r['title'], 0, 80, '...')) ?></td>
                        <td>
                            <?php if ($r['rep_position'] === 'yea'): ?>
                                <span class="position-yea">Yea</span>
                            <?php elseif ($r['rep_position'] === 'nay'): ?>
                                <span class="position-nay">Nay</span>
                            <?php elseif ($r['rep_position'] === 'abstain'): ?>
                                <span class="position-abstain">Abstain</span>
                            <?php else: ?>
                                <span class="position-none">No Response</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sTotal > 0): ?>
                                <div class="mini-bar">
                                    <div class="mini-yea" style="width:<?= round($r['state_yea']/$sTotal*100) ?>%"></div>
                                    <div class="mini-nay" style="width:<?= round($r['state_nay']/$sTotal*100) ?>%"></div>
                                </div>
                                <span style="font-size:0.75rem;color:#888;margin-left:0.3rem"><?= $sTotal ?> votes</span>
                            <?php else: ?>
                                <span class="position-none">No data</span>
                            <?php endif; ?>
                        </td>
                        <td class="gap-col <?= $gapClass ?>">
                            <?php if ($gap > 0): ?>
                                <?= $gap ?>%<?= !$r['rep_position'] ? ' silence' : ' gap' ?>
                            <?php elseif ($r['rep_position'] === 'nay' && $sTotal > 0): ?>
                                Aligned
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>
            <!-- Landing: all reps -->
            <div class="controls">
                <select id="stateFilter" onchange="filterReps()">
                    <option value="">All States</option>
                    <?php foreach ($states as $s): ?>
                    <option value="<?= $s ?>" <?= $stateFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="chamberFilter" onchange="filterReps()">
                    <option value="">All Chambers</option>
                    <option value="Senator" <?= $chamberFilter === 'Senator' ? 'selected' : '' ?>>Senate</option>
                    <option value="Representative" <?= $chamberFilter === 'Representative' ? 'selected' : '' ?>>House</option>
                </select>
                <select id="partyFilter" onchange="filterReps()">
                    <option value="">All Parties</option>
                    <option value="R" <?= $partyFilter === 'R' ? 'selected' : '' ?>>Republican</option>
                    <option value="D" <?= $partyFilter === 'D' ? 'selected' : '' ?>>Democrat</option>
                    <option value="I" <?= $partyFilter === 'I' ? 'selected' : '' ?>>Independent</option>
                </select>
            </div>

            <table class="rep-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>State</th>
                        <th>Party</th>
                        <th>Chamber</th>
                        <th>Responded</th>
                        <th>Silence</th>
                        <th>Yea</th>
                        <th>Nay</th>
                    </tr>
                </thead>
                <tbody id="repTableBody">
                <?php foreach ($reps as $r):
                    $responded = (int)$r['threats_responded'];
                    $silence = $totalThreatPolls > 0 ? round(($totalThreatPolls - $responded) / $totalThreatPolls * 100) : 100;
                    $chamber = (strpos($r['title'], 'Senator') !== false || strpos($r['title'], 'Senate') !== false) ? 'Senator' : 'Representative';
                ?>
                    <tr data-state="<?= $r['state_code'] ?>" data-chamber="<?= $chamber ?>" data-party="<?= $r['party'] ?>">
                        <td><a href="/poll/by-rep/<?= htmlspecialchars($r['bioguide_id']) ?>/"><?= htmlspecialchars($r['full_name']) ?></a></td>
                        <td><?= $r['state_code'] ?></td>
                        <td><span class="party-badge party-<?= $r['party'] ?>"><?= $r['party'] ?></span></td>
                        <td><?= $chamber === 'Senator' ? 'Senate' : 'House' ?></td>
                        <td><?= $responded ?>/<?= $totalThreatPolls ?></td>
                        <td class="<?= $silence >= 90 ? 'silence-high' : 'silence-low' ?>"><?= $silence ?>%</td>
                        <td style="color:#4caf50"><?= $r['yea_count'] ?: 0 ?></td>
                        <td style="color:#f44336"><?= $r['nay_count'] ?: 0 ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <script>
    function filterReps() {
        const state = document.getElementById('stateFilter').value;
        const chamber = document.getElementById('chamberFilter').value;
        const party = document.getElementById('partyFilter').value;
        const rows = document.querySelectorAll('#repTableBody tr');

        rows.forEach(row => {
            const matchState = !state || row.dataset.state === state;
            const matchChamber = !chamber || row.dataset.chamber === chamber;
            const matchParty = !party || row.dataset.party === party;
            row.style.display = (matchState && matchChamber && matchParty) ? '' : 'none';
        });
    }
    </script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
