<?php
/**
 * Connecticut State Page
 * ======================
 * Comprehensive state page with benefits, government, education,
 * economy, elections, towns, and civic engagement.
 *
 * Sections:
 *   - Overview (state facts)
 *   - Benefits (housing, energy, seniors, EV, childcare, DMV)
 *   - Your Reps (federal delegation)
 *   - Government (state officials, legislature)
 *   - Education (colleges, financial aid)
 *   - Budget & Economy
 *   - Elections & Voting
 *   - Your Town (169 towns grid)
 *   - Key Contacts
 *   - Talk link (civic ideas via /talk/?state=7)
 *
 * Built: Feb 2026 | Task: build-state-ct
 */

// Bootstrap
$config = require __DIR__ . '/../../config.php';

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

// State constants
$stateId = 7;
$stateName = 'Connecticut';
$stateAbbr = 'ct';

// Load user data
require_once __DIR__ . '/../../includes/get-user.php';
$dbUser = getUser($pdo);

// Nav variables via helper
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

// Variables for thought-form include
$userTown = $dbUser['town_name'] ?? null;
$userState = $dbUser['state_abbrev'] ?? null;
$canPost = $dbUser && !empty($dbUser['email_verified']);
$isMinor = $dbUser && ($dbUser['age_bracket'] === '13-17');
$needsParentConsent = $isMinor && !($dbUser['parent_consent'] ?? false);
if ($needsParentConsent) $canPost = false;
$defaultIsLocal = false; // State-level scope

// Award civic points for visiting state page
require_once __DIR__ . '/../../includes/point-logger.php';
PointLogger::init($pdo);
if ($dbUser) {
    PointLogger::award($dbUser['user_id'], 'state_page_visit', 'state', $stateId, 'state');
} else {
    $sessionId = $_COOKIE['tpb_civic_session'] ?? null;
    if ($sessionId) {
        PointLogger::awardSession($sessionId, 'state_page_visit', 'state', $stateId, 'state');
    }
}

// Page config
$currentPage = 'town';
$pageTitle = 'Connecticut - Your Civic Home | The People\'s Branch';

// =====================================================
// SECONDARY NAV - State-specific navigation
// =====================================================
$secondaryNavBrand = 'Connecticut';
$secondaryNav = [
    ['label' => 'Overview',    'anchor' => 'overview'],
    ['label' => 'Benefits',    'anchor' => 'benefits'],
    ['label' => 'Your Reps',   'anchor' => 'federal'],
    ['label' => 'Government',  'anchor' => 'officials'],
    ['label' => 'Education',   'anchor' => 'education'],
    ['label' => 'Economy',     'anchor' => 'economy'],
    ['label' => 'Elections',   'anchor' => 'elections'],
    ['label' => 'Your Town',   'anchor' => 'towns'],
    ['label' => 'Contacts',    'anchor' => 'contacts'],
    ['label' => 'CT Talk',     'url' => '/talk/?state=7'],
];

// Civic metrics for hero
$stmtMetrics = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(civic_points),0) as pts FROM users WHERE current_state_id = ?");
$stmtMetrics->execute([$stateId]);
$metricRow = $stmtMetrics->fetch();
$memberCount = (int)$metricRow['cnt'];
$civicPoints = (int)$metricRow['pts'];

$stmtTownsActive = $pdo->prepare("SELECT COUNT(DISTINCT current_town_id) as cnt FROM users WHERE current_state_id = ? AND current_town_id IS NOT NULL");
$stmtTownsActive->execute([$stateId]);
$townsActive = (int)$stmtTownsActive->fetch()['cnt'];

$stmtGroups = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM idea_groups g
    WHERE g.created_by IN (SELECT user_id FROM users WHERE current_state_id = ?)
    AND g.status IN ('active','crystallizing','crystallized')
");
$stmtGroups->execute([$stateId]);
$activeGroups = (int)$stmtGroups->fetch()['cnt'];

$stmtTasks = $pdo->prepare("SELECT COUNT(*) as cnt FROM tasks WHERE task_key LIKE ? AND status IN ('claimed','in_progress','review','completed')");
$stmtTasks->execute(['%-' . $stateAbbr . '-%']);
$activeTasks = (int)$stmtTasks->fetch()['cnt'];

