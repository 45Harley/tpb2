<?php
/**
 * Putnam, CT - Town Page (Prototype v1)
 * ======================================
 * Model town page with secondary nav and comprehensive sections
 * 
 * Sections:
 *   - Overview (what/where is Putnam)
 *   - History
 *   - Government (boards, departments, officials)
 *   - Budget & Taxes (links to TA Reports)
 *   - Schools
 *   - Living Here (shopping, dining, recreation)
 *   - Your Voice (thoughts, get involved)
 */

// Bootstrap
$config = require __DIR__ . '/../../../config.php';

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

// Town constants
$townId = 119;
$townName = 'Putnam';
$townSlug = 'putnam';
$stateAbbr = 'ct';
$stateId = 7;

// Load user data
require_once __DIR__ . '/../../../includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

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
$defaultIsLocal = true; // Pre-check local for town pages

// Page config
$currentPage = 'town';
$pageTitle = 'Putnam CT - A More Perfect Town | The People\'s Branch';

// =====================================================
// SECONDARY NAV - Town-specific navigation
// =====================================================
$secondaryNavBrand = 'Putnam';
$secondaryNav = [
    ['label' => 'Overview', 'anchor' => 'overview'],
    ['label' => 'History', 'anchor' => 'history'],
    ['label' => 'Government', 'anchor' => 'government'],
    ['label' => 'Calendar', 'url' => 'calendar.php', 'target' => '_blank'],
    ['label' => 'Budget', 'anchor' => 'budget'],
    ['label' => 'Schools', 'anchor' => 'schools'],
    ['label' => 'School Budget', 'url' => 'putnam-schools-budget.html'],
    ['label' => 'Living Here', 'anchor' => 'living'],
    ['label' => 'Putnam Talk', 'url' => '/talk/?town=119'],
];

// =====================================================
// LOAD DATA FROM DATABASE
// =====================================================

// Boards with vacancies
$stmt = $pdo->prepare("
    SELECT bd.branch_id, bd.branch_name, bd.branch_type, bd.vacancies, bd.total_seats,
           bd.contact_name, bd.contact_email, bd.contact_phone, bd.meeting_schedule, bd.website
    FROM branches_departments bd
    JOIN governing_organizations go ON bd.org_id = go.org_id
    WHERE go.town_id = ?
    ORDER BY bd.branch_type, bd.branch_name
");
$stmt->execute([$townId]);
$allBranches = $stmt->fetchAll();

// Separate boards from departments
$boards = array_filter($allBranches, fn($b) => in_array($b['branch_type'], ['Board', 'Commission', 'Committee', 'Authority']));
$departments = array_filter($allBranches, fn($b) => $b['branch_type'] === 'Department');

// Count vacancies
$totalVacancies = array_sum(array_column($allBranches, 'vacancies'));
$boardsWithVacancies = array_filter($boards, fn($b) => $b['vacancies'] > 0);

// Civic metrics for hero
$stmtMetrics = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(civic_points),0) as pts FROM users WHERE current_town_id = ?");
$stmtMetrics->execute([$townId]);
$metricRow = $stmtMetrics->fetch();
$memberCount = (int)$metricRow['cnt'];
$civicPoints = (int)$metricRow['pts'];

$stmtGroups = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM idea_groups g
    WHERE g.created_by IN (SELECT user_id FROM users WHERE current_town_id = ?)
    AND g.status IN ('active','crystallizing','crystallized')
");
$stmtGroups->execute([$townId]);
$activeGroups = (int)$stmtGroups->fetch()['cnt'];

