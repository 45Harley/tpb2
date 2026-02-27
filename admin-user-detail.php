<?php
/**
 * Admin User Detail Page
 * ======================
 * Shows complete user info when user_id is clicked in admin.php
 * Same auth as admin.php (role-based + password fallback)
 */

session_start();

$config = require __DIR__ . '/config.php';

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

// --- Auth: same as admin.php ---
$adminUser = null;
$dbUser = getUser($pdo);
if ($dbUser && empty($_SESSION['tpb_admin_logged_out'])) {
    $stmt = $pdo->prepare("SELECT 1 FROM user_role_membership WHERE user_id = ? AND role_id = 1 AND is_active = 1");
    $stmt->execute([$dbUser['user_id']]);
    if ($stmt->fetch()) {
        $adminUser = $dbUser;
        $_SESSION['tpb_admin'] = true;
    }
}

if (empty($_SESSION['tpb_admin'])) {
    header('Location: admin.php');
    exit;
}

// --- Get user_id from URL ---
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$userId) {
    header('Location: admin.php?tab=users');
    exit;
}

// --- Fetch all user data ---

// Core user record
$stmt = $pdo->prepare("
    SELECT u.*, s.state_name, s.abbreviation as state_abbrev, tw.town_name,
           il.level_name as identity_level, il.level_order
    FROM users u
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    echo "User not found.";
    exit;
}

// Identity & verification status
$stmt = $pdo->prepare("SELECT * FROM user_identity_status WHERE user_id = ?");
$stmt->execute([$userId]);
$identity = $stmt->fetch();

// Devices
$stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_active_at DESC");
$stmt->execute([$userId]);
$devices = $stmt->fetchAll();

