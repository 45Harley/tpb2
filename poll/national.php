<?php
/**
 * TPB Poll — National View
 * ========================
 * Aggregate citizen votes across all states for 300+ threats.
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

// National aggregate — citizen votes only (is_rep_vote = 0)
$threats = $pdo->query("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score, et.threat_date,
           eo.full_name as official_name,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_votes,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_votes,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_votes
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id AND pv.is_rep_vote = 0
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
")->fetchAll();

// Summary stats
$totalThreats = count($threats);
$totalVotes = 0;
$totalYea = 0;
$totalNay = 0;
foreach ($threats as $t) {
    $totalVotes += (int)$t['total_votes'];
    $totalYea += (int)$t['yea_votes'];
    $totalNay += (int)$t['nay_votes'];
}

// Tags per threat for data attributes
$threatTags = [];
$r = $pdo->query("
    SELECT tm.threat_id, t.tag_name
    FROM threat_tag_map tm
    JOIN threat_tags t ON tm.tag_id = t.tag_id
    WHERE t.is_active = 1
");
while ($row = $r->fetch()) {
    $threatTags[$row['threat_id']][] = $row['tag_name'];
}

$pageTitle = 'National Results — Polls';
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

        .summary-bar {
            display: flex; gap: 1.5rem; margin-bottom: 1.5rem; padding: 1rem;
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px; flex-wrap: wrap;
        }
        .summary-stat { text-align: center; }
        .summary-stat .num { font-size: 1.5rem; font-weight: 700; color: #d4af37; }
        .summary-stat .label { font-size: 0.75rem; color: #888; }

        .controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .controls select {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #444;
            background: #1a1a2e; color: #e0e0e0; font-size: 0.85rem;
        }

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
        .official-name { font-size: 0.8rem; color: #888; }

        .results-bar {
            height: 24px; background: #2a2a3e; border-radius: 12px;
            overflow: hidden; display: flex; margin-bottom: 0.3rem;
        }
        .results-yea { background: #4caf50; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.75rem; padding: 0 0.4rem; }
        .results-nay { background: #f44336; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.75rem; padding: 0 0.4rem; }
        .results-abstain-seg { background: #666; height: 100%; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 600; font-size: 0.75rem; padding: 0 0.4rem; }
        .results-text { font-size: 0.8rem; color: #888; }

        @media (max-width: 600px) {
            .threat-row-header { flex-direction: column; align-items: flex-start; }
            .summary-bar { gap: 1rem; }
        }
    </style>

    <main class="polls-container">
        <div class="page-header">
            <h1>National Results</h1>
            <p class="subtitle">How America responded to documented executive threats.</p>
        </div>

        <div class="view-links">
            <a href="/poll/">Vote</a>
            <a href="/poll/national/" class="active">National</a>
            <a href="/poll/by-state/">By State</a>
            <a href="/poll/by-rep/">By Rep</a>
        </div>

        <div class="intro-box">
            <p>The national pulse. Every bar below shows how citizens across all 50 states responded to a documented executive, legislative, or judicial threat scored 300+ on the <strong style="color:#d4af37">criminality scale</strong>. Green = &ldquo;acceptable,&rdquo; red = &ldquo;not acceptable.&rdquo; This is the country speaking &mdash; not pundits, not polls of 1,000. Every vote is a verified citizen.</p>
            <p>Want to cast yours? <a href="/poll/">Vote here</a>. Want to see if your reps are listening? <a href="/poll/by-rep/">By Rep</a>.</p>
        </div>

        <div class="summary-bar">
            <div class="summary-stat">
                <div class="num"><?= $totalThreats ?></div>
                <div class="label">Threats Polled</div>
            </div>
            <div class="summary-stat">
                <div class="num"><?= number_format($totalVotes) ?></div>
                <div class="label">Total Votes</div>
            </div>
            <div class="summary-stat">
                <div class="num"><?= $totalVotes > 0 ? round($totalNay / $totalVotes * 100) : 0 ?>%</div>
                <div class="label">Not Acceptable</div>
            </div>
            <div class="summary-stat">
                <div class="num"><?= $totalVotes > 0 ? round($totalYea / $totalVotes * 100) : 0 ?>%</div>
                <div class="label">Acceptable</div>
            </div>
        </div>

        <div class="controls">
            <select id="sortSelect" onchange="sortThreats()">
                <option value="severity">Sort: Severity (highest)</option>
                <option value="votes">Sort: Most votes</option>
                <option value="date">Sort: Date (newest)</option>
            </select>
        </div>

        <div id="threatList">
        <?php foreach ($threats as $t):
            $zone = getSeverityZone($t['severity_score']);
            $total = (int)$t['total_votes'];
            $yeaPct = $total > 0 ? round($t['yea_votes'] / $total * 100, 1) : 0;
            $nayPct = $total > 0 ? round($t['nay_votes'] / $total * 100, 1) : 0;
            $abstainPct = $total > 0 ? round($t['abstain_votes'] / $total * 100, 1) : 0;
            $tags = $threatTags[$t['threat_id']] ?? [];
        ?>
            <div class="threat-row" data-severity="<?= $t['severity_score'] ?>" data-date="<?= $t['threat_date'] ?>" data-votes="<?= $total ?>">
                <div class="threat-row-header">
                    <span class="severity-badge" style="background: <?= $zone['color'] ?>"><?= $t['severity_score'] ?> &mdash; <?= $zone['label'] ?></span>
                    <span class="threat-title"><a href="/usa/executive.php#threat-<?= $t['threat_id'] ?>" style="color:#d4af37;text-decoration:underline;" title="View full threat detail"><?= htmlspecialchars($t['title']) ?></a></span>
                    <?php if ($t['official_name']): ?>
                        <span class="official-name"><?= htmlspecialchars($t['official_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="results-bar">
                    <?php if ($total > 0): ?>
                        <?php if ($yeaPct > 0): ?><div class="results-yea" style="width:<?= $yeaPct ?>%"><?= $yeaPct ?>%</div><?php endif; ?>
                        <?php if ($nayPct > 0): ?><div class="results-nay" style="width:<?= $nayPct ?>%"><?= $nayPct ?>%</div><?php endif; ?>
                        <?php if ($abstainPct > 0): ?><div class="results-abstain-seg" style="width:<?= $abstainPct ?>%"><?= $abstainPct ?>%</div><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="results-text">
                    <?= $total ?> vote<?= $total != 1 ? 's' : '' ?>
                    (<?= $t['yea_votes'] ?: 0 ?> yea, <?= $t['nay_votes'] ?: 0 ?> nay, <?= $t['abstain_votes'] ?: 0 ?> abstain)
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </main>

    <script>
    function sortThreats() {
        const container = document.getElementById('threatList');
        const rows = Array.from(container.querySelectorAll('.threat-row'));
        const sort = document.getElementById('sortSelect').value;
        rows.sort((a, b) => {
            if (sort === 'severity') return (parseInt(b.dataset.severity)||0) - (parseInt(a.dataset.severity)||0);
            if (sort === 'votes') return (parseInt(b.dataset.votes)||0) - (parseInt(a.dataset.votes)||0);
            if (sort === 'date') return (b.dataset.date||'').localeCompare(a.dataset.date||'');
            return 0;
        });
        rows.forEach(r => container.appendChild(r));
    }
    </script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