$stmtTasks = $pdo->prepare("SELECT COUNT(*) as cnt FROM tasks WHERE task_key LIKE ? AND status IN ('claimed','in_progress','review','completed')");
$stmtTasks->execute(['%-' . $townSlug . '-%']);
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
.external-link { color: #88c0d0; }
.external-link::after { content: ' ↗'; font-size: 0.8em; opacity: 0.7; }

/* Hero */
.hero {
    background: linear-gradient(135deg, #1a2a1a 0%, #0a1a2a 100%);
    text-align: center;
    padding: 80px 20px;
}
.hero h1 { font-size: 2.5em; color: #fff; margin-bottom: 0.3em; }
.hero h1 span { color: #d4af37; }
.hero .tagline { color: #aaa; font-size: 1.3em; font-style: italic; }
.town-badge {
    display: inline-block;
    background: rgba(212, 175, 55, 0.15);
    border: 1px solid #d4af37;
    padding: 6px 20px;
    border-radius: 20px;
    font-size: 0.85em;
    color: #d4af37;
    letter-spacing: 1px;
    margin-bottom: 15px;
}
.hero-stats {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 20px;
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

/* Overview */
.overview { background: #0d1117; }
.quick-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

/* History */
.history { background: #0a0a0a; }
.history audio { width: 100%; margin-top: 1em; }

/* Government */
.government { background: #0d1117; }
.gov-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 15px;
    margin-top: 1em;
}
.gov-card {
    background: #1a1a2e;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #4a7c4a;
}
.gov-card.has-vacancy { border-left-color: #f39c12; }
.gov-card h4 { color: #7cb77c; margin: 0 0 8px 0; font-size: 1em; }
.gov-card .meta { color: #888; font-size: 0.85em; }
.gov-card .vacancy-badge {
    display: inline-block;
    background: #f39c12;
    color: #000;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 0.75em;
    font-weight: bold;
    margin-left: 8px;
}
.vacancy-cta {
    background: linear-gradient(135deg, #2a1a0a, #1a1a0a);
    border: 1px solid #f39c12;
    padding: 20px;
    border-radius: 10px;
    margin-top: 2em;
    text-align: center;
}
.vacancy-cta h3 { color: #f39c12; margin-top: 0; }

/* Budget */
.budget { background: #0a0a0a; }
.budget-link {
    display: inline-block;
    background: #d4af37;
    color: #000;
    padding: 12px 25px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
    margin-top: 1em;
}
.budget-link:hover { background: #e4bf47; }

/* Schools */
.schools { background: #0d1117; }

/* Living */
.living { background: #0a0a0a; }
.link-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 1em;
}
.link-card {
    background: #1a1a2e;
    padding: 15px;
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s;
}
.link-card:hover { transform: translateY(-2px); }
.link-card h4 { color: #d4af37; margin: 0 0 5px 0; }
.link-card p { color: #888; margin: 0; font-size: 0.9em; }

/* Voice */
.voice { background: #0d1117; }
.thought-form {
    background: #1a1a2e;
    padding: 25px;
    border-radius: 10px;
    margin-top: 1.5em;
}
.thought-form textarea {
    width: 100%;
    padding: 15px;
    background: #252535;
    border: 1px solid #444;
    border-radius: 8px;
    color: #fff;
    font-size: 1em;
    resize: vertical;
    min-height: 100px;
}
.thought-form button {
    background: #d4af37;
    color: #000;
    border: none;
    padding: 12px 25px;
    border-radius: 8px;
    font-size: 1em;
    cursor: pointer;
    margin-top: 10px;
}
.thought-form button:hover { background: #e4bf47; }
.scope-links {
    margin-top: 1em;
    color: #666;
    font-size: 0.9em;
}
.scope-links a {
    color: #88c0d0;
}

/* Thoughts list */
.thoughts-container {
    background: #1a1a2e;
    padding: 25px;
    border-radius: 10px;
    margin-bottom: 1.5em;
}
.thoughts-list {
    max-height: 400px;
    overflow-y: auto;
}
.thought-card {
    background: #252535;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 3px solid #4a7c4a;
}
.thought-content {
    color: #e0e0e0;
    margin-bottom: 10px;
    line-height: 1.5;
}
.thought-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85em;
    color: #888;
}
.thought-votes {
    display: flex;
    gap: 10px;
}
.vote-btn {
    background: #333;
    border: 1px solid #444;
    color: #aaa;
    padding: 4px 10px;
    border-radius: 15px;
    cursor: pointer;
    font-size: 0.85em;
    transition: all 0.2s;
}
.vote-btn:hover {
    background: #444;
    color: #fff;
}
.vote-btn.agree:hover { border-color: #4a7c4a; }
.vote-btn.disagree:hover { border-color: #a54; }
.empty-state {
    text-align: center;
    padding: 30px;
    color: #666;
}
.empty-state .icon { font-size: 2em; margin-bottom: 10px; }
CSS;

// Include header
require __DIR__ . '/../../../includes/header.php';

// Include nav (with secondary nav)
require __DIR__ . '/../../../includes/nav.php';
?>

<!-- HERO -->
<section class="hero" id="top">
    <div class="town-badge">THE QUIET CORNER • CONNECTICUT</div>
    <h1>Putnam: <span>A More Perfect Town</span></h1>
    <p class="tagline">Where the Quinebaug flows and neighbors know each other</p>
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
    <p style="margin-top: 1em;"><a href="/putnam-vision.html" style="color: #d4af37; font-size: 1.1em; text-decoration: none; border-bottom: 1px solid #d4af3766;">✨ Just Imagine — The Vision for Putnam</a></p>
</section>

<!-- OVERVIEW -->
<section class="overview" id="overview">
    <h2>Overview</h2>
    <p class="section-intro">
        Putnam is a town in Windham County, Connecticut, nestled in the northeast corner of the state 
        along the Quinebaug River. Known as the "Antiques Capital of the Northeast," it's a New England 
        mill town that has reinvented itself as a vibrant arts and dining destination.
    </p>
    
    <div class="quick-facts">
        <div class="fact-card">
            <div class="label">Population</div>
            <div class="value">9,347</div>
        </div>
        <div class="fact-card">
            <div class="label">Area</div>
            <div class="value">20.4 sq mi</div>
        </div>
        <div class="fact-card">
            <div class="label">Incorporated</div>
            <div class="value">1855</div>
        </div>
        <div class="fact-card">
            <div class="label">Named For</div>
            <div class="value">Gen. Israel Putnam</div>
        </div>
        <div class="fact-card">
            <div class="label">Nearest Metro</div>
            <div class="value">Worcester, MA (30 mi)</div>
        </div>
        <div class="fact-card">
            <div class="label">Nearest Airport</div>
            <div class="value">Bradley Intl (50 mi)</div>
        </div>
    </div>
    
    <h3>Location</h3>
    <p>
        Nestled along Interstate 395, Putnam borders Woodstock and Thompson to the north, 
        Rhode Island to the east, Killingly to the south, and Pomfret to the west.
        <a href="https://www.putnamct.us/visit-us/about-us" class="external-link" target="_blank">Official Town Info</a>
    </p>
</section>

<!-- HISTORY -->
<section class="history" id="history">
    <h2>History</h2>
    <p class="section-intro">
        From Native American lands to mill town to antiques capital — Putnam has reinvented itself many times.
    </p>
    
    <p>
        Originally known as Aspinock, the area was first settled around 1691. The first cotton textile mill 
        in Windham County was built here in 1806 by Smith Wilkinson. The town was incorporated in 1855, 
        carved from sections of Killingly, Pomfret, and Thompson, and named for Revolutionary War General 
        Israel Putnam — famous for "Don't fire until you see the whites of their eyes."
    </p>
    
    <p>
        In August 1955, Putnam was devastated by floods from Hurricanes Connie and Diane. The town rebuilt 
        and, toward the end of the 20th century, transformed its empty mills into a thriving antiques center. 
        Today, Main Street features restaurants, boutiques, galleries, and the historic Bradley Playhouse.
    </p>
    
    <h3>🎧 Hear Putnam's Story</h3>
    <audio controls>
        <source src="putnam-history.mp3" type="audio/mpeg">
        Your browser doesn't support audio.
    </audio>
    
    <p style="margin-top: 1em;">
        <a href="https://en.wikipedia.org/wiki/Putnam,_Connecticut" class="external-link" target="_blank">Full History (Wikipedia)</a> · 
        <a href="https://nectchamber.com/putnam/" class="external-link" target="_blank">Chamber of Commerce</a>
    </p>
</section>

<!-- GOVERNMENT -->
<section class="government" id="government">
    <h2>Government</h2>
    <p class="section-intro">
        Putnam operates with a Mayor, Board of Selectmen, and Town Administrator. 
        <?= count($boards) ?> boards/commissions and <?= count($departments) ?> departments serve the town.
    </p>
    
    <p>
        <strong>Mayor:</strong> Barney Seney (since 2017)<br>
        <strong>Town Administrator:</strong> Elaine Sistare · 
        <a href="https://www.putnamct.us/government/town-administrator" class="external-link" target="_blank">Office</a><br>
        <strong>Town Hall:</strong> 200 School Street · (860) 963-6800
    </p>
    
    <?php if ($totalVacancies > 0): ?>
    <div class="vacancy-cta">
        <h3>🙋 <?= $totalVacancies ?> Board Seats Need You!</h3>
        <p>Putnam has <?= $totalVacancies ?> vacancies across <?= count($boardsWithVacancies) ?> boards. Your voice matters — serve your town!</p>
    </div>
    <?php endif; ?>
    
    <h3>Boards & Commissions</h3>
    <div class="gov-grid">
        <?php foreach ($boards as $board): ?>
        <div class="gov-card <?= $board['vacancies'] > 0 ? 'has-vacancy' : '' ?>">
            <h4>
                <?= htmlspecialchars($board['branch_name']) ?>
                <?php if ($board['vacancies'] > 0): ?>
                <span class="vacancy-badge"><?= $board['vacancies'] ?> vacancy</span>
                <?php endif; ?>
            </h4>
            <div class="meta">
                <?php if ($board['meeting_schedule']): ?>
                <?= htmlspecialchars($board['meeting_schedule']) ?><br>
                <?php endif; ?>
                <?php if ($board['contact_phone']): ?>
                <?= htmlspecialchars($board['contact_phone']) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <h3>Departments</h3>
    <div class="gov-grid">
        <?php foreach ($departments as $dept): ?>
        <div class="gov-card">
            <h4><?= htmlspecialchars($dept['branch_name']) ?></h4>
            <div class="meta">
                <?php if ($dept['contact_name']): ?>
                <?= htmlspecialchars($dept['contact_name']) ?><br>
                <?php endif; ?>
                <?php if ($dept['contact_phone']): ?>
                <?= htmlspecialchars($dept['contact_phone']) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <p style="margin-top: 1.5em;">
        <a href="https://www.putnamct.us/departments" class="external-link" target="_blank">All Departments</a> · 
        <a href="https://onboard.putnamct.us/" class="external-link" target="_blank">Board Vacancies (OnBoard)</a> · 
        <a href="putnam-town-code.html">Town Code Reference</a>
    </p>
</section>

<!-- BUDGET -->
<section class="budget" id="budget">
    <h2>Budget & Taxes</h2>
    <p class="section-intro">
        See where your tax dollars and state grant money are spent. Town Administrator Elaine Sistare 
        provides monthly reports tracking 17+ major projects.
    </p>
    
    <a href="putnam-ta-compact.html" class="budget-link">📊 View TA Reports & Project Tracker</a>
    
    <h3>Key Projects (2025-2026)</h3>
    <ul style="color: #ccc; line-height: 1.8;">
        <li><strong>EMS Facility</strong> — New unit onsite at 191 Church Street</li>
        <li><strong>Tech Park</strong> — Special Town Meeting Jan 29, 2026</li>
        <li><strong>Track Improvements</strong> — $1M STEAP grant awarded</li>
        <li><strong>Danco Drive Bridge</strong> — Construction ongoing</li>
        <li><strong>WPCA Referendum</strong> — Expected March/April 2026</li>
    </ul>
    
    <p style="margin-top: 1em;">
        <a href="https://www.putnamct.us/departments/finance" class="external-link" target="_blank">Finance Department</a> · 
        <a href="https://www.putnamct.us/departments/revenue-collector" class="external-link" target="_blank">Tax Info</a>
    </p>
</section>

<!-- SCHOOLS -->
<section class="schools" id="schools">
    <h2>Schools</h2>
    <p class="section-intro">
        Putnam's public school system serves about 1,078 students from Pre-K through 12th grade.
    </p>
    
    <h3>Public Schools</h3>
    <ul style="color: #ccc; line-height: 1.8;">
        <li><strong>Putnam Elementary School</strong> — Grades Pre-K through 4 (437 students)</li>
        <li><strong>Putnam Middle School</strong> — Grades 5-8 (364 students)</li>
        <li><strong>Putnam High School</strong> — Grades 9-12 (277 students)</li>
    </ul>
    
    <div style="background: #1a1a2e; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 4px solid #d4af37;">
        <h3 style="color: #d4af37; margin-top: 0;">📊 FY26 School Budget</h3>
        <p style="color: #ccc; margin-bottom: 15px;">
            <strong style="font-size: 1.3em;">$21,934,750</strong> 
            <span style="color: #f39c12;">(+4.66% / +$976,843)</span>
        </p>
        <p style="color: #888; font-size: 0.9em; margin-bottom: 15px;">
            Budget approved Feb 11, 2025 by Board of Education. Includes staff reductions, 
            increased special ed costs, and unfunded requests for intervention support.
        </p>
        <a href="putnam-schools-budget.html" style="display: inline-block; background: #d4af37; color: #000; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold;">
            View Full School Budget Analysis →
        </a>
    </div>
    
    <h3>Higher Education</h3>
    <p style="color: #ccc;">
        <strong>CT State Quinebaug Valley</strong> — Community college offering associate degrees, 
        certificates, and workforce training.
        <a href="https://ctstate.edu/" class="external-link" target="_blank">Website</a>
    </p>
    
    <p style="margin-top: 1em;">
        <a href="https://www.putnam.k12.ct.us/" class="external-link" target="_blank">Putnam Public Schools</a>
    </p>
</section>

<!-- LIVING HERE -->
<section class="living" id="living">
    <h2>Living Here</h2>
    <p class="section-intro">
        Shopping, dining, recreation, and community — what makes Putnam home.
    </p>
    
    <div class="link-grid">
        <a href="https://www.discoverputnam.com/" class="link-card" target="_blank">
            <h4>Discover Putnam ↗</h4>
            <p>Events, dining, shopping — the business association guide</p>
        </a>
        <a href="https://ctvisit.com/listings/town-putnam" class="link-card" target="_blank">
            <h4>CT Visit ↗</h4>
            <p>Tourism info, attractions, outdoor recreation</p>
        </a>
        <a href="https://www.facebook.com/TheBradleyPlayhouse" class="link-card" target="_blank">
            <h4>Bradley Playhouse ↗</h4>
            <p>Historic theater since 1901 — plays, concerts, events</p>
        </a>
        <a href="https://www.putnamct.us/visit-us/putnam-public-library" class="link-card" target="_blank">
            <h4>Public Library ↗</h4>
            <p>Programs for all ages, community rooms</p>
        </a>
        <a href="https://www.putnamct.us/departments/parks-and-recreation" class="link-card" target="_blank">
            <h4>Parks & Recreation ↗</h4>
            <p>River Trail, parks, senior programming</p>
        </a>
        <a href="https://www.putnampolice.com/" class="link-card" target="_blank">
            <h4>Police Department ↗</h4>
            <p>Community partnerships, safety resources</p>
        </a>
    </div>
    
    <h3>Weather & Climate</h3>
    <p style="color: #ccc;">
        Putnam has a humid continental climate with warm summers and cold, snowy winters. 
        The town experienced devastating flooding in August 1955 when Hurricanes Connie and Diane 
        dropped over 14 inches of rain, causing the Quinebaug River to overflow and destroy bridges, 
        mills, and neighborhoods — $6 million in damage, the Belding-Hemingway magnesium plant exploding 
        in a spectacular fire visible for miles.
    </p>
    <p style="color: #ccc; margin-top: 10px;">
        The Army Corps of Engineers built the <strong>West Thompson Dam</strong> upstream on the Quinebaug 
        for flood control. Since the dam was completed, the river at Putnam has never reached flood stage 
        (10 feet) — compared to the 26.5-foot crest in 1955. The highest since: 8.67 feet in March 1998.
    </p>
</section>

<!-- GET INVOLVED -->
<section id="voice">
    <h3>Get Involved</h3>
    <ul style="color: #ccc; line-height: 1.8;">
        <li><a href="/talk/?town=119" class="external-link">Join the conversation on Talk</a> — share ideas for Putnam</li>
        <li><a href="https://onboard.putnamct.us/" class="external-link" target="_blank">Apply for a Board Seat</a> — <?= $totalVacancies ?> vacancies need you</li>
        <li><a href="/volunteer/" class="external-link">Volunteer with TPB</a> — Help build civic infrastructure</li>
        <li><a href="https://www.putnamct.us/government/mayors-office/elected-officials" class="external-link" target="_blank">Contact Your Officials</a></li>
    </ul>
</section>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
