<?php
/**
 * Create User Location API
 * =========================
 * Creates anonymous user on town selection, saves location progressively
 * 
 * Actions:
 *   save_town     - Create user (if needed), save state + town
 *   save_zip      - Save zip code to existing user
 *   save_location - All-in-one: state + town + zip + lat/lon + address + districts
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/smtp-mail.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

// Get input from POST or GET
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
} else {
    $input = $_GET;
}

$action = $input['action'] ?? '';
$sessionId = $input['session_id'] ?? '';
$directUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;

if (!$sessionId && !$directUserId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID or User ID required']);
    exit();
}

switch ($action) {
    case 'save_town':
        saveTown($pdo, $input, $sessionId);
        break;
    case 'save_zip':
        saveZip($pdo, $input, $sessionId);
        break;
    case 'save_location':
        saveLocation($pdo, $input, $sessionId);
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}

function saveTown($pdo, $input, $sessionId) {
    $stateCode = strtoupper(trim($input['state_code'] ?? ''));
    $townName = trim($input['town_name'] ?? '');
    
    if (!$stateCode || !$townName) {
        echo json_encode(['status' => 'error', 'message' => 'State and town required']);
        return;
    }
    
    // Get state_id
    $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateCode]);
    $stateId = $stmt->fetchColumn();
    
    if (!$stateId) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid state']);
        return;
    }
    
    // Get or create town_id
    $stmt = $pdo->prepare("SELECT town_id FROM towns WHERE LOWER(town_name) = LOWER(?) AND state_id = ?");
    $stmt->execute([$townName, $stateId]);
    $townId = $stmt->fetchColumn();
    
    if (!$townId) {
        $stmt = $pdo->prepare("INSERT INTO towns (town_name, state_id) VALUES (?, ?)");
        $stmt->execute([$townName, $stateId]);
        $townId = $pdo->lastInsertId();
    }
    
    // Check if user exists for this session
    $stmt = $pdo->prepare("
        SELECT u.user_id 
        FROM user_devices ud 
        JOIN users u ON ud.user_id = u.user_id 
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $userId = $stmt->fetchColumn();
    
    if (!$userId) {
        // Also check users.session_id
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $userId = $stmt->fetchColumn();
    }
    
    if ($userId) {
        // Update existing user
        $stmt = $pdo->prepare("
            UPDATE users 
            SET current_state_id = ?, current_town_id = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$stateId, $townId, $userId]);
    } else {
        // Create new anonymous user
        $username = 'anon_' . substr(md5($sessionId), 0, 8) . '_' . time();
        $email = $username . '@anonymous.tpb'; // Placeholder email
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, session_id, current_state_id, current_town_id, civic_points)
            VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$username, $email, $sessionId, $stateId, $townId]);
        $userId = $pdo->lastInsertId();
        
        // Transfer anonymous session points to new user
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        PointLogger::transferSession($sessionId, $userId);
        
        // Link device to user
        $stmt = $pdo->prepare("
            INSERT INTO user_devices (user_id, device_session) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE user_id = ?, is_active = 1
        ");
        $stmt->execute([$userId, $sessionId, $userId]);
    }
    
    echo json_encode([
        'status' => 'success',
        'user_id' => $userId,
        'state_id' => $stateId,
        'town_id' => $townId,
        'message' => "Welcome to $townName, $stateCode!"
    ]);
}

function saveZip($pdo, $input, $sessionId) {
    $zipCode = trim($input['zip_code'] ?? '');
    
    if (!$zipCode) {
        echo json_encode(['status' => 'error', 'message' => 'Zip code required']);
        return;
    }
    
    // Find user by session
    $stmt = $pdo->prepare("
        SELECT u.user_id 
        FROM user_devices ud 
        JOIN users u ON ud.user_id = u.user_id 
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $userId = $stmt->fetchColumn();
    
    if (!$userId) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $userId = $stmt->fetchColumn();
    }
    
    if (!$userId) {
        echo json_encode(['status' => 'error', 'message' => 'User not found - select town first']);
        return;
    }
    
    // Update zip
    $stmt = $pdo->prepare("UPDATE users SET zip_code = ? WHERE user_id = ?");
    $stmt->execute([$zipCode, $userId]);
    
    echo json_encode([
        'status' => 'success',
        'user_id' => $userId,
        'zip_code' => $zipCode,
        'message' => "Zip code $zipCode saved!"
    ]);
}

/**
 * All-in-one location save (used by map.php)
 * Creates user if needed, saves state + town + zip + lat/lon + address + districts
 */
