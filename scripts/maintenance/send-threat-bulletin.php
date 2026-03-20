<?php
/**
 * Daily Threat Bulletin Email
 *
 * Sends an HTML digest of threats from the last 2 days to subscribed users.
 * Run via cPanel cron: 0 8 * * * (8:00 AM EST — cPanel clock is EST)
 *
 * Requirements:
 *   - site_settings.threat_bulletin_enabled = '1'
 *   - Users with notify_threat_bulletin = 1, identity_level_id >= 2
 *
 * Usage:
 *   cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/send-threat-bulletin.php
 */

$startTime = microtime(true);

// Bootstrap
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/site-settings.php';
require_once __DIR__ . '/../../includes/severity.php';
require_once __DIR__ . '/../../includes/smtp-mail.php';
require_once __DIR__ . '/../../includes/threat-bulletin-html.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Check admin kill switch
if (getSiteSetting($pdo, 'threat_bulletin_enabled', '0') !== '1') {
    echo "Threat bulletin is disabled. Exiting.\n";
    exit(0);
}

// Get threats from last 2 days
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
    echo "No threats in the last 2 days. Skipping.\n";
    exit(0);
}

$threatCount = count($threats);

// Build email body
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$today = date('M j');
$subject = "TPB Threat Alert: {$threatCount} new threat" . ($threatCount !== 1 ? 's' : '') . " as of {$today}";

// Get subscribers
$subscribers = $pdo->query("
    SELECT user_id, email, first_name
    FROM users
    WHERE notify_threat_bulletin = 1
      AND identity_level_id >= 2
      AND deleted_at IS NULL
      AND email NOT LIKE '%@anonymous.tpb'
      AND email IS NOT NULL
      AND email != ''
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($subscribers)) {
    echo "No subscribers. Skipping.\n";
    exit(0);
}

// Clean up expired tokens
$pdo->exec("DELETE FROM bulletin_tokens WHERE expires_at < NOW()");

// Prepare token insert
$tokenStmt = $pdo->prepare("
    INSERT INTO bulletin_tokens (user_id, token, expires_at)
    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
");

// Send to each subscriber with personalized auth token
$sent = 0;
$failed = 0;

foreach ($subscribers as $sub) {
    // Generate per-subscriber auth token
    $token = bin2hex(random_bytes(16));
    $tokenStmt->execute([$sub['user_id'], $token]);

    // Build personalized email (links route through auth handler)
    $body = buildBulletinHtml($threats, $baseUrl, $threatCount, $token);

    $ok = sendSmtpMail($config, $sub['email'], $subject, $body, null, true);
    if ($ok) {
        $sent++;
    } else {
        $failed++;
        error_log("Threat bulletin failed for user {$sub['user_id']}: {$sub['email']}");
    }
    usleep(1000000); // 1s throttle between sends
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "Threat bulletin sent. Threats: {$threatCount}, Subscribers: " . count($subscribers) . ", Sent: {$sent}, Failed: {$failed}, Time: {$elapsed}s\n";

// Record result in site_settings
$result = json_encode([
    'status' => $failed === 0 ? 'success' : 'partial',
    'timestamp' => date('Y-m-d H:i:s'),
    'threats' => $threatCount,
    'subscribers' => count($subscribers),
    'sent' => $sent,
    'failed' => $failed,
    'elapsed' => $elapsed,
]);
setSiteSetting($pdo, 'threat_bulletin_last_result', $result);
if ($failed === 0) setSiteSetting($pdo, 'threat_bulletin_last_success', date('Y-m-d H:i:s'));


// buildBulletinHtml() is in includes/threat-bulletin-html.php
