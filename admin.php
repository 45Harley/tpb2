<?php
/**
 * TPB2 Admin Dashboard
 * ====================
 * View stats, manage thoughts, monitor activity
 * 
 * Password protected - change password below
 */

// Simple password protection
$ADMIN_PASSWORD = 'tpb2025admin'; // CHANGE THIS!

session_start();

// Handle login
if (isset($_POST['password'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['tpb_admin'] = true;
    } else {
        $loginError = 'Invalid password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION['tpb_admin']);
    header('Location: admin.php');
    exit;
}

// Check auth
if (!isset($_SESSION['tpb_admin'])) {
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
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🏛️ TPB Admin</h1>
            <form method="POST">
                <input type="password" name="password" placeholder="Admin Password" autofocus>
                <br>
                <button type="submit">Login</button>
            </form>
            <?php if (isset($loginError)): ?>
                <p class="error"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle actions
$message = '';
$messageType = '';

// Handle redirect messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'approved') {
        $message = 'Volunteer approved! Welcome email sent.';
        $messageType = 'success';
    } elseif ($_GET['msg'] === 'rejected') {
        $message = 'Application rejected. Email sent.';
        $messageType = 'success';
    }
}

// Delete thought
if (isset($_POST['delete_thought'])) {
    $thoughtId = (int)$_POST['thought_id'];
    $stmt = $pdo->prepare("DELETE FROM user_thought_votes WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $stmt = $pdo->prepare("DELETE FROM user_thoughts WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $message = "Thought #$thoughtId deleted";
    $messageType = 'success';
}

// Hide thought (set to draft)
if (isset($_POST['hide_thought'])) {
    $thoughtId = (int)$_POST['thought_id'];
    $stmt = $pdo->prepare("UPDATE user_thoughts SET status = 'draft' WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $message = "Thought #$thoughtId hidden";
    $messageType = 'success';
}

// Restore thought
if (isset($_POST['restore_thought'])) {
    $thoughtId = (int)$_POST['thought_id'];
    $stmt = $pdo->prepare("UPDATE user_thoughts SET status = 'published' WHERE thought_id = ?");
    $stmt->execute([$thoughtId]);
    $message = "Thought #$thoughtId restored";
    $messageType = 'success';
}

// Delete user
if (isset($_POST['delete_user'])) {
    $userId = (int)$_POST['user_id'];
    // Delete related data first
    $pdo->prepare("DELETE FROM user_thought_votes WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM user_thoughts WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM user_identity_status WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
    $message = "User #$userId and all their data deleted";
    $messageType = 'success';
}

// Approve volunteer
if (isset($_POST['approve_volunteer'])) {
    $appId = (int)$_POST['application_id'];
    
    // Get application details - ONLY if pending AND email not yet sent
    $stmt = $pdo->prepare("
        SELECT va.*, u.email, u.first_name, u.last_name
        FROM volunteer_applications va
        JOIN users u ON va.user_id = u.user_id
        WHERE va.application_id = ? AND va.status = 'pending' AND va.approval_email_sent = 0
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();
    
    if ($app) {
        // Update status AND mark email as sent (atomic)
        $stmt = $pdo->prepare("UPDATE volunteer_applications SET status = 'accepted', reviewed_at = NOW(), approval_email_sent = 1 WHERE application_id = ? AND approval_email_sent = 0");
        $rows = $stmt->execute([$appId]);
        
        // Only send email if we actually updated the row
        if ($stmt->rowCount() > 0) {
            $to = $app['email'];
            $subject = "Welcome to The People's Branch Volunteer Team!";
            $body = "Hi " . ($app['first_name'] ?: 'there') . ",\n\n";
            $body .= "Great news! Your volunteer application has been APPROVED.\n\n";
            $body .= "You now have access to the TPB Volunteer Workspace where you can:\n";
            $body .= "- View available tasks\n";
            $body .= "- Claim tasks to work on\n";
            $body .= "- Contribute to building The People's Branch\n\n";
            $body .= "Get started here:\n";
            $body .= "https://tpb2.sandgems.net/volunteer/\n\n";
            $body .= "Thank you for stepping up. Democracy needs people like you.\n\n";
            $body .= "— The People's Branch\n";
            
            $headers = "From: The People's Branch <noreply@sandgems.net>\r\n";
            mail($to, $subject, $body, $headers);
        }
        
        header('Location: admin.php?tab=volunteers&msg=approved');
        exit;
    } else {
        // Already processed - just redirect
        header('Location: admin.php?tab=volunteers');
        exit;
    }
}

// Reject volunteer
if (isset($_POST['reject_volunteer'])) {
    $appId = (int)$_POST['application_id'];
    
    // Get application details - ONLY if pending AND email not yet sent
    $stmt = $pdo->prepare("
        SELECT va.*, u.email, u.first_name, u.last_name
        FROM volunteer_applications va
        JOIN users u ON va.user_id = u.user_id
        WHERE va.application_id = ? AND va.status = 'pending' AND va.approval_email_sent = 0
    ");
    $stmt->execute([$appId]);
    $app = $stmt->fetch();
    
    if ($app) {
        // Update status AND mark email as sent (atomic)
        $stmt = $pdo->prepare("UPDATE volunteer_applications SET status = 'rejected', reviewed_at = NOW(), approval_email_sent = 1 WHERE application_id = ? AND approval_email_sent = 0");
        $stmt->execute([$appId]);
        
        // Only send email if we actually updated the row
        if ($stmt->rowCount() > 0) {
            $to = $app['email'];
            $subject = "Your TPB Volunteer Application";
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
            $body .= "— The People's Branch\n";
            
            $headers = "From: The People's Branch <noreply@sandgems.net>\r\n";
            mail($to, $subject, $body, $headers);
        }
        
        header('Location: admin.php?tab=volunteers&msg=rejected');
        exit;
    } else {
        // Already processed - just redirect
        header('Location: admin.php?tab=volunteers');
        exit;
    }
}

// Get stats
$stats = [
    'total_visits' => $pdo->query("SELECT COUNT(DISTINCT session_id) FROM points_log")->fetchColumn(),
    'verified_users' => $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN user_identity_status uis ON u.user_id = uis.user_id WHERE uis.email_verified = 1")->fetchColumn(),
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'thoughts' => $pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'published'")->fetchColumn(),
    'hidden_thoughts' => $pdo->query("SELECT COUNT(*) FROM user_thoughts WHERE status = 'draft'")->fetchColumn(),
    'votes' => $pdo->query("SELECT COUNT(*) FROM user_thought_votes")->fetchColumn(),
    'total_points' => $pdo->query("SELECT COALESCE(SUM(points_earned), 0) FROM points_log")->fetchColumn(),
    'beta_signups' => $pdo->query("SELECT COUNT(*) FROM points_log WHERE context_type = 'beta_signup'")->fetchColumn(),
    'pending_volunteers' => $pdo->query("SELECT COUNT(*) FROM volunteer_applications WHERE status = 'pending'")->fetchColumn(),
    'approved_volunteers' => $pdo->query("SELECT COUNT(*) FROM volunteer_applications WHERE status = 'accepted'")->fetchColumn(),
];

// Get pending volunteer applications
$pendingVolunteers = $pdo->query("
    SELECT 
        va.*,
        u.email,
        u.first_name,
        u.last_name,
        u.username,
        s.abbreviation as state_abbrev,
        tw.town_name,
        uis.phone,
        ss.set_name as skill_name
    FROM volunteer_applications va
    JOIN users u ON va.user_id = u.user_id
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
    LEFT JOIN skill_sets ss ON va.skill_set_id = ss.skill_set_id
    WHERE va.status = 'pending'
    ORDER BY va.applied_at DESC
")->fetchAll();

// Get all volunteer applications (for history)
$allVolunteers = $pdo->query("
    SELECT 
        va.*,
        u.email,
        u.first_name,
        u.last_name,
        s.abbreviation as state_abbrev,
        tw.town_name
    FROM volunteer_applications va
    JOIN users u ON va.user_id = u.user_id
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    ORDER BY va.applied_at DESC
    LIMIT 50
")->fetchAll();

// Get thoughts (all, including hidden)
$thoughts = $pdo->query("
    SELECT 
        t.thought_id,
        t.content,
        t.jurisdiction_level,
        t.status,
        t.upvotes,
        t.downvotes,
        t.created_at,
        u.email,
        u.first_name,
        s.abbreviation as state_abbrev,
        tw.town_name
    FROM user_thoughts t
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN states s ON t.state_id = s.state_id
    LEFT JOIN towns tw ON t.town_id = tw.town_id
    ORDER BY t.created_at DESC
    LIMIT 50
")->fetchAll();

// Get users
$users = $pdo->query("
    SELECT 
        u.user_id,
        u.email,
        u.first_name,
        u.last_name,
        u.civic_points,
        u.created_at,
        s.abbreviation as state_abbrev,
        tw.town_name,
        uis.email_verified,
        (SELECT COUNT(*) FROM user_thoughts WHERE user_id = u.user_id) as thought_count,
        (SELECT COUNT(*) FROM user_thought_votes WHERE user_id = u.user_id) as vote_count
    FROM users u
    LEFT JOIN states s ON u.current_state_id = s.state_id
    LEFT JOIN towns tw ON u.current_town_id = tw.town_id
    LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
    ORDER BY u.created_at DESC
    LIMIT 50
")->fetchAll();

// Get recent activity
$activity = $pdo->query("
    SELECT 
        context_type AS action_type,
        page_name,
        context_id AS element_id,
        session_id,
        points_earned,
        earned_at AS created_at
    FROM points_log
    ORDER BY earned_at DESC
    LIMIT 30
")->fetchAll();

// Current tab
$tab = $_GET['tab'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TPB Admin Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
        
        .status-published {
            color: #4caf50;
        }
        
        .status-draft {
            color: #ff9800;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85em;
            margin-right: 5px;
        }
        
        .btn-danger {
            background: #c62828;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b71c1c;
        }
        
        .btn-warning {
            background: #f57c00;
            color: white;
        }
        
        .btn-warning:hover {
            background: #e65100;
        }
        
        .btn-success {
            background: #2e7d32;
            color: white;
        }
        
        .btn-success:hover {
            background: #1b5e20;
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
        
        .activity-item .action {
            color: #4caf50;
        }
        
        .activity-item .time {
            color: #666;
        }
        
        .verified-badge {
            color: #4caf50;
        }
        
        .unverified-badge {
            color: #666;
        }
        
        .confirm-delete {
            display: none;
            background: rgba(198, 40, 40, 0.2);
            border: 1px solid #c62828;
            padding: 10px;
            border-radius: 5px;
            margin-top: 5px;
        }
        
        .jurisdiction-federal { color: #2196f3; }
        .jurisdiction-state { color: #9c27b0; }
        .jurisdiction-town { color: #4caf50; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏛️ TPB Admin Dashboard</h1>
        <div class="header-right">
            <a href="landing_page.html" target="_blank">View Site →</a>
            <a href="index.php" target="_blank">View Platform →</a>
            <a href="?logout=1">Logout</a>
        </div>
    </div>
    
    <div class="nav">
        <a href="?tab=dashboard" class="<?= $tab === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
        <a href="?tab=volunteers" class="<?= $tab === 'volunteers' ? 'active' : '' ?>">🙋 Volunteers <?= $stats['pending_volunteers'] > 0 ? '<span style="background:#d4af37;color:#000;padding:2px 8px;border-radius:10px;font-size:0.8em;margin-left:5px;">'.$stats['pending_volunteers'].'</span>' : '' ?></a>
        <a href="?tab=thoughts" class="<?= $tab === 'thoughts' ? 'active' : '' ?>">💭 Thoughts</a>
        <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">👥 Users</a>
        <a href="?tab=activity" class="<?= $tab === 'activity' ? 'active' : '' ?>">📈 Activity</a>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($tab === 'dashboard'): ?>
            <!-- DASHBOARD TAB -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['total_visits']) ?></div>
                    <div class="label">Unique Visitors</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['verified_users']) ?></div>
                    <div class="label">Verified Users</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['thoughts']) ?></div>
                    <div class="label">Published Thoughts</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['votes']) ?></div>
                    <div class="label">Total Votes</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['total_points']) ?></div>
                    <div class="label">Civic Points Earned</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= number_format($stats['beta_signups']) ?></div>
                    <div class="label">Beta Signups</div>
                </div>
            </div>
            
            <h2 class="section-title">Recent Activity</h2>
            <?php foreach (array_slice($activity, 0, 15) as $act): ?>
                <div class="activity-item">
                    <span>
                        <span class="action"><?= htmlspecialchars($act['action_type']) ?></span>
                        on <?= htmlspecialchars($act['page_name']) ?>
                        <?php if ($act['element_id']): ?>
                            (<?= htmlspecialchars($act['element_id']) ?>)
                        <?php endif; ?>
                        <span style="color: #d4af37;">+<?= $act['points_earned'] ?> pts</span>
                    </span>
                    <span class="time"><?= date('M j, g:i a', strtotime($act['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
            
        <?php elseif ($tab === 'volunteers'): ?>
            <!-- VOLUNTEERS TAB -->
            <h2 class="section-title">Pending Applications (<?= $stats['pending_volunteers'] ?>)</h2>
            
            <?php if (empty($pendingVolunteers)): ?>
                <div style="background: #1a1a2a; padding: 40px; border-radius: 10px; text-align: center; color: #888;">
                    <p style="font-size: 2em; margin-bottom: 10px;">✅</p>
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
                                <?= htmlspecialchars($vol['email']) ?> • 
                                <?= htmlspecialchars($vol['phone'] ?? 'No phone') ?> • 
                                <?= htmlspecialchars(($vol['town_name'] ?? '') . ', ' . ($vol['state_abbrev'] ?? '')) ?>
                            </p>
                            <p style="color: #666; font-size: 0.85em;">
                                Applied: <?= date('M j, Y g:i A', strtotime($vol['applied_at'])) ?> • 
                                Age: <?= htmlspecialchars($vol['age_range'] ?? 'Not specified') ?> • 
                                Skill: <?= htmlspecialchars($vol['skill_name'] ?? 'Not specified') ?>
                            </p>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="application_id" value="<?= $vol['application_id'] ?>">
                                <button type="submit" name="approve_volunteer" class="btn btn-success" onclick="return confirm('Approve this volunteer?')">✓ Approve</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="application_id" value="<?= $vol['application_id'] ?>">
                                <button type="submit" name="reject_volunteer" class="btn btn-danger" onclick="return confirm('Reject this application?')">✗ Reject</button>
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
                                <a href="<?= htmlspecialchars($vol['linkedin_url']) ?>" target="_blank" style="color: #4a90d9;">LinkedIn ↗</a>
                            <?php endif; ?>
                            <?php if ($vol['website_url']): ?>
                                <a href="<?= htmlspecialchars($vol['website_url']) ?>" target="_blank" style="color: #4a90d9;">Website ↗</a>
                            <?php endif; ?>
                            <?php if ($vol['github_url']): ?>
                                <a href="<?= htmlspecialchars($vol['github_url']) ?>" target="_blank" style="color: #4a90d9;">GitHub ↗</a>
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
                        <span style="color: #3498db;">🌟 Minor - Parent: <?= htmlspecialchars($vol['parent_name']) ?> (<?= htmlspecialchars($vol['parent_email'] ?? '') ?>)</span>
                    </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 15px; color: #666; font-size: 0.85em;">
                        Availability: <?= htmlspecialchars($vol['availability'] ?? 'Not specified') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <h2 class="section-title" style="margin-top: 40px;">All Applications (<?= count($allVolunteers) ?>)</h2>
            <table>
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
                                <span style="color: #f39c12;">⏳ Pending</span>
                            <?php elseif ($vol['status'] === 'accepted'): ?>
                                <span style="color: #2ecc71;">✅ Approved</span>
                            <?php elseif ($vol['status'] === 'rejected'): ?>
                                <span style="color: #e74c3c;">❌ Rejected</span>
                            <?php else: ?>
                                <span style="color: #888;"><?= htmlspecialchars($vol['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j', strtotime($vol['applied_at'])) ?></td>
                        <td><?= $vol['reviewed_at'] && $vol['reviewed_at'] !== '0000-00-00 00:00:00' ? date('M j', strtotime($vol['reviewed_at'])) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
        <?php elseif ($tab === 'thoughts'): ?>
            <!-- THOUGHTS TAB -->
            <h2 class="section-title">All Thoughts (<?= $stats['thoughts'] ?> published, <?= $stats['hidden_thoughts'] ?> hidden)</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Content</th>
                        <th>Author</th>
                        <th>Location</th>
                        <th>Level</th>
                        <th>Votes</th>
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
                            <td><?= htmlspecialchars($thought['email'] ?? $thought['first_name'] ?? 'Anonymous') ?></td>
                            <td>
                                <?= htmlspecialchars($thought['town_name'] ?? '') ?>
                                <?= $thought['town_name'] && $thought['state_abbrev'] ? ', ' : '' ?>
                                <?= htmlspecialchars($thought['state_abbrev'] ?? '') ?>
                            </td>
                            <td class="jurisdiction-<?= $thought['jurisdiction_level'] ?>">
                                <?= ucfirst($thought['jurisdiction_level']) ?>
                            </td>
                            <td>
                                <span style="color: #4caf50;">+<?= $thought['upvotes'] ?></span> /
                                <span style="color: #f44336;">-<?= $thought['downvotes'] ?></span>
                            </td>
                            <td class="status-<?= $thought['status'] ?>">
                                <?= ucfirst($thought['status']) ?>
                            </td>
                            <td><?= date('M j', strtotime($thought['created_at'])) ?></td>
                            <td>
                                <?php if ($thought['status'] === 'published'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                        <button type="submit" name="hide_thought" class="btn btn-warning" title="Hide from public">Hide</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                        <button type="submit" name="restore_thought" class="btn btn-success" title="Make public again">Restore</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete this thought?');">
                                    <input type="hidden" name="thought_id" value="<?= $thought['thought_id'] ?>">
                                    <button type="submit" name="delete_thought" class="btn btn-danger" title="Delete permanently">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
        <?php elseif ($tab === 'users'): ?>
            <!-- USERS TAB -->
            <h2 class="section-title">All Users (<?= $stats['total_users'] ?> total, <?= $stats['verified_users'] ?> verified)</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Location</th>
                        <th>Verified</th>
                        <th>Points</th>
                        <th>Thoughts</th>
                        <th>Votes</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['user_id'] ?></td>
                            <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '-') ?></td>
                            <td>
                                <?= htmlspecialchars($user['town_name'] ?? '') ?>
                                <?= $user['town_name'] && $user['state_abbrev'] ? ', ' : '' ?>
                                <?= htmlspecialchars($user['state_abbrev'] ?? '') ?>
                            </td>
                            <td>
                                <?php if ($user['email_verified']): ?>
                                    <span class="verified-badge">✅ Yes</span>
                                <?php else: ?>
                                    <span class="unverified-badge">❌ No</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($user['civic_points'] ?? 0) ?></td>
                            <td><?= $user['thought_count'] ?></td>
                            <td><?= $user['vote_count'] ?></td>
                            <td><?= date('M j', strtotime($user['created_at'])) ?></td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this user and ALL their data? This cannot be undone.');">
                                    <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                    <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
        <?php elseif ($tab === 'activity'): ?>
            <!-- ACTIVITY TAB -->
            <h2 class="section-title">Recent Activity Feed</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Page</th>
                        <th>Element</th>
                        <th>Session</th>
                        <th>Points</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $act): ?>
                        <tr>
                            <td><?= date('M j, g:i:s a', strtotime($act['created_at'])) ?></td>
                            <td class="action"><?= htmlspecialchars($act['action_type']) ?></td>
                            <td><?= htmlspecialchars($act['page_name']) ?></td>
                            <td><?= htmlspecialchars($act['element_id'] ?? '-') ?></td>
                            <td style="font-size: 0.8em; color: #666;"><?= htmlspecialchars(substr($act['session_id'] ?? '', 0, 20)) ?>...</td>
                            <td style="color: #d4af37;">+<?= $act['points_earned'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-refresh every 60 seconds
        setTimeout(() => location.reload(), 60000);
    </script>
</body>
</html>
