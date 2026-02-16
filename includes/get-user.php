<?php
/**
 * TPB Shared User Lookup
 * ======================
 * THE standard for finding the current user. Every page uses this.
 *
 * Priority:
 *   1. tpb_civic_session cookie → user_devices lookup (DB-validated, checks is_active)
 *   2. tpb_user_id cookie (direct fallback)
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/get-user.php';
 *   $dbUser = getUser($pdo);         // finds user from cookies
 *   $navVars = getNavVarsForUser($dbUser);
 */

/**
 * THE ONE FUNCTION — finds the current user from available cookies.
 * Call this. Don't roll your own.
 *
 * @param PDO $pdo Database connection
 * @return array|false User data or false if not found
 */
function getUser($pdo) {
    // Method 1: tpb_civic_session cookie → user_devices table (DB-validated, authoritative)
    $sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;
    if ($sessionId) {
        $user = getUserBySession($pdo, $sessionId);
        if ($user) return $user;
    }

    // Method 2: tpb_user_id cookie (direct lookup — fallback)
    $cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;
    if ($cookieUserId) {
        $user = getUserByUserId($pdo, $cookieUserId);
        if ($user) return $user;
    }

    return false;
}

/**
 * Get user by user_id (from tpb_user_id cookie)
 */
function getUserByUserId($pdo, $userId) {
    if (!$userId) return false;
    
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.first_name, u.last_name,
               u.current_town_id, u.current_state_id, u.civic_points,
               u.age_bracket, u.parent_consent, u.bio,
               u.show_first_name, u.show_last_name, u.show_age_bracket,
               u.identity_level_id, u.password_hash,
               u.street_address, u.zip_code, u.latitude, u.longitude,
               u.us_congress_district, u.state_senate_district, u.state_house_district,
               s.abbreviation as state_abbrev, s.state_name,
               tw.town_name,
               il.level_name as identity_level_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified,
               COALESCE(uis.phone, '') as phone
        FROM users u
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE u.user_id = ? AND u.deleted_at IS NULL
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get user by device session (from tpb_civic_session cookie)
 */
function getUserBySession($pdo, $sessionId) {
    if (!$sessionId) return false;
    
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.email, u.first_name, u.last_name,
               u.current_town_id, u.current_state_id, u.civic_points,
               u.age_bracket, u.parent_consent, u.bio,
               u.show_first_name, u.show_last_name, u.show_age_bracket,
               u.identity_level_id, u.password_hash,
               u.street_address, u.zip_code, u.latitude, u.longitude,
               u.us_congress_district, u.state_senate_district, u.state_house_district,
               s.abbreviation as state_abbrev, s.state_name,
               tw.town_name,
               il.level_name as identity_level_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified,
               COALESCE(uis.phone, '') as phone
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE ud.device_session = ? AND ud.is_active = 1 AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Get basic user (minimal fields for quick lookups)
 */
function getUserBasicBySession($pdo, $sessionId) {
    if (!$sessionId) return false;

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.civic_points
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1 AND u.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$sessionId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Privacy-aware display name for a user.
 * Respects show_first_name / show_last_name flags.
 * Falls back to username if both are hidden.
 *
 * @param array $user Row from users table (or getUser() result)
 * @return string Display-safe name
 */
function getDisplayName($user) {
    if (!$user) return 'Anonymous';

    $parts = [];
    if (!empty($user['show_first_name']) && !empty($user['first_name'])) {
        $parts[] = $user['first_name'];
    }
    if (!empty($user['show_last_name']) && !empty($user['last_name'])) {
        $parts[] = $user['last_name'];
    }

    if ($parts) return implode(' ', $parts);

    // Fallback: username (auto-generated handle)
    return $user['username'] ?? 'Anonymous';
}

/**
 * Get nav variables for a user (or visitor)
 */
function getNavVarsForUser($dbUser, $sessionPoints = 0) {
    if (!$dbUser) {
        return [
            'userId' => 0,
            'trustLevel' => 'Visitor',
            'userTrustLevel' => 0,
            'points' => $sessionPoints,
            'isLoggedIn' => false,
            'userEmail' => '',
            'userTownName' => '',
            'userTownSlug' => '',
            'userStateAbbr' => '',
            'userStateDisplay' => ''
        ];
    }

    $levelId = (int)($dbUser['identity_level_id'] ?? 1);
    $levelName = ucfirst($dbUser['identity_level_name'] ?? 'anonymous');
    $townName = $dbUser['town_name'] ?? '';

    return [
        'userId' => (int)($dbUser['user_id'] ?? 0),
        'trustLevel' => "Level {$levelId}: {$levelName}",
        'userTrustLevel' => $levelId,
        'points' => (int)($dbUser['civic_points'] ?? 0),
        'isLoggedIn' => true,
        'userEmail' => $dbUser['email'] ?? '',
        'userTownName' => $townName,
        'userTownSlug' => $townName ? strtolower(str_replace(' ', '-', $townName)) : '',
        'userStateAbbr' => strtolower($dbUser['state_abbrev'] ?? ''),
        'userStateDisplay' => $dbUser['state_abbrev'] ?? ''
    ];
}
