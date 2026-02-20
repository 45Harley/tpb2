<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $dbUser = getUser($pdo);
} catch (PDOException $e) { $dbUser = false; }

$currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;
$userJson = $dbUser ? json_encode(['user_id' => (int)$dbUser['user_id'], 'display_name' => getDisplayName($dbUser)]) : 'null';

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

$pageStyles = <<<'CSS'
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            background-attachment: fixed;
        }

        /* ── Header ── */
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

        /* ── Input Area ── */
        .input-area {
            padding: 12px 16px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            margin: 12px 16px;
        }

        .context-bar {
            margin-bottom: 8px;
        }
        .context-bar select {
            width: 100%;
            padding: 8px 12px;
            background: rgba(255,255,255,0.08);
            color: #eee;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .context-bar select option { background: #1a1a2e; color: #eee; }

        .input-row {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }

        .input-row textarea {
            flex: 1;
            min-height: 72px;
            max-height: 200px;
            padding: 10px 12px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            color: #eee;
            font-family: inherit;
            font-size: 0.95rem;
            resize: none;
            line-height: 1.4;
            overflow-y: scroll;
        }
        .input-row textarea:focus { outline: none; border-color: #4fc3f7; }
        .input-row textarea::placeholder { color: #888; }

        .char-counter {
            text-align: center;
            font-size: 0.7rem;
            color: #aaa;
            padding: 2px 0 0;
        }
        .char-counter.warn { color: #ff9800; }
        .char-counter.over { color: #ef5350; }

        .input-btn {
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .mic-btn {
            background: rgba(255,255,255,0.08);
            color: #aaa;
        }
        .mic-btn:hover { background: rgba(255,255,255,0.15); color: #eee; }
        .mic-btn.listening {
            background: rgba(244,67,54,0.3);
            color: #f44336;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(244,67,54,0.4); }
            50% { box-shadow: 0 0 0 8px rgba(244,67,54,0); }
        }

        .ai-btn {
            background: rgba(255,255,255,0.08);
            color: #666;
            font-size: 0.9rem;
        }
        .ai-btn:hover { background: rgba(255,255,255,0.15); }
        .ai-btn.active {
            background: rgba(124,77,255,0.25);
            color: #b388ff;
            border: 2px solid #b388ff;
            box-shadow: 0 0 8px rgba(179,136,255,0.4);
        }

        .send-btn {
            background: linear-gradient(145deg, #4caf50, #388e3c);
            color: white;
            font-weight: 600;
        }
        .send-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(76,175,80,0.3); }
        .send-btn:disabled { background: #333; color: #666; cursor: not-allowed; transform: none; box-shadow: none; }

        .anon-nudge {
            background: rgba(212,175,55,0.1);
            border: 1px solid rgba(212,175,55,0.25);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.8rem;
            color: #ccc;
            text-align: center;
            margin-bottom: 8px;
        }
        .anon-nudge a { color: #d4af37; }

        /* ── Stream ── */
        .stream {
            padding: 12px 16px;
            max-width: 700px;
            margin: 0 auto;
            width: 100%;
        }

        .stream-empty {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
            font-size: 0.9rem;
        }

        .idea-card {
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
            border-left: 3px solid #4fc3f7;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }

        /* Category border colors */
        .idea-card.cat-idea { border-left-color: #4fc3f7; }
        .idea-card.cat-decision { border-left-color: #4caf50; }
        .idea-card.cat-todo { border-left-color: #ff9800; }
        .idea-card.cat-note { border-left-color: #9c27b0; }
        .idea-card.cat-question { border-left-color: #e91e63; }
        .idea-card.cat-reaction { border-left-color: #607d8b; }
        .idea-card.cat-digest { border-left-color: #ffd700; }

        /* AI response cards */
        .idea-card.ai-response {
            background: rgba(124,77,255,0.12);
            border-color: rgba(124,77,255,0.25);
            border-left: 3px solid #7c4dff;
        }

        /* AI thinking spinner */
        .ai-thinking-content {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #e0d0ff;
            font-style: italic;
            font-size: 0.95rem;
        }
        .ai-thinking-dots {
            display: flex;
            gap: 5px;
        }
        .ai-thinking-dots span {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #b388ff;
            box-shadow: 0 0 6px rgba(179,136,255,0.6);
            animation: aiPulse 1.4s infinite;
        }
        .ai-thinking-dots span:nth-child(2) { animation-delay: 0.2s; }
        .ai-thinking-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes aiPulse {
            0%, 60%, 100% { opacity: 0.35; transform: scale(0.85); }
            30% { opacity: 1; transform: scale(1.1); box-shadow: 0 0 10px rgba(179,136,255,0.9); }
        }

        /* Digest/crystallization cards */
        .idea-card.digest-card {
            background: rgba(255,215,0,0.10);
            border-color: rgba(255,215,0,0.20);
            border-left: 4px solid #ffd700;
        }
        .idea-card.crystal-card {
            background: rgba(156,39,176,0.10);
            border-color: rgba(156,39,176,0.20);
            border-left: 4px solid #ce93d8;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }
        .card-author { color: #80d8ff; font-weight: 600; }
        .card-id { color: #aaa; font-weight: 400; margin-right: 6px; font-size: 0.8rem; cursor: pointer; }
        .card-id:hover { color: #eee; text-decoration: underline; }
        .card-time { color: #999; }

        .card-content {
            font-size: 0.9rem;
            line-height: 1.5;
            word-break: break-word;
            color: #ddd;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            font-size: 0.75rem;
            flex-wrap: wrap;
            gap: 6px;
        }

        .card-tags { display: flex; gap: 4px; flex-wrap: wrap; }
        .card-tag {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 8px;
            font-size: 0.7rem;
            background: rgba(79,195,247,0.12);
            color: #4fc3f7;
        }
        .card-tag.cat-tag {
            background: rgba(255,255,255,0.12);
            color: #ccc;
            text-transform: capitalize;
            font-weight: 500;
        }

        .card-actions { display: flex; gap: 6px; align-items: center; }
        .card-actions button {
            background: none;
            border: none;
            color: #aab;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.15s;
        }
        .card-actions button:hover { color: #eee; background: rgba(255,255,255,0.08); }
        .card-actions .delete-btn:hover { color: #ef5350; }

        .status-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.raw { background: rgba(158,158,158,0.2); color: #999; }
        .status-badge.refining { background: rgba(255,152,0,0.2); color: #ffb74d; }
        .status-badge.distilled { background: rgba(156,39,176,0.2); color: #ce93d8; }
        .status-badge.actionable { background: rgba(76,175,80,0.2); color: #81c784; }

        .clerk-badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(124,77,255,0.2);
            color: #b388ff;
        }

        .edited-tag { color: #ff9800; font-size: 0.7rem; }

        /* Inline edit */
        .inline-edit textarea {
            width: 100%;
            min-height: 60px;
            padding: 8px;
            border: 1px solid rgba(79,195,247,0.3);
            border-radius: 6px;
            background: rgba(255,255,255,0.06);
            color: #eee;
            font-family: inherit;
            font-size: 0.9rem;
            resize: vertical;
            margin-top: 4px;
        }
        .inline-edit .edit-actions {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        .inline-edit .edit-actions button {
            padding: 4px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .edit-save { background: #4caf50; color: white; }
        .edit-cancel { background: rgba(255,255,255,0.1); color: #aaa; }

        .load-more {
            text-align: center;
            padding: 12px;
        }
        .load-more button {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: #aaa;
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .load-more button:hover { background: rgba(255,255,255,0.12); color: #eee; }

        /* ── Status Filter ── */
        .filter-bar {
            display: flex;
            gap: 6px;
            padding: 6px 16px;
            overflow-x: auto;
        }
        .filter-btn {
            padding: 4px 12px;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px;
            background: none;
            color: #888;
            font-size: 0.78rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .filter-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
        .filter-btn.active { border-color: #4fc3f7; color: #4fc3f7; background: rgba(79,195,247,0.1); }

        .cat-btn {
            padding: 4px 12px;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px;
            background: none;
            color: #888;
            font-size: 0.78rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .cat-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
        .cat-btn.active[data-cat=""] { border-color: #4fc3f7; color: #4fc3f7; background: rgba(79,195,247,0.1); }
        .cat-btn.active[data-cat="idea"] { border-color: #4fc3f7; color: #4fc3f7; background: rgba(79,195,247,0.1); }
        .cat-btn.active[data-cat="decision"] { border-color: #4caf50; color: #4caf50; background: rgba(76,175,80,0.1); }
        .cat-btn.active[data-cat="todo"] { border-color: #ff9800; color: #ff9800; background: rgba(255,152,0,0.1); }
        .cat-btn.active[data-cat="note"] { border-color: #9c27b0; color: #9c27b0; background: rgba(156,39,176,0.1); }
        .cat-btn.active[data-cat="question"] { border-color: #e91e63; color: #e91e63; background: rgba(233,30,99,0.1); }

        .vote-btn {
            background: none;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 2px 8px;
            cursor: pointer;
            font-size: 0.8rem;
            color: #aaa;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .vote-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
        .vote-btn.active-agree { border-color: #4caf50; color: #4caf50; background: rgba(76,175,80,0.1); }
        .vote-btn.active-disagree { border-color: #ef5350; color: #ef5350; background: rgba(239,83,80,0.1); }
        .vote-btn .count { font-size: 0.75rem; color: #bbb; }

        /* ── Footer Bar ── */
        .footer-bar {
            display: none;
            justify-content: center;
            gap: 12px;
            padding: 10px 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            background: rgba(26,26,46,0.95);
            position: sticky;
            bottom: 0;
        }
        .footer-bar.visible { display: flex; }
        .footer-bar button {
            padding: 8px 24px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .footer-bar button:disabled { opacity: 0.4; cursor: not-allowed; }
        .footer-bar .gather-btn {
            border: 1px solid rgba(79,195,247,0.4);
            background: rgba(79,195,247,0.12);
            color: #80d8ff;
        }
        .footer-bar .gather-btn:hover { background: rgba(79,195,247,0.22); color: #b3e5fc; border-color: rgba(79,195,247,0.6); }
        .footer-bar .crystallize-btn {
            border: 1px solid rgba(206,147,216,0.4);
            background: rgba(156,39,176,0.15);
            color: #e1bee7;
        }
        .footer-bar .crystallize-btn:hover { background: rgba(156,39,176,0.25); color: #f3e5f5; border-color: rgba(206,147,216,0.6); }

        /* ── Status toast ── */
        .toast {
            position: fixed;
            bottom: 60px;
            left: 50%;
            transform: translateX(-50%);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.85rem;
            z-index: 100;
            transition: opacity 0.3s;
        }
        .toast.success { background: rgba(76,175,80,0.9); color: white; }
        .toast.error { background: rgba(244,67,54,0.9); color: white; }
        .toast.info { background: rgba(33,150,243,0.9); color: white; }
        .toast.hidden { opacity: 0; pointer-events: none; }

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
            .input-area, .stream, .page-header, .geo-header, .access-banner { max-width: 700px; margin-left: auto; margin-right: auto; width: 100%; }
            .footer-bar { max-width: 700px; margin-left: auto; margin-right: auto; width: 100%; }
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

<?php if ($canPost): ?>
    <div class="input-area">
        <div style="position:absolute;left:-9999px;"><input type="text" id="talkHoneypot" tabindex="-1" autocomplete="off"></div>

        <div class="context-bar">
            <select id="contextSelect" title="Where to save your ideas">
                <option value="" style="background:#1a1a2e;">Personal</option>
            </select>
        </div>

        <div class="input-row">
            <button class="input-btn mic-btn" id="micBtn" title="Voice input">&#x1f3a4;</button>
            <textarea id="ideaInput" placeholder="What's on your mind?" rows="3" maxlength="2000"></textarea>
            <button class="input-btn ai-btn" id="aiBtn" title="Toggle AI response">&#x1f916;</button>
            <button class="input-btn send-btn" id="sendBtn" title="Send">&#x27a4;</button>
        </div>
        <div class="char-counter" id="charCounter">0 / 2,000</div>
    </div>
<?php endif; ?>

    <div class="filter-bar">
        <button class="filter-btn active" onclick="setFilter('')" data-filter="">All</button>
        <button class="filter-btn" onclick="setFilter('raw')" data-filter="raw">Raw</button>
        <button class="filter-btn" onclick="setFilter('refining')" data-filter="refining">Refining</button>
        <button class="filter-btn" onclick="setFilter('distilled')" data-filter="distilled">Distilled</button>
        <button class="filter-btn" onclick="setFilter('actionable')" data-filter="actionable">Actionable</button>
    </div>
    <div class="filter-bar" style="padding-top:0;">
        <button class="cat-btn active" onclick="setCategoryFilter('')" data-cat="">All</button>
        <button class="cat-btn" onclick="setCategoryFilter('idea')" data-cat="idea" style="border-color:rgba(79,195,247,0.3);">Idea</button>
        <button class="cat-btn" onclick="setCategoryFilter('decision')" data-cat="decision" style="border-color:rgba(76,175,80,0.3);">Decision</button>
        <button class="cat-btn" onclick="setCategoryFilter('todo')" data-cat="todo" style="border-color:rgba(255,152,0,0.3);">Todo</button>
        <button class="cat-btn" onclick="setCategoryFilter('note')" data-cat="note" style="border-color:rgba(156,39,176,0.3);">Note</button>
        <button class="cat-btn" onclick="setCategoryFilter('question')" data-cat="question" style="border-color:rgba(233,30,99,0.3);">Question</button>
    </div>

    <div class="stream" id="stream">
        <div class="stream-empty" id="streamEmpty">Loading...</div>
    </div>

    <div class="footer-bar" id="footerBar">
        <button id="gatherBtn" class="gather-btn" onclick="runGather()">Gather</button>
        <button id="crystallizeBtn" class="crystallize-btn" onclick="runCrystallize()">Crystallize</button>
    </div>

    <div class="toast hidden" id="toast"></div>

    <script>
    // ── State ──
    var currentUser = <?= $userJson ?>;
    var canPost = <?= $canPost ? 'true' : 'false' ?>;
    var geoState = <?= $geoStateId ? $geoStateId : 'null' ?>;
    var geoTown = <?= $geoTownId ? $geoTownId : 'null' ?>;
    // Browser detection
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
    var currentContext = localStorage.getItem('tpb_talk_context') || '';
    var aiRespond = false;
    var sessionId = sessionStorage.getItem('tpb_session');
    if (!sessionId) { sessionId = crypto.randomUUID(); sessionStorage.setItem('tpb_session', sessionId); }
    var formLoadTime = Math.floor(Date.now() / 1000);
    var loadedIdeas = [];
    var pollTimer = null;
    var userRole = null;
    var publicAccess = null;
    var isSubmitting = false;
    var currentFilter = '';
    var currentCategoryFilter = '';

    // ── DOM ──
    var contextSelect = document.getElementById('contextSelect');
    var ideaInput = document.getElementById('ideaInput');
    var sendBtn = document.getElementById('sendBtn');
    var aiBtn = document.getElementById('aiBtn');
    var micBtn = document.getElementById('micBtn');
    var stream = document.getElementById('stream');
    var streamEmpty = document.getElementById('streamEmpty');
    var footerBar = document.getElementById('footerBar');
    var toast = document.getElementById('toast');

    // ── URL override ──
    // Geo params override group context
    if (geoState || geoTown) {
        currentContext = '';
        localStorage.removeItem('tpb_talk_context');
    } else {
        var urlGroup = new URLSearchParams(window.location.search).get('group');
        if (urlGroup) {
            currentContext = urlGroup;
            localStorage.setItem('tpb_talk_context', currentContext);
        }
    }

    // ── AI toggle ──
    if (aiBtn) {
        if (aiRespond) aiBtn.classList.add('active');
        aiBtn.addEventListener('click', function() {
            aiRespond = !aiRespond;
            aiBtn.classList.toggle('active', aiRespond);
        });
    }

    // ── Textarea auto-resize + char counter ──
    var charCounter = document.getElementById('charCounter');
    var maxChars = 2000;
    function updateCharCounter() {
        if (!ideaInput || !charCounter) return;
        var len = ideaInput.value.length;
        charCounter.textContent = len.toLocaleString() + ' / ' + maxChars.toLocaleString();
        charCounter.className = 'char-counter' + (len > maxChars * 0.9 ? (len >= maxChars ? ' over' : ' warn') : '');
    }
    if (ideaInput) {
        ideaInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
            updateCharCounter();
        });
    }

    // ── Context selector ──
    if (contextSelect) {
        contextSelect.addEventListener('change', function() {
            currentContext = this.value;
            localStorage.setItem('tpb_talk_context', currentContext);
            switchContext();
        });
    }

    // ── Submit ──
    if (sendBtn) sendBtn.addEventListener('click', submitIdea);
    if (ideaInput) {
        ideaInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                submitIdea();
            }
        });
    }

    async function submitIdea() {
        // Stop mic if listening
        if (micOn && recognition) { micOn = false; recognition.stop(); }

        var content = ideaInput.value.trim();
        if (!content || isSubmitting) return;

        isSubmitting = true;
        sendBtn.disabled = true;
        sendBtn.textContent = '...';

        try {
            var groupId = currentContext ? parseInt(currentContext) : null;
            var resp = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: content,
                    source: 'web',
                    session_id: sessionId,
                    group_id: groupId,
                    auto_classify: true,
                    website_url: document.getElementById('talkHoneypot').value,
                    _form_load_time: formLoadTime
                })
            });
            var data = await resp.json();

            if (data.success && data.idea) {
                ideaInput.value = '';
                ideaInput.style.height = 'auto';
                updateCharCounter();
                prependIdea(data.idea);

                // If AI respond is on, send to brainstorm
                if (aiRespond) {
                    await brainstormRespond(content, groupId);
                }
            } else {
                showToast(data.error || 'Save failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }

        isSubmitting = false;
        sendBtn.disabled = false;
        sendBtn.textContent = '\u27A4';
    }

    async function brainstormRespond(message, groupId) {
        // Show thinking indicator with animated dots
        var thinkingId = 'thinking-' + Date.now();
        var thinkingCard = document.createElement('div');
        thinkingCard.id = thinkingId;
        thinkingCard.className = 'idea-card ai-response';
        thinkingCard.innerHTML = '<div class="card-header"><span class="card-author">AI</span></div>'
            + '<div class="card-content"><div class="ai-thinking-content">'
            + '<div class="ai-thinking-dots"><span></span><span></span><span></span></div>'
            + '<span class="ai-thinking-label">Thinking...</span>'
            + '</div></div>';
        stream.insertBefore(thinkingCard, stream.firstChild);

        // Update label after 6 seconds
        var thinkingTimer = setTimeout(function() {
            var label = thinkingCard.querySelector('.ai-thinking-label');
            if (label) label.textContent = 'Still thinking, one moment...';
        }, 6000);

        try {
            var resp = await fetch('api.php?action=brainstorm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    history: [],
                    session_id: sessionId,
                    shareable: groupId ? 1 : 0,
                    group_id: groupId
                })
            });
            var data = await resp.json();
            clearTimeout(thinkingTimer);

            var el = document.getElementById(thinkingId);
            if (el) {
                if (data.success) {
                    // Replace thinking card with proper rendered card
                    if (data.ai_idea) {
                        var realCard = renderIdeaCard(data.ai_idea);
                        el.parentNode.replaceChild(realCard, el);
                    } else {
                        el.querySelector('.card-content').textContent = data.response;
                        el.querySelector('.card-content').style.color = '';
                        el.querySelector('.card-content').style.fontStyle = '';
                    }
                } else {
                    el.querySelector('.card-content').textContent = data.error || 'AI unavailable';
                    el.querySelector('.card-content').style.color = '#ef5350';
                }
            }
        } catch (err) {
            clearTimeout(thinkingTimer);
            var el = document.getElementById(thinkingId);
            if (el) {
                el.querySelector('.card-content').textContent = 'Network error';
                el.querySelector('.card-content').style.color = '#ef5350';
            }
        }
    }

    // ── Stream rendering ──
    function prependIdea(idea) {
        if (document.getElementById('idea-' + idea.id)) return; // already in DOM
        streamEmpty.style.display = 'none';
        var card = renderIdeaCard(idea);
        stream.insertBefore(card, stream.firstChild);
        loadedIdeas.unshift(idea);
    }

    function renderIdeaCard(idea) {
        var card = document.createElement('div');
        card.className = 'idea-card';
        card.id = 'idea-' + idea.id;
        card.dataset.createdAt = idea.created_at;

        // Card type styling
        if (idea.clerk_key && idea.category === 'chat') {
            card.classList.add('ai-response');
        } else if (idea.category === 'digest' && idea.status === 'distilled') {
            card.classList.add('crystal-card');
        } else if (idea.category === 'digest') {
            card.classList.add('digest-card');
        } else {
            card.classList.add('cat-' + (idea.category || 'idea'));
        }

        var isOwn = currentUser && idea.user_id == currentUser.user_id;
        var authorName = idea.author_display || (isOwn ? 'You' : 'Anonymous');
        var clerkBadge = idea.clerk_key ? ' <span class="clerk-badge">' + escHtml(idea.clerk_key) + '</span>' : '';
        var editedTag = idea.edit_count > 0 ? ' <span class="edited-tag">(edited)</span>' : '';
        var timeStr = formatTime(idea.created_at);

        // Header
        var idBadge = idea.id ? '<span class="card-id" onclick="replyTo(' + idea.id + ')" title="Reply to #' + idea.id + '">#' + idea.id + '</span>' : '';
        var header = '<div class="card-header"><span class="card-author">' + idBadge + escHtml(authorName) + clerkBadge + editedTag + '</span><span class="card-time" title="' + escHtml(idea.created_at) + '">' + timeStr + '</span></div>';

        // Content
        var content = '<div class="card-content" id="content-' + idea.id + '">' + escHtml(idea.content) + '</div>';

        // Footer: tags + actions
        var tagsHtml = '';
        if (idea.category && idea.category !== 'chat') {
            tagsHtml += '<span class="card-tag cat-tag">' + escHtml(idea.category) + '</span>';
        }
        if (idea.tags) {
            idea.tags.split(',').forEach(function(t) {
                t = t.trim();
                if (t) tagsHtml += '<span class="card-tag">' + escHtml(t) + '</span>';
            });
        }
        if (idea.status && idea.status !== 'raw') {
            tagsHtml += '<span class="status-badge ' + idea.status + '">' + idea.status + '</span>';
        }

        var voteHtml = '';
        var canVote = (userRole || publicAccess === 'vote' || !currentContext);
        if (!idea.clerk_key && idea.category !== 'digest') {
            if (canVote) {
                var agreeActive = idea.user_vote === 'agree' ? ' active-agree' : '';
                var disagreeActive = idea.user_vote === 'disagree' ? ' active-disagree' : '';
                voteHtml = '<button class="vote-btn' + agreeActive + '" onclick="voteIdea(' + idea.id + ',\'agree\')" title="Agree">\ud83d\udc4d <span class="count" id="agree-' + idea.id + '">' + (idea.agree_count || 0) + '</span></button>' +
                           '<button class="vote-btn' + disagreeActive + '" onclick="voteIdea(' + idea.id + ',\'disagree\')" title="Disagree">\ud83d\udc4e <span class="count" id="disagree-' + idea.id + '">' + (idea.disagree_count || 0) + '</span></button>';
            } else if (publicAccess === 'read') {
                voteHtml = '<span style="font-size:0.8rem;color:#888;">\ud83d\udc4d ' + (idea.agree_count || 0) + ' \u00b7 \ud83d\udc4e ' + (idea.disagree_count || 0) + '</span>';
            }
        }

        var actionsHtml = '';
        if (isOwn && !idea.clerk_key) {
            // Own human ideas: edit, delete, promote
            actionsHtml += '<button onclick="startEdit(' + idea.id + ')" title="Edit">&#x270E;</button>';
            actionsHtml += '<button class="delete-btn" onclick="deleteIdea(' + idea.id + ')" title="Delete">&#x2715;</button>';
            if (idea.status === 'raw') {
                actionsHtml += '<button onclick="promote(' + idea.id + ',\'refining\')" title="Promote">&#x2B06;</button>';
            } else if (idea.status === 'refining') {
                actionsHtml += '<button onclick="promote(' + idea.id + ',\'distilled\')" title="Promote">&#x2B06;</button>';
            }
        } else if (isOwn && idea.clerk_key) {
            // AI responses to your ideas: delete only
            actionsHtml += '<button class="delete-btn" onclick="deleteIdea(' + idea.id + ')" title="Delete">&#x2715;</button>';
        }

        var footer = '<div class="card-footer"><div class="card-tags">' + tagsHtml + '</div><div class="card-actions">' + voteHtml + actionsHtml + '</div></div>';

        card.innerHTML = header + content + footer;
        return card;
    }

    // ── Load ideas ──
    async function loadIdeas(before) {
        var url = 'api.php?action=history&limit=50';
        if (currentContext) {
            url += '&group_id=' + currentContext + '&include_chat=1';
        } else if (geoTown) {
            url += '&town_id=' + geoTown;
        } else if (geoState) {
            url += '&state_id=' + geoState;
        }
        if (currentFilter) {
            url += '&status=' + currentFilter;
        }
        if (currentCategoryFilter) {
            url += '&category=' + currentCategoryFilter;
        }
        if (before) {
            url += '&before=' + encodeURIComponent(before);
        }

        try {
            var resp = await fetch(url);
            var data = await resp.json();

            if (!data.success) {
                streamEmpty.textContent = data.error || 'Could not load ideas';
                streamEmpty.style.display = 'block';
                return;
            }

            if (data.user_role !== undefined) {
                userRole = data.user_role;
            }
            if (data.public_access !== undefined) {
                publicAccess = data.public_access;
            }
            updateFooter();

            // Hide input area for non-member public viewers
            var inputArea = document.querySelector('.input-area');
            if (inputArea) {
                if (currentContext && !userRole && publicAccess) {
                    inputArea.style.display = 'none';
                } else {
                    inputArea.style.display = '';
                }
            }

            if (!before) {
                // Initial load — clear stream
                stream.innerHTML = '';
                stream.appendChild(streamEmpty);
                loadedIdeas = [];
            }

            if (data.ideas.length === 0 && loadedIdeas.length === 0) {
                var emptyMsg = 'No ideas yet. What\'s on your mind?';
                if (currentContext) emptyMsg = 'No ideas in this group yet. Start the conversation!';
                else if (geoTown || geoState) emptyMsg = 'No ideas here yet. Be the first to share!';
                streamEmpty.textContent = emptyMsg;
                streamEmpty.style.display = 'block';
                return;
            }

            streamEmpty.style.display = 'none';

            // Remove existing "load more" button
            var existingLoadMore = stream.querySelector('.load-more');
            if (existingLoadMore) existingLoadMore.remove();

            data.ideas.forEach(function(idea) {
                // Skip duplicates
                if (loadedIdeas.some(function(i) { return i.id === idea.id; })) return;
                var card = renderIdeaCard(idea);
                stream.appendChild(card);
                loadedIdeas.push(idea);
            });

            // Add "Load more" if we got a full page
            if (data.ideas.length >= 50) {
                var oldest = data.ideas[data.ideas.length - 1];
                var loadMoreDiv = document.createElement('div');
                loadMoreDiv.className = 'load-more';
                loadMoreDiv.innerHTML = '<button onclick="loadIdeas(\'' + oldest.created_at + '\')">Load older ideas</button>';
                stream.appendChild(loadMoreDiv);
            }
        } catch (err) {
            streamEmpty.textContent = 'Network error loading ideas';
            streamEmpty.style.display = 'block';
        }
    }

    // ── Status filter ──
    function setFilter(status) {
        currentFilter = status;
        document.querySelectorAll('.filter-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.filter === status);
        });
        loadedIdeas = [];
        loadIdeas();
    }

    function setCategoryFilter(cat) {
        currentCategoryFilter = cat;
        document.querySelectorAll('.cat-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.cat === cat);
        });
        loadedIdeas = [];
        loadIdeas();
    }

    // ── Context switch ──
    function switchContext() {
        stopPolling();
        loadedIdeas = [];
        userRole = null;
        publicAccess = null;
        loadIdeas();
        if (currentContext) startPolling();
    }

    // ── Polling ──
    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pollForNew, 8000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    var isPolling = false;
    var hasGeoOrGroup = currentContext || geoState || geoTown;
    async function pollForNew() {
        if (isPolling || document.hidden || !hasGeoOrGroup) return;
        isPolling = true;
        var newest = loadedIdeas.length ? loadedIdeas[0].created_at : null;
        if (!newest) { isPolling = false; return; }

        try {
            var url = 'api.php?action=history&since=' + encodeURIComponent(newest) + '&limit=20';
            if (currentContext) {
                url += '&group_id=' + currentContext + '&include_chat=1';
            } else if (geoTown) {
                url += '&town_id=' + geoTown;
            } else if (geoState) {
                url += '&state_id=' + geoState;
            }
            var resp = await fetch(url);
            var data = await resp.json();

            if (data.success && data.ideas.length > 0) {
                data.ideas.forEach(function(idea) {
                    if (loadedIdeas.some(function(i) { return i.id === idea.id; })) return;
                    prependIdea(idea);
                });
            }
        } catch (e) {}
        isPolling = false;
    }

    // Pause polling when tab hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else if (hasGeoOrGroup) {
            pollForNew(); // immediate check
            startPolling();
        }
    });

    // ── Inline edit ──
    function startEdit(ideaId) {
        var contentEl = document.getElementById('content-' + ideaId);
        if (!contentEl || contentEl.style.display === 'none') return;
        var currentText = contentEl.textContent;

        contentEl.style.display = 'none';

        var form = document.createElement('div');
        form.className = 'inline-edit';
        form.id = 'edit-form-' + ideaId;
        form.innerHTML = '<textarea>' + escHtml(currentText) + '</textarea>' +
            '<div class="edit-actions">' +
            '<button class="edit-cancel" onclick="cancelEdit(' + ideaId + ')">Cancel</button>' +
            '<button class="edit-save" onclick="saveEdit(' + ideaId + ')">Save</button>' +
            '</div>';

        contentEl.parentNode.insertBefore(form, contentEl.nextSibling);
    }

    function cancelEdit(ideaId) {
        var form = document.getElementById('edit-form-' + ideaId);
        if (form) form.remove();
        var contentEl = document.getElementById('content-' + ideaId);
        if (contentEl) contentEl.style.display = '';
    }

    async function saveEdit(ideaId) {
        var form = document.getElementById('edit-form-' + ideaId);
        if (!form) return;
        var newContent = form.querySelector('textarea').value.trim();
        if (!newContent) return;

        try {
            var resp = await fetch('api.php?action=edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, content: newContent })
            });
            var data = await resp.json();
            if (data.success) {
                form.remove();
                var contentEl = document.getElementById('content-' + ideaId);
                if (contentEl) {
                    contentEl.textContent = newContent;
                    contentEl.style.display = '';
                }
                showToast('Saved', 'success');
            } else {
                showToast(data.error || 'Edit failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }
    }

    // ── Delete ──
    async function deleteIdea(ideaId) {
        if (!confirm('Delete this idea?')) return;
        try {
            var resp = await fetch('api.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId })
            });
            var data = await resp.json();
            if (data.success) {
                var card = document.getElementById('idea-' + ideaId);
                if (card) card.remove();
                loadedIdeas = loadedIdeas.filter(function(i) { return i.id !== ideaId; });
                if (loadedIdeas.length === 0) {
                    streamEmpty.textContent = 'No ideas yet.';
                    streamEmpty.style.display = 'block';
                }
                showToast('Deleted', 'success');
            } else {
                showToast(data.error || 'Delete failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }
    }

    // ── Promote ──
    async function promote(ideaId, newStatus) {
        try {
            var resp = await fetch('api.php?action=promote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, status: newStatus })
            });
            var data = await resp.json();
            if (data.success) {
                // Re-render the card in place
                var card = document.getElementById('idea-' + ideaId);
                var idea = loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea && card) {
                    idea.status = newStatus;
                    var newCard = renderIdeaCard(idea);
                    card.replaceWith(newCard);
                }
                showToast('Promoted to ' + newStatus, 'success');
            } else {
                showToast(data.error || 'Promote failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }
    }

    // ── Gather / Crystallize ──
    async function runGather() {
        var btn = document.getElementById('gatherBtn');
        btn.disabled = true;
        btn.textContent = 'Gathering...';
        showToast('Running gatherer...', 'info');

        try {
            var resp = await fetch('api.php?action=gather', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: parseInt(currentContext) })
            });
            var data = await resp.json();
            if (data.success) {
                var linkCount = (data.actions || []).filter(function(a) { return a.action === 'LINK' && a.success; }).length;
                var summaryCount = (data.actions || []).filter(function(a) { return a.action === 'SUMMARIZE' && a.success; }).length;
                showToast('Found ' + linkCount + ' connections, created ' + summaryCount + ' digest(s)', 'success');
                // Reload stream to show new digests
                loadedIdeas = [];
                loadIdeas();
            } else {
                showToast(data.error || 'Gather failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }

        btn.disabled = false;
        btn.textContent = 'Gather';
    }

    async function runCrystallize() {
        if (!confirm('Crystallize this group into a proposal?')) return;

        var btn = document.getElementById('crystallizeBtn');
        btn.disabled = true;
        btn.textContent = 'Crystallizing...';
        showToast('Crystallizing...', 'info');

        try {
            var resp = await fetch('api.php?action=crystallize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: parseInt(currentContext) })
            });
            var data = await resp.json();
            if (data.success) {
                showToast('Proposal created!', 'success');
                loadedIdeas = [];
                loadIdeas();
            } else {
                showToast(data.error || 'Crystallize failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }

        btn.disabled = false;
        btn.textContent = 'Crystallize';
    }

    // ── Vote ──
    async function voteIdea(ideaId, voteType) {
        if (!currentUser) {
            showToast('Log in to vote', 'error');
            return;
        }
        try {
            var resp = await fetch('api.php?action=vote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, vote_type: voteType })
            });
            var data = await resp.json();
            if (data.success) {
                // Update the idea in loadedIdeas
                var idea = loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea) {
                    idea.agree_count = data.agree_count;
                    idea.disagree_count = data.disagree_count;
                    idea.user_vote = data.user_vote;
                    // Re-render card in place
                    var oldCard = document.getElementById('idea-' + ideaId);
                    if (oldCard) {
                        var newCard = renderIdeaCard(idea);
                        oldCard.replaceWith(newCard);
                    }
                }
            } else {
                showToast(data.error || 'Vote failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }
    }

    // ── Footer visibility ──
    function updateFooter() {
        if (currentContext && userRole === 'facilitator') {
            footerBar.classList.add('visible');
        } else {
            footerBar.classList.remove('visible');
        }
    }

    // ── Voice input (toggle on/off, appends to textarea) ──
    var recognition = null;
    var micOn = false;
    var micBaseText = '';
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
        recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = 'en-US';

        recognition.onstart = function() {
            micOn = true;
            micBtn.classList.add('listening');
            micBtn.textContent = '\u23FA';
            // Snapshot current text so we append after it
            micBaseText = ideaInput.value;
        };
        recognition.onend = function() {
            micBtn.classList.remove('listening');
            micBtn.textContent = '\uD83C\uDFA4';
            if (micOn) {
                // Browser killed it (timeout, etc.) — restart to keep toggle on
                try { recognition.start(); } catch(e) { micOn = false; }
            }
        };
        recognition.onresult = function(e) {
            var final = '', interim = '';
            for (var i = 0; i < e.results.length; i++) {
                if (e.results[i].isFinal) {
                    final += e.results[i][0].transcript;
                } else {
                    interim += e.results[i][0].transcript;
                }
            }
            // Append finalized + interim after the base text
            var sep = micBaseText && !micBaseText.endsWith(' ') ? ' ' : '';
            ideaInput.value = micBaseText + sep + final + interim;
            ideaInput.style.height = 'auto';
            ideaInput.style.height = Math.min(ideaInput.scrollHeight, 120) + 'px';
        };
        recognition.onerror = function(e) {
            if (e.error === 'no-speech') return; // ignore silence, keep listening
            micOn = false;
            micBtn.classList.remove('listening');
            micBtn.textContent = '\uD83C\uDFA4';
        };

        micBtn.addEventListener('click', function() {
            if (micOn) {
                micOn = false;
                recognition.stop();
                // Commit: update base text to include everything captured so far
                micBaseText = ideaInput.value;
            } else {
                recognition.start();
            }
        });
    } else {
        micBtn.style.display = 'none';
    }

    // ── Reply to idea ──
    function replyTo(ideaId) {
        var prefix = 're: #' + ideaId + ' - ';
        // Don't double-prepend if already replying to this idea
        if (ideaInput.value.indexOf(prefix) === 0) {
            ideaInput.focus();
            return;
        }
        ideaInput.value = prefix;
        ideaInput.focus();
        // Place cursor at end
        ideaInput.setSelectionRange(prefix.length, prefix.length);
        // Auto-resize + counter
        ideaInput.style.height = 'auto';
        ideaInput.style.height = Math.min(ideaInput.scrollHeight, 200) + 'px';
        updateCharCounter();
        // Scroll input into view on mobile
        ideaInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ── Helpers ──
    function showToast(msg, type) {
        toast.textContent = msg;
        toast.className = 'toast ' + type;
        clearTimeout(toast._timer);
        toast._timer = setTimeout(function() { toast.classList.add('hidden'); }, 3000);
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        var now = new Date();
        var diff = (now - d) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        var opts = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
        if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
        return d.toLocaleDateString(undefined, opts);
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Init ──
    async function init() {
        // In geo stream mode, hide context selector (geo overrides group)
        if ((geoState || geoTown) && contextSelect) {
            contextSelect.parentElement.style.display = 'none';
        }

        // Load user's groups into selector
        if (currentUser && contextSelect) {
            try {
                var resp = await fetch('api.php?action=list_groups&mine=1');
                var data = await resp.json();
                if (data.success && data.groups) {
                    data.groups.forEach(function(g) {
                        if (g.status === 'archived') return;
                        var opt = document.createElement('option');
                        opt.value = g.id;
                        opt.textContent = g.name;
                        opt.style.cssText = 'background:#1a1a2e;color:#eee;';
                        contextSelect.appendChild(opt);
                    });
                }
            } catch (e) {}
        }

        // Restore context (only if not in geo mode)
        if (currentContext && contextSelect && !geoState && !geoTown) {
            var found = false;
            for (var i = 0; i < contextSelect.options.length; i++) {
                if (contextSelect.options[i].value === currentContext) {
                    contextSelect.value = currentContext;
                    found = true;
                    break;
                }
            }
            if (!found) {
                currentContext = '';
                localStorage.setItem('tpb_talk_context', '');
            }
        }

        // Load ideas
        loadIdeas();

        // Start polling if in group or geo mode
        if (hasGeoOrGroup) startPolling();
    }

    init();
    </script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
