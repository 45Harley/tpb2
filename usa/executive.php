<?php
/**
 * Executive Branch — The People's Branch
 * Two views: Civics (neutral) and Accountability (mob framing)
 * People cards with linked threats, contact info, and civic actions.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Executive Branch — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

// ============================================================
// Data queries
// ============================================================

// President
$president = $pdo->query("
    SELECT * FROM elected_officials WHERE title = 'President' AND is_current = 1 LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// VP + Cabinet (reports_to president)
$presId = $president ? $president['official_id'] : 326;
$stmt = $pdo->prepare("
    SELECT * FROM elected_officials
    WHERE reports_to = ? AND is_current = 1
    ORDER BY FIELD(title, 'Vice President') DESC, title ASC
");
$stmt->execute([$presId]);
$cabinet = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All executive official IDs (president + cabinet)
$allIds = [$presId];
foreach ($cabinet as $m) $allIds[] = $m['official_id'];

// All tags
$allTags = $pdo->query("SELECT * FROM threat_tags WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tagsById = [];
foreach ($allTags as $tag) $tagsById[$tag['tag_id']] = $tag;

// Tag assignments per threat
$threatTags = [];
$r = $pdo->query("SELECT tm.threat_id, tm.tag_id FROM threat_tag_map tm JOIN threat_tags t ON tm.tag_id = t.tag_id WHERE t.is_active = 1");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $threatTags[$row['threat_id']][] = $tagsById[$row['tag_id']];
}

// Threats per official
$in = implode(',', array_map('intval', $allIds));
$threats = $pdo->query("
    SELECT et.*, eo.full_name as official_name
    FROM executive_threats et
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    WHERE et.is_active = 1
    ORDER BY et.threat_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Group threats by official + compute severity stats
$threatsByOfficial = [];
$totalThreats = 0;
$officialSeverity = []; // oid => [scores]
foreach ($threats as $t) {
    $oid = $t['official_id'] ?: 0;
    $threatsByOfficial[$oid][] = $t;
    $totalThreats++;
    if ($t['severity_score'] !== null) {
        $officialSeverity[$oid][] = (int)$t['severity_score'];
    }
}

// Severity zone helper
function getSeverityZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#555', 'class' => 'unscored'];
    if ($score === 0) return ['label' => 'Clean', 'color' => '#4caf50', 'class' => 'clean'];
    if ($score <= 10) return ['label' => 'Questionable', 'color' => '#8bc34a', 'class' => 'questionable'];
    if ($score <= 30) return ['label' => 'Misconduct', 'color' => '#cddc39', 'class' => 'misconduct'];
    if ($score <= 70) return ['label' => 'Misdemeanor', 'color' => '#ffeb3b', 'class' => 'misdemeanor'];
    if ($score <= 150) return ['label' => 'Felony', 'color' => '#ff9800', 'class' => 'felony'];
    if ($score <= 300) return ['label' => 'Serious Felony', 'color' => '#ff5722', 'class' => 'serious-felony'];
    if ($score <= 500) return ['label' => 'High Crime', 'color' => '#f44336', 'class' => 'high-crime'];
    if ($score <= 700) return ['label' => 'Atrocity', 'color' => '#d32f2f', 'class' => 'atrocity'];
    if ($score <= 900) return ['label' => 'Crime Against Humanity', 'color' => '#b71c1c', 'class' => 'crime-humanity'];
    return ['label' => 'Genocide', 'color' => '#000', 'class' => 'genocide'];
}

// Threat response counts
$responseCounts = [];
$r = $pdo->query("SELECT threat_id, action_type, COUNT(*) as cnt FROM threat_responses GROUP BY threat_id, action_type");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $responseCounts[$row['threat_id']][$row['action_type']] = (int)$row['cnt'];
}

// Average ratings
$avgRatings = [];
$r = $pdo->query("SELECT threat_id, AVG(rating) as avg_rating, COUNT(*) as rating_count FROM threat_ratings GROUP BY threat_id");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $avgRatings[$row['threat_id']] = ['avg' => round($row['avg_rating'], 1), 'count' => (int)$row['rating_count']];
}

// User's own responses and ratings (if logged in)
$userResponses = [];
$userRatings = [];
if ($dbUser) {
    $stmt = $pdo->prepare("SELECT threat_id, action_type FROM threat_responses WHERE user_id = ?");
    $stmt->execute([$dbUser['user_id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userResponses[$row['threat_id']][] = $row['action_type'];
    }
    $stmt = $pdo->prepare("SELECT threat_id, rating FROM threat_ratings WHERE user_id = ?");
    $stmt->execute([$dbUser['user_id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $userRatings[$row['threat_id']] = $row['rating'];
    }
}

// User's reps (for action dropdown)
$userReps = [];
if ($dbUser && !empty($dbUser['state_abbrev'])) {
    $stmt = $pdo->prepare("SELECT official_id, full_name, title FROM elected_officials WHERE is_current = 1 AND title IN ('U.S. Senator') AND state_code = ?");
    $stmt->execute([$dbUser['state_abbrev']]);
    $userReps = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageStyles = <<<'CSS'
.exec-page { max-width: 1000px; margin: 0 auto; padding: 40px 24px; }
.exec-page h1 { font-size: 28px; margin-bottom: 8px; }
.exec-subtitle { color: #8892a8; font-size: 15px; margin-bottom: 24px; }

/* View toggle */
.view-toggle { display: flex; gap: 0; margin-bottom: 28px; }
.view-btn { padding: 10px 24px; background: #141929; border: 1px solid #252d44; color: #8892a8; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; }
.view-btn:first-child { border-radius: 6px 0 0 6px; }
.view-btn:last-child { border-radius: 0 6px 6px 0; }
.view-btn.active { background: #d4af37; color: #000; border-color: #d4af37; }
.view-btn.active-mob { background: #cc0000; color: #fff; border-color: #cc0000; }

/* Stats bar */
.stats-bar { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.stat-box { background: #141929; border: 1px solid #252d44; padding: 16px 20px; border-radius: 8px; text-align: center; flex: 1; min-width: 100px; }
.stat-num { font-size: 2rem; font-weight: bold; }
.stat-num.gold { color: #d4af37; }
.stat-num.red { color: #ff4444; }
.stat-label { font-size: 0.8rem; color: #6b7394; margin-top: 4px; }

/* People cards */
.people-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
.person-card { background: #141929; border: 1px solid #252d44; border-radius: 10px; overflow: hidden; transition: border-color 0.2s; }
.person-card:hover { border-color: #3a4468; }
.person-card.boss { border-color: #d4af37; border-width: 2px; }
.person-card.mob-boss { border-color: #cc0000; border-width: 2px; box-shadow: 0 0 20px rgba(255,68,68,0.15); }

.person-header { padding: 20px 24px; display: flex; gap: 20px; align-items: flex-start; }
.person-photo { width: 64px; height: 64px; border-radius: 50%; background: #252d44; flex-shrink: 0; object-fit: cover; }
.person-info { flex: 1; min-width: 0; }
.person-name { font-size: 1.2rem; color: #f0f2f8; font-weight: 600; margin-bottom: 4px; }
.person-title { color: #d4af37; font-size: 0.9rem; margin-bottom: 4px; }
.person-dept { color: #6b7394; font-size: 0.85rem; }
.person-party { display: inline-block; font-size: 0.75rem; padding: 2px 8px; border-radius: 10px; font-weight: 600; margin-top: 6px; }
.party-r { background: rgba(239,68,68,0.15); color: #ef4444; }
.party-d { background: rgba(59,130,246,0.15); color: #60a5fa; }
.party-i { background: rgba(168,85,247,0.15); color: #a855f7; }

/* Mob-specific labels */
.mob-label { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 0.8rem; font-weight: 700; margin-top: 6px; }
.mob-label.boss { background: #cc0000; color: #fff; }
.mob-label.mobster { background: rgba(255,68,68,0.15); color: #ff6b6b; }

.person-contact { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
.person-contact a { color: #8892a8; font-size: 0.8rem; text-decoration: none; }
.person-contact a:hover { color: #d4af37; }

.person-stats { display: flex; gap: 16px; padding: 0 24px 12px; }
.person-stat { font-size: 0.8rem; color: #6b7394; }
.person-stat strong { color: #e8eaf0; }

/* Threats section on card */
.threat-toggle { padding: 12px 24px; background: #0d1220; border-top: 1px solid #252d44; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; color: #8892a8; }
.threat-toggle:hover { background: #111728; }
.threat-count-badge { background: #cc0000; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
.threat-count-badge.gold { background: #d4af37; color: #000; }
.expand-arrow { transition: transform 0.2s; }
.expand-arrow.open { transform: rotate(180deg); }

.threats-list { display: none; border-top: 1px solid #252d44; }
.threats-list.open { display: block; }

.threat-item { padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,0.04); }
.threat-item:last-child { border-bottom: none; }
.threat-date { font-size: 0.8rem; color: #6b7394; margin-bottom: 4px; }
.threat-title-row { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; }
.threat-title { font-size: 1rem; color: #e8eaf0; font-weight: 500; }
.threat-type { font-size: 0.7rem; padding: 2px 8px; border-radius: 3px; text-transform: uppercase; font-weight: 600; }
.threat-type.strategic { background: #cc0000; color: #fff; }
.threat-type.tactical { background: #ff9500; color: #000; }
.threat-desc { color: #a0aec0; font-size: 0.9rem; line-height: 1.6; margin-bottom: 10px; }
.threat-source a { color: #7ab8e0; text-decoration: none; font-size: 0.85rem; }
.threat-source a:hover { text-decoration: underline; }

.call-script { background: #0d1220; border: 1px solid #252d44; border-radius: 6px; padding: 12px; margin: 10px 0; }
.call-script-label { font-size: 0.8rem; color: #7ab8e0; font-weight: 600; margin-bottom: 6px; }
.call-script-text { color: #e0e0e0; font-size: 0.9rem; line-height: 1.5; font-style: italic; }
.copy-btn { margin-top: 8px; padding: 6px 14px; background: #252d44; border: 1px solid #3a4468; color: #e0e0e0; border-radius: 4px; cursor: pointer; font-size: 0.8rem; }
.copy-btn:hover { background: #3a4468; }
.copy-btn.copied { background: #2a5a2a; border-color: #3a7a3a; color: #88c088; }

.action-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; align-items: center; }
.action-btn { padding: 8px 14px; border-radius: 5px; border: none; cursor: pointer; font-size: 0.85rem; font-weight: 500; }
.action-btn.call { background: #2a5a2a; color: #88c088; }
.action-btn.email { background: #2a4a5a; color: #7ab8e0; }
.action-btn.share { background: #5a4a2a; color: #e0c080; }
.action-btn:hover { filter: brightness(1.2); }
.action-btn.done { opacity: 0.6; }
.action-btn.done::after { content: ' \2713'; }
.action-counts { font-size: 0.8rem; color: #6b7394; margin-top: 8px; }
.action-counts span { margin-right: 12px; }

.rep-select { padding: 6px; background: #252d44; border: 1px solid #3a4468; color: #e0e0e0; border-radius: 4px; font-size: 0.85rem; }

/* Rating */
.rating-row { margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.04); }
.rating-label { font-size: 0.85rem; color: #8892a8; margin-bottom: 6px; }
.rating-bar { display: flex; align-items: center; gap: 8px; }
.rating-end { font-size: 0.7rem; color: #6b7394; font-weight: 600; width: 28px; text-align: center; }
.rating-track { flex: 1; height: 20px; background: linear-gradient(to right, #2a5a2a, #444, #8b0000); border-radius: 10px; position: relative; cursor: pointer; }
.rating-fill { position: absolute; top: 0; left: 0; height: 100%; background: rgba(255,255,255,0.12); border-radius: 10px 0 0 10px; pointer-events: none; }
.rating-marker { position: absolute; top: -2px; width: 10px; height: 24px; background: #fff; border-radius: 5px; transform: translateX(-50%); box-shadow: 0 2px 4px rgba(0,0,0,0.3); pointer-events: none; }
.rating-info { display: flex; justify-content: space-between; margin-top: 4px; font-size: 0.8rem; }
.your-rating { color: #7ab8e0; }
.avg-rating { color: #6b7394; }

/* Jan 6 panel (accountability view) */
.jan6-panel { background: linear-gradient(135deg, #1a1a2e 0%, #2a1a1a 100%); border: 2px solid #ff6b6b; border-radius: 10px; padding: 24px; margin-bottom: 24px; display: none; }
.jan6-panel.show { display: block; }
.jan6-title { color: #ff6b6b; font-size: 1.2rem; font-weight: 600; text-align: center; margin-bottom: 16px; }
.jan6-stats { display: flex; justify-content: center; gap: 32px; flex-wrap: wrap; }
.jan6-stat { text-align: center; }
.jan6-num { font-size: 2rem; color: #ff6b6b; font-weight: bold; }
.jan6-label { font-size: 0.8rem; color: #6b7394; }
.jan6-quote { font-style: italic; color: #8892a8; text-align: center; margin-top: 16px; font-size: 0.9rem; }
.jan6-quote cite { color: #ff6b6b; }

/* Severity badge */
.severity-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 3px; font-size: 0.75rem; font-weight: 700; font-family: monospace; white-space: nowrap; }
.severity-score { font-size: 0.8rem; }

/* Tag pills */
.tag-pills { display: flex; gap: 4px; flex-wrap: wrap; margin: 6px 0; }
.tag-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; border: 1px solid transparent; }
.tag-pill:hover { opacity: 0.8; }
.tag-pill.active-filter { outline: 2px solid #fff; outline-offset: 1px; }

/* Filter bar */
.filter-bar { background: #141929; border: 1px solid #252d44; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
.filter-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.filter-label { font-size: 0.8rem; color: #6b7394; white-space: nowrap; }
.filter-pills { display: flex; gap: 4px; flex-wrap: wrap; flex: 1; }
.filter-pill { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; cursor: pointer; border: 1px solid #3a4468; background: #1a2038; color: #8892a8; transition: all 0.2s; }
.filter-pill:hover { border-color: #5a6488; }
.filter-pill.active { border-color: currentColor; }
.sort-select { padding: 4px 8px; background: #1a2038; border: 1px solid #3a4468; color: #e0e0e0; border-radius: 4px; font-size: 0.8rem; }
.filter-count { font-size: 0.75rem; color: #6b7394; margin-left: 8px; }

/* Official severity stats */
.severity-summary { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.severity-avg { font-size: 0.8rem; color: #8892a8; }
.severity-avg strong { font-family: monospace; }
.severity-bar-mini { width: 80px; height: 6px; background: #252d44; border-radius: 3px; overflow: hidden; display: inline-block; vertical-align: middle; }
.severity-bar-fill { height: 100%; border-radius: 3px; }

.toast { position: fixed; bottom: 20px; right: 20px; background: #2a5a2a; color: #88c088; padding: 12px 24px; border-radius: 8px; display: none; z-index: 100; font-size: 0.9rem; }
.toast.show { display: block; }

@media (max-width: 600px) {
    .exec-page { padding: 24px 16px; }
    .person-header { flex-wrap: wrap; gap: 12px; }
    .stats-bar { gap: 8px; }
    .stat-box { min-width: 80px; padding: 12px; }
    .action-row { flex-direction: column; }
    .filter-row { flex-direction: column; align-items: flex-start; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="exec-page">
    <h1>Executive Branch</h1>
    <p class="exec-subtitle">The President, Cabinet, and Executive Accountability</p>

    <!-- View Toggle -->
    <div class="view-toggle">
        <button class="view-btn active" id="btn-civics" onclick="setView('civics')">Civics</button>
        <button class="view-btn" id="btn-accountability" onclick="setView('accountability')">Accountability</button>
    </div>

    <!-- Stats -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-num" id="stat-officials"><?= count($cabinet) + 1 ?></div>
            <div class="stat-label">Officials</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" id="stat-threats"><?= $totalThreats ?></div>
            <div class="stat-label">Active Threats</div>
        </div>
        <div class="stat-box">
            <div class="stat-num" id="stat-actions"><?= array_sum(array_map(fn($c) => array_sum($c), $responseCounts)) ?></div>
            <div class="stat-label">Actions Taken</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar" id="filter-bar">
        <div class="filter-row" style="margin-bottom:8px;">
            <span class="filter-label">Filter by tag:</span>
            <div class="filter-pills" id="tag-filters">
                <span class="filter-pill active" data-tag="all" onclick="filterByTag('all',this)" style="color:#d4af37;border-color:#d4af37">All</span>
                <?php foreach ($allTags as $tag): ?>
                <span class="filter-pill" data-tag="<?= $tag['tag_name'] ?>" onclick="filterByTag('<?= $tag['tag_name'] ?>',this)" style="color:<?= $tag['color'] ?>"><?= htmlspecialchars($tag['tag_label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter-row">
            <span class="filter-label">Sort:</span>
            <select class="sort-select" id="sort-select" onchange="sortThreats(this.value)">
                <option value="date">Date (newest)</option>
                <option value="severity">Severity (highest)</option>
                <option value="official">By official</option>
            </select>
            <span class="filter-count" id="filter-count"><?= $totalThreats ?> threats</span>
        </div>
    </div>

    <!-- Jan 6 Panel (accountability only) -->
    <div class="jan6-panel" id="jan6-panel">
        <div class="jan6-title">The Jan 6th Mob — Pardoned Insurrectionists</div>
        <div class="jan6-stats">
            <div class="jan6-stat"><div class="jan6-num">~1,500</div><div class="jan6-label">Pardoned</div></div>
            <div class="jan6-stat"><div class="jan6-num">608</div><div class="jan6-label">Assaulted Police</div></div>
            <div class="jan6-stat"><div class="jan6-num">33+</div><div class="jan6-label">Charged with New Crimes</div></div>
        </div>
        <p class="jan6-quote">"A private militia of proven street fighters."<br><cite>&mdash; Rep. Jamie Raskin</cite></p>
    </div>

    <!-- People Cards -->
    <div class="people-grid">
        <?php
        // President first, then cabinet
        $allOfficials = $president ? array_merge([$president], $cabinet) : $cabinet;
        foreach ($allOfficials as $idx => $official):
            $oid = $official['official_id'];
            $isPresident = ($official['title'] === 'President');
            $isVP = ($official['title'] === 'Vice President');
            $myThreats = $threatsByOfficial[$oid] ?? [];
            $threatCount = count($myThreats);
            $partyClass = strtolower(substr($official['party'] ?? '', 0, 1));
        ?>
        <div class="person-card <?= $isPresident ? 'boss' : '' ?>" data-oid="<?= $oid ?>" data-threats="<?= $threatCount ?>">
            <div class="person-header">
                <?php if (!empty($official['photo_url'])): ?>
                <img class="person-photo" src="<?= htmlspecialchars($official['photo_url']) ?>" alt="">
                <?php else: ?>
                <div class="person-photo"></div>
                <?php endif; ?>
                <div class="person-info">
                    <div class="person-name"><?= htmlspecialchars($official['full_name']) ?></div>
                    <div class="person-title civics-label"><?= htmlspecialchars($official['title']) ?></div>
                    <div class="person-title mob-label-title" style="display:none"><?= $isPresident ? 'The Boss' : ($isVP ? 'Underboss' : htmlspecialchars($official['title'])) ?></div>
                    <?php if ($official['office_name']): ?>
                    <div class="person-dept"><?= htmlspecialchars($official['office_name']) ?></div>
                    <?php endif; ?>
                    <span class="person-party party-<?= $partyClass ?>"><?= htmlspecialchars($official['party'] ?? '') ?></span>
                    <?php if ($isPresident): ?>
                    <span class="mob-label boss" style="display:none">THE BOSS</span>
                    <?php elseif (!$isVP): ?>
                    <span class="mob-label mobster" style="display:none">MOBSTER</span>
                    <?php endif; ?>

                    <div class="person-contact">
                        <?php if ($official['phone']): ?><a href="tel:<?= htmlspecialchars($official['phone']) ?>">&#128222; <?= htmlspecialchars($official['phone']) ?></a><?php endif; ?>
                        <?php if ($official['email']): ?><a href="mailto:<?= htmlspecialchars($official['email']) ?>">&#9993; Email</a><?php endif; ?>
                        <?php if ($official['website']): ?><a href="<?= htmlspecialchars($official['website']) ?>" target="_blank">&#127760; Website</a><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($threatCount > 0):
                $myScores = $officialSeverity[$oid] ?? [];
                $avgSev = $myScores ? round(array_sum($myScores) / count($myScores)) : 0;
                $maxSev = $myScores ? max($myScores) : 0;
                $avgZone = getSeverityZone($avgSev);
                $barPct = min(100, $avgSev / 10);
            ?>
            <div class="person-stats">
                <span class="person-stat"><strong><?= $threatCount ?></strong> threat<?= $threatCount !== 1 ? 's' : '' ?></span>
                <span class="person-stat severity-summary">
                    <span class="severity-avg">Avg: <strong style="color:<?= $avgZone['color'] ?>"><?= $avgSev ?></strong></span>
                    <span class="severity-bar-mini"><span class="severity-bar-fill" style="width:<?= $barPct ?>%;background:<?= $avgZone['color'] ?>"></span></span>
                    <span class="severity-avg">Max: <strong style="color:<?= getSeverityZone($maxSev)['color'] ?>"><?= $maxSev ?></strong></span>
                </span>
            </div>

            <div class="threat-toggle" onclick="toggleThreats(<?= $oid ?>)">
                <span>
                    <span class="threat-count-badge gold civics-badge"><?= $threatCount ?> accountability issue<?= $threatCount !== 1 ? 's' : '' ?></span>
                    <span class="threat-count-badge mob-badge" style="display:none"><?= $threatCount ?> threat<?= $threatCount !== 1 ? 's' : '' ?></span>
                </span>
                <span class="expand-arrow" id="arrow-<?= $oid ?>">&#9660;</span>
            </div>

            <div class="threats-list" id="threats-<?= $oid ?>">
                <?php foreach ($myThreats as $t):
                    $tid = $t['threat_id'];
                    $hasCalled = isset($userResponses[$tid]) && in_array('called', $userResponses[$tid]);
                    $hasEmailed = isset($userResponses[$tid]) && in_array('emailed', $userResponses[$tid]);
                    $hasShared = isset($userResponses[$tid]) && in_array('shared', $userResponses[$tid]);
                    $avgR = $avgRatings[$tid] ?? null;
                    $userR = $userRatings[$tid] ?? null;
                    $rc = $responseCounts[$tid] ?? [];
                ?>
                <div class="threat-item" id="threat-<?= $tid ?>" data-severity="<?= (int)$t['severity_score'] ?>" data-date="<?= $t['threat_date'] ?>" data-official="<?= $oid ?>" data-tags="<?= implode(',', array_map(fn($tg) => $tg['tag_name'], $threatTags[$tid] ?? [])) ?>">
                    <div class="threat-date"><?= date('F j, Y', strtotime($t['threat_date'])) ?></div>
                    <div class="threat-title-row">
                        <?php $zone = getSeverityZone($t['severity_score']); ?>
                        <span class="severity-badge" style="background:<?= $zone['color'] ?>;color:<?= ($t['severity_score'] ?? 0) > 500 ? '#fff' : '#000' ?>">
                            <span class="severity-score"><?= (int)$t['severity_score'] ?></span>
                        </span>
                        <span class="threat-title"><?= htmlspecialchars($t['title']) ?></span>
                        <span class="threat-type <?= $t['threat_type'] ?>"><?= $t['threat_type'] ?></span>
                    </div>
                    <?php if (!empty($threatTags[$tid])): ?>
                    <div class="tag-pills">
                        <?php foreach ($threatTags[$tid] as $tg): ?>
                        <span class="tag-pill" style="background:<?= $tg['color'] ?>22;color:<?= $tg['color'] ?>;border-color:<?= $tg['color'] ?>44" onclick="filterByTag('<?= $tg['tag_name'] ?>')"><?= htmlspecialchars($tg['tag_label']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($t['target']): ?>
                    <div style="font-size:0.8rem;color:#7ab8e0;margin-bottom:6px;">Target: <?= htmlspecialchars($t['target']) ?></div>
                    <?php endif; ?>
                    <div class="threat-desc"><?= nl2br(htmlspecialchars($t['description'])) ?></div>
                    <?php if ($t['source_url']): ?>
                    <div class="threat-source"><a href="<?= htmlspecialchars($t['source_url']) ?>" target="_blank">&#128240; Source</a></div>
                    <?php endif; ?>

                    <?php if ($t['action_script']): ?>
                    <div class="call-script">
                        <div class="call-script-label">&#128222; Call Script</div>
                        <div class="call-script-text" id="script-<?= $tid ?>"><?= htmlspecialchars($t['action_script']) ?></div>
                        <button class="copy-btn" onclick="copyScript(<?= $tid ?>, this)">Copy Script</button>
                    </div>
                    <?php endif; ?>

                    <div class="action-row">
                        <?php if (!empty($userReps)): ?>
                        <select class="rep-select" id="rep-<?= $tid ?>">
                            <option value="">Your Rep</option>
                            <?php foreach ($userReps as $rep): ?>
                            <option value="<?= $rep['official_id'] ?>"><?= htmlspecialchars($rep['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <button class="action-btn call <?= $hasCalled ? 'done' : '' ?>" onclick="logAction(<?= $tid ?>,'called',this)">&#128222; Called</button>
                        <button class="action-btn email <?= $hasEmailed ? 'done' : '' ?>" onclick="logAction(<?= $tid ?>,'emailed',this)">&#9993; Emailed</button>
                        <button class="action-btn share <?= $hasShared ? 'done' : '' ?>" onclick="logAction(<?= $tid ?>,'shared',this)">&#128226; Shared</button>
                    </div>
                    <?php if (!empty($rc)): ?>
                    <div class="action-counts">
                        <?php if (!empty($rc['called'])): ?><span>&#128222; <?= $rc['called'] ?> calls</span><?php endif; ?>
                        <?php if (!empty($rc['emailed'])): ?><span>&#9993; <?= $rc['emailed'] ?> emails</span><?php endif; ?>
                        <?php if (!empty($rc['shared'])): ?><span>&#128226; <?= $rc['shared'] ?> shares</span><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="rating-row">
                        <div class="rating-label">How dangerous is this?</div>
                        <div class="rating-bar" data-tid="<?= $tid ?>">
                            <span class="rating-end">-10</span>
                            <div class="rating-track" onclick="rateThreat(event, <?= $tid ?>)">
                                <div class="rating-fill" id="fill-<?= $tid ?>" style="width:<?= $avgR ? (($avgR['avg'] + 10) / 20 * 100) : 50 ?>%"></div>
                                <div class="rating-marker" id="marker-<?= $tid ?>" style="left:<?= $userR !== null ? (($userR + 10) / 20 * 100) : -100 ?>%"></div>
                            </div>
                            <span class="rating-end">+10</span>
                        </div>
                        <div class="rating-info">
                            <span class="your-rating" id="yr-<?= $tid ?>"><?= $userR !== null ? ('You: ' . ($userR > 0 ? '+' : '') . $userR) : 'Click to rate' ?></span>
                            <span class="avg-rating" id="ar-<?= $tid ?>"><?= $avgR ? ('Avg: ' . ($avgR['avg'] > 0 ? '+' : '') . $avgR['avg'] . ' (' . $avgR['count'] . ')') : 'No ratings' ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="toast" id="toast">Action logged!</div>

<script>
// View toggle
var currentView = localStorage.getItem('tpb_exec_view') || 'civics';
setView(currentView, true);

function setView(view, init) {
    currentView = view;
    localStorage.setItem('tpb_exec_view', view);
    var bc = document.getElementById('btn-civics');
    var ba = document.getElementById('btn-accountability');
    var jan6 = document.getElementById('jan6-panel');
    var statNum = document.getElementById('stat-threats');

    if (view === 'civics') {
        bc.className = 'view-btn active';
        ba.className = 'view-btn';
        jan6.classList.remove('show');
        statNum.className = 'stat-num gold';
    } else {
        bc.className = 'view-btn';
        ba.className = 'view-btn active-mob';
        jan6.classList.add('show');
        statNum.className = 'stat-num red';
    }

    // Toggle labels
    document.querySelectorAll('.civics-label').forEach(function(el) { el.style.display = view === 'civics' ? '' : 'none'; });
    document.querySelectorAll('.mob-label-title').forEach(function(el) { el.style.display = view === 'accountability' ? '' : 'none'; });
    document.querySelectorAll('.mob-label').forEach(function(el) { el.style.display = view === 'accountability' ? '' : 'none'; });
    document.querySelectorAll('.civics-badge').forEach(function(el) { el.style.display = view === 'civics' ? '' : 'none'; });
    document.querySelectorAll('.mob-badge').forEach(function(el) { el.style.display = view === 'accountability' ? '' : 'none'; });

    // Card styling
    document.querySelectorAll('.person-card.boss, .person-card.mob-boss').forEach(function(card) {
        card.classList.toggle('boss', view === 'civics');
        card.classList.toggle('mob-boss', view === 'accountability');
    });

    // Auto-expand threats in accountability view
    if (view === 'accountability' && !init) {
        document.querySelectorAll('.threats-list').forEach(function(el) { el.classList.add('open'); });
        document.querySelectorAll('.expand-arrow').forEach(function(el) { el.classList.add('open'); });
    }
}

function toggleThreats(oid) {
    var list = document.getElementById('threats-' + oid);
    var arrow = document.getElementById('arrow-' + oid);
    list.classList.toggle('open');
    arrow.classList.toggle('open');
}

function copyScript(tid, btn) {
    var text = document.getElementById('script-' + tid).textContent;
    navigator.clipboard.writeText(text).then(function() {
        btn.textContent = 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() { btn.textContent = 'Copy Script'; btn.classList.remove('copied'); }, 2000);
    });
}

function logAction(tid, actionType, btn) {
    var repSelect = document.getElementById('rep-' + tid);
    var repId = repSelect ? repSelect.value : null;

    fetch('/api/log-threat-action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({threat_id: tid, action_type: actionType, rep_id: repId || null})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            btn.classList.add('done');
            showToast('Action logged! +' + data.points_earned + ' points');
            if (typeof window.tpbUpdateNavPoints === 'function') window.tpbUpdateNavPoints();
        } else {
            showToast(data.error || 'Login required');
        }
    })
    .catch(function() { showToast('Error logging action'); });
}

function rateThreat(event, tid) {
    var track = event.currentTarget;
    var rect = track.getBoundingClientRect();
    var pct = (event.clientX - rect.left) / rect.width;
    var rating = Math.round(pct * 20 - 10);

    document.getElementById('marker-' + tid).style.left = (pct * 100) + '%';
    document.getElementById('yr-' + tid).textContent = 'You: ' + (rating > 0 ? '+' : '') + rating;

    fetch('/api/rate-threat.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({threat_id: tid, rating: rating})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            document.getElementById('ar-' + tid).textContent = 'Avg: ' + (data.avg > 0 ? '+' : '') + data.avg + ' (' + data.count + ')';
            document.getElementById('fill-' + tid).style.width = ((data.avg + 10) / 20 * 100) + '%';
        }
    });
}

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 3000);
}

// ============================================================
// Tag filtering + severity sorting
// ============================================================
var activeTag = 'all';

function filterByTag(tag, pill) {
    activeTag = tag;
    // Update filter pills
    document.querySelectorAll('#tag-filters .filter-pill').forEach(function(p) {
        p.classList.toggle('active', p.dataset.tag === tag);
    });

    var shown = 0;
    var total = 0;
    document.querySelectorAll('.threat-item').forEach(function(item) {
        total++;
        var tags = item.dataset.tags || '';
        if (tag === 'all' || tags.split(',').indexOf(tag) !== -1) {
            item.style.display = '';
            shown++;
        } else {
            item.style.display = 'none';
        }
    });

    document.getElementById('filter-count').textContent = shown + ' of ' + total + ' threats';

    // Update person card visibility — hide cards with 0 visible threats
    document.querySelectorAll('.person-card').forEach(function(card) {
        var visible = card.querySelectorAll('.threat-item:not([style*="display: none"])').length;
        var threatsList = card.querySelector('.threats-list');
        var toggle = card.querySelector('.threat-toggle');
        if (tag !== 'all' && visible === 0 && threatsList) {
            card.style.opacity = '0.3';
        } else {
            card.style.opacity = '';
        }
    });
}

function sortThreats(sortBy) {
    document.querySelectorAll('.threats-list').forEach(function(list) {
        var items = Array.from(list.querySelectorAll('.threat-item'));
        items.sort(function(a, b) {
            if (sortBy === 'severity') {
                return (parseInt(b.dataset.severity) || 0) - (parseInt(a.dataset.severity) || 0);
            } else if (sortBy === 'date') {
                return b.dataset.date.localeCompare(a.dataset.date);
            }
            return 0;
        });
        items.forEach(function(item) { list.appendChild(item); });
    });

    // For "by official" sort, reorder person cards
    if (sortBy === 'official') {
        var grid = document.querySelector('.people-grid');
        var cards = Array.from(grid.querySelectorAll('.person-card'));
        cards.sort(function(a, b) {
            return (parseInt(b.dataset.threats) || 0) - (parseInt(a.dataset.threats) || 0);
        });
        cards.forEach(function(card) { grid.appendChild(card); });
    }
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
