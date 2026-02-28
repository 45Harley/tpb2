<?php
/**
 * Elections — The Fight
 * =====================
 * Ported from tpb.sandgems.net/the-fight.php
 * Pledges + Knockouts action tracker for Election 2026.
 * TPB2 auth via getUser(), civic points via PointLogger.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'The Fight — Elections 2026';
$ogTitle = 'The Fight — Take the Pledge for Democracy';
$ogDescription = '14 pledges. 14 knockouts. Track your civic actions and hold power accountable in Election 2026.';

$isLoggedIn = (bool)$dbUser;
$userId = $dbUser ? $dbUser['user_id'] : null;
$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
$shareText = "14 pledges. 14 knockouts. Track threats to democracy. Hold power accountable. Join The Fight.";

// Get all active pledges with their primary knockout
$pledges = $pdo->query("
    SELECT p.*, k.knockout_id, k.label as knockout_label
    FROM pledges p
    LEFT JOIN pledge_knockouts pk ON p.pledge_id = pk.pledge_id
    LEFT JOIN knockouts k ON pk.knockout_id = k.knockout_id
    WHERE p.is_active = 1
    GROUP BY p.pledge_id
    ORDER BY p.display_order
")->fetchAll(PDO::FETCH_ASSOC);

// Get user's pledges and knockouts
$userPledgeIds = [];
$userKnockoutIds = [];
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT pledge_id FROM user_pledges WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userPledgeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT knockout_id FROM user_knockouts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userKnockoutIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Calculate progress
$totalItems = count($pledges) * 2;
$checkedItems = count($userPledgeIds) + count($userKnockoutIds);
$progressPct = $totalItems > 0 ? round(($checkedItems / $totalItems) * 100) : 0;

// Global stats
$fighters = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_pledges")->fetchColumn();
$pledgesTotal = $pdo->query("SELECT COUNT(*) FROM user_pledges")->fetchColumn();
$knockoutsTotal = $pdo->query("SELECT COUNT(*) FROM user_knockouts")->fetchColumn();

// User's threat activity (from TPB2 tables)
$threatActivity = ['rated' => 0, 'shared' => 0, 'emailed' => 0];
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM threat_ratings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $threatActivity['rated'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT action_type, COUNT(*) as c FROM threat_responses
        WHERE user_id = ? GROUP BY action_type
    ");
    $stmt->execute([$userId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['action_type'] === 'shared') $threatActivity['shared'] = $row['c'];
        if ($row['action_type'] === 'emailed') $threatActivity['emailed'] = $row['c'];
        if ($row['action_type'] === 'called') $threatActivity['called'] = $row['c'];
    }
}

// User's poll votes
$pollVoteCount = 0;
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM poll_votes WHERE user_id = ?");
    $stmt->execute([$userId]);
    $pollVoteCount = $stmt->fetchColumn();
}

$pageStyles = <<<'CSS'
.fight-container { max-width: 800px; margin: 0 auto; padding: 2rem 1rem; }

.fight-header { text-align: center; margin-bottom: 2rem; }
.fight-header h1 { font-size: 2.5em; color: #ff4444; text-transform: uppercase; letter-spacing: 4px; margin-bottom: 0.25rem; }
.fight-header .tagline { font-size: 1.1rem; color: #d4af37; font-style: italic; }

.progress-section {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem; margin-bottom: 1.5rem;
}
.progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
.progress-header h2 { color: #fff; font-size: 1rem; margin: 0; }
.progress-pct { color: #00ff66; font-size: 1.5em; font-weight: 700; }
.progress-bar { background: #0a0a0f; border: 2px solid #333; border-radius: 12px; height: 20px; overflow: hidden; }
.progress-bar .fill { background: linear-gradient(90deg, #3399ff, #00dd55); height: 100%; transition: width 0.3s; }

.auto-section {
    background: #1a1a2e; border: 1px solid #2a4a2a; border-radius: 8px;
    padding: 1.25rem; margin-bottom: 1.5rem;
}
.auto-section h2 { color: #88cc88; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; margin: 0 0 1rem; }
.auto-item { display: flex; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #1a2a1a; color: #88cc88; font-size: 0.9rem; }
.auto-item:last-child { border-bottom: none; }
.auto-item .check { color: #00dd55; margin-right: 0.75rem; font-size: 1.1em; }
.auto-item .count { margin-left: auto; background: #1a2a1a; padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; }

.login-prompt {
    background: #1a1a2e; border: 1px solid #d4af37; border-radius: 8px;
    padding: 1.5rem; margin-bottom: 1.5rem; text-align: center;
}
.login-prompt h3 { color: #d4af37; margin: 0 0 0.5rem; }
.login-prompt p { color: #aaa; margin: 0; font-size: 0.9rem; }
.login-prompt a { color: #d4af37; }

.fight-grid {
    display: grid; grid-template-columns: 1fr 50px 1fr;
    gap: 0; margin-bottom: 1.5rem;
}
.column-header {
    padding: 0.75rem 1rem; text-align: center; font-size: 0.8rem;
    text-transform: uppercase; letter-spacing: 2px; font-weight: 700;
}
.pledges-header { background: #1a2a1a; color: #88cc88; border-radius: 8px 0 0 0; border: 1px solid #2a4a2a; border-right: none; }
.arrow-header { background: #1a1a2e; border-top: 1px solid #333; border-bottom: 1px solid #333; }
.knockouts-header { background: #2a1a1a; color: #ff6666; border-radius: 0 8px 0 0; border: 1px solid #4a2a2a; border-left: none; }

.pledges-col { background: #0f170f; border: 1px solid #2a4a2a; border-top: none; border-right: none; border-radius: 0 0 0 8px; padding: 0.5rem 0; }
.arrow-col { background: #1a1a2e; border-top: none; border-bottom: 1px solid #333; display: flex; flex-direction: column; justify-content: space-around; align-items: center; padding: 0.5rem 0; }
.knockouts-col { background: #170f0f; border: 1px solid #4a2a2a; border-top: none; border-left: none; border-radius: 0 0 8px 0; padding: 0.5rem 0; }

.action-row { display: flex; align-items: center; padding: 0.7rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
.action-row:last-child { border-bottom: none; }
.action-row input[type="checkbox"] {
    appearance: none; -webkit-appearance: none; width: 22px; height: 22px;
    margin-right: 0.75rem; cursor: pointer; background: #1a1a2e;
    border: 2px solid #444; border-radius: 4px; position: relative; flex-shrink: 0;
}
.pledges-col .action-row input[type="checkbox"]:checked { background: #00cc44; border-color: #00cc44; }
.knockouts-col .action-row input[type="checkbox"]:checked { background: #ff4444; border-color: #ff4444; }
.action-row input[type="checkbox"]:checked::after {
    content: '\2713'; position: absolute; top: 50%; left: 50%;
    transform: translate(-50%, -50%); color: #000; font-size: 14px; font-weight: 900;
}
.action-row input[type="checkbox"]:disabled { cursor: not-allowed; opacity: 0.4; }
.action-row label { color: #aaa; font-size: 0.85rem; cursor: pointer; }
.action-row.checked label { color: #fff; font-weight: 500; }
.arrow { color: #555; font-size: 1.2em; }
.arrow.lit { color: #ffdd00; }

.motto {
    text-align: center; padding: 1.25rem; background: #1a1a2e;
    border: 1px solid #333; border-radius: 8px; margin-bottom: 1.5rem;
}
.motto p { font-size: 1.05rem; color: #888; margin: 0; }
.motto .pledge-word { color: #88cc88; }
.motto .knockout-word { color: #ff6666; }

.stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
.stat-box {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem; text-align: center;
}
.stat-box .number { font-size: 2em; font-weight: 700; color: #3399ff; }
.stat-box .label { font-size: 0.7rem; color: #888; text-transform: uppercase; letter-spacing: 1px; }
.stat-box.knockouts .number { color: #ff4444; }

.battle-cry {
    text-align: center; padding: 1.25rem;
    background: linear-gradient(135deg, #1a1a2e 0%, #2a1a2a 100%);
    border: 2px solid #ff4444; border-radius: 8px;
}
.battle-cry p { font-size: 1.3em; font-weight: 700; color: #ff4444; font-style: italic; margin: 0; }

.share-section { text-align: center; margin-top: 1.5rem; }
.share-section p { color: #888; font-size: 0.85rem; margin-bottom: 0.5rem; }
.share-row { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
.share-btn {
    padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none;
    font-weight: 600; font-size: 0.85rem; transition: transform 0.2s, opacity 0.2s;
    display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer;
}
.share-btn:hover { transform: translateY(-1px); opacity: 0.9; }
.share-btn.x { background: #000; color: #fff; border: 1px solid #333; }
.share-btn.bsky { background: #0085ff; color: #fff; }
.share-btn.fb { background: #1877f2; color: #fff; }
.share-btn.email { background: #38a169; color: #fff; }

.points-flash {
    position: fixed; top: 20px; right: 20px; background: #d4af37; color: #000;
    padding: 0.5rem 1rem; border-radius: 6px; font-weight: 700; font-size: 0.9rem;
    opacity: 0; transition: opacity 0.3s; z-index: 1000; pointer-events: none;
}
.points-flash.show { opacity: 1; }

@media (max-width: 600px) {
    .fight-header h1 { font-size: 1.8em; }
    .fight-grid { grid-template-columns: 1fr 40px 1fr; }
    .action-row { padding: 0.5rem 0.5rem; }
    .action-row label { font-size: 0.75rem; }
    .stats-row { grid-template-columns: 1fr; }
}

.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
    text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
}
.view-links a:hover { border-color: #d4af37; color: #d4af37; }
.view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

/* ── Fight Intro ── */
.fight-intro { text-align: left; max-width: 700px; margin: 0 auto; padding: 1rem 0; }
.intro-question { font-size: 1.2rem; font-weight: 700; color: #d4af37; margin-bottom: 0.75rem; }
.intro-body { font-size: 0.95rem; line-height: 1.7; color: #bbb; }

@media (max-width: 600px) {
    .fight-intro { padding: 0.5rem 0; }
    .intro-question { font-size: 1rem; }
    .intro-body { font-size: 0.85rem; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<main class="fight-container">
    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php" class="active">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php">Threats</a>
        <a href="/elections/races.php">Races</a>
    </div>

    <div class="fight-header">
        <h1>The Fight</h1>
        <div class="fight-intro">
            <p class="intro-question">What does the Golden Rule demand right now?</p>
            <p class="intro-body">The Fight is where citizens ask that question together. A threat appears. An institution caves. A law is broken. And we respond &mdash; with evidence, with ideas, with action. Not a shooting match. Not a shouting match. A discovery. Even when your idea doesn't prevail, you win &mdash; because you were heard, you learned, and the truth got sharper. That's the Golden Rule: you'd want the same for yourself. A debate where everyone wins is just a fancy word for learning. And learning, together, is how we build a more perfect Union.</p>
        </div>
    </div>

    <?php if ($isLoggedIn): ?>
        <div class="progress-section">
            <div class="progress-header">
                <h2>Your Progress</h2>
                <span class="progress-pct"><?= $progressPct ?>%</span>
            </div>
            <div class="progress-bar">
                <div class="fill" id="progressFill" style="width: <?= $progressPct ?>%"></div>
            </div>
        </div>

        <div class="auto-section">
            <h2>Your Activity (Auto-Tracked)</h2>
            <?php if ($threatActivity['rated'] > 0): ?>
            <div class="auto-item"><span class="check">&#10003;</span> Rated threats <span class="count"><?= $threatActivity['rated'] ?></span></div>
            <?php endif; ?>
            <?php if ($threatActivity['shared'] > 0): ?>
            <div class="auto-item"><span class="check">&#10003;</span> Shared threats <span class="count"><?= $threatActivity['shared'] ?></span></div>
            <?php endif; ?>
            <?php if (($threatActivity['emailed'] ?? 0) > 0): ?>
            <div class="auto-item"><span class="check">&#10003;</span> Emailed reps <span class="count"><?= $threatActivity['emailed'] ?></span></div>
            <?php endif; ?>
            <?php if (($threatActivity['called'] ?? 0) > 0): ?>
            <div class="auto-item"><span class="check">&#10003;</span> Called reps <span class="count"><?= $threatActivity['called'] ?></span></div>
            <?php endif; ?>
            <?php if ($pollVoteCount > 0): ?>
            <div class="auto-item"><span class="check">&#10003;</span> Poll votes cast <span class="count"><?= $pollVoteCount ?></span></div>
            <?php endif; ?>
            <?php if ($threatActivity['rated'] == 0 && $threatActivity['shared'] == 0 && $pollVoteCount == 0): ?>
            <div class="auto-item" style="color: #666;">No activity yet. <a href="/poll/" style="color: #3399ff;">Vote on threats</a> to get started.</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="login-prompt">
            <h3>Join The Fight</h3>
            <p>Verify your email to track your pledges, earn civic points, and land knockouts.<br>
            <a href="/join.php">Join now</a> or <a href="/poll/">vote on threats</a> to get started.</p>
        </div>
    <?php endif; ?>

    <div class="fight-grid">
        <div class="column-header pledges-header">Pledges</div>
        <div class="column-header arrow-header"></div>
        <div class="column-header knockouts-header">Knockouts</div>

        <div class="pledges-col">
            <?php foreach ($pledges as $pledge):
                $isPledged = in_array($pledge['pledge_id'], $userPledgeIds);
            ?>
            <div class="action-row <?= $isPledged ? 'checked' : '' ?>">
                <input type="checkbox" class="pledge-checkbox"
                       data-pledge-id="<?= $pledge['pledge_id'] ?>"
                       <?= $isPledged ? 'checked' : '' ?>
                       <?= !$isLoggedIn ? 'disabled' : '' ?>>
                <label><?= htmlspecialchars($pledge['label']) ?></label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="arrow-col">
            <?php foreach ($pledges as $pledge):
                $isPledged = in_array($pledge['pledge_id'], $userPledgeIds);
            ?>
            <span class="arrow <?= $isPledged ? 'lit' : '' ?>">&#8594;</span>
            <?php endforeach; ?>
        </div>

        <div class="knockouts-col">
            <?php foreach ($pledges as $pledge):
                $isAchieved = in_array($pledge['knockout_id'], $userKnockoutIds);
            ?>
            <div class="action-row <?= $isAchieved ? 'checked' : '' ?>">
                <input type="checkbox" class="knockout-checkbox"
                       data-knockout-id="<?= $pledge['knockout_id'] ?>"
                       <?= $isAchieved ? 'checked' : '' ?>
                       <?= !$isLoggedIn ? 'disabled' : '' ?>>
                <label><?= htmlspecialchars($pledge['knockout_label']) ?></label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="motto">
        <p><span class="pledge-word">Pledges</span> get you ready. <span class="knockout-word">Knockouts</span> win the fight.</p>
    </div>

    <div class="stats-row">
        <div class="stat-box">
            <div class="number"><?= number_format($fighters) ?></div>
            <div class="label">In The Fight</div>
        </div>
        <div class="stat-box">
            <div class="number"><?= number_format($pledgesTotal) ?></div>
            <div class="label">Pledges Made</div>
        </div>
        <div class="stat-box knockouts">
            <div class="number"><?= number_format($knockoutsTotal) ?></div>
            <div class="label">Knockouts Landed</div>
        </div>
    </div>

    <div class="battle-cry">
        <p>"Hey diddle diddle, up the middle."</p>
    </div>

    <div class="share-section">
        <p>Every share recruits another citizen.</p>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>&url=<?= urlencode("$siteUrl/elections/the-fight.php") ?>" target="_blank" class="share-btn x">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText . " $siteUrl/elections/the-fight.php") ?>" target="_blank" class="share-btn bsky">Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("$siteUrl/elections/the-fight.php") ?>" target="_blank" class="share-btn fb">Facebook</a>
            <a href="mailto:?subject=<?= urlencode('Join The Fight — The People\'s Branch') ?>&body=<?= urlencode($shareText . "\n\n$siteUrl/elections/the-fight.php") ?>" class="share-btn email">Email</a>
        </div>
    </div>

    <?php
    $talkStreamPlaceholder = 'What does the Golden Rule demand right now?';
    require dirname(__DIR__) . '/includes/talk-stream.php';
    ?>

</main>
<div class="points-flash" id="pointsFlash"></div>

<?php if ($isLoggedIn): ?>
<script>
function showPoints(pts) {
    const el = document.getElementById('pointsFlash');
    el.textContent = '+' + pts + ' civic points!';
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2000);
    if (window.tpbUpdateNavPoints) window.tpbUpdateNavPoints();
}

function updateProgress() {
    const pledged = document.querySelectorAll('.pledge-checkbox:checked').length;
    const knocked = document.querySelectorAll('.knockout-checkbox:checked').length;
    const total = <?= $totalItems ?>;
    const pct = total > 0 ? Math.round(((pledged + knocked) / total) * 100) : 0;
    document.getElementById('progressFill').style.width = pct + '%';
    document.querySelector('.progress-pct').textContent = pct + '%';
}

document.querySelectorAll('.pledge-checkbox').forEach((cb, index) => {
    cb.addEventListener('change', function() {
        const pledgeId = this.dataset.pledgeId;
        const checked = this.checked ? 1 : 0;
        const row = this.closest('.action-row');
        const arrows = document.querySelectorAll('.arrow-col .arrow');

        fetch('/api/pledge-action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({pledge_id: pledgeId, checked: checked})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (checked) {
                    row.classList.add('checked');
                    arrows[index].classList.add('lit');
                } else {
                    row.classList.remove('checked');
                    arrows[index].classList.remove('lit');
                }
                if (data.points_earned > 0) showPoints(data.points_earned);
                updateProgress();
            }
        });
    });
});

document.querySelectorAll('.knockout-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const knockoutId = this.dataset.knockoutId;
        const checked = this.checked ? 1 : 0;
        const row = this.closest('.action-row');

        fetch('/api/knockout-action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({knockout_id: knockoutId, checked: checked})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (checked) {
                    row.classList.add('checked');
                } else {
                    row.classList.remove('checked');
                }
                if (data.points_earned > 0) showPoints(data.points_earned);
                updateProgress();
            }
        });
    });
});
</script>
<?php endif; ?>


<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
