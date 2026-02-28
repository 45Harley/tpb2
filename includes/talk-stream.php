<?php
/**
 * Talk Stream — Config-Driven Reusable Include
 * =============================================
 * Embeds a full-featured Talk stream on any page.
 *
 * Required from calling page:
 *   $pdo        — PDO connection to sandge5_tpb2
 *   $dbUser     — from getUser($pdo) or false
 *   $isLoggedIn — (bool)$dbUser
 *
 * Config: set $talkStreamConfig array before requiring this file.
 * All keys optional with sane defaults. See below.
 */

// ── Merge config with defaults ──────────────────────────────────────────
$_tsDefaults = [
    'group'               => null,       // group name to look up (null = geo/personal)
    'scope'               => 'federal',  // scope filter for group lookup
    'title'               => null,       // null = no title shown
    'subtitle'            => null,
    'placeholder'         => "What's on your mind?",
    'show_group_selector' => false,
    'show_filters'        => false,
    'show_categories'     => false,
    'show_ai_toggle'      => true,
    'show_mic'            => true,
    'show_admin_tools'    => 'auto',     // true/false/'auto'
    'geo_state_id'        => null,
    'geo_town_id'         => null,
    'limit'               => 30,
];
$_ts = array_merge($_tsDefaults, $talkStreamConfig ?? []);

// ── Group lookup ────────────────────────────────────────────────────────
$_tsGroupId = null;
if ($_ts['group'] && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT id FROM idea_groups WHERE name = ? AND scope = ? LIMIT 1");
    $stmt->execute([$_ts['group'], $_ts['scope']]);
    $row = $stmt->fetch();
    $_tsGroupId = $row ? (int)$row['id'] : null;
    if (!$_tsGroupId) return; // group not found, silently skip
}

// ── Membership check ────────────────────────────────────────────────────
$_tsMember = false;
$_tsUserId = $dbUser ? (int)$dbUser['user_id'] : null;
if ($isLoggedIn && $_tsGroupId) {
    $stmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$_tsGroupId, $_tsUserId]);
    $_tsMember = (bool)$stmt->fetch();
}

// ── User context ────────────────────────────────────────────────────────
$_tsUserJson = $dbUser ? json_encode([
    'user_id'            => (int)$dbUser['user_id'],
    'display_name'       => getDisplayName($dbUser),
    'identity_level_id'  => (int)($dbUser['identity_level_id'] ?? 1)
]) : 'null';

$_tsUserLevel = $dbUser ? (int)($dbUser['identity_level_id'] ?? 1) : 0;
$_tsCanPost   = $isLoggedIn && $_tsUserLevel >= 2;

// ── Unique prefix for DOM IDs ───────────────────────────────────────────
if ($_tsGroupId) {
    $_tsPrefix = 'ts' . $_tsGroupId;
} elseif ($_ts['geo_town_id']) {
    $_tsPrefix = 'tsgeo' . $_ts['geo_town_id'];
} elseif ($_ts['geo_state_id']) {
    $_tsPrefix = 'tsgeo' . $_ts['geo_state_id'];
} else {
    $_tsPrefix = 'ts0';
}

// ── Load shared assets once per page ─────────────────────────────────────
// CSS should be loaded in <head> by calling page via $headLinks (set before header.php).
// JS loaded here in body (needs to be before the init script below).
if (!defined('TALK_STREAM_ASSETS_LOADED')) {
    define('TALK_STREAM_ASSETS_LOADED', true);
    $tsAssetVer = filemtime(dirname(__DIR__) . '/assets/talk-stream.css');
    // Fallback: load CSS here if calling page didn't set $headLinks
    if (empty($headLinks)) {
        echo '<link rel="stylesheet" href="/assets/talk-stream.css?v=' . $tsAssetVer . '">' . "\n";
    }
    echo '<script src="/assets/talk-stream.js?v=' . $tsAssetVer . '"></script>' . "\n";
}
?>

<div class="talk-stream" id="<?= $_tsPrefix ?>-wrapper">

<?php if ($_ts['title']): ?>
    <h2 class="stream-title"><?= htmlspecialchars($_ts['title']) ?></h2>
<?php endif; ?>
<?php if ($_ts['subtitle']): ?>
    <p class="stream-subtitle"><?= htmlspecialchars($_ts['subtitle']) ?></p>
<?php endif; ?>

<?php if ($_tsCanPost): ?>
    <div class="input-area">
        <div style="position:absolute;left:-9999px;">
            <input type="text" id="<?= $_tsPrefix ?>-hp" tabindex="-1" autocomplete="off">
        </div>
<?php if ($_ts['show_group_selector']): ?>
        <div class="context-bar">
            <select id="<?= $_tsPrefix ?>-contextSelect">
                <option value="">Personal</option>
            </select>
        </div>
<?php endif; ?>
        <div class="input-row">
