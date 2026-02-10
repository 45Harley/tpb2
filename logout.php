<?php
/**
 * TPB Logout
 * Clears all session/user cookies and redirects to home
 */
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;

if ($sessionId) {
    $config = [
        'host' => 'localhost',
        'database' => 'sandge5_tpb2',
        'username' => 'sandge5_tpb2',
        'password' => '.YeO6kSJAHh5',
        'charset' => 'utf8mb4'
    ];
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
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
