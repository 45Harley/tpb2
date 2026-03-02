<?php
/**
 * Invite Accept Page
 * ==================
 * Handles the full invitation acceptance flow:
 *   1. Validate token from URL
 *   2. Create user account from invitation
 *   3. Set up device/session (cookie)
 *   4. Award points to invitor
 *   5. Notify invitor via email
 *   6. Transfer any anonymous session points
 *   7. Render guided onboarding landing page
 */

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/point-logger.php';
require_once dirname(__DIR__) . '/includes/smtp-mail.php';
require_once dirname(__DIR__) . '/includes/invite-email.php';

// ---------------------------------------------------------------------------
// 1. Validate token
// ---------------------------------------------------------------------------
$token = $_GET['token'] ?? '';
$stmt = $pdo->prepare("
    SELECT i.*, u.email AS invitor_email
    FROM invitations i
    JOIN users u ON i.invitor_user_id = u.user_id
    WHERE i.token = ? LIMIT 1
");
$stmt->execute([$token]);
$invitation = $stmt->fetch();

if (!$invitation) {
    $pageTitle = 'Invalid Invitation';
    include dirname(__DIR__) . '/includes/header.php';
    include dirname(__DIR__) . '/includes/nav.php';
    ?>
    <div class="main narrow" style="text-align:center; padding-top:4rem;">
        <h1 style="color:#e63946;">Invalid or Expired Invitation</h1>
        <p class="subtitle">This invitation link is not valid. It may have already been used or has expired.</p>
        <p style="margin-top:2rem;"><a href="/" class="btn btn-primary">Go Home</a></p>
    </div>
    <?php
    include dirname(__DIR__) . '/includes/footer.php';
    echo '</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// 2. If already joined (token reuse), redirect to login
// ---------------------------------------------------------------------------
if ($invitation['status'] === 'joined') {
    header('Location: /login.php');
    exit;
}

// ---------------------------------------------------------------------------
// 3. Check if invitee email already exists
// ---------------------------------------------------------------------------
$email = $invitation['invitee_email'];
$existing = $pdo->prepare("SELECT user_id, deleted_at FROM users WHERE email = ? LIMIT 1");
$existing->execute([$email]);
$existingUser = $existing->fetch();

if ($existingUser && !$existingUser['deleted_at']) {
    // Active user — just redirect to login
    header('Location: /login.php');
    exit;
}

// ---------------------------------------------------------------------------
// 4. Create or restore user account
// ---------------------------------------------------------------------------
$sessionId = 'civic_' . bin2hex(random_bytes(16));

if ($existingUser && $existingUser['deleted_at']) {
    // Restore soft-deleted user instead of creating a duplicate
    $newUserId = (int)$existingUser['user_id'];
    $pdo->prepare("UPDATE users SET deleted_at = NULL, identity_level_id = GREATEST(identity_level_id, 2) WHERE user_id = ?")
        ->execute([$newUserId]);
} else {
    // Brand new user
    $username = explode('@', $email)[0] . '_' . substr(md5($email), 0, 6);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, identity_level_id, civic_points) VALUES (?, ?, 2, 0)");
    $stmt->execute([$username, $email]);
    $newUserId = $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// 5. Set up device/session
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("INSERT INTO user_devices (user_id, device_session) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = ?, is_active = 1");
$stmt->execute([$newUserId, $sessionId, $newUserId]);
setcookie('tpb_civic_session', $sessionId, time() + 86400 * 365, '/', '', true, true);

// ---------------------------------------------------------------------------
// 6. Update invitation record
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare("UPDATE invitations SET status='joined', invitee_user_id=?, joined_at=NOW() WHERE id=?");
$stmt->execute([$newUserId, $invitation['id']]);

// ---------------------------------------------------------------------------
// 7. Award 100 pts to invitor
// ---------------------------------------------------------------------------
PointLogger::init($pdo);
$pointResult = PointLogger::award($invitation['invitor_user_id'], 'referral_joined', 'referral', $invitation['id']);

// Mark points awarded on invitation
$pdo->prepare("UPDATE invitations SET points_awarded=1 WHERE id=?")->execute([$invitation['id']]);

// ---------------------------------------------------------------------------
// 8. Send notification email to invitor
// ---------------------------------------------------------------------------
$invitorPtsStmt = $pdo->prepare("SELECT civic_points FROM users WHERE user_id = ?");
$invitorPtsStmt->execute([$invitation['invitor_user_id']]);
$invitorPts = (int)$invitorPtsStmt->fetchColumn();

$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$notifBody = buildInvitorNotificationEmail($email, $invitorPts, $baseUrl);
sendSmtpMail($config, $invitation['invitor_email'], "Your friend {$email} just joined TPB! +100 Civic Points", $notifBody, null, true);

// ---------------------------------------------------------------------------
// 9. Transfer any anonymous session points
// ---------------------------------------------------------------------------
PointLogger::transferSession($sessionId, $newUserId);

// ---------------------------------------------------------------------------
// 10. Re-fetch user for nav display
// ---------------------------------------------------------------------------
// Manually set the cookie in $_COOKIE so getUser() can find it in this request
$_COOKIE['tpb_civic_session'] = $sessionId;
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'invite';
$pageTitle = 'Welcome to The People\'s Branch';
$invitorEmailDisplay = htmlspecialchars($invitation['invitor_email']);

// Page-specific styles (from mockup)
$pageStyles = <<<'CSS'
    /* Welcome hero */
    .welcome-hero {
        max-width: 700px;
        margin: 0 auto;
        padding: 3rem 1.5rem 1.5rem;
        text-align: center;
    }
    .welcome-hero h1 {
        color: #d4af37;
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }
    .welcome-hero .invited-by {
        color: #aaa;
        font-size: 1rem;
        margin-bottom: 0.75rem;
    }
    .welcome-hero .invited-by strong { color: #c8a415; }
    .welcome-hero .points-earned {
        display: inline-block;
        background: rgba(212, 175, 55, 0.15);
        border: 1px solid rgba(212, 175, 55, 0.4);
        padding: 0.5rem 1.25rem;
        border-radius: 8px;
        color: #f5c842;
        font-weight: 600;
        font-size: 0.95rem;
    }

    /* Explore section */
    .section-container {
        max-width: 700px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }
    .section-title {
        color: #fff;
        font-size: 1.3rem;
        margin: 2.5rem 0 0.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #333;
    }
    .section-subtitle {
        color: #888;
        font-size: 0.9rem;
        margin-bottom: 1.25rem;
    }

    /* Feature cards */
    .feature-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .feature-card {
        display: block;
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 10px;
        padding: 1.25rem;
        text-decoration: none;
        transition: border-color 0.2s, transform 0.2s;
        cursor: pointer;
    }
    .feature-card:hover {
        border-color: #d4af37;
        transform: translateY(-2px);
    }
    .feature-card .card-icon {
        font-size: 1.8rem;
        margin-bottom: 0.5rem;
    }
    .feature-card h3 {
        color: #fff;
        font-size: 1rem;
        margin-bottom: 0.3rem;
    }
    .feature-card p {
        color: #888;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .feature-card .card-cta {
        display: inline-block;
        margin-top: 0.75rem;
        color: #c8a415;
        font-size: 0.85rem;
        font-weight: 600;
    }

    /* Profile completion section */
    .profile-steps {
        list-style: none;
        margin-bottom: 3rem;
    }
    .profile-step {
        display: flex;
        gap: 1rem;
        align-items: flex-start;
        padding: 1rem 1.25rem;
        margin-bottom: 0.75rem;
        background: #1a1a2e;
        border: 1px solid #333;
        border-radius: 10px;
        text-decoration: none;
        transition: border-color 0.2s;
        cursor: pointer;
    }
    .profile-step:hover {
        border-color: #d4af37;
    }
    .step-icon {
        flex-shrink: 0;
        width: 40px;
        height: 40px;
        background: rgba(212, 175, 55, 0.15);
        border: 1px solid rgba(212, 175, 55, 0.3);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    .step-text h3 {
        color: #fff;
        font-size: 1rem;
        margin-bottom: 0.2rem;
    }
    .step-text .because {
        color: #c8a415;
        font-size: 0.85rem;
        font-style: italic;
        margin-bottom: 0.2rem;
    }
    .step-text p {
        color: #888;
        font-size: 0.85rem;
        line-height: 1.4;
    }
    .step-points {
        flex-shrink: 0;
        color: #f5c842;
        font-size: 0.8rem;
        font-weight: 600;
        margin-left: auto;
        white-space: nowrap;
        padding-top: 0.2rem;
    }

    /* Landing footer */
    .landing-footer {
        text-align: center;
        padding: 2rem 1rem;
        border-top: 1px solid #222;
        color: #555;
        font-size: 0.85rem;
    }
    .landing-footer a { color: #d4af37; text-decoration: none; }
    .landing-footer a:hover { text-decoration: underline; }

    @media (max-width: 600px) {
        .feature-grid { grid-template-columns: 1fr; }
        .welcome-hero h1 { font-size: 1.5rem; }
    }
CSS;

// ---------------------------------------------------------------------------
// HTML output
// ---------------------------------------------------------------------------
include dirname(__DIR__) . '/includes/header.php';
include dirname(__DIR__) . '/includes/nav.php';
?>

<div style="background:#0d0d0d; min-height: 80vh;">

<!-- Welcome Hero -->
<div class="welcome-hero">
    <h1>Welcome to The People's Branch</h1>
    <p class="invited-by">Invited by <strong><?= $invitorEmailDisplay ?></strong></p>
    <?php if ((int)$dbUser['civic_points'] > 0): ?>
        <div class="points-earned">&#x2B50; You've already earned <?= (int)$dbUser['civic_points'] ?> Civic Points just by joining</div>
    <?php else: ?>
        <div class="points-earned">&#x2B50; Start earning Civic Points — explore below</div>
    <?php endif; ?>
</div>

<!-- Explore: What's yours now -->
<div class="section-container">
    <h2 class="section-title">Here's what's yours now</h2>
    <p class="section-subtitle">Click any card to explore in a new tab. Come back here when you're ready.</p>

    <div class="feature-grid">
        <a class="feature-card" href="/talk/" target="_blank">
            <div class="card-icon">&#x1F4AC;</div>
            <h3>USA Talk</h3>
            <p>Citizens brainstorming solutions together. Real ideas, real debate, real consensus.</p>
            <span class="card-cta">See what people are saying &rarr;</span>
        </a>
        <a class="feature-card" href="/elections/" target="_blank">
            <div class="card-icon">&#x1F6A8;</div>
            <h3>Threat Stream</h3>
            <p>A live scorecard of government actions rated on a criminality scale. Transparent accountability.</p>
            <span class="card-cta">See the threats &rarr;</span>
        </a>
        <a class="feature-card" href="/poll/" target="_blank">
            <div class="card-icon">&#x1F5F3;</div>
            <h3>Polls</h3>
            <p>Vote yea, nay, or abstain on active threats and policy. Your opinion is tallied publicly.</p>
            <span class="card-cta">Cast your first vote &rarr;</span>
        </a>
        <a class="feature-card" href="/usa/" target="_blank">
            <div class="card-icon">&#x1F5FA;</div>
            <h3>Your Country</h3>
            <p>Interactive map of every state and town. Find your community, see your representatives.</p>
            <span class="card-cta">Explore the map &rarr;</span>
        </a>
    </div>

    <!-- Profile completion -->
    <h2 class="section-title">Make it personal</h2>
    <p class="section-subtitle">Each step unlocks more of the platform — and earns you more points.</p>

    <ul class="profile-steps">
        <li class="profile-step" onclick="window.location.href='/profile.php'">
            <div class="step-icon">&#x1F3E0;</div>
            <div class="step-text">
                <h3>Set your town</h3>
                <p class="because">Because your neighbors are already here.</p>
                <p>Your town has its own Talk stream, its own reps, its own issues. Without it, you're only seeing the national level.</p>
            </div>
            <span class="step-points">+50 pts</span>
        </li>
        <li class="profile-step" onclick="window.location.href='/profile.php'">
            <div class="step-icon">&#x1F4F1;</div>
            <div class="step-text">
                <h3>Verify your phone</h3>
                <p class="because">Because trust earns influence.</p>
                <p>Verified citizens can volunteer, facilitate groups, and delegate votes. Your trust level goes from Remembered to Verified.</p>
            </div>
            <span class="step-points">+25 pts</span>
        </li>
        <li class="profile-step" onclick="window.location.href='/profile.php'">
            <div class="step-icon">&#x1F512;</div>
            <div class="step-text">
                <h3>Set a password</h3>
                <p class="because">Because any device, anytime.</p>
                <p>Right now you're on a magic link. A password means you can log in from any device without waiting for an email.</p>
            </div>
            <span class="step-points">+10 pts</span>
        </li>
        <li class="profile-step" onclick="window.location.href='/invite/'">
            <div class="step-icon">&#x1F4E8;</div>
            <div class="step-text">
                <h3>Invite a friend</h3>
                <p class="because">Because <?= $invitorEmailDisplay ?> did it for you.</p>
                <p>Pay it forward. When your friend joins, you earn 100 Civic Points — the same way <?= $invitorEmailDisplay ?> just did.</p>
            </div>
            <span class="step-points">+100 pts</span>
        </li>
    </ul>
</div>

<div class="landing-footer">
    <p><a href="/">&#x1F3DB; Home</a> &nbsp;&middot;&nbsp; <a href="/help/">Help Center</a> &nbsp;&middot;&nbsp; <a href="/help/guide.php?flow=philosophy">Our Philosophy</a></p>
    <p style="margin-top:0.5rem;">The People's Branch &mdash; No Kings. Only Citizens.</p>
</div>

</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
</body>
</html>
