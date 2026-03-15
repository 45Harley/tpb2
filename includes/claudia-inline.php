<?php
/**
 * Claudia Inline Mandate Form
 * ============================
 * Static mandate input embedded on Talk/Fight pages.
 * Not the floating widget — this sits IN the page.
 *
 * Required from calling page:
 *   $pdo        — PDO connection
 *   $dbUser     — from getUser($pdo) or false
 *   $isLoggedIn — (bool)$dbUser
 *
 * Config: set $claudiaInlineConfig before requiring this file.
 *   'scope'       => 'federal'|'state'|'town' (default: 'federal')
 *   'scope_label' => 'USA'|'Connecticut'|'Putnam' etc.
 *   'title'       => heading text (default: 'Your Mandate')
 *   'placeholder' => textarea placeholder
 */

require_once __DIR__ . '/../config/mandate-topics.php';

$_ciDefaults = [
    'scope'       => 'federal',
    'scope_label' => 'USA',
    'title'       => 'Your Mandate',
    'placeholder' => 'What matters most to you? Pick a topic and share your mandate.',
];
$_ci = array_merge($_ciDefaults, $claudiaInlineConfig ?? []);

$_ciUserLevel = $dbUser ? (int)($dbUser['identity_level_id'] ?? 1) : 0;
$_ciCanPost = $isLoggedIn && $_ciUserLevel >= 2;

// Category for idea_log based on scope
$_ciCategoryMap = ['federal' => 'mandate-federal', 'state' => 'mandate-state', 'town' => 'mandate-town'];
$_ciCategory = $_ciCategoryMap[$_ci['scope']] ?? 'mandate-federal';

// Load CSS once per page
if (!defined('CLAUDIA_INLINE_LOADED')) {
    define('CLAUDIA_INLINE_LOADED', true);
    $_ciCssVer = file_exists(__DIR__ . '/../assets/claudia/claudia-inline.css')
        ? filemtime(__DIR__ . '/../assets/claudia/claudia-inline.css') : 0;
    echo '<link rel="stylesheet" href="/assets/claudia/claudia-inline.css?v=' . $_ciCssVer . '">' . "\n";
}
?>

<div class="claudia-inline" id="claudia-inline-form">
    <div class="claudia-inline-header">
        <h2 class="claudia-inline-title"><?= htmlspecialchars($_ci['title']) ?></h2>
        <span class="claudia-inline-scope"><?= htmlspecialchars($_ci['scope_label']) ?></span>
    </div>

<?php if ($_ciCanPost): ?>
    <div class="claudia-inline-topics" id="claudia-inline-topics">
    <?php foreach (MANDATE_POLICY_TOPICS as $topic): ?>
        <button class="claudia-topic-pill" data-topic="<?= htmlspecialchars($topic) ?>">
            <?= htmlspecialchars($topic) ?>
        </button>
    <?php endforeach; ?>
    </div>

    <div class="claudia-inline-input-row">
        <textarea class="claudia-inline-textarea" id="claudia-inline-input"
                  placeholder="<?= htmlspecialchars($_ci['placeholder']) ?>"
                  rows="2" maxlength="2000"></textarea>
        <button class="claudia-inline-send" id="claudia-inline-send" disabled>Submit</button>
    </div>
    <div class="claudia-inline-status" id="claudia-inline-status"></div>

<?php elseif (!$isLoggedIn): ?>
    <div class="claudia-inline-auth">
        <a href="/join.php">Join</a> or <a href="/login.php">log in</a> to submit your mandate.
    </div>
<?php elseif ($_ciUserLevel < 2): ?>
    <div class="claudia-inline-auth">
        <a href="/profile.php#email">Verify your email</a> to submit your mandate.
    </div>
<?php endif; ?>
</div>

<script>
window._claudiaInlineConfig = {
    category: <?= json_encode($_ciCategory) ?>,
    scope: <?= json_encode($_ci['scope']) ?>,
    userId: <?= $dbUser ? (int)$dbUser['user_id'] : 'null' ?>
};
</script>
<?php
$_ciJsVer = file_exists(__DIR__ . '/../assets/claudia/claudia-inline.js')
    ? filemtime(__DIR__ . '/../assets/claudia/claudia-inline.js') : 0;
?>
<script src="/assets/claudia/claudia-inline.js?v=<?= $_ciJsVer ?>"></script>
