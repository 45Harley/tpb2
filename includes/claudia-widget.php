<?php
/**
 * Claudia Unified Widget — Platform-Wide Conversational Assistant
 * ===============================================================
 * One widget, one voice engine, one pipe. Replaces c-widget.php.
 *
 * Include on any page via footer.php.
 * Requires: $pdo in scope (from host page).
 * Optional: $claudiaConfig array set before include.
 *
 * Design: docs/plans/2026-03-11-claudia-unified-design.md
 */

// Auto-derive context from $currentPage + request URI
if (!isset($claudiaConfig)) {
    $autoContext = isset($currentPage) ? $currentPage : 'general';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $basename = basename($path, '.php');

    if ($basename && $basename !== 'index' && $basename !== $autoContext) {
        $autoContext .= '-' . preg_replace('/[^a-z0-9-]/', '', strtolower($basename));
    }

    $claudiaConfig = [
        'context' => $autoContext,
        'mode_default' => 'chat',
        'mode_available' => ['chat'],
        'capabilities' => ['auth'],
        'events' => false,
    ];
}

// Ensure mode fields exist
$claudiaConfig['mode_default'] = $claudiaConfig['mode_default'] ?? 'chat';
$claudiaConfig['mode_available'] = $claudiaConfig['mode_available'] ?? ['chat'];

// Site-wide toggle check
$claudiaWidgetEnabled = '0';
if (isset($pdo) && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute(['claudia_widget_enabled']);
        $claudiaWidgetEnabled = $stmt->fetchColumn() ?: '0';
    } catch (Exception $e) {
        $claudiaWidgetEnabled = '0';
    }
}
if ($claudiaWidgetEnabled !== '1') return;

// Get user
if (!isset($dbUser)) {
    require_once __DIR__ . '/get-user.php';
    $dbUser = getUser($pdo);
}

// Per-user toggle (logged-in users)
if ($dbUser && isset($dbUser['claudia_enabled']) && $dbUser['claudia_enabled'] == 0) return;

// Build user context for JS
$claudiaUser = null;
if ($dbUser) {
    $claudiaUser = [
        'user_id' => (int)$dbUser['user_id'],
        'display_name' => $dbUser['display_name'] ?? $dbUser['first_name'] ?? 'Friend',
        'town_name' => $dbUser['town_name'] ?? null,
        'state_name' => $dbUser['state_name'] ?? null,
        'state_abbrev' => $dbUser['state_abbrev'] ?? null,
        'district' => $dbUser['us_congress_district'] ?? null,
        'civic_points' => (int)($dbUser['civic_points'] ?? 0),
        'identity_level' => (int)($dbUser['identity_level_id'] ?? 1),
    ];
}

// CSS + JS versioning
$_cuCssPath = __DIR__ . '/../assets/claudia/claudia-unified.css';
$_cuJsPath = __DIR__ . '/../assets/claudia/claudia-unified.js';
$cssVer = file_exists($_cuCssPath) ? filemtime($_cuCssPath) : 0;
$jsVer = file_exists($_cuJsPath) ? filemtime($_cuJsPath) : 0;
?>
<link rel="stylesheet" href="/assets/claudia/claudia-unified.css?v=<?= $cssVer ?>">

