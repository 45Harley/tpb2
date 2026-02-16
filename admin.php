<?php
/**
 * TPB2 Admin Dashboard
 * ====================
 * View stats, manage thoughts, monitor activity
 *
 * Auth: Role-based (Admin role in user_role_membership) OR password fallback
 */

session_start();

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/smtp-mail.php';

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

require_once __DIR__ . '/includes/get-user.php';

// --- CSRF ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function validateCsrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        die('CSRF validation failed');
    }
}

// --- Audit logging ---
function logAdminAction($pdo, $adminUserId, $actionType, $targetType, $targetId, $details = []) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_actions (admin_user_id, action_type, target_type, target_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $adminUserId,
            $actionType,
            $targetType,
            $targetId,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (PDOException $e) {
        // Silently fail — don't block admin action if audit table has issues
    }
}

function parseBrowserName($ua) {
    if (!$ua) return '-';
    if (strpos($ua, 'Edg/') !== false) return 'Edge';
    if (strpos($ua, 'OPR/') !== false || strpos($ua, 'Opera') !== false) return 'Opera';
    if (strpos($ua, 'Chrome/') !== false) return 'Chrome';
    if (strpos($ua, 'Firefox/') !== false) return 'Firefox';
    if (strpos($ua, 'Safari/') !== false) return 'Safari';
    if (strpos($ua, 'Windows PC') !== false) return 'Desktop';
    if (strpos($ua, 'iPhone') !== false) return 'iOS';
    return 'Other';
}

// --- Handle logout FIRST (before any auth) ---
if (isset($_GET['logout'])) {
    unset($_SESSION['tpb_admin']);
    unset($_SESSION['tpb_admin_user_id']);
    $_SESSION['tpb_admin_logged_out'] = true;  // suppress auto-login
    header('Location: admin.php');
    exit;
}

// --- Auth: Hybrid (role-based + password fallback) ---
$adminUser = null;
$adminUserId = null;

// Method 1: Check if logged-in user has Admin role (skip if just logged out)
$dbUser = getUser($pdo);
if ($dbUser && empty($_SESSION['tpb_admin_logged_out'])) {
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_role_membership
        WHERE user_id = ? AND role_id = 1 AND is_active = 1
    ");
    $stmt->execute([$dbUser['user_id']]);
    if ($stmt->fetch()) {
        $adminUser = $dbUser;
        $adminUserId = (int)$dbUser['user_id'];
        $_SESSION['tpb_admin'] = true;
        $_SESSION['tpb_admin_user_id'] = $adminUserId;
    }
}

// Method 2: Password login (clears logged-out flag)
if (!$adminUser && isset($_POST['password'])) {
    $adminPassword = $config['admin_password'] ?? 'tpb2025admin';
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['tpb_admin'] = true;
        $_SESSION['tpb_admin_user_id'] = null;
        unset($_SESSION['tpb_admin_logged_out']);
    } else {
        $loginError = 'Invalid password';
    }
}

// Method 3: Existing session
if (!$adminUser && isset($_SESSION['tpb_admin']) && $_SESSION['tpb_admin']) {
    $adminUserId = $_SESSION['tpb_admin_user_id'] ?? null;
} elseif (!$adminUser) {
    $_SESSION['tpb_admin'] = false;
}