function saveLocation($pdo, $input, $sessionId) {
    $stateCode = strtoupper(trim($input['state_code'] ?? ''));
    $townName = trim($input['town_name'] ?? '');
    
    if (!$stateCode || !$townName) {
        echo json_encode(['status' => 'error', 'message' => 'State and town required']);
        return;
    }
    
    // Get state_id
    $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
    $stmt->execute([$stateCode]);
    $stateId = $stmt->fetchColumn();
    
    if (!$stateId) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid state']);
        return;
    }
    
    // Get or create town_id
    $stmt = $pdo->prepare("SELECT town_id FROM towns WHERE LOWER(town_name) = LOWER(?) AND state_id = ?");
    $stmt->execute([$townName, $stateId]);
    $townId = $stmt->fetchColumn();
    
    if (!$townId) {
        $stmt = $pdo->prepare("INSERT INTO towns (town_name, state_id) VALUES (?, ?)");
        $stmt->execute([$townName, $stateId]);
        $townId = $pdo->lastInsertId();
    }
    
    // Find or create user — prefer user_id if provided
    $directUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $userId = null;
    
    if ($directUserId) {
        // Verify this user exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
        $stmt->execute([$directUserId]);
        $userId = $stmt->fetchColumn();
    }
    
    if (!$userId) {
        $stmt = $pdo->prepare("
            SELECT u.user_id 
            FROM user_devices ud 
            JOIN users u ON ud.user_id = u.user_id 
            WHERE ud.device_session = ? AND ud.is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $userId = $stmt->fetchColumn();
    }
    
    if (!$userId) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $userId = $stmt->fetchColumn();
    }
    
    $isNew = false;
    
    if (!$userId) {
        // Create new anonymous user
        $username = 'anon_' . substr(md5($sessionId), 0, 8) . '_' . time();
        $email = $username . '@anonymous.tpb';
        
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, session_id, current_state_id, current_town_id, civic_points, identity_level_id)
            VALUES (?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt->execute([$username, $email, $sessionId, $stateId, $townId]);
        $userId = $pdo->lastInsertId();
        $isNew = true;
        
        // Transfer anonymous session points to new user
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        PointLogger::transferSession($sessionId, $userId);
        
        // Create user_identity_status record
        $stmt = $pdo->prepare("
            INSERT INTO user_identity_status (user_id, identity_level_id, email_verified, phone_verified)
            VALUES (?, 1, 0, 0)
            ON DUPLICATE KEY UPDATE identity_level_id = 1
        ");
        $stmt->execute([$userId]);
        
        // Link device
        $stmt = $pdo->prepare("
            INSERT INTO user_devices (user_id, device_session) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE user_id = ?, is_active = 1
        ");
        $stmt->execute([$userId, $sessionId, $userId]);
    }
    
    // Build full update with all available fields
    $updates = ['current_state_id = ?', 'current_town_id = ?'];
    $params = [$stateId, $townId];
    
    // Zip
    $zip = trim($input['zip_code'] ?? '');
    if ($zip) {
        $updates[] = 'zip_code = ?';
        $params[] = $zip;
    }
    
    // Lat/Lon
    $lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
    $lng = isset($input['longitude']) ? (float)$input['longitude'] : null;
    if ($lat !== null && $lat != 0) {
        $updates[] = 'latitude = ?';
        $params[] = $lat;
    }
    if ($lng !== null && $lng != 0) {
        $updates[] = 'longitude = ?';
        $params[] = $lng;
    }
    if ($lat || $lng) {
        $updates[] = 'location_updated_at = NOW()';
    }
    
    // Street address
    $address = trim($input['street_address'] ?? '');
    if ($address) {
        $updates[] = 'street_address = ?';
        $params[] = $address;
    }
    
    // Districts — strip "District " prefix if present
    $congress = preg_replace('/^District\s*/i', '', trim($input['us_congress_district'] ?? ''));
    $senate = preg_replace('/^District\s*/i', '', trim($input['state_senate_district'] ?? ''));
    $house = preg_replace('/^District\s*/i', '', trim($input['state_house_district'] ?? ''));
    
    if ($congress) {
        $updates[] = 'us_congress_district = ?';
        $params[] = $congress;
    }
    if ($senate) {
        $updates[] = 'state_senate_district = ?';
        $params[] = $senate;
    }
    if ($house) {
        $updates[] = 'state_house_district = ?';
        $params[] = $house;
    }
    if ($congress || $senate || $house) {
        $updates[] = 'districts_updated_at = NOW()';
    }
    
    // Profile fields (optional)
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    
    if ($firstName) {
        $updates[] = 'first_name = ?';
        $params[] = $firstName;
    }
    if ($lastName) {
        $updates[] = 'last_name = ?';
        $params[] = $lastName;
    }
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Only update email if it's not already a real email (don't overwrite verified emails)
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $currentEmail = $stmt->fetchColumn();
        if (!$currentEmail || strpos($currentEmail, '@anonymous.tpb') !== false) {
            $updates[] = 'email = ?';
            $params[] = $email;
        }
    }
    
    // Execute update
    $params[] = $userId;
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // If email was provided and user is unverified, send magic link
    $magicLinkSent = false;
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Check if already verified
        $stmt = $pdo->prepare("SELECT COALESCE(email_verified, 0) as email_verified FROM user_identity_status WHERE user_id = ?");
        $stmt->execute([$userId]);
        $verified = $stmt->fetchColumn();
        
        if (!$verified) {
            // Generate magic link token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $stmt = $pdo->prepare("UPDATE users SET magic_link_token = ?, magic_link_expires = ? WHERE user_id = ?");
            $stmt->execute([$token, $expires, $userId]);
            
            // Send verification email
            $baseUrl = 'https://tpb2.sandgems.net';
            $magicLink = "{$baseUrl}/api/verify-magic-link.php?token={$token}";
            $firstName = trim($input['first_name'] ?? '');
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
            
            $magicLinkSent = sendSmtpMail($config, $email, "Your People's Branch Verification Link", $message);
        }
    }
    
    $responseMsg = "Welcome to $townName, $stateCode!";
    if ($magicLinkSent) {
        $responseMsg .= " Check your email to verify your account.";
    } elseif ($isNew) {
        $responseMsg .= " Add your email anytime to save your progress permanently.";
    }
    
    echo json_encode([
        'status' => 'success',
        'user_id' => $userId,
        'state_id' => $stateId,
        'town_id' => $townId,
        'is_new' => $isNew,
        'is_anonymous' => $isNew && (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)),
        'magic_link_sent' => $magicLinkSent,
        'message' => $responseMsg
    ]);
}
