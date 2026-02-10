<?php
/**
 * Putnam, CT - Town Page
 * ======================
 * The model town - woven into the civic fabric
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

// Session handling
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

// Load user data
require_once __DIR__ . '/../../../includes/get-user.php';
$dbUser = $sessionId ? getUserBySession($pdo, $sessionId) : null;

// Calculate trust level for nav
$trustLevel = 'Visitor';
$userTrustLevel = 0;
if ($dbUser) {
    if (!empty($dbUser['phone_verified'])) {
        $trustLevel = 'Verified (2FA)';
        $userTrustLevel = 3;
    } elseif (!empty($dbUser['email_verified'])) {
        $trustLevel = 'Email Verified';
        $userTrustLevel = 2;
    } elseif (!empty($dbUser['email'])) {
        $trustLevel = 'Registered';
        $userTrustLevel = 1;
    }
}

// Nav variables
$isLoggedIn = (bool)$dbUser;
$points = $dbUser ? (int)$dbUser['civic_points'] : 0;
$userEmail = $dbUser['email'] ?? '';
$userTownName = $dbUser['town_name'] ?? '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = strtolower($dbUser['state_abbrev'] ?? '');
$userStateDisplay = strtoupper($userStateAbbr);

// Page config
$currentPage = 'town';
$pageTitle = 'Putnam CT - A More Perfect Town | The People\'s Branch';

// Load town data from DB
// Boards with vacancies
$stmt = $pdo->prepare("
    SELECT bd.branch_id, bd.branch_name, bd.branch_type, bd.vacancies, bd.total_seats
    FROM branches_departments bd
    JOIN governing_organizations go ON bd.org_id = go.org_id
    WHERE go.town_id = ? AND bd.vacancies > 0
    ORDER BY bd.vacancies DESC
    LIMIT 10
");
$stmt->execute([$townId]);
$boardsWithVacancies = $stmt->fetchAll();

// Total vacancy count
$totalVacancies = array_sum(array_column($boardsWithVacancies, 'vacancies'));

// Yellow Pages listings (if any)
$stmt = $pdo->prepare("
    SELECT dl.*, sc.description as sic_description, sc.major_group_desc
    FROM directory_listings dl
    JOIN sic_codes sc ON dl.sic_code = sc.sic_code
    WHERE dl.town_id = ? AND dl.is_active = 1
    ORDER BY dl.created_at DESC
    LIMIT 12
");
$stmt->execute([$townId]);
$listings = $stmt->fetchAll();

// Page-specific styles (kept from original)
$pageStyles = <<<'CSS'
/* HERO */
.hero {
    position: relative;
    min-height: 80vh;
    background: linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.6)),
                linear-gradient(135deg, #1a2a1a 0%, #0a1a2a 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: white;
    padding: 40px 20px;
}

.hero-content {
    max-width: 900px;
    animation: fadeIn 1s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.town-badge {
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

.hero h1 {
    font-size: 3.2em;
    margin-bottom: 15px;
    text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
    font-weight: normal;
    line-height: 1.2;
}

.hero h1 span {
    color: #d4af37;
}

.hero .tagline {
    font-size: 1.5em;
    margin-bottom: 25px;
    color: #ccc;
    font-style: italic;
}

.hero-description {
    font-size: 1.15em;
    color: #aaa;
    max-width: 700px;
    margin: 0 auto 35px;
    line-height: 1.7;
}

.hero-cta {
    margin-top: 30px;
}

/* Pulsing Audio Badge */
@keyframes badge-pulse {
    0%, 100% { 
        box-shadow: 0 0 0 0 rgba(212, 175, 55, 0.4);
        transform: scale(1);
    }
    50% { 
        box-shadow: 0 0 20px 10px rgba(212, 175, 55, 0);
        transform: scale(1.02);
    }
}

.audio-badge:hover {
    background: linear-gradient(135deg, #3a2a4a 0%, #2a3a4a 100%) !important;
    animation: none !important;
    transform: scale(1.05);
}

.btn {
    padding: 16px 35px;
    font-size: 1.1em;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    font-weight: bold;
    margin: 8px;
}

.btn-primary {
    background: #d4af37;
    color: #1a1a1a;
}

.btn-primary:hover {
    background: #f4cf57;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3);
}

.btn-secondary {
    background: transparent;
    color: #d4af37;
    border: 2px solid #d4af37;
}

.btn-secondary:hover {
    background: rgba(212, 175, 55, 0.1);
}

.scroll-indicator {
    position: absolute;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    font-size: 2em;
    color: white;
    animation: bounce 2s infinite;
    opacity: 0.7;
}

@keyframes bounce {
    0%, 100% { transform: translateX(-50%) translateY(0); }
    50% { transform: translateX(-50%) translateY(-10px); }
}

/* SECTIONS */
section {
    max-width: 1100px;
    margin: 0 auto;
    padding: 80px 20px;
}

section h2 {
    font-size: 2.2em;
    margin-bottom: 20px;
    color: #d4af37;
    text-align: center;
}

.section-intro {
    text-align: center;
    font-size: 1.2em;
    max-width: 700px;
    margin: 0 auto 50px;
    color: #aaa;
    line-height: 1.7;
}

/* WHAT MAKES PUTNAM SPECIAL */
.special {
    background: #0f1a0f;
    color: #e0e0e0;
}

.special-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 25px;
}

.special-card {
    background: #1a2a1a;
    padding: 30px;
    border-radius: 10px;
    border-left: 4px solid #4a7c4a;
    text-decoration: none;
    color: inherit;
    display: block;
    cursor: pointer;
    transition: all 0.2s ease;
}

.special-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    border-left-color: #7cb77c;
}

.special-card h3 {
    color: #7cb77c;
    margin-bottom: 12px;
    font-size: 1.25em;
}

.special-card p {
    color: #aaa;
    font-size: 0.95em;
    line-height: 1.6;
}

/* WHAT WE'RE BUILDING */
.building {
    background: #1a1a2a;
    color: #e0e0e0;
}

.building-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
}

