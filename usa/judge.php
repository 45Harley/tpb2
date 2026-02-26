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

/* Responsive */
@media (max-width: 600px) {
    .judge-identity { flex-direction: column; align-items: center; text-align: center; }
    .judge-identity .photo, .judge-identity .photo-placeholder { width: 120px; height: 120px; }
    .stats-grid { flex-direction: column; }
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