// =====================================================
// PAGE STYLES
// =====================================================
$pageStyles = <<<'CSS'
/* General */
html { scroll-behavior: smooth; }
section { padding: 60px 20px; max-width: 1100px; margin: 0 auto; }
section h2 { color: #d4af37; font-size: 2em; margin-bottom: 0.5em; border-bottom: 1px solid #333; padding-bottom: 0.3em; }
section h3 { color: #88c0d0; margin-top: 1.5em; }
.section-intro { color: #aaa; font-size: 1.1em; margin-bottom: 1.5em; }
.section-subtitle { color: #888; font-size: 1.1em; margin-bottom: 30px; }
.external-link { color: #88c0d0; }
.external-link::after { content: ' ↗'; font-size: 0.8em; opacity: 0.7; }

/* Hero */
.hero {
    background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.7)),
                linear-gradient(135deg, #1a2a3a 0%, #0a1a2a 100%);
    text-align: center;
    padding: 80px 20px;
    min-height: 50vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.hero-content { max-width: 900px; animation: fadeIn 1s ease-in; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.state-badge {
    display: inline-block;
    background: rgba(212, 175, 55, 0.15);
    border: 2px solid #d4af37;
    padding: 8px 25px;
    border-radius: 30px;
    font-size: 1em;
    margin-bottom: 20px;
    letter-spacing: 2px;
    color: #d4af37;
}
.hero h1 { font-size: 3em; margin-bottom: 15px; text-shadow: 2px 2px 8px rgba(0,0,0,0.8); font-weight: normal; color: #fff; }
.hero h1 span { color: #d4af37; }
.hero .tagline { font-size: 1.3em; margin-bottom: 25px; color: #ccc; font-style: italic; }
.hero-description { font-size: 1.1em; color: #aaa; max-width: 700px; margin: 0 auto 30px; }
.hero-cta .btn { margin: 8px; }
.hero-stats {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 25px;
    flex-wrap: wrap;
}
.hero-stat {
    background: rgba(212, 175, 55, 0.08);
    border: 1px solid rgba(212, 175, 55, 0.25);
    border-radius: 10px;
    padding: 10px 20px;
    text-align: center;
    min-width: 80px;
}
.hero-stat .stat-value {
    color: #d4af37;
    font-size: 1.5em;
    font-weight: bold;
    line-height: 1.2;
}
.hero-stat .stat-label {
    color: #888;
    font-size: 0.75em;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Quick Facts */
.quick-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
    margin-top: 1.5em;
}
.fact-card {
    background: #1a1a2e;
    padding: 20px;
    border-radius: 8px;
    border-left: 3px solid #d4af37;
}
.fact-card .label { color: #888; font-size: 0.85em; text-transform: uppercase; }
.fact-card .value { color: #fff; font-size: 1.3em; margin-top: 5px; }

/* Benefits */
.benefits { background: #0f0f0f; }
.benefits-category { margin-bottom: 40px; }
.benefits-category h3 { font-size: 1.4em; color: #e0e0e0; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #333; }
.benefits-category h3 .icon { margin-right: 10px; }
.benefit-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.benefit-card {
    background: #1a1a1a;
    border: 1px solid #2a2a2a;
    border-radius: 10px;
    padding: 20px;
    transition: all 0.2s;
}
.benefit-card:hover { border-color: #d4af37; transform: translateY(-2px); }
.benefit-card h4 { color: #d4af37; font-size: 1.1em; margin-bottom: 8px; }
.benefit-card .benefit-amount { color: #4a9; font-size: 1.2em; font-weight: bold; margin-bottom: 8px; }
.benefit-card p { color: #888; font-size: 0.9em; margin-bottom: 12px; }
.benefit-card .benefit-link { font-size: 0.85em; }
.benefit-card .benefit-deadline { color: #c94; font-size: 0.85em; margin-top: 8px; }

/* Officials */
.officials { background: #0f1a1f; }
.officials-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
.official-card {
    background: #1a2a2a;
    padding: 25px;
    border-radius: 10px;
    text-align: center;
    border: 1px solid #2a3a3a;
    transition: all 0.2s;
}
.official-card:hover { border-color: #d4af37; }
.official-card .level { font-size: 0.8em; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
.official-card h3 { color: #d4af37; font-size: 1.2em; margin-bottom: 5px; }
.official-card .title { color: #999; font-size: 0.9em; font-style: italic; margin-bottom: 10px; }
.official-card .party { font-size: 0.85em; color: #888; }
.official-card .party.democrat { color: #6af; }
.official-card .party.republican { color: #f66; }
.official-card a { color: #88c0d0; font-size: 0.85em; }

/* Legislature */
.legislature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 1em; }
.legislature-card { background: #1a2a2a; padding: 25px; border-radius: 10px; border: 1px solid #2a3a3a; }
.legislature-card h4 { color: #d4af37; margin-bottom: 10px; }
.party-bar { height: 8px; border-radius: 4px; overflow: hidden; display: flex; margin: 10px 0; }
.party-bar .dem { background: #4488ff; }
.party-bar .rep { background: #ff4444; }
.party-breakdown { display: flex; justify-content: space-between; font-size: 0.9em; }
.party-breakdown .dem { color: #6af; }
.party-breakdown .rep { color: #f66; }

/* Towns */
.towns { background: #1a1a1a; }
.town-search { max-width: 500px; margin: 0 auto 30px; }
.town-search input {
    width: 100%; padding: 15px 20px; font-size: 1.1em;
    border: 2px solid #333; border-radius: 8px; background: #252525; color: #e0e0e0;
}
.town-search input:focus { outline: none; border-color: #d4af37; }
.towns-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 20px; }
.town-link { background: #252525; padding: 12px 15px; border-radius: 6px; text-align: center; transition: all 0.2s; color: #aaa; font-size: 0.95em; }
.town-link:hover { background: #2a2a2a; color: #d4af37; text-decoration: none; }
.town-link.active { background: #2a3a2a; color: #d4af37; border: 1px solid #d4af37; }
.town-link.coming-soon { opacity: 0.5; cursor: default; }

/* Contacts */
.contacts { background: #1a1a2a; }
.contacts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
.contact-card { background: #252535; padding: 25px; border-radius: 10px; border-left: 4px solid #d4af37; }
.contact-card h4 { color: #d4af37; margin-bottom: 10px; }
.contact-card .phone { font-size: 1.3em; color: #e0e0e0; margin-bottom: 8px; }
.contact-card .website { font-size: 0.9em; word-break: break-all; }
.contact-card .description { color: #888; font-size: 0.9em; margin-top: 10px; }

/* Economy / Elections */
.economy { background: #0d1117; }
.elections { background: #0a0a0a; }
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 1em; }
.stat-card { background: #1a1a2e; padding: 20px; border-radius: 8px; text-align: center; }
.stat-card .stat-value { color: #d4af37; font-size: 1.8em; font-weight: bold; }
.stat-card .stat-label { color: #888; font-size: 0.85em; margin-top: 5px; }

/* Voice */
.voice { background: #0d1117; }
.thought-form { background: #1a1a2e; padding: 25px; border-radius: 10px; margin-top: 1.5em; }
.scope-links { margin-top: 1em; color: #666; font-size: 0.9em; }
.scope-links a { color: #88c0d0; }

/* Callout box */
.callout { background: #1a2a1a; border-radius: 10px; padding: 25px; margin-top: 30px; border-left: 4px solid #d4af37; }
.callout h4 { color: #d4af37; margin-bottom: 10px; }
.callout p { color: #aaa; margin: 0; }

/* Source citation */
.source-note { color: #666; font-size: 0.85em; margin-top: 2em; padding-top: 1em; border-top: 1px solid #222; }
.source-note a { color: #88c0d0; }

/* Responsive */
@media (max-width: 768px) {
    .hero h1 { font-size: 2.2em; }
    section h2 { font-size: 1.6em; }
    .quick-facts { grid-template-columns: repeat(2, 1fr); }
}
CSS;

// Include header
require __DIR__ . '/../../includes/header.php';

// Include nav (with secondary nav)
require __DIR__ . '/../../includes/nav.php';
?>

<!-- HERO -->
<div class="hero" id="top">
    <div class="hero-content">
        <div class="state-badge">THE CONSTITUTION STATE</div>
        <h1><span>Connecticut</span></h1>
        <p class="tagline">Your Voice. Your Representatives. Your Benefits.</p>
        <p class="hero-description">
            Connecticut has 169 towns, 3.6 million residents, and billions in benefits programs most people
            don't know about. Find what you're owed, know who represents you, and make your voice count.
        </p>
        <div class="hero-cta">
            <a href="#benefits" class="btn btn-primary">Find Your Benefits</a>
            <a href="#towns" class="btn btn-secondary">Find Your Town</a>
            <a href="#voice" class="btn btn-secondary">Share Your Thought</a>
        </div>
        <?php if ($memberCount > 0 || $civicPoints > 0): ?>
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="stat-value"><?= number_format($memberCount) ?></div>
                <div class="stat-label">Members</div>
            </div>
            <div class="hero-stat">
                <div class="stat-value"><?= number_format($civicPoints) ?></div>
                <div class="stat-label">Civic Points</div>
            </div>
            <?php if ($townsActive > 0): ?>
            <div class="hero-stat">
                <div class="stat-value"><?= $townsActive ?></div>
                <div class="stat-label">Towns Active</div>
            </div>
            <?php endif; ?>
            <?php if ($activeGroups > 0): ?>
            <div class="hero-stat">
                <div class="stat-value"><?= $activeGroups ?></div>
                <div class="stat-label">Active Groups</div>
            </div>
            <?php endif; ?>
            <?php if ($activeTasks > 0): ?>
            <div class="hero-stat">
                <div class="stat-value"><?= $activeTasks ?></div>
                <div class="stat-label">Tasks</div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- OVERVIEW -->
<section id="overview">
    <h2>Connecticut at a Glance</h2>
    <p class="section-intro">
        The Constitution State — 5th to ratify the U.S. Constitution, home to the world's insurance capital,
        and the only state with no county government. Every one of Connecticut's 169 towns governs itself.
    </p>
    <div class="quick-facts">
        <div class="fact-card">
            <div class="label">Population</div>
            <div class="value">3,675,069</div>
        </div>
        <div class="fact-card">
            <div class="label">Capital</div>
            <div class="value">Hartford</div>
        </div>
        <div class="fact-card">
            <div class="label">Largest City</div>
            <div class="value">Bridgeport</div>
        </div>
        <div class="fact-card">
            <div class="label">Area</div>
            <div class="value">5,543 sq mi</div>
        </div>
        <div class="fact-card">
            <div class="label">Founded</div>
            <div class="value">1788 (5th state)</div>
        </div>
        <div class="fact-card">
            <div class="label">Electoral Votes</div>
            <div class="value">7</div>
        </div>
        <div class="fact-card">
            <div class="label">Median Income</div>
            <div class="value">$99,240</div>
        </div>
        <div class="fact-card">
            <div class="label">Towns</div>
            <div class="value">169</div>
        </div>
    </div>
    <p class="source-note">Source: <a href="https://www.census.gov/quickfacts/fact/table/CT/PST045224" target="_blank">U.S. Census Bureau QuickFacts</a></p>
</section>

<!-- BENEFITS -->
<section class="benefits" id="benefits">
    <h2>Benefits Connecticut Owes You</h2>
    <p class="section-subtitle">Programs exist. Most people don't know about them. Here's what you might qualify for.</p>

    <!-- HOUSING -->
    <div class="benefits-category" id="housing">
        <h3><span class="icon">🏠</span> First-Time Homebuyers</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Time To Own</h4>
                <div class="benefit-amount">Up to $50,000</div>
                <p>Forgivable down payment loan. 0% interest, forgiven at 10% per year. Up to 20% down + 5% closing costs in high-opportunity areas.</p>
                <a href="https://www.chfa.org/homebuyers-homeowners/homebuyers/time-to-own-down-payment-assistance-program-loan/" class="benefit-link" target="_blank">CHFA Time To Own →</a>
            </div>
            <div class="benefit-card">
                <h4>CHFA DAP Loan</h4>
                <div class="benefit-amount">Up to $20,000</div>
                <p>Down payment assistance at 1% interest. Second mortgage. Must complete free homebuyer education course.</p>
                <a href="https://www.chfa.org/homebuyers-homeowners/homebuyers/downpayment-assistance-program-dap-loan/" class="benefit-link" target="_blank">CHFA DAP Program →</a>
            </div>
            <div class="benefit-card">
                <h4>SmartMove CT</h4>
                <div class="benefit-amount">3% Interest Loan</div>
                <p>Low-interest second mortgage for down payment and closing costs. As little as 1% down required.</p>
                <a href="https://www.hdfconnects.org/" class="benefit-link" target="_blank">Housing Development Fund →</a>
            </div>
            <div class="benefit-card">
                <h4>Municipal Programs</h4>
                <div class="benefit-amount">$5K - $40K</div>
                <p>Hartford up to $40K, Bridgeport $15K, Hamden $5K matching, Fairfield varies. Check your city.</p>
                <a href="https://www.fha.com/fha-grants?state=CT" class="benefit-link" target="_blank">Find Local Programs →</a>
            </div>
        </div>
    </div>

    <!-- ENERGY -->
    <div class="benefits-category" id="energy">
        <h3><span class="icon">⚡</span> Energy Rebates & Assistance</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Heat Pump Rebate</h4>
                <div class="benefit-amount">Up to $15,000</div>
                <p>Air source heat pump installation. Reduce heating/cooling costs 25-50%. 0.99% financing available.</p>
                <a href="https://www.energizect.com/rebates-incentives/heating-cooling/heat-pumps/residential-air-source" class="benefit-link" target="_blank">Energize CT Heat Pumps →</a>
            </div>
            <div class="benefit-card">
                <h4>CEAP Heating Assistance</h4>
                <div class="benefit-amount">$295 - $645</div>
                <p>Help paying heating bills. Standard: $295-$595. Vulnerable households (age 60+, disabled, child under 6): $345-$645.</p>
                <a href="https://portal.ct.gov/heatinghelp/connecticut-energy-assistance-program-ceap" class="benefit-link" target="_blank">CT Energy Assistance →</a>
                <p class="benefit-deadline">Apply Sept 1 - May 29</p>
            </div>
            <div class="benefit-card">
                <h4>Home Energy Solutions</h4>
                <div class="benefit-amount">$75 co-pay</div>
                <p>Energy audit + weatherization. Free for income-eligible households. Insulation rebates up to $1.70/sq ft.</p>
                <a href="https://www.energizect.com/rebates-and-incentives" class="benefit-link" target="_blank">Energize CT →</a>
            </div>
            <div class="benefit-card">
                <h4>Operation Fuel</h4>
                <div class="benefit-amount">Emergency Help</div>
                <p>For those who don't qualify for CEAP. Income 151-200% of federal poverty level.</p>
                <a href="https://www.operationfuel.org/get-help/" class="benefit-link" target="_blank">Operation Fuel →</a>
            </div>
        </div>
    </div>

    <!-- SENIORS -->
    <div class="benefits-category" id="seniors">
        <h3><span class="icon">👴</span> Senior Benefits (65+)</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Property Tax Credit</h4>
                <div class="benefit-amount">Up to $1,250</div>
                <p>Circuit Breaker program. $1,250 married, $1,000 single. Income limits: $46,300 single / $56,500 married.</p>
                <a href="https://portal.ct.gov/opm/igpp/grants/tax-relief-grants/homeowners--elderlydisabled-circuit-breaker-tax-relief-program" class="benefit-link" target="_blank">CT Property Tax Relief →</a>
                <p class="benefit-deadline">Apply Feb 1 - May 15 at Town Assessor</p>
            </div>
            <div class="benefit-card">
                <h4>Medicare Savings Program</h4>
                <div class="benefit-amount">Saves $2,000+/yr</div>
                <p>Pays Medicare premiums, deductibles, co-pays. QMB income limits: $2,752/mo individual, $3,719/mo couple.</p>
                <a href="https://www.connect.ct.gov" class="benefit-link" target="_blank">Apply at connect.ct.gov →</a>
            </div>
            <div class="benefit-card">
                <h4>Renters Rebate</h4>
                <div class="benefit-amount">Partial Rebate</div>
                <p>Low-income seniors/disabled renters get partial rebate of rent and utilities. Apply at Town Assessor.</p>
                <a href="https://uwc.211ct.org/property-tax-credit-for-elderlydisabled/" class="benefit-link" target="_blank">211 CT Info →</a>
                <p class="benefit-deadline">Apply Apr 1 - Oct 1</p>
            </div>
            <div class="benefit-card">
                <h4>Free College Tuition (62+)</h4>
                <div class="benefit-amount">Tuition Waiver</div>
                <p>CT residents 62+ attend any public college tuition-free. Degree-seeking or audit. Fees still apply.</p>
                <a href="https://www.ccsu.edu/age-friendly-university" class="benefit-link" target="_blank">CCSU Age-Friendly Info →</a>
            </div>
        </div>
    </div>

    <!-- CHILDCARE -->
    <div class="benefits-category" id="childcare">
        <h3><span class="icon">👶</span> Childcare</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Care 4 Kids</h4>
                <div class="benefit-amount">Family fee capped at 7%</div>
                <p>Subsidized childcare for working families. Max fee is 7% of household income. New applicants: up to 60% of State Median Income.</p>
                <a href="https://www.ctcare4kids.com/" class="benefit-link" target="_blank">Care 4 Kids →</a>
            </div>
        </div>
    </div>

    <!-- EV -->
    <div class="benefits-category" id="ev">
        <h3><span class="icon">🚗</span> Electric Vehicle Incentives</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>CHEAPR Standard - BEV</h4>
                <div class="benefit-amount">$1,000</div>
                <p>New battery electric vehicle rebate. Applied at dealer.</p>
                <a href="https://portal.ct.gov/deep/air/mobile-sources/cheapr/cheapr---home" class="benefit-link" target="_blank">CHEAPR Program →</a>
            </div>
            <div class="benefit-card">
                <h4>CHEAPR Rebate+ New</h4>
                <div class="benefit-amount">Up to $4,000 BEV</div>
                <p>Income-qualified. $4,000 BEV, $2,000 PHEV. Pre-qualify for voucher first.</p>
                <a href="https://portal.ct.gov/deep/air/mobile-sources/cheapr/cheapr---home" class="benefit-link" target="_blank">CHEAPR Rebate+ →</a>
            </div>
            <div class="benefit-card">
                <h4>CHEAPR Rebate+ Used</h4>
                <div class="benefit-amount">Up to $5,000 BEV</div>
                <p>Income-qualified used EV. $5,000 BEV, $3,000 PHEV. Apply after purchase from licensed CT dealer.</p>
                <a href="https://portal.ct.gov/deep/air/mobile-sources/cheapr/cheapr---home" class="benefit-link" target="_blank">CHEAPR Used →</a>
            </div>
            <div class="benefit-card">
                <h4>EV Charger Rebates</h4>
                <div class="benefit-amount">$500 - $1,500</div>
                <p>Level 2 home charger installation. Varies by utility (Eversource, UI, municipal).</p>
                <a href="https://www.eversource.com/residential/save-money-energy/clean-energy-options/electric-vehicles/charging-stations/ct" class="benefit-link" target="_blank">Eversource EV →</a>
            </div>
        </div>
    </div>

    <!-- DMV -->
    <div class="benefits-category" id="dmv">
        <h3><span class="icon">🚘</span> DMV Services</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Online License Renewal</h4>
                <div class="benefit-amount">Skip the Line</div>
                <p>Renew driver's license online. Receive by mail in ~20 days.</p>
                <a href="https://portal.ct.gov/dmv/licenses-permits-ids/renew-driver-license" class="benefit-link" target="_blank">Renew Online →</a>
            </div>
            <div class="benefit-card">
                <h4>REAL ID</h4>
                <div class="benefit-amount">Required for Flights</div>
                <p>Required since May 7, 2025 for domestic air travel. Check if your license has the gold star.</p>
                <a href="https://portal.ct.gov/dmv/licenses-permits-ids/get-real-id" class="benefit-link" target="_blank">Get REAL ID →</a>
            </div>
            <div class="benefit-card">
                <h4>Military Fee Waiver</h4>
                <div class="benefit-amount">Free Renewal</div>
                <p>Active duty or honorably discharged within 2 years. CT resident when joined service.</p>
                <a href="https://portal.ct.gov/dmv" class="benefit-link" target="_blank">CT DMV →</a>
            </div>
        </div>
    </div>
</section>

<!-- FEDERAL REPS -->
<section class="officials" id="federal">
    <h2>Your Voice in Washington</h2>
    <p class="section-subtitle">Connecticut's federal delegation — all 7 members represent YOU in Congress.</p>

    <div class="officials-grid">
        <div class="official-card">
            <div class="level">U.S. Senate</div>
            <h3>Richard Blumenthal</h3>
            <div class="title">Senior Senator</div>
            <div class="party democrat">Democrat</div>
            <a href="https://www.blumenthal.senate.gov/" target="_blank">blumenthal.senate.gov</a>
        </div>
        <div class="official-card">
            <div class="level">U.S. Senate</div>
            <h3>Chris Murphy</h3>
            <div class="title">Junior Senator</div>
            <div class="party democrat">Democrat</div>
            <a href="https://www.murphy.senate.gov/" target="_blank">murphy.senate.gov</a>
        </div>
        <div class="official-card">
            <div class="level">U.S. House · CT-1</div>
            <h3>John B. Larson</h3>
            <div class="title">Representative — Hartford area</div>
            <div class="party democrat">Democrat</div>
        </div>
        <div class="official-card">
            <div class="level">U.S. House · CT-2</div>
            <h3>Joe Courtney</h3>
            <div class="title">Representative — Eastern CT</div>
            <div class="party democrat">Democrat</div>
        </div>
        <div class="official-card">
            <div class="level">U.S. House · CT-3</div>
            <h3>Rosa DeLauro</h3>
            <div class="title">Representative — New Haven area</div>
            <div class="party democrat">Democrat</div>
        </div>
        <div class="official-card">
            <div class="level">U.S. House · CT-4</div>
            <h3>Jim Himes</h3>
            <div class="title">Representative — Fairfield County</div>
            <div class="party democrat">Democrat</div>
        </div>
        <div class="official-card">
            <div class="level">U.S. House · CT-5</div>
            <h3>Jahana Hayes</h3>
            <div class="title">Representative — NW/Central CT</div>
            <div class="party democrat">Democrat</div>
        </div>
    </div>
    <p style="text-align: center; margin-top: 25px; color: #888;">
        <a href="/">Don't know your district? Find your representative on the map →</a>
    </p>
</section>

<!-- STATE OFFICIALS -->
<section class="officials" id="officials" style="background: #0a1520;">
    <h2>Connecticut State Officials</h2>
    <p class="section-subtitle">Your state-level elected officials in Hartford. All six constitutional officers.</p>

    <div class="officials-grid">
        <div class="official-card">
            <div class="level">Governor</div>
            <h3>Ned Lamont</h3>
            <div class="title">Governor of Connecticut</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/governor" target="_blank">portal.ct.gov/governor</a>
        </div>
        <div class="official-card">
            <div class="level">Lt. Governor</div>
            <h3>Susan Bysiewicz</h3>
            <div class="title">Lieutenant Governor</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/lt-governor" target="_blank">portal.ct.gov/lt-governor</a>
        </div>
        <div class="official-card">
            <div class="level">Attorney General</div>
            <h3>William Tong</h3>
            <div class="title">Attorney General</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/ag" target="_blank">portal.ct.gov/ag</a>
        </div>
        <div class="official-card">
            <div class="level">Secretary of State</div>
            <h3>Stephanie Thomas</h3>
            <div class="title">Secretary of the State</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/sots" target="_blank">portal.ct.gov/sots</a>
        </div>
        <div class="official-card">
            <div class="level">Treasurer</div>
            <h3>Erick Russell</h3>
            <div class="title">State Treasurer</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/ott" target="_blank">portal.ct.gov/ott</a>
        </div>
        <div class="official-card">
            <div class="level">Comptroller</div>
            <h3>Sean Scanlon</h3>
            <div class="title">State Comptroller</div>
            <div class="party democrat">Democrat</div>
            <a href="https://portal.ct.gov/osc" target="_blank">portal.ct.gov/osc</a>
        </div>
    </div>

    <!-- Legislature -->
    <h3 style="color: #d4af37; margin-top: 2em;">Connecticut General Assembly</h3>
    <div class="legislature-grid">
        <div class="legislature-card">
            <h4>State Senate — 36 seats</h4>
            <div class="party-bar">
                <div class="dem" style="width: 67%;"></div>
                <div class="rep" style="width: 33%;"></div>
            </div>
            <div class="party-breakdown">
                <span class="dem">Democrats: 24</span>
                <span class="rep">Republicans: 12</span>
            </div>
        </div>
        <div class="legislature-card">
            <h4>State House — 151 seats</h4>
            <div class="party-bar">
                <div class="dem" style="width: 68%;"></div>
                <div class="rep" style="width: 32%;"></div>
            </div>
            <div class="party-breakdown">
                <span class="dem">Democrats: 102</span>
                <span class="rep">Republicans: 49</span>
            </div>
        </div>
    </div>
    <p style="text-align: center; margin-top: 15px;">
        <a href="https://www.cga.ct.gov/" target="_blank" style="color: #88c0d0;">CT General Assembly →</a>
    </p>
    <p class="source-note">Source: <a href="https://portal.ct.gov/Government" target="_blank">CT.gov Government Portal</a></p>
</section>

<!-- EDUCATION -->
<section class="benefits" id="education" style="background: #0a1a0a;">
    <h2>Education</h2>
    <p class="section-subtitle">Connecticut's colleges, universities, and financial aid to help you afford them.</p>

    <div class="benefits-category">
        <h3><span class="icon">🏛️</span> Public Colleges & Universities</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>University of Connecticut</h4>
                <div class="benefit-amount">Storrs (+ 5 campuses)</div>
                <p>CT's flagship research university. 100+ majors. Regional campuses in Hartford, Stamford, Waterbury, Avery Point, Torrington.</p>
                <a href="https://uconn.edu" class="benefit-link" target="_blank">uconn.edu →</a>
            </div>
            <div class="benefit-card">
                <h4>CT State Universities (4)</h4>
                <div class="benefit-amount">~$7,000/year</div>
                <p>Central (New Britain), Eastern (Willimantic), Southern (New Haven), Western (Danbury). Strong education and professional programs.</p>
                <a href="https://www.ct.edu" class="benefit-link" target="_blank">ct.edu →</a>
            </div>
            <div class="benefit-card">
                <h4>CT State Community College</h4>
                <div class="benefit-amount">12 Campuses</div>
                <p>Affordable 2-year degrees. ~$5,200/year. Includes Quinebaug Valley (Danielson/Willimantic).</p>
                <a href="https://ctstate.edu" class="benefit-link" target="_blank">ctstate.edu →</a>
            </div>
            <div class="benefit-card">
                <h4>Charter Oak State College</h4>
                <div class="benefit-amount">100% Online</div>
                <p>CT's public online college. Flexible degrees for working adults. Credit for prior learning.</p>
                <a href="https://www.charteroak.edu" class="benefit-link" target="_blank">charteroak.edu →</a>
            </div>
        </div>
    </div>

    <div class="benefits-category">
        <h3><span class="icon">💰</span> Financial Aid & Scholarships</h3>
        <div class="benefit-cards">
            <div class="benefit-card">
                <h4>Debt-Free Community College</h4>
                <div class="benefit-amount">Mary Ann Handley Award</div>
                <p>Covers gap between grants and tuition at CT State Community College. File FAFSA and register for 6+ credits.</p>
                <a href="https://www.ct.edu/admission/free" class="benefit-link" target="_blank">ct.edu - Debt-Free Tuition →</a>
            </div>
            <div class="benefit-card">
                <h4>Roberta B. Willis Scholarship</h4>
                <div class="benefit-amount">Up to $5,250/year</div>
                <p>Need-merit scholarship for CT residents at 4-year CT colleges. SAT 1200+ or top 20%. FAFSA required.</p>
                <a href="https://portal.ct.gov/ohe/knowledge-base/articles/for-students/connecticut-student-aid-programs" class="benefit-link" target="_blank">CT Student Aid →</a>
                <p class="benefit-deadline">Apply by Feb 15</p>
            </div>
            <div class="benefit-card">
                <h4>Federal Pell Grant</h4>
                <div class="benefit-amount">Up to $7,395/year</div>
                <p>Federal grant for undergraduates with financial need. No repayment. File FAFSA.</p>
                <a href="https://studentaid.gov/understand-aid/types/grants/pell" class="benefit-link" target="_blank">studentaid.gov →</a>
            </div>
            <div class="benefit-card">
                <h4>Senior Citizens (62+)</h4>
                <div class="benefit-amount">Free Tuition</div>
                <p>CT residents 62+ can attend any public college tuition-free. Degree or audit. Fees still apply.</p>
                <a href="https://www.ccsu.edu/age-friendly-university" class="benefit-link" target="_blank">Age-Friendly Info →</a>
            </div>
        </div>
    </div>

    <div class="callout">
        <h4>Tuition Frozen for 2025-2026</h4>
        <p>CT State Colleges & Universities froze tuition for the second year in a row. State universities ~$7,000/year. Community colleges ~$5,200/year.</p>
    </div>
</section>

<!-- ECONOMY -->
<section class="economy" id="economy">
    <h2>Jobs & Economy</h2>
    <p class="section-subtitle">Connecticut's economic snapshot.</p>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value">$357B</div>
            <div class="stat-label">State GDP (2024)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">4.2%</div>
            <div class="stat-label">Unemployment (Dec 2025)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">$99,240</div>
            <div class="stat-label">Median Household Income</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">Top 5</div>
            <div class="stat-label">Highest Income State</div>
        </div>
    </div>

    <h3>Key Industries</h3>
    <div class="benefit-cards" style="margin-top: 1em;">
        <div class="benefit-card">
            <h4>Insurance & Financial Services</h4>
            <p>Hartford is the "Insurance Capital of the World." Home to Aetna, The Hartford, Cigna, Travelers, and dozens more.</p>
        </div>
        <div class="benefit-card">
            <h4>Aerospace & Defense</h4>
            <p>Pratt & Whitney (East Hartford), Sikorsky Aircraft (Stratford), Electric Boat/General Dynamics (Groton) — submarines and jet engines.</p>
        </div>
        <div class="benefit-card">
            <h4>Healthcare & Biotech</h4>
            <p>Yale New Haven Health System, Hartford HealthCare, and a growing biotech corridor along I-91.</p>
        </div>
        <div class="benefit-card">
            <h4>Higher Education</h4>
            <p>Yale, UConn, Wesleyan, Trinity, and dozens of colleges make education one of CT's largest employers.</p>
        </div>
    </div>
    <p class="source-note">Sources: <a href="https://fred.stlouisfed.org/series/CTNGSP" target="_blank">Federal Reserve (GDP)</a>, <a href="https://www.census.gov/quickfacts/CT" target="_blank">Census Bureau (income)</a></p>
</section>

<!-- ELECTIONS -->
<section class="elections" id="elections">
    <h2>Elections & Voting</h2>
    <p class="section-subtitle">Connecticut voter registration and how to make your voice count.</p>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value">2.4M</div>
            <div class="stat-label">Registered Voters</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #6af;">35%</div>
            <div class="stat-label">Democrat</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #f66;">21%</div>
            <div class="stat-label">Republican</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: #aaa;">42%</div>
            <div class="stat-label">Unaffiliated</div>
        </div>
    </div>

    <div class="callout" style="margin-top: 2em;">
        <h4>Unaffiliated voters are CT's largest bloc</h4>
        <p>Over 1 million CT voters are unaffiliated — more than either party. Connecticut allows same-day voter registration and no-excuse absentee voting.</p>
    </div>

    <div class="benefit-cards" style="margin-top: 2em;">
        <div class="benefit-card">
            <h4>Register to Vote</h4>
            <p>Online, by mail, or in person. Same-day registration available on Election Day.</p>
            <a href="https://voterregistration.ct.gov/" class="benefit-link" target="_blank">Register Online →</a>
        </div>
        <div class="benefit-card">
            <h4>Check Registration</h4>
            <p>Verify your registration status, polling place, and district information.</p>
            <a href="https://portaldir.ct.gov/sots/LookUp.aspx" class="benefit-link" target="_blank">Look Up Your Status →</a>
        </div>
        <div class="benefit-card">
            <h4>Absentee Ballot</h4>
            <p>No excuse needed. Apply online or through your town clerk.</p>
            <a href="https://portal.ct.gov/sots/election-services/voter-information/absentee-voting" class="benefit-link" target="_blank">Absentee Voting Info →</a>
        </div>
    </div>
    <p class="source-note">Source: <a href="https://portal.ct.gov/sots/election-services/statistics-and-data/statistics-and-data" target="_blank">CT Secretary of the State — Statistics</a></p>
</section>

<!-- TOWNS -->
<section class="towns" id="towns">
    <h2>Find Your Town</h2>
    <p class="section-subtitle">Connecticut has 169 towns. Find yours to see local representatives and civic issues.</p>

    <div class="town-search">
        <input type="text" id="townSearch" placeholder="Search for your town..." oninput="filterTowns()">
    </div>

    <div class="towns-grid" id="townsGrid">
        <!-- Active towns -->
        <a href="brooklyn/" class="town-link active">Brooklyn ✓</a>
        <a href="pomfret/" class="town-link active">Pomfret ✓</a>
        <a href="putnam/" class="town-link active">Putnam ✓</a>
        <a href="woodstock/" class="town-link active">Woodstock ✓</a>

        <!-- Coming soon - all 165 remaining towns alphabetical -->
        <span class="town-link coming-soon">Andover</span>
        <span class="town-link coming-soon">Ansonia</span>
        <span class="town-link coming-soon">Ashford</span>
        <span class="town-link coming-soon">Avon</span>
        <span class="town-link coming-soon">Barkhamsted</span>
        <span class="town-link coming-soon">Beacon Falls</span>
        <span class="town-link coming-soon">Berlin</span>
        <span class="town-link coming-soon">Bethany</span>
        <span class="town-link coming-soon">Bethel</span>
        <span class="town-link coming-soon">Bethlehem</span>
        <span class="town-link coming-soon">Bloomfield</span>
        <span class="town-link coming-soon">Bolton</span>
        <span class="town-link coming-soon">Bozrah</span>
        <span class="town-link coming-soon">Branford</span>
        <span class="town-link coming-soon">Bridgeport</span>
        <span class="town-link coming-soon">Bridgewater</span>
        <span class="town-link coming-soon">Bristol</span>
        <span class="town-link coming-soon">Brookfield</span>
        <span class="town-link coming-soon">Burlington</span>
        <span class="town-link coming-soon">Canaan</span>
        <span class="town-link coming-soon">Canterbury</span>
        <span class="town-link coming-soon">Canton</span>
        <span class="town-link coming-soon">Chaplin</span>
        <span class="town-link coming-soon">Cheshire</span>
        <span class="town-link coming-soon">Chester</span>
        <span class="town-link coming-soon">Clinton</span>
        <span class="town-link coming-soon">Colchester</span>
        <span class="town-link coming-soon">Colebrook</span>
        <span class="town-link coming-soon">Columbia</span>
        <span class="town-link coming-soon">Cornwall</span>
        <span class="town-link coming-soon">Coventry</span>
        <span class="town-link coming-soon">Cromwell</span>
        <span class="town-link coming-soon">Danbury</span>
        <span class="town-link coming-soon">Darien</span>
        <span class="town-link coming-soon">Deep River</span>
        <span class="town-link coming-soon">Derby</span>
        <span class="town-link coming-soon">Durham</span>
        <span class="town-link coming-soon">East Granby</span>
        <span class="town-link coming-soon">East Haddam</span>
        <span class="town-link coming-soon">East Hampton</span>
        <span class="town-link coming-soon">East Hartford</span>
        <span class="town-link coming-soon">East Haven</span>
        <span class="town-link coming-soon">East Lyme</span>
        <span class="town-link coming-soon">East Windsor</span>
        <span class="town-link coming-soon">Eastford</span>
        <span class="town-link coming-soon">Easton</span>
        <span class="town-link coming-soon">Ellington</span>
        <span class="town-link coming-soon">Enfield</span>
        <span class="town-link coming-soon">Essex</span>
        <span class="town-link coming-soon">Fairfield</span>
        <span class="town-link coming-soon">Farmington</span>
        <span class="town-link coming-soon">Franklin</span>
        <span class="town-link coming-soon">Glastonbury</span>
        <span class="town-link coming-soon">Goshen</span>
        <span class="town-link coming-soon">Granby</span>
        <span class="town-link coming-soon">Greenwich</span>
        <span class="town-link coming-soon">Griswold</span>
        <span class="town-link coming-soon">Groton</span>
        <span class="town-link coming-soon">Guilford</span>
        <span class="town-link coming-soon">Haddam</span>
        <span class="town-link coming-soon">Hamden</span>
        <span class="town-link coming-soon">Hampton</span>
        <span class="town-link coming-soon">Hartford</span>
        <span class="town-link coming-soon">Hartland</span>
        <span class="town-link coming-soon">Harwinton</span>
        <span class="town-link coming-soon">Hebron</span>
        <span class="town-link coming-soon">Kent</span>
        <span class="town-link coming-soon">Killingly</span>
        <span class="town-link coming-soon">Killingworth</span>
        <span class="town-link coming-soon">Lebanon</span>
        <span class="town-link coming-soon">Ledyard</span>
        <span class="town-link coming-soon">Lisbon</span>
        <span class="town-link coming-soon">Litchfield</span>
        <span class="town-link coming-soon">Lyme</span>
        <span class="town-link coming-soon">Madison</span>
        <span class="town-link coming-soon">Manchester</span>
        <span class="town-link coming-soon">Mansfield</span>
        <span class="town-link coming-soon">Marlborough</span>
        <span class="town-link coming-soon">Meriden</span>
        <span class="town-link coming-soon">Middlebury</span>
        <span class="town-link coming-soon">Middlefield</span>
        <span class="town-link coming-soon">Middletown</span>
        <span class="town-link coming-soon">Milford</span>
        <span class="town-link coming-soon">Monroe</span>
        <span class="town-link coming-soon">Montville</span>
        <span class="town-link coming-soon">Morris</span>
        <span class="town-link coming-soon">Naugatuck</span>
        <span class="town-link coming-soon">New Britain</span>
        <span class="town-link coming-soon">New Canaan</span>
        <span class="town-link coming-soon">New Fairfield</span>
        <span class="town-link coming-soon">New Hartford</span>
        <span class="town-link coming-soon">New Haven</span>
        <span class="town-link coming-soon">New London</span>
        <span class="town-link coming-soon">New Milford</span>
        <span class="town-link coming-soon">Newington</span>
        <span class="town-link coming-soon">Newtown</span>
        <span class="town-link coming-soon">Norfolk</span>
        <span class="town-link coming-soon">North Branford</span>
        <span class="town-link coming-soon">North Canaan</span>
        <span class="town-link coming-soon">North Haven</span>
        <span class="town-link coming-soon">North Stonington</span>
        <span class="town-link coming-soon">Norwalk</span>
        <span class="town-link coming-soon">Norwich</span>
        <span class="town-link coming-soon">Old Lyme</span>
        <span class="town-link coming-soon">Old Saybrook</span>
        <span class="town-link coming-soon">Orange</span>
        <span class="town-link coming-soon">Oxford</span>
        <span class="town-link coming-soon">Plainfield</span>
        <span class="town-link coming-soon">Plainville</span>
        <span class="town-link coming-soon">Plymouth</span>
        <span class="town-link coming-soon">Portland</span>
        <span class="town-link coming-soon">Preston</span>
        <span class="town-link coming-soon">Prospect</span>
        <span class="town-link coming-soon">Redding</span>
        <span class="town-link coming-soon">Ridgefield</span>
        <span class="town-link coming-soon">Rocky Hill</span>
        <span class="town-link coming-soon">Roxbury</span>
        <span class="town-link coming-soon">Salem</span>
        <span class="town-link coming-soon">Salisbury</span>
        <span class="town-link coming-soon">Scotland</span>
        <span class="town-link coming-soon">Seymour</span>
        <span class="town-link coming-soon">Sharon</span>
        <span class="town-link coming-soon">Shelton</span>
        <span class="town-link coming-soon">Sherman</span>
        <span class="town-link coming-soon">Simsbury</span>
        <span class="town-link coming-soon">Somers</span>
        <span class="town-link coming-soon">South Windsor</span>
        <span class="town-link coming-soon">Southbury</span>
        <span class="town-link coming-soon">Southington</span>
        <span class="town-link coming-soon">Sprague</span>
        <span class="town-link coming-soon">Stafford</span>
        <span class="town-link coming-soon">Stamford</span>
        <span class="town-link coming-soon">Sterling</span>
        <span class="town-link coming-soon">Stonington</span>
        <span class="town-link coming-soon">Stratford</span>
        <span class="town-link coming-soon">Suffield</span>
        <span class="town-link coming-soon">Thomaston</span>
        <span class="town-link coming-soon">Thompson</span>
        <span class="town-link coming-soon">Tolland</span>
        <span class="town-link coming-soon">Torrington</span>
        <span class="town-link coming-soon">Trumbull</span>
        <span class="town-link coming-soon">Union</span>
        <span class="town-link coming-soon">Vernon</span>
        <span class="town-link coming-soon">Voluntown</span>
        <span class="town-link coming-soon">Wallingford</span>
        <span class="town-link coming-soon">Warren</span>
        <span class="town-link coming-soon">Washington</span>
        <span class="town-link coming-soon">Waterbury</span>
        <span class="town-link coming-soon">Waterford</span>
        <span class="town-link coming-soon">Watertown</span>
        <span class="town-link coming-soon">West Hartford</span>
        <span class="town-link coming-soon">West Haven</span>
        <span class="town-link coming-soon">Westbrook</span>
        <span class="town-link coming-soon">Weston</span>
        <span class="town-link coming-soon">Westport</span>
        <span class="town-link coming-soon">Wethersfield</span>
        <span class="town-link coming-soon">Willington</span>
        <span class="town-link coming-soon">Wilton</span>
        <span class="town-link coming-soon">Winchester</span>
        <span class="town-link coming-soon">Windham</span>
        <span class="town-link coming-soon">Windsor</span>
        <span class="town-link coming-soon">Windsor Locks</span>
        <span class="town-link coming-soon">Wolcott</span>
        <span class="town-link coming-soon">Woodbridge</span>
        <span class="town-link coming-soon">Woodbury</span>
    </div>

    <p style="text-align: center; margin-top: 30px; color: #888;">
        Want your town added? <a href="/volunteer/">Volunteer to help build it →</a>
    </p>
</section>

<!-- KEY CONTACTS -->
<section class="contacts" id="contacts">
    <h2>Key Contacts</h2>
    <p class="section-subtitle">When you need help, these are the numbers to call.</p>

    <div class="contacts-grid">
        <div class="contact-card">
            <h4>211 Connecticut</h4>
            <div class="phone">2-1-1</div>
            <div class="website"><a href="https://211ct.org" target="_blank">211ct.org</a></div>
            <p class="description">All services directory. Housing, food, healthcare, utilities, childcare, and more.</p>
        </div>
        <div class="contact-card">
            <h4>DSS Benefits Center</h4>
            <div class="phone">1-855-626-6632</div>
            <div class="website"><a href="https://portal.ct.gov/dss" target="_blank">portal.ct.gov/dss</a></div>
            <p class="description">SNAP, cash assistance, Medicaid, childcare subsidies.</p>
        </div>
        <div class="contact-card">
            <h4>Energy Assistance</h4>
            <div class="phone">1-800-842-1132</div>
            <div class="website"><a href="https://portal.ct.gov/heatinghelp" target="_blank">ct.gov/heatinghelp</a></div>
            <p class="description">CEAP heating assistance. Apply Sept through May.</p>
        </div>
        <div class="contact-card">
            <h4>CHFA Housing</h4>
            <div class="phone">1-860-571-3502</div>
            <div class="website"><a href="https://www.chfa.org" target="_blank">chfa.org</a></div>
            <p class="description">First-time homebuyer programs, down payment assistance.</p>
        </div>
        <div class="contact-card">
            <h4>Energize CT</h4>
            <div class="phone">1-877-WISE-USE</div>
            <div class="website"><a href="https://www.energizect.com" target="_blank">energizect.com</a></div>
            <p class="description">Energy rebates, home efficiency, heat pumps, weatherization.</p>
        </div>
        <div class="contact-card">
            <h4>CT DMV</h4>
            <div class="phone">1-800-842-8222</div>
            <div class="website"><a href="https://portal.ct.gov/dmv" target="_blank">portal.ct.gov/dmv</a></div>
            <p class="description">License, registration, REAL ID. Many services available online.</p>
        </div>
    </div>
</section>

<!-- GET INVOLVED -->
<section id="voice">
    <h3>Get Involved</h3>
    <ul style="color: #ccc; line-height: 1.8;">
        <li><a href="/talk/?state=7" class="external-link">Join the conversation on Talk</a> — share ideas for Connecticut</li>
        <li><a href="/volunteer/" class="external-link">Volunteer with TPB</a> — Help build civic infrastructure</li>
        <li><a href="#towns">Find your town page</a></li>
    </ul>
</section>

<!-- SOURCE FOOTER -->
<div style="max-width: 1100px; margin: 0 auto; padding: 20px; text-align: center; color: #555; font-size: 0.85em;">
    <p>Information compiled from official CT .gov sources. Always verify with agencies before making decisions.</p>
    <p>Built: February 2026 | Task: <a href="/volunteer/task.php?key=build-state-ct" style="color: #888;">build-state-ct</a></p>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

<script>
// Town search filter
function filterTowns() {
    const search = document.getElementById('townSearch').value.toLowerCase();
    const towns = document.querySelectorAll('.town-link');
    towns.forEach(town => {
        const name = town.textContent.toLowerCase();
        town.style.display = name.includes(search) ? '' : 'none';
    });
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
</script>
