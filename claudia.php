<?php
/**
 * Claudia Pop-Out — Full-page conversational assistant
 * Opened via window.open() from the widget's pop-out button.
 */
$config = require 'config.php';
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) { $pdo = null; }

// Check site-wide toggle
$claudiaWidgetEnabled = '0';
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute(['claudia_widget_enabled']);
        $claudiaWidgetEnabled = $stmt->fetchColumn() ?: '0';
    } catch (Exception $e) {
        $claudiaWidgetEnabled = '0';
    }
}
if ($claudiaWidgetEnabled !== '1') {
    echo '<html><body style="background:#0f172a;color:#94a3b8;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif"><p>Claudia is currently disabled.</p></body></html>';
    exit;
}

require_once __DIR__ . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : null;

// Build user data for JS
$claudiaUser = null;
if ($dbUser) {
    $claudiaUser = [
        'userId' => (int)$dbUser['user_id'],
        'firstName' => $dbUser['first_name'] ?? null,
        'stateAbbr' => $dbUser['state_abbrev'] ?? null,
        'townName' => $dbUser['town_name'] ?? null,
        'identityLevel' => (int)($dbUser['identity_level_id'] ?? 1),
        'isReturning' => true,
    ];
}

// Pop-out gets all capabilities
$claudiaConfigJson = json_encode([
    'context' => 'popout',
    'capabilities' => ['auth', 'onboarding', 'mandates', 'profile'],
    'events' => false,
    'user' => $claudiaUser,
    'siteEnabled' => true,
    'isPopout' => true,
], JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claudia — Your Civic Guide</title>
    <link rel="stylesheet" href="/assets/claudia/claudia.css">
    <link rel="stylesheet" href="/assets/claudia/claudia-popout.css">
</head>
<body>
    <div id="claudia-popout">
        <div class="claudia-popout-header">
            <span class="claudia-popout-title">C — Your Civic Guide</span>
            <button class="claudia-popout-settings" id="claudia-settings-btn" title="Settings">&#9881;</button>
            <div class="claudia-settings-menu" id="claudia-settings-menu">
                <div class="claudia-settings-item" data-action="change-mode">Change interaction mode</div>
                <div class="claudia-settings-item" data-action="clear-chat">Clear conversation</div>
            </div>
        </div>
        <div class="claudia-popout-messages" id="claudia-messages"></div>
        <div class="claudia-typing" id="claudia-typing">
            Claudia is thinking<span>.</span><span>.</span><span>.</span>
        </div>
        <div class="claudia-popout-input">
            <button class="claudia-mic-btn" id="claudia-mic-btn" title="Voice input">&#127908;</button>
            <input type="text" class="claudia-text-input" id="claudia-text-input"
                   placeholder="Type your message..." autocomplete="off">
            <button class="claudia-send-btn" id="claudia-send-btn">Send</button>
        </div>
    </div>

    <script>window.ClaudiaConfig = <?= $claudiaConfigJson ?>;</script>
    <script src="/assets/claudia/claudia-core.js"></script>
    <script src="/assets/claudia/claudia-auth.js"></script>
    <script src="/assets/claudia/claudia-onboarding.js"></script>
    <script src="/assets/claudia/claudia-popout.js"></script>
</body>
</html>