<!-- Claudia Unified Widget -->
<div id="claudia-widget" class="claudia-widget" style="display:none;">
    <div class="claudia-header" id="claudia-drag-handle">
        <span class="claudia-title">Claudia</span>
        <div class="claudia-header-controls">
            <button class="claudia-mode-btn" id="claudia-mode-btn" title="Chat mode">Chat</button>
            <button class="claudia-hdr-btn" id="claudia-settings-btn" title="Settings">&#9881;</button>
            <button class="claudia-hdr-btn" id="claudia-popout-btn" title="Pop out">&#8599;</button>
            <button class="claudia-hdr-btn" id="claudia-minimize-btn" title="Minimize">&minus;</button>
        </div>
    </div>

    <div class="claudia-settings-menu" id="claudia-settings-menu" style="display:none;">
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-voice">
            <span>Voice Mode</span>
            <span class="claudia-toggle" id="claudia-voice-toggle">
                <span class="claudia-toggle-knob"></span>
            </span>
            <span class="claudia-toggle-label" id="claudia-voice-label">OFF</span>
        </div>
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-audio-mode">
            <span>Earphones</span>
            <span class="claudia-toggle" id="claudia-earphones-toggle">
                <span class="claudia-toggle-knob"></span>
            </span>
            <span class="claudia-toggle-label" id="claudia-earphones-label">OFF</span>
        </div>
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-websearch">
            <span>Web Search</span>
            <span class="claudia-toggle" id="claudia-websearch-toggle">
                <span class="claudia-toggle-knob"></span>
            </span>
            <span class="claudia-toggle-label" id="claudia-ws-label">OFF</span>
        </div>
        <div class="claudia-settings-item claudia-toggle-item" data-action="toggle-scratchpad">
            <span>Scratchpad</span>
            <span class="claudia-toggle" id="claudia-scratchpad-toggle">
                <span class="claudia-toggle-knob"></span>
            </span>
            <span class="claudia-toggle-label" id="claudia-sp-label">OFF</span>
        </div>
        <div class="claudia-settings-item" data-action="clear-chat">
            <span>Clear Conversation</span>
        </div>
    </div>

    <div class="claudia-scratchpad" id="claudia-scratchpad" style="display:none;">
        <div class="claudia-scratchpad-header">
            <span class="claudia-scratchpad-title">Scratchpad</span>
            <div class="claudia-scratchpad-actions">
                <div class="claudia-save-bar" id="claudia-save-bar">
                    <button class="claudia-sp-btn claudia-save-level" data-level="federal" title="Save as federal mandate">Fed</button>
                    <button class="claudia-sp-btn claudia-save-level" data-level="state" title="Save as state mandate">State</button>
                    <button class="claudia-sp-btn claudia-save-level" data-level="town" title="Save as town mandate">Town</button>
                    <button class="claudia-sp-btn claudia-save-level" data-level="idea" title="Save as idea">Idea</button>
                </div>
                <button class="claudia-sp-btn" id="claudia-sp-clear" title="Clear all">Clear</button>
            </div>
        </div>
        <div class="claudia-scratchpad-items" id="claudia-scratchpad-items"></div>
    </div>

    <div class="claudia-body">
        <div class="claudia-voice-bar" id="claudia-voice-bar" style="display:none;">
            <span class="claudia-voice-state idle" id="claudia-voice-state">IDLE</span>
        </div>

        <div class="claudia-input-mode" id="claudia-input-mode">
            <button class="claudia-mode-tab active" data-input-mode="chat" id="claudia-tab-chat">Chat</button>
            <button class="claudia-mode-tab" data-input-mode="post" id="claudia-tab-post">Post Idea</button>
        </div>
        <div class="claudia-input-area">
            <button class="claudia-mic-btn" id="claudia-mic-btn" title="Voice input">&#127908;</button>
            <input type="text" class="claudia-text-input" id="claudia-text-input"
                   placeholder="Ask Claudia..." autocomplete="off">
            <button class="claudia-send-btn" id="claudia-send-btn">Send</button>
        </div>

        <div class="claudia-typing" id="claudia-typing" style="display:none;">Claudia is thinking...</div>

        <div class="claudia-messages" id="claudia-messages"></div>
    </div>

    <div class="claudia-resize-handle" id="claudia-resize-handle"></div>
</div>

<!-- Claudia Bubble (minimized state) -->
<div id="claudia-bubble" class="claudia-bubble">
    <span class="claudia-bubble-icon">C</span>
</div>

<script>
window.claudiaConfig = <?= json_encode($claudiaConfig) ?>;
window.claudiaUser = <?= json_encode($claudiaUser) ?>;
</script>
<script src="/assets/claudia/claudia-unified.js?v=<?= $jsVer ?>"></script>
