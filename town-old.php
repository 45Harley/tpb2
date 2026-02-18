<?php
/**
 * TPB State/Town Router
 * =====================
 * Dynamic fallback for state/town pages that don't have static files yet.
 * 
 * Routes:
 *   /z-states/{state}/           → state page (if no static exists)
 *   /z-states/{state}/{town}/    → town page (if no static exists)
 * 
 * Shows "Coming Soon" with volunteer CTA.
 */

$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Get params
$stateAbbr = isset($_GET['state']) ? strtoupper(trim($_GET['state'])) : '';
$townSlug = isset($_GET['town']) ? strtolower(trim($_GET['town'])) : '';

// Validate state abbreviation (2 letters)
if (!preg_match('/^[A-Z]{2}$/', $stateAbbr)) {
    http_response_code(404);
    die("Invalid state");
}

// Lookup state in DB
$stmt = $pdo->prepare("SELECT state_id, state_name, abbreviation, legislature_url FROM states WHERE abbreviation = ?");
$stmt->execute([$stateAbbr]);
$state = $stmt->fetch();

if (!$state) {
    http_response_code(404);
    die("State not found");
}

// If town provided, lookup town
$town = null;
if ($townSlug) {
    // Convert slug back to name for lookup (beacon-falls → Beacon Falls)
    // Try exact match first, then try with spaces
    $townNameGuess = ucwords(str_replace('-', ' ', $townSlug));
    
    $stmt = $pdo->prepare("
        SELECT t.town_id, t.town_name, t.population, 
               t.us_congress_district, t.state_senate_district, t.state_house_district
        FROM towns t
        WHERE t.state_id = ? AND LOWER(REPLACE(t.town_name, ' ', '-')) = ?
    ");
    $stmt->execute([$state['state_id'], $townSlug]);
    $town = $stmt->fetch();
    
    if (!$town) {
        http_response_code(404);
        die("Town not found in " . htmlspecialchars($state['state_name']));
    }
}

// Get user data
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);

// Count members in this state/town
if ($town) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE current_town_id = ?");
    $stmt->execute([$town['town_id']]);
    $memberCount = $stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE current_state_id = ?");
    $stmt->execute([$state['state_id']]);
    $memberCount = $stmt->fetchColumn();
}

// Nav variables via helper
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
// Override town name since town.php uses different field name
if ($dbUser) {
    $userTownName = $dbUser['town_name'] ?? '';
    $userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
}

// Page config
$isTownPage = (bool)$town;
$pageTitle = $isTownPage 
    ? $town['town_name'] . ', ' . $state['abbreviation'] . ' - The People\'s Branch'
    : $state['state_name'] . ' - The People\'s Branch';
$currentPage = 'town';

// CTA link based on trust level
$ctaLink = '/profile.php';
$ctaText = 'Get Started';
if ($dbUser && $dbUser['phone_verified']) {
    $ctaLink = '/volunteer/apply.php';
    $ctaText = 'Apply to Volunteer';
}

require 'includes/header.php';
require 'includes/nav.php';
?>

<style>
.coming-soon-container {
    max-width: 600px;
    margin: 2rem auto;
    padding: 1rem;
    text-align: center;
}
.location-title {
    font-size: 2rem;
    color: #d4af37;
    margin-bottom: 0.5rem;
}
.location-subtitle {
    color: #888;
    margin-bottom: 2rem;
}
.coming-soon-badge {
    display: inline-block;
    background: rgba(212, 175, 55, 0.2);
    border: 1px solid #d4af37;
    color: #d4af37;
    padding: 0.5rem 1.5rem;
    border-radius: 20px;
    font-size: 1.2rem;
    margin-bottom: 1.5rem;
}
.stats-box {
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
    padding: 1.5rem;
    margin: 1.5rem 0;
    text-align: left;
}
.stat-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0;
    color: #ccc;
}
.stat-row .icon {
    font-size: 1.2rem;
}
.stat-row a {
    color: #88c0d0;
    text-decoration: none;
}
.stat-row a:hover {
    text-decoration: underline;
}
.cta-section {
    margin: 2rem 0;
    padding: 1.5rem;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}
