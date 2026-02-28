<?php
/**
 * Verify Bulletin Token
 * =====================
 * Auto-authenticates users clicking links in bulletin emails.
 * Clicking proves email ownership (same proof as magic link).
 *
 * GET /api/verify-bulletin-token.php?bt=<token>&dest=/elections/threats.php
 */

$config = require __DIR__ . '/../config.php';

$token = $_GET['bt'] ?? null;
$dest = $_GET['dest'] ?? '/elections/threats.php';

// Validate dest is a relative path (prevent open redirect)
if (!$dest || $dest[0] !== '/') {
    $dest = '/elections/threats.php';
}

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tpb2.sandgems.net');

if (!$token) {
    header('Location: ' . $baseUrl . $dest);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Look up token
    $stmt = $pdo->prepare("
        SELECT bt.token_id, bt.user_id, bt.used_at,
               u.email, u.deleted_at
        FROM bulletin_tokens bt
        JOIN users u ON bt.user_id = u.user_id
        WHERE bt.token = ? AND bt.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['deleted_at'] !== null) {
        // Invalid/expired token or deleted user — redirect without auth
        header('Location: ' . $baseUrl . $dest);
        exit;
    }

    // Mark first use
    if (!$row['used_at']) {
        $pdo->prepare("UPDATE bulletin_tokens SET used_at = NOW() WHERE token_id = ?")->execute([$row['token_id']]);
    }

    // Generate device session (same pattern as verify-magic-link.php)
    $deviceSession = 'civic_' . bin2hex(random_bytes(8)) . '_' . time();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Detect device type
    $deviceType = 'desktop';
    if (preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent)) {
        $deviceType = preg_match('/iPad|Tablet/i', $userAgent) ? 'tablet' : 'phone';
    }

    // Check if device already exists for this user
    $stmt = $pdo->prepare("SELECT device_id FROM user_devices WHERE user_id = ? AND device_name = ? LIMIT 1");
    $stmt->execute([$row['user_id'], substr($userAgent, 0, 100)]);
    $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingDevice) {
        $stmt = $pdo->prepare("UPDATE user_devices SET device_session = ?, ip_address = ?, login_count = login_count + 1, last_active_at = NOW(), is_active = 1 WHERE device_id = ?");
        $stmt->execute([$deviceSession, $ipAddress, $existingDevice['device_id']]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO user_devices (user_id, device_session, device_name, device_type, ip_address, login_count, verified_at, is_active)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), 1)
        ");
        $stmt->execute([$row['user_id'], $deviceSession, substr($userAgent, 0, 100), $deviceType, $ipAddress]);
    }

    // Set auth cookies
    require_once __DIR__ . '/../includes/set-cookie.php';
    tpbSetLoginCookies($row['user_id'], $deviceSession, TPB_COOKIE_30_DAYS);

    header('Location: ' . $baseUrl . $dest);
    exit;

} catch (PDOException $e) {
    // On error, still redirect (graceful degradation)
    header('Location: ' . $baseUrl . $dest);
    exit;
}
