<?php
/**
 * TPB Login Page - Simple Form Version
 * Direct form POST, no JavaScript fetch complications
 */

// Database connection
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
} catch (PDOException $e) {
    die("Database connection failed");
}

$siteConfig = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/smtp-mail.php';

$error = '';
$success = '';
$showMagicLink = false;

// Check if already logged in
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
if ($sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.user_id FROM users u
        INNER JOIN user_devices ud ON u.user_id = ud.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    if ($stmt->fetch()) {
        header('Location: /profile.php');
        exit;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);
    
    if (empty($email) || empty($password)) {
        $error = 'Email and password required';
    } else {
        // Get IP hash for rate limiting
        $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        // Check IP-based rate limiting
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts
            FROM login_attempts
            WHERE ip_hash = ? AND attempt_type = 'login' AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ipHash]);
        $ipAttempts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($ipAttempts['attempts'] >= 20) {
            $error = 'Too many login attempts. Please wait an hour.';
        } else {
            // Find user
            $stmt = $pdo->prepare("SELECT user_id, email, password_hash, username FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Log failed attempt
                $stmt = $pdo->prepare("INSERT INTO login_attempts (user_id, ip_hash, attempt_type, success) VALUES (NULL, ?, 'login', 0)");
                $stmt->execute([$ipHash]);
                $error = 'Invalid email or password';
            } elseif (empty($user['password_hash'])) {
                $error = 'No password set for this account. Use the magic link to sign in.';
                $showMagicLink = true;
            } elseif (!password_verify($password, $user['password_hash'])) {
                // Log failed attempt
                $stmt = $pdo->prepare("INSERT INTO login_attempts (user_id, ip_hash, attempt_type, success) VALUES (?, ?, 'login', 0)");
                $stmt->execute([$user['user_id'], $ipHash]);
                $error = 'Invalid email or password';
            } else {
                // Success! Transfer any anonymous session points before switching session
                $oldSessionId = $_COOKIE['tpb_civic_session'] ?? null;
                
                // Create or update device session
                $newSessionId = 'civic_' . bin2hex(random_bytes(8)) . '_' . time();
                $cookieExpiry = $rememberMe ? time() + (30 * 24 * 60 * 60) : 0;
                
                // Get device info
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                $deviceType = preg_match('/mobile|android|iphone|ipad/i', $userAgent) ? 'mobile' : 'web';
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                
                // Check for existing device (same user + user agent)
                $stmt = $pdo->prepare("
                    SELECT device_id, login_count 
                    FROM user_devices 
                    WHERE user_id = ? AND device_name = ?
                    LIMIT 1
                ");
                $stmt->execute([$user['user_id'], substr($userAgent, 0, 100)]);
                $existingDevice = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingDevice) {
                    // Update existing device - increment counter, update session + IP
                    $stmt = $pdo->prepare("
                        UPDATE user_devices 
                        SET device_session = ?, 
                            ip_address = ?, 
                            login_count = login_count + 1,
                            is_active = 1,
                            last_active_at = NOW()
                        WHERE device_id = ?
                    ");
                    $stmt->execute([$newSessionId, $ipAddress, $existingDevice['device_id']]);
                } else {
                    // Create new device record
                    $stmt = $pdo->prepare("
                        INSERT INTO user_devices (user_id, device_session, device_name, device_type, ip_address, login_count, is_active)
                        VALUES (?, ?, ?, ?, ?, 1, 1)
                    ");
                    $stmt->execute([$user['user_id'], $newSessionId, substr($userAgent, 0, 100), $deviceType, $ipAddress]);
                }
                
                // Set cookie
                setcookie('tpb_civic_session', $newSessionId, [
                    'expires' => $cookieExpiry,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                
                // Update last login
                $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?");
                $stmt->execute([$user['user_id']]);
                
                // Award daily login points
                require_once __DIR__ . '/includes/point-logger.php';
                PointLogger::init($pdo);
                
                // Transfer anonymous session points to this user
                if ($oldSessionId) {
                    PointLogger::transferSession($oldSessionId, $user['user_id']);
                }
                
                PointLogger::award($user['user_id'], 'daily_login');
                
                // Check for any missed level advancements
                require_once __DIR__ . '/includes/level-manager.php';
                LevelManager::checkAndAdvance($pdo, $user['user_id']);
                
                // Log success
                $stmt = $pdo->prepare("INSERT INTO login_attempts (user_id, ip_hash, attempt_type, success) VALUES (?, ?, 'login', 1)");
                $stmt->execute([$user['user_id'], $ipHash]);
                
                header('Location: /profile.php');
                exit;
            }
        }
    }
}

// Handle magic link request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'magic_link') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email';
    } else {
        $stmt = $pdo->prepare("SELECT user_id, email FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            try {
                // Generate magic token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                $stmt = $pdo->prepare("UPDATE users SET magic_link_token = ?, magic_link_expires = ? WHERE user_id = ?");
                $stmt->execute([$token, $expires, $user['user_id']]);
                
                // Send email
                $magicLink = "https://" . $_SERVER['HTTP_HOST'] . "/api/verify-magic-link.php?token=" . $token;
                $subject = "Your TPB Login Link";
                $body = "Click here to log in:\n\n$magicLink\n\nThis link expires in 15 minutes.";
                
                $mailResult = sendSmtpMail($siteConfig, $user['email'], $subject, $body);
                if (!$mailResult) {
                    $error = 'Email could not be sent. Please try password login.';
                }
            } catch (Exception $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
        // Always show success (don't reveal if email exists)
        $success = 'If that email is registered, a login link has been sent.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | The People's Branch</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f1a;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .container {
            background: #1a1a2e;
            border-radius: 16px;
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            border: 1px solid #2a2a4a;
        }
        .logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .logo h1 {
            color: #d4af37;
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }
        .logo p {
            color: #888;
            font-size: 0.9rem;
        }
        h2 {
            color: #e0e0e0;
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        label {
            display: block;
            color: #888;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background: #0f0f1a;
            border: 1px solid #2a2a4a;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 1rem;
        }
        input:focus {
            outline: none;
            border-color: #d4af37;
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper input {
            padding-right: 3rem;
        }
        .toggle-password {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            cursor: pointer;
            font-size: 1.25rem;
            padding: 0.25rem;
        }
        .toggle-password:hover {
            color: #d4af37;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .checkbox-group input {
            width: auto;
        }
        .checkbox-group label {
            margin: 0;
            color: #888;
        }
        .btn {
            width: 100%;
            padding: 0.875rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #d4af37, #aa8a2e);
            color: #0f0f1a;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }
        .btn-secondary {
            background: transparent;
            border: 1px solid #2a2a4a;
            color: #888;
            margin-top: 0.5rem;
        }
        .btn-secondary:hover {
            border-color: #d4af37;
            color: #d4af37;
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            color: #666;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #2a2a4a;
        }
        .divider span {
            padding: 0 1rem;
            font-size: 0.85rem;
        }
        .message {
            padding: 0.875rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .message.error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid #e74c3c;
            color: #e74c3c;
        }
        .message.success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid #2ecc71;
            color: #2ecc71;
        }
        .footer {
            text-align: center;
            margin-top: 1.5rem;
            color: #666;
            font-size: 0.85rem;
        }
        .footer a {
            color: #d4af37;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .magic-section {
            display: none;
        }
        .magic-section.show {
            display: block;
        }
        .login-section.hide {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>🏛️ The People's Branch</h1>
            <p>Your voice in democracy</p>
        </div>
        
        <h2>Login to Existing Account</h2>
        <p style="margin-top: 0.5rem;"><a href="/join.php" style="color: #7eb8da;">Don't have an account? Create New Account</a></p>
        
        <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <!-- Password Login Form -->
        <div id="loginSection" class="login-section<?= $showMagicLink ? ' hide' : '' ?>">
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="you@example.com" required 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">👁️</button>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="remember_me" id="remember_me">
                    <label for="remember_me">Remember me on this device</label>
                </div>
                
                <button type="submit" class="btn btn-primary">Log In</button>
            </form>
            
            <div class="divider"><span>or</span></div>
            
            <button type="button" class="btn btn-secondary" onclick="showMagicLink()">
                📧 Send me a magic link instead
            </button>
        </div>
        
        <!-- Magic Link Form -->
        <div id="magicSection" class="magic-section<?= $showMagicLink ? ' show' : '' ?>">
            <form method="POST" action="">
                <input type="hidden" name="action" value="magic_link">
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="you@example.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <button type="submit" class="btn btn-primary">Send Magic Link</button>
            </form>
            
            <div class="divider"><span>or</span></div>
            
            <button type="button" class="btn btn-secondary" onclick="showLogin()">
                🔐 Log in with password instead
            </button>
        </div>
        
        <div class="footer">
            <a href="/">← Back to home</a>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const pw = document.getElementById('password');
            const btn = document.querySelector('.toggle-password');
            if (pw.type === 'password') {
                pw.type = 'text';
                btn.textContent = '🙈';
            } else {
                pw.type = 'password';
                btn.textContent = '👁️';
            }
        }
        
        function showMagicLink() {
            document.getElementById('loginSection').classList.add('hide');
            document.getElementById('magicSection').classList.add('show');
        }
        
        function showLogin() {
            document.getElementById('loginSection').classList.remove('hide');
            document.getElementById('magicSection').classList.remove('show');
        }
    </script>
</body>
</html>
