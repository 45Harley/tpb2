<?php
/**
 * TPB2 Save Profile API
 * =====================
 * Saves user profile data (town, state, first name, last name)
 * Creates user record if doesn't exist (by session_id)
 * 
 * POST /api/save-profile.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "town": "Ledyard",
 *   "state": "CT",
 *   "first_name": "John",
 *   "last_name": "Doe"
 * }
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

$config = require __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

$sessionId = $input['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'No session_id provided']);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Use centralized auth to find user
    require_once __DIR__ . '/../includes/get-user.php';
    $user = getUser($pdo);

    // If no user found, create one tied to this session
    // This allows anonymous users to save location and build civic points
    if (!$user) {
        // Create user record
        $stmt = $pdo->prepare("
            INSERT INTO users (session_id, identity_level_id, created_at)
            VALUES (?, 1, NOW())
        ");
        $stmt->execute([$sessionId]);
        $userId = $pdo->lastInsertId();
        
        // Transfer anonymous session points to new user
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        PointLogger::transferSession($sessionId, $userId);
        
        // Create user_devices entry
        $stmt = $pdo->prepare("
            INSERT INTO user_devices (user_id, device_session, is_active, created_at)
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$userId, $sessionId]);
        
        // Create user_identity_status entry
        $stmt = $pdo->prepare("
            INSERT INTO user_identity_status (user_id)
            VALUES (?)
        ");
        $stmt->execute([$userId]);
        
        $user = ['user_id' => $userId, 'email_verified' => 0];
    }
    
    $userId = $user['user_id'];

    // Build update query dynamically
    $updates = [];
    $params = [];

    // State - look up state_id
    if (!empty($input['state'])) {
        $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
        $stmt->execute([$input['state']]);
        $stateId = $stmt->fetchColumn();
        if ($stateId) {
            $updates[] = "current_state_id = ?";
            $params[] = $stateId;
        }
    } else if (array_key_exists('state', $input) && empty($input['state'])) {
        // Explicitly clearing state
        $updates[] = "current_state_id = NULL";
    }

    // Town - look up town_id from pre-populated towns table
    // Towns table now contains ~29,500 US towns from zip_codes import (2025-12-21)
    if (!empty($input['town']) && !empty($input['state'])) {
        // Get state_id first
        $stmt = $pdo->prepare("SELECT state_id FROM states WHERE abbreviation = ?");
        $stmt->execute([$input['state']]);
        $stateId = $stmt->fetchColumn();
        
        if ($stateId) {
            // Look up town (case-insensitive for flexibility)
            $stmt = $pdo->prepare("SELECT town_id FROM towns WHERE LOWER(town_name) = LOWER(?) AND state_id = ?");
            $stmt->execute([$input['town'], $stateId]);
            $townId = $stmt->fetchColumn();
            
            if ($townId) {
                $updates[] = "current_town_id = ?";
                $params[] = $townId;
            }
            // If town not found, don't fail - just don't set town_id
            // This handles edge cases where a place name from geolocation
            // doesn't match our towns table exactly
        }
    } else if (array_key_exists('town', $input) && empty($input['town'])) {
        // Explicitly clearing town
        $updates[] = "current_town_id = NULL";
    }

    // Latitude/Longitude (from geolocation)
    if (isset($input['latitude']) && isset($input['longitude'])) {
        $updates[] = "latitude = ?";
        $params[] = $input['latitude'];
        $updates[] = "longitude = ?";
        $params[] = $input['longitude'];
        $updates[] = "location_updated_at = NOW()";
    }

    // District fields (from OpenStates geo API)
    if (array_key_exists('us_congress_district', $input)) {
        if ($input['us_congress_district']) {
            $updates[] = "us_congress_district = ?";
            $params[] = $input['us_congress_district'];
        } else {
            $updates[] = "us_congress_district = NULL";
        }
    }
    if (array_key_exists('state_senate_district', $input)) {
        if ($input['state_senate_district']) {
            $updates[] = "state_senate_district = ?";
            $params[] = $input['state_senate_district'];
        } else {
            $updates[] = "state_senate_district = NULL";
        }
    }
    if (array_key_exists('state_house_district', $input)) {
        if ($input['state_house_district']) {
            $updates[] = "state_house_district = ?";
            $params[] = $input['state_house_district'];
        } else {
            $updates[] = "state_house_district = NULL";
        }
    }
    // Update districts timestamp if any district field was set
    if (array_key_exists('us_congress_district', $input) || 
        array_key_exists('state_senate_district', $input) || 
        array_key_exists('state_house_district', $input)) {
        $updates[] = "districts_updated_at = NOW()";
    }

    // First name
    if (!empty($input['first_name'])) {
        $updates[] = "first_name = ?";
        $params[] = $input['first_name'];
    }

    // Last name
    if (!empty($input['last_name'])) {
        $updates[] = "last_name = ?";
        $params[] = $input['last_name'];
    }

    // Display preferences (toggles)
    if (array_key_exists('show_first_name', $input)) {
        $updates[] = "show_first_name = ?";
        $params[] = $input['show_first_name'] ? 1 : 0;
    }
    if (array_key_exists('show_last_name', $input)) {
        $updates[] = "show_last_name = ?";
        $params[] = $input['show_last_name'] ? 1 : 0;
    }
    if (array_key_exists('show_age_bracket', $input)) {
        $updates[] = "show_age_bracket = ?";
        $params[] = $input['show_age_bracket'] ? 1 : 0;
    }

    // Age bracket
    if (!empty($input['age_bracket'])) {
        $updates[] = "age_bracket = ?";
        $params[] = $input['age_bracket'];
    }

    // Email - if changed, clear email_verified
    $emailChanged = false;
    if (!empty($input['email'])) {
        // Check if email is different from current
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $currentEmail = $stmt->fetchColumn();
        
        if ($currentEmail !== $input['email']) {
            $updates[] = "email = ?";
            $params[] = $input['email'];
            $emailChanged = true;
        }
    }

    // Phone - save to user_identity_status table
    $phoneSaved = false;
    if (!empty($input['phone'])) {
        // Check if user_identity_status row exists
        $stmt = $pdo->prepare("SELECT user_id FROM user_identity_status WHERE user_id = ?");
        $stmt->execute([$userId]);
        $identityExists = $stmt->fetch();

        if ($identityExists) {
            $stmt = $pdo->prepare("UPDATE user_identity_status SET phone = ? WHERE user_id = ?");
            $stmt->execute([$input['phone'], $userId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO user_identity_status (user_id, phone) VALUES (?, ?)");
            $stmt->execute([$userId, $input['phone']]);
        }
        $phoneSaved = true;
    }

    // If email changed, clear email_verified
    if ($emailChanged) {
        $stmt = $pdo->prepare("SELECT user_id FROM user_identity_status WHERE user_id = ?");
        $stmt->execute([$userId]);
        $identityExists = $stmt->fetch();
        
        if ($identityExists) {
            $stmt = $pdo->prepare("UPDATE user_identity_status SET email_verified = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
    }

    // Handle phone verification clear (for changing phone)
    if (array_key_exists('phoneVerified', $input) && $input['phoneVerified'] === false) {
        $stmt = $pdo->prepare("SELECT user_id FROM user_identity_status WHERE user_id = ?");
        $stmt->execute([$userId]);
        $identityExists = $stmt->fetch();
        
        if ($identityExists) {
            $stmt = $pdo->prepare("UPDATE user_identity_status SET phone_verified = 0, phone = NULL, phone_verify_token = NULL, phone_verify_expires = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
    }

    // Update users table if there are changes
    if (!empty($updates)) {
        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Profile saved',
        'user_id' => $userId
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