<?php if ($_ts['show_mic']): ?>
            <button class="input-btn mic-btn" id="<?= $_tsPrefix ?>-micBtn" title="Dictate">&#x1F3A4;</button>
<?php endif; ?>
            <textarea id="<?= $_tsPrefix ?>-input" placeholder="<?= htmlspecialchars($_ts['placeholder']) ?>" rows="2" maxlength="2000"></textarea>
<?php if ($_ts['show_ai_toggle']): ?>
            <button class="input-btn ai-btn" id="<?= $_tsPrefix ?>-aiBtn" title="AI brainstorm">AI</button>
<?php endif; ?>
            <button class="input-btn send-btn" id="<?= $_tsPrefix ?>-sendBtn" title="Send">&#x27A4;</button>
        </div>
        <div class="char-counter" id="<?= $_tsPrefix ?>-charCounter">0 / 2,000</div>
    </div>
<?php elseif (!$isLoggedIn): ?>
    <div class="anon-nudge">
        <a href="/join.php">Join</a> or <a href="/login.php">log in</a> to share your thoughts.
    </div>
<?php elseif ($_tsUserLevel < 2): ?>
    <div class="anon-nudge">
        <a href="/verify-email.php">Verify your email</a> to participate in the discussion.
    </div>
<?php endif; ?>

<?php if ($_ts['show_filters']): ?>
    <div class="filter-bar" id="<?= $_tsPrefix ?>-filterBar">
        <button class="filter-btn active" data-filter="" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setFilter('')">All</button>
        <button class="filter-btn" data-filter="raw" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setFilter('raw')">Raw</button>
        <button class="filter-btn" data-filter="refining" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setFilter('refining')">Refining</button>
        <button class="filter-btn" data-filter="distilled" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setFilter('distilled')">Distilled</button>
        <button class="filter-btn" data-filter="actionable" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setFilter('actionable')">Actionable</button>
    </div>
<?php endif; ?>

<?php if ($_ts['show_categories']): ?>
    <div class="filter-bar" id="<?= $_tsPrefix ?>-catBar">
        <button class="cat-btn active" data-cat="" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('')">All</button>
        <button class="cat-btn" data-cat="idea" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('idea')">Ideas</button>
        <button class="cat-btn" data-cat="decision" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('decision')">Decisions</button>
        <button class="cat-btn" data-cat="todo" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('todo')">Todos</button>
        <button class="cat-btn" data-cat="note" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('note')">Notes</button>
        <button class="cat-btn" data-cat="question" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].setCategoryFilter('question')">Questions</button>
    </div>
<?php endif; ?>

    <div class="stream" id="<?= $_tsPrefix ?>-stream">
        <div class="stream-empty" id="<?= $_tsPrefix ?>-streamEmpty">Loading...</div>
    </div>

    <div class="footer-bar" id="<?= $_tsPrefix ?>-footerBar">
        <button class="gather-btn" id="<?= $_tsPrefix ?>-gatherBtn" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].runGather()">Gather</button>
        <button class="crystallize-btn" id="<?= $_tsPrefix ?>-crystallizeBtn" onclick="TalkStream._instances['<?= $_tsPrefix ?>'].runCrystallize()">Crystallize</button>
    </div>

    <div class="toast hidden" id="<?= $_tsPrefix ?>-toast"></div>
</div>

<script>
new TalkStream({
    prefix:            '<?= $_tsPrefix ?>',
    apiBase:           '/talk/api.php',
    groupId:           <?= $_tsGroupId ? $_tsGroupId : 'null' ?>,
    geoStateId:        <?= $_ts['geo_state_id'] ? (int)$_ts['geo_state_id'] : 'null' ?>,
    geoTownId:         <?= $_ts['geo_town_id'] ? (int)$_ts['geo_town_id'] : 'null' ?>,
    currentUser:       <?= $_tsUserJson ?>,
    canPost:           <?= $_tsCanPost ? 'true' : 'false' ?>,
    isLoggedIn:        <?= $isLoggedIn ? 'true' : 'false' ?>,
    isMember:          <?= $_tsMember ? 'true' : 'false' ?>,
    showFilters:       <?= $_ts['show_filters'] ? 'true' : 'false' ?>,
    showCategories:    <?= $_ts['show_categories'] ? 'true' : 'false' ?>,
    showGroupSelector: <?= $_ts['show_group_selector'] ? 'true' : 'false' ?>,
    showAiToggle:      <?= $_ts['show_ai_toggle'] ? 'true' : 'false' ?>,
    showMic:           <?= $_ts['show_mic'] ? 'true' : 'false' ?>,
    showAdminTools:    <?= is_bool($_ts['show_admin_tools']) ? ($_ts['show_admin_tools'] ? 'true' : 'false') : "'auto'" ?>,
    limit:             <?= (int)$_ts['limit'] ?>,
    placeholder:       <?= json_encode($_ts['placeholder']) ?>
}).init();
</script>
