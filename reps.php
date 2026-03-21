<?php
/**
 * Representatives Browser
 * =======================
 * Display elected officials with filters
 *
 * Filters:
 * - ?my=1 - preset filter using user's location/districts
 * - ?level=federal|state|town
 * - ?state=CT
 * - ?branch=executive|legislative|judicial
 * - ?search=name
 * - ?town=putnam (for town-level)
 */

// Database connection
$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Get user data
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);

// Get filters from URL
$myRepsMode = isset($_GET['my']) && $_GET['my'] == '1';
$levelFilter = isset($_GET['level']) ? $_GET['level'] : '';
$stateFilter = isset($_GET['state']) ? strtoupper($_GET['state']) : '';
$chamberFilter = isset($_GET['branch']) ? $_GET['branch'] : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';
$townFilter = isset($_GET['town']) ? trim($_GET['town']) : '';
$showFilter = isset($_GET['show']) ? $_GET['show'] : ($myRepsMode ? 'elected' : 'all');

// My Reps mode - apply user's location as filters
$userState = '';
$userTown = '';
$userTownId = '';
$userCD = '';
$userSLDU = '';
$userSLDL = '';

if ($dbUser && $dbUser['state_abbrev']) {
    $userState = $dbUser['state_abbrev'];
    $userTown = $dbUser['town_name'] ?? '';
    $userTownId = $dbUser['current_town_id'] ?? '';
    $userCD = $dbUser['us_congress_district'];
    $userSLDU = $dbUser['state_senate_district'];
    $userSLDL = $dbUser['state_house_district'];
}

if ($myRepsMode && $userState) {
    $stateFilter = $userState;
}

// Get all states for dropdown
$states = $pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

// Build query — include branch info for grouping, DISTINCT to avoid duplicate branch rows
$sql = "
    SELECT DISTINCT eo.official_id, eo.full_name, eo.title, eo.party, eo.office_name,
           eo.email, eo.phone, eo.website, eo.bioguide_id, eo.photo_url,
           eo.ocd_id, eo.state_code, eo.org_id, eo.branch_id,
           eo.appointment_type, eo.term_start, eo.term_end, eo.is_vacant,
           go.org_type, go.org_name, go.town_id,
           s.state_name,
           t.town_name as org_town_name,
           bd.branch_name, bd.branch_type, bd.total_seats, bd.description as board_description,
           (SELECT rc.description FROM role_canonicals rc
            WHERE rc.local_title = eo.title AND rc.org_id = eo.org_id
            AND (rc.branch_id = eo.branch_id OR rc.branch_id IS NULL)
            LIMIT 1) as role_description
    FROM elected_officials eo
    JOIN governing_organizations go ON eo.org_id = go.org_id
    LEFT JOIN states s ON eo.state_code = s.abbreviation
    LEFT JOIN towns t ON go.town_id = t.town_id
    LEFT JOIN branches_departments bd ON eo.branch_id = bd.branch_id AND bd.org_id = eo.org_id
    WHERE eo.is_current = 1
";
$params = array();

// Level filter
if ($levelFilter) {
    $sql .= " AND go.org_type = ?";
    $params[] = ucfirst($levelFilter);
}

// State filter (for browse mode)
if ($stateFilter && !$myRepsMode) {
    // For federal level, filter by state_code (senators, reps) but include president/vp/supreme court
    if ($levelFilter == 'federal') {
        $sql .= " AND (eo.state_code = ? OR eo.title IN ('President', 'Vice President') OR (eo.office_name LIKE '%Supreme Court%' AND go.org_type = 'Federal'))";
        $params[] = $stateFilter;
    } else {
        $sql .= " AND (eo.state_code = ? OR go.org_type = 'Federal')";
        $params[] = $stateFilter;
    }
}

// Branch filter
if ($chamberFilter) {
    switch ($chamberFilter) {
        case 'executive':
            $sql .= " AND eo.title IN ('President', 'Vice President', 'Governor', 'Mayor', 'First Selectman')";
            break;
        case 'legislative':
            $sql .= " AND (eo.title = 'U.S. Senator' OR eo.ocd_id LIKE '%/cd:%' OR eo.ocd_id LIKE '%/sldu:%' OR eo.ocd_id LIKE '%/sldl:%' OR eo.office_name LIKE '%Council%' OR eo.office_name LIKE '%Selectman%')";
            break;
        case 'judicial':
            $sql .= " AND eo.office_name LIKE '%Court%'";
            break;
    }
}

