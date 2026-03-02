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
 *   7. Redirect to /welcome.php onboarding page
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
// 10. Redirect to persistent welcome page
// ---------------------------------------------------------------------------
$invitorEmailEnc = urlencode($invitation['invitor_email']);
header("Location: /welcome.php?from=invite&invitor={$invitorEmailEnc}");
exit;
