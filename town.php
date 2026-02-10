<?php
/**
 * TPB Dynamic Town/State Fallback Page
 * =====================================
 * Replaces "Coming Soon" with volunteer recruitment portal.
 * No nav menus. Tiered CTA based on user trust level.
 * 
 * Routes (via .htaccess):
 *   /z-states/{state}/           → state-level page
 *   /z-states/{state}/{town}/    → town-level page
 */

$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// ─── GET PARAMS ───
$stateAbbr = isset($_GET['state']) ? strtoupper(trim($_GET['state'])) : '';
$townSlug  = isset($_GET['town'])  ? strtolower(trim($_GET['town']))  : '';

// Validate state abbreviation
if (!$stateAbbr || !preg_match('/^[A-Z]{2}$/', $stateAbbr)) {
    http_response_code(404);
    echo "Not found.";
    exit;
}

// ─── LOOKUP STATE ───
$stmtState = $pdo->prepare("SELECT state_id, state_name, abbreviation, legislature_url FROM states WHERE abbreviation = ?");
$stmtState->execute([$stateAbbr]);
$stateData = $stmtState->fetch();

if (!$stateData) {
    http_response_code(404);
    echo "State not found.";
    exit;
}

// ─── LOOKUP TOWN (if provided) ───
$townData = null;
$isTownPage = !empty($townSlug);

