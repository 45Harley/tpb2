<?php
/**
 * TPB2 Verify Magic Link API
 * ==========================
 * Verifies email token and links device to user
 * 
 * GET /api/verify-magic-link.php?token=xxx
 * 
 * - Finds existing user OR creates new
 * - Adds device to user_devices table
 * - Sets cookies for this device
 */

$config = require __DIR__ . '/../config.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    die('Invalid link. No token provided.');
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user by token
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.session_id, u.magic_link_expires,
               COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.magic_link_token = ?
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die('Invalid or expired link. Please request a new one.');
    }

    // Check if expired
    if (strtotime($user['magic_link_expires']) < time()) {
        die('This link has expired. Please request a new one.');
    }

    // Generate a new device session (same format as login.php)
    $deviceSession = 'civic_' . bin2hex(random_bytes(8)) . '_' . time();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Detect device type from user agent
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceType = 'unknown';
    $deviceName = 'Unknown Device';
    
    if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
        if (preg_match('/iPad/i', $userAgent)) {
            $deviceType = 'tablet';
            $deviceName = 'iPad';
        } elseif (preg_match('/iPhone/i', $userAgent)) {
            $deviceType = 'phone';
            $deviceName = 'iPhone';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $deviceType = preg_match('/Mobile/i', $userAgent) ? 'phone' : 'tablet';
            $deviceName = 'Android ' . ($deviceType === 'phone' ? 'Phone' : 'Tablet');
        } else {
            $deviceType = 'phone';
            $deviceName = 'Mobile Device';
        }
    } else {
        $deviceType = 'desktop';
        if (preg_match('/Windows/i', $userAgent)) {
            $deviceName = 'Windows PC';
        } elseif (preg_match('/Macintosh/i', $userAgent)) {
            $deviceName = 'Mac';
        } elseif (preg_match('/Linux/i', $userAgent)) {
            $deviceName = 'Linux PC';
        } else {
            $deviceName = 'Desktop';
        }
    }

    // Check if this device already exists for this user (by user agent, like login.php)
    $stmt = $pdo->prepare("SELECT device_id, login_count FROM user_devices WHERE user_id = ? AND device_name = ? LIMIT 1");
    $stmt->execute([$user['user_id'], substr($userAgent, 0, 100)]);
    $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingDevice) {
        // Update existing device with new session
        $stmt = $pdo->prepare("UPDATE user_devices SET device_session = ?, ip_address = ?, login_count = login_count + 1, last_active_at = NOW(), is_active = 1 WHERE device_id = ?");
        $stmt->execute([$deviceSession, $ipAddress, $existingDevice['device_id']]);
    } else {
        // Add new device for this user
        $stmt = $pdo->prepare("
            INSERT INTO user_devices (user_id, device_session, device_name, device_type, ip_address, login_count, verified_at, is_active)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), 1)
        ");
        $stmt->execute([$user['user_id'], $deviceSession, substr($userAgent, 0, 100), $deviceType, $ipAddress]);
    }

    // Transfer anonymous session points to this user
    // (uses OLD session before we switched to deviceSession)
    $oldSessionId = $user['session_id'] ?? null;
    require_once __DIR__ . '/../includes/point-logger.php';
    PointLogger::init($pdo);
    $transferResult = PointLogger::transferSession($oldSessionId, $user['user_id']);
    $sessionPoints = $transferResult['points_transferred'] ?? 0;

    // Check if already verified
    $alreadyVerified = $user['email_verified'];
    
    // Clear magic link token (regardless of verification status)
    $stmt = $pdo->prepare("
        UPDATE users 
        SET magic_link_token = NULL,
            magic_link_expires = NULL
        WHERE user_id = ?
    ");
    $stmt->execute([$user['user_id']]);

    // Mark email verified if not already
    // Points are awarded by LevelManager → PointLogger (not here — avoids double-award)
    if (!$alreadyVerified) {
        // Check if user_identity_status row exists
        $stmt = $pdo->prepare("SELECT user_id FROM user_identity_status WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $identityExists = $stmt->fetch();

        if ($identityExists) {
            $stmt = $pdo->prepare("
                UPDATE user_identity_status 
                SET email_verified = 1, email_verified_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$user['user_id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO user_identity_status (user_id, email_verified, email_verified_at)
                VALUES (?, 1, NOW())
            ");
            $stmt->execute([$user['user_id']]);
        }

        // Auto-advance identity level via LevelManager
        // LevelManager calls PointLogger::award('email_verified') — the single path for these points
        require_once __DIR__ . '/../includes/level-manager.php';
        LevelManager::checkAndAdvance($pdo, $user['user_id']);
    }

    // Set auth cookies (1 year for magic link)
    require_once __DIR__ . '/../includes/set-cookie.php';
    tpbSetLoginCookies($user['user_id'], $deviceSession, TPB_COOKIE_1_YEAR);

    // Redirect after verification
    $redirectStatus = $alreadyVerified ? 'device_added' : 'success';
    $emailPoints = $alreadyVerified ? 0 : 50;
    $totalPoints = $sessionPoints + $emailPoints;
    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tpb2.sandgems.net');
    
    // Check for return_url parameter (from town pages)
    $returnUrl = $_GET['return_url'] ?? null;
    if ($returnUrl && strpos($returnUrl, '/') === 0) {
        // Relative URL - redirect back to that page
        header('Location: ' . $baseUrl . $returnUrl);
    } else {
        // Default redirect to profile
        header('Location: ' . $baseUrl . '/profile.php?verified=' . $redirectStatus . '&points=' . $totalPoints);
    }
    exit();;

} catch (PDOException $e) {
    die('Database error. Please try again later.');
}
