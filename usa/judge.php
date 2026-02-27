<?php
/**
 * Judge Detail Page — Summary dashboard for a single federal judge
 * Usage: /usa/judge.php?id=328  (by official_id)
 *
 * Similar to rep.php but for judicial appointments.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
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

$judgeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$judgeId) { header('Location: /usa/judicial.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM elected_officials WHERE official_id = ? AND is_current = 1 LIMIT 1");
$stmt->execute([$judgeId]);
$judge = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$judge || empty($judge['court_type'])) {
    header('Location: /usa/judicial.php');
    exit;
}

// State name
$stateName = '';
if ($judge['state_code']) {
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
    $stmt->execute([$judge['state_code']]);
    $stateName = $stmt->fetchColumn() ?: $judge['state_code'];
}

// Circuit states (if circuit judge)
$circuitStates = [];
$circuitName = '';
if ($judge['court_id']) {
    $stmt = $pdo->prepare("SELECT cs.circuit_name, cs.state_code, s.state_name FROM circuit_states cs LEFT JOIN states s ON cs.state_code = s.abbreviation WHERE cs.circuit_id = ? ORDER BY s.state_name");
    $stmt->execute([$judge['court_id']]);
    $circuitStates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($circuitStates) {
        $circuitName = $circuitStates[0]['circuit_name'];
    }
}

// Colleagues on same court
$stmt = $pdo->prepare("
    SELECT official_id, full_name, chief_judge, senior_status, photo_url, title
    FROM elected_officials
    WHERE court_id = ? AND is_current = 1 AND official_id != ?
    ORDER BY chief_judge DESC, senior_status ASC, full_name
    LIMIT 30
");
$stmt->execute([$judge['court_id'], $judgeId]);
$colleagues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Threats for this judge
require_once dirname(__DIR__) . '/includes/severity.php';

$stmt = $pdo->prepare("SELECT * FROM executive_threats WHERE official_id = ? AND is_active = 1 ORDER BY threat_date DESC");
$stmt->execute([$judgeId]);
$judgeThreats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Threat tags
$allTags = $pdo->query("SELECT * FROM threat_tags WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tagsById = [];
foreach ($allTags as $tag) $tagsById[$tag['tag_id']] = $tag;
$threatTags = [];
if ($judgeThreats) {
    $tids = array_column($judgeThreats, 'threat_id');
    $ph = implode(',', array_fill(0, count($tids), '?'));
    $stmt = $pdo->prepare("SELECT tm.threat_id, tm.tag_id FROM threat_tag_map tm JOIN threat_tags t ON tm.tag_id = t.tag_id WHERE t.is_active = 1 AND tm.threat_id IN ($ph)");
    $stmt->execute($tids);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $threatTags[$row['threat_id']][] = $tagsById[$row['tag_id']] ?? null;
    }
    $threatTags = array_map(fn($arr) => array_filter($arr), $threatTags);
}

// Severity stats
$scores = array_filter(array_map(fn($t) => $t['severity_score'], $judgeThreats), fn($s) => $s !== null);
$scores = array_map('intval', $scores);
$avgSev = $scores ? round(array_sum($scores) / count($scores)) : 0;
$maxSev = $scores ? max($scores) : 0;

// Years serving
$yearsServing = '';
if ($judge['term_start']) {
    $start = new DateTime($judge['term_start']);
    $now = new DateTime();
    $diff = $start->diff($now);
    $yearsServing = $diff->y;
}

// Confirmation vote string
$voteStr = '';
if ($judge['votes_yes'] !== null && $judge['votes_no'] !== null) {
    $voteStr = $judge['votes_yes'] . '–' . $judge['votes_no'];
}

$pageTitle = htmlspecialchars($judge['full_name']) . ' — The People\'s Branch';

$pageStyles = <<<'CSS'
.judge-detail {
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
.judge-identity {
    display: flex;
    gap: 24px;
    align-items: flex-start;
    margin-bottom: 30px;
    padding: 24px;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
}
.judge-identity .photo {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 8px;
    background: #2a2a2a;
    flex-shrink: 0;
}
.judge-identity .photo-placeholder {
    width: 150px;
    height: 150px;
    border-radius: 8px;
    background: #2a2a2a;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #555;
    font-size: 3em;
}
.judge-identity .info { flex: 1; }
.judge-identity .info h1 {
    color: #e0e0e0;
    font-size: 1.5em;
    margin: 0 0 6px;
}
.appointment-tag {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 12px;
    font-size: 0.8em;
    font-weight: 700;
    color: #fff;
    background: #1a5f2a;
    margin-bottom: 10px;
}
.appointment-tag.senior { background: #8b6914; }
.judge-meta {
    color: #999;
    font-size: 0.9em;
    line-height: 1.8;
}
.judge-meta a { color: #d4af37; text-decoration: none; }
.judge-meta a:hover { text-decoration: underline; }

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
    font-size: 1.3em;
    font-weight: 700;
    color: #d4af37;
}
.stat-card .label {
    font-size: 0.75em;
    color: #888;
    margin-top: 4px;
}

/* Court info */
.court-info-row {
    display: flex;
    gap: 12px;
    margin-bottom: 8px;
    font-size: 0.9em;
}
.court-info-label { color: #888; min-width: 100px; }
.court-info-value { color: #ccc; }
.court-info-value a { color: #d4af37; text-decoration: none; }
.court-info-value a:hover { text-decoration: underline; }
.state-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.state-chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    background: #0d0d1a;
    color: #ccc;
    font-size: 0.8em;
}

/* Colleague chips */
.colleague-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.colleague-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: #0d0d1a;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.82em;
    color: #ccc;
    transition: background 0.2s;
    border: 1px solid transparent;
}
.colleague-chip:hover {
    background: #1a1a2e;
    color: #d4af37;
    border-color: #333;
}
.colleague-chip img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    background: #2a2a2a;
}
.colleague-chip .chip-placeholder {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #2a2a2a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7em;
    color: #555;
}
.colleague-chip .chip-chief { color: #d4af37; font-weight: 700; }
.colleague-chip .chip-senior { color: #8b6914; font-style: italic; font-size: 0.9em; }

/* Threat items */
.threat-item { padding: 16px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
.threat-item:last-child { border-bottom: none; }
.threat-date { font-size: 0.8rem; color: #aaa; margin-bottom: 4px; }
.threat-title-row { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
.threat-title { font-size: 0.95rem; color: #e8eaf0; font-weight: 500; flex: 1; min-width: 200px; }
.severity-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; min-width: 32px; text-align: center; flex-shrink: 0; }
.threat-type { font-size: 0.7rem; padding: 2px 8px; border-radius: 3px; text-transform: uppercase; font-weight: 600; flex-shrink: 0; }
.threat-type.strategic { background: #cc0000; color: #fff; }
.threat-type.tactical { background: #ff9500; color: #000; }
.tag-pills { display: flex; gap: 4px; flex-wrap: wrap; margin: 6px 0; }
.tag-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; border: 1px solid transparent; }
.threat-desc { color: #d0d0d0; font-size: 0.9rem; line-height: 1.6; margin-bottom: 10px; }
.threat-source a { color: #d4af37; text-decoration: none; font-size: 0.85rem; }
.threat-source a:hover { text-decoration: underline; }
.call-script { background: #0d0d1a; border: 1px solid #333; border-radius: 6px; padding: 12px; margin: 10px 0; }
.call-script-label { font-size: 0.8rem; color: #d4af37; font-weight: 600; margin-bottom: 6px; }
.call-script-text { color: #e0e0e0; font-size: 0.9rem; line-height: 1.5; font-style: italic; }
.severity-summary { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; font-size: 0.85rem; color: #bbb; margin-bottom: 16px; }
.severity-summary strong { font-family: monospace; }
.severity-bar-mini { width: 80px; height: 6px; background: #252d44; border-radius: 3px; overflow: hidden; display: inline-block; vertical-align: middle; }
.severity-bar-fill { height: 100%; border-radius: 3px; }

/* Responsive */
@media (max-width: 600px) {
    .judge-identity { flex-direction: column; align-items: center; text-align: center; }
    .judge-identity .photo, .judge-identity .photo-placeholder { width: 120px; height: 120px; }
    .stats-grid { flex-direction: column; }
    .threat-title-row { flex-direction: column; align-items: flex-start; gap: 4px; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="judge-detail">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/usa/judicial.php">Judicial Branch</a>
        <?php if ($judge['court_type'] === 'circuit' && $circuitName): ?>
            &rsaquo; <a href="/usa/judicial.php#circuit-<?= $judge['court_id'] ?>"><?= htmlspecialchars($circuitName) ?></a>
        <?php elseif ($judge['court_type'] === 'district' && $judge['state_code']): ?>
            &rsaquo; <a href="/usa/judicial.php#district-<?= $judge['state_code'] ?>"><?= htmlspecialchars($stateName) ?></a>
        <?php endif; ?>
        &rsaquo; <?= htmlspecialchars($judge['full_name']) ?>
    </div>

    <!-- Identity Card -->
    <div class="judge-identity">
        <?php if ($judge['photo_url']): ?>
            <img class="photo" src="<?= htmlspecialchars($judge['photo_url']) ?>" alt="<?= htmlspecialchars($judge['full_name']) ?>">
        <?php else: ?>
            <div class="photo-placeholder">&#9878;</div>
        <?php endif; ?>
        <div class="info">
            <h1><?= htmlspecialchars($judge['full_name']) ?></h1>
            <?php if ((int)($judge['senior_status'] ?? 0)): ?>
                <span class="appointment-tag senior">Senior Status</span>
            <?php else: ?>
                <span class="appointment-tag">Presidential Appointment</span>
            <?php endif; ?>
            <div class="judge-meta">
                <?= htmlspecialchars($judge['court_name'] ?: $judge['office_name']) ?><br>
                <?= htmlspecialchars($judge['title']) ?>
                <?php if ($judge['appointer_name']): ?>
                    <br>Appointed by <?= htmlspecialchars($judge['appointer_name']) ?>
                <?php endif; ?>
                <?php if ($judge['cl_slug']): ?>
                    <br><a href="https://www.courtlistener.com/person/<?= htmlspecialchars($judge['cl_slug']) ?>/" target="_blank" rel="noopener">View on CourtListener &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Appointment Details -->
    <div class="section-box">
        <h2>Appointment Details</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="num"><?= $judge['date_nominated'] ? date('M j, Y', strtotime($judge['date_nominated'])) : '—' ?></div>
                <div class="label">Nominated</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $judge['date_confirmed'] ? date('M j, Y', strtotime($judge['date_confirmed'])) : '—' ?></div>
                <div class="label">Confirmed</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $voteStr ?: '—' ?></div>
                <div class="label">Senate Vote</div>
            </div>
            <div class="stat-card">
                <div class="num"><?= $yearsServing !== '' ? $yearsServing : '—' ?></div>
                <div class="label">Years Serving</div>
            </div>
        </div>
    </div>

    <!-- Court Information -->
    <div class="section-box">
        <h2>Court Information</h2>
        <div class="court-info-row">
            <span class="court-info-label">Court</span>
            <span class="court-info-value"><?= htmlspecialchars($judge['court_name'] ?: $judge['office_name']) ?></span>
        </div>
        <div class="court-info-row">
            <span class="court-info-label">Level</span>
            <span class="court-info-value"><?= ucfirst($judge['court_type'] ?? 'Unknown') ?></span>
        </div>
        <?php if ($judge['court_type'] === 'circuit' && $circuitStates): ?>
        <div class="court-info-row">
            <span class="court-info-label">Jurisdiction</span>
            <span class="court-info-value">
                <div class="state-chips">
                    <?php foreach ($circuitStates as $cs): ?>
                        <span class="state-chip"><?= htmlspecialchars($cs['state_name'] ?: $cs['state_code']) ?></span>
                    <?php endforeach; ?>
                </div>
            </span>
        </div>
        <?php elseif ($judge['court_type'] === 'district' && $stateName): ?>
        <div class="court-info-row">
            <span class="court-info-label">State</span>
            <span class="court-info-value"><?= htmlspecialchars($stateName) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($judge['term_start']): ?>
        <div class="court-info-row">
            <span class="court-info-label">Serving Since</span>
            <span class="court-info-value"><?= date('F j, Y', strtotime($judge['term_start'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Accountability Record -->
    <?php if ($judgeThreats): ?>
    <div class="section-box">
        <h2>Accountability Record</h2>
        <div class="stats-grid" style="margin-bottom:16px">
            <div class="stat-card">
                <div class="num" style="color:#dc2626"><?= count($judgeThreats) ?></div>
                <div class="label">Active Threat<?= count($judgeThreats) !== 1 ? 's' : '' ?></div>
            </div>
            <?php $avgZone = getSeverityZone($avgSev); $barPct = min(100, $avgSev / 10); ?>
            <div class="stat-card">
                <div class="num" style="color:<?= $avgZone['color'] ?>"><?= $avgSev ?></div>
                <div class="label">Avg Severity (<?= $avgZone['label'] ?>)</div>
            </div>
            <?php $maxZone = getSeverityZone($maxSev); ?>
            <div class="stat-card">
                <div class="num" style="color:<?= $maxZone['color'] ?>"><?= $maxSev ?></div>
                <div class="label">Max Severity (<?= $maxZone['label'] ?>)</div>
            </div>
        </div>

        <?php foreach ($judgeThreats as $t):
            $tid = $t['threat_id'];
            $zone = getSeverityZone($t['severity_score']);
        ?>
        <div class="threat-item">
            <div class="threat-date"><?= date('F j, Y', strtotime($t['threat_date'])) ?></div>
            <div class="threat-title-row">
                <span class="severity-badge" style="background:<?= $zone['color'] ?>;color:<?= ($t['severity_score'] ?? 0) > 500 ? '#fff' : '#000' ?>">
                    <?= (int)$t['severity_score'] ?>
                </span>
                <span class="threat-title"><?= htmlspecialchars($t['title']) ?></span>
                <span class="threat-type <?= $t['threat_type'] ?>"><?= $t['threat_type'] ?></span>
            </div>
            <?php if (!empty($threatTags[$tid])): ?>
            <div class="tag-pills">
                <?php foreach ($threatTags[$tid] as $tg): ?>
                <span class="tag-pill" style="background:<?= $tg['color'] ?>22;color:<?= $tg['color'] ?>;border-color:<?= $tg['color'] ?>44"><?= htmlspecialchars($tg['tag_label']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($t['target']): ?>
            <div style="font-size:0.8rem;color:#d4af37;margin-bottom:6px;">Target: <?= htmlspecialchars($t['target']) ?></div>
            <?php endif; ?>
            <div class="threat-desc"><?= nl2br(htmlspecialchars($t['description'])) ?></div>
            <?php if ($t['source_url']): ?>
            <div class="threat-source"><a href="<?= htmlspecialchars($t['source_url']) ?>" target="_blank">&#128240; Source</a></div>
            <?php endif; ?>
            <?php if ($t['action_script']): ?>
            <div class="call-script">
                <div class="call-script-label">&#128222; What You Can Do</div>
                <div class="call-script-text"><?= htmlspecialchars($t['action_script']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Colleagues on this Court -->
    <?php if ($colleagues): ?>
    <div class="section-box">
        <h2>Other Judges on this Court (<?= count($colleagues) ?>)</h2>
        <div class="colleague-grid">
            <?php foreach ($colleagues as $col): ?>
            <a class="colleague-chip" href="/usa/judge.php?id=<?= $col['official_id'] ?>">
                <?php if ($col['photo_url']): ?>
                    <img src="<?= htmlspecialchars($col['photo_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <span class="chip-placeholder">&#9878;</span>
                <?php endif; ?>
                <span>
                    <?= htmlspecialchars($col['full_name']) ?>
                    <?php if ((int)($col['chief_judge'] ?? 0)): ?>
                        <span class="chip-chief">(Chief)</span>
                    <?php elseif ((int)($col['senior_status'] ?? 0)): ?>
                        <span class="chip-senior">(Senior)</span>
                    <?php endif; ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
