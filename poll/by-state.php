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

// State detail mode
$state = null;
$stateThreats = [];
$nationalData = [];

if ($stateCode) {
    $stmt = $pdo->prepare("SELECT state_id, state_name, abbreviation FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateCode]);
    $state = $stmt->fetch();
}

if ($state) {
    // State votes per threat
    $stmt = $pdo->prepare("
        SELECT p.poll_id, p.threat_id,
               et.title, et.severity_score, et.threat_date,
               eo.full_name as official_name,
               COUNT(pv.poll_vote_id) as state_votes,
               SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as state_yea,
               SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as state_nay,
               SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as state_abstain
        FROM polls p
        JOIN executive_threats et ON p.threat_id = et.threat_id
        LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
        LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
            AND pv.is_rep_vote = 0
            AND pv.user_id IN (SELECT user_id FROM users WHERE current_state_id = ? AND deleted_at IS NULL)
        WHERE p.poll_type = 'threat' AND p.active = 1
        GROUP BY p.poll_id
        ORDER BY et.severity_score DESC
    ");
    $stmt->execute([$state['state_id']]);
    $stateThreats = $stmt->fetchAll();

    // National totals for comparison
    $r = $pdo->query("
        SELECT p.poll_id,
               COUNT(pv.poll_vote_id) as nat_votes,
               SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as nat_yea,
               SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nat_nay
        FROM polls p
        LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id AND pv.is_rep_vote = 0
        WHERE p.poll_type = 'threat' AND p.active = 1
        GROUP BY p.poll_id
    ");
    while ($row = $r->fetch()) {
        $nationalData[$row['poll_id']] = $row;
    }
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

        @media (max-width: 600px) {
            .threat-row-header { flex-direction: column; align-items: flex-start; }
            .comparison { flex-direction: column; }
        }
    </style>

    <main class="polls-container">
        <?php if ($state): ?>
            <div class="breadcrumb"><a href="/poll/by-state/">By State</a> &rsaquo; <?= htmlspecialchars($state['state_name']) ?></div>
            <div class="page-header">
                <h1><?= htmlspecialchars($state['state_name']) ?></h1>
                <p class="subtitle">How <?= htmlspecialchars($state['state_name']) ?> citizens voted vs. the nation.</p>
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
            <a href="/poll/by-state/" class="active">By State</a>
            <a href="/poll/by-rep/">By Rep</a>
        </div>

        <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>

        <?php if ($state): ?>
            <!-- State detail intro -->
            <div class="intro-box">
                <p>How <?= htmlspecialchars($state['state_name']) ?> citizens voted on each documented threat, side by side with the national average. Where your state diverges from the country, that's the story &mdash; and the pressure point for your <a href="/poll/by-rep/">representatives</a>.</p>
            </div>
            <!-- State detail view -->
            <?php foreach ($stateThreats as $t):
                $zone = getSeverityZone($t['severity_score']);
                $sTotal = (int)$t['state_votes'];
                $sYeaPct = $sTotal > 0 ? round($t['state_yea'] / $sTotal * 100, 1) : 0;
                $sNayPct = $sTotal > 0 ? round($t['state_nay'] / $sTotal * 100, 1) : 0;
                $sAbstainPct = $sTotal > 0 ? round($t['state_abstain'] / $sTotal * 100, 1) : 0;

                $nat = $nationalData[$t['poll_id']] ?? ['nat_votes' => 0, 'nat_yea' => 0, 'nat_nay' => 0];
                $nTotal = (int)$nat['nat_votes'];
                $nYeaPct = $nTotal > 0 ? round($nat['nat_yea'] / $nTotal * 100, 1) : 0;
                $nNayPct = $nTotal > 0 ? round($nat['nat_nay'] / $nTotal * 100, 1) : 0;
                $nAbstainPct = $nTotal > 0 ? round((($nTotal - $nat['nat_yea'] - $nat['nat_nay']) / $nTotal) * 100, 1) : 0;
            ?>
                <div class="threat-row">
                    <div class="threat-row-header">
                        <span class="severity-badge" style="background: <?= $zone['color'] ?>"><?= $t['severity_score'] ?> &mdash; <?= $zone['label'] ?></span>
                        <span class="threat-title"><a href="/usa/executive.php#threat-<?= $t['threat_id'] ?>" style="color:#d4af37;text-decoration:underline;" title="View full threat detail"><?= htmlspecialchars($t['title']) ?></a></span>
                    </div>
                    <div class="comparison">
                        <div class="comparison-col">
                            <div class="col-label"><?= htmlspecialchars($state['state_name']) ?> (<?= $sTotal ?> votes)</div>
                            <?php if ($sTotal > 0): ?>
                            <div class="results-bar">
                                <?php if ($sYeaPct > 0): ?><div class="results-yea" style="width:<?= $sYeaPct ?>%"><?= $sYeaPct ?>%</div><?php endif; ?>
                                <?php if ($sNayPct > 0): ?><div class="results-nay" style="width:<?= $sNayPct ?>%"><?= $sNayPct ?>%</div><?php endif; ?>
                                <?php if ($sAbstainPct > 0): ?><div class="results-abstain-seg" style="width:<?= $sAbstainPct ?>%"><?= $sAbstainPct ?>%</div><?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="no-votes">No votes from this state yet</div>
                            <?php endif; ?>
                        </div>
                        <div class="comparison-col">
                            <div class="col-label">National (<?= $nTotal ?> votes)</div>
                            <?php if ($nTotal > 0): ?>
                            <div class="results-bar">
                                <?php if ($nYeaPct > 0): ?><div class="results-yea" style="width:<?= $nYeaPct ?>%"><?= $nYeaPct ?>%</div><?php endif; ?>
                                <?php if ($nNayPct > 0): ?><div class="results-nay" style="width:<?= $nNayPct ?>%"><?= $nNayPct ?>%</div><?php endif; ?>
                                <?php if ($nAbstainPct > 0): ?><div class="results-abstain-seg" style="width:<?= $nAbstainPct ?>%"><?= $nAbstainPct ?>%</div><?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="no-votes">No national votes yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($stateThreats)): ?>
                <p style="color: #888;">No threat polls found.</p>
            <?php endif; ?>

        <?php else: ?>
            <!-- 50-state intro -->
            <div class="intro-box">
                <p>Every state below shows how many citizens have voted on executive, legislative, and judicial threats scored 300+ on the <strong style="color:#d4af37">criminality scale</strong>. Click a state to see how it voted on each threat compared to the national average. Not yet voted? <a href="/poll/">Cast yours</a>.</p>
            </div>
            <!-- 50-state landing -->
            <table class="state-table">
                <thead>
                    <tr>
                        <th>State</th>
                        <th>Voters</th>
                        <th>Total Votes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stateList as $s): ?>
                    <tr>
                        <td><a href="/poll/by-state/<?= strtolower($s['abbreviation']) ?>/"><?= htmlspecialchars($s['state_name']) ?></a> (<?= $s['abbreviation'] ?>)</td>
                        <td><?= $s['unique_voters'] ?: 0 ?></td>
                        <td><?= $s['total_votes'] ?: 0 ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