.building-card {
    background: #252535;
    padding: 28px;
    border-radius: 10px;
    border-left: 4px solid #d4af37;
    transition: all 0.2s ease;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    display: block;
}

.building-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
    border-left-color: #fff;
}

.building-card .card-type {
    font-size: 0.8em;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.building-card h3 {
    color: #d4af37;
    margin-bottom: 10px;
    font-size: 1.15em;
}

.building-card p {
    color: #999;
    font-size: 0.95em;
    line-height: 1.5;
}

/* VACANCIES */
.vacancies {
    background: #1a0f1a;
    color: #e0e0e0;
}

.vacancy-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.vacancy-card {
    background: #2a1a2a;
    padding: 20px;
    border-radius: 10px;
    border-left: 4px solid #c77dff;
}

.vacancy-card h4 {
    color: #c77dff;
    margin-bottom: 8px;
}

.vacancy-card .seats {
    color: #4caf50;
    font-weight: bold;
}

/* YELLOW PAGES */
.yellow-pages {
    background: #1a1a0f;
    color: #e0e0e0;
}

.listing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
}

.listing-card {
    background: #2a2a1a;
    padding: 20px;
    border-radius: 10px;
    border-left: 4px solid #d4af37;
}

.listing-card h4 {
    color: #d4af37;
    margin-bottom: 5px;
}

.listing-card .category {
    color: #888;
    font-size: 0.85em;
    margin-bottom: 10px;
}

.listing-card .contact {
    font-size: 0.9em;
    color: #aaa;
}

/* WHO WE NEED */
.who {
    background: #0f1a1a;
    color: #e0e0e0;
}

.who-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.who-card {
    background: #1a2a2a;
    padding: 25px;
    border-radius: 10px;
    text-align: center;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.who-card:hover {
    transform: translateY(-3px);
    border-color: #d4af37;
}

.who-icon {
    font-size: 2.5em;
    margin-bottom: 15px;
}

.who-card h3 {
    color: #d4af37;
    font-size: 1.1em;
    margin-bottom: 8px;
}

.who-card p {
    color: #888;
    font-size: 0.9em;
}

/* REPS */
.reps {
    background: #0a1520;
    color: #e0e0e0;
}

.reps-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
}

.rep-card {
    background: #1a2530;
    padding: 25px;
    border-radius: 10px;
    border: 1px solid #2a3540;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
}

.rep-card:hover {
    border-color: #d4af37;
}

.rep-level {
    font-size: 0.75em;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
}

.rep-card h3 {
    color: #d4af37;
    margin-bottom: 5px;
    font-size: 1.1em;
}

.rep-title {
    color: #999;
    font-size: 0.9em;
    font-style: italic;
}

.rep-link {
    color: #4a90a4;
    font-size: 0.85em;
    margin-top: 10px;
}

