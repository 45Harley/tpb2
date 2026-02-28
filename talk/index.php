<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $dbUser = getUser($pdo);
} catch (PDOException $e) { $dbUser = false; }

$isLoggedIn = (bool)$dbUser;
$currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

// Geo context from URL params
$geoStateId = isset($_GET['state']) ? (int)$_GET['state'] : null;
$geoTownId  = isset($_GET['town'])  ? (int)$_GET['town']  : null;
$geoStateName = null;
$geoTownName = null;
$geoLabel = 'USA';

if ($geoTownId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT t.town_name, s.abbreviation, s.state_id FROM towns t JOIN states s ON t.state_id = s.state_id WHERE t.town_id = ?");
    $stmt->execute([$geoTownId]);
    $geo = $stmt->fetch();
    if ($geo) {
        $geoTownName = $geo['town_name'];
        $geoStateName = $geo['abbreviation'];
        $geoStateId = (int)$geo['state_id'];
        $geoLabel = $geoTownName . ', ' . $geoStateName;
    }
} elseif ($geoStateId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT state_name, abbreviation FROM states WHERE state_id = ?");
    $stmt->execute([$geoStateId]);
    $geo = $stmt->fetch();
    if ($geo) {
        $geoStateName = $geo['state_name'];
        $geoLabel = $geoStateName;
    }
}

// Access status for banners
$userLevel = $dbUser ? (int)($dbUser['identity_level_id'] ?? 1) : 0;
$hasLocation = $dbUser && !empty($dbUser['current_state_id']);
$canPost = $userLevel >= 2 && $hasLocation;

// Nav setup
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'talk';
$pageTitle = ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk | The People\'s Branch';
$geoQuery = $geoTownId ? '?town=' . $geoTownId : ($geoStateId ? '?state=' . $geoStateId : '');
$secondaryNavBrand = ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk';
$secondaryNav = [
    ['label' => 'Stream',  'url' => '/talk/' . $geoQuery],
    ['label' => 'Groups',  'url' => '/talk/groups.php' . $geoQuery],
    ['label' => 'Help',    'url' => '/talk/help.php'],
];

// Pre-load talk-stream CSS in <head> via header.php
$_tsCssVer = filemtime(__DIR__ . '/../assets/talk-stream.css');
$headLinks = '    <link rel="stylesheet" href="/assets/talk-stream.css?v=' . $_tsCssVer . '">' . "\n";

