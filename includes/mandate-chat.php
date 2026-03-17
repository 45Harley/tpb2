<?php
/**
 * Mandate Chat — Discuss & Create Draft
 *
 * Lightweight CRUD bubble workspace for mandate drafting.
 * User types ideas, optionally includes AI, checks a scope, and saves.
 *
 * Usage:
 *   $mandateChatConfig = ['placeholder' => '...', 'default_scope' => 'town'];
 *   require __DIR__ . '/mandate-chat.php';
 *
 * Expects: $dbUser, $pdo, $isLoggedIn to be set by calling page.
 */

// ── Merge config with defaults ──────────────────────────────────────────
$_mcDefaults = [
    'placeholder'    => "What matters most to you?",
    'prefix'         => 'mc',
    'group_id'       => null,
    'default_scope'  => null,  // 'federal', 'state', 'town', or null
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

<!-- Prompt — always visible -->
<div class="mandate-chat" id="<?= $_mcPrefix ?>-wrapper">
    <div class="mc-section-header">
        <h3 title="Type or dictate your idea, then Add it as a draft or Include AI to refine it">Prompt</h3>
    </div>
    <div class="mc-input">
        <textarea id="<?= $_mcPrefix ?>-input"
                  placeholder="<?= htmlspecialchars($_mc['placeholder']) ?>"
                  rows="2"
                  maxlength="2000"
                  title="Type your idea here — press Enter to Include AI, or click Add to draft directly"></textarea>
        <button class="mc-add" id="<?= $_mcPrefix ?>-add" title="Add your text directly as a draft — no AI involved">Add</button>
        <button class="mc-mic" id="<?= $_mcPrefix ?>-mic" title="Tap to dictate — say 'command' to switch to voice commands">&#x1F3A4;</button>
        <button class="mc-ask-ai" id="<?= $_mcPrefix ?>-ask-ai" title="Send your text to AI for refinement — both your text and the AI response appear as drafts">Include AI</button>
    </div>
    <div class="mc-char" id="<?= $_mcPrefix ?>-char" title="Character count">0 / 2,000</div>

    <!-- Discuss & Draft Box -->
    <div class="mc-drafts-header">
        <h3 title="Your drafts appear here — newest on top. Check a scope and save when ready.">Discuss &amp; Create Draft</h3>
        <button class="mc-clear-all" id="<?= $_mcPrefix ?>-clear-all" title="Clear all drafts from this workspace">Clear All</button>
    </div>
    <div class="mc-drafts" id="<?= $_mcPrefix ?>-drafts"></div>

    <!-- Toast -->
    <div class="mc-toast" id="<?= $_mcPrefix ?>-toast"></div>
</div>

<script>
new MandateChat({
    prefix:       '<?= $_mcPrefix ?>',
    apiChat:      '/api/claude-chat.php',
    apiSave:      '/talk/api.php',
    userId:       <?= $_mcUserId ?>,
    district:     <?= json_encode($_mcDistrict) ?>,
    stateName:    <?= json_encode($_mcStateName) ?>,
    townName:     <?= json_encode($_mcTownName) ?>,
    stateId:      <?= $_mcStateId ?>,
    townId:       <?= $_mcTownId ?>,
    groupId:      <?= json_encode($_mc['group_id']) ?>,
    placeholder:  <?= json_encode($_mc['placeholder']) ?>,
    defaultScope: <?= json_encode($_mc['default_scope']) ?>
}).init();
</script>
