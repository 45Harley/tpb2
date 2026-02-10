<?php
/**
 * SMTP Mail Helper
 * Sends email via SMTP using config.php settings
 * 
 * Usage:
 *   require_once __DIR__ . '/../config.php';
 *   require_once __DIR__ . '/smtp-mail.php';
 *   $config = require __DIR__ . '/../config.php';
 *   $sent = sendSmtpMail($config, $toEmail, $subject, $body);
 */

/**
 * Send email via SMTP
 * 
 * @param array $config - Config array with smtp settings
 * @param string $toEmail - Recipient email
 * @param string $subject - Email subject
 * @param string $body - Plain text body
 * @param string|null $fromName - Optional from name override
 * @return bool - Success/failure
 */
function sendSmtpMail($config, $toEmail, $subject, $body, $fromName = null, $isHtml = false) {
    $smtp = $config['smtp'];
    $fromEmail = $config['email_from'];
    $fromName = $fromName ?? $config['email_from_name'] ?? 'The People\'s Branch';
    
    // Connect with SSL
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $socket = @stream_socket_client(
        "ssl://{$smtp['host']}:{$smtp['port']}",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    
    if (!$socket) {
        error_log("SMTP connection failed: $errstr ($errno)");
        return false;
    }
    
    // Read greeting (may be multiple lines)
    while ($line = fgets($socket, 512)) {
        if (substr($line, 3, 1) == ' ') break;
    }
    
    // EHLO
    fwrite($socket, "EHLO localhost\r\n");
    while ($line = fgets($socket, 512)) {
        if (substr($line, 3, 1) == ' ') break;
    }
    
    // AUTH LOGIN
    fwrite($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') {
        error_log("SMTP AUTH LOGIN failed: $response");
        fclose($socket);
        return false;
    }
    
    // Username
    fwrite($socket, base64_encode($smtp['username']) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') {
        error_log("SMTP username failed: $response");
        fclose($socket);
        return false;
    }
    
    // Password
    fwrite($socket, base64_encode($smtp['password']) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '235') {
        error_log("SMTP auth failed: $response");
        fclose($socket);
        return false;
    }
    
    // MAIL FROM
    fwrite($socket, "MAIL FROM:<$fromEmail>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP MAIL FROM failed: $response");
        fclose($socket);
        return false;
    }
    
    // RCPT TO
    fwrite($socket, "RCPT TO:<$toEmail>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') {
        error_log("SMTP RCPT TO failed: $response");
        fclose($socket);
        return false;
    }
    
    // DATA
    fwrite($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '354') {
        error_log("SMTP DATA failed: $response");
        fclose($socket);
        return false;
    }
    
    // Message
    $contentType = $isHtml ? 'text/html' : 'text/plain';
    $message = "From: $fromName <$fromEmail>\r\n";
    $message .= "To: $toEmail\r\n";
    $message .= "Subject: $subject\r\n";
    $message .= "Content-Type: $contentType; charset=UTF-8\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "\r\n";
    $message .= $body;
    $message .= "\r\n.\r\n";
    
    fwrite($socket, $message);
    $response = fgets($socket, 512);
    
    $success = (substr($response, 0, 3) == '250');
    
    // QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    
    if (!$success) {
        error_log("SMTP send failed: $response");
    }
    
    return $success;
}
