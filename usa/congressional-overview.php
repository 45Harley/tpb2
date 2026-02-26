<?php
/**
 * Congressional Overview — Photo card grid grouped by state delegation
 * Shows all 541 current reps with poll responsiveness stats.
 * State dropdown for anonymous users, auto-scroll for logged-in.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Congressional Overview — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/congressional-overview.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

// All states for dropdown
$allStates = $pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name")->fetchAll(PDO::FETCH_ASSOC);

// User's state: query param > logged-in profile
$paramState = isset($_GET['state']) ? strtoupper(trim($_GET['state'])) : '';
$userState = $paramState ?: strtoupper($userStateAbbr ?? '');

// Total active threat polls
$totalThreatPolls = (int)$pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND active = 1")->fetchColumn();

// All current reps with bioguide_id + poll stats
$reps = $pdo->query("
    SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code,
           eo.bioguide_id, eo.office_name,
           u.user_id,
           COUNT(pv.poll_vote_id) as threats_responded,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_count,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_count
    FROM elected_officials eo
    LEFT JOIN users u ON u.official_id = eo.official_id AND u.deleted_at IS NULL
    LEFT JOIN poll_votes pv ON pv.user_id = u.user_id AND pv.is_rep_vote = 1
    WHERE eo.is_current = 1
      AND eo.bioguide_id IS NOT NULL AND eo.bioguide_id != ''
    GROUP BY eo.official_id
    ORDER BY eo.state_code, eo.title DESC, eo.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// Group by state
$byState = [];
foreach ($reps as $rep) {
    $byState[$rep['state_code']][] = $rep;
}

// State names lookup
$stateNames = [];
foreach ($allStates as $s) {
    $stateNames[$s['abbreviation']] = $s['state_name'];
}

// Sort byState by state name (not code)
uksort($byState, function($a, $b) use ($stateNames) {
    return strcmp($stateNames[$a] ?? $a, $stateNames[$b] ?? $b);
});

$pageStyles = <<<'CSS'
.congress-overview {
    max-width: 1200px;
    margin: 0 auto;
    padding: 30px 20px;
}
.congress-overview h2 {
    text-align: center;
    color: #d4af37;
    font-size: 1.6em;
    margin-bottom: 10px;
}
.congress-subtitle {
    text-align: center;
    color: #888;
    font-size: 0.9em;
    margin-bottom: 25px;
}
.state-picker {
    text-align: center;
    margin-bottom: 30px;
    padding: 20px;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
}
.state-picker label {
    color: #ccc;
    font-size: 0.95em;
    margin-right: 10px;
}
.state-picker select {
    padding: 8px 14px;
    border-radius: 6px;
    border: 1px solid #444;
    background: #0d0d1a;
    color: #e0e0e0;
    font-size: 0.95em;
    cursor: pointer;
}
.state-section {
    margin-bottom: 40px;
    scroll-margin-top: 80px;
}
.state-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid #333;
}
.state-header h3 {
    color: #d4af37;
    font-size: 1.3em;
    margin: 0;
}
.state-header .state-count {
    color: #888;
    font-size: 0.85em;
}
.state-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}
.rep-card {
    width: 150px;
    text-align: center;
    padding: 14px 10px;
    border-radius: 8px;
    background: #1a1a1a;
    border: 2px solid #2a2a2a;
    transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
}
.rep-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.5);
}
.rep-card.party-D { border-color: #2563eb; }
.rep-card.party-R { border-color: #dc2626; }
.rep-card.party-I { border-color: #7c3aed; }
.rep-card:hover.party-D { box-shadow: 0 0 12px rgba(37,99,235,0.3), 0 6px 20px rgba(0,0,0,0.5); }
.rep-card:hover.party-R { box-shadow: 0 0 12px rgba(220,38,38,0.3), 0 6px 20px rgba(0,0,0,0.5); }
.rep-card:hover.party-I { box-shadow: 0 0 12px rgba(124,58,237,0.3), 0 6px 20px rgba(0,0,0,0.5); }

.rep-photo {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: block;
    background: #2a2a2a;
}
.rep-photo-placeholder {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    margin: 0 auto 10px;
    background: #2a2a2a;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #555;
    font-size: 2em;
}
.rep-name {
    color: #e0e0e0;
    font-weight: 700;
    font-size: 0.85em;
    margin-bottom: 3px;
    line-height: 1.2;
}
.rep-party-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.65em;
    font-weight: 700;
    color: #fff;
    margin-bottom: 6px;
}
.badge-D { background: #2563eb; }
.badge-R { background: #dc2626; }
.badge-I { background: #7c3aed; }
.rep-chamber {
    color: #888;
    font-size: 0.7em;
    margin-bottom: 8px;
    line-height: 1.2;
    min-height: 2em;
}
.rep-stats {
    font-size: 0.75em;
    line-height: 1.5;
}
.rep-stats .responded {
    color: #d4af37;
    font-weight: 600;
}
.rep-stats .silence {
    font-weight: 600;
}
.silence-high { color: #f44336; }
.silence-med { color: #ff9800; }
.silence-low { color: #4caf50; }

.highlight-state {
    animation: stateGlow 3s ease-out;
}
@keyframes stateGlow {
    0% { background: rgba(212,175,55,0.15); }
    100% { background: transparent; }
}

/* Responsive */
@media (max-width: 768px) {
    .rep-card { width: calc(33.33% - 12px); min-width: 110px; }
    .rep-photo, .rep-photo-placeholder { width: 80px; height: 80px; }
}
@media (max-width: 480px) {
    .rep-card { width: calc(50% - 10px); }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';

function chamberLabel($title, $officeName) {
    if (stripos($title, 'Senator') !== false) return 'Senate';
    $dist = '';
    if (preg_match('/District (\d+)/', $officeName, $m)) {
        $dist = $m[1] === '0' ? 'At-Large' : 'District ' . $m[1];
    }
    return $dist ? "House — $dist" : 'House';
}

function partyInitial($party) {
    return substr($party, 0, 1) === 'D' ? 'D' : (substr($party, 0, 1) === 'R' ? 'R' : 'I');
}
?>

<div class="congress-overview">
    <h2>Congressional Overview</h2>
    <p class="congress-subtitle"><?= count($reps) ?> members &middot; <?= $totalThreatPolls ?> threat polls tracked</p>

    <div class="state-picker">
        <label for="statePicker">Select your state to see the delegation that represents you:</label>
        <select id="statePicker">
            <option value="">— Choose a state —</option>
            <?php foreach ($allStates as $s): ?>
                <option value="<?= $s['abbreviation'] ?>"<?= $s['abbreviation'] === $userState ? ' selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php foreach ($byState as $stateCode => $members):
        $stateName = $stateNames[$stateCode] ?? $stateCode;
        $senators = array_filter($members, function($m) { return stripos($m['title'], 'Senator') !== false; });
        $houseMembers = array_filter($members, function($m) { return stripos($m['title'], 'Senator') === false; });
    ?>
    <div class="state-section" id="state-<?= $stateCode ?>">
        <div class="state-header">
            <h3><?= htmlspecialchars($stateName) ?></h3>
            <span class="state-count"><?= count($senators) ?> senator<?= count($senators) !== 1 ? 's' : '' ?>, <?= count($houseMembers) ?> rep<?= count($houseMembers) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="state-cards">
            <?php foreach ($members as $m):
                $p = partyInitial($m['party']);
                $bid = $m['bioguide_id'];
                $photoUrl = $bid ? "https://bioguide.congress.gov/bioguide/photo/" . $bid[0] . "/$bid.jpg" : '';
                $responded = (int)$m['threats_responded'];
                $silenceRate = $totalThreatPolls > 0 ? round(($totalThreatPolls - $responded) / $totalThreatPolls * 100) : 100;
                $silenceClass = $silenceRate >= 80 ? 'silence-high' : ($silenceRate >= 40 ? 'silence-med' : 'silence-low');
            ?>
            <div class="rep-card party-<?= $p ?>">
                <?php if ($photoUrl): ?>
                    <img class="rep-photo" src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($m['full_name']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="rep-photo-placeholder">?</div>
                <?php endif; ?>
                <div class="rep-name"><?= htmlspecialchars($m['full_name']) ?></div>
                <span class="rep-party-badge badge-<?= $p ?>"><?= htmlspecialchars($m['party']) ?></span>
                <div class="rep-chamber"><?= chamberLabel($m['title'], $m['office_name']) ?></div>
                <div class="rep-stats">
                    <div class="responded"><?= $responded ?>/<?= $totalThreatPolls ?> responded</div>
                    <div class="silence <?= $silenceClass ?>"><?= $silenceRate ?>% silent</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function() {
    var picker = document.getElementById('statePicker');
    var userState = <?= json_encode($userState) ?>;

    function scrollToState(code) {
        if (!code) return;
        var el = document.getElementById('state-' + code);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.classList.add('highlight-state');
        }
    }

    picker.addEventListener('change', function() {
        scrollToState(this.value);
    });

    // Auto-scroll on load for logged-in users or pre-selected state
    if (userState) {
        setTimeout(function() { scrollToState(userState); }, 300);
    }
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
