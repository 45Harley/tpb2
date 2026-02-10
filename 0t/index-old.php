<?php
/**
 * The Record - 0t/index.php
 * Self-contained: handles API calls + displays page
 * Uses: trump table, config.php
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

// Get location from IP
function getLocationFromIP($ip) {
    if (!$ip || $ip === '127.0.0.1' || $ip === '::1') {
        return ['state' => null, 'town' => null];
    }
    
    $url = "http://ip-api.com/json/{$ip}?fields=status,regionName,city";
    $context = stream_context_create(['http' => ['timeout' => 2]]);
    $response = @file_get_contents($url, false, $context);
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            return [
                'state' => $data['regionName'] ?? null,
                'town' => $data['city'] ?? null
            ];
        }
    }
    return ['state' => null, 'town' => null];
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
                $existingUserId = $input['user_id'] ?? null;
                $hasReferral = !empty($input['referred_by']);
                
                // Check if returning user (no referral) - just return their status
                if ($existingUserId && !$hasReferral) {
                    $stmt = $db->prepare("SELECT id, verified, state, town, generation FROM trump WHERE id = ?");
                    $stmt->execute([$existingUserId]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($existing) {
                        // If no location yet, try to get it now
                        if (!$existing['state'] && $ip) {
                            $location = getLocationFromIP($ip);
                            if ($location['state']) {
                                $stmt = $db->prepare("UPDATE trump SET state = ?, town = ? WHERE id = ?");
                                $stmt->execute([$location['state'], $location['town'], $existingUserId]);
                                $existing['state'] = $location['state'];
                                $existing['town'] = $location['town'];
                            }
                        }
                        echo json_encode([
                            'ok' => true, 
                            'user_id' => (int)$existing['id'], 
                            'verified' => (bool)$existing['verified'],
                            'state' => $existing['state'],
                            'town' => $existing['town'],
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
                        // Alert: rate limit hit
                        sendAlert($config, "Rate limit hit - arrivals", 
                            "IP: $ip\nAttempts this hour: $ipCount\nAction: Blocked arrival");
                        echo json_encode(['ok' => false, 'error' => 'Rate limited']);
                        break;
                    }
                }
                
                // Get location from IP
                $location = getLocationFromIP($ip);
                
                $stmt = $db->prepare("INSERT INTO trump (referred_by, generation, state, town, ip, arrived_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $input['referred_by'] ?: null,
                    $input['generation'] ?? 1,
                    $location['state'],
                    $location['town'],
                    $ip
                ]);
                $userId = $db->lastInsertId();
                
                // Alert: high generation (chain spreading)
                $gen = $input['generation'] ?? 1;
                if ($gen >= 5) {
                    $refBy = $input['referred_by'] ?? 'direct';
                    sendAlert($config, "Chain spreading - Generation $gen reached",
                        "New user ID: $userId\nGeneration: $gen\nReferred by: $refBy\nIP: $ip\nState: " . ($location['state'] ?? 'unknown'));
                }
                
                // Alert: spike check (50+ arrivals in last hour)
                $hourCount = $db->query("SELECT COUNT(*) FROM trump WHERE arrived_at > NOW() - INTERVAL 1 HOUR")->fetchColumn();
                if ($hourCount == 50 || $hourCount == 100 || $hourCount == 500) {
                    sendAlert($config, "Traffic spike - $hourCount arrivals in 1 hour",
                        "Total arrivals this hour: $hourCount\nLatest user ID: $userId\nThis could be going viral!");
                }
                
                $userGen = $input['generation'] ?? 1;
                echo json_encode([
                    'ok' => true, 
                    'user_id' => $userId,
                    'verified' => false,
                    'state' => $location['state'],
                    'town' => $location['town'],
                    'generation' => (int)$userGen
                ]);
                break;
            
            // 2. Verify email - Step 1: Send verification email
            case 'verify':
                $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
                $userId = $input['user_id'] ?? null;
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                
                if (!$email) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid email']);
                    break;
                }
                
                // Check if email already verified
                $stmt = $db->prepare("SELECT id FROM trump WHERE email = ? AND verified = 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    echo json_encode(['ok' => false, 'error' => 'Email already verified']);
                    break;
                }
                
                // Rate limit: max 5 verification attempts per IP per hour
                if ($ip) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM trump WHERE ip = ? AND verify_token IS NOT NULL AND created_at > NOW() - INTERVAL 1 HOUR");
                    $stmt->execute([$ip]);
                    $ipVerifyCount = $stmt->fetchColumn();
                    if ($ipVerifyCount >= 5) {
                        sendAlert($config, "Possible bot - Same IP multiple verifications",
                            "IP: $ip\nAttempted email: $email\nAttempts this hour: $ipVerifyCount");
                        echo json_encode(['ok' => false, 'error' => 'Too many attempts. Try again later.']);
                        break;
                    }
                }
                
                // Generate verification token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                if ($userId) {
                    // Update existing row - DON'T save email yet, just token
                    $stmt = $db->prepare("UPDATE trump SET verify_token = ?, verify_token_expires = ? WHERE id = ?");
                    $stmt->execute([$token, $expires, $userId]);
                } else {
                    // Create new row - DON'T save email yet, but get location
                    $location = getLocationFromIP($ip);
                    $stmt = $db->prepare("INSERT INTO trump (verify_token, verify_token_expires, ip, state, town) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$token, $expires, $ip, $location['state'], $location['town']]);
                    $userId = $db->lastInsertId();
                }
                
                // Send verification email - include email in URL
                $verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/0t/?verify=' . $token . '&email=' . urlencode($email);
                $subject = "Verify your email - The Record";
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
                $stmt = $db->prepare("SELECT id FROM trump WHERE email = ? AND verified = 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    echo json_encode(['ok' => false, 'error' => 'Email already verified']);
                    break;
                }
                
                // Find user with this token
                $stmt = $db->prepare("SELECT id FROM trump WHERE verify_token = ? AND verify_token_expires > NOW() AND verified = 0");
                $stmt->execute([$token]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    echo json_encode(['ok' => false, 'error' => 'Invalid or expired token']);
                    break;
                }
                
                // Mark as verified and save email NOW
                $stmt = $db->prepare("UPDATE trump SET email = ?, verified = 1, verified_at = NOW(), verify_token = NULL, verify_token_expires = NULL WHERE id = ?");
                $stmt->execute([$email, $user['id']]);
                
                // Send admin notification
                $trumpConfig = $config['trump'] ?? [];
                $notifyEvery = $trumpConfig['notify_every'] ?? 1;
                $notifyEmail = $trumpConfig['notify_email'] ?? $config['admin_email'];
                
                $count = $db->query("SELECT COUNT(*) FROM trump WHERE verified = 1")->fetchColumn();
                
                if ($count % $notifyEvery === 0) {
                    $subject = "The Record: New verified user #$count";
                    $body = "Email: $email\nUser ID: {$user['id']}\nTotal verified: $count";
                    @mail($notifyEmail, $subject, $body, "From: noreply@4tpb.org");
                }
                
                // Milestone alerts
                $milestones = [100, 500, 1000, 5000, 10000, 50000, 100000];
                if (in_array($count, $milestones)) {
                    sendAlert($config, "MILESTONE: $count verified users!",
                        "You just hit $count verified users!\n\nLatest: $email\nUser ID: {$user['id']}\n\nKeep it going!");
                }
                
                echo json_encode(['ok' => true, 'user_id' => (int)$user['id']]);
                break;
            
            // 3. Select message
            case 'select_message':
                $userId = $input['user_id'] ?? null;
                $message = intval($input['message'] ?? 0);
                
                if ($userId && $message >= 1 && $message <= 3) {
                    $stmt = $db->prepare("UPDATE trump SET message_selected = ? WHERE id = ?");
                    $stmt->execute([$message, $userId]);
                }
                echo json_encode(['ok' => true]);
                break;
            
            // 4. Opened email app
            case 'opened_email':
                $userId = $input['user_id'] ?? null;
                if ($userId) {
                    $stmt = $db->prepare("UPDATE trump SET opened_email_app = 1 WHERE id = ?");
                    $stmt->execute([$userId]);
                }
                echo json_encode(['ok' => true]);
                break;
            
            // 5. Get counts
            case 'counts':
                $state = $input['state'] ?? null;
                $town = $input['town'] ?? null;
                
                // Display offsets
                $offsetUSA = 1247;
                $offsetState = 89;
                $offsetTown = 12;
                
                $usa = $db->query("SELECT COUNT(*) FROM trump WHERE verified = 1")->fetchColumn() + $offsetUSA;
                
                $stateCount = $offsetState;
                if ($state) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM trump WHERE verified = 1 AND state = ?");
                    $stmt->execute([$state]);
                    $stateCount = $stmt->fetchColumn() + $offsetState;
                }
                
                $townCount = $offsetTown;
                if ($town) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM trump WHERE verified = 1 AND town = ?");
                    $stmt->execute([$town]);
                    $townCount = $stmt->fetchColumn() + $offsetTown;
                }
                
                echo json_encode([
                    'ok' => true,
                    'usa' => (int)$usa,
                    'state' => (int)$stateCount,
                    'town' => (int)$townCount
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
$offsetState = 89;
$offsetTown = 12;

// Get initial counts for page load
try {
    $db = getDB($config);
    $usaCount = ($db->query("SELECT COUNT(*) FROM trump WHERE verified = 1")->fetchColumn() ?: 0) + $offsetUSA;
} catch (Exception $e) {
    $usaCount = $offsetUSA;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Record: Trump 2025 - Lies & Illegal Actions</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Georgia, serif;
            background: #0a0a0a;
            color: #e0e0e0;
            line-height: 1.7;
        }
        
        a {
            color: #6ca0dc;
        }
        
        /* ACTION SECTION - TOP */
        .action-section {
            background: linear-gradient(135deg, #1a1a2a 0%, #0a0a0a 100%);
            padding: 50px 20px;
            border-bottom: 2px solid #d4af37;
        }
        
        .action-section h1 {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: normal;
        }
        
        .action-section h1 span {
            color: #d4af37;
        }
        
        .action-section .tagline {
            text-align: center;
            color: #888;
            margin-bottom: 30px;
            font-size: 1.2rem;
        }
        
        .action-container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        .instructions {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid #d4af37;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 1.1rem;
            text-align: center;
            color: #ccc;
        }
        
        .instructions strong {
            color: #d4af37;
        }
        
        .verify-row {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
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
        
        .button.secondary {
            background: transparent;
            border: 1px solid #d4af37;
            color: #d4af37;
        }
        
        .button.secondary:hover {
            background: rgba(212, 175, 55, 0.1);
        }
        
        /* COUNTERS SECTION */
        .counters-section {
            padding: 40px 20px;
            background: #111;
            border-bottom: 1px solid #333;
        }
        
        .counters-section h2 {
            text-align: center;
            font-size: 1.4rem;
            font-weight: normal;
            color: #fff;
            margin-bottom: 25px;
        }
        
        .counters {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 15px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .counter-box {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px 30px;
            text-align: center;
            min-width: 140px;
        }
        
        .counter-box.primary {
            border-color: #d4af37;
            background: rgba(212, 175, 55, 0.05);
        }
        
        .counter-box .number {
            font-size: 1.8rem;
            color: #d4af37;
            font-weight: bold;
            display: block;
        }
        
        .counter-box .label {
            color: #888;
            font-size: 0.85rem;
        }
        
        .counter-box.primary .label {
            color: #ccc;
        }
        
        /* ORIGINAL RECORD STYLES */
        .header {
            background: linear-gradient(135deg, #1a1a2a 0%, #0a0a0a 100%);
            padding: 60px 20px;
            text-align: center;
            border-bottom: 1px solid #333;
        }
        
        .header h1 {
            font-size: 2.8rem;
            margin-bottom: 15px;
            font-weight: normal;
        }
        
        .header h1 span {
            color: #d4af37;
        }
        
        .header .subtitle {
            font-size: 1.3rem;
            color: #888;
            margin-bottom: 30px;
        }
        
        .header .stats {
            display: flex;
            justify-content: center;
            gap: 50px;
            flex-wrap: wrap;
            margin-top: 30px;
        }
        
        .stat-box {
            text-align: center;
        }
        
        .stat-box .number {
            font-size: 3rem;
            color: #d4af37;
            font-weight: bold;
        }
        
        .stat-box .label {
            font-size: 0.9rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        
        .intro {
            max-width: 800px;
            margin: 0 auto;
            padding: 50px 20px;
            text-align: center;
        }
        
        .intro p {
            font-size: 1.2rem;
            color: #aaa;
            margin-bottom: 20px;
        }
        
        .intro .verify {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid #d4af37;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .intro .verify p {
            color: #d4af37;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .nav-tabs {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 20px;
            background: #111;
            border-bottom: 1px solid #333;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .nav-tabs a {
            color: #888;
            text-decoration: none;
            padding: 10px 25px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .nav-tabs a:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }
        
        .nav-tabs a.active {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.1);
        }
        
        .section {
            max-width: 900px;
            margin: 0 auto;
            padding: 50px 20px;
        }
        
        .section h2 {
            font-size: 2rem;
            color: #d4af37;
            margin-bottom: 10px;
            font-weight: normal;
            border-bottom: 1px solid #333;
            padding-bottom: 15px;
        }
        
        .section h2 span {
            font-size: 1rem;
            color: #666;
            font-weight: normal;
        }
        
        .section-intro {
            color: #888;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .category {
            margin-bottom: 40px;
        }
        
        .category h3 {
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 20px;
            padding-left: 15px;
            border-left: 3px solid #d4af37;
        }
        
        .item {
            background: rgba(255,255,255,0.03);
            border: 1px solid #222;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .item:hover {
            border-color: #444;
        }
        
        .item .claim {
            color: #e57373;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        
        .item .claim::before {
            content: "LIE: ";
            color: #a33;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .item .truth {
            color: #81c784;
            margin-bottom: 10px;
        }
        
        .item .truth::before {
            content: "TRUTH: ";
            color: #4a4;
            font-weight: bold;
            font-size: 0.8rem;
        }
        
        .item .source {
            font-size: 0.85rem;
            color: #666;
        }
        
        .item.illegal .claim::before {
            content: "ACTION: ";
            color: #a33;
        }
        
        .item.illegal .truth::before {
            content: "RULING: ";
            color: #4a4;
        }
        
        .item.illegal .claim {
            color: #ffb74d;
        }
        
        .badge {
            display: inline-block;
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 3px;
            margin-left: 10px;
            vertical-align: middle;
        }
        
        .badge.court-ruled {
            background: #4a2;
            color: #fff;
        }
        
        .badge.repeated {
            background: #a33;
            color: #fff;
        }
        
        .badge.contempt {
            background: #d4af37;
            color: #000;
        }
        
        .comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin: 40px 0;
        }
        
        .comparison-box {
            padding: 25px;
            border-radius: 8px;
        }
        
        .comparison-box.trump {
            background: rgba(180, 50, 50, 0.1);
            border: 1px solid #633;
        }
        
        .comparison-box.others {
            background: rgba(100, 100, 100, 0.1);
            border: 1px solid #444;
        }
        
        .comparison-box h4 {
            margin-bottom: 15px;
            font-weight: normal;
        }
        
        .comparison-box.trump h4 {
            color: #e57373;
        }
        
        .comparison-box.others h4 {
            color: #aaa;
        }
        
        .comparison-box ul {
            list-style: none;
        }
        
        .comparison-box li {
            padding: 8px 0;
            border-bottom: 1px solid #333;
            color: #999;
        }
        
        .comparison-box li:last-child {
            border-bottom: none;
        }
        
        .comparison-box li strong {
            color: #fff;
        }
        
        .footer {
            text-align: center;
            padding: 50px 20px;
            border-top: 1px solid #333;
            color: #555;
        }
        
        .footer p {
            margin-bottom: 10px;
        }
        
        @media (max-width: 600px) {
            .action-section h1 {
                font-size: 2rem;
            }
            
            .verify-row {
                flex-direction: column;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .header .stats {
                gap: 30px;
            }
            
            .stat-box .number {
                font-size: 2.2rem;
            }
            
            .comparison {
                grid-template-columns: 1fr;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .nav-tabs a {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <!-- ACTION SECTION -->
    <section class="action-section">
        <h1>The <span>Record</span></h1>
        <p class="tagline">Watch the opposition to Trump grow</p>
        <p style="text-align: center; margin-bottom: 20px;">
            <a href="#hard-evidence" style="color: #d4af37; text-decoration: none; font-size: 1.1rem;">
                Felonies + court losses + lawsuits + documented lies. See the hard evidence ↓
            </a>
        </p>
        
        <div class="action-container">
            <div class="instructions">
                Verify your email. Talk to <strong>at least 2 people</strong> you know.<br>
                Get them to agree to do the same. Then send them here.
            </div>
            
            <div class="verify-row">
                <input type="email" id="yourEmail" placeholder="Your email address">
                <button class="button" onclick="verifyEmail()">Verify</button>
            </div>
            
            <div class="message-options">
                <h3>Pick a message to send:</h3>
                
                <div class="message-option" onclick="selectMessage(1)" id="msg1">
                    <p>"Great way to oppose the Trump Administration. Pass it along."</p>
                    <span class="select-indicator">Select</span>
                </div>
                
                <div class="message-option" onclick="selectMessage(2)" id="msg2">
                    <p>"Glad to see this. Hopefully it will explode and go viral. Wanna help?"</p>
                    <span class="select-indicator">Select</span>
                </div>
                
                <div class="message-option" onclick="selectMessage(3)" id="msg3">
                    <p>"I'm sending this to everyone I can think of. You too. Do the viral thing."</p>
                    <span class="select-indicator">Select</span>
                </div>
            </div>
            
            <div class="send-options">
                <h3>Send to at least 2 people:</h3>
                <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer; color: #888;">
                    <input type="checkbox" id="bccCheckbox" style="width: 18px; height: 18px; cursor: pointer;">
                    <span>Let us know you sent it (helps us count)</span>
                </label>
                <button class="button" onclick="openEmailApp()">Open my email app</button>
            </div>
        </div>
    </section>
    
    <!-- COUNTERS SECTION -->
    <section class="counters-section">
        <h2>The Opposition is Growing</h2>
        
        <div class="counters">
            <div class="counter-box primary">
                <span class="number" id="usaCount"><?= number_format($usaCount) ?></span>
                <span class="label">USA</span>
            </div>
            <div class="counter-box">
                <span class="number" id="stateCount">0</span>
                <span class="label" id="stateLabel">Your State</span>
            </div>
            <div class="counter-box">
                <span class="number" id="townCount">0</span>
                <span class="label" id="townLabel">Your Town</span>
            </div>
        </div>
    </section>
    
    <!-- SUMMARY STATS -->
    <header class="header" style="padding-top: 40px;">
        <p class="subtitle">Documented lies and illegal actions — Trump 2025</p>
        
        <div class="stats">
            <div class="stat-box">
                <div class="number">100+</div>
                <div class="label">Documented Lies</div>
            </div>
            <div class="stat-box">
                <div class="number">34</div>
                <div class="label">Felony Convictions</div>
            </div>
            <div class="stat-box">
                <div class="number">186</div>
                <div class="label">Court Losses (2025)</div>
            </div>
            <div class="stat-box">
                <div class="number">530</div>
                <div class="label">Lawsuits Filed</div>
            </div>
        </div>
    </header>
    
    <div id="hard-evidence"></div>
    
    <section class="intro">
        <p>This page documents verifiable, fact-checked false statements and court-ruled illegal actions by the Trump administration in 2025.</p>
        <p>Every claim includes sources. Every ruling is documented. Nothing here requires you to believe anyone — you can verify it yourself.</p>
        
        <div class="verify">
            <p>Truth doesn't need you to believe it. It just needs you to look.</p>
        </div>
    </section>
    
    <nav class="nav-tabs">
        <a href="#lies" class="active">Lies</a>
        <a href="#illegal">Illegal Actions</a>
        <a href="#losses">Court Losses</a>
        <a href="#lawsuits">Lawsuits</a>
        <a href="#sources">Sources</a>
    </nav>
    
    <!-- LIES SECTION -->
    <section class="section" id="lies">
        <h2>Documented Lies <span>— Exposed by fact-checkers</span></h2>
        <p class="section-intro">CNN documented 100 false claims in just Trump's first 100 days. These are repeated lies — debunked again and again, yet still repeated.</p>
        
        <div class="category">
            <h3>Economy & Prices</h3>
            
            <div class="item">
                <p class="claim">"Grocery prices are down." <span class="badge repeated">Repeated 50+ times</span></p>
                <p class="truth">Grocery prices are UP 1.9-2.7% in 2025 according to Consumer Price Index data.</p>
                <p class="source">Source: Bureau of Labor Statistics, CNN Fact Check</p>
            </div>
            
            <div class="item">
                <p class="claim">"Inflation is stopped."</p>
                <p class="truth">Inflation continues at 2.7-3.0% — the same rate as when Trump took office in January 2025.</p>
                <p class="source">Source: Bureau of Labor Statistics</p>
            </div>
            
            <div class="item">
                <p class="claim">"When I took office, inflation was the worst in 48 years, and some would say in the history of our country."</p>
                <p class="truth">Inflation in January 2025 was 3.0%. The all-time record was 23.7% in 1920. The 40-year high of 9.1% occurred in June 2022 — more than two years before Trump returned.</p>
                <p class="source">Source: Federal Reserve historical data</p>
            </div>
            
            <div class="item">
                <p class="claim">"Price of apples doubled under Biden."</p>
                <p class="truth">Apple prices increased 7-8% under Biden — nowhere near 100%.</p>
                <p class="source">Source: Bureau of Labor Statistics</p>
            </div>
            
            <div class="item">
                <p class="claim">Drug prices will be cut "400%, 500%, 600%, 700%, 800%, 900%."</p>
                <p class="truth">Mathematically impossible. A 100% cut would make drugs free. You cannot cut more than 100%.</p>
                <p class="source">Source: Basic math</p>
            </div>
            
            <div class="item">
                <p class="claim">"Gas is under $2.50 in much of the country" and "just hit $1.99 a gallon."</p>
                <p class="truth">Only 4 states had averages below $2.50 (Oklahoma, Arkansas, Iowa, Colorado). National average: $2.90/gallon.</p>
                <p class="source">Source: AAA Gas Prices</p>
            </div>
            
            <div class="item">
                <p class="claim">"We were losing $2 trillion a year on trade."</p>
                <p class="truth">Total US trade deficit in 2024 was $918 billion (goods and services) or $1.2 trillion (goods only). Neither is close to $2 trillion.</p>
                <p class="source">Source: US Census Bureau</p>
            </div>
            
            <div class="item">
                <p class="claim">Tariffs are "paid by foreign countries."</p>
                <p class="truth">Tariffs are paid by US importers, who pass costs to American consumers. Trump contradicted himself when he said he'd lower coffee prices by lowering coffee tariffs.</p>
                <p class="source">Source: Basic trade economics, Trump's own statement</p>
            </div>
            
            <div class="item">
                <p class="claim">"Secured $17-18 trillion in investment."</p>
                <p class="truth">Wild exaggeration with no supporting evidence.</p>
                <p class="source">Source: FactCheck.org</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Ukraine & Foreign Policy</h3>
            
            <div class="item">
                <p class="claim">"Biden gave away $350 billion to Ukraine." <span class="badge repeated">Repeated 50+ times</span></p>
                <p class="truth">Actual US aid to Ukraine: ~$90-133 billion according to government inspector general and Kiel Institute tracking.</p>
                <p class="source">Source: US Government Inspector General, Kiel Institute for World Economy</p>
            </div>
            
            <div class="item">
                <p class="claim">"Europe gave $200 billion less than the US to Ukraine."</p>
                <p class="truth">Europe actually gave MORE than the US: $157 billion vs $135 billion allocated.</p>
                <p class="source">Source: Kiel Institute for World Economy</p>
            </div>
            
            <div class="item">
                <p class="claim">"Ukraine started the war" and "could have made a deal."</p>
                <p class="truth">Russia invaded Ukraine on February 24, 2022. Ukraine did not invade Russia.</p>
                <p class="source">Source: Observable reality</p>
            </div>
            
            <div class="item">
                <p class="claim">"Ukraine leadership has expressed zero gratitude for our efforts."</p>
                <p class="truth">CNN found 78 documented examples of Zelensky expressing thanks.</p>
                <p class="source">Source: CNN, Daniel Dale fact check</p>
            </div>
            
            <div class="item">
                <p class="claim">"I ended 7-8 wars."</p>
                <p class="truth">Clear exaggeration. Even generously counting conflicts Trump played a role in, the number is far lower.</p>
                <p class="source">Source: FactCheck.org</p>
            </div>
            
            <div class="item">
                <p class="claim">Claimed he would end Ukraine war "day one" was "in jest."</p>
                <p class="truth">Trump made this pledge at least 53 times in 2023 and 2024 in entirely serious contexts.</p>
                <p class="source">Source: Video record, CNN</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Border & Immigration</h3>
            
            <div class="item">
                <p class="claim">"Lowest level of illegal border crossings ever recorded."</p>
                <p class="truth">Lowest since the early 1960s — not "ever recorded."</p>
                <p class="source">Source: US Customs and Border Protection historical data</p>
            </div>
            
            <div class="item">
                <p class="claim">Foreign leaders "emptied prisons and mental institutions" to send migrants to US.</p>
                <p class="truth">No evidence supports this claim.</p>
                <p class="source">Source: FactCheck.org</p>
            </div>
            
            <div class="item">
                <p class="claim">January 2021 had "lowest illegal immigration ever" (pointing to rally chart).</p>
                <p class="truth">The chart's highlighted "low point" was April 2020 — during COVID when global migration slowed. Trump still had 8 months left in office.</p>
                <p class="source">Source: CBP data analysis</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Foreign Aid & Spending</h3>
            
            <div class="item">
                <p class="claim">"$50 million for condoms for Hamas." Later: "$100 million for condoms for Hamas."</p>
                <p class="truth">Completely fabricated. The contractor identified by State Department refuted this claim. No such program existed.</p>
                <p class="source">Source: State Department, FactCheck.org</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Crime & Cities</h3>
            
            <div class="item">
                <p class="claim">"Portland is burning to the ground" / "fires all over the place."</p>
                <p class="truth">Portland Fire & Rescue reported few calls. Portland Police said protests were "nowhere near city-wide." The city was not ablaze.</p>
                <p class="source">Source: Portland Fire & Rescue, Portland Police</p>
            </div>
            
            <div class="item">
                <p class="claim">"Washington DC has no murders" / "no murders in six months."</p>
                <p class="truth">Multiple homicides occurred in DC even after National Guard deployment, including several in November alone.</p>
                <p class="source">Source: DC Metropolitan Police</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Drug Enforcement</h3>
            
            <div class="item">
                <p class="claim">"Every drug boat blown up saves 25,000 lives on average."</p>
                <p class="truth">Total US overdose deaths from ALL drugs in 2024: ~82,000. The Caribbean is not a significant fentanyl route. This number is "absurd" according to Johns Hopkins professor.</p>
                <p class="source">Source: CDC overdose data, Johns Hopkins University</p>
            </div>
        </div>
        
        <div class="category">
            <h3>2020 Election</h3>
            
            <div class="item">
                <p class="claim">"Rigged election" / "fake election" / "fraudulent election" / "now we found that out definitively." <span class="badge repeated">Repeated constantly</span></p>
                <p class="truth">Trump legitimately lost a free and fair election. No evidence of fraud has been found despite dozens of court cases, audits, and investigations.</p>
                <p class="source">Source: 60+ court rulings, state audits, DOJ investigation</p>
            </div>
            
            <div class="item">
                <p class="claim">Signed a "law" imposing 10-year prison for monument damage.</p>
                <p class="truth">Trump signed an executive order (not a law) directing the AG to prioritize such prosecutions under existing law. He did not create a new statute.</p>
                <p class="source">Source: Federal Register</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Historical Claims</h3>
            
            <div class="item">
                <p class="claim">Warned Pete Hegseth about Osama bin Laden before 9/11.</p>
                <p class="truth">Pete Hegseth was a 19-year-old college freshman in 2000. He didn't become Secretary of Defense until January 2025 — 24 years after 9/11.</p>
                <p class="source">Source: Hegseth's biography, timeline</p>
            </div>
            
            <div class="item">
                <p class="claim">"Completed the wall."</p>
                <p class="truth">Built 458 of planned 1,000 miles. When he left office in 2021, 280 miles remained unbuilt.</p>
                <p class="source">Source: CBP records</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Vaccines & Health</h3>
            
            <div class="item">
                <p class="claim">US gives "far more" vaccines than any other country.</p>
                <p class="truth">False according to comparative international health data.</p>
                <p class="source">Source: WHO, CDC comparative data</p>
            </div>
            
            <div class="item">
                <p class="claim">Amish "have essentially no autism" because they don't vaccinate.</p>
                <p class="truth">False. Amish communities do experience autism. This is a long-debunked anti-vaccine myth.</p>
                <p class="source">Source: Scientific American, medical literature</p>
            </div>
            
            <div class="item">
                <p class="claim">Tylenol during pregnancy causes autism.</p>
                <p class="truth">Scientific American reports that fever itself in the second trimester is a risk factor for autism — meaning Tylenol (which reduces fever) may actually be protective. The administration's claim is counter-productive.</p>
                <p class="source">Source: Scientific American</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Miscellaneous False Claims</h3>
            
            <div class="item">
                <p class="claim">US is giving Panama $1 billion per year.</p>
                <p class="truth">The US has given Panama approximately $100 million over the past decade — a total of $100 million, not $1 billion per year.</p>
                <p class="source">Source: Foreign aid records</p>
            </div>
            
            <div class="item">
                <p class="claim">Claimed thousands of people attended his inauguration parade who weren't there.</p>
                <p class="truth">Verifiably false by video and photo evidence. Crowds were significantly smaller than claimed.</p>
                <p class="source">Source: Visual evidence</p>
            </div>
        </div>
    </section>
    
    <!-- ILLEGAL ACTIONS SECTION -->
    <section class="section" id="illegal">
        <h2>Illegal Actions <span>— Blocked by courts</span></h2>
        <p class="section-intro">Multiple federal judges have ruled Trump administration actions illegal or unconstitutional. These aren't opinions — they're court rulings.</p>
        
        <div class="category">
            <h3>Constitutional Violations</h3>
            
            <div class="item illegal">
                <p class="claim">Birthright Citizenship Executive Order. <span class="badge court-ruled">Blocked by 4 Judges</span></p>
                <p class="truth">Four federal judges blocked it. Judge John Coughenour called it "blatantly unconstitutional." Trump's DOJ lawyer admitted in court no president has authority to change the 14th Amendment.</p>
                <p class="source">Source: US District Courts in WA, MD, MA, NJ</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Eliminating federal agencies by executive order.</p>
                <p class="truth">Only Congress can eliminate agencies it created. Executive orders cannot override acts of Congress.</p>
                <p class="source">Source: Constitutional law</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Defunding congressionally-appropriated programs.</p>
                <p class="truth">The President cannot refuse to spend money Congress has appropriated. This was established in Nixon-era Impoundment Control Act.</p>
                <p class="source">Source: Impoundment Control Act of 1974</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Contempt of Court</h3>
            
            <div class="item illegal">
                <p class="claim">Deportation of Kilmar Abrego Garcia to El Salvador. <span class="badge contempt">Contempt of Court</span></p>
                <p class="truth">Federal judge ruled the Trump administration in CONTEMPT OF COURT for violating deportation order. Administration was ordered to "facilitate" return of wrongfully deported Maryland man who was protected by immigration court order. Government admitted "administrative error" but proceeded anyway.</p>
                <p class="source">Source: Judge Paula Xinis, D. Maryland</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Continued deportations after court orders.</p>
                <p class="truth">Supreme Court had to intervene to halt deportation flights that continued despite lower court injunctions.</p>
                <p class="source">Source: Supreme Court emergency orders</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Immigration Enforcement Violations</h3>
            
            <div class="item illegal">
                <p class="claim">Racial profiling in ICE raids.</p>
                <p class="truth">Federal lawsuit alleges systematic targeting of "brown-skinned people" in Southern California, warrantless arrests, denying access to attorneys.</p>
                <p class="source">Source: LA immigrant advocacy groups lawsuit, July 2025</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Paying foreign government to imprison US deportees.</p>
                <p class="truth">$6 million/year to El Salvador to hold deportees at CECOT — putting them beyond reach of US courts. Judge ruled US maintained "constructive custody" over these prisoners.</p>
                <p class="source">Source: Judge Boasberg ruling</p>
            </div>
            
            <div class="item illegal">
                <p class="claim">Using Alien Enemies Act for immigration enforcement. <span class="badge court-ruled">5th Circuit Blocked</span></p>
                <p class="truth">The 5th Circuit — one of the most conservative appeals courts — ruled in 2-1 decision that immigration does not constitute an "invasion" under the 1798 wartime law.</p>
                <p class="source">Source: 5th Circuit Court of Appeals, September 2025</p>
            </div>
        </div>
    </section>
    
    <!-- COURT LOSSES SECTION -->
    <section class="section" id="losses">
        <h2>Court Losses <span>— A historic record of defeat</span></h2>
        <p class="section-intro">Trump has lost more court cases than any president in American history. These aren't opinions — they're verdicts, judgments, and rulings.</p>
        
        <div class="category">
            <h3>Criminal Convictions</h3>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">NY Hush Money Case — May 30, 2024</p>
                <p class="truth"><strong>GUILTY on ALL 34 felony counts</strong> of falsifying business records. First former president convicted of felonies in American history. Sentenced to unconditional discharge January 2025.</p>
                <p class="source">Source: Manhattan DA, Judge Juan Merchan</p>
            </div>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">Trump Organization Tax Fraud — December 2022</p>
                <p class="truth"><strong>CONVICTED</strong> of 17 counts of tax fraud. Two Trump companies found guilty of orchestrating tax evasion scheme for over a decade. Fined $1.6 million.</p>
                <p class="source">Source: Manhattan DA</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Civil Judgments Against Trump</h3>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">E. Jean Carroll I — May 2023</p>
                <p class="truth"><strong>$5 million judgment.</strong> Jury found Trump liable for sexual abuse and defamation. Federal appeals court upheld finding December 2024.</p>
                <p class="source">Source: US District Court SDNY, 2nd Circuit Court of Appeals</p>
            </div>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">E. Jean Carroll II — January 2024</p>
                <p class="truth"><strong>$83.3 million judgment</strong> for defamation. Appeals court rejected Trump's appeal September 2025, including his presidential immunity claims.</p>
                <p class="source">Source: US District Court SDNY, 2nd Circuit Court of Appeals</p>
            </div>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">NY Civil Fraud Case — February 2024</p>
                <p class="truth"><strong>FRAUD FINDING UPHELD.</strong> Judge found Trump engaged in years of fraud by padding financial statements. Original $355-515 million penalty reduced by appeals court as "excessive" BUT fraud finding stands. Trump and sons banned from corporate leadership.</p>
                <p class="source">Source: Judge Arthur Engoron, NY Appellate Division (August 2025)</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Second Term Losses (2025)</h3>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">Just Security Litigation Tracker (Current)</p>
                <p class="truth">
                    <strong>186 Plaintiff Wins</strong> (government action blocked)<br>
                    • Government Action Blocked: 50<br>
                    • Government Action Temporarily Blocked: 103<br>
                    • Government Action Blocked Pending Appeal: 28<br>
                    • Case Closed in Favor of Plaintiff: 5<br><br>
                    <strong>113 Government Wins</strong><br>
                    <strong>214 Cases Awaiting Ruling</strong>
                </p>
                <p class="source">Source: Just Security Litigation Tracker (updated weekly)</p>
            </div>
            
            <div class="item">
                <p class="claim" style="color: #e57373;">Mandatory Immigration Detention Policy</p>
                <p class="truth"><strong>225+ judges</strong> have ruled the administration's new mandatory detention policy is a "likely violation of law and the right to due process."</p>
                <p class="source">Source: Politico (November 28, 2025)</p>
            </div>
        </div>
        
        <div class="category">
            <h3>Summary: The Numbers</h3>
            
            <div class="comparison">
                <div class="comparison-box trump">
                    <h4>Trump Court Losses</h4>
                    <ul>
                        <li><strong>34 felony convictions</strong> (hush money)</li>
                        <li><strong>$88+ million</strong> in civil judgments (Carroll cases)</li>
                        <li><strong>79 of 85</strong> first term agency cases lost</li>
                        <li><strong>61 of 62</strong> election lawsuits lost</li>
                        <li><strong>186</strong> second term cases lost (so far)</li>
                        <li><strong>225+ judges</strong> ruled detention policy illegal</li>
                    </ul>
                </div>
                <div class="comparison-box others">
                    <h4>Normal Win Rates</h4>
                    <ul>
                        <li>Past administrations: <strong>~70%</strong> win rate</li>
                        <li>Trump first term: <strong>17%</strong> win rate</li>
                        <li>Trump with GOP judges: <strong>36%</strong> win rate</li>
                        <li>Trump second term: <strong>~38%</strong> win rate</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
    
    <!-- LAWSUITS COMPARISON -->
    <section class="section" id="lawsuits">
        <h2>Unprecedented Lawsuits</h2>
        <p class="section-intro">The Trump administration faces more legal challenges than any administration in modern history.</p>
        
        <div class="comparison">
            <div class="comparison-box trump">
                <h4>Trump 2025</h4>
                <ul>
                    <li><strong>530 lawsuits</strong> filed in first 10 months</li>
                    <li><strong>253 active cases</strong> currently pending</li>
                    <li><strong>127 lawsuits</strong> in first 2 months alone</li>
                    <li><strong>20-30 cases</strong> expected to reach Supreme Court</li>
                    <li><strong>17+ actions</strong> ruled illegal by courts</li>
                </ul>
            </div>
            <div class="comparison-box others">
                <h4>Previous Presidents (First Year)</h4>
                <ul>
                    <li>Biden: <strong>133 lawsuits</strong> (entire 4-year term)</li>
                    <li>Obama: <strong>30-40 lawsuits</strong></li>
                    <li>Bush: <strong>fewer than 20 lawsuits</strong></li>
                </ul>
            </div>
        </div>
        
        <div class="item">
            <p class="truth" style="color: #d4af37;">Trump's 530 lawsuits in 10 months = more than Biden's entire presidency, more than 10x Obama's first year, more than 25x Bush's first year.</p>
        </div>
    </section>
    
    <!-- SOURCES -->
    <section class="section" id="sources">
        <h2>Sources</h2>
        <p class="section-intro">Every claim on this page can be verified. Here are the primary sources.</p>
        
        <div class="category">
            <h3>Fact-Checking Organizations</h3>
            <div class="item">
                <p><a href="https://www.cnn.com/politics/fact-check-trump-false-claims-debunked" target="_blank">CNN Fact Check: 100 Trump False Claims (First 100 Days)</a></p>
                <p><a href="https://www.cnn.com/2025/12/27/politics/analysis-donald-trumps-top-25-lies-of-2025" target="_blank">CNN: Trump's Top 25 Lies of 2025</a></p>
                <p><a href="https://www.factcheck.org/person/donald-trump/" target="_blank">FactCheck.org: Donald Trump</a></p>
                <p><a href="https://www.factcheck.org/2025/12/the-whoppers-of-2025/" target="_blank">FactCheck.org: The Whoppers of 2025</a></p>
            </div>
        </div>
        
        <div class="category">
            <h3>Legal Trackers</h3>
            <div class="item">
                <p><a href="https://www.justsecurity.org/107087/tracker-litigation-legal-challenges-trump-administration/" target="_blank">Just Security: Litigation Tracker</a></p>
                <p><a href="https://www.lawfaremedia.org/projects-series/trials-of-the-trump-administration/tracking-trump-administration-litigation" target="_blank">Lawfare: Trump Administration Litigation Tracker</a></p>
                <p><a href="https://campaignlegal.org/CanTrumpDoThat" target="_blank">Campaign Legal Center: Can President Trump Do That?</a></p>
                <p><a href="https://cohen.house.gov/TrumpAdminTracker" target="_blank">Rep. Steve Cohen: Trump Admin Tracker</a></p>
            </div>
        </div>
        
        <div class="category">
            <h3>Court Rulings & Legal Documents</h3>
            <div class="item">
                <p><a href="https://en.wikipedia.org/wiki/Legal_affairs_of_the_second_Trump_presidency" target="_blank">Wikipedia: Legal Affairs of Second Trump Presidency</a> (with case citations)</p>
                <p><a href="https://en.wikipedia.org/wiki/False_or_misleading_statements_by_Donald_Trump_(second_term)" target="_blank">Wikipedia: False Statements by Trump (Second Term)</a> (with sources)</p>
                <p><a href="https://en.wikipedia.org/wiki/Deportation_of_Kilmar_Abrego_Garcia" target="_blank">Wikipedia: Deportation of Kilmar Abrego Garcia</a></p>
            </div>
        </div>
        
        <div class="category">
            <h3>Government Data</h3>
            <div class="item">
                <p><a href="https://www.bls.gov/cpi/" target="_blank">Bureau of Labor Statistics: Consumer Price Index</a></p>
                <p><a href="https://gasprices.aaa.com/" target="_blank">AAA: Gas Prices</a></p>
                <p><a href="https://www.cbp.gov/newsroom/stats" target="_blank">Customs and Border Protection: Statistics</a></p>
            </div>
        </div>
    </section>
    
    <footer class="footer">
        <p>Last updated: December 2025</p>
        <p>This page contains no opinions — only documented facts and court rulings.</p>
        <p>Truth is self-evident. Provable. Infinite. It only needs to be revealed.</p>
    </footer>
    
    <script>
        let userId = localStorage.getItem('tr_user_id') || null;
        let isVerified = localStorage.getItem('tr_verified') === 'true';
        let selectedMessage = 0;
        let userState = null;
        let userTown = null;
        
        // On page load: check if returning verified user
        (function() {
            if (isVerified) {
                showWelcomeBack();
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            const ref = urlParams.get('ref');
            const gen = urlParams.get('gen');
            const verifyToken = urlParams.get('verify');
            const verifyEmail = urlParams.get('email');
            
            // Handle email verification link
            if (verifyToken && verifyEmail) {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'complete_verify',
                        token: verifyToken,
                        email: verifyEmail
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        userId = data.user_id;
                        localStorage.setItem('tr_user_id', userId);
                        localStorage.setItem('tr_verified', 'true');
                        isVerified = true;
                        alert('Email verified! Welcome to The Record.');
                        // Remove token from URL
                        window.history.replaceState({}, '', window.location.pathname);
                        showWelcomeBack();
                        updateCounts();
                    } else {
                        alert(data.error || 'Verification failed. Link may have expired.');
                    }
                })
                .catch(() => alert('Error verifying. Please try again.'));
                return; // Don't run rest of IIFE
            }
            
            // If arriving via referral link, clear localStorage - this is a "new person"
            // (In real use, they'd have a different browser. This allows same-browser testing.)
            if (ref) {
                localStorage.removeItem('tr_user_id');
                localStorage.removeItem('tr_verified');
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
                        generation: parseInt(gen) || 1,
                        state: null,
                        town: null
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.ok && data.user_id) {
                        userId = data.user_id;
                        localStorage.setItem('tr_user_id', userId);
                        
                        // Store generation for later use
                        if (data.generation) {
                            localStorage.setItem('tr_generation', data.generation);
                        }
                        
                        // Sync verified status from server
                        if (data.verified && !isVerified) {
                            localStorage.setItem('tr_verified', 'true');
                            isVerified = true;
                            showWelcomeBack();
                        }
                        
                        // Set location and update labels
                        if (data.state) {
                            userState = data.state;
                            document.getElementById('stateLabel').textContent = data.state;
                        }
                        if (data.town) {
                            userTown = data.town;
                            document.getElementById('townLabel').textContent = data.town;
                        }
                        
                        // Now get counts with location
                        updateCounts();
                    }
                })
                .catch(() => {});
            } else {
                // No user yet, just get counts
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
                    document.getElementById('usaCount').textContent = data.usa.toLocaleString();
                    document.getElementById('stateCount').textContent = data.state.toLocaleString();
                    document.getElementById('townCount').textContent = data.town.toLocaleString();
                }
            })
            .catch(() => {});
        }
        
        // Refresh counts periodically
        setInterval(updateCounts, 30000);
        
        function selectMessage(num) {
            document.querySelectorAll('.message-option').forEach(el => {
                el.classList.remove('selected');
            });
            document.getElementById('msg' + num).classList.add('selected');
            selectedMessage = num;
            
            // Log selection
            if (userId) {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'select_message',
                        user_id: userId,
                        message: num
                    })
                }).catch(() => {});
            }
        }
        
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
                    alert('Check your email for a verification link!');
                } else {
                    alert(data.error || 'Error sending verification email.');
                }
            })
            .catch(() => {
                alert('Error connecting to server.');
            });
        }
        
        function openEmailApp() {
            if (selectedMessage === 0) {
                alert('Please select a message first.');
                return;
            }
            
            const messages = {
                1: "Great way to oppose the Trump Administration. Pass it along.",
                2: "Glad to see this. Hopefully it will explode and go viral. Wanna help?",
                3: "I'm sending this to everyone I can think of. You too. Do the viral thing."
            };
            
            // Log that user opened email app
            if (userId) {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'opened_email',
                        user_id: userId
                    })
                }).catch(() => {});
            }
            
            // Build tracking link - use stored generation
            const myGen = parseInt(localStorage.getItem('tr_generation')) || 1;
            const nextGen = myGen + 1;
            const baseUrl = window.location.origin + window.location.pathname;
            const trackingLink = baseUrl + '?ref=' + (userId || 'anon') + '&gen=' + nextGen;
            
            const subject = encodeURIComponent(messages[selectedMessage]);
            const body = encodeURIComponent(trackingLink);
            const bccChecked = document.getElementById('bccCheckbox').checked;
            const bcc = bccChecked ? "&bcc=hhg@sandgems.net" : "";
            
            window.location.href = "mailto:?subject=" + subject + "&body=" + body + bcc;
        }
        
        function showWelcomeBack() {
            const container = document.querySelector('.action-container');
            const usaCount = document.getElementById('usaCount').textContent;
            container.innerHTML = `
                <div class="instructions" style="background: rgba(100, 200, 100, 0.1); border-color: #4a4;">
                    <strong style="color: #4a4;">Welcome back!</strong><br>
                    See the counters today — or send the link to others.
                </div>
                
                <div class="message-options">
                    <h3>Pick a message to send:</h3>
                    
                    <div class="message-option" onclick="selectMessage(1)" id="msg1">
                        <p>"Great way to oppose the Trump Administration. Pass it along."</p>
                        <span class="select-indicator">Select</span>
                    </div>
                    
                    <div class="message-option" onclick="selectMessage(2)" id="msg2">
                        <p>"Glad to see this. Hopefully it will explode and go viral. Wanna help?"</p>
                        <span class="select-indicator">Select</span>
                    </div>
                    
                    <div class="message-option" onclick="selectMessage(3)" id="msg3">
                        <p>"I'm sending this to everyone I can think of. You too. Do the viral thing."</p>
                        <span class="select-indicator">Select</span>
                    </div>
                </div>
                
                <div class="send-options">
                    <h3>Send to more people:</h3>
                    <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer; color: #888;">
                        <input type="checkbox" id="bccCheckbox" style="width: 18px; height: 18px; cursor: pointer;">
                        <span>Let us know you sent it (helps us count)</span>
                    </label>
                    <button class="button" onclick="openEmailApp()">Open my email app</button>
                </div>
            `;
        }
        
        // Smooth scroll for nav
        document.querySelectorAll('.nav-tabs a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                target.scrollIntoView({ behavior: 'smooth' });
                
                document.querySelectorAll('.nav-tabs a').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>
