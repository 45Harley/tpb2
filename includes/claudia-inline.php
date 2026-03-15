<?php
/**
 * Claudia Inline Mandate Form
 * ============================
 * Full mandate interface embedded on Talk/Fight pages.
 * Visual layout matches mandate-poc.php logged-in section exactly.
 *
 * Required from calling page:
 *   $pdo        — PDO connection
 *   $dbUser     — from getUser($pdo) or false
 *   $isLoggedIn — (bool)$dbUser
 *
 * Config: set $claudiaInlineConfig before requiring this file.
 *   'scope'       => 'federal'|'state'|'town' (default: 'federal')
 *   'scope_label' => 'USA'|'Connecticut'|'Putnam' etc.
 *   'title'       => heading text (default: 'My Mandate')
 *   'placeholder' => textarea placeholder
 */

require_once __DIR__ . '/../config/mandate-topics.php';

$_ciDefaults = [
    'scope'       => 'federal',
    'scope_label' => 'USA',
    'title'       => 'My Mandate',
    'placeholder' => 'What matters most to you? Pick a topic and share your mandate.',
];
$_ci = array_merge($_ciDefaults, $claudiaInlineConfig ?? []);

$_ciUserLevel = $dbUser ? (int)($dbUser['identity_level_id'] ?? 1) : 0;
$_ciCanPost = $isLoggedIn && $_ciUserLevel >= 2;

// Category for idea_log based on scope
$_ciCategoryMap = ['federal' => 'mandate-federal', 'state' => 'mandate-state', 'town' => 'mandate-town'];
$_ciCategory = $_ciCategoryMap[$_ci['scope']] ?? 'mandate-federal';

// User geo data for mandate summary and delegation popup
$_ciUserStateId  = $dbUser ? ($dbUser['current_state_id'] ?? null) : null;
$_ciUserTownId   = $dbUser ? ($dbUser['current_town_id'] ?? null) : null;
$_ciUserDistrict = $dbUser ? ($dbUser['us_congress_district'] ?? null) : null;
$_ciUserTownName  = null;
$_ciUserStateName = null;
$_ciUserStateAbbr = null;

if ($_ciUserTownId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT t.town_name, s.state_name, s.abbreviation FROM towns t JOIN states s ON t.state_id = s.state_id WHERE t.town_id = ?");
    $stmt->execute([$_ciUserTownId]);
    $geo = $stmt->fetch();
    if ($geo) {
        $_ciUserTownName  = $geo['town_name'];
        $_ciUserStateName = $geo['state_name'];
        $_ciUserStateAbbr = $geo['abbreviation'];
    }
} elseif ($_ciUserStateId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT state_name, abbreviation FROM states WHERE state_id = ?");
    $stmt->execute([$_ciUserStateId]);
    $geo = $stmt->fetch();
    if ($geo) {
        $_ciUserStateName = $geo['state_name'];
        $_ciUserStateAbbr = $geo['abbreviation'];
    }
}

// Load CSS once per page
if (!defined('CLAUDIA_INLINE_LOADED')) {
    define('CLAUDIA_INLINE_LOADED', true);
    $_ciCssVer = file_exists(__DIR__ . '/../assets/claudia/claudia-inline.css')
        ? filemtime(__DIR__ . '/../assets/claudia/claudia-inline.css') : 0;
    echo '<link rel="stylesheet" href="/assets/claudia/claudia-inline.css?v=' . $_ciCssVer . '">' . "\n";
}
?>

<div class="mandate-wrap" id="claudia-inline-form">

    <!-- Mandate Header — matches mandate-poc.php exactly -->
    <div class="mandate-header">
        <h1><?= htmlspecialchars($_ci['title']) ?></h1>
        <a href="/help/guide.php?flow=mandate-chat" class="mandate-help-btn">&#x1F393; How It Works</a>
<?php if ($dbUser && ($_ciUserTownName || $_ciUserStateName || $_ciUserDistrict)): ?>
        <p class="geo-info" id="claudia-inline-geo" title="Click to see your elected representatives">
<?php if ($_ciUserTownName): ?>
            <span><?= htmlspecialchars($_ciUserTownName) ?></span>,
<?php endif; ?>
<?php if ($_ciUserStateAbbr): ?>
            <span><?= htmlspecialchars($_ciUserStateAbbr) ?></span>
<?php endif; ?>
<?php if ($_ciUserDistrict): ?>
            &mdash; District <span><?= htmlspecialchars($_ciUserDistrict) ?></span>