/* CTA / THOUGHT FORM */
.cta {
    background: linear-gradient(135deg, #1a1a2e 0%, #2a1a3a 100%);
    color: #e0e0e0;
    text-align: center;
}

.cta h2 {
    color: #d4af37;
}

.cta-subtitle {
    color: #aaa;
    font-size: 1.1em;
    margin-bottom: 30px;
}

.thought-input-container {
    max-width: 600px;
    margin: 0 auto;
    text-align: left;
}

.thought-input {
    width: 100%;
    min-height: 120px;
    padding: 15px;
    font-size: 1.05em;
    border: 2px solid #333;
    border-radius: 10px;
    background: #0d0d1a;
    color: #e0e0e0;
    resize: vertical;
    font-family: inherit;
}

.thought-input:focus {
    outline: none;
    border-color: #d4af37;
}

.thought-submit {
    margin-top: 15px;
    text-align: center;
}

.dictate-btn {
    position: absolute;
    right: 10px;
    top: 10px;
    background: #252535;
    border: 1px solid #444;
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 0.9em;
    cursor: pointer;
    color: #e0e0e0;
    transition: all 0.2s;
}

.dictate-btn:hover {
    background: #353545;
    border-color: #d4af37;
}

.dictate-btn.recording {
    background: #5a2a2a;
    border-color: #e63946;
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

/* THOUGHTS LIST */
.thoughts-section {
    background: #0d1117;
    padding: 60px 20px;
}

.thoughts-list {
    max-width: 700px;
    margin: 0 auto;
}

.thought-card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
}

.thought-card:hover {
    border-color: #d4af37;
}

.thought-content {
    color: #e6e6e6;
    font-size: 1.05em;
    line-height: 1.5;
    margin-bottom: 12px;
}

.thought-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #666;
    font-size: 0.85em;
}

.thought-votes {
    display: flex;
    align-items: center;
    gap: 8px;
}

.vote-btn {
    background: #252535;
    border: 1px solid #444;
    border-radius: 6px;
    padding: 6px 12px;
    cursor: pointer;
    color: #888;
    font-size: 0.9em;
    transition: all 0.2s ease;
}

.vote-btn:hover {
    background: #303045;
    border-color: #666;
}

.vote-btn.agree:hover {
    border-color: #4a9;
    color: #4a9;
}

.vote-btn.disagree:hover {
    border-color: #a54;
    color: #a54;
}

.vote-btn.voted {
    font-weight: 600;
}

.vote-btn.agree.voted {
    background: #1a3a2a;
    border-color: #4a9;
    color: #4a9;
}

.vote-btn.disagree.voted {
    background: #3a1a1a;
    border-color: #a54;
    color: #a54;
}

