<?php
/**
 * Mandate Chat — Lightweight ephemeral chat component.
 *
 * Usage:
 *   $mandateChatConfig = ['placeholder' => '...'];
 *   require __DIR__ . '/mandate-chat.php';
 *
 * Expects: $dbUser, $pdo, $isLoggedIn to be set.
 */

// ── Merge config with defaults ──────────────────────────────────────────
$_mcDefaults = [
    'placeholder' => "What do you want your reps to do?",
    'prefix'      => 'mc',
];
$_mc = array_merge($_mcDefaults, $mandateChatConfig ?? []);
$_mcPrefix = $_mc['prefix'];

// ── User context for JS ─────────────────────────────────────────────────
$_mcDistrict  = $dbUser ? ($dbUser['us_congress_district'] ?? '') : '';
$_mcStateName = $dbUser ? ($dbUser['state_name'] ?? '') : '';
$_mcTownName  = $dbUser ? ($dbUser['town_name'] ?? '') : '';
$_mcUserId    = $dbUser ? (int)$dbUser['user_id'] : 0;
$_mcStateId   = $dbUser ? (int)($dbUser['current_state_id'] ?? 0) : 0;
$_mcTownId    = $dbUser ? (int)($dbUser['current_town_id'] ?? 0) : 0;

// ── Load assets once ────────────────────────────────────────────────────
if (!defined('MANDATE_CHAT_ASSETS_LOADED')) {
    define('MANDATE_CHAT_ASSETS_LOADED', true);
    $_mcCssVer = file_exists(__DIR__ . '/../assets/mandate-chat.css') ? filemtime(__DIR__ . '/../assets/mandate-chat.css') : 0;
    $_mcJsVer  = file_exists(__DIR__ . '/../assets/mandate-chat.js')  ? filemtime(__DIR__ . '/../assets/mandate-chat.js')  : 0;
    echo '<link rel="stylesheet" href="/assets/mandate-chat.css?v=' . $_mcCssVer . '">' . "\n";
    echo '<script src="/assets/mandate-chat.js?v=' . $_mcJsVer . '"></script>' . "\n";
}
?>

<div class="mandate-chat" id="<?= $_mcPrefix ?>-wrapper">
    <!-- Chat Messages -->
    <div class="mc-messages" id="<?= $_mcPrefix ?>-messages"></div>

    <!-- Input Area -->
    <div class="mc-input">
        <textarea id="<?= $_mcPrefix ?>-input"
                  placeholder="<?= htmlspecialchars($_mc['placeholder']) ?>"
                  rows="2"
                  maxlength="2000"></textarea>
        <button class="mc-mic" id="<?= $_mcPrefix ?>-mic" title="Voice input">&#x1F3A4;</button>
        <button class="mc-send" id="<?= $_mcPrefix ?>-send" title="Send">&#x27A4;</button>
    </div>
    <div class="mc-char" id="<?= $_mcPrefix ?>-char">0 / 2,000</div>

    <!-- Ideas Scratchpad -->
    <div class="mc-scratchpad">
        <div class="mc-scratchpad-header">
            <h3>Pinned Ideas</h3>
            <button class="mc-clear-chat">Clear All</button>
        </div>
        <div class="mc-idea-list" id="<?= $_mcPrefix ?>-idea-list"></div>

        <!-- Save Bar -->
        <div class="mc-save-bar">
            <select id="<?= $_mcPrefix ?>-idea-select"></select>
            <button class="mc-save-federal">Save Federal Mandate</button>
            <button class="mc-save-state">Save State Mandate</button>
            <button class="mc-save-town">Save Town Mandate</button>
            <button class="mc-save-idea">Save as Idea</button>
        </div>
    </div>

    <!-- Toast -->
    <div class="mc-toast" id="<?= $_mcPrefix ?>-toast"></div>
</div>

<script>
new MandateChat({
    prefix:      '<?= $_mcPrefix ?>',
    apiChat:     '/api/claude-chat.php',
    apiSave:     '/talk/api.php',
    userId:      <?= $_mcUserId ?>,
    district:    <?= json_encode($_mcDistrict) ?>,
    stateName:   <?= json_encode($_mcStateName) ?>,
    townName:    <?= json_encode($_mcTownName) ?>,
    stateId:     <?= $_mcStateId ?>,
    townId:      <?= $_mcTownId ?>,
    placeholder: <?= json_encode($_mc['placeholder']) ?>
}).init();
</script>