if ($isTownPage) {
    // Convert slug to search: "fort-mill" → "Fort Mill"
    $townSearch = str_replace('-', ' ', $townSlug);
    
    $stmtTown = $pdo->prepare("
        SELECT t.town_id, t.town_name, t.population, 
               t.us_congress_district, t.state_senate_district, t.state_house_district
        FROM towns t 
        WHERE LOWER(REPLACE(t.town_name, ' ', '-')) = ? AND t.state_id = ?
    ");
    $stmtTown->execute([$townSlug, $stateData['state_id']]);
    $townData = $stmtTown->fetch();
    
    // Try looser match if exact slug didn't work
    if (!$townData) {
        $stmtTown2 = $pdo->prepare("
            SELECT t.town_id, t.town_name, t.population,
                   t.us_congress_district, t.state_senate_district, t.state_house_district
            FROM towns t 
            WHERE LOWER(t.town_name) LIKE ? AND t.state_id = ?
            LIMIT 1
        ");
        $stmtTown2->execute(['%' . $townSearch . '%', $stateData['state_id']]);
        $townData = $stmtTown2->fetch();
    }
    
    if (!$townData) {
        http_response_code(404);
        echo "Town not found.";
        exit;
    }
}

// ─── CHECK FOR EXISTING BUILD TASK ───
$existingTask = null;
if ($townData) {
    $taskKey = 'build-' . $townSlug . '-' . strtolower($stateAbbr);
    $stmtTask = $pdo->prepare("SELECT task_id, status, claimed_by_user_id FROM tasks WHERE task_key = ?");
    $stmtTask->execute([$taskKey]);
    $existingTask = $stmtTask->fetch();
}

// ─── USER LOOKUP — standard method, same as every page ───
require_once 'includes/get-user.php';
$dbUser = getUser($pdo);

// ─── DETERMINE USER STATUS ───
$userStatus = 'anonymous'; // not logged in
$volunteerApp = null;

if ($dbUser) {
    $identityLevel = (int)($dbUser['identity_level_id'] ?? 1);
    
    if ($identityLevel >= 3) {
        // Verified — check volunteer status
        $stmtVol = $pdo->prepare("
            SELECT application_id, skill_set_id, status 
            FROM volunteer_applications 
            WHERE user_id = ? 
            ORDER BY applied_at DESC LIMIT 1
        ");
        $stmtVol->execute([$dbUser['user_id']]);
        $volunteerApp = $stmtVol->fetch();
        
        if ($volunteerApp) {
            if ($volunteerApp['status'] === 'accepted') {
                if ((int)$volunteerApp['skill_set_id'] === 1) {
                    $userStatus = 'tech_volunteer';
                } else {
                    $userStatus = 'volunteer_no_tech';
                }
            } elseif ($volunteerApp['status'] === 'pending' || $volunteerApp['status'] === 'under_review') {
                $userStatus = 'volunteer_pending';
            } else {
                $userStatus = 'verified';
            }
        } else {
            $userStatus = 'verified';
        }
    } else {
        $userStatus = 'not_verified';
    }
}

// ─── PAGE DATA ───
$pageName = $isTownPage ? $townData['town_name'] : $stateData['state_name'];
$pageTitle = $pageName . ', ' . $stateData['abbreviation'] . ' — The People\'s Branch';

// Count TPB members in this state/town
$memberCount = 0;
if ($isTownPage && $townData) {
    $stmtMembers = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE current_town_id = ?");
    $stmtMembers->execute([$townData['town_id']]);
    $memberCount = (int)$stmtMembers->fetch()['cnt'];
} else {
    $stmtMembers = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE current_state_id = ?");
    $stmtMembers->execute([$stateData['state_id']]);
    $memberCount = (int)$stmtMembers->fetch()['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0a0f;color:#e0e0e0;line-height:1.7;min-height:100vh;display:flex;flex-direction:column}

/* HEADER - minimal, no menus */
.top-bar{background:#111;border-bottom:1px solid #d4af37;padding:12px 20px;text-align:center}
.top-bar a{color:#d4af37;text-decoration:none;font-size:1.3em;font-weight:700;letter-spacing:.05em}

/* MAIN */
.main{flex:1;max-width:700px;margin:0 auto;padding:40px 20px 60px;width:100%}

/* GREETING */
.greeting{text-align:center;margin-bottom:35px}
.greeting h1{font-size:2.2em;color:#e0e0e0;margin-bottom:6px;font-weight:700}
.greeting h1 span{color:#d4af37}
.greeting .region{color:#888;font-size:.9em;letter-spacing:.1em;text-transform:uppercase;margin-bottom:20px}

/* DATA GRID */
.data-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:25px 0 35px}
.data-item{background:#1a1a2e;border:1px solid #2a2a3e;border-radius:8px;padding:14px;text-align:center}
.data-item .dl{font-size:.7em;color:#888;text-transform:uppercase;letter-spacing:.08em;margin-bottom:4px}
.data-item .dv{font-size:1.05em;color:#d4af37;font-weight:600}

/* DIVIDER */
.divider{border:none;border-top:1px solid #2a2a3e;margin:30px 0}

/* CTA SECTION */
.cta-section{text-align:center;margin:30px 0}
.cta-section h2{font-size:1.4em;color:#e0e0e0;margin-bottom:12px}
.cta-section .cta-desc{color:#aaa;font-size:.95em;max-width:500px;margin:0 auto 25px;line-height:1.6}

/* TASK BOX */
.task-box{background:#1a1a2e;border:1px solid #d4af37;border-radius:12px;padding:25px;margin:20px 0;text-align:left}
.task-box h3{color:#d4af37;font-size:1.1em;margin-bottom:8px}
.task-box .task-detail{color:#aaa;font-size:.9em;margin-bottom:4px}
.task-box .task-detail strong{color:#e0e0e0}

/* STATUS BOX */
.status-box{background:rgba(212,175,55,.06);border:1px solid #2a2a3e;border-radius:10px;padding:20px;margin:20px 0;text-align:center}
.status-box .status-icon{font-size:2em;margin-bottom:8px}
.status-box p{color:#aaa;font-size:.95em;line-height:1.6}

/* BUTTONS */
.btn{display:inline-block;padding:12px 28px;border-radius:8px;font-weight:600;font-size:.95em;text-decoration:none;transition:all .2s;margin-top:10px}
.btn-gold{background:#d4af37;color:#0a0a0f}
.btn-gold:hover{background:#e4bf47;text-decoration:none}
.btn-outline{border:1px solid #d4af37;color:#d4af37}
.btn-outline:hover{background:rgba(212,175,55,.1);text-decoration:none}

/* STEP LIST */
.steps{text-align:left;max-width:400px;margin:20px auto}
.step{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #1a1a2e}
.step:last-child{border-bottom:none}
.step-num{min-width:28px;height:28px;background:#d4af37;color:#0a0a0f;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85em;flex-shrink:0}
.step-done{background:#4caf50}
.step-text{color:#ccc;font-size:.9em;line-height:1.5}
.step-text a{color:#88c0d0}

/* ABOUT */
.about{margin-top:40px;padding:25px;background:#111;border-radius:10px;border:1px solid #222;text-align:center}
.about h3{color:#d4af37;margin-bottom:8px;font-size:1.1em}
.about p{color:#888;font-size:.9em;line-height:1.6}
.about a{color:#88c0d0}

/* ALREADY CLAIMED */
.claimed-note{background:rgba(76,175,80,.08);border:1px solid rgba(76,175,80,.2);border-radius:8px;padding:15px;margin:15px 0;text-align:center;color:#aaa;font-size:.9em}

/* FOOTER */
footer{background:#0a0a0a;border-top:1px solid #222;padding:25px 20px;text-align:center}
footer .ft{color:#666;font-size:.85em}
footer a{color:#d4af37;text-decoration:none}

@media(max-width:600px){
    .greeting h1{font-size:1.7em}
    .data-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- MINIMAL HEADER - NO MENUS -->
<div class="top-bar">
    <a href="/">🏛️ The People's Branch</a>
</div>

<div class="main">

<!-- GREETING -->
<div class="greeting">
    <?php if ($isTownPage): ?>
        <h1><?= htmlspecialchars($townData['town_name']) ?>, <span><?= htmlspecialchars($stateData['abbreviation']) ?></span></h1>
    <?php else: ?>
        <h1><span><?= htmlspecialchars($stateData['state_name']) ?></span></h1>
    <?php endif; ?>
    
    <div class="region">
        <?php if ($isTownPage): ?>
            <?= htmlspecialchars($stateData['state_name']) ?>
        <?php else: ?>
            United States
        <?php endif; ?>
    </div>
</div>

<!-- DATA FROM DB -->
<div class="data-grid">
    <?php if ($isTownPage && $townData['population']): ?>
        <div class="data-item"><div class="dl">Population</div><div class="dv"><?= number_format($townData['population']) ?></div></div>
    <?php endif; ?>
    
    <?php if ($isTownPage && $townData['us_congress_district']): ?>
        <div class="data-item"><div class="dl">US Congress</div><div class="dv"><?= htmlspecialchars($stateAbbr . '-' . $townData['us_congress_district']) ?></div></div>
    <?php endif; ?>
    
    <?php if ($isTownPage && $townData['state_senate_district']): ?>
        <div class="data-item"><div class="dl">State Senate</div><div class="dv">District <?= htmlspecialchars($townData['state_senate_district']) ?></div></div>
    <?php endif; ?>
    
    <?php if ($isTownPage && $townData['state_house_district']): ?>
        <div class="data-item"><div class="dl">State House</div><div class="dv">District <?= htmlspecialchars($townData['state_house_district']) ?></div></div>
    <?php endif; ?>
    
    <div class="data-item"><div class="dl">TPB Members</div><div class="dv"><?= $memberCount ?></div></div>
    
    <?php if ($stateData['legislature_url']): ?>
        <div class="data-item"><div class="dl">Legislature</div><div class="dv"><a href="<?= htmlspecialchars($stateData['legislature_url']) ?>" target="_blank" style="color:#88c0d0;font-size:.85em">Visit →</a></div></div>
    <?php endif; ?>
</div>

<hr class="divider">

<!-- CTA SECTION - TIERED BY USER STATUS -->
<div class="cta-section">
    
    <?php if ($existingTask && $existingTask['status'] === 'completed'): ?>
        <!-- Task already done - page should exist soon -->
        <div class="status-box">
            <div class="status-icon">✅</div>
            <p>This town's page has been built and is awaiting deployment. Check back soon!</p>
        </div>
    
    <?php elseif ($existingTask && in_array($existingTask['status'], ['claimed', 'in_progress', 'review'])): ?>
        <!-- Task in progress -->
        <div class="status-box">
            <div class="status-icon">🔨</div>
            <p>A volunteer is already building this town's page. Check back soon!</p>
        </div>
    
    <?php else: ?>
        <!-- Town needs building -->
        <h2>🚧 This <?= $isTownPage ? 'Town' : 'State' ?> Needs a Builder</h2>
        <p class="cta-desc">
            <?php if ($isTownPage): ?>
                <?= htmlspecialchars($townData['town_name']) ?> doesn't have a civic page yet. 
                We need a verified volunteer with tech skills to help build it.
            <?php else: ?>
                <?= htmlspecialchars($stateData['state_name']) ?> doesn't have a state page yet.
                We need a verified volunteer with tech skills to help build it.
            <?php endif; ?>
        </p>
        
        <?php if ($userStatus === 'anonymous'): ?>
            <!-- NOT LOGGED IN -->
            <div class="steps">
                <div class="step"><div class="step-num">1</div><div class="step-text">Join The People's Branch</div></div>
                <div class="step"><div class="step-num">2</div><div class="step-text">Verify your email and phone (2FA)</div></div>
                <div class="step"><div class="step-num">3</div><div class="step-text">Apply to volunteer with tech skills</div></div>
                <div class="step"><div class="step-num">4</div><div class="step-text">Get approved and accept this build task</div></div>
            </div>
            <a href="/" class="btn btn-gold">Join TPB →</a>
        
        <?php elseif ($userStatus === 'not_verified'): ?>
            <!-- LOGGED IN BUT NOT VERIFIED -->
            <div class="steps">
                <div class="step"><div class="step-num step-done">✓</div><div class="step-text">Joined TPB</div></div>
                <div class="step"><div class="step-num">2</div><div class="step-text">Verify your email and phone (2FA)</div></div>
                <div class="step"><div class="step-num">3</div><div class="step-text">Apply to volunteer with tech skills</div></div>
                <div class="step"><div class="step-num">4</div><div class="step-text">Get approved and accept this build task</div></div>
            </div>
            <a href="/profile.php" class="btn btn-gold">Verify My Identity →</a>
        
        <?php elseif ($userStatus === 'verified'): ?>
            <!-- VERIFIED BUT NOT A VOLUNTEER -->
            <div class="steps">
                <div class="step"><div class="step-num step-done">✓</div><div class="step-text">Joined TPB</div></div>
                <div class="step"><div class="step-num step-done">✓</div><div class="step-text">Identity verified</div></div>
                <div class="step"><div class="step-num">3</div><div class="step-text">Apply to volunteer — select <strong>Technical</strong> skills</div></div>
                <div class="step"><div class="step-num">4</div><div class="step-text">Get approved and accept this build task</div></div>
            </div>
            <a href="/volunteer/" class="btn btn-gold">Apply to Volunteer →</a>
        
        <?php elseif ($userStatus === 'volunteer_pending'): ?>
            <!-- APPLICATION PENDING -->
            <div class="status-box">
                <div class="status-icon">⏳</div>
                <p>Your volunteer application is being reviewed. We'll let you know when you're approved.</p>
            </div>
        
        <?php elseif ($userStatus === 'volunteer_no_tech'): ?>
            <!-- APPROVED BUT WRONG SKILL SET -->
            <div class="status-box">
                <div class="status-icon">👋</div>
                <p>Thanks for volunteering! This task needs <strong>Technical</strong> skills. 
                Other tasks may be available for your skill set.</p>
            </div>
            <a href="/volunteer/" class="btn btn-outline">See Available Tasks →</a>
        
        <?php elseif ($userStatus === 'tech_volunteer'): ?>
            <!-- APPROVED TECH VOLUNTEER - SHOW THE TASK -->
            <div class="task-box">
                <h3>🎯 Task: BUILD — <?= htmlspecialchars($pageName) ?>, <?= htmlspecialchars($stateAbbr) ?></h3>
                <div class="task-detail"><strong>Type:</strong> Town Page Build</div>
                <div class="task-detail"><strong>Skill:</strong> Technical</div>
                <div class="task-detail"><strong>Tools:</strong> AI-assisted (Town Builder Kit + Claude)</div>
                <div class="task-detail"><strong>Deliverable:</strong> ZIP file with HTML page + SQL</div>
                <div class="task-detail" style="margin-top:10px;color:#ccc;">
                    When you complete this build, a <strong>TEST</strong> task will be created 
                    for another volunteer to review your work. After testing, a <strong>DEPLOY</strong> 
                    task completes the chain.
                </div>
            </div>
            <a href="/volunteer/accept-task.php?task=build-<?= htmlspecialchars($townSlug) ?>-<?= strtolower($stateAbbr) ?>" class="btn btn-gold">Accept This Task →</a>
        
        <?php endif; ?>
    
    <?php endif; ?>
</div>

<hr class="divider">

<!-- ABOUT TPB -->
<div class="about">
    <h3>What is The People's Branch?</h3>
    <p>Your voice in democracy. We're building civic pages for every town in America — 
    by citizens, for citizens. Not Left. Not Right. Forward.</p>
    <p style="margin-top:10px"><a href="/our-story.html">Learn More →</a></p>
</div>

</div><!-- /main -->

<footer>
    <div class="ft">
        <a href="/">🏛️ The People's Branch</a> · 
        Not Left. Not Right. Forward. Only Citizens.
    </div>
</footer>

</body>
</html>