/* Responsive */
@media (max-width: 768px) {
    .hero h1 {
        font-size: 2.2em;
    }
    
    section h2 {
        font-size: 1.8em;
    }
    
    .btn {
        padding: 12px 25px;
        font-size: 1em;
    }
    
    .special-grid, .building-grid {
        grid-template-columns: 1fr;
    }
    
    .thought-input-container {
        max-width: 300px;
        margin: 10px auto;
    }
    
    .who-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

html {
    scroll-behavior: smooth;
}
CSS;

// Include header
require __DIR__ . '/../../../includes/header.php';

// Include nav
require __DIR__ . '/../../../includes/nav.php';
?>

<!-- HERO -->
<section class="hero" id="hero">
    <div class="hero-content">
        <div class="town-badge">THE QUIET CORNER • CONNECTICUT</div>
        <h1>Putnam: <span>A More Perfect Town</span></h1>
        <p class="tagline">Protect what's special. Build what's next.</p>
        
        <p class="hero-description">
            The Antiques Capital of the Northeast. The River Trail along the Quinebaug. 
            First Fridays, the Bradley Playhouse, Main Street's revival. 
            Putnam is already something special — and with your voice, it can be even better.
        </p>
        
        <div class="hero-cta">
            <a href="#share" class="btn btn-primary">Add Your Voice</a>
            <a href="#building" class="btn btn-secondary">See What We're Building</a>
        </div>
    </div>
    <div class="scroll-indicator">↓</div>
</section>

<!-- PUTNAM HISTORY AUDIO -->
<section id="history" style="background: #0d1117; padding: 40px 20px;">
    <div style="max-width: 700px; margin: 0 auto;">
        <div style="background: #1a1a2a; padding: 1.5rem; border-radius: 10px; border: 1px solid #333;">
            <h3 style="color: #d4af37; margin-bottom: 0.5rem;">🎧 Hear Putnam's Story</h3>
            <p style="color: #888; font-size: 0.9rem; margin-bottom: 1rem;">A brief audio history of our town</p>
            <audio controls style="width: 100%;">
                <source src="putnam-history.mp3" type="audio/mpeg">
                Your browser doesn't support audio.
            </audio>
        </div>
    </div>
</section>

<!-- WHAT MAKES PUTNAM SPECIAL -->
<section class="special" id="special">
    <h2>What Makes Putnam Special</h2>
    <p class="section-intro">
        This is worth protecting. This is worth building on.
    </p>
    
    <!-- Pulsing Audio Badge -->
    <div style="text-align: center; margin-bottom: 2rem;">
        <a href="#history" onclick="document.querySelector('#history audio').play(); return true;" 
           class="audio-badge" 
           style="display: inline-flex; align-items: center; gap: 0.5rem; 
                  background: linear-gradient(135deg, #2a1a3a 0%, #1a2a3a 100%); 
                  padding: 12px 24px; border-radius: 30px; 
                  border: 2px solid #d4af37; text-decoration: none; color: #d4af37;
                  animation: badge-pulse 2s infinite; cursor: pointer;">
            <span style="font-size: 1.3em;">🎧</span>
            <span style="font-weight: 600;">Hear Putnam's Story</span>
            <span style="background: #d4af37; color: #000; padding: 2px 8px; border-radius: 12px; font-size: 0.8em;">2 min</span>
        </a>
    </div>
    
    <div class="special-grid">
        <a href="#share" class="special-card" data-question="What would strengthen our antiques identity?">
            <h3>🏛️ Antiques Capital of the Northeast</h3>
            <p>Four floors at the Antiques Marketplace. Jeremiah's next door. 300+ dealers. Treasure hunters come from Boston to New York. This is our identity.</p>
        </a>
        
        <a href="#share" class="special-card" data-question="How should we improve the River Trail?">
            <h3>🚶 The Putnam River Trail</h3>
            <p>Two miles along the Quinebaug — through woodlands, parks, past the mills, into downtown. Walking, biking, running, skating. Pet-friendly. Ours.</p>
        </a>
        
        <a href="#share" class="special-card" data-question="What arts or events would you like to see?">
            <h3>🎭 Arts & Al Fresco</h3>
            <p>The Bradley Playhouse. First Fridays with music and art. Fire & Ice Festival. Great Pumpkin Festival. Galleries and boutiques. Small-town creative energy.</p>
        </a>
        
        <a href="#share" class="special-card" data-question="What does Main Street need?">
            <h3>🍽️ Main Street Revival</h3>
            <p>85 Main. Black Dog. The Stomping Ground. Bear Hands Brewing. From hard times to thriving — the comeback is real, and it's delicious.</p>
        </a>
        
        <a href="#share" class="special-card" data-question="What makes Putnam's character worth protecting?">
            <h3>📚 Quiet Corner Character</h3>
            <p>The 1906 train station. Head-on parking. Locals who say hello. The Boxcar Children Museum. A pace of life that lets you breathe.</p>
        </a>
        
        <a href="#share" class="special-card" data-question="What brings our community together?">
            <h3>🏠 9,347 Neighbors</h3>
            <p>Small enough to know people. Big enough to matter. Teachers, artists, business owners, families, retirees — all with a stake in what Putnam becomes.</p>
        </a>
    </div>
</section>

<!-- WHAT WE'RE BUILDING TOGETHER -->
<section class="building" id="building">
    <h2>What We're Building Together</h2>
    <p class="section-intro">
        Protect what works. Fix what's broken. Dream what's next.<br>
        These are the conversations Putnam residents are starting.
    </p>
    
    <div class="building-grid">
        <a href="#share" class="building-card" data-question="What would help Main Street thrive?">
            <div class="card-type">Protect</div>
            <h3>Keep Main Street Thriving</h3>
            <p>Support local businesses. Fill empty storefronts. Make downtown a destination every day, not just First Fridays.</p>
        </a>
        
        <a href="#share" class="building-card" data-question="How should we grow the River Trail?">
            <div class="card-type">Expand</div>
            <h3>Grow the River Trail</h3>
            <p>Connect more neighborhoods. Add amenities. Make the Quinebaug corridor Putnam's crown jewel.</p>
        </a>
        
        <a href="#share" class="building-card" data-question="What property needs attention?">
            <div class="card-type">Fix</div>
            <h3>Address Blight</h3>
            <p>Unmaintained properties hurt everyone. Identify them. Track them. Push for action.</p>
        </a>
        
        <a href="#share" class="building-card" data-question="What do our schools need most?">
            <div class="card-type">Invest</div>
            <h3>Strengthen Our Schools</h3>
            <p>Our kids deserve the best. Budget priorities, teacher support, facility needs — make education a community conversation.</p>
        </a>
        
        <a href="#share" class="building-card" data-question="What road or sidewalk needs fixing?">
            <div class="card-type">Fix</div>
            <h3>Roads & Infrastructure</h3>
            <p>Potholes. Sidewalks. Route 21 traffic. The basics matter. Report issues, track progress, demand accountability.</p>
        </a>
        
        <a href="#share" class="building-card" data-question="What's your vision for Putnam?">
            <div class="card-type">Dream</div>
            <h3>What's YOUR Vision?</h3>
            <p>A dog park expansion? More youth programs? Better transit connections? Your idea could be the next thing we build.</p>
        </a>
    </div>
</section>

<?php if ($totalVacancies > 0): ?>
<!-- BOARD VACANCIES (DB-DRIVEN) -->
<section class="vacancies" id="vacancies">
    <h2>🪑 <?= $totalVacancies ?> Vacant Seats Need You</h2>
    <p class="section-intro">
        Your town government has open positions. No experience required — just show up and serve.
    </p>
    
    <div class="vacancy-grid">
        <?php foreach ($boardsWithVacancies as $board): ?>
        <div class="vacancy-card">
            <h4><?= htmlspecialchars($board['branch_name']) ?></h4>
            <p class="seats"><?= $board['vacancies'] ?> open seat<?= $board['vacancies'] > 1 ? 's' : '' ?></p>
            <p style="color: #888; font-size: 0.85em;"><?= htmlspecialchars($board['branch_type']) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    
    <p style="text-align: center; margin-top: 30px;">
        <a href="https://onboard.putnamct.us/" target="_blank" class="btn btn-primary">Apply to Serve →</a>
    </p>
</section>
<?php endif; ?>

<!-- PUTNAM THOUGHTS -->
<section class="thoughts-section" id="thoughts">
    <h2>What Putnam Is Saying</h2>
    <p class="section-intro">
        Real thoughts from your neighbors. Join the conversation.
    </p>
    
    <div class="thoughts-list" id="thoughtsList">
        <div class="thoughts-loading">Loading thoughts...</div>
    </div>
</section>

<!-- WHO WE NEED -->
<section class="who" id="who">
    <h2>Putnam Needs Your Voice</h2>
    <p class="section-intro">
        Whatever your background, you have something to contribute.<br>
        A more perfect Putnam takes all of us.
    </p>
    
    <div class="who-grid">
        <a href="/volunteer/apply.php?role=business" class="who-card">
            <div class="who-icon">🏪</div>
            <h3>Business Owners</h3>
            <p>You built Main Street. Help shape its future.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=arts" class="who-card">
            <div class="who-icon">🎨</div>
            <h3>Artists & Creatives</h3>
            <p>Arts & Al Fresco needs your voice and vision.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=education" class="who-card">
            <div class="who-icon">👨‍🏫</div>
            <h3>Teachers & Educators</h3>
            <p>Our schools shape our future. Speak up.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=tech" class="who-card">
            <div class="who-icon">💻</div>
            <h3>Tech & Retirees</h3>
            <p>Skills + time = civic infrastructure.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=families" class="who-card">
            <div class="who-icon">👨‍👩‍👧</div>
            <h3>Young Families</h3>
            <p>You're raising the next generation here.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=trails" class="who-card">
            <div class="who-icon">🚶</div>
            <h3>Trail Advocates</h3>
            <p>The River Trail is just the beginning.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=longtime" class="who-card">
            <div class="who-icon">🏠</div>
            <h3>Longtime Residents</h3>
            <p>You know what Putnam was. Help shape what it becomes.</p>
        </a>
        
        <a href="/volunteer/apply.php?role=newcomer" class="who-card">
            <div class="who-icon">🆕</div>
            <h3>Newcomers</h3>
            <p>Fresh eyes see possibilities we've missed.</p>
        </a>
    </div>
</section>

<?php if (count($listings) > 0): ?>
<!-- YELLOW PAGES (DB-DRIVEN) -->
<section class="yellow-pages" id="directory">
    <h2>📒 Putnam Yellow Pages</h2>
    <p class="section-intro">
        Local businesses serving local neighbors.
    </p>
    
    <div class="listing-grid">
        <?php foreach ($listings as $listing): ?>
        <div class="listing-card">
            <h4><?= htmlspecialchars($listing['business_name']) ?></h4>
            <p class="category"><?= htmlspecialchars($listing['sic_description']) ?></p>
            <?php if ($listing['tagline']): ?>
            <p style="color: #ccc; font-size: 0.9em; margin-bottom: 8px;"><?= htmlspecialchars($listing['tagline']) ?></p>
            <?php endif; ?>
            <p class="contact">
                <?php if ($listing['phone']): ?>📞 <?= htmlspecialchars($listing['phone']) ?><br><?php endif; ?>
                <?php if ($listing['website']): ?><a href="<?= htmlspecialchars($listing['website']) ?>" target="_blank" style="color: #4a90a4;">Website →</a><?php endif; ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
    
    <?php if ($userTrustLevel >= 3): ?>
    <p style="text-align: center; margin-top: 30px;">
        <a href="/directory/add.php?town=<?= $townId ?>" class="btn btn-primary">+ Add Your Business</a>
    </p>
    <?php else: ?>
    <p style="text-align: center; margin-top: 30px; color: #888;">
        Own a business? <a href="/profile.php" style="color: #d4af37;">Verify your account</a> to add a free listing.
    </p>
    <?php endif; ?>
</section>
<?php else: ?>
<!-- YELLOW PAGES PLACEHOLDER -->
<section class="yellow-pages" id="directory">
    <h2>📒 Putnam Yellow Pages</h2>
    <p class="section-intro">
        Local businesses serving local neighbors.<br>
        <em>Coming soon — be the first to add your business!</em>
    </p>
    
    <?php if ($userTrustLevel >= 3): ?>
    <p style="text-align: center; margin-top: 30px;">
        <a href="/directory/add.php?town=<?= $townId ?>" class="btn btn-primary">+ Add Your Business</a>
    </p>
    <?php else: ?>
    <p style="text-align: center; margin-top: 30px; color: #888;">
        Own a business? <a href="/profile.php" style="color: #d4af37;">Verify your account</a> to add a free listing.
    </p>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- YOUR REPRESENTATIVES -->
<section class="reps" id="reps">
    <h2>Your Representatives</h2>
    <p class="section-intro">
        These are the people who represent Putnam. They should hear from you.
    </p>
    
    <div class="reps-grid">
        <a href="https://putnamct.us/government/mayors-office" target="_blank" class="rep-card">
            <div class="rep-level">Town</div>
            <h3>Mayor Barney Seney</h3>
            <div class="rep-title">Mayor since 2017 (D)</div>
            <div class="rep-link">→ Mayor's Office</div>
        </a>
        
        <a href="https://putnamct.us/government/boards/board-of-selectmen" target="_blank" class="rep-card">
            <div class="rep-level">Town</div>
            <h3>Board of Selectmen</h3>
            <div class="rep-title">6 members + Mayor</div>
            <div class="rep-link">→ Meeting Agendas & Minutes</div>
        </a>
        
        <a href="https://putnamct.us/government/town-administrator" target="_blank" class="rep-card">
            <div class="rep-level">Town</div>
            <h3>Elaine Sistare</h3>
            <div class="rep-title">Town Administrator</div>
            <div class="rep-link">→ Town Admin Office</div>
        </a>
        
        <a href="https://www.housedems.ct.gov/Boyd" target="_blank" class="rep-card">
            <div class="rep-level">State House</div>
            <h3>Pat Boyd</h3>
            <div class="rep-title">District 51 (D)</div>
            <div class="rep-link">→ State Legislature</div>
        </a>
        
        <a href="https://www.senatedems.ct.gov/Flexer" target="_blank" class="rep-card">
            <div class="rep-level">State Senate</div>
            <h3>Mae Flexer</h3>
            <div class="rep-title">District 29 (D)</div>
            <div class="rep-link">→ State Legislature</div>
        </a>
        
        <a href="https://courtney.house.gov/" target="_blank" class="rep-card">
            <div class="rep-level">U.S. House</div>
            <h3>Joe Courtney</h3>
            <div class="rep-title">CT-2 (D)</div>
            <div class="rep-link">→ Congress</div>
        </a>
        
        <a href="https://www.blumenthal.senate.gov/" target="_blank" class="rep-card">
            <div class="rep-level">U.S. Senate</div>
            <h3>Richard Blumenthal</h3>
            <div class="rep-title">Senator (D)</div>
            <div class="rep-link">→ Senate</div>
        </a>
        
        <a href="https://www.murphy.senate.gov/" target="_blank" class="rep-card">
            <div class="rep-level">U.S. Senate</div>
            <h3>Chris Murphy</h3>
            <div class="rep-title">Senator (D)</div>
            <div class="rep-link">→ Senate</div>
        </a>
    </div>
</section>

<!-- SHARE YOUR VOICE CTA -->
<section class="cta" id="share">
    <h2>What's Your Vision for Putnam?</h2>
    <p class="cta-subtitle">
        Something to protect? Something to fix? Something to build?<br>
        Your neighbors are listening. Your representatives should be too.
    </p>
    
    <div class="thought-input-container">
        <div style="position: relative;">
            <textarea class="thought-input" id="thoughtInput" placeholder="Share your thought about Putnam..." maxlength="1000"></textarea>
            <button type="button" id="dictateBtn" class="dictate-btn">🎤 Dictate</button>
        </div>
        <div style="text-align: right; margin: 5px 0 10px;">
            <span id="thoughtCounter" style="color: #888; font-size: 0.85em;">0 / 1000</span>
        </div>
        
        <!-- Category Dropdown -->
        <select id="thoughtCategory" style="width: 100%; padding: 12px; margin: 15px 0; background: #1a1a2e; border: 1px solid #444; border-radius: 8px; color: #e0e0e0; font-size: 1em;">
            <option value="">Select category (optional)</option>
            <optgroup label="── About Putnam ──">
                <option value="1">🏗️ Infrastructure</option>
                <option value="2">📚 Education</option>
                <option value="3">🚔 Public Safety</option>
                <option value="4">🌳 Environment</option>
                <option value="5">💼 Economy</option>
                <option value="6">🏥 Healthcare</option>
                <option value="7">🏠 Housing</option>
                <option value="8">🚌 Transportation</option>
                <option value="9">🏛️ Government</option>
                <option value="10">👥 Community</option>
                <option value="11">❓ Other</option>
            </optgroup>
            <optgroup label="── About TPB Platform ──">
                <option value="16">💡 Idea</option>
                <option value="17">🐛 Bug Report</option>
                <option value="18">❓ Question</option>
                <option value="19">💬 Discussion</option>
            </optgroup>
        </select>
        
        <!-- TPB Profile Notice (shows when TPB category selected) -->
        <div id="tpbProfileNotice" style="display: none; background: #2a2a4a; border: 1px solid #d4af37; border-radius: 8px; padding: 12px; margin-bottom: 15px;">
            <p style="color: #d4af37; margin: 0 0 8px; font-size: 0.95em;">⚠️ TPB feedback requires a complete profile</p>
            <p style="color: #aaa; margin: 0; font-size: 0.85em;">We need your name and phone to follow up on your <span id="tpbCategoryName">idea</span>.</p>
            <div id="tpbMissingFields" style="color: #ff6b6b; margin-top: 8px; font-size: 0.85em;"></div>
        </div>
        
        <!-- Other Topic Field (shows when Other selected) -->
        <div id="otherTopicField" style="display: none; margin-bottom: 15px;">
            <input type="text" id="otherTopic" placeholder="What topic is this?" maxlength="100"
                   style="width: 100%; padding: 12px; background: #1a1a2e; border: 1px solid #444; border-radius: 8px; color: #e0e0e0; font-size: 1em;">
            <div style="text-align: right; margin-top: 5px;">
                <span id="otherTopicCounter" style="color: #888; font-size: 0.85em;">0 / 100</span>
            </div>
        </div>
        
        <div class="thought-submit">
            <button type="button" class="btn btn-primary" id="submitThought">Share Your Thought</button>
        </div>
        
        <!-- Email verification (hidden by default) -->
        <div id="emailSection" style="display: none; margin-top: 20px;">
            <p style="color: #d4af37; margin-bottom: 10px;">Enter your email to verify and submit:</p>
            <div style="display: flex; gap: 10px; max-width: 400px; margin: 0 auto;">
                <input type="email" id="emailInput" placeholder="your@email.com" 
                       style="flex: 1; padding: 12px; border-radius: 6px; border: 1px solid #444; background: #1a1a2e; color: #fff;">
                <button type="button" id="sendVerification" class="btn btn-primary">Verify</button>
            </div>
        </div>
        
        <!-- Status messages -->
        <div id="submitStatus" style="margin-top: 20px; display: none;"></div>
        
        <p style="margin-top: 25px; color: #666; font-size: 0.95em;">
            Just your email + Putnam = your voice counts.<br>
            No long forms. No social media games. Just civic participation.
        </p>
    </div>
</section>

<script>
// Session and user profile
var sessionId = '<?= htmlspecialchars($sessionId ?? '') ?>';
var userProfile = null;
var tpbCategories = [16, 17, 18, 19];
var tpbCategoryNames = {16: 'idea', 17: 'bug report', 18: 'question', 19: 'discussion'};

// Load thoughts
async function loadThoughts() {
    try {
        const response = await fetch('/api/get-thoughts.php?town_id=<?= $townId ?>&limit=10');
        const data = await response.json();
        
        const container = document.getElementById('thoughtsList');
        
        if (!data.thoughts || data.thoughts.length === 0) {
            container.innerHTML = '<div class="empty-state"><div class="icon">💭</div><p>Be the first to share a thought about Putnam!</p></div>';
            return;
        }
        
        container.innerHTML = data.thoughts.map(function(t) {
            var displayName = t.display_name || 'A neighbor';
            var timeAgo = t.time_ago || '';
            return '<div class="thought-card">' +
                '<div class="thought-content">' + escapeHtml(t.content) + '</div>' +
                '<div class="thought-meta">' +
                    '<span>' + displayName + ' · ' + timeAgo + '</span>' +
                    '<div class="thought-votes">' +
                        '<button class="vote-btn agree" data-id="' + t.thought_id + '" data-vote="1">👍 <span class="count">' + (t.agree_count || 0) + '</span></button>' +
                        '<button class="vote-btn disagree" data-id="' + t.thought_id + '" data-vote="-1">👎 <span class="count">' + (t.disagree_count || 0) + '</span></button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }).join('');
        
        // Add vote handlers
        document.querySelectorAll('.vote-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                voteThought(this.dataset.id, this.dataset.vote);
            });
        });
    } catch (err) {
        document.getElementById('thoughtsList').innerHTML = '<p style="color: #666; text-align: center;">Could not load thoughts</p>';
    }
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

async function voteThought(thoughtId, vote) {
    try {
        await fetch('/api/vote-thought.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ thought_id: thoughtId, vote: vote, session_id: sessionId })
        });
        loadThoughts();
    } catch (err) {
        console.error('Vote failed');
    }
}

// Load thoughts on page load
loadThoughts();

// Thought submission
function showStatus(msg, isError) {
    var el = document.getElementById('submitStatus');
    el.style.display = 'block';
    el.style.background = isError ? '#3a1a1a' : '#1a3a1a';
    el.style.color = isError ? '#ff6b6b' : '#4caf50';
    el.style.padding = '15px';
    el.style.borderRadius = '8px';
    el.textContent = msg;
}

async function submitThought(content, categoryId, otherTopic) {
    try {
        const response = await fetch('/api/submit-thought.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                content: content,
                category_id: categoryId,
                other_topic: otherTopic,
                town_id: <?= $townId ?>,
                session_id: sessionId
            })
        });
        return await response.json();
    } catch (err) {
        return { status: 'error', message: 'Network error' };
    }
}

