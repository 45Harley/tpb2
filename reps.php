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

// Get session
$sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;

// Get user data
$dbUser = null;
if ($sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.*,
               u.identity_level_id,
               s.abbreviation as state_abbrev, s.state_name,
               t.town_name,
               il.level_name as identity_level_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM user_devices ud
        JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns t ON u.current_town_id = t.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute(array($sessionId));
    $dbUser = $stmt->fetch();
}

// Get filters from URL
$myRepsMode = isset($_GET['my']) && $_GET['my'] == '1';
$levelFilter = isset($_GET['level']) ? $_GET['level'] : '';
$stateFilter = isset($_GET['state']) ? strtoupper($_GET['state']) : '';
$chamberFilter = isset($_GET['branch']) ? $_GET['branch'] : '';
$searchFilter = isset($_GET['search']) ? trim($_GET['search']) : '';
$townFilter = isset($_GET['town']) ? trim($_GET['town']) : '';

// My Reps mode - apply user's location as filters
$userState = '';
$userTown = '';
$userCD = '';
$userSLDU = '';
$userSLDL = '';

if ($dbUser && $dbUser['state_abbrev']) {
    $userState = $dbUser['state_abbrev'];
    $userTown = $dbUser['town_name'];
    $userCD = $dbUser['us_congress_district'];
    $userSLDU = $dbUser['state_senate_district'];
    $userSLDL = $dbUser['state_house_district'];
}

if ($myRepsMode && $userState) {
    $stateFilter = $userState;
}

// Get all states for dropdown
$states = $pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

// Build query
$sql = "
    SELECT eo.*,
           go.org_type, go.org_name,
           s.state_name,
           t.town_name as org_town_name
    FROM elected_officials eo
    JOIN governing_organizations go ON eo.org_id = go.org_id
    LEFT JOIN states s ON eo.state_code = s.abbreviation
    LEFT JOIN towns t ON go.town_id = t.town_id
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

// Search filter
if ($searchFilter) {
    $sql .= " AND (eo.full_name LIKE ? OR eo.office_name LIKE ?)";
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

// My Reps mode - filter to user's specific districts
if ($myRepsMode && $userState) {
    $districtConditions = array();

    // Federal - President, VP, Supreme Court
    $districtConditions[] = "eo.title IN ('President', 'Vice President')";
    $districtConditions[] = "(eo.office_name LIKE '%Supreme Court%' AND go.org_type = 'Federal')";

    // US Senators for their state
    $districtConditions[] = "(eo.title = 'U.S. Senator' AND eo.state_code = " . $pdo->quote($userState) . ")";

    // US Rep for their district
    if ($userCD) {
        $districtConditions[] = "(eo.ocd_id LIKE '%state:" . strtolower($userState) . "/cd:" . $userCD . "')";
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

    // Town officials
    if ($userTown) {
        $districtConditions[] = "(t.town_name = " . $pdo->quote($userTown) . " AND go.org_type = 'Town')";
    }

    $sql .= " AND (" . implode(" OR ", $districtConditions) . ")";
}

// Order
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
    eo.full_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$officials = $stmt->fetchAll();

// Count by level
$counts = array('federal' => 0, 'state' => 0, 'town' => 0, 'total' => count($officials));
foreach ($officials as $o) {
    $type = strtolower($o['org_type']);
    if (isset($counts[$type])) {
        $counts[$type]++;
    }
}

// Nav variables via helper
require_once __DIR__ . '/includes/get-user.php';
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
    gap: 1rem;
}
.level-header {
    color: #d4af37;
    font-size: 1.1rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid #333;
    margin-top: 1rem;
}
.level-header:first-child { margin-top: 0; }
.official-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid #333;
    border-radius: 8px;
    padding: 1rem;
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
        $currentLevel = '';
        foreach ($officials as $o):
            // Level header
            if ($o['org_type'] !== $currentLevel):
                $currentLevel = $o['org_type'];
        ?>
        <div class="level-header">
            <?php if ($currentLevel == 'Federal'): ?>🇺🇸 Federal
            <?php elseif ($currentLevel == 'State'): ?>🗺️ State
            <?php else: ?>🏘️ Town
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="official-card">
            <div class="official-header">
                <span class="official-name"><?= htmlspecialchars($o['full_name']) ?></span>
                <?php if ($o['party']): ?>
                <span class="party-badge party-<?= substr($o['party'], 0, 1) ?>"><?= htmlspecialchars($o['party']) ?></span>
                <?php endif; ?>
            </div>
            <div class="official-title"><?= htmlspecialchars($o['title']) ?></div>
            <div class="official-office"><?= htmlspecialchars($o['office_name']) ?></div>
            <div class="official-contact">
                <?php if ($o['email']): ?>
                <a href="mailto:<?= htmlspecialchars($o['email']) ?>" class="contact-link">📧 Email</a>
                <?php endif; ?>
                <?php if ($o['phone']): ?>
                <a href="tel:<?= htmlspecialchars($o['phone']) ?>" class="contact-link">📞 <?= htmlspecialchars($o['phone']) ?></a>
                <?php endif; ?>
                <?php if ($o['website']): ?>
                <a href="<?= htmlspecialchars($o['website']) ?>" target="_blank" class="contact-link">🌐 Website</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<?php require 'includes/footer.php'; ?>
