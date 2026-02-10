<?php
/**
 * TPB2 Send Phone Verification Link API
 * ======================================
 * Sends a magic link to user's VERIFIED EMAIL to confirm their phone number.
 * This is not SMS - it's email-based phone confirmation.
 * 
 * POST /api/send-phone-verify-link.php
 * Body: {
 *   "session_id": "civic_xxx",
 *   "phone": "(555) 123-4567"
 * }
 * 
 * REQUIRES: User must have verified email first
 * FLOW: 
 *   1. User enters phone number
 *   2. Click "Verify Phone"
 *   3. We send email: "Click to confirm your phone: (555) 123-4567"
 *   4. User clicks link -> phone marked as verified
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
require_once __DIR__ . '/../includes/smtp-mail.php';

$input = json_decode(file_get_contents('php://input'), true);

$sessionId = $input['session_id'] ?? null;
$phone = trim($input['phone'] ?? '');
$returnUrl = trim($input['return_url'] ?? '');

if (!$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    exit();
}

if (!$phone) {
    echo json_encode(['status' => 'error', 'message' => 'Phone number required']);
    exit();
}

// Basic phone validation - strip non-digits and check length
$phoneDigits = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 11) {
    echo json_encode(['status' => 'error', 'message' => 'Please enter a valid phone number']);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Find user — tpb_user_id cookie first (reliable), then session fallback
    $cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;
    $user = null;
    
    if ($cookieUserId) {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.first_name, 
                   COALESCE(uis.email_verified, 0) as email_verified,
                   COALESCE(uis.phone_verified, 0) as phone_verified,
                   uis.phone as current_phone
            FROM users u
            LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
            WHERE u.user_id = ?
        ");
        $stmt->execute([$cookieUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$user && $sessionId) {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.email, u.first_name, 
                   COALESCE(uis.email_verified, 0) as email_verified,
                   COALESCE(uis.phone_verified, 0) as phone_verified,
                   uis.phone as current_phone
            FROM user_devices ud
            INNER JOIN users u ON ud.user_id = u.user_id
            LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
            WHERE ud.device_session = ? AND ud.is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email first']);
        exit();
    }

    if (!$user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email before adding phone']);
        exit();
    }

    if (!$user['email']) {
        echo json_encode(['status' => 'error', 'message' => 'No email on file']);
        exit();
    }

    // Generate phone verification token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store phone and token in user_identity_status
    $stmt = $pdo->prepare("
        INSERT INTO user_identity_status (user_id, phone, phone_verify_token, phone_verify_expires)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            phone = VALUES(phone),
            phone_verify_token = VALUES(phone_verify_token),
            phone_verify_expires = VALUES(phone_verify_expires),
            phone_verified = 0
    ");
    $stmt->execute([$user['user_id'], $phone, $token, $expires]);

    // Build verification link
    $baseUrl = $config['base_url'];
    $verifyLink = "{$baseUrl}/api/verify-phone-link.php?token={$token}";
    if ($returnUrl) {
        $verifyLink .= "&return_url=" . urlencode($returnUrl);
    }

    // Send email
    $to = $user['email'];
    $firstName = $user['first_name'] ?: 'Citizen';
    
    $subject = "TPB: Confirm your phone number";
    
    $htmlBody = "
    <div style='font-family: Georgia, serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <h2 style='color: #1a3a5c;'>📱 Confirm Your Phone Number</h2>
        <p>Hi {$firstName},</p>
        <p>You requested to add this phone number to your TPB account:</p>
        <div style='background: #f5f5f5; padding: 15px; border-radius: 8px; text-align: center; margin: 20px 0;'>
            <strong style='font-size: 1.3em; color: #1a3a5c;'>{$phone}</strong>
        </div>
        <p>Click the button below to confirm this is your phone number:</p>
        <div style='text-align: center; margin: 30px 0;'>
            <a href='{$verifyLink}' style='background: #1a3a5c; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                ✓ Yes, This Is My Phone
            </a>
        </div>
        <p style='color: #666; font-size: 0.9em;'>This link expires in 1 hour.</p>
        <p style='color: #666; font-size: 0.9em;'>If you didn't request this, you can ignore this email.</p>
        <hr style='border: none; border-top: 1px solid #ddd; margin: 30px 0;'>
        <p style='color: #888; font-size: 0.85em;'>The People's Branch — Your voice, aggregated</p>
    </div>
    ";

    $textBody = "Confirm Your Phone Number\n\n";
    $textBody .= "Hi {$firstName},\n\n";
    $textBody .= "You requested to add this phone number to your TPB account:\n";
    $textBody .= "{$phone}\n\n";
    $textBody .= "Click this link to confirm: {$verifyLink}\n\n";
    $textBody .= "This link expires in 1 hour.\n\n";
    $textBody .= "— The People's Branch";

    $mailSent = sendSmtpMail($config, $to, $subject, $htmlBody, null, true);

    if ($mailSent) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Verification link sent to your email',
            'email_hint' => substr($user['email'], 0, 3) . '***'
        ]);
    } else {
        // Return link for testing if mail fails
        echo json_encode([
            'status' => 'warning',
            'message' => 'Email may not have sent. Check your inbox.',
            'demo_link' => $verifyLink
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
