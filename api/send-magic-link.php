<?php
/**
 * TPB2 Send Magic Link API
 * ========================
 * Finds existing user OR creates new, then sends magic link
 * 
 * DUAL ONBOARDING PATHS - This API serves both:
 *   - index.php (inline form)
 *   - join.php (dedicated page)
 * Keep both callers in sync. Both should show "Already verified? 
 * Just enter email to link this device" messaging.
 * 
 * POST /api/send-magic-link.php
 * Body: {
 *   "email": "user@example.com",
 *   "session_id": "civic_xxx",
 *   "first_name": "John",         // optional
 *   "website_url": "",            // honeypot - should be empty
 *   "_form_load_time": 1234567890 // timestamp when form loaded
 * }
 * 
 * KEY: Email is the identity. Same email = same user.
 * - NEW USER: Creates account, sends "verify to join" email
 * - RETURNING USER: Finds by email, sends "verify this device" email
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

require_once __DIR__ . '/bot-detect.php';
require_once __DIR__ . '/../includes/smtp-mail.php';

$config = require __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

// Bot detection
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $formLoadTime = isset($input['_form_load_time']) ? (int)$input['_form_load_time'] : null;
    $botCheck = checkForBot($pdo, 'magic_link', $input, $formLoadTime);
    
    if ($botCheck['is_bot']) {
        // Silent rejection
        echo json_encode(['status' => 'success', 'message' => 'Check your email for the verification link.']);
        exit();
    }
} catch (PDOException $e) {
    // Continue anyway
}

$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
$sessionId = $input['session_id'] ?? null;
$firstName = trim($input['first_name'] ?? '');
$lastName = trim($input['last_name'] ?? '');
$phone = trim($input['phone'] ?? '');
$ageBracket = trim($input['age_bracket'] ?? '');
$parentEmail = filter_var($input['parent_email'] ?? '', FILTER_VALIDATE_EMAIL);
$returnUrl = trim($input['return_url'] ?? '');

// Location data from town picker
$stateCode = strtoupper(trim($input['state_code'] ?? ''));
$townName = trim($input['town_name'] ?? '');
$zipCode = trim($input['zip_code'] ?? '');
$lat = isset($input['lat']) && $input['lat'] !== '' ? (float)$input['lat'] : null;
$lng = isset($input['lng']) && $input['lng'] !== '' ? (float)$input['lng'] : null;
$usCongressDistrict = trim($input['us_congress_district'] ?? '');
$stateSenateDistrict = trim($input['state_senate_district'] ?? '');
$stateHouseDistrict = trim($input['state_house_district'] ?? '');

// Validate age bracket if provided
$validAgeBrackets = ['13-17', '18-24', '25-44', '45-64', '65+'];
if ($ageBracket && !in_array($ageBracket, $validAgeBrackets)) {
    $ageBracket = '';
}

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Valid email required']);
    exit();
}

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit();
}

// If minor, require parent email
if ($ageBracket === '13-17' && !$parentEmail) {
    echo json_encode(['status' => 'error', 'message' => 'Parent/guardian email required for users under 18']);
    exit();
}

try {
    // Reuse PDO from bot detection, or create if not exists
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    // Generate magic link token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Generate parent consent token if minor
    $parentConsentToken = ($ageBracket === '13-17') ? bin2hex(random_bytes(32)) : null;

    // Check if user exists by email
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.first_name, COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Also check if this session already has a user (email correction scenario)
    $sessionUser = null;
    if (!$existingUser && $sessionId) {
        // Check user_devices first
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.first_name, COALESCE(uis.email_verified, 0) as email_verified
            FROM user_devices ud
            INNER JOIN users u ON ud.user_id = u.user_id
            LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
            WHERE ud.device_session = ? AND ud.is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fallback to users.session_id
        if (!$sessionUser) {
            $stmt = $pdo->prepare("
                SELECT u.user_id, u.email, u.first_name, COALESCE(uis.email_verified, 0) as email_verified
                FROM users u
                LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
                WHERE u.session_id = ?
            ");
            $stmt->execute([$sessionId]);
            $sessionUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($existingUser) {
        // EXISTING USER (by email) - update token and session_id for this verification
        $stmt = $pdo->prepare("
            UPDATE users 
            SET magic_link_token = ?, 
                magic_link_expires = ?,
                session_id = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$token, $expires, $sessionId, $existingUser['user_id']]);
        $userId = $existingUser['user_id'];
        $isNewUser = false;
        
        // Update first_name if provided and not already set
        if ($firstName && !$existingUser['first_name']) {
            $stmt = $pdo->prepare("UPDATE users SET first_name = ? WHERE user_id = ?");
            $stmt->execute([$firstName, $userId]);
        }
        
        // Update last_name if provided
        if ($lastName) {
            $stmt = $pdo->prepare("UPDATE users SET last_name = ? WHERE user_id = ?");
            $stmt->execute([$lastName, $userId]);
        }
        
        // Update phone if provided
        if ($phone) {
            $stmt = $pdo->prepare("
                INSERT INTO user_identity_status (user_id, phone) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE phone = ?
            ");
            $stmt->execute([$userId, $phone, $phone]);
        }
    } else if ($sessionUser && !$sessionUser['email_verified']) {
        // SESSION HAS UNVERIFIED USER - this is an email correction! Update email instead of creating new user
        $stmt = $pdo->prepare("
            UPDATE users 
            SET email = ?,
                magic_link_token = ?, 
                magic_link_expires = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$email, $token, $expires, $sessionUser['user_id']]);
        $userId = $sessionUser['user_id'];
        $isNewUser = false;
        
        // Clear email_verified since email changed
        $stmt = $pdo->prepare("
            INSERT INTO user_identity_status (user_id, email_verified) VALUES (?, 0)
            ON DUPLICATE KEY UPDATE email_verified = 0
        ");
        $stmt->execute([$userId]);
        
        // Update first_name if provided and not already set
        if ($firstName && !$sessionUser['first_name']) {
            $stmt = $pdo->prepare("UPDATE users SET first_name = ? WHERE user_id = ?");
            $stmt->execute([$firstName, $userId]);
        }
        
        // Update last_name if provided
        if ($lastName) {
            $stmt = $pdo->prepare("UPDATE users SET last_name = ? WHERE user_id = ?");
            $stmt->execute([$lastName, $userId]);
        }
        
        // Update phone if provided
        if ($phone) {
            $stmt = $pdo->prepare("
                INSERT INTO user_identity_status (user_id, phone) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE phone = ?
            ");
            $stmt->execute([$userId, $phone, $phone]);
        }
    } else {
        // NEW USER - create account
        $username = explode('@', $email)[0] . '_' . substr(md5($email), 0, 6);
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, first_name, last_name, age_bracket, parent_email, parent_consent_token, session_id, magic_link_token, magic_link_expires, civic_points)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $username, 
            $email, 
            $firstName ?: null, 
            $lastName ?: null, 
            $ageBracket ?: null,
            $parentEmail ?: null,
            $parentConsentToken,
            $sessionId, 
            $token, 
            $expires
        ]);
        $userId = $pdo->lastInsertId();
        $isNewUser = true;
        
        // Transfer anonymous session points to new user
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        PointLogger::transferSession($sessionId, $userId);
        
        // Create user_identity_status record with phone if provided
        if ($phone) {
            $stmt = $pdo->prepare("INSERT INTO user_identity_status (user_id, phone) VALUES (?, ?)");
            $stmt->execute([$userId, $phone]);
        }
    }

    // Save location if provided (from map/town picker flow)
    if ($stateCode && $townName && $userId) {
        // Get state_id
        $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
        $stmt->execute([$stateCode]);
        $stateId = $stmt->fetchColumn();

        if ($stateId) {
            // Get or create town_id
            $stmt = $pdo->prepare("SELECT town_id FROM towns WHERE LOWER(town_name) = LOWER(?) AND state_id = ?");
            $stmt->execute([$townName, $stateId]);
            $townId = $stmt->fetchColumn();

            if (!$townId) {
                // Create town if it doesn't exist
                $stmt = $pdo->prepare("INSERT INTO towns (town_name, state_id) VALUES (?, ?)");
                $stmt->execute([$townName, $stateId]);
                $townId = $pdo->lastInsertId();
            }

            // Update user with full location data
            $stmt = $pdo->prepare("
                UPDATE users
                SET current_state_id = ?, current_town_id = ?, zip_code = ?,
                    latitude = ?, longitude = ?,
                    us_congress_district = ?, state_senate_district = ?, state_house_district = ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                $stateId, $townId, $zipCode ?: null,
                $lat, $lng,
                $usCongressDistrict ?: null, $stateSenateDistrict ?: null, $stateHouseDistrict ?: null,
                $userId
            ]);
        }
    }

    // Build magic link URL
    $baseUrl = $config['base_url'];
    $magicLink = "{$baseUrl}/api/verify-magic-link.php?token={$token}";
    if ($returnUrl) {
        $magicLink .= "&return_url=" . urlencode($returnUrl);
    }
    
    // Build parent consent link if minor
    $parentConsentLink = $parentConsentToken ? "{$baseUrl}/api/verify-parent-consent.php?token={$parentConsentToken}" : null;

    // Send email to user
    $subject = "Your People's Branch Verification Link";
    
    if ($isNewUser) {
        $greeting = $firstName ? "Hello {$firstName}!" : "Hello!";
        $message = "
{$greeting}

Click below to verify your email and join The People's Branch:

{$magicLink}

This link expires in 1 hour.

---
The People's Branch
Your voice matters.
";
    } else {
        $greeting = $existingUser['first_name'] ? "Welcome back, {$existingUser['first_name']}!" : "Welcome back!";
        $message = "
{$greeting}

Click below to verify this device:

{$magicLink}

This link expires in 1 hour.

---
The People's Branch
Your voice, any device.
";
    }

    // Send via SMTP
    $emailSent = sendSmtpMail($config, $email, $subject, $message);
    
    // Send parent consent email if minor
    $parentEmailSent = false;
    if ($parentEmail && $parentConsentLink && $isNewUser) {
        $childName = $firstName ?: 'Your child';
        $parentSubject = "Parent Consent Request - The People's Branch";
        $parentMessage = "
Hello,

{$childName} has signed up for The People's Branch, a civic engagement platform that helps citizens participate in local democracy.

Since they are under 18, we need your consent for them to participate.

What is The People's Branch?
- A platform where citizens can share concerns about local issues
- Residents vote on issues, helping surface what matters to the community
- Officials see what their constituents care about
- Content is moderated and appropriate for all ages (5 to 125)

To approve {$childName}'s participation, click below:

{$parentConsentLink}

If you have questions, visit our website at 4tpb.org.

If you did NOT expect this email or don't want your child to participate, simply ignore this message. They won't be able to post until you approve.

---
The People's Branch
No Kings. Only Citizens.
";
        $parentEmailSent = sendSmtpMail($config, $parentEmail, $parentSubject, $parentMessage);
    }

    if ($emailSent) {
        $responseMsg = $isNewUser ? 'Magic link sent! Check your email.' : 'Verification link sent! Check your email to add this device.';
        if ($parentEmail && $isNewUser) {
            $responseMsg .= ' We also sent a consent request to your parent/guardian.';
        }
        echo json_encode([
            'status' => 'success',
            'message' => $responseMsg,
            'user_id' => $userId,
            'is_new_user' => $isNewUser,
            'email_sent' => true,
            'parent_email_sent' => $parentEmailSent
        ]);
    } else {
        echo json_encode([
            'status' => 'warning',
            'message' => 'User created but email may not have sent.',
            'user_id' => $userId,
            'email_sent' => false,
            'demo_link' => $magicLink
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
