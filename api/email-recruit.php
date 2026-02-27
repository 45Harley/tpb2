<?php
/**
 * Email Recruit API
 * POST /api/email-recruit.php
 * Body: { "to": "friend@example.com", "type": "amendment" }
 * Sends a recruitment email about the People's Accountability Amendment.
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/smtp-mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$to = filter_var($input['to'] ?? '', FILTER_VALIDATE_EMAIL);

if (!$to) {
    echo json_encode(['success' => false, 'error' => 'Valid email required']);
    exit;
}

$siteUrl = $config['base_url'] ?? 'https://tpb2.sandgems.net';

$subject = "The People's Accountability Amendment - How We Fix Democracy";
$message = <<<EOT
70% — The People's Threshold

If 70% of Americans agree you must go, YOU MUST GO.

No more waiting for Congress to impeach. No more hoping politicians hold their own accountable. No more watching corruption go unpunished.

The People's Accountability Amendment gives citizens the power to recall any federal official — including the President — with a 70% vote.

Plus: NO pardons for removed officials. NO immunity claims to delay justice.

19 states already allow recall of governors. Most democracies can remove leaders through no-confidence votes. Why can't Americans recall a president who betrays us?

It's time to bring America into the 21st century.

Read more: {$siteUrl}/elections/the-amendment.php

---
The People's Branch
No Kings. Only Citizens.
EOT;

$sent = sendSmtpMail($config, $to, $subject, $message);

echo json_encode(['success' => $sent]);