// Check auth
if (empty($_SESSION['tpb_admin'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>TPB Admin Login</title>
        <style>
            body { font-family: Georgia, serif; background: #0a0a0a; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
            .login-box { background: #1a1a2a; padding: 40px; border-radius: 10px; border: 2px solid #d4af37; text-align: center; }
            h1 { color: #d4af37; margin-bottom: 30px; }
            input[type="password"] { padding: 12px 20px; font-size: 16px; border: 1px solid #444; border-radius: 5px; background: #252525; color: #e0e0e0; width: 250px; }
            button { padding: 12px 30px; font-size: 16px; background: #1a3a5c; color: #ffffff; border: none; border-radius: 5px; cursor: pointer; margin-top: 15px; }
            button:hover { background: #2a4a6c; }
            .error { color: #ff6b6b; margin-top: 15px; }
            .hint { color: #666; font-size: 0.85em; margin-top: 15px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>TPB Admin</h1>
            <?php if ($dbUser): ?>
                <p style="color: #ff6b6b;">You are logged in as <?= htmlspecialchars($dbUser['email']) ?> but do not have Admin role.</p>
                <hr style="border-color: #333; margin: 20px 0;">
            <?php endif; ?>
            <form method="POST">
                <div style="position:relative;display:inline-block;">
                    <input type="password" name="password" id="adminPw" placeholder="Admin Password" autofocus>
                    <span onclick="let p=document.getElementById('adminPw');let t=p.type==='password'?'text':'password';p.type=t;this.textContent=t==='password'?'\u{1F441}':'\u{1F441}\u{200D}\u{1F5E8}';" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:1.2em;user-select:none;">&#x1F441;</span>
                </div>
                <br>
                <button type="submit">Login</button>
            </form>
            <?php if (isset($loginError)): ?>
                <p class="error"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>
            <p class="hint">Admin role users are logged in automatically</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =====================================================
// HANDLE POST ACTIONS
// =====================================================

$message = '';
$messageType = '';

// Handle redirect messages
if (isset($_GET['msg'])) {
    $messages = [
        'approved' => 'Volunteer approved! Welcome email sent.',
        'rejected' => 'Application rejected. Email sent.',
        'approve_failed' => 'Volunteer email failed to send.',
        'reject_failed' => 'Rejection email failed to send.',
    ];
    if (isset($messages[$_GET['msg']])) {
        $message = $messages[$_GET['msg']];
        $messageType = str_contains($_GET['msg'], 'failed') ? 'error' : 'success';
    }
}

// Delete thought
if (isset($_POST['delete_thought'])) {
    validateCsrf();
    $thoughtId = (int)$_POST['thought_id'];
    $pdo->prepare("DELETE FROM user_thought_votes WHERE thought_id = ?")->execute([$thoughtId]);
    $pdo->prepare("DELETE FROM user_thoughts WHERE thought_id = ?")->execute([$thoughtId]);
    logAdminAction($pdo, $adminUserId, 'delete_thought', 'thought', $thoughtId);
    $message = "Thought #$thoughtId deleted";
    $messageType = 'success';
}

// Hide thought (set to draft)
if (isset($_POST['hide_thought'])) {
    validateCsrf();
    $thoughtId = (int)$_POST['thought_id'];
    $pdo->prepare("UPDATE user_thoughts SET status = 'draft' WHERE thought_id = ?")->execute([$thoughtId]);
    logAdminAction($pdo, $adminUserId, 'hide_thought', 'thought', $thoughtId);
    $message = "Thought #$thoughtId hidden";
    $messageType = 'success';
}

// Restore thought
if (isset($_POST['restore_thought'])) {
    validateCsrf();
    $thoughtId = (int)$_POST['thought_id'];
    $pdo->prepare("UPDATE user_thoughts SET status = 'published' WHERE thought_id = ?")->execute([$thoughtId]);
    logAdminAction($pdo, $adminUserId, 'restore_thought', 'thought', $thoughtId);
    $message = "Thought #$thoughtId restored";
    $messageType = 'success';
}

// Soft-delete user (mark as deleted, deactivate devices)
if (isset($_POST['delete_user'])) {
    validateCsrf();
    $userId = (int)$_POST['user_id'];
    $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE user_id = ? AND deleted_at IS NULL")->execute([$userId]);
    $pdo->prepare("UPDATE user_devices SET is_active = 0 WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("UPDATE user_thoughts SET status = 'draft' WHERE user_id = ? AND status = 'published'")->execute([$userId]);
    logAdminAction($pdo, $adminUserId, 'soft_delete_user', 'user', $userId);
    $message = "User #$userId marked as deleted (devices deactivated, thoughts hidden)";
    $messageType = 'success';
}

// Restore soft-deleted user
if (isset($_POST['restore_user'])) {
    validateCsrf();
    $userId = (int)$_POST['user_id'];
    $pdo->prepare("UPDATE users SET deleted_at = NULL WHERE user_id = ?")->execute([$userId]);
    logAdminAction($pdo, $adminUserId, 'restore_user', 'user', $userId);
    $message = "User #$userId restored";
    $messageType = 'success';
}

// Approve volunteer
if (isset($_POST['approve_volunteer'])) {
    validateCsrf();
    $appId = (int)$_POST['application_id'];

    $stmt = $pdo->prepare("
        SELECT va.*, u.email, u.first_name, u.last_name
        FROM volunteer_applications va
        JOIN users u ON va.user_id = u.user_id
        WHERE va.application_id = ? AND va.status = 'pending' AND va.approval_email_sent = 0
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    if ($app) {
        $stmt = $pdo->prepare("UPDATE volunteer_applications SET status = 'accepted', reviewed_at = NOW(), approval_email_sent = 1 WHERE application_id = ? AND approval_email_sent = 0");
        $stmt->execute([$appId]);

        if ($stmt->rowCount() > 0) {
            $baseUrl = $config['base_url'];
            $body = "Hi " . ($app['first_name'] ?: 'there') . ",\n\n";
            $body .= "Great news! Your volunteer application has been APPROVED.\n\n";
            $body .= "You now have access to the TPB Volunteer Workspace where you can:\n";
            $body .= "- View available tasks\n";
            $body .= "- Claim tasks to work on\n";
            $body .= "- Contribute to building The People's Branch\n\n";
            $body .= "Get started here:\n";
            $body .= "{$baseUrl}/volunteer/\n\n";
            $body .= "Thank you for stepping up. Democracy needs people like you.\n\n";
            $body .= "--- The People's Branch\n";

            $emailSent = sendSmtpMail($config, $app['email'], "Welcome to The People's Branch Volunteer Team!", $body);
            logAdminAction($pdo, $adminUserId, 'approve_volunteer', 'volunteer', $appId, ['email' => $app['email'], 'email_sent' => $emailSent]);
        }

        header('Location: admin.php?tab=volunteers&msg=' . ($emailSent ?? false ? 'approved' : 'approve_failed'));
        exit;
    } else {
        header('Location: admin.php?tab=volunteers');
        exit;
    }
}

// Reject volunteer
if (isset($_POST['reject_volunteer'])) {
    validateCsrf();
    $appId = (int)$_POST['application_id'];

    $stmt = $pdo->prepare("
        SELECT va.*, u.email, u.first_name, u.last_name
        FROM volunteer_applications va
        JOIN users u ON va.user_id = u.user_id
        WHERE va.application_id = ? AND va.status = 'pending' AND va.approval_email_sent = 0
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    if ($app) {
        $stmt = $pdo->prepare("UPDATE volunteer_applications SET status = 'rejected', reviewed_at = NOW(), approval_email_sent = 1 WHERE application_id = ? AND approval_email_sent = 0");
        $stmt->execute([$appId]);

        if ($stmt->rowCount() > 0) {
            $body = "Hi " . ($app['first_name'] ?: 'there') . ",\n\n";
            $body .= "Thank you for your interest in volunteering with The People's Branch.\n\n";
            $body .= "After reviewing your application, we're unable to move forward at this time.\n\n";
            $body .= "This doesn't mean the door is closed. As TPB grows, our needs change.\n";
            $body .= "Feel free to apply again in the future.\n\n";
            $body .= "In the meantime, you can still:\n";
            $body .= "- Share your thoughts on the platform\n";
            $body .= "- Vote on issues that matter to you\n";
            $body .= "- Spread the word about TPB\n\n";
            $body .= "Thank you for caring about democracy.\n\n";
            $body .= "--- The People's Branch\n";

            $emailSent = sendSmtpMail($config, $app['email'], "Your TPB Volunteer Application", $body);
            logAdminAction($pdo, $adminUserId, 'reject_volunteer', 'volunteer', $appId, ['email' => $app['email'], 'email_sent' => $emailSent]);
        }

        header('Location: admin.php?tab=volunteers&msg=' . ($emailSent ?? false ? 'rejected' : 'reject_failed'));
        exit;
    } else {
        header('Location: admin.php?tab=volunteers');
        exit;
    }
}

// =====================================================
// STATS & DATA QUERIES
// =====================================================

// --- Growth ---
$stats = [];
$stats['citizens'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email NOT LIKE '%@anonymous.tpb' AND deleted_at IS NULL")->fetchColumn();
$stats['active_week'] = (int)$pdo->query("SELECT COUNT(DISTINCT ud.user_id) FROM user_devices ud JOIN users u ON ud.user_id = u.user_id WHERE ud.is_active = 1 AND ud.last_active_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND u.deleted_at IS NULL")->fetchColumn();
$stats['new_week'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND email NOT LIKE '%@anonymous.tpb' AND deleted_at IS NULL")->fetchColumn();
$stats['deleted_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL")->fetchColumn();

// --- Identity progression ---
$identityLevels = $pdo->query("
    SELECT il.level_name, il.level_id, COUNT(u.user_id) as cnt
    FROM identity_levels il
    LEFT JOIN users u ON u.identity_level_id = il.level_id AND u.email NOT LIKE '%@anonymous.tpb' AND u.deleted_at IS NULL
    GROUP BY il.level_id, il.level_name
    ORDER BY il.level_id
")->fetchAll();

// --- Civic engagement ---
$stats['thoughts_total'] = (int)$pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'published'")->fetchColumn();
$stats['thoughts_week'] = (int)$pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'published' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$stats['hidden_thoughts'] = (int)$pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'draft'")->fetchColumn();
$stats['votes_total'] = (int)$pdo->query("SELECT COUNT(*) FROM user_thought_votes")->fetchColumn();
$stats['votes_week'] = (int)$pdo->query("SELECT COUNT(*) FROM user_thought_votes WHERE voted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$stats['states'] = (int)$pdo->query("SELECT COUNT(DISTINCT current_state_id) FROM users WHERE current_state_id IS NOT NULL")->fetchColumn();
$stats['towns'] = (int)$pdo->query("SELECT COUNT(DISTINCT current_town_id) FROM users WHERE current_town_id IS NOT NULL")->fetchColumn();

// --- Action items ---
$stats['pending_volunteers'] = (int)$pdo->query("SELECT COUNT(*) FROM volunteer_applications WHERE status = 'pending'")->fetchColumn();
$stats['approved_volunteers'] = (int)$pdo->query("SELECT COUNT(*) FROM volunteer_applications WHERE status = 'accepted'")->fetchColumn();
$stats['open_tasks'] = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'open'")->fetchColumn();

// Bot stats
$botStats = [];
try {
    $botStats['attempts_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $botStats['attempts_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
    $botStats['unique_ips_24h'] = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $topFormStmt = $pdo->query("SELECT form_name, COUNT(*) as cnt FROM bot_attempts WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY form_name ORDER BY cnt DESC LIMIT 1");
    $topForm = $topFormStmt->fetch();
    $botStats['top_form'] = $topForm ? $topForm['form_name'] . ' (' . $topForm['cnt'] . ')' : 'none';
} catch (PDOException $e) {
    $botStats = ['attempts_24h' => 0, 'attempts_7d' => 0, 'unique_ips_24h' => 0, 'top_form' => 'n/a'];
}

// Bot attempts (recent 100)
$botAttempts = [];
try {
    $botAttempts = $pdo->query("
        SELECT ip_address, form_name, honeypot_filled, too_fast, missing_referrer,
               user_agent, created_at
        FROM bot_attempts
        ORDER BY created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Top offender IPs (3+ attempts in 7 days)
$botOffenders = [];
try {
    $botOffenders = $pdo->query("
        SELECT ip_address, COUNT(*) as attempt_count,
               MAX(created_at) as last_seen,
               GROUP_CONCAT(DISTINCT form_name) as forms_targeted
        FROM bot_attempts
        WHERE created_at > NOW() - INTERVAL 7 DAY
        GROUP BY ip_address
        HAVING attempt_count >= 3
        ORDER BY attempt_count DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// --- Tab data ---
$tab = $_GET['tab'] ?? 'dashboard';

// Volunteers
$pendingVolunteers = $pdo->query("
    SELECT va.*, u.email, u.first_name, u.last_name, u.username,
           s.abbreviation as state_abbrev, tw.town_name, uis.phone, ss.set_name as skill_name
    FROM volunteer_applications va
    JOIN users u ON va.user_id = u.user_id
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
    LEFT JOIN skill_sets ss ON va.skill_set_id = ss.skill_set_id
    WHERE va.status = 'pending'
    ORDER BY va.applied_at DESC
")->fetchAll();

$allVolunteers = $pdo->query("
    SELECT va.*, u.email, u.first_name, u.last_name, s.abbreviation as state_abbrev, tw.town_name
    FROM volunteer_applications va
    JOIN users u ON va.user_id = u.user_id
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    ORDER BY va.applied_at DESC
    LIMIT 50
")->fetchAll();

// Thoughts — sorted by engagement
$thoughts = $pdo->query("
    SELECT t.thought_id, t.content, t.jurisdiction_level, t.status,
           t.upvotes, t.downvotes, (t.upvotes - t.downvotes) as net_score,
           (t.upvotes + t.downvotes) as engagement, t.created_at,
           u.email, u.first_name, s.abbreviation as state_abbrev, tw.town_name
    FROM user_thoughts t
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN states s ON t.state_id = s.state_id
    LEFT JOIN towns tw ON t.town_id = tw.town_id
    ORDER BY engagement DESC, t.created_at DESC
    LIMIT 50
")->fetchAll();

// Users — with identity level and roles
$users = $pdo->query("
    SELECT u.user_id, u.email, u.first_name, u.last_name, u.civic_points, u.created_at, u.deleted_at,
           s.abbreviation as state_abbrev, tw.town_name, il.level_name as identity_level,
           (SELECT COUNT(*) FROM user_thoughts WHERE user_id = u.user_id AND status = 'published') as thought_count,
           (SELECT COUNT(*) FROM user_thought_votes WHERE user_id = u.user_id) as vote_count,
           (SELECT COUNT(*) FROM user_devices WHERE user_id = u.user_id AND is_active = 1) as active_devices,
           (SELECT device_name FROM user_devices WHERE user_id = u.user_id AND is_active = 1 ORDER BY last_active_at DESC LIMIT 1) as last_ua,
           (SELECT GROUP_CONCAT(ur.role_name SEPARATOR ', ')
            FROM user_role_membership urm
            JOIN user_roles ur ON urm.role_id = ur.role_id
            WHERE urm.user_id = u.user_id AND urm.is_active = 1) as roles
    FROM users u
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
    WHERE u.email NOT LIKE '%@anonymous.tpb'
    ORDER BY u.deleted_at IS NOT NULL, u.created_at DESC
    LIMIT 50
")->fetchAll();

// Activity — meaningful civic events only
$activity = $pdo->query("
    SELECT * FROM (
        SELECT 'thought' as event_type,
               CONCAT('Published: \"', SUBSTRING(ut.content, 1, 60), '...\"') as description,
               ut.created_at as event_time,
               u.email as user_email, u.first_name
        FROM user_thoughts ut
        JOIN users u ON ut.user_id = u.user_id
        WHERE ut.status = 'published'

        UNION ALL

        SELECT 'vote' as event_type,
               CONCAT(uv.vote_type, ' on thought #', uv.thought_id) as description,
               uv.voted_at as event_time,
               u.email as user_email, u.first_name
        FROM user_thought_votes uv
        JOIN users u ON uv.user_id = u.user_id

        UNION ALL

        SELECT 'identity' as event_type,
               CONCAT('Email verified') as description,
               pl.earned_at as event_time,
               u.email as user_email, u.first_name
        FROM points_log pl
        JOIN users u ON pl.user_id = u.user_id
        WHERE pl.context_type = 'email_verified'

        UNION ALL

        SELECT 'identity' as event_type,
               CONCAT('Phone verified') as description,
               pl.earned_at as event_time,
               u.email as user_email, u.first_name
        FROM points_log pl
        JOIN users u ON pl.user_id = u.user_id
        WHERE pl.context_type = 'phone_verified'

        UNION ALL

        SELECT 'volunteer' as event_type,
               CONCAT('Volunteer application (', va.status, ')') as description,
               va.applied_at as event_time,
               u.email as user_email, u.first_name
        FROM volunteer_applications va
        JOIN users u ON va.user_id = u.user_id
    ) events
    ORDER BY event_time DESC
    LIMIT 50
")->fetchAll();

// Recent admin actions
$adminActions = $pdo->query("
    SELECT aa.*, u.email as admin_email
    FROM admin_actions aa
    LEFT JOIN users u ON aa.admin_user_id = u.user_id
    ORDER BY aa.created_at DESC
    LIMIT 20
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TPB Admin Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            min-height: 100%;
            overflow-x: auto;
            overflow-y: auto;
        }

        body {
            font-family: 'Georgia', serif;
            background: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #1a1a2a 0%, #2a2a4a 100%);
            padding: 20px 40px;
            border-bottom: 2px solid #d4af37;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #d4af37;
            font-size: 1.8em;
        }

        .header-right {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .header a {
            color: #888;
            text-decoration: none;
        }

        .header a:hover {
            color: #d4af37;
        }

        .admin-badge {
            color: #666;
            font-size: 0.85em;
        }

        .nav {
            background: #1a1a1a;
            padding: 0 40px;
            display: flex;
            gap: 0;
            border-bottom: 1px solid #333;
        }

        .nav a {
            color: #888;
            text-decoration: none;
            padding: 15px 25px;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .nav a:hover {
            color: #e0e0e0;
            background: #252525;
        }

        .nav a.active {
            color: #d4af37;
            border-bottom-color: #d4af37;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: rgba(76, 175, 80, 0.2);
            border: 1px solid #4caf50;
            color: #4caf50;
        }

        .message.error {
            background: rgba(244, 67, 54, 0.2);
            border: 1px solid #f44336;
            color: #f44336;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #1a1a2a;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 25px;
            text-align: center;
        }

        .stat-card .number {
            font-size: 2.5em;
            color: #d4af37;
            font-weight: bold;
        }

        .stat-card .label {
            color: #888;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .stat-card .sub {
            color: #666;
            font-size: 0.8em;
            margin-top: 3px;
        }

        .stat-card .sub .green { color: #4caf50; }

        /* Identity bar */
        .identity-bar {
            display: flex;
            gap: 0;
            margin-bottom: 30px;
            border-radius: 8px;
            overflow: hidden;
            background: #1a1a1a;
        }

        .identity-bar .segment {
            padding: 15px 20px;
            text-align: center;
            flex: 1;
            border-right: 1px solid #333;
        }

        .identity-bar .segment:last-child { border-right: none; }
        .identity-bar .segment .count { font-size: 1.8em; font-weight: bold; }
        .identity-bar .segment .name { font-size: 0.85em; color: #888; }
        .level-anonymous .count { color: #666; }
        .level-remembered .count { color: #2196f3; }
        .level-verified .count { color: #4caf50; }
        .level-vetted .count { color: #d4af37; }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            background: #1a1a1a;
            border-radius: 10px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #333;
        }

        th {
            background: #252525;
            color: #d4af37;
            font-weight: normal;
        }

        tr:hover {
            background: #202020;
        }

        .status-published { color: #4caf50; }
        .status-draft { color: #ff9800; }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            margin-right: 5px;
        }

        .btn-danger { background: #c62828; color: white; }
        .btn-danger:hover { background: #b71c1c; }
        .btn-warning { background: #f57c00; color: white; }
        .btn-warning:hover { background: #e65100; }
        .btn-success { background: #2e7d32; color: white; }
        .btn-success:hover { background: #1b5e20; }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .thought-content {
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .section-title {
            color: #d4af37;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #333;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 15px;
            background: #1a1a1a;
            margin-bottom: 5px;
            border-radius: 5px;
            font-size: 0.9em;
        }

        .activity-item .time { color: #666; }

        .event-thought { color: #4caf50; }
        .event-vote { color: #2196f3; }
        .event-identity { color: #d4af37; }
        .event-volunteer { color: #9c27b0; }

        .jurisdiction-federal { color: #2196f3; }
        .jurisdiction-state { color: #9c27b0; }
        .jurisdiction-town { color: #4caf50; }

        .action-items {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-item {
            background: #1a1a2a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .action-item.attention {
            border-color: #d4af37;
        }

        .action-item .count {
            font-size: 1.5em;
            font-weight: bold;
            color: #d4af37;
        }

        .roles-badge {
            color: #9c27b0;
            font-size: 0.8em;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TPB Admin</h1>
        <div class="header-right">
            <?php if ($adminUserId): ?>
                <span class="admin-badge">Admin: <?= htmlspecialchars($adminUser['email'] ?? 'user #' . $adminUserId) ?></span>
            <?php else: ?>
                <span class="admin-badge">Admin: password</span>
            <?php endif; ?>
            <a href="?tab=help">Help</a>
            <a href="index.php" target="_blank">View Site</a>
            <a href="?logout=1">Logout</a>
        </div>
    </div>

    <div class="nav">
        <a href="?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="?tab=volunteers" class="<?= $tab === 'volunteers' ? 'active' : '' ?>">Volunteers <?= $stats['pending_volunteers'] > 0 ? '<span style="background:#d4af37;color:#000;padding:2px 8px;border-radius:10px;font-size:0.8em;margin-left:5px;">'.$stats['pending_volunteers'].'</span>' : '' ?></a>
        <a href="?tab=thoughts" class="<?= $tab === 'thoughts' ? 'active' : '' ?>">Thoughts</a>
        <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <a href="?tab=activity" class="<?= $tab === 'activity' ? 'active' : '' ?>">Activity</a>
        <a href="?tab=bot" class="<?= $tab === 'bot' ? 'active' : '' ?>">Bot <?= $botStats['attempts_24h'] > 0 ? '<span style="background:#ef5350;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;margin-left:5px;">'.$botStats['attempts_24h'].'</span>' : '' ?></a>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <!-- GROWTH -->
            <h2 class="section-title">Growth</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= $stats['citizens'] ?></div>
                    <div class="label">Citizens</div>
                    <div class="sub"><?php if ($stats['new_week']): ?><span class="green">+<?= $stats['new_week'] ?> this week</span><?php else: ?>no new this week<?php endif; ?></div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $stats['active_week'] ?></div>
                    <div class="label">Active This Week</div>
                    <div class="sub">logged in last 7 days</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $stats['states'] ?></div>
                    <div class="label">States</div>
                    <div class="sub"><?= $stats['towns'] ?> towns</div>
                </div>
            </div>

            <!-- IDENTITY PROGRESSION -->
            <h2 class="section-title">Identity Verification</h2>
            <div class="identity-bar">
                <?php foreach ($identityLevels as $level): ?>
                <div class="segment level-<?= htmlspecialchars($level['level_name']) ?>">
                    <div class="count"><?= $level['cnt'] ?></div>
                    <div class="name"><?= ucfirst(htmlspecialchars($level['level_name'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- CIVIC ENGAGEMENT -->
            <h2 class="section-title">Civic Engagement</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= $stats['thoughts_total'] ?></div>
                    <div class="label">Thoughts Published</div>
                    <div class="sub"><?php if ($stats['thoughts_week']): ?><span class="green">+<?= $stats['thoughts_week'] ?> this week</span><?php else: ?>none this week<?php endif; ?> | <?= $stats['hidden_thoughts'] ?> hidden</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $stats['votes_total'] ?></div>
                    <div class="label">Votes Cast</div>
                    <div class="sub"><?php if ($stats['votes_week']): ?><span class="green">+<?= $stats['votes_week'] ?> this week</span><?php else: ?>none this week<?php endif; ?></div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $stats['approved_volunteers'] ?></div>
                    <div class="label">Volunteers</div>
                    <div class="sub"><?= $stats['open_tasks'] ?> open tasks</div>
                </div>
            </div>

            <!-- ACTION ITEMS -->
            <?php if ($stats['pending_volunteers'] > 0 || $stats['open_tasks'] > 0): ?>
            <h2 class="section-title">Needs Attention</h2>
            <div class="action-items">
                <?php if ($stats['pending_volunteers'] > 0): ?>
                <a href="?tab=volunteers" class="action-item attention" style="text-decoration:none;color:#e0e0e0;">
                    <span>Pending volunteer applications</span>
                    <span class="count"><?= $stats['pending_volunteers'] ?></span>
                </a>
                <?php endif; ?>
                <?php if ($stats['open_tasks'] > 0): ?>
                <div class="action-item">
                    <span>Open tasks needing assignment</span>
                    <span class="count"><?= $stats['open_tasks'] ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- RECENT CIVIC ACTIVITY -->
            <h2 class="section-title">Recent Civic Activity</h2>
            <?php if (empty($activity)): ?>
                <p style="color: #666; padding: 20px;">No civic activity yet.</p>
            <?php else: ?>
                <?php foreach (array_slice($activity, 0, 15) as $act): ?>
                <div class="activity-item">
                    <span>
                        <span class="event-<?= htmlspecialchars($act['event_type']) ?>"><?= htmlspecialchars($act['event_type']) ?></span>
                        <?= htmlspecialchars($act['first_name'] ?: $act['user_email']) ?>:
                        <?= htmlspecialchars($act['description']) ?>
                    </span>
                    <span class="time"><?= date('M j, g:i a', strtotime($act['event_time'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- ADMIN AUDIT LOG -->
            <?php if (!empty($adminActions)): ?>
            <h2 class="section-title">Admin Actions</h2>
            <?php foreach (array_slice($adminActions, 0, 10) as $action): ?>
                <div class="activity-item">
                    <span>
                        <span style="color: #ff9800;"><?= htmlspecialchars($action['action_type']) ?></span>
                        <?= htmlspecialchars($action['target_type']) ?> #<?= $action['target_id'] ?>
                        <?php if ($action['admin_email']): ?>
                            by <?= htmlspecialchars($action['admin_email']) ?>
                        <?php else: ?>
                            <span style="color: #666;">(password auth)</span>
                        <?php endif; ?>
                    </span>
                    <span class="time"><?= date('M j, g:i a', strtotime($action['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($tab === 'volunteers'): ?>
            <!-- VOLUNTEERS TAB -->
            <h2 class="section-title">Pending Applications (<?= $stats['pending_volunteers'] ?>)</h2>

            <?php if (empty($pendingVolunteers)): ?>
                <div style="background: #1a1a2a; padding: 40px; border-radius: 10px; text-align: center; color: #888;">
                    <p>No pending applications</p>
                </div>
            <?php else: ?>
                <?php foreach ($pendingVolunteers as $vol): ?>
                <div style="background: #1a1a2a; border: 1px solid #333; border-radius: 10px; padding: 25px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
                        <div>
                            <h3 style="color: #d4af37; margin-bottom: 5px;">
                                <?= htmlspecialchars(trim(($vol['first_name'] ?? '') . ' ' . ($vol['last_name'] ?? '')) ?: 'Unknown') ?>
                            </h3>
                            <p style="color: #888; font-size: 0.9em;">
                                <?= htmlspecialchars($vol['email']) ?> |
                                <?= htmlspecialchars($vol['phone'] ?? 'No phone') ?> |
                                <?= htmlspecialchars(($vol['town_name'] ?? '') . ', ' . ($vol['state_abbrev'] ?? '')) ?>
                            </p>
                            <p style="color: #666; font-size: 0.85em;">
                                Applied: <?= date('M j, Y g:i A', strtotime($vol['applied_at'])) ?> |
                                Age: <?= htmlspecialchars($vol['age_range'] ?? 'Not specified') ?> |
                                Skill: <?= htmlspecialchars($vol['skill_name'] ?? 'Not specified') ?>
                            </p>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="application_id" value="<?= $vol['application_id'] ?>">
                                <button type="submit" name="approve_volunteer" class="btn btn-success" onclick="return confirm('Approve this volunteer?')">Approve</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="application_id" value="<?= $vol['application_id'] ?>">
                                <button type="submit" name="reject_volunteer" class="btn btn-danger" onclick="return confirm('Reject this application?')">Reject</button>
                            </form>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <h4 style="color: #888; font-size: 0.85em; margin-bottom: 8px;">MOTIVATION</h4>
                            <p style="background: #252525; padding: 15px; border-radius: 8px; font-size: 0.95em;">
                                <?= nl2br(htmlspecialchars($vol['motivation'] ?? 'Not provided')) ?>
                            </p>
                        </div>
                        <div>
                            <h4 style="color: #888; font-size: 0.85em; margin-bottom: 8px;">BACKGROUND</h4>
                            <p style="background: #252525; padding: 15px; border-radius: 8px; font-size: 0.95em;">
                                <?= nl2br(htmlspecialchars($vol['experience'] ?? 'Not provided')) ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($vol['linkedin_url'] || $vol['website_url'] || $vol['github_url'] || $vol['vouch_name'] || $vol['other_verification']): ?>
                    <div style="margin-top: 15px;">
                        <h4 style="color: #888; font-size: 0.85em; margin-bottom: 8px;">VERIFICATION</h4>
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <?php if ($vol['linkedin_url']): ?>
                                <a href="<?= htmlspecialchars($vol['linkedin_url']) ?>" target="_blank" style="color: #4a90d9;">LinkedIn</a>
                            <?php endif; ?>
                            <?php if ($vol['website_url']): ?>
                                <a href="<?= htmlspecialchars($vol['website_url']) ?>" target="_blank" style="color: #4a90d9;">Website</a>
                            <?php endif; ?>
                            <?php if ($vol['github_url']): ?>
                                <a href="<?= htmlspecialchars($vol['github_url']) ?>" target="_blank" style="color: #4a90d9;">GitHub</a>
                            <?php endif; ?>
                            <?php if ($vol['vouch_name']): ?>
                                <span style="color: #888;">Vouch: <?= htmlspecialchars($vol['vouch_name']) ?> (<?= htmlspecialchars($vol['vouch_email'] ?? '') ?>)</span>
                            <?php endif; ?>
                            <?php if ($vol['other_verification']): ?>
                                <span style="color: #888;">Other: <?= htmlspecialchars($vol['other_verification']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($vol['age_range'] === '13-17' && $vol['parent_name']): ?>
                    <div style="margin-top: 15px; background: rgba(52, 152, 219, 0.1); border: 1px solid #3498db; padding: 10px 15px; border-radius: 8px;">
                        <span style="color: #3498db;">Minor - Parent: <?= htmlspecialchars($vol['parent_name']) ?> (<?= htmlspecialchars($vol['parent_email'] ?? '') ?>)</span>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top: 15px; color: #666; font-size: 0.85em;">
                        Availability: <?= htmlspecialchars($vol['availability'] ?? 'Not specified') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2 class="section-title" style="margin-top: 40px;">All Applications (<?= count($allVolunteers) ?>)</h2>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Reviewed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allVolunteers as $vol): ?>
                    <tr>
                        <td><?= $vol['application_id'] ?></td>
                        <td><?= htmlspecialchars(trim(($vol['first_name'] ?? '') . ' ' . ($vol['last_name'] ?? '')) ?: '-') ?></td>
                        <td><?= htmlspecialchars($vol['email'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(($vol['town_name'] ?? '') . ', ' . ($vol['state_abbrev'] ?? '')) ?></td>
                        <td>
                            <?php if ($vol['status'] === 'pending'): ?>
                                <span style="color: #f39c12;">Pending</span>
                            <?php elseif ($vol['status'] === 'accepted'): ?>
                                <span style="color: #2ecc71;">Approved</span>
                            <?php elseif ($vol['status'] === 'rejected'): ?>
                                <span style="color: #e74c3c;">Rejected</span>
                            <?php else: ?>
                                <span style="color: #888;"><?= htmlspecialchars($vol['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j', strtotime($vol['applied_at'])) ?></td>
                        <td><?= $vol['reviewed_at'] && $vol['reviewed_at'] !== '0000-00-00 00:00:00' ? date('M j', strtotime($vol['reviewed_at'])) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php elseif ($tab === 'thoughts'): ?>
            <!-- THOUGHTS TAB -->
            <h2 class="section-title">All Thoughts (<?= $stats['thoughts_total'] ?> published, <?= $stats['hidden_thoughts'] ?> hidden)</h2>

            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Content</th>
                        <th>Author</th>
                        <th>Location</th>
                        <th>Level</th>
                        <th>Score</th>
                        <th>Engagement</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($thoughts as $thought): ?>
                        <tr>
                            <td><?= $thought['thought_id'] ?></td>
                            <td class="thought-content" title="<?= htmlspecialchars($thought['content']) ?>">
                                <?= htmlspecialchars($thought['content']) ?>
                            </td>
                            <td><?= htmlspecialchars($thought['first_name'] ?? $thought['email'] ?? 'Anonymous') ?></td>
                            <td>
                                <?= htmlspecialchars($thought['town_name'] ?? '') ?>
                                <?= $thought['town_name'] && $thought['state_abbrev'] ? ', ' : '' ?>
                                <?= htmlspecialchars($thought['state_abbrev'] ?? '') ?>
                            </td>
                            <td class="jurisdiction-<?= $thought['jurisdiction_level'] ?>">
                                <?= ucfirst($thought['jurisdiction_level']) ?>
                            </td>
                            <td>
                                <?php $score = $thought['net_score']; ?>
                                <span style="color: <?= $score > 0 ? '#4caf50' : ($score < 0 ? '#f44336' : '#666') ?>;">
                                    <?= $score > 0 ? '+' : '' ?><?= $score ?>
                                </span>
                            </td>
                            <td><?= $thought['engagement'] ?> votes</td>
                            <td class="status-<?= $thought['status'] ?>">
                                <?= ucfirst($thought['status']) ?>
                            </td>
                            <td><?= date('M j', strtotime($thought['created_at'])) ?></td>
                            <td>
                                <?php if ($thought['status'] === 'published'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                        <button type="submit" name="hide_thought" class="btn btn-warning" title="Hide from public">Hide</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                        <button type="submit" name="restore_thought" class="btn btn-success" title="Make public again">Restore</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete this thought?');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                    <button type="submit" name="delete_thought" class="btn btn-danger" title="Delete permanently">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php elseif ($tab === 'users'): ?>
            <!-- USERS TAB -->
            <h2 class="section-title">Citizens (<?= $stats['citizens'] ?>)</h2>

            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Identity</th>
                        <th>Roles</th>
                        <th>Points</th>
                        <th>Thoughts</th>
                        <th>Votes</th>
                        <th>Devices</th>
                        <th>Browser</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr<?= $user['deleted_at'] ? ' style="opacity: 0.5;"' : '' ?>>
                            <td><?= $user['user_id'] ?></td>
                            <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '-') ?></td>
                            <td>
                                <?= htmlspecialchars($user['town_name'] ?? '') ?>
                                <?= $user['town_name'] && $user['state_abbrev'] ? ', ' : '' ?>
                                <?= htmlspecialchars($user['state_abbrev'] ?? '') ?>
                            </td>
                            <td>
                                <?php if ($user['deleted_at']): ?>
                                    <span style="color: #c62828;">Deleted</span>
                                <?php else:
                                    $levelColors = ['anonymous' => '#666', 'remembered' => '#2196f3', 'verified' => '#4caf50', 'vetted' => '#d4af37'];
                                    $levelColor = $levelColors[$user['identity_level'] ?? ''] ?? '#666';
                                ?>
                                    <span style="color: <?= $levelColor ?>;"><?= ucfirst(htmlspecialchars($user['identity_level'] ?? 'unknown')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['roles']): ?>
                                    <span class="roles-badge"><?= htmlspecialchars($user['roles']) ?></span>
                                <?php else: ?>
                                    <span style="color: #444;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($user['civic_points'] ?? 0) ?></td>
                            <td><?= $user['thought_count'] ?></td>
                            <td><?= $user['vote_count'] ?></td>
                            <td><?= $user['active_devices'] ?></td>
                            <td><?= parseBrowserName($user['last_ua'] ?? '') ?></td>
                            <td><?= date('M j', strtotime($user['created_at'])) ?></td>
                            <td>
                                <?php if ($user['deleted_at']): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Restore this user?');">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" name="restore_user" class="btn btn-success">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Mark this user as deleted? Their devices will be deactivated and thoughts hidden.');">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php elseif ($tab === 'activity'): ?>
            <!-- ACTIVITY TAB -->
            <h2 class="section-title">Civic Activity Feed</h2>

            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Who</th>
                        <th>What</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $act): ?>
                        <tr>
                            <td><?= date('M j, g:i a', strtotime($act['event_time'])) ?></td>
                            <td><span class="event-<?= htmlspecialchars($act['event_type']) ?>"><?= htmlspecialchars($act['event_type']) ?></span></td>
                            <td><?= htmlspecialchars($act['first_name'] ?: $act['user_email']) ?></td>
                            <td><?= htmlspecialchars($act['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

        <?php elseif ($tab === 'bot'): ?>
            <!-- BOT TAB -->
            <h2 class="section-title">Bot Detection</h2>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= $botStats['attempts_24h'] ?></div>
                    <div class="label">Attempts (24h)</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $botStats['attempts_7d'] ?></div>
                    <div class="label">Attempts (7d)</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $botStats['unique_ips_24h'] ?></div>
                    <div class="label">Unique IPs (24h)</div>
                </div>
                <div class="stat-card">
                    <div class="number" style="font-size:1.2em;"><?= htmlspecialchars($botStats['top_form']) ?></div>
                    <div class="label">Top Form (7d)</div>
                </div>
            </div>

            <?php if ($botOffenders): ?>
            <h2 class="section-title">Top Offenders (7d)</h2>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Attempts</th>
                        <th>Last Seen</th>
                        <th>Forms Targeted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($botOffenders as $offender): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($offender['ip_address']) ?></code></td>
                            <td style="color: <?= $offender['attempt_count'] >= 10 ? '#ef5350' : '#ff9800' ?>; font-weight: bold;"><?= $offender['attempt_count'] ?></td>
                            <td><?= date('M j, g:i a', strtotime($offender['last_seen'])) ?></td>
                            <td><?= htmlspecialchars($offender['forms_targeted']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

            <h2 class="section-title">Recent Attempts</h2>
            <?php if (empty($botAttempts)): ?>
                <p style="color: #888;">No bot attempts recorded.</p>
            <?php else: ?>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>IP</th>
                        <th>Form</th>
                        <th>Triggers</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($botAttempts as $attempt):
                        $triggers = [];
                        if ($attempt['honeypot_filled']) $triggers[] = 'honeypot';
                        if ($attempt['too_fast']) $triggers[] = 'too_fast';
                        if ($attempt['missing_referrer']) $triggers[] = 'no_referrer';
                    ?>
                        <tr>
                            <td><?= date('M j, g:i a', strtotime($attempt['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($attempt['ip_address']) ?></code></td>
                            <td><?= htmlspecialchars($attempt['form_name']) ?></td>
                            <td>
                                <?php foreach ($triggers as $t): ?>
                                    <span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:0.75em;margin:1px;background:<?= $t === 'honeypot' ? 'rgba(239,83,80,0.2);color:#ef5350' : ($t === 'too_fast' ? 'rgba(255,152,0,0.2);color:#ff9800' : 'rgba(158,158,158,0.2);color:#999') ?>;"><?= $t ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($attempt['user_agent']) ?>"><?= htmlspecialchars(substr($attempt['user_agent'], 0, 60)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

        <?php elseif ($tab === 'help'): ?>
            <!-- HELP TAB -->
            <?php
            $helpFile = __DIR__ . '/docs/admin-guide.md';
            if (file_exists($helpFile)) {
                $md = file_get_contents($helpFile);
                // Simple markdown to HTML conversion
                $html = htmlspecialchars($md);
                // Headers
                $html = preg_replace('/^### (.+)$/m', '<h3 style="color:#d4af37;margin:25px 0 10px;">$1</h3>', $html);
                $html = preg_replace('/^## (.+)$/m', '<h2 class="section-title">$1</h2>', $html);
                $html = preg_replace('/^# (.+)$/m', '<h1 style="color:#d4af37;margin-bottom:10px;">$1</h1>', $html);
                // Bold
                $html = preg_replace('/\*\*(.+?)\*\*/', '<strong style="color:#e0e0e0;">$1</strong>', $html);
                // Inline code
                $html = preg_replace('/`([^`]+)`/', '<code style="background:#252525;padding:2px 6px;border-radius:3px;font-size:0.9em;">$1</code>', $html);
                // Horizontal rules
                $html = preg_replace('/^---$/m', '<hr style="border:none;border-top:1px solid #333;margin:20px 0;">', $html);
                // Table rows
                $html = preg_replace_callback('/^\|(.+)\|$/m', function($m) {
                    $cells = array_map('trim', explode('|', trim($m[1])));
                    $out = '<tr>';
                    foreach ($cells as $cell) {
                        if (preg_match('/^[-:]+$/', $cell)) return '';
                        $out .= '<td style="padding:8px 12px;border-bottom:1px solid #333;">' . $cell . '</td>';
                    }
                    return $out . '</tr>';
                }, $html);
                // Wrap table rows in table
                $html = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<div class="table-wrap"><table style="width:100%;border-collapse:collapse;background:#1a1a1a;border-radius:8px;overflow:hidden;margin:15px 0;">$0</table></div>', $html);
                // List items
                $html = preg_replace('/^- (.+)$/m', '<li style="margin:4px 0;margin-left:20px;">$1</li>', $html);
                // Paragraphs (double newlines)
                $html = preg_replace('/\n\n/', '</p><p style="margin:10px 0;color:#ccc;">', $html);
                // Single newlines within content
                $html = str_replace("\n", '<br>', $html);
                echo '<div style="max-width:900px;line-height:1.8;color:#ccc;">';
                echo '<p style="margin:10px 0;color:#ccc;">' . $html . '</p>';
                echo '</div>';
            } else {
                echo '<p style="color:#888;">Help file not found at docs/admin-guide.md</p>';
            }
            ?>

        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 60 seconds (skip help tab)
        <?php if ($tab !== 'help'): ?>
        setTimeout(() => location.reload(), 60000);
        <?php endif; ?>
    </script>
</body>
</html>