// Town filter
if ($townFilter) {
    $sql .= " AND t.town_name LIKE ?";
    $params[] = '%' . $townFilter . '%';
}

// Show filter — elected/appointed/staff/all
if ($showFilter === 'elected') {
    $sql .= " AND eo.appointment_type = 'elected'";
} elseif ($showFilter === 'boards') {
    $sql .= " AND (eo.appointment_type = 'elected' OR (eo.appointment_type = 'appointed' AND eo.term_end IS NOT NULL))";
} elseif ($showFilter === 'staff') {
    // Show everything including staff
}
// 'all' = no filter

// Search filter
if ($searchFilter) {
    $sql .= " AND (eo.full_name LIKE ? OR eo.office_name LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

// My Reps mode - filter to user's specific districts
if ($myRepsMode && $userState) {
    $districtConditions = array();

    // Federal - President, VP, Supreme Court (scope to Federal org to avoid matching Council President/VP)
    $districtConditions[] = "(eo.title IN ('President', 'Vice President') AND go.org_type = 'Federal')";
    $districtConditions[] = "(eo.office_name LIKE '%Supreme Court%' AND go.org_type = 'Federal')";

    // US Senators for their state
    $districtConditions[] = "(eo.title = 'U.S. Senator' AND eo.state_code = " . $pdo->quote($userState) . ")";

    // US Rep for their district — extract district number from "CT-2" format
    if ($userCD) {
        $cdNum = $userCD;
        if (strpos($userCD, '-') !== false) {
            $parts = explode('-', $userCD);
            $cdNum = ltrim($parts[1], '0') ?: '0';
        }
        $districtConditions[] = "(eo.ocd_id LIKE '%state:" . strtolower($userState) . "/cd:" . $cdNum . "')";
    }

    // Governor
    $districtConditions[] = "(eo.title = 'Governor' AND eo.state_code = " . $pdo->quote($userState) . ")";

    // State Supreme Court
    $districtConditions[] = "(eo.office_name LIKE '%Supreme Court%' AND eo.state_code = " . $pdo->quote($userState) . ")";

    // State Senator for their district
    if ($userSLDU) {
        $districtConditions[] = "(eo.ocd_id LIKE '%state:" . strtolower($userState) . "/sldu:" . $userSLDU . "')";
    }

    // State Rep for their district
    if ($userSLDL) {
        $districtConditions[] = "(eo.ocd_id LIKE '%state:" . strtolower($userState) . "/sldl:" . $userSLDL . "')";
    }

    // Town officials — use town_id for precision (town names can repeat across states)
    if ($userTownId) {
        $districtConditions[] = "(go.town_id = " . (int)$userTownId . " AND go.org_type = 'Town')";
    } elseif ($userTown) {
        $districtConditions[] = "(t.town_name = " . $pdo->quote($userTown) . " AND eo.state_code = " . $pdo->quote($userState) . " AND go.org_type = 'Town')";
    }

    $sql .= " AND (" . implode(" OR ", $districtConditions) . ")";
}

// Order — federal/state by role importance, town by elected-first then board name
$sql .= " ORDER BY
    CASE go.org_type
        WHEN 'Federal' THEN 1
        WHEN 'State' THEN 2
        WHEN 'Town' THEN 3
    END,
    CASE
        WHEN eo.title = 'President' THEN 1
        WHEN eo.title = 'Vice President' THEN 2
        WHEN eo.title = 'Governor' THEN 3
        WHEN eo.title = 'U.S. Senator' THEN 4
        WHEN eo.ocd_id LIKE '%/cd:%' THEN 5
        WHEN eo.office_name LIKE '%Supreme Court%' THEN 6
        WHEN eo.ocd_id LIKE '%/sldu:%' THEN 7
        WHEN eo.ocd_id LIKE '%/sldl:%' THEN 8
        ELSE 9
    END,
    CASE eo.appointment_type WHEN 'elected' THEN 1 WHEN 'appointed' THEN 2 ELSE 3 END,
    CASE bd.branch_type
        WHEN 'Executive' THEN 1
        WHEN 'Legislative' THEN 2
        WHEN 'Board' THEN 3
        WHEN 'Commission' THEN 4
        WHEN 'Committee' THEN 5
        WHEN 'Authority' THEN 6
        WHEN 'Department' THEN 7
        ELSE 8
    END,
    bd.branch_name,
    CASE eo.title
        WHEN 'Mayor' THEN 1 WHEN 'First Selectman' THEN 1
        WHEN 'Deputy Mayor' THEN 2
        WHEN 'Chair' THEN 3 WHEN 'Chairman' THEN 3 WHEN 'Chairperson' THEN 3 WHEN 'President' THEN 3
        WHEN 'Vice Chair' THEN 4 WHEN 'Vice Chairman' THEN 4 WHEN 'Vice President' THEN 4
        WHEN 'Secretary' THEN 5 WHEN 'Clerk' THEN 5
        WHEN 'Treasurer' THEN 6
        ELSE 7
    END,
    eo.full_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$officials = $stmt->fetchAll();

// Group officials by level, then by board for town level
$counts = array('federal' => 0, 'state' => 0, 'town' => 0, 'total' => count($officials));
$grouped = array('Federal' => [], 'State' => [], 'Town' => []);
foreach ($officials as $o) {
    $type = strtolower($o['org_type']);
    if (isset($counts[$type])) $counts[$type]++;
    $grouped[$o['org_type']][] = $o;
}

// For town level, sub-group by board and appointment type
$townElected = [];
$townAppointed = [];
$townStaff = [];
foreach ($grouped['Town'] as $o) {
    $board = $o['branch_name'] ?: 'Other';
    $isStaff = ($o['appointment_type'] === 'appointed' && empty($o['term_end']) && !in_array($o['branch_type'], ['Board', 'Commission', 'Committee', 'Authority', 'Legislative']));
    if ($isStaff) {
        $townStaff[$board][] = $o;
    } elseif ($o['appointment_type'] === 'elected') {
        $townElected[$board][] = $o;
    } else {
        $townAppointed[$board][] = $o;
    }
}

// Nav variables via helper
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Page config
$pageTitle = $myRepsMode ? 'My Representatives' : 'Browse Representatives';
$currentPage = 'government';
$pageStyles = '
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.filter-group label {
    font-size: 0.75rem;
    color: #888;
    text-transform: uppercase;
}
.filter-group select,
.filter-group input {
    padding: 0.5rem;
    background: #252525;
    border: 1px solid #444;
    border-radius: 4px;
    color: #e0e0e0;
    font-size: 0.9rem;
}
.level-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.level-tab {
    padding: 0.5rem 1rem;
    background: transparent;
    border: 1px solid #444;
    border-radius: 20px;
    color: #888;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: none;
}
.level-tab:hover { border-color: #666; color: #e0e0e0; }
.level-tab.active { background: #d4af37; border-color: #d4af37; color: #000; }
.results-info {
    color: #888;
    font-size: 0.9rem;
    margin-bottom: 1rem;
}
.officials-grid {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.level-section {
    margin-bottom: 1.5rem;
}
.level-header {
    color: #d4af37;
    font-size: 1.3rem;
    padding: 0.75rem 0;
    border-bottom: 2px solid #d4af37;
    margin-bottom: 1rem;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.level-header .count { font-size: 0.8rem; color: #888; font-weight: normal; }
.level-header .toggle { font-size: 0.8rem; color: #666; }
.level-body { }
.level-body.collapsed { display: none; }
.board-group {
    margin-bottom: 1.25rem;
}
.board-header {
    color: #88c0d0;
    font-size: 0.95rem;
    padding: 0.4rem 0.75rem;
    margin-bottom: 0.5rem;
    background: rgba(136,192,208,0.08);
    border-left: 3px solid #88c0d0;
    border-radius: 0 4px 4px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.board-header.elected { border-left-color: #4caf50; background: rgba(76,175,80,0.08); color: #81c784; }
.board-header.appointed { border-left-color: #ff9800; background: rgba(255,152,0,0.08); color: #ffb74d; }
.board-header.staff { border-left-color: #666; background: rgba(100,100,100,0.08); color: #aaa; }
.board-header .seats { font-size: 0.8rem; color: #888; font-weight: normal; }
.board-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    padding-left: 0.75rem;
}
.category-label {
    color: #888;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 1rem 0 0.5rem 0;
    padding-bottom: 0.3rem;
    border-bottom: 1px solid #333;
}
.official-card {
    width: 150px;
    text-align: center;
    padding: 14px 10px;
    border-radius: 8px;
    background: #1a1a1a;
    border: 2px solid #2a2a2a;
    transition: transform 0.2s, border-color 0.2s;
}
.official-card:hover {
    transform: translateY(-2px);
    border-color: #444;
}
.official-card.party-D { border-color: #1a3a5c; }
.official-card.party-R { border-color: #5c1a1a; }
.official-card.party-I, .official-card.party-U { border-color: #3a3a3a; }
.official-card.vacant { border-color: #5c3a1a; opacity: 0.6; }
.official-card .rep-photo {
    width: 70px;
    height: 70px;
    object-fit: cover;
    border-radius: 50%;
    margin: 0 auto 8px;
    display: block;
    background: #2a2a2a;
}
.official-card { position: relative; }
.official-card .card-tooltip {
    display: none;
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    width: 260px;
    background: #1a1a2e;
    border: 1px solid #d4af37;
    border-radius: 8px;
    padding: 12px;
    z-index: 1000;
    text-align: left;
    box-shadow: 0 4px 20px rgba(0,0,0,0.6);
    margin-top: 8px;
}
.official-card:hover { z-index: 999; }
.official-card:hover .card-tooltip { display: block; }
.card-tooltip .tt-board { color: #d4af37; font-size: 0.85rem; font-weight: bold; margin-bottom: 4px; }
.card-tooltip .tt-board-desc { color: #b0b0b0; font-size: 0.8rem; margin-bottom: 8px; line-height: 1.4; }
.card-tooltip .tt-role { color: #88c0d0; font-size: 0.8rem; font-style: italic; line-height: 1.4; }
.card-tooltip::before {
    content: "";
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent;
    border-bottom-color: #d4af37;
}
.official-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
}
.official-name {
    font-size: 1.1rem;
    font-weight: bold;
    color: #e0e0e0;
}
.party-badge {
    font-size: 0.75rem;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-weight: bold;
}
.party-D { background: #1a3a5c; color: #7ab8e0; }
.party-R { background: #5c1a1a; color: #e07a7a; }
.party-I { background: #3a3a3a; color: #aaa; }
.official-title {
    color: #d4af37;
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}
.official-office {
    color: #888;
    font-size: 0.85rem;
    margin-bottom: 0.75rem;
}
.official-contact {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.contact-link {
    font-size: 0.85rem;
    color: #7ab8e0;
    text-decoration: none;
    padding: 0.25rem 0.5rem;
    background: rgba(122,184,224,0.1);
    border-radius: 4px;
}
.contact-link:hover { background: rgba(122,184,224,0.2); }
.no-results {
    text-align: center;
    padding: 3rem;
    color: #888;
}
.no-results .icon { font-size: 3rem; margin-bottom: 1rem; }
.set-location-prompt {
    background: rgba(212,175,55,0.1);
    border: 1px solid #d4af37;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
}
.set-location-prompt p { color: #d4af37; margin-bottom: 1rem; }
';

// Include header and nav
require 'includes/header.php';
require 'includes/nav.php';
?>

<main class="main">
    <h1><?= $myRepsMode ? 'My Representatives' : 'Browse Representatives' ?></h1>
    <p class="subtitle"><?= $myRepsMode ? 'Officials who represent you' : 'Find elected officials' ?></p>

    <?php if ($myRepsMode && !$userState): ?>
    <div class="set-location-prompt">
        <p>📍 Set your location to see your representatives</p>
        <a href="profile.php" class="btn btn-primary">Set My Location</a>
    </div>
    <?php endif; ?>

    <!-- Mode Toggle -->
    <div class="level-tabs">
        <a href="reps.php?my=1" class="level-tab <?= $myRepsMode ? 'active' : '' ?>">My Reps</a>
        <a href="reps.php" class="level-tab <?= !$myRepsMode ? 'active' : '' ?>">Browse All</a>
    </div>

    <!-- Filters -->
    <form class="filter-bar" method="get">
        <?php if ($myRepsMode): ?>
        <input type="hidden" name="my" value="1">
        <?php endif; ?>

        <?php if (!$myRepsMode): ?>
        <div class="filter-group">
            <label>State</label>
            <select name="state" onchange="this.form.submit()">
                <option value="">All States</option>
                <?php foreach ($states as $s): ?>
                <option value="<?= $s['abbreviation'] ?>" <?= $stateFilter == $s['abbreviation'] ? 'selected' : '' ?>><?= htmlspecialchars($s['state_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="filter-group">
            <label>Level</label>
            <select name="level" onchange="this.form.submit()">
                <option value="">All Levels</option>
                <option value="federal" <?= $levelFilter == 'federal' ? 'selected' : '' ?>>Federal</option>
                <option value="state" <?= $levelFilter == 'state' ? 'selected' : '' ?>>State</option>
                <option value="town" <?= $levelFilter == 'town' ? 'selected' : '' ?>>Town</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Show</label>
            <select name="show" onchange="this.form.submit()">
                <option value="elected" <?= $showFilter == 'elected' ? 'selected' : '' ?>>Elected</option>
                <option value="boards" <?= $showFilter == 'boards' ? 'selected' : '' ?>>Elected + Appointed Boards</option>
                <option value="all" <?= $showFilter == 'all' ? 'selected' : '' ?>>All (incl. Staff)</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Branch</label>
            <select name="branch" onchange="this.form.submit()">
                <option value="">All Branches</option>
                <option value="executive" <?= $chamberFilter == 'executive' ? 'selected' : '' ?>>Executive</option>
                <option value="legislative" <?= $chamberFilter == 'legislative' ? 'selected' : '' ?>>Legislative</option>
                <option value="judicial" <?= $chamberFilter == 'judicial' ? 'selected' : '' ?>>Judicial</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="Name or office...">
        </div>

        <div class="filter-group" style="justify-content: flex-end;">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;">Search</button>
        </div>
    </form>

    <!-- Results -->
    <div class="results-info">
        Showing <?= count($officials) ?> officials
        <?php if ($counts['federal']): ?>(<?= $counts['federal'] ?> federal)<?php endif; ?>
        <?php if ($counts['state']): ?>(<?= $counts['state'] ?> state)<?php endif; ?>
        <?php if ($counts['town']): ?>(<?= $counts['town'] ?> town)<?php endif; ?>
    </div>

    <?php if (empty($officials)): ?>
    <div class="no-results">
        <div class="icon">🔍</div>
        <p>No officials found matching your filters.</p>
        <?php if ($myRepsMode && !$userState): ?>
        <p><a href="profile.php">Set your location</a> to see your representatives.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="officials-grid">

    <?php
    // Helper: render a single card
    function renderCard($o) {
        $partyCode = $o['party'] ? strtoupper(substr($o['party'], 0, 1)) : '';
        $partyClass = $partyCode ? "party-$partyCode" : '';
        if ($o['full_name'] === 'VACANT') $partyClass = 'vacant';
        $photoUrl = !empty($o['bioguide_id'])
            ? "https://bioguide.congress.gov/bioguide/photo/{$o['bioguide_id'][0]}/{$o['bioguide_id']}.jpg"
            : (!empty($o['photo_url']) ? htmlspecialchars($o['photo_url']) : '');
        ?>
        <div class="official-card <?= $partyClass ?>">
            <?php if ($photoUrl): ?>
            <img src="<?= $photoUrl ?>" alt="<?= htmlspecialchars($o['full_name']) ?>" class="rep-photo" loading="lazy"
                 onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="official-name" style="font-size:0.9rem;font-weight:bold;color:#e0e0e0;margin-bottom:4px"><?= htmlspecialchars($o['full_name']) ?></div>
            <?php if ($o['party']): ?>
            <span class="party-badge party-<?= $partyCode ?>" style="font-size:0.7rem;display:inline-block;margin-bottom:4px"><?= htmlspecialchars($o['party']) ?></span>
            <?php endif; ?>
            <div style="color:#d4af37;font-size:0.8rem"><?= htmlspecialchars($o['title']) ?></div>
            <?php if (!empty($o['office_name'])): ?>
            <div style="color:#888;font-size:0.75rem;margin-top:2px"><?= htmlspecialchars($o['office_name']) ?></div>
            <?php endif; ?>
            <?php if (!empty($o['term_end'])): ?>
            <div style="color:#666;font-size:0.7rem;margin-top:4px">Term ends <?= date('M Y', strtotime($o['term_end'])) ?></div>
            <?php endif; ?>
            <div class="official-contact" style="margin-top:6px;justify-content:center">
                <?php if ($o['email']): ?>
                <a href="mailto:<?= htmlspecialchars($o['email']) ?>" class="contact-link" style="font-size:0.75rem">📧</a>
                <?php endif; ?>
                <?php if ($o['phone']): ?>
                <a href="tel:<?= htmlspecialchars($o['phone']) ?>" class="contact-link" style="font-size:0.75rem">📞</a>
                <?php endif; ?>
                <?php if ($o['website']): ?>
                <a href="<?= htmlspecialchars($o['website']) ?>" target="_blank" class="contact-link" style="font-size:0.75rem">🌐</a>
                <?php endif; ?>
            </div>
            <?php
            $bd = $o['board_description'] ?? '';
            $rd = $o['role_description'] ?? '';
            $bn = $o['branch_name'] ?? '';
            if ($bd || $rd || $bn): ?>
            <div class="card-tooltip">
                <?php if ($bn): ?>
                <div class="tt-board"><?= htmlspecialchars($bn) ?></div>
                <?php endif; ?>
                <?php if ($bd): ?>
                <div class="tt-board-desc"><?= htmlspecialchars($bd) ?></div>
                <?php endif; ?>
                <?php if ($rd): ?>
                <div class="tt-role"><?= htmlspecialchars($o['title'] ?? '') ?>: <?= htmlspecialchars($rd) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // Helper: render a board group with header and cards
    function renderBoardGroup($boardName, $officials, $type = 'elected') {
        $seats = $officials[0]['total_seats'] ?? '';
        $seatsLabel = $seats ? "$seats seats" : '';
        $boardDesc = $officials[0]['board_description'] ?? '';
        ?>
        <div class="board-group">
            <div class="board-header <?= $type ?>" <?php if ($boardDesc): ?>title="<?= htmlspecialchars($boardDesc) ?>"<?php endif; ?>>
                <?= htmlspecialchars($boardName) ?>
                <?php if ($seatsLabel): ?><span class="seats"><?= $seatsLabel ?></span><?php endif; ?>
            </div>
            <?php if ($boardDesc): ?>
            <div style="color:#888;font-size:0.8rem;padding:0 0.75rem 0.5rem;margin-top:-4px"><?= htmlspecialchars($boardDesc) ?></div>
            <?php endif; ?>
            <div class="board-cards">
                <?php foreach ($officials as $o) renderCard($o); ?>
            </div>
        </div>
        <?php
    }
    ?>

    <?php // ── FEDERAL ── ?>
    <?php if (!empty($grouped['Federal'])): ?>
    <div class="level-section">
        <div class="level-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
            🇺🇸 Federal <span class="count"><?= $counts['federal'] ?></span> <span class="toggle">▼</span>
        </div>
        <div class="level-body">
            <div class="board-cards">
                <?php foreach ($grouped['Federal'] as $o) renderCard($o); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── STATE ── ?>
    <?php if (!empty($grouped['State'])): ?>
    <div class="level-section">
        <div class="level-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
            🗺️ State <span class="count"><?= $counts['state'] ?></span> <span class="toggle">▼</span>
        </div>
        <div class="level-body">
            <div class="board-cards">
                <?php foreach ($grouped['State'] as $o) renderCard($o); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── TOWN — grouped by board ── ?>
    <?php if (!empty($grouped['Town'])): ?>
    <div class="level-section">
        <div class="level-header" onclick="this.nextElementSibling.classList.toggle('collapsed')">
            🏘️ <?= htmlspecialchars($userTown ?: 'Town') ?> <span class="count"><?= $counts['town'] ?></span> <span class="toggle">▼</span>
        </div>
        <div class="level-body">

            <?php if (!empty($townElected)): ?>
            <div class="category-label">Elected</div>
            <?php foreach ($townElected as $board => $members) renderBoardGroup($board, $members, 'elected'); ?>
            <?php endif; ?>

            <?php if (!empty($townAppointed)): ?>
            <div class="category-label">Appointed</div>
            <?php foreach ($townAppointed as $board => $members) renderBoardGroup($board, $members, 'appointed'); ?>
            <?php endif; ?>

            <?php if (!empty($townStaff)): ?>
            <div class="category-label" style="cursor:pointer" onclick="document.getElementById('staff-section').classList.toggle('collapsed')">Staff ▸</div>
            <div id="staff-section" class="collapsed">
            <?php foreach ($townStaff as $board => $members) renderBoardGroup($board, $members, 'staff'); ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    </div>
    <?php endif; ?>
</main>

<?php
require 'includes/footer.php';
?>
