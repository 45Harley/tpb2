<?php
/**
 * TPB Poll — By Rep View (State-Centric)
 * =======================================
 * Landing: your state's delegation (senators + house reps) with roll call summary.
 * State switcher to view other states.
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
$repIdParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$totalThreatPolls = (int)$pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND active = 1")->fetchColumn();

// All states for switcher
$allStates = $pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

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
} elseif ($repIdParam) {
    $stmt = $pdo->prepare("
        SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code, eo.bioguide_id
        FROM elected_officials eo
        WHERE eo.official_id = ? AND eo.is_current = 1
    ");
    $stmt->execute([$repIdParam]);
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

// Landing mode: state-centric delegation view
$stateReps = [];
$viewState = null;
$viewStateCode = '';

if (!$rep) {
    // Determine which state to show
    $requestedState = strtoupper(trim($_GET['state_filter'] ?? ''));

    if ($requestedState) {
        $viewStateCode = $requestedState;
    } elseif ($dbUser && !empty($dbUser['current_state_id'])) {
        // Default to user's state
        $stmt = $pdo->prepare("SELECT abbreviation FROM states WHERE state_id = ?");
        $stmt->execute([$dbUser['current_state_id']]);
        $viewStateCode = $stmt->fetchColumn() ?: '';
    }

    if ($viewStateCode) {
        $stmt = $pdo->prepare("SELECT state_id, abbreviation, state_name FROM states WHERE abbreviation = ?");
        $stmt->execute([$viewStateCode]);
        $viewState = $stmt->fetch();
    }

    if ($viewState) {
        // Get reps for this state with vote stats
        $stmt = $pdo->prepare("
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
              AND eo.state_code = ?
              AND eo.bioguide_id IS NOT NULL AND eo.bioguide_id != ''
            GROUP BY eo.official_id
            ORDER BY eo.title DESC, eo.full_name
        ");
        $stmt->execute([$viewState['abbreviation']]);
        $stateReps = $stmt->fetchAll();
    }
}

$pageTitle = $rep
    ? "{$rep['full_name']} — Roll Call"
    : ($viewState ? "{$viewState['state_name']} Delegation — Polls" : 'Your Representatives — Polls');
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

        .intro-box {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #ccc;
            font-size: 0.9rem; line-height: 1.6;
        }
        .intro-box p { margin: 0 0 0.4rem; }
        .intro-box a { color: #d4af37; }

        /* State switcher */
        .state-switcher {
            display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .state-switcher label { color: #888; font-size: 0.85rem; font-weight: 600; }
        .state-switcher select {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #444;
            background: #1a1a2e; color: #e0e0e0; font-size: 0.85rem;
        }

        /* Rep card in delegation view */
        .rep-card {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1.25rem; margin-bottom: 1rem; display: flex; gap: 1rem;
            align-items: center; flex-wrap: wrap; transition: border-color 0.2s;
        }
        .rep-card:hover { border-color: #555; }
        .rep-card .rep-info { flex: 1; min-width: 200px; }
        .rep-card .rep-name { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; }
        .rep-card .rep-name a { color: #d4af37; text-decoration: none; }
        .rep-card .rep-details { font-size: 0.85rem; color: #888; margin-top: 0.2rem; }
        .party-badge {
            display: inline-block; padding: 0.15rem 0.45rem; border-radius: 4px;
            font-size: 0.7rem; font-weight: 700; color: #fff;
        }
        .party-R { background: #c0392b; }
        .party-D { background: #2980b9; }
        .party-I { background: #8e44ad; }
        .rep-stats { display: flex; gap: 1.25rem; flex-wrap: wrap; }
        .rep-stat { text-align: center; }
        .rep-stat .num { font-size: 1.2rem; font-weight: 700; color: #d4af37; }
        .rep-stat .label { font-size: 0.7rem; color: #888; }
        .silence-high { color: #f44336 !important; }

        /* Roll call table (detail view) */
        .rep-detail-card {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1.25rem; margin-bottom: 1.5rem; display: flex; gap: 1rem;
            align-items: center; flex-wrap: wrap;
        }
        .rep-detail-card .rep-name { font-size: 1.2rem; font-weight: 700; color: #e0e0e0; }
        .rep-detail-card .rep-details { font-size: 0.9rem; color: #888; }

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

        .mini-bar {
            height: 14px; background: #2a2a3e; border-radius: 7px;
            overflow: hidden; display: inline-flex; width: 100px;
        }
        .mini-yea { background: #4caf50; height: 100%; }
        .mini-nay { background: #f44336; height: 100%; }

        .no-state-prompt {
            background: #1a1a2e; border: 1px solid #d4af37; border-radius: 8px;
            padding: 2rem; text-align: center; color: #ccc;
        }
        .no-state-prompt h3 { color: #d4af37; margin-bottom: 0.75rem; }

        @media (max-width: 768px) {
            .roll-call { font-size: 0.75rem; }
            .roll-call th, .roll-call td { padding: 0.4rem; }
            .rep-card, .rep-detail-card { flex-direction: column; }
            .rep-stats { justify-content: flex-start; }
        }
    </style>

    <main class="polls-container">
        <?php if ($rep): ?>
            <div class="breadcrumb"><a href="/poll/by-rep/">By Rep</a> &rsaquo; <?= htmlspecialchars($rep['full_name']) ?></div>
            <div class="page-header">
                <h1>Roll Call: <?= htmlspecialchars($rep['full_name']) ?></h1>
                <p class="subtitle">Position on <?= $totalThreatPolls ?> documented threats scored 300+</p>
            </div>
        <?php elseif ($viewState): ?>
            <div class="page-header">
                <h1><?= htmlspecialchars($viewState['state_name']) ?> Delegation</h1>
                <p class="subtitle">How your representatives responded to documented threats.</p>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h1>Your Representatives</h1>
                <p class="subtitle">Find your state to see how your delegation responded.</p>
            </div>
        <?php endif; ?>

        <div class="view-links">
            <a href="/poll/">Vote</a>
            <a href="/poll/national/">National</a>
            <a href="/poll/by-state/">By State</a>
            <a href="/poll/by-rep/" class="active">By Rep</a>
        </div>

        <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>

        <?php if ($rep): ?>
            <!-- Rep detail intro -->
            <div class="intro-box">
                <p>The full roll call for <?= htmlspecialchars($rep['full_name']) ?>. Every threat scored 300+ is listed with their position alongside how <?= htmlspecialchars($rep['state_code']) ?> citizens voted on the same threat. The &ldquo;gap&rdquo; column shows where your representative diverges from constituents &mdash; or stays silent.</p>
            </div>

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
            <div class="rep-detail-card">
                <div>
                    <div class="rep-name"><?= htmlspecialchars($rep['full_name']) ?></div>
                    <div class="rep-details">
                        <?= htmlspecialchars($rep['title']) ?> &middot; <?= $rep['state_code'] ?>
                        &middot; <span class="party-badge party-<?= $rep['party'] ?>"><?= $rep['party'] ?></span>
                    </div>
                </div>
                <div class="rep-stats">
                    <div class="rep-stat"><div class="num"><?= $responded ?>/<?= $totalThreatPolls ?></div><div class="label">Responded</div></div>
                    <div class="rep-stat"><div class="num <?= $silenceRate >= 90 ? 'silence-high' : '' ?>"><?= $silenceRate ?>%</div><div class="label">Silence Rate</div></div>
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
                        <th><?= htmlspecialchars($rep['state_code']) ?> Citizens</th>
                        <th>Gap</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rollCall as $r):
                    $zone = getSeverityZone($r['severity_score']);
                    $sTotal = (int)$r['state_votes'];
                    $sNayPct = $sTotal > 0 ? round($r['state_nay'] / $sTotal * 100) : 0;

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
                        <td><a href="/usa/executive.php#threat-<?= $r['threat_id'] ?>" style="color:#d4af37;text-decoration:underline;" title="View full threat detail"><?= htmlspecialchars(mb_strimwidth($r['title'], 0, 80, '...')) ?></a></td>
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

        <?php elseif ($viewState): ?>
            <!-- State delegation intro -->
            <div class="intro-box">
                <p>Your <?= htmlspecialchars($viewState['state_name']) ?> delegation &mdash; senators and representatives &mdash; and how each responded to <?= $totalThreatPolls ?> documented threats. A high silence rate means they haven't weighed in. Click a name to see their full roll call, threat by threat, compared to how <?= htmlspecialchars($viewState['state_name']) ?> citizens voted.</p>
            </div>

            <!-- State switcher -->
            <div class="state-switcher">
                <label for="stateSwitcher">Viewing:</label>
                <select id="stateSwitcher" onchange="switchState()">
                    <?php foreach ($allStates as $s): ?>
                    <option value="<?= $s['abbreviation'] ?>" <?= $viewStateCode === $s['abbreviation'] ? 'selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Delegation cards -->
            <?php if (empty($stateReps)): ?>
                <p style="color: #888;">No current representatives found for <?= htmlspecialchars($viewState['state_name']) ?>.</p>
            <?php endif; ?>

            <?php foreach ($stateReps as $r):
                $responded = (int)$r['threats_responded'];
                $silence = $totalThreatPolls > 0 ? round(($totalThreatPolls - $responded) / $totalThreatPolls * 100) : 100;
                $chamber = (strpos($r['title'], 'Senator') !== false || strpos($r['title'], 'Senate') !== false) ? 'Senate' : 'House';
            ?>
                <div class="rep-card">
                    <div class="rep-info">
                        <div class="rep-name"><a href="/poll/by-rep/<?= htmlspecialchars($r['bioguide_id']) ?>/"><?= htmlspecialchars($r['full_name']) ?></a></div>
                        <div class="rep-details">
                            <?= $chamber ?> &middot;
                            <span class="party-badge party-<?= $r['party'] ?>"><?= $r['party'] ?></span>
                        </div>
                    </div>
                    <div class="rep-stats">
                        <div class="rep-stat"><div class="num"><?= $responded ?>/<?= $totalThreatPolls ?></div><div class="label">Responded</div></div>
                        <div class="rep-stat"><div class="num <?= $silence >= 90 ? 'silence-high' : '' ?>"><?= $silence ?>%</div><div class="label">Silence</div></div>
                        <div class="rep-stat"><div class="num" style="color:#4caf50"><?= $r['yea_count'] ?: 0 ?></div><div class="label">Yea</div></div>
                        <div class="rep-stat"><div class="num" style="color:#f44336"><?= $r['nay_count'] ?: 0 ?></div><div class="label">Nay</div></div>
                        <div class="rep-stat"><div class="num" style="color:#888"><?= $r['abstain_count'] ?: 0 ?></div><div class="label">Abstain</div></div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <!-- No state: show picker -->
            <div class="intro-box">
                <p>See how your congressional delegation responded to documented threats. Select your state below to view your senators and representatives, their silence rates, and how their positions compare to citizen votes.</p>
            </div>

            <div class="no-state-prompt">
                <h3>Select Your State</h3>
                <select id="stateSwitcher" onchange="switchState()" style="padding: 0.5rem 1rem; border-radius: 6px; border: 1px solid #444; background: #0a0a0f; color: #e0e0e0; font-size: 1rem;">
                    <option value="">Choose a state...</option>
                    <?php foreach ($allStates as $s): ?>
                    <option value="<?= $s['abbreviation'] ?>"><?= htmlspecialchars($s['state_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </main>

    <script>
    function switchState() {
        const state = document.getElementById('stateSwitcher').value;
        if (state) {
            window.location.href = '/poll/by-rep/?state_filter=' + state;
        }
    }
    </script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