$pageStyles = <<<'CSS'
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            background-attachment: fixed;
        }

        /* ── Page chrome ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .page-header h1 { font-size: 1.2rem; color: #ffffff; }
        .header-links { display: flex; gap: 12px; font-size: 0.85rem; }
        .header-links a { color: #90caf9; text-decoration: none; }
        .header-links a:hover { text-decoration: underline; color: #bbdefb; }

        .user-status { font-size: 0.75rem; color: #81c784; text-align: right; padding: 4px 16px 0; }
        .user-status .dot { display: inline-block; width: 7px; height: 7px; background: #4caf50; border-radius: 50%; margin-right: 3px; }

        /* ── Access Banner ── */
        .access-banner {
            display: block;
            padding: 12px 16px;
            text-align: center;
            font-size: 0.9rem;
            color: #fff;
            text-decoration: none;
            transition: background 0.2s;
        }
        .access-banner:hover { filter: brightness(1.15); }
        .access-banner.verify-email {
            background: linear-gradient(135deg, rgba(33,150,243,0.25), rgba(33,150,243,0.15));
            border-bottom: 1px solid rgba(33,150,243,0.3);
        }
        .access-banner.set-location {
            background: linear-gradient(135deg, rgba(76,175,80,0.25), rgba(76,175,80,0.15));
            border-bottom: 1px solid rgba(76,175,80,0.3);
        }
        .access-banner .banner-sub {
            display: block;
            font-size: 0.75rem;
            color: #bbb;
            margin-top: 2px;
        }

        /* ── Geo Stream Header ── */
        .geo-header {
            text-align: center;
            padding: 10px 16px 4px;
            font-size: 1rem;
            color: #90caf9;
            font-weight: 600;
        }
        .geo-header .geo-breadcrumb {
            font-size: 0.75rem;
            color: #888;
            font-weight: 400;
        }
        .geo-header .geo-breadcrumb a {
            color: #666;
            text-decoration: none;
        }
        .geo-header .geo-breadcrumb a:hover { color: #90caf9; text-decoration: underline; }

        /* ── Responsive ── */
        @media (min-width: 700px) {
            .page-header, .geo-header, .access-banner { max-width: 700px; margin-left: auto; margin-right: auto; width: 100%; }
        }
CSS;

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/nav.php';
?>

    <div class="user-status">
<?php if ($dbUser): ?>
        <span class="dot"></span><?= htmlspecialchars(getDisplayName($dbUser)) ?> · <span id="browserName"></span>
<?php else: ?>
        <span id="browserName"></span>
<?php endif; ?>
    </div>

<?php // Access banner (persistent, clickable)
if ($userLevel < 2): ?>
    <a href="/join.php" class="access-banner verify-email">
        Verify your email to join the conversation.
        <span class="banner-sub">Your voice matters.</span>
    </a>
<?php elseif (!$hasLocation): ?>
    <a href="/profile.php#town" class="access-banner set-location">
        Set your town to join your local community.
        <span class="banner-sub">Civic life starts where you live.</span>
    </a>
<?php endif; ?>

<?php // Geo stream header
if ($geoTownId || $geoStateId): ?>
    <div class="geo-header">
        <?= htmlspecialchars($geoLabel) ?>
        <div class="geo-breadcrumb">
<?php if ($geoTownId): ?>
            <a href="/talk/">USA</a> &rsaquo; <a href="/talk/?state=<?= $geoStateId ?>"><?= htmlspecialchars($geoStateName) ?></a> &rsaquo; <?= htmlspecialchars($geoTownName) ?>
<?php elseif ($geoStateId): ?>
            <a href="/talk/">USA</a> &rsaquo; <?= htmlspecialchars($geoStateName) ?>
<?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php
// ── Talk Stream Include ──────────────────────────────────────────────────
$talkStreamConfig = [
    'show_group_selector' => !($geoStateId || $geoTownId),
    'show_filters'        => true,
    'show_categories'     => true,
    'show_ai_toggle'      => true,
    'show_mic'            => true,
    'show_admin_tools'    => 'auto',
    'geo_state_id'        => $geoStateId,
    'geo_town_id'         => $geoTownId,
    'limit'               => 50,
];
require __DIR__ . '/../includes/talk-stream.php';
?>

    <script>
    // Browser detection for status bar
    (function() {
        var ua = navigator.userAgent, name = 'Unknown';
        if (ua.indexOf('Edg/') > -1) name = 'Edge';
        else if (ua.indexOf('OPR/') > -1 || ua.indexOf('Opera') > -1) name = 'Opera';
        else if (ua.indexOf('Chrome/') > -1) name = 'Chrome';
        else if (ua.indexOf('Firefox/') > -1) name = 'Firefox';
        else if (ua.indexOf('Safari/') > -1) name = 'Safari';
        var el = document.getElementById('browserName');
        if (el) el.textContent = name;
    })();

    // URL ?group=NNN param → pre-select group in stream
    (function() {
        var urlGroup = new URLSearchParams(window.location.search).get('group');
        if (urlGroup) {
            localStorage.setItem('tpb_talk_context', urlGroup);
            // Find the TalkStream instance and switch to this group
            var prefix = '<?= $_tsPrefix ?? 'ts0' ?>';
            var ts = TalkStream._instances[prefix];
            if (ts) ts.setGroup(parseInt(urlGroup));
        }
        // Geo params override group context
        var geoState = <?= $geoStateId ? $geoStateId : 'null' ?>;
        var geoTown = <?= $geoTownId ? $geoTownId : 'null' ?>;
        if (geoState || geoTown) {
            localStorage.removeItem('tpb_talk_context');
        }
    })();
    </script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
