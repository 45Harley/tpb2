<?php
/**
 * Claudia Widget — Platform-Wide Conversational Assistant
 * Include this on any page to add the C widget.
 * Requires: $pdo in scope (from host page).
 * Optional: $claudiaConfig array set before include.
 */

// Auto-derive context from $currentPage + request URI
if (!isset($claudiaConfig)) {
    // Build context from nav state: $currentPage (set by every page) + sub-page from URI
    $autoContext = isset($currentPage) ? $currentPage : 'general';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $basename = basename($path, '.php');

    // Add sub-page specificity (e.g. elections + threats → elections-threats)
    if ($basename && $basename !== 'index' && $basename !== $autoContext) {
        $autoContext .= '-' . preg_replace('/[^a-z0-9-]/', '', strtolower($basename));
    }

    $claudiaConfig = [
        'context' => $autoContext,
        'capabilities' => ['auth'],
        'events' => false,
    ];
}

// Toggle check: site-wide
// Note: getSiteSetting() is defined in admin.php. For c-widget.php,
// use a simple inline query since we don't want to require admin.php.
$claudiaWidgetEnabled = '0';
if (isset($pdo) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute(['claudia_widget_enabled']);
        $claudiaWidgetEnabled = $stmt->fetchColumn() ?: '0';
    } catch (Exception $e) {
        // If query fails (table doesn't exist yet, etc.), widget stays off
        $claudiaWidgetEnabled = '0';
    }
}

if ($claudiaWidgetEnabled !== '1') return; // Site-wide OFF — emit nothing

// Get user — host page must have $pdo in scope. $dbUser may already exist from host page.
if (!isset($dbUser)) {
    require_once __DIR__ . '/get-user.php';
    $dbUser = getUser($pdo);
}

// Toggle check: per-user (logged in)
if ($dbUser && isset($dbUser['claudia_enabled']) && $dbUser['claudia_enabled'] == 0) {
    return; // User opted out — emit nothing
}

// Build user data for JS (safe fields only)
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

$claudiaConfigJson = json_encode([
    'context' => $claudiaConfig['context'],
    'capabilities' => $claudiaConfig['capabilities'],
    'events' => $claudiaConfig['events'] ?? false,
    'user' => $claudiaUser,
    'siteEnabled' => true,
], JSON_UNESCAPED_UNICODE);
?>
<script>window.ClaudiaConfig = <?= $claudiaConfigJson ?>;</script>
<link rel="stylesheet" href="/assets/claudia/claudia.css">

<!-- Claudia Widget HTML -->
<div id="claudia-widget">
    <!-- Mode picker overlay -->
    <div id="claudia-mode-overlay">
        <div class="claudia-mode-picker">
            <h3>Hi, I'm Claudia</h3>
            <p class="claudia-intro">You can call me C. I'll guide you through getting set up. How would you like to chat?</p>
            <button class="claudia-mode-btn" data-mode="voice">
                <span class="claudia-mode-icon">🎤</span> Voice
                <span class="claudia-mode-desc">I'll speak, you speak</span>
            </button>
            <button class="claudia-mode-btn" data-mode="text">
                <span class="claudia-mode-icon">⌨️</span> Text
                <span class="claudia-mode-desc">Read and type</span>
            </button>
            <button class="claudia-mode-btn" data-mode="both">
                <span class="claudia-mode-icon">🔀</span> Both
                <span class="claudia-mode-desc">I'll speak + show text, you type or speak</span>
            </button>
            <button class="claudia-mode-dismiss" id="claudia-mode-dismiss">Maybe later</button>
        </div>
    </div>

    <!-- Collapsed bubble -->
    <div id="claudia-bubble"><span class="claudia-bubble-icon">C</span> Ask C</div>

    <!-- Expanded panel -->
    <div id="claudia-panel">
        <div class="claudia-header">
            <span class="claudia-header-title">C — Your Civic Guide</span>
            <button class="claudia-header-btn" id="claudia-popout-btn" title="Pop out">⤴</button>
            <button class="claudia-header-btn" id="claudia-settings-btn" title="Settings">⚙</button>
            <button class="claudia-header-btn" id="claudia-minimize-btn" title="Close">✕</button>
            <div class="claudia-settings-menu" id="claudia-settings-menu">
                <div class="claudia-settings-item" data-action="change-mode">Change interaction mode</div>
                <div class="claudia-settings-item" data-action="clear-chat">Clear conversation</div>
                <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-websearch">
                    <span>Web Search</span>
                    <span class="claudia-toggle" id="claudia-websearch-toggle">
                        <span class="claudia-toggle-knob"></span>
                        <span class="claudia-toggle-label" id="claudia-ws-label">OFF</span>
                    </span>
                </div>
                <div class="claudia-settings-item" data-action="disable-claudia">Disable Claudia</div>
            </div>
        </div>
        <div class="claudia-input-area">
            <button class="claudia-mic-btn" id="claudia-mic-btn" title="Voice input">🎤</button>
            <input type="text" class="claudia-text-input" id="claudia-text-input" placeholder="Type your message..." autocomplete="off">
            <button class="claudia-send-btn" id="claudia-send-btn">Send</button>
            <button class="claudia-clear-btn" id="claudia-clear-btn" title="Clear conversation">Clear</button>
        </div>
        <div class="claudia-typing" id="claudia-typing">
            Claudia is thinking<span>.</span><span>.</span><span>.</span>
        </div>
        <div class="claudia-messages" id="claudia-messages"></div>
    </div>
</div>

<script src="/assets/claudia/claudia-core.js"></script>
<?php
// Load capability module scripts
foreach ($claudiaConfig['capabilities'] as $cap) {
    $jsFile = "/assets/claudia/claudia-{$cap}.js";
    $localPath = __DIR__ . "/../assets/claudia/claudia-{$cap}.js";
    if (file_exists($localPath)) {
        echo "<script src=\"{$jsFile}\"></script>\n";
    }
}
?>
<script src="/assets/claudia/claudia-popout.js"></script>
