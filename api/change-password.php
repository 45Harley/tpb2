<?php
/**
 * TPB Change Password API
 * Handles setting and changing passwords with rate limiting
 */

header('Content-Type: application/json');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

// Database connection
$config = require __DIR__ . '/../config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

// Get user via centralized auth
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser) {
    http_response_code(401);
    die(json_encode(['status' => 'error', 'message' => 'Not logged in']));
}

$user = $dbUser;

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';

// Validate new password
if (strlen($newPassword) < 8) {
    die(json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters']));
}

// Get IP hash for rate limiting
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

// Check for lockout (rate limiting)
$stmt = $pdo->prepare("
    SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
    FROM login_attempts
    WHERE user_id = ? 
      AND attempt_type = 'password_change'
      AND success = 0
      AND attempted_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
");
$stmt->execute([$user['user_id']]);
$attempts = $stmt->fetch(PDO::FETCH_ASSOC);

if ($attempts['attempts'] >= 3) {
    // Calculate time remaining
    $lastAttempt = strtotime($attempts['last_attempt']);
    $lockoutEnds = $lastAttempt + (30 * 60);
    $remaining = ceil(($lockoutEnds - time()) / 60);
    
    die(json_encode([
        'status' => 'locked',
        'message' => 'Too many failed attempts',
        'minutes_remaining' => max(1, $remaining)
    ]));
}

// Also check IP-based rate limiting
$stmt = $pdo->prepare("
    SELECT COUNT(*) as attempts
    FROM login_attempts
    WHERE ip_hash = ?
      AND attempt_type = 'password_change'
      AND success = 0
      AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
");
$stmt->execute([$ipHash]);
$ipAttempts = $stmt->fetch(PDO::FETCH_ASSOC);

if ($ipAttempts['attempts'] >= 10) {
    die(json_encode([
        'status' => 'locked',
        'message' => 'Too many attempts from this location',
        'minutes_remaining' => 60
    ]));
}

// If user has existing password, verify current password
$hasExistingPassword = !empty($user['password_hash']);

if ($hasExistingPassword) {
    if (empty($currentPassword)) {
        die(json_encode(['status' => 'error', 'message' => 'Current password required']));
    }
    
    if (!password_verify($currentPassword, $user['password_hash'])) {
        // Log failed attempt
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (user_id, ip_hash, attempt_type, success)
            VALUES (?, ?, 'password_change', 0)
        ");
        $stmt->execute([$user['user_id'], $ipHash]);
        
        // Check how many attempts left
        $attemptsLeft = 3 - ($attempts['attempts'] + 1);
        
        die(json_encode([
            'status' => 'error',
            'message' => 'Current password is incorrect' . ($attemptsLeft > 0 ? " ($attemptsLeft attempts remaining)" : '')
        ]));
    }
}

// Hash new password
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

// Update password
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
$stmt->execute([$newHash, $user['user_id']]);

// Log successful attempt
$stmt = $pdo->prepare("
    INSERT INTO login_attempts (user_id, ip_hash, attempt_type, success)
    VALUES (?, ?, 'password_change', 1)
");
$stmt->execute([$user['user_id'], $ipHash]);

// Award civic points for setting/changing password
require_once __DIR__ . '/../includes/point-logger.php';
PointLogger::init($pdo);
PointLogger::award($user['user_id'], 'password_set', 'security', null, 'profile');

echo json_encode([
    'status' => 'success',
    'action' => $hasExistingPassword ? 'changed' : 'set',
    'message' => 'Password ' . ($hasExistingPassword ? 'changed' : 'set') . ' successfully'
]);