// Roles
$stmt = $pdo->prepare("
    SELECT urm.*, ur.role_name, ur.description, ur.role_emoji
    FROM user_role_membership urm
    JOIN user_roles ur ON urm.role_id = ur.role_id
    WHERE urm.user_id = ?
");
$stmt->execute([$userId]);
$roles = $stmt->fetchAll();

// Volunteer applications
$stmt = $pdo->prepare("SELECT * FROM volunteer_applications WHERE user_id = ? ORDER BY applied_at DESC");
$stmt->execute([$userId]);
$volunteerApps = $stmt->fetchAll();

// Thoughts
$stmt = $pdo->prepare("SELECT * FROM user_thoughts WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$userId]);
$thoughts = $stmt->fetchAll();

// Points log
$stmt = $pdo->prepare("
    SELECT pl.*, pa.action_name, pa.description as action_desc
    FROM points_log pl
    JOIN point_actions pa ON pl.action_id = pa.action_id
    WHERE pl.user_id = ?
    ORDER BY pl.earned_at DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$pointsLog = $stmt->fetchAll();

// Login attempts
$stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE user_id = ? ORDER BY attempted_at DESC LIMIT 20");
$stmt->execute([$userId]);
$loginAttempts = $stmt->fetchAll();

// Poll votes
$stmt = $pdo->prepare("SELECT * FROM poll_votes WHERE user_id = ? ORDER BY voted_at DESC LIMIT 20");
$stmt->execute([$userId]);
$pollVotes = $stmt->fetchAll();

// Threat ratings
$stmt = $pdo->prepare("SELECT * FROM threat_ratings WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$userId]);
$threatRatings = $stmt->fetchAll();

// Threat responses
$stmt = $pdo->prepare("SELECT * FROM threat_responses WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$userId]);
$threatResponses = $stmt->fetchAll();

// AI memory
$stmt = $pdo->prepare("SELECT * FROM user_ai_memory WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$userId]);
$aiMemory = $stmt->fetchAll();

// Group memberships
$stmt = $pdo->prepare("SELECT * FROM user_group_membership WHERE user_id = ?");
$stmt->execute([$userId]);
$groups = $stmt->fetchAll();

// Milestones
$stmt = $pdo->prepare("SELECT * FROM user_milestones WHERE user_id = ? ORDER BY achieved_at DESC");
$stmt->execute([$userId]);
$milestones = $stmt->fetchAll();

// Trust scores
$stmt = $pdo->prepare("SELECT * FROM user_trust_scores WHERE user_id = ?");
$stmt->execute([$userId]);
$trustScores = $stmt->fetchAll();

// Admin actions targeting this user
$stmt = $pdo->prepare("SELECT aa.*, u.email as admin_email FROM admin_actions aa LEFT JOIN users u ON aa.admin_user_id = u.user_id WHERE aa.target_type = 'user' AND aa.target_id = ? ORDER BY aa.created_at DESC LIMIT 20");
$stmt->execute([$userId]);
$adminActions = $stmt->fetchAll();

// Helper
function formatDate($d) {
    if (!$d || $d === '0000-00-00 00:00:00') return '-';
    return date('M j, Y g:i a', strtotime($d));
}

function parseBrowserName($ua) {
    if (!$ua) return '-';
    if (strpos($ua, 'Edg/') !== false) return 'Edge';
    if (strpos($ua, 'OPR/') !== false || strpos($ua, 'Opera') !== false) return 'Opera';
    if (strpos($ua, 'Chrome/') !== false) return 'Chrome';
    if (strpos($ua, 'Firefox/') !== false) return 'Firefox';
    if (strpos($ua, 'Safari/') !== false) return 'Safari';
    if (strpos($ua, 'iPhone') !== false) return 'Safari (iOS)';
    if (strpos($ua, 'Android') !== false) return 'Android';
    return substr($ua, 0, 30);
}

$levelColors = ['anonymous' => '#666', 'remembered' => '#2196f3', 'verified' => '#4caf50', 'vetted' => '#d4af37'];
$levelColor = $levelColors[$user['identity_level'] ?? ''] ?? '#666';
$displayName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User #<?= $userId ?> — <?= htmlspecialchars($displayName) ?> — TPB Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Georgia', serif; background: #0a0a0a; color: #e0e0e0; line-height: 1.6; }

        .header {
            background: linear-gradient(135deg, #1a1a2a 0%, #2a2a4a 100%);
            padding: 20px 40px;
            border-bottom: 2px solid #d4af37;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { color: #d4af37; font-size: 1.8em; }
        .header a { color: #888; text-decoration: none; }
        .header a:hover { color: #d4af37; }
        .header-right { display: flex; gap: 20px; align-items: center; }

        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        .back-link { color: #888; text-decoration: none; font-size: 0.9em; }
        .back-link:hover { color: #d4af37; }

        .user-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin: 20px 0 30px 0;
            padding-bottom: 20px;
            border-bottom: 1px solid #333;
        }
        .user-header h2 { color: #d4af37; font-size: 1.6em; }
        .user-header .meta { color: #888; font-size: 0.9em; margin-top: 5px; }
        .user-header .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .badge-active { background: rgba(76,175,80,0.2); color: #4caf50; border: 1px solid #4caf50; }
        .badge-deleted { background: rgba(198,40,40,0.2); color: #c62828; border: 1px solid #c62828; }

        .section {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 10px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .section-header {
            background: #252525;
            padding: 12px 20px;
            color: #d4af37;
            font-size: 1.05em;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-header:hover { background: #2a2a2a; }
        .section-header .count { color: #888; font-size: 0.85em; }
        .section-body { padding: 20px; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .info-row {
            display: flex;
            border-bottom: 1px solid #252525;
            padding: 8px 0;
        }
        .info-label { color: #888; width: 160px; flex-shrink: 0; font-size: 0.9em; }
        .info-value { color: #e0e0e0; font-size: 0.9em; word-break: break-all; }
        .info-value.highlight { color: #d4af37; }
        .info-value.green { color: #4caf50; }
        .info-value.red { color: #c62828; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid #252525; font-size: 0.85em; }
        th { color: #d4af37; font-weight: normal; background: #1e1e1e; }
        tr:hover { background: #202020; }

        .empty-state { color: #555; font-style: italic; padding: 15px 0; }

        .points-total {
            font-size: 2em;
            color: #d4af37;
            font-weight: bold;
            text-align: center;
            padding: 10px;
        }

        .privacy-tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75em;
            margin-left: 5px;
        }
        .privacy-show { background: rgba(76,175,80,0.2); color: #4caf50; }
        .privacy-hide { background: rgba(198,40,40,0.2); color: #c62828; }

        @media (max-width: 800px) {
            .info-grid { grid-template-columns: 1fr; }
            .header { padding: 15px 20px; }
            .container { padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>TPB Admin</h1>
        <div class="header-right">
            <a href="admin.php?tab=users">&larr; Back to Users</a>
            <a href="admin.php?tab=dashboard">Dashboard</a>
            <a href="?logout=1">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="admin.php?tab=users" class="back-link">&larr; All Users</a>

        <div class="user-header">
            <div>
                <h2><?= htmlspecialchars($displayName) ?></h2>
                <div class="meta">
                    User #<?= $userId ?> &middot;
                    <span style="color: <?= $levelColor ?>;"><?= ucfirst(htmlspecialchars($user['identity_level'] ?? 'unknown')) ?></span> &middot;
                    Joined <?= formatDate($user['created_at']) ?>
                </div>
            </div>
            <div>
                <?php if ($user['deleted_at']): ?>
                    <span class="status-badge badge-deleted">Deleted <?= formatDate($user['deleted_at']) ?></span>
                <?php else: ?>
                    <span class="status-badge badge-active">Active</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- CORE PROFILE -->
        <div class="section">
            <div class="section-header">Core Profile</div>
            <div class="section-body">
                <div class="info-grid">
                    <div>
                        <div class="info-row"><span class="info-label">User ID</span><span class="info-value"><?= $user['user_id'] ?></span></div>
                        <div class="info-row"><span class="info-label">Username</span><span class="info-value"><?= htmlspecialchars($user['username'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($user['email'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">First Name</span><span class="info-value"><?= htmlspecialchars($user['first_name'] ?? '-') ?><span class="privacy-tag <?= $user['show_first_name'] ? 'privacy-show' : 'privacy-hide' ?>"><?= $user['show_first_name'] ? 'public' : 'hidden' ?></span></span></div>
                        <div class="info-row"><span class="info-label">Last Name</span><span class="info-value"><?= htmlspecialchars($user['last_name'] ?? '-') ?><span class="privacy-tag <?= $user['show_last_name'] ? 'privacy-show' : 'privacy-hide' ?>"><?= $user['show_last_name'] ? 'public' : 'hidden' ?></span></span></div>
                        <div class="info-row"><span class="info-label">Age Bracket</span><span class="info-value"><?= htmlspecialchars($user['age_bracket'] ?? '-') ?><span class="privacy-tag <?= $user['show_age_bracket'] ? 'privacy-show' : 'privacy-hide' ?>"><?= $user['show_age_bracket'] ? 'public' : 'hidden' ?></span></span></div>
                        <div class="info-row"><span class="info-label">Bio</span><span class="info-value"><?= htmlspecialchars($user['bio'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Password</span><span class="info-value"><?= !empty($user['password_hash']) ? htmlspecialchars($user['password_hash']) : '<span style="color:#c62828;">Not set</span>' ?></span></div>
                    </div>
                    <div>
                        <div class="info-row"><span class="info-label">Identity Level</span><span class="info-value" style="color: <?= $levelColor ?>;"><?= ucfirst(htmlspecialchars($user['identity_level'] ?? 'unknown')) ?> (<?= $user['identity_level_id'] ?>)</span></div>
                        <div class="info-row"><span class="info-label">Civic Points</span><span class="info-value highlight"><?= number_format($user['civic_points'] ?? 0) ?></span></div>
                        <div class="info-row"><span class="info-label">Onboarding</span><span class="info-value"><?= $user['onboarding_complete'] ? '<span class="green">Complete</span>' : '<span class="red">Incomplete</span>' ?></span></div>
                        <div class="info-row"><span class="info-label">Session ID</span><span class="info-value" style="font-size:0.8em;"><?= htmlspecialchars($user['session_id'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Created</span><span class="info-value"><?= formatDate($user['created_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Updated</span><span class="info-value"><?= formatDate($user['updated_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Last Login</span><span class="info-value"><?= formatDate($user['last_login_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Deleted</span><span class="info-value"><?= $user['deleted_at'] ? '<span class="red">' . formatDate($user['deleted_at']) . '</span>' : '-' ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- LOCATION & DISTRICTS -->
        <div class="section">
            <div class="section-header">Location & Districts</div>
            <div class="section-body">
                <div class="info-grid">
                    <div>
                        <div class="info-row"><span class="info-label">State</span><span class="info-value"><?= htmlspecialchars(($user['state_name'] ?? '') . ' (' . ($user['state_abbrev'] ?? '') . ')') ?></span></div>
                        <div class="info-row"><span class="info-label">Town</span><span class="info-value"><?= htmlspecialchars($user['town_name'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Zip Code</span><span class="info-value"><?= htmlspecialchars($user['zip_code'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Street Address</span><span class="info-value"><?= htmlspecialchars($user['street_address'] ?? '-') ?></span></div>
                    </div>
                    <div>
                        <div class="info-row"><span class="info-label">US Congress</span><span class="info-value"><?= htmlspecialchars($user['us_congress_district'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">State Senate</span><span class="info-value"><?= htmlspecialchars($user['state_senate_district'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">State House</span><span class="info-value"><?= htmlspecialchars($user['state_house_district'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Lat / Lng</span><span class="info-value"><?= ($user['latitude'] && $user['longitude']) ? $user['latitude'] . ', ' . $user['longitude'] : '-' ?></span></div>
                        <div class="info-row"><span class="info-label">Location Updated</span><span class="info-value"><?= formatDate($user['location_updated_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Districts Updated</span><span class="info-value"><?= formatDate($user['districts_updated_at']) ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- IDENTITY & VERIFICATION -->
        <div class="section">
            <div class="section-header">Identity & Verification</div>
            <div class="section-body">
                <?php if ($identity): ?>
                <div class="info-grid">
                    <div>
                        <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= htmlspecialchars($identity['phone'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Phone Verified</span><span class="info-value <?= $identity['phone_verified'] ? 'green' : 'red' ?>"><?= $identity['phone_verified'] ? 'Yes' : 'No' ?></span></div>
                        <div class="info-row"><span class="info-label">Phone Verified At</span><span class="info-value"><?= formatDate($identity['phone_verified_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Email Verified</span><span class="info-value <?= $identity['email_verified'] ? 'green' : 'red' ?>"><?= $identity['email_verified'] ? 'Yes' : 'No' ?></span></div>
                        <div class="info-row"><span class="info-label">Email Verified At</span><span class="info-value"><?= formatDate($identity['email_verified_at']) ?></span></div>
                    </div>
                    <div>
                        <div class="info-row"><span class="info-label">ID Verified</span><span class="info-value <?= $identity['id_verified'] ? 'green' : 'red' ?>"><?= $identity['id_verified'] ? 'Yes' : 'No' ?></span></div>
                        <div class="info-row"><span class="info-label">ID Verified At</span><span class="info-value"><?= formatDate($identity['id_verified_at']) ?></span></div>
                        <div class="info-row"><span class="info-label">Background Check</span><span class="info-value <?= $identity['background_checked'] ? 'green' : 'red' ?>"><?= $identity['background_checked'] ? 'Yes' : 'No' ?></span></div>
                        <div class="info-row"><span class="info-label">Background At</span><span class="info-value"><?= formatDate($identity['background_checked_at']) ?></span></div>
                    </div>
                </div>
                <?php else: ?>
                    <p class="empty-state">No identity verification record</p>
                <?php endif; ?>

                <?php if ($user['parent_email']): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #252525;">
                    <div class="info-row"><span class="info-label">Parent Email</span><span class="info-value"><?= htmlspecialchars($user['parent_email']) ?></span></div>
                    <div class="info-row"><span class="info-label">Parent Consent</span><span class="info-value <?= $user['parent_consent'] ? 'green' : 'red' ?>"><?= $user['parent_consent'] ? 'Yes' : 'No' ?></span></div>
                    <div class="info-row"><span class="info-label">Consent At</span><span class="info-value"><?= formatDate($user['parent_consent_at']) ?></span></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- DEVICES -->
        <div class="section">
            <div class="section-header">Devices <span class="count">(<?= count($devices) ?>)</span></div>
            <div class="section-body">
                <?php if ($devices): ?>
                <table>
                    <thead><tr><th>ID</th><th>Type</th><th>Browser</th><th>IP</th><th>Logins</th><th>Verified</th><th>Last Active</th><th>Active</th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td><?= $d['device_id'] ?></td>
                            <td><?= htmlspecialchars($d['device_type'] ?? '-') ?></td>
                            <td><?= parseBrowserName($d['device_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($d['ip_address'] ?? '-') ?></td>
                            <td><?= $d['login_count'] ?></td>
                            <td><?= formatDate($d['verified_at']) ?></td>
                            <td><?= formatDate($d['last_active_at']) ?></td>
                            <td style="color: <?= $d['is_active'] ? '#4caf50' : '#c62828' ?>;"><?= $d['is_active'] ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No devices</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ROLES -->
        <div class="section">
            <div class="section-header">Roles <span class="count">(<?= count($roles) ?>)</span></div>
            <div class="section-body">
                <?php if ($roles): ?>
                <table>
                    <thead><tr><th>Role</th><th>Description</th><th>Active</th><th>Assigned</th></tr></thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars(($r['role_emoji'] ?? '') . ' ' . $r['role_name']) ?></td>
                            <td><?= htmlspecialchars($r['description'] ?? '-') ?></td>
                            <td style="color: <?= $r['is_active'] ? '#4caf50' : '#c62828' ?>;"><?= $r['is_active'] ? 'Yes' : 'No' ?></td>
                            <td><?= formatDate($r['assigned_at'] ?? $r['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No roles assigned</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- VOLUNTEER APPLICATIONS -->
        <div class="section">
            <div class="section-header">Volunteer Applications <span class="count">(<?= count($volunteerApps) ?>)</span></div>
            <div class="section-body">
                <?php if ($volunteerApps): ?>
                <table>
                    <thead><tr><th>Status</th><th>Skills</th><th>Motivation</th><th>Applied</th></tr></thead>
                    <tbody>
                    <?php foreach ($volunteerApps as $va): ?>
                        <tr>
                            <td><?= htmlspecialchars($va['status'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($va['skills'] ?? $va['skill_areas'] ?? '-') ?></td>
                            <td style="max-width:300px;"><?= htmlspecialchars(substr($va['motivation'] ?? $va['why_volunteer'] ?? '-', 0, 200)) ?></td>
                            <td><?= formatDate($va['applied_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No volunteer applications</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- POINTS LOG -->
        <div class="section">
            <div class="section-header">
                Points Log
                <span class="count"><?= number_format($user['civic_points'] ?? 0) ?> total points &middot; <?= count($pointsLog) ?> actions shown</span>
            </div>
            <div class="section-body">
                <?php if ($pointsLog): ?>
                <table>
                    <thead><tr><th>Points</th><th>Action</th><th>Context</th><th>Page</th><th>Earned</th></tr></thead>
                    <tbody>
                    <?php foreach ($pointsLog as $pl): ?>
                        <tr>
                            <td style="color: #d4af37; font-weight: bold;">+<?= $pl['points_earned'] ?></td>
                            <td><?= htmlspecialchars($pl['action_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(($pl['context_type'] ?? '') . ($pl['context_id'] ? ' / ' . $pl['context_id'] : '')) ?></td>
                            <td><?= htmlspecialchars($pl['page_name'] ?? '-') ?></td>
                            <td><?= formatDate($pl['earned_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No points earned</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- THOUGHTS -->
        <div class="section">
            <div class="section-header">Thoughts <span class="count">(<?= count($thoughts) ?>)</span></div>
            <div class="section-body">
                <?php if ($thoughts): ?>
                <table>
                    <thead><tr><th>ID</th><th>Content</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($thoughts as $t): ?>
                        <tr>
                            <td><?= $t['thought_id'] ?></td>
                            <td style="max-width:400px;"><?= htmlspecialchars(substr($t['content'] ?? '', 0, 200)) ?></td>
                            <td style="color: <?= ($t['status'] ?? '') === 'published' ? '#4caf50' : '#ff9800' ?>;"><?= htmlspecialchars($t['status'] ?? '-') ?></td>
                            <td><?= formatDate($t['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No thoughts</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- LOGIN ATTEMPTS -->
        <div class="section">
            <div class="section-header">Login Attempts <span class="count">(<?= count($loginAttempts) ?>)</span></div>
            <div class="section-body">
                <?php if ($loginAttempts): ?>
                <table>
                    <thead><tr><th>Type</th><th>Success</th><th>IP Hash</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($loginAttempts as $la): ?>
                        <tr>
                            <td><?= htmlspecialchars($la['attempt_type'] ?? '-') ?></td>
                            <td style="color: <?= $la['success'] ? '#4caf50' : '#c62828' ?>;"><?= $la['success'] ? 'Yes' : 'No' ?></td>
                            <td style="font-size:0.75em;"><?= htmlspecialchars(substr($la['ip_hash'] ?? '', 0, 16)) ?>...</td>
                            <td><?= formatDate($la['attempted_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="empty-state">No login attempts</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- THREAT ACTIVITY -->
        <?php if ($threatRatings || $threatResponses): ?>
        <div class="section">
            <div class="section-header">Threat Activity <span class="count">(<?= count($threatRatings) ?> ratings, <?= count($threatResponses) ?> responses)</span></div>
            <div class="section-body">
                <?php if ($threatRatings): ?>
                <h4 style="color: #d4af37; margin-bottom: 10px;">Ratings</h4>
                <table>
                    <thead><tr><th>Threat ID</th><th>Rating</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($threatRatings as $tr): ?>
                        <tr>
                            <td><?= $tr['threat_id'] ?? '-' ?></td>
                            <td><?= $tr['rating'] ?? $tr['threat_level'] ?? '-' ?></td>
                            <td><?= formatDate($tr['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($threatResponses): ?>
                <h4 style="color: #d4af37; margin: 15px 0 10px 0;">Responses</h4>
                <table>
                    <thead><tr><th>Threat ID</th><th>Response</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($threatResponses as $tr): ?>
                        <tr>
                            <td><?= $tr['threat_id'] ?? '-' ?></td>
                            <td style="max-width:400px;"><?= htmlspecialchars(substr($tr['response'] ?? $tr['content'] ?? '', 0, 200)) ?></td>
                            <td><?= formatDate($tr['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- POLL VOTES -->
        <?php if ($pollVotes): ?>
        <div class="section">
            <div class="section-header">Poll Votes <span class="count">(<?= count($pollVotes) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Poll ID</th><th>Choice</th><th>Voted</th></tr></thead>
                    <tbody>
                    <?php foreach ($pollVotes as $pv): ?>
                        <tr>
                            <td><?= $pv['poll_id'] ?? $pv['question_id'] ?? '-' ?></td>
                            <td><?= htmlspecialchars($pv['choice'] ?? $pv['answer'] ?? $pv['vote_value'] ?? '-') ?></td>
                            <td><?= formatDate($pv['created_at'] ?? $pv['voted_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- AI MEMORY -->
        <?php if ($aiMemory): ?>
        <div class="section">
            <div class="section-header">AI Memory <span class="count">(<?= count($aiMemory) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Key</th><th>Value</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ($aiMemory as $am): ?>
                        <tr>
                            <td><?= htmlspecialchars($am['memory_key'] ?? $am['key'] ?? '-') ?></td>
                            <td style="max-width:400px;"><?= htmlspecialchars(substr($am['memory_value'] ?? $am['value'] ?? $am['content'] ?? '', 0, 200)) ?></td>
                            <td><?= formatDate($am['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- GROUP MEMBERSHIPS -->
        <?php if ($groups): ?>
        <div class="section">
            <div class="section-header">Group Memberships <span class="count">(<?= count($groups) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Group ID</th><th>Role</th><th>Joined</th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td><?= $g['group_id'] ?? '-' ?></td>
                            <td><?= htmlspecialchars($g['role'] ?? $g['membership_role'] ?? '-') ?></td>
                            <td><?= formatDate($g['joined_at'] ?? $g['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- MILESTONES -->
        <?php if ($milestones): ?>
        <div class="section">
            <div class="section-header">Milestones <span class="count">(<?= count($milestones) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Milestone</th><th>Achieved</th></tr></thead>
                    <tbody>
                    <?php foreach ($milestones as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['milestone_name'] ?? $m['milestone_key'] ?? '-') ?></td>
                            <td><?= formatDate($m['achieved_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- TRUST SCORES -->
        <?php if ($trustScores): ?>
        <div class="section">
            <div class="section-header">Trust Scores <span class="count">(<?= count($trustScores) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Category</th><th>Score</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($trustScores as $ts): ?>
                        <tr>
                            <td><?= htmlspecialchars($ts['category'] ?? $ts['trust_type'] ?? '-') ?></td>
                            <td style="color: #d4af37;"><?= $ts['score'] ?? $ts['trust_score'] ?? '-' ?></td>
                            <td><?= formatDate($ts['updated_at'] ?? $ts['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ADMIN ACTIONS ON THIS USER -->
        <?php if ($adminActions): ?>
        <div class="section">
            <div class="section-header">Admin Actions on This User <span class="count">(<?= count($adminActions) ?>)</span></div>
            <div class="section-body">
                <table>
                    <thead><tr><th>Action</th><th>By</th><th>Details</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php foreach ($adminActions as $aa): ?>
                        <tr>
                            <td><?= htmlspecialchars($aa['action_type'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($aa['admin_email'] ?? 'password login') ?></td>
                            <td style="max-width:300px; font-size:0.8em;"><?= htmlspecialchars(substr($aa['details'] ?? '', 0, 150)) ?></td>
                            <td><?= formatDate($aa['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- RAW FINGERPRINT / IP DATA -->
        <div class="section">
            <div class="section-header">Security & Tracking</div>
            <div class="section-body">
                <div class="info-row"><span class="info-label">Fingerprint Hash</span><span class="info-value" style="font-size:0.8em;"><?= htmlspecialchars($user['fingerprint_hash'] ?? '-') ?></span></div>
                <div class="info-row"><span class="info-label">Last IP Hash</span><span class="info-value" style="font-size:0.8em;"><?= htmlspecialchars($user['last_ip_hash'] ?? '-') ?></span></div>
                <div class="info-row"><span class="info-label">Magic Link Token</span><span class="info-value" style="font-size:0.8em;"><?= htmlspecialchars($user['magic_link_token'] ?? '-') ?></span></div>
                <div class="info-row"><span class="info-label">Magic Link Expires</span><span class="info-value"><?= formatDate($user['magic_link_expires']) ?></span></div>
                <div class="info-row"><span class="info-label">Official ID</span><span class="info-value"><?= $user['official_id'] ?? '-' ?></span></div>
            </div>
        </div>

    </div>
</body>
</html>
