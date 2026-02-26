<?php
/**
 * Judicial Branch — Federal judges overview
 * ==========================================
 * Three tiers: Supreme Court, Circuit Courts, District Courts.
 * Data loaded from CourtListener via scripts/db/load-judicial-data.php.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Judicial Branch — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/congressional-overview.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

// ── Data queries ──

// SCOTUS justices
$scotus = $pdo->query("
    SELECT * FROM elected_officials
    WHERE court_type = 'supreme' AND is_current = 1
    ORDER BY chief_judge DESC, term_start ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Circuit judges grouped by court_id
$circuits = $pdo->query("
    SELECT * FROM elected_officials
    WHERE court_type = 'circuit' AND is_current = 1
    ORDER BY court_id, chief_judge DESC, senior_status ASC, full_name
")->fetchAll(PDO::FETCH_ASSOC);
$circuitGroups = [];
foreach ($circuits as $j) {
    $circuitGroups[$j['court_id']][] = $j;
}

// District judges grouped by state
$districts = $pdo->query("
    SELECT * FROM elected_officials
    WHERE court_type = 'district' AND is_current = 1
    ORDER BY state_code, court_name, chief_judge DESC, senior_status ASC, full_name
")->fetchAll(PDO::FETCH_ASSOC);
$districtByState = [];
foreach ($districts as $j) {
    $sc = $j['state_code'] ?: '??';
    $districtByState[$sc][] = $j;
}
ksort($districtByState);

// Circuit-state mappings
$circuitStateMap = [];
$circuitNameMap = [];
foreach ($pdo->query("SELECT circuit_id, circuit_name, state_code FROM circuit_states ORDER BY circuit_id, state_code") as $cs) {
    $circuitStateMap[$cs['circuit_id']][] = $cs['state_code'];
    $circuitNameMap[$cs['circuit_id']] = $cs['circuit_name'];
}

// State names
$stateNames = [];
foreach ($pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name") as $s) {
    $stateNames[$s['abbreviation']] = $s['state_name'];
}

// Ordered circuit list
$circuitOrder = ['ca1','ca2','ca3','ca4','ca5','ca6','ca7','ca8','ca9','ca10','ca11','cadc','cafc'];

// Counts
$scotusCount = count($scotus);
$circuitCount = count($circuits);
$districtCount = count($districts);

$pageStyles = <<<'CSS'
.judicial-overview {
    max-width: 1200px;
    margin: 0 auto;
    padding: 30px 20px;
}
.judicial-overview h2 {
    text-align: center;
    color: #d4af37;
    font-size: 1.6em;
    margin-bottom: 8px;
}
.judicial-subtitle {
    text-align: center;
    color: #888;
    font-size: 0.95em;
    margin-bottom: 24px;
}

/* Summary stats */
.judicial-stats {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-bottom: 32px;
    flex-wrap: wrap;
}
.judicial-stat {
    text-align: center;
    padding: 12px 20px;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    min-width: 120px;
}
.judicial-stat .num { font-size: 1.5em; font-weight: 700; color: #d4af37; }
.judicial-stat .label { font-size: 0.75em; color: #888; margin-top: 4px; }

/* Court sections */
.court-section { margin-bottom: 40px; }
.court-section > h3 {
    color: #d4af37;
    font-size: 1.3em;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid #333;
}

/* Card grids */
.cards-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 16px;
}
.judge-card {
    width: 150px;
    text-align: center;
    padding: 14px 10px;
    border-radius: 8px;
    background: #1a1a1a;
    border: 2px solid #2a2a2a;
    transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
    text-decoration: none;
    display: block;
    cursor: pointer;
}
.judge-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.5);
    border-color: #d4af37;
}
.judge-card.boss-card {
    width: 170px;
    border-color: #d4af37;
    box-shadow: 0 0 12px rgba(212,175,55,0.3);
}
.judge-card.boss-card:hover {
    box-shadow: 0 0 20px rgba(212,175,55,0.5), 0 6px 20px rgba(0,0,0,0.5);
}
.judge-card.senior-card {
    opacity: 0.7;
    border-style: dashed;
}
.card-photo {
    width: 100px;
    height: 100px;
    object-fit: cover;
    border-radius: 50%;
    margin: 0 auto 10px;
    display: block;
    background: #2a2a2a;
}
.card-photo-placeholder {
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

/* SCOTUS hero section — bigger cards & photos */
.scotus-section .cards-grid { gap: 20px; }
.scotus-section .judge-card { width: 170px; padding: 18px 12px; }
.scotus-section .judge-card.boss-card { width: 190px; }
.scotus-section .card-photo,
.scotus-section .card-photo-placeholder { width: 120px; height: 120px; }
.scotus-section .card-name { font-size: 0.95em; }
.scotus-section .card-title { font-size: 0.75em; }
.card-name {
    color: #e0e0e0;
    font-weight: 700;
    font-size: 0.85em;
    margin-bottom: 3px;
    line-height: 1.2;
}
.boss-card .card-name { color: #ffdb58; font-size: 0.9em; }
.card-title {
    color: #888;
    font-size: 0.7em;
    line-height: 1.3;
    margin-bottom: 6px;
    min-height: 2.6em;
}
.card-appointer {
    color: #666;
    font-size: 0.65em;
}
.card-senior {
    color: #b8860b;
    font-size: 0.65em;
    font-style: italic;
}

/* Circuit / state sections */
.circuit-section, .state-section {
    margin-bottom: 28px;
    scroll-margin-top: 80px;
}
.circuit-header, .state-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid #333;
    cursor: pointer;
}
.circuit-header h4, .state-header h4 {
    color: #d4af37;
    font-size: 1.1em;
    margin: 0;
}
.circuit-states-list {
    color: #888;
    font-size: 0.8em;
}
.group-count {
    color: #888;
    font-size: 0.8em;
    margin-left: auto;
}
.group-toggle {
    color: #555;
    font-size: 0.8em;
    transition: transform 0.2s;
}
.circuit-section.open .group-toggle,
.state-section.open .group-toggle { transform: rotate(90deg); }
.circuit-section .cards-grid,
.state-section .cards-grid { display: none; }
.circuit-section.open .cards-grid,
.state-section.open .cards-grid { display: flex; }

/* Picker */
.picker-row {
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.picker-row select {
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    border: 1px solid #444;
    background: #1a1a2e;
    color: #e0e0e0;
    font-size: 0.85rem;
}

/* Responsive */
@media (max-width: 768px) {
    .judge-card { width: calc(33.33% - 12px); min-width: 110px; }
    .judge-card.boss-card { width: calc(33.33% - 12px); min-width: 110px; }
    .card-photo, .card-photo-placeholder { width: 80px; height: 80px; }
    .scotus-section .judge-card { width: calc(33.33% - 14px); min-width: 120px; }
    .scotus-section .judge-card.boss-card { width: calc(33.33% - 14px); min-width: 120px; }
    .scotus-section .card-photo,
    .scotus-section .card-photo-placeholder { width: 100px; height: 100px; }
}
@media (max-width: 480px) {
    .judge-card { width: calc(50% - 10px); }
    .judge-card.boss-card { width: calc(50% - 10px); }
    .scotus-section .judge-card,
    .scotus-section .judge-card.boss-card { width: calc(50% - 10px); }
    .judicial-stats { gap: 1rem; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';

// ── Card renderer ──
function renderJudgeCard($j, $isBoss = false) {
    $oid = $j['official_id'];
    $name = htmlspecialchars($j['full_name']);
    $title = htmlspecialchars($j['title']);
    $photo = $j['photo_url'] ?? '';
    $appointer = $j['appointer_name'] ? htmlspecialchars($j['appointer_name']) : '';
    $isSenior = (int)($j['senior_status'] ?? 0);

    $classes = 'judge-card';
    if ($isBoss) $classes .= ' boss-card';
    if ($isSenior) $classes .= ' senior-card';

    echo "<a class=\"$classes\" href=\"/usa/judge.php?id=$oid\">";

    if ($photo) {
        echo "<img class=\"card-photo\" src=\"" . htmlspecialchars($photo) . "\" alt=\"$name\" loading=\"lazy\">";
    } else {
        echo "<div class=\"card-photo-placeholder\">&#9878;</div>";
    }

    echo "<div class=\"card-name\">$name</div>";
    echo "<div class=\"card-title\">$title</div>";

    if ($appointer) {
        echo "<div class=\"card-appointer\">Appointed by $appointer</div>";
    }
    if ($isSenior) {
        echo "<div class=\"card-senior\">Senior Status</div>";
    }

    echo "</a>\n";
}
?>

<div class="judicial-overview">
    <h2>Judicial Branch</h2>
    <p class="judicial-subtitle">Federal judges across three tiers of the court system</p>

    <?php if ($scotusCount > 0): ?>
    <!-- ═══ SUPREME COURT ═══ -->
    <div class="court-section scotus-section">
        <h3>Supreme Court of the United States</h3>
        <div class="cards-grid">
            <?php foreach ($scotus as $j): ?>
                <?php renderJudgeCard($j, (int)($j['chief_judge'] ?? 0)); ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="judicial-stats">
        <div class="judicial-stat">
            <div class="num"><?= $scotusCount ?></div>
            <div class="label">Supreme Court</div>
        </div>
        <div class="judicial-stat">
            <div class="num"><?= $circuitCount ?></div>
            <div class="label">Circuit Court</div>
        </div>
        <div class="judicial-stat">
            <div class="num"><?= $districtCount ?></div>
            <div class="label">District Court</div>
        </div>
        <div class="judicial-stat">
            <div class="num"><?= $scotusCount + $circuitCount + $districtCount ?></div>
            <div class="label">Total Federal Judges</div>
        </div>
    </div>

    <?php if ($circuitCount > 0): ?>
    <!-- ═══ CIRCUIT COURTS ═══ -->
    <div class="court-section">
        <h3>U.S. Circuit Courts of Appeals</h3>

        <div class="picker-row">
            <select id="circuitPicker">
                <option value="">Jump to circuit...</option>
                <?php foreach ($circuitOrder as $cid): ?>
                    <?php if (isset($circuitGroups[$cid])): ?>
                    <option value="<?= $cid ?>"><?= htmlspecialchars($circuitNameMap[$cid] ?? $cid) ?> (<?= count($circuitGroups[$cid]) ?>)</option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach ($circuitOrder as $cid):
            if (!isset($circuitGroups[$cid])) continue;
            $judges = $circuitGroups[$cid];
            $cName = $circuitNameMap[$cid] ?? $cid;
            $cStates = $circuitStateMap[$cid] ?? [];
            $stateLabels = array_map(fn($sc) => $sc, $cStates);
        ?>
        <div class="circuit-section" id="circuit-<?= $cid ?>">
            <div class="circuit-header" onclick="this.parentElement.classList.toggle('open')">
                <h4><?= htmlspecialchars($cName) ?></h4>
                <span class="circuit-states-list"><?= implode(', ', $stateLabels) ?></span>
                <span class="group-count"><?= count($judges) ?> judge<?= count($judges) !== 1 ? 's' : '' ?></span>
                <span class="group-toggle">&#9654;</span>
            </div>
            <div class="cards-grid">
                <?php foreach ($judges as $j): ?>
                    <?php renderJudgeCard($j, (int)($j['chief_judge'] ?? 0)); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($districtCount > 0): ?>
    <!-- ═══ DISTRICT COURTS ═══ -->
    <div class="court-section">
        <h3>U.S. District Courts</h3>

        <div class="picker-row">
            <select id="districtPicker">
                <option value="">Jump to state...</option>
                <?php foreach ($districtByState as $sc => $judges): ?>
                    <option value="<?= $sc ?>"><?= htmlspecialchars($stateNames[$sc] ?? $sc) ?> (<?= count($judges) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach ($districtByState as $sc => $judges):
            $sName = $stateNames[$sc] ?? $sc;
        ?>
        <div class="state-section" id="district-<?= $sc ?>">
            <div class="state-header" onclick="this.parentElement.classList.toggle('open')">
                <h4><?= htmlspecialchars($sName) ?></h4>
                <span class="group-count"><?= count($judges) ?> judge<?= count($judges) !== 1 ? 's' : '' ?></span>
                <span class="group-toggle">&#9654;</span>
            </div>
            <div class="cards-grid">
                <?php foreach ($judges as $j): ?>
                    <?php renderJudgeCard($j, (int)($j['chief_judge'] ?? 0)); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($scotusCount + $circuitCount + $districtCount === 0): ?>
    <div style="background:#1a1a2e;border:1px solid #333;border-radius:10px;padding:48px 32px;text-align:center;margin-top:24px;">
        <h3 style="color:#d4af37;margin-bottom:8px;">Data Loading</h3>
        <p style="color:#888;font-size:0.9em;">Federal judge data has not been loaded yet. Run the loader script to populate this page.</p>
    </div>
    <?php endif; ?>
</div>

<script>
(function() {
    var cp = document.getElementById('circuitPicker');
    var dp = document.getElementById('districtPicker');
    function jumpTo(id) {
        var el = document.getElementById(id);
        if (el) {
            if (!el.classList.contains('open')) el.classList.add('open');
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
    if (cp) cp.addEventListener('change', function() { if (this.value) { jumpTo('circuit-' + this.value); this.value = ''; } });
    if (dp) dp.addEventListener('change', function() { if (this.value) { jumpTo('district-' + this.value); this.value = ''; } });
})();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