<?php endif; ?>
        </p>
<?php endif; ?>
    </div>

    <!-- Delegation Popup -->
<?php if ($dbUser): ?>
    <div class="delegation-popup" id="claudia-inline-delegation">
        <div class="delegation-popup-header" id="claudia-delegation-drag">
            <h3>Your Representatives</h3>
            <button class="delegation-popup-close" id="claudia-delegation-close">&times;</button>
        </div>
        <div id="claudia-delegation-body">
            <div class="delegation-loading">Loading...</div>
        </div>
    </div>
<?php endif; ?>

<?php if ($_ciCanPost): ?>
    <!-- Topic pills -->
    <div class="mandate-topics" id="claudia-inline-topics">
    <?php foreach (MANDATE_POLICY_TOPICS as $topic): ?>
        <button class="mandate-topic-pill" data-topic="<?= htmlspecialchars($topic) ?>">
            <?= htmlspecialchars($topic) ?>
        </button>
    <?php endforeach; ?>
    </div>

    <!-- Mandate Input -->
    <div class="mandate-input-row">
        <textarea class="mandate-textarea" id="claudia-inline-input"
                  placeholder="<?= htmlspecialchars($_ci['placeholder']) ?>"
                  rows="3" maxlength="2000"></textarea>
        <button class="mandate-submit-btn" id="claudia-inline-send" disabled>Submit Mandate</button>
    </div>
    <div class="mandate-status" id="claudia-inline-status"></div>

<?php elseif (!$isLoggedIn): ?>
    <div class="mandate-auth-nudge">
        <a href="/join.php">Join</a> or <a href="/login.php">log in</a> to submit your mandate.
    </div>
<?php elseif ($_ciUserLevel < 2): ?>
    <div class="mandate-auth-nudge">
        <a href="/profile.php#email">Verify your email</a> to submit your mandate.
    </div>
<?php endif; ?>

    <!-- Public Mandate Summary — matches mandate-poc.php exactly -->
    <div class="mandate-summary" id="claudia-inline-summary">
        <div class="mandate-summary-header">
            <h3 id="claudia-inline-summary-title" title="Click to see your elected representatives">Public Mandate Summary</h3>
        </div>
        <!-- Level Filter Tabs -->
        <div class="level-tabs" id="claudia-inline-level-tabs">
            <button class="level-tab active" data-level="" title="Show all mandates from your area">All</button>
            <button class="level-tab" data-level="mandate-federal" title="U.S. Congress &mdash; House &amp; Senate">Federal</button>
            <button class="level-tab" data-level="mandate-state" title="State legislature &mdash; your state reps">State</button>
            <button class="level-tab" data-level="mandate-town" title="Local town government &mdash; selectmen, council">Town</button>
            <button class="level-tab" data-level="mine" title="Only mandates you have saved">My Mandates</button>
        </div>
        <div style="text-align:right; padding: 4px 12px;">
            <a href="/mandate-summary.php" style="color:#d4af37; font-size:0.8rem; text-decoration:none;" title="View full statistics and topic breakdown">The People's Pulse &rarr;</a>
        </div>
        <div id="claudia-inline-summary-body" style="padding: 1.5rem;">
            <p style="color:#b0b0b0;">Loading mandate data...</p>
        </div>
    </div>

</div>

<script>
window._claudiaInlineConfig = {
    category: <?= json_encode($_ciCategory) ?>,
    scope: <?= json_encode($_ci['scope']) ?>,
    userId: <?= $dbUser ? (int)$dbUser['user_id'] : 'null' ?>,
    userStateId: <?= json_encode($_ciUserStateId) ?>,
    userTownId: <?= json_encode($_ciUserTownId) ?>,
    userDistrict: <?= json_encode($_ciUserDistrict) ?>,
    userTownName: <?= json_encode($_ciUserTownName ?: '') ?>,
    userStateName: <?= json_encode($_ciUserStateName ?: '') ?>,
    userStateAbbr: <?= json_encode($_ciUserStateAbbr ?: '') ?>
};
</script>
<?php
$_ciJsVer = file_exists(__DIR__ . '/../assets/claudia/claudia-inline.js')
    ? filemtime(__DIR__ . '/../assets/claudia/claudia-inline.js') : 0;
?>
<script src="/assets/claudia/claudia-inline.js?v=<?= $_ciJsVer ?>"></script>
