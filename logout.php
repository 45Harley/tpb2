<?php
/**
 * TPB Logout
 * Clears all session/user cookies and redirects to home
 */
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

if ($sessionId) {
    $config = require __DIR__ . '/config.php';
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->prepare("UPDATE user_devices SET is_active = 0 WHERE device_session = ?");
        $stmt->execute([$sessionId]);
    } catch (PDOException $e) {
        // Continue — cookie clear is most important
    }
}

// Clear ALL TPB cookies
$tpbCookies = [
    'tpb_civic_session',
    'tpb_user_id',
    'tpb_user_state',
    'tpb_user_town',
    'tpb_visited_demo',
    'tpb_email_verified'
];
foreach ($tpbCookies as $name) {
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Output HTML with JS to clear localStorage, then redirect
?>
<!DOCTYPE html>
<html>
<head><title>Logging out...</title></head>
<body>
<script>
localStorage.removeItem('tpb_civic_session');
window.location.href = '/';
</script>
</body>
</html>
