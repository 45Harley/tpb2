<?php
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/smtp-mail.php';
require_once dirname(__DIR__) . '/includes/invite-email.php';

$dbUser = getUser($pdo);
if (!$dbUser || $dbUser['identity_level_id'] < 2) {
    http_response_code(403);
    echo json_encode(['error' => 'Email verification required to send invitations.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$emails = $input['emails'] ?? [];

if (empty($emails) || !is_array($emails)) {
    http_response_code(400);
    echo json_encode(['error' => 'No emails provided.']);
    exit;
}

$invitorEmail = $dbUser['email'];
$invitorId = $dbUser['user_id'];
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');

// Prepare statements
$checkUser = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$insertInvite = $pdo->prepare("INSERT INTO invitations (invitor_user_id, invitee_email, token) VALUES (?, ?, ?)");

$results = [];
foreach ($emails as $email) {
    $email = trim(strtolower($email));

    // Validate format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $results[] = ['email' => $email, 'status' => 'invalid'];
        continue;
    }

    // Check if already a member
    $checkUser->execute([$email]);
    if ($checkUser->fetch()) {
        $results[] = ['email' => $email, 'status' => 'already_member'];
        continue;
    }

    // Generate token and insert
    $token = bin2hex(random_bytes(16));
    $insertInvite->execute([$invitorId, $email, $token]);

    // Build email
    $acceptUrl = "{$baseUrl}/invite/accept.php?token={$token}";
    $subject = "Your friend {$invitorEmail} invited you to The People's Branch";
    $body = buildInviteEmail($invitorEmail, $acceptUrl, $baseUrl);

    // Send to invitee
    $ok = sendSmtpMail($config, $email, $subject, $body, null, true);

    $results[] = ['email' => $email, 'status' => $ok ? 'sent' : 'failed'];

    usleep(1000000); // 1s throttle
}

echo json_encode(['results' => $results]);
