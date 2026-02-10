<?php
/**
 * People Power - 0t/index.php
 * Self-contained: handles API calls + displays page
 * 
 * Uses users table as source of truth for identity
 * - trump table stores referral chain only (user_id, referred_by, generation)
 * - users table stores email
 * - user_identity_status stores email_verified
 */

require_once __DIR__ . '/../config.php';
$config = require __DIR__ . '/../config.php';

// Database connection
function getDB($config) {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

// Send alert email
function sendAlert($config, $subject, $body) {
    $to = $config['trump']['notify_email'] ?? $config['admin_email'];
    $headers = "From: alerts@4tpb.org";
    @mail($to, "[0t Alert] " . $subject, $body, $headers);
}

// Find or create user by email
function findOrCreateUser($db, $email) {
    $stmt = $db->prepare("SELECT user_id FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        return $user['user_id'];
    }
    
    $username = 'pp_' . bin2hex(random_bytes(4));
    $stmt = $db->prepare("INSERT INTO users (email, username, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$email, $username]);
    $userId = $db->lastInsertId();
    
    $stmt = $db->prepare("INSERT INTO user_identity_status (user_id, email_verified) VALUES (?, 0)");
    $stmt->execute([$userId]);
    
    return $userId;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? '';
    
    try {
        $db = getDB($config);
        
        switch ($action) {
            
            // 1. Log arrival (when someone lands on page via referral)
            case 'arrive':
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $existingTrumpId = $input['user_id'] ?? null;
                $hasReferral = !empty($input['referred_by']);
                
                // Check if returning user (no referral) - just return their status
                if ($existingTrumpId && !$hasReferral) {
                    $stmt = $db->prepare("
                        SELECT t.id, t.user_id, t.generation,
                               uis.email_verified as verified
                        FROM trump t
                        LEFT JOIN users u ON t.user_id = u.user_id
                        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
                        WHERE t.id = ?
                    ");
                    $stmt->execute([$existingTrumpId]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        echo json_encode([
                            'ok' => true, 
                            'user_id' => (int)$existing['id'], 
                            'verified' => (bool)$existing['verified'],
                            'generation' => (int)$existing['generation']
                        ]);
                        break;
                    }
                }
                
                // Rate limit: max 10 arrivals per IP per hour
                if ($ip) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM trump WHERE ip = ? AND arrived_at > NOW() - INTERVAL 1 HOUR");
                    $stmt->execute([$ip]);
                    $ipCount = $stmt->fetchColumn();
                    if ($ipCount >= 10) {
                        sendAlert($config, "Rate limit hit - arrivals", 
                            "IP: $ip\nAttempts this hour: $ipCount\nAction: Blocked arrival");
                        echo json_encode(['ok' => false, 'error' => 'Rate limited']);
                        break;
                    }
                }
                
                // Create trump record (user_id=0 placeholder until verification)
                $stmt = $db->prepare("INSERT INTO trump (user_id, referred_by, generation, ip, arrived_at) VALUES (0, ?, ?, ?, NOW())");
                $stmt->execute([
                    $input['referred_by'] ?: null,
                    $input['generation'] ?? 1,
                    $ip
                ]);
                $trumpId = $db->lastInsertId();
                
                // Alert: high generation (chain spreading)
                $gen = $input['generation'] ?? 1;
                if ($gen >= 5) {
                    $refBy = $input['referred_by'] ?? 'direct';
                    sendAlert($config, "Chain spreading - Generation $gen reached",
                        "New trump ID: $trumpId\nGeneration: $gen\nReferred by: $refBy\nIP: $ip");
                }
                
                // Alert: spike check (50+ arrivals in last hour)
                $hourCount = $db->query("SELECT COUNT(*) FROM trump WHERE arrived_at > NOW() - INTERVAL 1 HOUR")->fetchColumn();
                if ($hourCount == 50 || $hourCount == 100 || $hourCount == 500) {
                    sendAlert($config, "Traffic spike - $hourCount arrivals in 1 hour",
                        "Total arrivals this hour: $hourCount\nLatest trump ID: $trumpId\nThis could be going viral!");
                }
                
                echo json_encode([
                    'ok' => true, 
                    'user_id' => $trumpId,
                    'verified' => false,
                    'generation' => (int)($input['generation'] ?? 1)
                ]);
                break;
            
            // 2. Verify email - Step 1: Send verification email
            case 'verify':
                $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
                $trumpId = $input['user_id'] ?? null;
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                
                if (!$email) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid email']);
                    break;
                }
                
                // Check if email already verified in users table
                $stmt = $db->prepare("
                    SELECT u.user_id, t.id as trump_id
                    FROM users u
                    JOIN user_identity_status uis ON u.user_id = uis.user_id
                    LEFT JOIN trump t ON t.user_id = u.user_id
                    WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))
                      AND uis.email_verified = 1
                ");
                $stmt->execute([$email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $returnId = $existing['trump_id'] ?: $existing['user_id'];
                    echo json_encode(['ok' => true, 'already_verified' => true, 'user_id' => (int)$returnId]);
                    break;
                }
                
                // Find or create user
                $userId = findOrCreateUser($db, $email);
                
                // Link trump record to user if we have a trump ID
                if ($trumpId) {
                    $stmt = $db->prepare("UPDATE trump SET user_id = ? WHERE id = ?");
                    $stmt->execute([$userId, $trumpId]);
                }
                
                // Generate verification token (store in users.magic_link_token)
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $stmt = $db->prepare("UPDATE users SET magic_link_token = ?, magic_link_expires = ? WHERE user_id = ?");
                $stmt->execute([$token, $expires, $userId]);
                
                // Send verification email
                $verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/0t/?verify=' . $token . '&email=' . urlencode($email);
                $subject = "Verify your email - People Power";
                $body = "Click this link to verify your email:\n\n$verifyUrl\n\nThis link expires in 24 hours.";
                $headers = "From: noreply@4tpb.org";
                @mail($email, $subject, $body, $headers);
                
                echo json_encode(['ok' => true, 'message' => 'Check your email for verification link']);
                break;
            
            // 2b. Complete verification when user clicks email link
            case 'complete_verify':
                $token = $input['token'] ?? null;
                $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
                
                if (!$token || !$email) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid token or email']);
                    break;
                }
                
                // Check if email already verified
                $stmt = $db->prepare("
                    SELECT u.user_id FROM users u
                    JOIN user_identity_status uis ON u.user_id = uis.user_id
                    WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(?))
                      AND uis.email_verified = 1
                ");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    echo json_encode(['ok' => false, 'error' => 'Email already verified']);
                    break;
                }
                
                // Find user with this token
                $stmt = $db->prepare("
                    SELECT user_id FROM users 
                    WHERE magic_link_token = ? 
                      AND magic_link_expires > NOW()
                      AND LOWER(TRIM(email)) = LOWER(TRIM(?))
                ");
                $stmt->execute([$token, $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid or expired token']);
                    break;
                }
                
                $userId = $user['user_id'];
                
                // Mark email as verified
                $stmt = $db->prepare("UPDATE user_identity_status SET email_verified = 1, email_verified_at = NOW() WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Clear the token
                $stmt = $db->prepare("UPDATE users SET magic_link_token = NULL, magic_link_expires = NULL WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Get trump.id for this user (to return to JS)
                $stmt = $db->prepare("SELECT id FROM trump WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
                $trump = $stmt->fetch(PDO::FETCH_ASSOC);
                $trumpId = $trump ? $trump['id'] : $userId;
                
                // Send admin notification
                $trumpConfig = $config['trump'] ?? [];
                $notifyEvery = $trumpConfig['notify_every'] ?? 1;
                $notifyEmail = $trumpConfig['notify_email'] ?? $config['admin_email'];
                
                // Count verified via join
                $count = $db->query("
                    SELECT COUNT(DISTINCT t.id) 
                    FROM trump t
                    JOIN users u ON t.user_id = u.user_id
                    JOIN user_identity_status uis ON u.user_id = uis.user_id
                    WHERE uis.email_verified = 1
                ")->fetchColumn();
                
                if ($count % $notifyEvery === 0) {
                    $subject = "People Power: New verified user #$count";
                    $body = "Email: $email\nUser ID: $userId\nTrump ID: $trumpId\nTotal verified: $count";
                    @mail($notifyEmail, $subject, $body, "From: noreply@4tpb.org");
                }
                
                // Milestone alerts
                $milestones = [100, 500, 1000, 5000, 10000, 50000, 100000];
                if (in_array($count, $milestones)) {
                    sendAlert($config, "MILESTONE: $count verified users!",
                        "You just hit $count verified users!\n\nLatest: $email\nUser ID: $userId\n\nKeep it going!");
                }
                
                echo json_encode(['ok' => true, 'user_id' => (int)$trumpId]);
                break;
            
            // 3. Get counts
            case 'counts':
                // Display offsets
                $offsetUSA = 1247;
                
                // Count verified users via join
                $usa = $db->query("
                    SELECT COUNT(DISTINCT t.id) 
                    FROM trump t
                    JOIN users u ON t.user_id = u.user_id
                    JOIN user_identity_status uis ON u.user_id = uis.user_id
                    WHERE uis.email_verified = 1
                ")->fetchColumn() + $offsetUSA;
                
                echo json_encode([
                    'ok' => true,
                    'usa' => (int)$usa
                ]);
                break;
            
            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Display offsets (seed numbers so nobody has to be "first")
$offsetUSA = 1247;

// Check cookie for returning user
$cookieUserId = isset($_COOKIE['tr_user_id']) ? (int)$_COOKIE['tr_user_id'] : null;
$isVerifiedUser = false;
$userGeneration = 1;

// Get initial counts for page load
try {
    $db = getDB($config);
    
    // Count verified via join
    $usaCount = ($db->query("
        SELECT COUNT(DISTINCT t.id) 
        FROM trump t
        JOIN users u ON t.user_id = u.user_id
        JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE uis.email_verified = 1
    ")->fetchColumn() ?: 0) + $offsetUSA;
    
    // Check if cookie user is verified
    if ($cookieUserId) {
        $stmt = $db->prepare("
            SELECT t.id, t.generation, uis.email_verified
            FROM trump t
            JOIN users u ON t.user_id = u.user_id
            JOIN user_identity_status uis ON u.user_id = uis.user_id
            WHERE t.id = ?
              AND uis.email_verified = 1
        ");
        $stmt->execute([$cookieUserId]);
        $cookieUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cookieUser) {
            $isVerifiedUser = true;
            $userGeneration = (int)$cookieUser['generation'];
        }
    }
} catch (Exception $e) {
    $usaCount = $offsetUSA;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Principle of People Power</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Georgia, serif;
            background: #1a1a2e;
            color: #e8e8e8;
            line-height: 1.8;
        }
        
        /* NAV */
        .site-nav {
            display: flex;
            justify-content: center;
            gap: 30px;
            padding: 15px 20px;
            background: #111;
            border-bottom: 1px solid #333;
        }
        
        .site-nav a {
            color: #888;
            text-decoration: none;
            padding: 8px 20px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .site-nav a:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }
        
        .site-nav a.active {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        /* MAIN CONTENT */
        .content {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        h1 {
            font-size: 2rem;
            font-weight: 400;
            text-align: center;
            margin-bottom: 30px;
            color: #fff;
        }
        
        .subtitle {
            text-align: center;
            color: #888;
            font-size: 1rem;
            margin-top: -20px;
            margin-bottom: 30px;
        }
        
        .subtitle a {
            color: #d4af37;
            text-decoration: none;
        }
        
        .subtitle a:hover {
            text-decoration: underline;
        }
        
        h2 {
            color: #d4af37;
            font-size: 1.3rem;
            margin-top: 40px;
            margin-bottom: 15px;
        }
        
        p {
            margin-bottom: 20px;
        }
        
        .highlight {
            color: #d4af37;
            font-style: italic;
        }
        
        .keyword {
            color: #4fc3f7;
            font-weight: bold;
        }
        
        blockquote {
            border-left: 3px solid #d4af37;
            padding-left: 20px;
            margin: 25px 0;
            font-style: italic;
            color: #fff;
        }
        
        a {
            color: #4fc3f7;
        }
        
        /* Counter Display */
        .counter-section {
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
            padding: 30px;
            margin: 40px 0;
            text-align: center;
        }
        
        .counter-label {
            text-transform: uppercase;
            letter-spacing: 3px;
            color: #888;
            font-size: 0.85rem;
            margin-bottom: 15px;
        }
        
        .counter {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-bottom: 15px;
        }
        
        .digit {
            background: #2a2a4a;
            border: 2px solid #3a3a6a;
            border-radius: 6px;
            width: 50px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.2rem;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #4fc3f7;
        }
        
        .comma {
            font-size: 2.2rem;
            color: #4fc3f7;
            display: flex;
            align-items: flex-end;
            padding-bottom: 8px;
        }
        
        .counter-note {
            color: #888;
            font-size: 0.95rem;
        }
        
        /* Generation table */
        table {
            width: 100%;
            max-width: 300px;
            margin: 20px auto;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 8px 15px;
            text-align: center;
            border-bottom: 1px solid #333;
        }
        
        th {
            color: #d4af37;
        }
        
        td:last-child {
            color: #4fc3f7;
            font-family: 'Courier New', monospace;
        }
        
        /* ACTION SECTION */
        .action-section {
            background: rgba(212, 175, 55, 0.05);
            border: 1px solid #d4af37;
            border-radius: 8px;
            padding: 30px;
            margin: 40px 0;
        }
        
        .action-section h3 {
            color: #d4af37;
            font-size: 1.1rem;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .verify-row {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .verify-row input {
            flex: 1;
            padding: 14px;
            font-size: 1rem;
            font-family: Georgia, serif;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 4px;
            color: #fff;
        }
        
        .verify-row input:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        .verify-row input::placeholder {
            color: #555;
        }
        
        .button {
            background: #d4af37;
            color: #000;
            padding: 14px 28px;
            font-size: 1rem;
            font-family: Georgia, serif;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .button:hover {
            background: #e5c54a;
        }
        
        .invite-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }
        
        .invite-section > p {
            color: #888;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .button.secondary {
            background: transparent;
            border: 1px solid #d4af37;
            color: #d4af37;
            display: block;
            width: 100%;
            text-align: center;
        }
        
        .button.secondary:hover {
            background: rgba(212, 175, 55, 0.1);
        }
        
        /* Message options */
        .message-options h3 {
            font-size: 1rem;
            font-weight: normal;
            color: #888;
            margin-bottom: 12px;
        }
        
        .message-option {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 12px 15px;
            margin-bottom: 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: border-color 0.2s;
        }
        
        .message-option:hover {
            border-color: #d4af37;
        }
        
        .message-option.selected {
            border-color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        .message-option p {
            color: #ccc;
            font-size: 0.95rem;
            margin: 0;
        }
        
        .message-option .select-indicator {
            color: #d4af37;
            font-size: 0.8rem;
        }
        
        .send-options {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #333;
        }
        
        .send-options h3 {
            font-size: 1rem;
            font-weight: normal;
            color: #888;
            margin-bottom: 12px;
        }
        
        /* Copy block */
        .copy-block {
            background: #111;
            border: 1px solid #333;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .copy-block-label {
            font-size: 0.85rem;
            color: #888;
            margin-bottom: 10px;
        }
        
        .copy-block-text {
            background: #0a0a0a;
            border: 1px solid #444;
            border-radius: 4px;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #4fc3f7;
            word-break: break-all;
            margin-bottom: 10px;
            user-select: all;
            white-space: pre-wrap;
        }
        
        .copy-btn {
            background: #333;
            color: #ccc;
            border: 1px solid #444;
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .copy-btn:hover {
            background: #444;
            color: #fff;
        }
        
        .copy-btn.copied {
            background: #2a4a2a;
            border-color: #4a4;
            color: #4a4;
        }
        
        /* Footer */
        .footer {
            margin-top: 50px;
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 30px;
        }
        
        .truth-line {
            font-size: 1.2rem;
            color: #fff;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .progress-line {
            font-style: italic;
            color: #d4af37;
            font-size: 1.1rem;
        }
        
        /* Welcome back */
        .welcome-back {
            background: rgba(100, 200, 100, 0.1);
            border-color: #4a4;
        }
        
        .welcome-back h3 {
            color: #4a4;
        }
        
        /* Responsive */
        @media (max-width: 600px) {
            h1 { font-size: 1.7rem; }
            .digit { width: 40px; height: 55px; font-size: 1.8rem; }
            .verify-row { flex-direction: column; }
            .site-nav { gap: 15px; }
            .site-nav a { padding: 8px 15px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <nav class="site-nav">
        <a href="/">← TPB</a>
        <a href="/0t/" class="active">People Power</a>
        <a href="/0t/record.php">The Record</a>
    </nav>
    
    <div class="content">
        <h1>The Principle of People Power</h1>
        <p class="subtitle">Sponsored by <a href="/">TPB - The People's Branch</a></p>
        
        <h2>Unity Is Power</h2>
        
        <p>The founding fathers had it right: <span class="highlight">"United we stand; divided we fall."</span></p>
        
        <p>Unity is the principle of people power — united in <span class="keyword">thought</span> and <span class="keyword">action</span>.</p>
        
        <p>Division is the absence of unity. It creates chaos, fear, hate, violence, and wars — ultimately destroying itself. Sounds like repeated human history, doesn't it?</p>
        
        <h2>The Purpose</h2>
        
        <p>This website demonstrates the principle of united <span class="keyword">thought</span> and <span class="keyword">action</span>.</p>
        
        <p><strong>The Thought:</strong></p>
        <blockquote>
            "Donald J. Trump is not fit to be President of the <span class="keyword">United</span> States of America."
        </blockquote>
        
        <p><strong>The Action:</strong> Two simple steps:</p>
        <p>1. Verify your email to add your voice to the count<br>
        2. Invite two friends who promise to do the same</p>
        
        <p>That's it. Bookmark this page and watch the power of united voices grow.</p>
        
        <!-- COUNTER -->
        <div class="counter-section">
            <p class="counter-label">Voices United</p>
            <div class="counter" id="counterDisplay"></div>
            <p class="counter-note">United in <span class="keyword">thought</span> and <span class="keyword">action</span></p>
        </div>
        
        <!-- ACTION -->
        <div class="action-section" id="actionSection">
            <h3>Add Your Voice</h3>
            
            <div class="verify-row">
                <input type="email" id="yourEmail" placeholder="Your email address">
                <button class="button" onclick="verifyEmail()">Verify</button>
            </div>
            
            <div class="invite-section">
                <p>After verifying, share with friends:</p>
                
                <div class="copy-block">
                    <p class="copy-block-label">Your personal link:</p>
                    <div class="copy-block-text" id="inviteText"></div>
                    <button class="copy-btn" onclick="copyLink()">Copy Link</button>
                </div>
                
                <p style="color: #888; font-size: 0.9rem; margin-top: 15px;">Share with friends — paste in email, text, social media, anywhere.</p>
            </div>
        </div>
        
        <h2>Question the Thought?</h2>
        
        <p>Read <a href="/0t/record.php">The Record</a> — documented facts, court rulings, and verified sources — then decide for yourself.</p>
        
        <h2>How It Works</h2>
        
        <p>When one person invites 2 people, and those 2 each invite 2 more, and those 4 each invite 2 more... people power builds quickly, doubling with each generation:</p>
        
        <table>
            <tr><th>Generation</th><th>Voices</th></tr>
            <tr><td>1</td><td>2</td></tr>
            <tr><td>2</td><td>4</td></tr>
            <tr><td>3</td><td>8</td></tr>
            <tr><td>4</td><td>16</td></tr>
            <tr><td>5</td><td>32</td></tr>
            <tr><td>10</td><td>1,024</td></tr>
            <tr><td>20</td><td>1,048,576</td></tr>
            <tr><td>30</td><td>1,073,741,824</td></tr>
        </table>
        
        <p><strong>30 generations = over 1 billion voices.</strong></p>
        
        <p>The count demonstrates the incredible power of united <span class="keyword">thought</span> and <span class="keyword">action</span>.</p>
        
        <div class="footer">
            <p class="truth-line">Truth is self-evident. Provable. Infinite. It only needs to be revealed.</p>
            <p class="progress-line">That's called progress.</p>
        </div>
    </div>
    
    <script>
        // Cookie helpers
        function setCookie(name, value, days) {
            const expires = new Date(Date.now() + days * 864e5).toUTCString();
            document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/';
        }
        function getCookie(name) {
            const value = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
            return value ? decodeURIComponent(value.pop()) : null;
        }
        function deleteCookie(name) {
            document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        }
        
        let userId = getCookie('tr_user_id') || null;
        let isVerified = <?= $isVerifiedUser ? 'true' : 'false' ?>;
        let selectedMessage = 0;
        let userState = null;
        let userTown = null;
        let userGeneration = <?= $userGeneration ?>;
        
        // Build counter display
        function buildCounter(count) {
            const str = String(count).padStart(6, '0');
            let html = '';
            for (let i = 0; i < 6; i++) {
                if (i === 3) html += '<div class="comma">,</div>';
                html += '<div class="digit" id="d' + i + '">' + str[i] + '</div>';
            }
            document.getElementById('counterDisplay').innerHTML = html;
        }
        
        // Initialize counter
        buildCounter(<?= $usaCount ?>);
        
        // Get tracking link
        function getTrackingLink() {
            const nextGen = userGeneration + 1;
            return window.location.origin + '/0t/?ref=' + (userId || 'anon') + '&gen=' + nextGen;
        }
        
        // Copy invite
        function copyInvite() {
            const text = document.getElementById('inviteText').textContent;
            if (text === 'Select a message first') {
                alert('Please select a message first.');
                return;
            }
            navigator.clipboard.writeText(text).then(() => {
                const btn = document.querySelector('.copy-btn');
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = 'Copy to Clipboard';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(() => {
                alert('Press Ctrl+C to copy');
            });
        }
        
        // On page load
        (function() {
            if (isVerified) {
                showWelcomeBack();
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            const ref = urlParams.get('ref');
            const gen = urlParams.get('gen');
            const verifyToken = urlParams.get('verify');
            const verifyEmailParam = urlParams.get('email');
            
            // Handle email verification link
            if (verifyToken && verifyEmailParam) {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'complete_verify',
                        token: verifyToken,
                        email: verifyEmailParam
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        userId = data.user_id;
                        setCookie('tr_user_id', userId, 365);
                        isVerified = true;
                        alert('Email verified! Welcome to People Power.');
                        window.history.replaceState({}, '', window.location.pathname);
                        showWelcomeBack();
                        updateCounts();
                    } else {
                        alert(data.error || 'Verification failed.');
                    }
                })
                .catch(() => alert('Error verifying.'));
                return;
            }
            
            // Referral link - new person
            if (ref) {
                deleteCookie('tr_user_id');
                userId = null;
                isVerified = false;
            }
            
            // Check with server
            if (userId || ref || gen) {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'arrive',
                        user_id: userId,
                        referred_by: ref,
                        generation: parseInt(gen) || 1
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok && data.user_id) {
                        userId = data.user_id;
                        setCookie('tr_user_id', userId, 365);
                        
                        if (data.generation) {
                            userGeneration = data.generation;
                        }
                        
                        if (data.verified && !isVerified) {
                            isVerified = true;
                            showWelcomeBack();
                        }
                        
                        if (data.state) userState = data.state;
                        if (data.town) userTown = data.town;
                        
                        updateCounts();
                    }
                })
                .catch(() => {});
            } else {
                updateCounts();
            }
        })();
        
        function updateCounts() {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'counts',
                    state: userState,
                    town: userTown
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const str = String(data.usa).padStart(6, '0');
                    for (let i = 0; i < 6; i++) {
                        const el = document.getElementById('d' + i);
                        if (el) el.textContent = str[i];
                    }
                }
            })
            .catch(() => {});
        }
        
        setInterval(updateCounts, 30000);
        
        function verifyEmail() {
            const email = document.getElementById('yourEmail').value;
            if (!email || !email.includes('@')) {
                alert('Please enter a valid email.');
                return;
            }
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'verify',
                    email: email,
                    user_id: userId
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (data.already_verified) {
                        // Returning user - set their cookie and show welcome
                        userId = data.user_id;
                        setCookie('tr_user_id', userId, 365);
                        isVerified = true;
                        alert('Welcome back! You are already verified.');
                        showWelcomeBack();
                        updateCounts();
                    } else {
                        alert('Check your email for a verification link!');
                    }
                } else {
                    alert(data.error || 'Error.');
                }
            })
            .catch(() => alert('Error connecting.'));
        }
        
        function copyLink() {
            const link = getTrackingLink();
            navigator.clipboard.writeText(link).then(() => {
                const btn = document.querySelector('.copy-btn');
                btn.textContent = 'Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = 'Copy Link';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(() => {
                alert('Press Ctrl+C to copy');
            });
        }
        
        // Set link on page load
        document.addEventListener('DOMContentLoaded', function() {
            const el = document.getElementById('inviteText');
            if (el) {
                el.textContent = getTrackingLink();
            }
        });
        
        function showWelcomeBack() {
            const section = document.getElementById('actionSection');
            section.classList.add('welcome-back');
            section.innerHTML = `
                <h3>Welcome Back!</h3>
                <p style="text-align: center; color: #ccc; margin-bottom: 20px;">Your voice is counted. Share with more friends:</p>
                
                <div class="copy-block">
                    <p class="copy-block-label">Your personal link:</p>
                    <div class="copy-block-text" id="inviteText">${getTrackingLink()}</div>
                    <button class="copy-btn" onclick="copyLink()">Copy Link</button>
                </div>
                
                <p style="color: #888; font-size: 0.9rem; margin-top: 15px; text-align: center;">Share with friends — paste in email, text, social media, anywhere.</p>
            `;
        }
    </script>
</body>
</html>
