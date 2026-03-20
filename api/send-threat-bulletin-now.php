<?php
/**
 * Send today's threat bulletin immediately to the current user.
 * Called when a user clicks "Subscribe Free" on the threats page.
 * Same email format as the daily cron bulletin.
 */

header('Content-Type: application/json');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/severity.php';
require_once __DIR__ . '/../includes/smtp-mail.php';
require_once __DIR__ . '/../includes/threat-bulletin-html.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dbUser = getUser($pdo);
if (!$dbUser || ($dbUser['identity_level_id'] ?? 0) < 2) {
    echo json_encode(['status' => 'error', 'message' => 'Not authorized']);
    exit;
}

$email = $dbUser['email'] ?? '';
if (empty($email) || strpos($email, '@anonymous.tpb') !== false) {
    echo json_encode(['status' => 'error', 'message' => 'No verified email']);
    exit;
}

// Get threats from last 2 days (same query as daily cron)
$threats = $pdo->query("
    SELECT et.threat_id, et.threat_date, et.title, et.severity_score, et.branch,
           eo.full_name AS official_name
    FROM executive_threats et
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    WHERE et.is_active = 1
      AND et.threat_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
    ORDER BY et.severity_score DESC, et.threat_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($threats)) {
    echo json_encode(['status' => 'ok', 'message' => 'No recent threats to send', 'sent' => false]);
    exit;
}

$threatCount = count($threats);
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$today = date('M j');
$subject = "TPB Threat Alert: {$threatCount} new threat" . ($threatCount !== 1 ? 's' : '') . " as of {$today}";

// Generate auth token for email links
$token = bin2hex(random_bytes(16));
$pdo->prepare("INSERT INTO bulletin_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))")
    ->execute([$dbUser['user_id'], $token]);

$body = buildBulletinHtml($threats, $baseUrl, $threatCount, $token);
$ok = sendSmtpMail($config, $email, $subject, $body, null, true);

if ($ok) {
    echo json_encode(['status' => 'success', 'message' => 'Bulletin sent', 'threats' => $threatCount]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to send email']);
}