.cta-section h3 {
    color: #e0e0e0;
    margin-bottom: 1rem;
}
.cta-steps {
    text-align: left;
    margin: 1rem auto;
    max-width: 300px;
}
.cta-steps li {
    padding: 0.3rem 0;
    color: #aaa;
}
.cta-steps li.completed {
    color: #4caf50;
    text-decoration: line-through;
}
.cta-button {
    display: inline-block;
    background: #d4af37;
    color: #000;
    padding: 0.75rem 2rem;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    margin-top: 1rem;
}
.cta-button:hover {
    background: #e4bf47;
}
.back-links {
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #333;
}
.back-links a {
    color: #88c0d0;
    text-decoration: none;
    margin: 0 1rem;
}
.back-links a:hover {
    text-decoration: underline;
}
</style>

<main class="coming-soon-container">
    
    <?php if ($isTownPage): ?>
        <!-- TOWN PAGE -->
        <h1 class="location-title"><?= htmlspecialchars($town['town_name']) ?>, <?= htmlspecialchars($state['state_name']) ?></h1>
    <?php else: ?>
        <!-- STATE PAGE -->
        <h1 class="location-title"><?= htmlspecialchars($state['state_name']) ?></h1>
    <?php endif; ?>
    
    <div class="coming-soon-badge">🚧 Coming Soon 🚧</div>
    
    <p class="location-subtitle">
        <?php if ($isTownPage): ?>
            <?= htmlspecialchars($town['town_name']) ?> is waiting for local volunteers to help build its TPB page.
        <?php else: ?>
            <?= htmlspecialchars($state['state_name']) ?> is waiting for volunteers to help build its TPB presence.
        <?php endif; ?>
    </p>
    
    <div class="stats-box">
        <div class="stat-row">
            <span class="icon">📊</span>
            <span><?= $memberCount ?> TPB member<?= $memberCount != 1 ? 's' : '' ?> in <?= htmlspecialchars($isTownPage ? $town['town_name'] : $state['state_name']) ?></span>
        </div>
        
        <?php if ($isTownPage): ?>
            <?php if ($town['us_congress_district']): ?>
            <div class="stat-row">
                <span class="icon">🗳️</span>
                <span>US Congress District: <?= htmlspecialchars($state['abbreviation']) ?>-<?= htmlspecialchars($town['us_congress_district']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($town['state_senate_district']): ?>
            <div class="stat-row">
                <span class="icon">🏛️</span>
                <span>State Senate District: <?= htmlspecialchars($town['state_senate_district']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($town['state_house_district']): ?>
            <div class="stat-row">
                <span class="icon">🏠</span>
                <span>State House District: <?= htmlspecialchars($town['state_house_district']) ?></span>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($state['legislature_url']): ?>
        <div class="stat-row">
            <span class="icon">🔗</span>
            <span><a href="<?= htmlspecialchars($state['legislature_url']) ?>" target="_blank"><?= htmlspecialchars($state['state_name']) ?> Legislature</a></span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="cta-section">
        <h3>Want to help build this page?</h3>
        <ol class="cta-steps">
            <li class="<?= $dbUser ? 'completed' : '' ?>">Complete your profile</li>
            <li class="<?= ($dbUser && $dbUser['email_verified']) ? 'completed' : '' ?>">Verify your email</li>
            <li class="<?= ($dbUser && $dbUser['phone_verified']) ? 'completed' : '' ?>">Verify your phone (2FA)</li>
            <li>Apply to volunteer</li>
        </ol>
        <a href="<?= $ctaLink ?>" class="cta-button"><?= $ctaText ?></a>
    </div>
    
    <div class="back-links">
        <?php if ($isTownPage): ?>
            <a href="/z-states/<?= strtolower($state['abbreviation']) ?>/">← <?= htmlspecialchars($state['state_name']) ?></a>
        <?php endif; ?>
        <a href="/">← USA Map</a>
    </div>
    
</main>

<?php require 'includes/footer.php'; ?>