document.getElementById('submitThought').addEventListener('click', async function() {
    var content = document.getElementById('thoughtInput').value.trim();
    var categoryId = document.getElementById('thoughtCategory').value;
    var otherTopic = document.getElementById('otherTopic').value.trim();
    
    if (!content) {
        showStatus('Please enter your thought', true);
        return;
    }
    
    this.disabled = true;
    this.textContent = 'Submitting...';
    
    var result = await submitThought(content, categoryId || null, otherTopic || null);
    
    if (result.status === 'success') {
        showStatus('✓ Your thought has been shared! Thank you for your voice.', false);
        document.getElementById('thoughtInput').value = '';
        document.getElementById('thoughtCounter').textContent = '0 / 1000';
        setTimeout(function() { loadThoughts(); }, 1000);
    } else if (result.needs_verification) {
        document.getElementById('emailSection').style.display = 'block';
        localStorage.setItem('tpb_pending_thought', content);
        localStorage.setItem('tpb_pending_category', categoryId);
        localStorage.setItem('tpb_pending_other', otherTopic);
        showStatus('Please verify your email to submit.', false);
    } else {
        showStatus('Error: ' + (result.message || 'Could not submit'), true);
    }
    
    this.disabled = false;
    this.textContent = 'Share Your Thought';
});

