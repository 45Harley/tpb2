<?php
/**
 * Magic Link Email Test - SMTP Version
 * Tests SMTP authentication vs raw mail()
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$result = '';
$error = '';
$debug = '';

// SMTP settings - update password
$smtpHost = 'mail.sandgems.net';
$smtpPort = 465;
$smtpUser = 'harley@sandgems.net';
$smtpPass = '!44Dalesmith45!';
$fromEmail = 'harley@sandgems.net';
$fromName = 'The People\'s Branch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $method = $_POST['method'] ?? 'smtp';
    
    if (!$email) {
        $error = 'Invalid email address';
    } else {
        $token = bin2hex(random_bytes(16));
        $testUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/0t/test-mail.php?token=' . $token;
        
        $subject = "Test Magic Link";
        $body = "This is a test magic link:\n\n$testUrl\n\nIf you received this, email works!";
        
        if ($method === 'mail') {
            // Try raw mail()
            $headers = "From: $fromEmail\r\nReply-To: $fromEmail\r\nX-Mailer: PHP/" . phpversion();
            $sent = mail($email, $subject, $body, $headers);
            $lastError = error_get_last();
            
            if ($sent) {
                $result = "mail() returned TRUE - check your inbox";
            } else {
                $error = "mail() returned FALSE";
                if ($lastError) {
                    $debug = $lastError['message'];
                }
            }
        } else {
            // Try SMTP
            if (empty($smtpPass)) {
                $error = "SMTP password not configured - edit test-mail.php line 17";
            } else {
                $sent = sendSMTP($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $email, $subject, $body, $debug);
                if ($sent) {
                    $result = "SMTP sent successfully - check your inbox";
                } else {
                    $error = "SMTP failed";
                }
            }
        }
    }
}

function sendSMTP($host, $port, $user, $pass, $fromEmail, $fromName, $toEmail, $subject, $body, &$debug) {
    $debug = "";
    
    // Connect with SSL
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $socket = @stream_socket_client(
        "ssl://$host:$port",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    
    if (!$socket) {
        $debug = "Connection failed: $errstr ($errno)";
        return false;
    }
    
    $debug .= "Connected to $host:$port\n";
    
    // Read greeting (may be multiple lines)
    while ($line = fgets($socket, 512)) {
        $debug .= "S: $line";
        if (substr($line, 3, 1) == ' ') break;
    }
    
    // EHLO
    fwrite($socket, "EHLO localhost\r\n");
    $debug .= "C: EHLO localhost\n";
    while ($line = fgets($socket, 512)) {
        $debug .= "S: $line";
        if (substr($line, 3, 1) == ' ') break;
    }
    
    // AUTH LOGIN
    fwrite($socket, "AUTH LOGIN\r\n");
    $debug .= "C: AUTH LOGIN\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    // Should get 334 asking for username
    if (substr($response, 0, 3) != '334') {
        $debug .= "Expected 334, got: " . substr($response, 0, 3) . "\n";
        fclose($socket);
        return false;
    }
    
    // Username (base64)
    fwrite($socket, base64_encode($user) . "\r\n");
    $debug .= "C: [username base64]\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    // Should get 334 asking for password
    if (substr($response, 0, 3) != '334') {
        $debug .= "Expected 334 for password, got: " . substr($response, 0, 3) . "\n";
        fclose($socket);
        return false;
    }
    
    // Password (base64)
    fwrite($socket, base64_encode($pass) . "\r\n");
    $debug .= "C: [password base64]\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    if (substr($response, 0, 3) != '235') {
        $debug .= "AUTH FAILED\n";
        fclose($socket);
        return false;
    }
    
    // MAIL FROM
    fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
    $debug .= "C: MAIL FROM:<$fromEmail>\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    // RCPT TO
    fwrite($socket, "RCPT TO:<$toEmail>\r\n");
    $debug .= "C: RCPT TO:<$toEmail>\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    // DATA
    fwrite($socket, "DATA\r\n");
    $debug .= "C: DATA\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    // Message
    $message = "From: $fromName <$fromEmail>\r\n";
    $message .= "To: $toEmail\r\n";
    $message .= "Subject: $subject\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "\r\n";
    $message .= $body;
    $message .= "\r\n.\r\n";
    
    fwrite($socket, $message);
    $debug .= "C: [message body]\n";
    $response = fgets($socket, 512);
    $debug .= "S: $response";
    
    $success = (substr($response, 0, 3) == '250');
    
    // QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    
    return $success;
}

// Check if arriving via test link
$token = $_GET['token'] ?? null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Magic Link Test</title>
    <style>
        body { font-family: Georgia, serif; max-width: 700px; margin: 50px auto; padding: 20px; background: #1a1a2e; color: #e8e8e8; }
        h1 { color: #d4af37; }
        input[type="email"] { padding: 12px; width: 300px; font-size: 16px; }
        button { padding: 12px 24px; background: #d4af37; color: #000; border: none; font-size: 16px; cursor: pointer; margin: 5px; }
        .success { background: #2a4a2a; border: 1px solid #4a4; padding: 20px; margin: 20px 0; }
        .error { background: #4a2a2a; border: 1px solid #a44; padding: 20px; margin: 20px 0; }
        .info { background: #2a2a4a; border: 1px solid #44a; padding: 20px; margin: 20px 0; }
        pre { background: #111; padding: 10px; overflow-x: auto; font-size: 12px; }
        label { margin-right: 15px; }
    </style>
</head>
<body>
    <h1>Magic Link Test</h1>
    
    <?php if ($token): ?>
        <div class="success">
            <strong>SUCCESS!</strong><br>
            You clicked the magic link. Token: <?= htmlspecialchars($token) ?><br><br>
            This means email works and the link was received.
        </div>
    <?php endif; ?>
    
    <?php if ($result): ?>
        <div class="success"><?= htmlspecialchars($result) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($debug): ?>
        <div class="info"><strong>Debug:</strong><pre><?= htmlspecialchars($debug) ?></pre></div>
    <?php endif; ?>
    
    <form method="POST">
        <p>Enter your email to test:</p>
        <input type="email" name="email" placeholder="your@email.com" required><br><br>
        
        <p>Method:</p>
        <label><input type="radio" name="method" value="smtp" checked> SMTP (authenticated)</label>
        <label><input type="radio" name="method" value="mail"> mail() (raw)</label>
        <br><br>
        
        <button type="submit">Send Test Email</button>
    </form>
    
    <div class="info">
        <strong>SMTP Settings:</strong><br>
        Host: <?= $smtpHost ?>:<?= $smtpPort ?><br>
        User: <?= $smtpUser ?><br>
        Password: <?= $smtpPass ? '(configured)' : '<strong style="color:#f88">NOT SET - edit line 17</strong>' ?>
    </div>
</body>
</html>