// Character counter
document.getElementById('thoughtInput').addEventListener('input', function() {
    document.getElementById('thoughtCounter').textContent = this.value.length + ' / 1000';
});

// Category change handlers
document.getElementById('thoughtCategory').addEventListener('change', function() {
    var otherField = document.getElementById('otherTopicField');
    var tpbNotice = document.getElementById('tpbProfileNotice');
    var categoryId = parseInt(this.value);
    
    otherField.style.display = (this.value == 11) ? 'block' : 'none';
    tpbNotice.style.display = tpbCategories.includes(categoryId) ? 'block' : 'none';
});

document.getElementById('otherTopic').addEventListener('input', function() {
    document.getElementById('otherTopicCounter').textContent = this.value.length + ' / 100';
});

// Dictation
(function initDictate() {
    var dictateBtn = document.getElementById('dictateBtn');
    var textarea = document.getElementById('thoughtInput');
    
    if (!dictateBtn || !textarea) return;
    
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        dictateBtn.style.display = 'none';
        return;
    }
    
    var recognition = new SpeechRecognition();
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.lang = 'en-US';
    
    var isRecording = false;
    var finalTranscript = '';
    
    dictateBtn.addEventListener('click', function() {
        if (isRecording) {
            recognition.stop();
        } else {
            finalTranscript = textarea.value;
            if (finalTranscript && !finalTranscript.endsWith(' ')) {
                finalTranscript += ' ';
            }
            recognition.start();
        }
    });
    
    recognition.onstart = function() {
        isRecording = true;
        dictateBtn.classList.add('recording');
        dictateBtn.textContent = '🎤 Stop';
    };
    
    recognition.onend = function() {
        isRecording = false;
        dictateBtn.classList.remove('recording');
        dictateBtn.textContent = '🎤 Dictate';
        document.getElementById('thoughtCounter').textContent = textarea.value.length + ' / 1000';
    };
    
    recognition.onresult = function(event) {
        var interimTranscript = '';
        for (var i = event.resultIndex; i < event.results.length; i++) {
            var transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) {
                finalTranscript += transcript;
            } else {
                interimTranscript += transcript;
            }
        }
        textarea.value = finalTranscript + interimTranscript;
        document.getElementById('thoughtCounter').textContent = textarea.value.length + ' / 1000';
    };
    
    recognition.onerror = function(event) {
        isRecording = false;
        dictateBtn.classList.remove('recording');
        dictateBtn.textContent = '🎤 Dictate';
        if (event.error === 'not-allowed') {
            alert('Microphone access denied.');
        }
    };
})();

// Card click → populate thought with question
document.querySelectorAll('.special-card, .building-card').forEach(function(card) {
    card.addEventListener('click', function(e) {
        var question = this.dataset.question;
        if (question) {
            document.getElementById('thoughtInput').value = question + ' ';
            document.getElementById('thoughtInput').focus();
            document.getElementById('thoughtCounter').textContent = (question.length + 1) + ' / 1000';
        }
    });
});
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
