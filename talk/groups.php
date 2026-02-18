<?php
/**
 * Talk Groups — /talk/groups.php
 * Browse, create, join, and manage deliberation groups (Phase 3)
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/set-cookie.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $dbUser = getUser($pdo);
    $currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;
} catch (PDOException $e) {
    $currentUserId = 0;
    $pdo = null;
}

// ── Phase 5: Handle invite accept/decline via token ──
$inviteResult = null;
if (isset($_GET['invite_action'], $_GET['token']) && $pdo) {
    $inviteAction = $_GET['invite_action'];
    $token = $_GET['token'];

    if ($inviteAction === 'accept') {
        $stmt = $pdo->prepare("
            SELECT gi.*, ig.name AS group_name
            FROM group_invites gi
            JOIN idea_groups ig ON ig.id = gi.group_id
            WHERE gi.accept_token = ?
        ");
    } elseif ($inviteAction === 'decline') {
        $stmt = $pdo->prepare("
            SELECT gi.*, ig.name AS group_name
            FROM group_invites gi
            JOIN idea_groups ig ON ig.id = gi.group_id
            WHERE gi.decline_token = ?
        ");
    } else {
        $stmt = null;
    }

    if ($stmt) {
        $stmt->execute([$token]);
        $invite = $stmt->fetch();

        if (!$invite) {
            $inviteResult = ['type' => 'error', 'message' => 'Invalid or expired invitation link.'];
        } elseif ($invite['status'] !== 'pending') {
            $inviteResult = ['type' => 'info', 'message' => "This invitation was already {$invite['status']}."];
        } elseif (strtotime($invite['expires_at']) < time()) {
            // Mark expired
            $pdo->prepare("UPDATE group_invites SET status = 'expired' WHERE id = ?")->execute([$invite['id']]);
            $inviteResult = ['type' => 'error', 'message' => 'This invitation has expired. Ask the facilitator to send a new one.'];
        } elseif ($inviteAction === 'accept') {
            $acceptUserId = $invite['user_id'];

            // New user: auto-create account from invite email
            if (!$acceptUserId) {
                $invEmail = strtolower($invite['email']);
                // Check if an account was created since the invite was sent
                $stmt2 = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = ?");
                $stmt2->execute([$invEmail]);
                $existing = $stmt2->fetch();
                if ($existing) {
                    $acceptUserId = (int)$existing['user_id'];
                } else {
                    // Auto-create account
                    $emailPrefix = explode('@', $invEmail)[0];
                    $autoUsername = preg_replace('/[^a-z0-9]/', '', $emailPrefix) . '_' . substr(md5($invEmail . time()), 0, 4);
                    $pdo->prepare("INSERT INTO users (username, email, civic_points) VALUES (?, ?, 0)")
                        ->execute([$autoUsername, $invEmail]);
                    $acceptUserId = (int)$pdo->lastInsertId();

                    // Mark email as verified (clicking invite link proves ownership)
                    $pdo->prepare("INSERT INTO user_identity_status (user_id, email_verified) VALUES (?, 1)")
                        ->execute([$acceptUserId]);

                    // Create device session and log in
                    $deviceSession = 'civic_' . bin2hex(random_bytes(8)) . '_' . time();
                    $pdo->prepare("INSERT INTO user_devices (user_id, device_session, device_type, ip_address, is_active) VALUES (?, ?, 'web', ?, 1)")
                        ->execute([$acceptUserId, $deviceSession, $_SERVER['REMOTE_ADDR'] ?? '']);
                    tpbSetLoginCookies($acceptUserId, $deviceSession, TPB_COOKIE_1_YEAR);

                    // Update the invite record with the new user_id
                    $pdo->prepare("UPDATE group_invites SET user_id = ? WHERE id = ?")->execute([$acceptUserId, $invite['id']]);

                    // Refresh dbUser for the rest of the page
                    $dbUser = getUser($pdo);
                    $currentUserId = $acceptUserId;
                }
            }

            // Add to group if not already a member
            $stmt2 = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ?");
            $stmt2->execute([$invite['group_id'], $acceptUserId]);
            if (!$stmt2->fetch()) {
                $pdo->prepare("INSERT INTO idea_group_members (group_id, user_id, role, status) VALUES (?, ?, 'member', 'active')")
                    ->execute([$invite['group_id'], $acceptUserId]);
            }
            $pdo->prepare("UPDATE group_invites SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$invite['id']]);

            $welcomeMsg = !$invite['user_id']
                ? "Welcome to The People's Branch! Your account has been created and you've joined \"{$invite['group_name']}\"."
                : "You've joined \"{$invite['group_name']}\"!";

            $inviteResult = [
                'type' => 'success',
                'message' => $welcomeMsg,
                'group_id' => $invite['group_id']
            ];
        } elseif ($inviteAction === 'decline') {
            $pdo->prepare("UPDATE group_invites SET status = 'declined', responded_at = NOW() WHERE id = ?")->execute([$invite['id']]);
            $inviteResult = ['type' => 'info', 'message' => "You've declined the invitation to \"{$invite['group_name']}\"."];
        }
    }
}

$groupId = (int)($_GET['id'] ?? 0);
// If invite was accepted, show that group
if ($inviteResult && ($inviteResult['type'] ?? '') === 'success' && isset($inviteResult['group_id'])) {
    $groupId = (int)$inviteResult['group_id'];
}
$mode = $groupId ? 'detail' : 'list';

// Geo context from URL params
$geoStateId = isset($_GET['state']) ? (int)$_GET['state'] : null;
$geoTownId  = isset($_GET['town'])  ? (int)$_GET['town']  : null;
$geoScope = 'federal';
$geoLabel = 'USA';
if ($geoTownId && $pdo) {
    $stmt = $pdo->prepare("SELECT t.town_name, s.abbreviation, s.state_id FROM towns t JOIN states s ON t.state_id = s.state_id WHERE t.town_id = ?");
    $stmt->execute([$geoTownId]);
    $geo = $stmt->fetch();
    if ($geo) { $geoScope = 'town'; $geoStateId = (int)$geo['state_id']; $geoLabel = $geo['town_name'] . ', ' . $geo['abbreviation']; }
} elseif ($geoStateId && $pdo) {
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE state_id = ?");
    $stmt->execute([$geoStateId]);
    $geo = $stmt->fetch();
    if ($geo) { $geoScope = 'state'; $geoLabel = $geo['state_name']; }
}

// Resolve user's town/state names for scope selector
$userTownName = null;
$userStateName = null;
$userTownId = $dbUser ? (int)($dbUser['current_town_id'] ?? 0) : 0;
$userStateId = $dbUser ? (int)($dbUser['current_state_id'] ?? 0) : 0;
if ($userTownId && $pdo) {
    $stmt = $pdo->prepare("SELECT t.town_name, s.abbreviation FROM towns t JOIN states s ON t.state_id = s.state_id WHERE t.town_id = ?");
    $stmt->execute([$userTownId]);
    $row = $stmt->fetch();
    if ($row) $userTownName = $row['town_name'] . ', ' . $row['abbreviation'];
}
if ($userStateId && $pdo) {
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE state_id = ?");
    $stmt->execute([$userStateId]);
    $row = $stmt->fetch();
    if ($row) $userStateName = $row['state_name'];
}

// Nav setup
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'talk';
$geoQuery = $geoTownId ? '?town=' . $geoTownId : ($geoStateId ? '?state=' . $geoStateId : '');
$pageTitle = ($groupId ? 'Group' : 'Groups') . ' - ' . ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk | The People\'s Branch';
$secondaryNavBrand = ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk';
$secondaryNav = [
    ['label' => 'Stream',  'url' => '/talk/' . $geoQuery],
    ['label' => 'Groups',  'url' => '/talk/groups.php' . $geoQuery],
    ['label' => 'Help',    'url' => '/talk/help.php'],
];

$pageStyles = <<<'CSS'
        .container { padding: 20px; }

        .container { max-width: 700px; margin: 0 auto; }

        header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }

        h1 { font-size: 1.3rem; color: #ffffff; }
        h2 { font-size: 1.1rem; color: #eee; margin-bottom: 0.75rem; }

        .header-links { display: flex; gap: 1rem; font-size: 0.9rem; }
        .header-links a { color: #90caf9; text-decoration: none; }
        .header-links a:hover { text-decoration: underline; color: #bbdefb; }

        .user-status { font-size: 0.8rem; color: #81c784; text-align: right; margin-bottom: 0.75rem; }
        .user-status .dot { display: inline-block; width: 8px; height: 8px; background: #4caf50; border-radius: 50%; margin-right: 4px; }

        .section { margin-bottom: 2rem; }

        .group-card {
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 4px solid #4fc3f7;
            cursor: pointer;
            transition: background 0.2s;
        }
        .group-card:hover { background: rgba(255,255,255,0.14); }

        .group-card .name { font-size: 1rem; font-weight: 600; color: #eee; margin-bottom: 4px; }
        .group-card .desc { font-size: 0.85rem; color: #aaa; margin-bottom: 8px; }
        .group-card .meta { display: flex; gap: 12px; font-size: 0.75rem; color: #999; flex-wrap: wrap; align-items: center; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge.forming { background: rgba(255,152,0,0.2); color: #ffb74d; }
        .badge.active { background: rgba(76,175,80,0.2); color: #81c784; }
        .badge.crystallizing { background: rgba(156,39,176,0.2); color: #ce93d8; }
        .badge.crystallized { background: rgba(255,215,0,0.2); color: #ffd700; }
        .badge.archived { background: rgba(100,100,100,0.2); color: #999; }

        .badge.facilitator { background: rgba(255,215,0,0.2); color: #ffd700; }
        .badge.member { background: rgba(79,195,247,0.2); color: #4fc3f7; }
        .badge.observer { background: rgba(100,100,100,0.2); color: #999; }

        .tags { display: flex; gap: 6px; flex-wrap: wrap; }
        .tag {
            display: inline-block; padding: 2px 8px; border-radius: 8px;
            font-size: 0.7rem; background: rgba(79,195,247,0.15); color: #4fc3f7;
        }

        .btn {
            display: inline-block; padding: 8px 16px; border: none; border-radius: 8px;
            font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: #0288d1; color: #fff; }
        .btn-primary:hover { background: #039be5; }
        .btn-secondary { background: rgba(255,255,255,0.1); color: #4fc3f7; border: 1px solid rgba(79,195,247,0.3); }
        .btn-secondary:hover { background: rgba(79,195,247,0.15); }

        .staleness-banner {
            background: rgba(255, 152, 0, 0.12);
            border: 1px solid rgba(255, 152, 0, 0.3);
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 1rem;
            color: #ffb74d;
            font-size: 0.85rem;
        }
        .btn-danger { background: rgba(244,67,54,0.2); color: #e57373; border: 1px solid rgba(244,67,54,0.3); }
        .btn-danger:hover { background: rgba(244,67,54,0.3); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.85rem; color: #aaa; margin-bottom: 4px; }
        .form-group input, .form-group textarea, .form-group select,
        textarea, input[type="text"], input[type="email"], select {
            width: 100%; padding: 10px 12px; border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px; background: #2a2a3e; color: #fff;
            font-family: inherit; font-size: 0.9rem;
        }
        .form-group select option, select option { background: #1a1a2e; color: #eee; }
        .form-group textarea, textarea { min-height: 80px; resize: vertical; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus,
        textarea:focus, input:focus, select:focus {
            outline: none; border-color: #4fc3f7;
        }

        .create-form {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 1.5rem;
            display: none;
        }
        .create-form.visible { display: block; }

        .empty { text-align: center; padding: 2rem; color: #999; }

        .detail-header { margin-bottom: 1.5rem; }
        .detail-header .name { font-size: 1.4rem; font-weight: 600; color: #eee; }
        .detail-header .desc { color: #aaa; margin-top: 4px; }
        .detail-header .meta { display: flex; gap: 12px; margin-top: 8px; font-size: 0.85rem; color: #999; align-items: center; flex-wrap: wrap; }

        .members-list { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1rem; }
        .member-chip {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 12px;
            background: rgba(255,255,255,0.08); font-size: 0.8rem;
        }
        .member-chip.inactive { opacity: 0.5; }
        .member-chip .member-actions { display: flex; gap: 2px; margin-left: 4px; }
        .member-chip .member-actions button {
            background: none; border: none; color: #aab; cursor: pointer;
            font-size: 0.8rem; padding: 1px 4px; border-radius: 4px; transition: all 0.15s;
        }
        .member-chip .member-actions button:hover { color: #eee; background: rgba(255,255,255,0.1); }
        .member-chip .member-actions button.danger:hover { color: #ef5350; }

        .actions { display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap; }

        .status-msg { padding: 10px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
        .status-msg.success { background: rgba(76,175,80,0.15); color: #81c784; }
        .status-msg.error { background: rgba(244,67,54,0.15); color: #e57373; }

        .sub-groups { margin-top: 1rem; }
        .sub-group { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 6px; font-size: 0.85rem; }
        .sub-group a { color: #4fc3f7; text-decoration: none; font-weight: 600; }

        .sic-badge {
            display: inline-block; padding: 2px 8px; border-radius: 8px;
            font-size: 0.65rem; font-weight: 600;
            background: rgba(156,39,176,0.15); color: #ce93d8;
        }
        .standard-badge {
            display: inline-block; padding: 2px 8px; border-radius: 8px;
            font-size: 0.65rem; font-weight: 600;
            background: rgba(255,215,0,0.15); color: #ffd700;
        }
        .group-card.standard { border-left-color: #ffd700; }

        .geo-filter {
            display: flex; gap: 6px; margin-bottom: 1rem; flex-wrap: wrap;
        }
        .geo-filter-btn {
            padding: 6px 14px; border: 1px solid rgba(255,255,255,0.12);
            border-radius: 16px; background: none; color: #888;
            font-size: 0.8rem; cursor: pointer; transition: all 0.15s;
        }
        .geo-filter-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
        .geo-filter-btn.active { border-color: #4fc3f7; color: #4fc3f7; background: rgba(79,195,247,0.1); }
CSS;

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/nav.php';
?>

    <div class="container">

        <div id="statusMsg"></div>

<?php if ($inviteResult): ?>
        <div style="padding: 12px 16px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.95rem;
            <?php if ($inviteResult['type'] === 'success'): ?>background: rgba(46,125,50,0.2); border: 1px solid #4caf50; color: #81c784;
            <?php elseif ($inviteResult['type'] === 'error'): ?>background: rgba(198,40,40,0.2); border: 1px solid #e57373; color: #ef9a9a;
            <?php else: ?>background: rgba(255,152,0,0.2); border: 1px solid #ffb74d; color: #ffcc80;<?php endif; ?>">
            <?= htmlspecialchars($inviteResult['message']) ?>
        </div>
<?php endif; ?>

        <?php if ($mode === 'list'): ?>
            <!-- ════ LIST VIEW ════ -->

            <div class="geo-filter">
                <button class="geo-filter-btn<?= !$geoStateId && !$geoTownId ? ' active' : '' ?>" onclick="location.href='groups.php'">All / USA</button>
<?php if ($dbUser && !empty($dbUser['current_state_id'])): ?>
                <button class="geo-filter-btn<?= $geoStateId && !$geoTownId ? ' active' : '' ?>" onclick="location.href='groups.php?state=<?= (int)$dbUser['current_state_id'] ?>'">My State</button>
<?php endif; ?>
<?php if ($dbUser && !empty($dbUser['current_town_id'])): ?>
                <button class="geo-filter-btn<?= $geoTownId ? ' active' : '' ?>" onclick="location.href='groups.php?town=<?= (int)$dbUser['current_town_id'] ?>'">My Town</button>
<?php endif; ?>
            </div>

            <?php if ($currentUserId): ?>
                <button class="btn btn-primary" onclick="document.getElementById('createForm').classList.toggle('visible')" style="margin-bottom: 1rem;">+ Create Group</button>

                <div class="create-form" id="createForm">
                    <h2>Create a Group</h2>
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" id="groupName" placeholder="e.g., Putnam Housing" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="groupDesc" placeholder="What is this group about?"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Tags</label>
                        <input type="text" id="groupTags" placeholder="e.g., housing, putnam, ct">
                    </div>
                    <div class="form-group">
                        <label>Scope</label>
                        <select id="groupScope">
                            <option value="">National (all of USA)</option>
<?php if ($userStateName): ?>
                            <option value="state"<?= $geoScope === 'state' ? ' selected' : '' ?>><?= htmlspecialchars($userStateName) ?></option>
<?php endif; ?>
<?php if ($userTownName): ?>
                            <option value="town"<?= $geoScope === 'town' ? ' selected' : '' ?>><?= htmlspecialchars($userTownName) ?></option>
<?php endif; ?>
                        </select>
                        <span style="font-size:0.8rem;color:#888;">Where should this group appear?</span>
                    </div>
                    <div class="form-group">
                        <label>Access Level</label>
                        <select id="groupAccess">
                            <option value="observable">Observable (anyone can see, members contribute)</option>
                            <option value="open">Open (anyone can join and contribute)</option>
                            <option value="closed">Closed (invitation only)</option>
                        </select>
                    </div>
                    <div class="form-group" style="font-size:0.85rem;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:6px;">
                            <input type="checkbox" id="groupPublicRead" onchange="if(!this.checked) document.getElementById('groupPublicVote').checked=false;">
                            Allow verified non-members to read ideas
                        </label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="groupPublicVote" onchange="if(this.checked) document.getElementById('groupPublicRead').checked=true;">
                            Allow verified non-members to vote on ideas
                        </label>
                    </div>
                    <button class="btn btn-primary" onclick="createGroup()">Create Group</button>
                </div>
            <?php endif; ?>

            <div id="civicGroups" class="section">
                <h2>Civic Topics</h2>
                <div id="civicGroupsList"><div class="empty">Loading...</div></div>
            </div>

            <div id="myGroups" class="section">
                <h2>My Groups</h2>
                <div id="myGroupsList"><div class="empty">Loading...</div></div>
            </div>

            <div id="discoverSection" class="section">
                <h2>Community Groups</h2>
                <div id="discoverList"><div class="empty">Loading...</div></div>
            </div>

        <?php else: ?>
            <!-- ════ DETAIL VIEW ════ -->
            <div id="groupDetail"><div class="empty">Loading...</div></div>
        <?php endif; ?>
    </div>

    <script>
    var currentUserId = <?= $currentUserId ?>;
    var groupId = <?= $groupId ?>;
    var geoState = <?= $geoStateId ? $geoStateId : 'null' ?>;
    var geoTown = <?= $geoTownId ? $geoTownId : 'null' ?>;
    var geoScope = <?= json_encode($geoScope) ?>;
    var userTownId = <?= $userTownId ?: 'null' ?>;
    var userStateId = <?= $userStateId ?: 'null' ?>;

    function showStatus(msg, type) {
        var el = document.getElementById('statusMsg');
        el.innerHTML = '<div class="status-msg ' + type + '">' + msg + '</div>';
        setTimeout(function() { el.innerHTML = ''; }, 4000);
    }

    async function apiPost(action, body) {
        var resp = await fetch('api.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        return resp.json();
    }

    async function apiGet(action, params) {
        var url = 'api.php?action=' + action;
        if (params) url += '&' + new URLSearchParams(params).toString();
        var resp = await fetch(url);
        return resp.json();
    }

    <?php if ($mode === 'list'): ?>
    // ─── List Mode ───

    async function loadGroups() {
        // Build geo filter params
        var geoParams = {};
        if (geoTown) { geoParams.scope = 'town'; geoParams.state_id = geoState; geoParams.town_id = geoTown; }
        else if (geoState) { geoParams.scope = 'state'; geoParams.state_id = geoState; }

        // Auto-create standard groups for this geo scope if needed
        if (geoTown || geoState) {
            var autoParams = { scope: geoScope };
            if (geoState) autoParams.state_id = geoState;
            if (geoTown) autoParams.town_id = geoTown;
            await apiPost('auto_create_standard_groups', autoParams);
        }

        // Load all groups (with geo filter)
        var all = await apiGet('list_groups', geoParams);
        var allGroups = all.success ? all.groups : [];

        // Separate standard (civic) from user-created
        var civicGroups = allGroups.filter(function(g) { return g.is_standard == 1; });
        var communityGroups = allGroups.filter(function(g) { return g.is_standard != 1 && !g.user_role; });

        // Civic Topics
        var civicList = document.getElementById('civicGroupsList');
        if (civicGroups.length > 0) {
            civicList.innerHTML = civicGroups.map(renderGroupCard).join('');
        } else {
            civicList.innerHTML = '<div class="empty">No civic topics here yet.</div>';
        }

        // My groups
        if (currentUserId) {
            var mine = await apiGet('list_groups', Object.assign({ mine: 1 }, geoParams));
            var myList = document.getElementById('myGroupsList');
            if (mine.success && mine.groups.length > 0) {
                myList.innerHTML = mine.groups.map(renderGroupCard).join('');
            } else {
                myList.innerHTML = '<div class="empty">No groups yet. Create or join one!</div>';
            }
        } else {
            document.getElementById('myGroups').style.display = 'none';
        }

        // Community Groups (user-created, not mine)
        var discoverList = document.getElementById('discoverList');
        if (communityGroups.length > 0) {
            discoverList.innerHTML = communityGroups.map(renderGroupCard).join('');
        } else {
            discoverList.innerHTML = '<div class="empty">No community groups yet.</div>';
        }
    }

    function renderGroupCard(g) {
        var tags = g.tags ? g.tags.split(',').map(function(t) {
            return '<span class="tag">' + t.trim() + '</span>';
        }).join('') : '';
        var roleLabels = { facilitator: '🎯 Group Facilitator', member: '💬 Group Member', observer: '👁 Group Observer' };
        var roleBadge = g.user_role ? '<span class="badge ' + g.user_role + '">' + (roleLabels[g.user_role] || g.user_role) + '</span>' : '';
        var standardBadge = g.is_standard == 1 ? '<span class="standard-badge">Civic</span> ' : '';
        var sicBadge = g.sic_code ? '<span class="sic-badge">SIC ' + escHtml(g.sic_code) + '</span> ' : '';
        var cardClass = 'group-card' + (g.is_standard == 1 ? ' standard' : '');

        return '<div class="' + cardClass + '" onclick="location.href=\'?id=' + g.id + '\'">' +
            '<div class="name">' + standardBadge + escHtml(g.name) + ' ' + roleBadge + '</div>' +
            (g.sic_description ? '<div class="desc">' + sicBadge + escHtml(g.sic_description) + '</div>' :
             g.description ? '<div class="desc">' + escHtml(g.description) + '</div>' : '') +
            '<div class="meta">' +
                '<span class="badge ' + g.status + '">' + g.status + '</span>' +
                '<span>' + (g.member_count || 0) + ' member' + (g.member_count != 1 ? 's' : '') + '</span>' +
                '<span>' + g.access_level + '</span>' +
            '</div>' +
            (tags ? '<div class="tags" style="margin-top:6px;">' + tags + '</div>' : '') +
        '</div>';
    }

    async function createGroup() {
        var name = document.getElementById('groupName').value.trim();
        if (!name) { showStatus('Group name is required', 'error'); return; }

        var scopeVal = document.getElementById('groupScope').value;
        var body = {
            name: name,
            description: document.getElementById('groupDesc').value.trim(),
            tags: document.getElementById('groupTags').value.trim(),
            access_level: document.getElementById('groupAccess').value,
            public_readable: document.getElementById('groupPublicRead').checked ? 1 : 0,
            public_voting: document.getElementById('groupPublicVote').checked ? 1 : 0
        };
        if (scopeVal === 'town' && userTownId) {
            body.scope = 'town';
            body.town_id = userTownId;
            if (userStateId) body.state_id = userStateId;
        } else if (scopeVal === 'state' && userStateId) {
            body.scope = 'state';
            body.state_id = userStateId;
        }
        var data = await apiPost('create_group', body);

        if (data.success) {
            showStatus('Group "' + name + '" created!', 'success');
            document.getElementById('createForm').classList.remove('visible');
            document.getElementById('groupName').value = '';
            document.getElementById('groupDesc').value = '';
            document.getElementById('groupTags').value = '';
            loadGroups();
        } else {
            showStatus(data.error || 'Error creating group', 'error');
        }
    }

    loadGroups();

    <?php else: ?>
    // ─── Detail Mode ───
    var roleLabels = { facilitator: '🎯 Facilitator', member: '💬 Member', observer: '👁 Observer' };

    async function loadGroupDetail() {
        var data = await apiGet('get_group', { group_id: groupId });
        var el = document.getElementById('groupDetail');

        if (!data.success) {
            el.innerHTML = '<div class="empty">' + (data.error || 'Group not found') + '</div>';
            return;
        }

        var g = data.group;
        var members = data.members || [];
        var subGroups = data.sub_groups || [];
        var userRole = data.user_role;
        var isFacilitator = userRole === 'facilitator';
        var isMember = !!userRole;

        var tags = g.tags ? g.tags.split(',').map(function(t) {
            return '<span class="tag">' + t.trim() + '</span>';
        }).join('') : '';

        var html = '<a href="groups.php" style="color:#4fc3f7;font-size:0.85rem;">← All groups</a>';

        // Header
        html += '<div class="detail-header">' +
            '<div class="name">' + escHtml(g.name) + '</div>' +
            '<div class="desc" id="groupDesc">' +
                (g.description ? escHtml(g.description) : '<span style="color:#666;font-style:italic;">No description</span>') +
                (isFacilitator ? ' <span onclick="editDescription(' + g.id + ')" style="cursor:pointer;color:#4fc3f7;font-size:0.8rem;" title="Edit description">&#x270E;</span>' : '') +
            '</div>' +
            '<div class="meta">' +
                '<span class="badge ' + g.status + '">' + g.status + '</span>' +
                '<span>' + members.length + ' member' + (members.length != 1 ? 's' : '') + '</span>' +
                '<span>' + g.access_level + '</span>' +
                (userRole ? '<span class="badge ' + userRole + '">You: ' + userRole + '</span>' : '') +
                (g.public_voting == 1 ? '<span style="color:#81c784;font-size:0.75rem;">&#127760; Public voting</span>' :
                 g.public_readable == 1 ? '<span style="color:#90caf9;font-size:0.75rem;">&#127760; Public reading</span>' : '') +
            '</div>' +
            (tags ? '<div class="tags" style="margin-top:6px;">' + tags + '</div>' : '') +
        '</div>';

        // Actions
        var publicAccess = data.public_access;
        html += '<div class="actions">';
        if (isMember) {
            html += '<a href="index.php?group=' + g.id + '" class="btn btn-primary">Open in Talk</a>';
        }
        if (!isMember && g.access_level !== 'closed') {
            html += '<button class="btn btn-primary" onclick="joinGroup(' + g.id + ')">Join Group</button>';
        }
        if (!isMember && publicAccess) {
            var paLabel = publicAccess === 'vote' ? 'View & Vote' : 'View Ideas';
            html += '<a href="index.php?group=' + g.id + '" class="btn btn-secondary">&#127760; ' + paLabel + '</a>';
        }
        if (isMember && !isFacilitator) {
            html += '<button class="btn btn-danger" onclick="leaveGroup(' + g.id + ')">Leave</button>';
        }
        if (isFacilitator && g.status === 'forming') {
            html += '<button class="btn btn-secondary" onclick="updateStatus(' + g.id + ', \'active\')">Activate Group</button>';
        }
        if (isFacilitator && g.status === 'active') {
            html += '<button class="btn btn-danger" onclick="archiveGroup(' + g.id + ')">📦 Archive</button>';
        }
        if (isFacilitator && g.status === 'crystallized') {
            html += '<button class="btn btn-danger" onclick="archiveGroup(' + g.id + ')">📦 Archive (Final)</button>';
            html += '<button class="btn btn-secondary" onclick="updateStatus(' + g.id + ', \'active\')">🔓 Reopen</button>';
        }
        if (isFacilitator && g.status === 'archived') {
            html += '<button class="btn btn-secondary" onclick="updateStatus(' + g.id + ', \'active\')">🔓 Reopen</button>';
        }
        html += '</div>';

        // Staleness check placeholder
        html += '<div id="stalenessArea"></div>';

        // Members
        html += '<div class="section" style="margin-top:1.5rem;"><h2>Members</h2><div class="members-list">';
        members.forEach(function(m) {
            var isMe = m.user_id == currentUserId;
            var isInactive = m.status === 'inactive';
            html += '<div class="member-chip' + (isInactive ? ' inactive' : '') + '">' +
                escHtml(m.display_name || 'User') +
                ' <span class="badge ' + m.role + '">' + (roleLabels[m.role] || m.role) + '</span>' +
                (isInactive ? ' <span style="color:#ef9a9a;font-size:0.7rem;">inactive</span>' : '');
            if (isFacilitator && !isMe) {
                html += '<span class="member-actions">';
                // Role buttons
                if (m.role !== 'facilitator') html += '<button onclick="changeMemberRole(' + g.id + ',' + m.user_id + ',\'facilitator\')" title="Promote to Facilitator">&#x2B06;</button>';
                if (m.role === 'facilitator') html += '<button onclick="changeMemberRole(' + g.id + ',' + m.user_id + ',\'member\')" title="Set as Member">&#x2B07;</button>';
                if (m.role !== 'observer') html += '<button onclick="changeMemberRole(' + g.id + ',' + m.user_id + ',\'observer\')" title="Set as Observer">&#x1F441;</button>';
                // Status toggle
                if (isInactive) {
                    html += '<button onclick="changeMemberStatus(' + g.id + ',' + m.user_id + ',\'active\')" title="Reactivate" style="color:#81c784;">&#x2714;</button>';
                } else {
                    html += '<button class="danger" onclick="changeMemberStatus(' + g.id + ',' + m.user_id + ',\'inactive\')" title="Deactivate">&#x23F8;</button>';
                }
                // Remove
                html += '<button class="danger" onclick="removeMember(' + g.id + ',' + m.user_id + ')" title="Remove from group">&#x2715;</button>';
                html += '</span>';
            }
            html += '</div>';
        });
        html += '</div>';


        html += '</div>';

        // Public access settings (facilitator only)
        if (isFacilitator) {
            html += '<div class="section" style="margin-top:1.5rem;"><h2>Public Access</h2>' +
                '<div style="background:rgba(255,255,255,0.05);border-radius:10px;padding:14px;">' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px;font-size:0.9rem;">' +
                    '<input type="checkbox" id="pubReadToggle"' + (g.public_readable == 1 ? ' checked' : '') +
                    ' onchange="if(!this.checked){document.getElementById(\'pubVoteToggle\').checked=false;}">' +
                    ' Verified non-members can read ideas' +
                '</label>' +
                '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;font-size:0.9rem;">' +
                    '<input type="checkbox" id="pubVoteToggle"' + (g.public_voting == 1 ? ' checked' : '') +
                    ' onchange="if(this.checked){document.getElementById(\'pubReadToggle\').checked=true;}">' +
                    ' Verified non-members can vote on ideas' +
                '</label>' +
                '<div style="font-size:0.75rem;color:#888;margin-bottom:10px;">Only affects users with verified accounts (phone-verified or higher).</div>' +
                '<button class="btn btn-secondary" onclick="savePublicAccess(' + g.id + ')">Save</button>' +
                '</div></div>';
        }

        // Invite form (facilitator only)
        if (isFacilitator) {
            html += '<div class="section" style="margin-top:1.5rem;">' +
                '<h2>Invite Members</h2>' +
                '<div style="background:rgba(255,255,255,0.05);border-radius:10px;padding:14px;">' +
                    '<textarea id="inviteEmails" rows="3" placeholder="Enter email addresses (one per line, or comma-separated)"></textarea>' +
                    '<button class="btn btn-primary" onclick="sendInvites(' + g.id + ')" style="margin-top:8px;">📧 Send Invites</button>' +
                    '<div id="inviteResults" style="margin-top:10px;"></div>' +
                '</div>' +
            '</div>';
        }

        // Invite list (members + facilitators, not observers)
        if (userRole && userRole !== 'observer') {
            html += '<div class="section" style="margin-top:1.5rem;"><h2>Invitations</h2>' +
                '<div id="inviteList"><div class="empty">Loading...</div></div></div>';
        }

        // Sub-groups
        if (subGroups.length > 0) {
            html += '<div class="section"><h2>Sub-groups</h2><div class="sub-groups">';
            subGroups.forEach(function(sg) {
                html += '<div class="sub-group">' +
                    '<a href="?id=' + sg.id + '">' + escHtml(sg.name) + '</a>' +
                    '<span class="badge ' + sg.status + '">' + sg.status + '</span>' +
                    '<span style="color:#999;font-size:0.75rem;">' + (sg.member_count || 0) + ' members</span>' +
                '</div>';
            });
            html += '</div></div>';
        }

        el.innerHTML = html;

        // Load invites for members/facilitators
        if (userRole && userRole !== 'observer') {
            loadInvites(groupId);
        }

        // Check staleness for facilitators
        if (isFacilitator && ['active', 'crystallizing', 'crystallized'].includes(g.status)) {
            var staleData = await apiGet('check_staleness', { group_id: groupId });
            if (staleData.success && staleData.stale) {
                var area = document.getElementById('stalenessArea');
                var banner = '<div class="staleness-banner"><strong>&#9888; Some outputs may be stale</strong>';
                staleData.digests.forEach(function(d) {
                    if (!d.is_stale) return;
                    var label = d.type === 'gather' ? 'Gather digest' : 'Crystallized proposal';
                    var details = [];
                    if (d.edited_count > 0) details.push(d.edited_count + ' edited');
                    if (d.deleted_count > 0) details.push(d.deleted_count + ' deleted');
                    banner += '<div style="margin-top:4px;font-size:0.8rem;">' +
                        label + ' #' + d.digest_id + ': ' +
                        details.join(', ') + ' source idea(s) since ' +
                        d.created_at.substring(0, 16) +
                        '</div>';
                });
                banner += '<div style="margin-top:8px;font-size:0.8rem;color:#aaa;">Re-run gatherer or re-crystallize to update.</div>';
                banner += '</div>';
                area.innerHTML = banner;
            }
        }
    }

    async function savePublicAccess(gId) {
        var data = await apiPost('update_group', {
            group_id: gId,
            public_readable: document.getElementById('pubReadToggle').checked ? 1 : 0,
            public_voting: document.getElementById('pubVoteToggle').checked ? 1 : 0
        });
        if (data.success) {
            showStatus('Public access settings updated', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error || 'Error updating settings', 'error');
        }
    }

    function editDescription(gId) {
        var el = document.getElementById('groupDesc');
        var current = el.textContent.replace(/\s*✎$/, '').trim();
        if (current === 'No description') current = '';
        el.innerHTML = '<textarea id="descEdit" rows="3">' + escHtml(current) + '</textarea>' +
            '<div style="display:flex;gap:6px;margin-top:6px;">' +
            '<button class="btn btn-secondary" onclick="cancelDescEdit()" style="padding:4px 12px;font-size:0.8rem;">Cancel</button>' +
            '<button class="btn btn-primary" onclick="saveDescription(' + gId + ')" style="padding:4px 12px;font-size:0.8rem;">Save</button>' +
            '</div>';
        document.getElementById('descEdit').focus();
    }

    function cancelDescEdit() {
        loadGroupDetail();
    }

    async function saveDescription(gId) {
        var val = document.getElementById('descEdit').value.trim();
        var data = await apiPost('update_group', { group_id: gId, description: val });
        if (data.success) {
            showStatus('Description updated', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error || 'Error updating description', 'error');
        }
    }

    async function joinGroup(id) {
        var data = await apiPost('join_group', { group_id: id });
        if (data.success) {
            showStatus('Joined as ' + data.role, 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function leaveGroup(id) {
        if (!confirm('Leave this group?')) return;
        var data = await apiPost('leave_group', { group_id: id });
        if (data.success) {
            location.href = 'groups.php';
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function updateStatus(id, status) {
        var data = await apiPost('update_group', { group_id: id, status: status });
        if (data.success) {
            showStatus('Status updated to ' + status, 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function changeMemberRole(gId, uId, role) {
        var data = await apiPost('update_member', { group_id: gId, user_id: uId, role: role });
        if (data.success) {
            showStatus('Role changed to ' + data.role, 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function changeMemberStatus(gId, uId, status) {
        var data = await apiPost('update_member', { group_id: gId, user_id: uId, status: status });
        if (data.success) {
            showStatus(status === 'active' ? 'Member reactivated' : 'Member deactivated', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function removeMember(gId, uId) {
        if (!confirm('Remove this member from the group permanently?')) return;
        var data = await apiPost('update_member', { group_id: gId, user_id: uId, remove: true });
        if (data.success) {
            showStatus('Member removed', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }


    async function archiveGroup(id) {
        if (!confirm('Archive this group? This locks the final crystallization as the definitive result. You can reopen later if needed.')) return;
        var data = await apiPost('update_group', { group_id: id, status: 'archived' });
        if (data.success) {
            showStatus('Group archived.', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function sendInvites(gId) {
        var textarea = document.getElementById('inviteEmails');
        var emails = textarea.value.trim();
        if (!emails) { showStatus('Enter at least one email address', 'error'); return; }

        var resultsEl = document.getElementById('inviteResults');
        resultsEl.innerHTML = '<div style="color:#aaa;font-size:0.85rem;">Sending invites...</div>';

        var data = await apiPost('invite_to_group', { group_id: gId, emails: emails });
        if (!data.success) {
            resultsEl.innerHTML = '<div style="color:#ef9a9a;">' + escHtml(data.error) + '</div>';
            return;
        }

        var statusLabels = {
            invited: { color: '#81c784', label: 'Invited' },
            invalid_email: { color: '#ef9a9a', label: 'Invalid email' },
            not_verified: { color: '#ffcc80', label: 'Email not verified' },
            already_member: { color: '#ffcc80', label: 'Already a member' },
            already_invited: { color: '#ffcc80', label: 'Already invited' }
        };

        var html = '';
        data.results.forEach(function(r) {
            var info = statusLabels[r.status] || { color: '#aaa', label: r.status };
            var extra = '';
            if (r.status === 'invited' && r.mail_sent === false) extra = ' <span style="color:#ef9a9a;">(email failed)</span>';
            if (r.status === 'invited' && r.new_user) extra += ' <span style="color:#4fc3f7;font-size:0.8rem;">(new — account created on accept)</span>';
            html += '<div style="font-size:0.85rem;padding:3px 0;">' +
                '<span style="color:' + info.color + ';">' + info.label + '</span> — ' +
                escHtml(r.email) + extra +
            '</div>';
        });

        html += '<div style="margin-top:6px;font-size:0.8rem;color:#aaa;">' +
            data.invited_count + ' invited, ' + data.error_count + ' skipped</div>';

        resultsEl.innerHTML = html;
        if (data.invited_count > 0) {
            textarea.value = '';
            loadInvites(gId);
        }
    }

    async function loadInvites(gId) {
        var el = document.getElementById('inviteList');
        if (!el) return;

        var data = await apiGet('get_invites', { group_id: gId });
        if (!data.success) {
            el.innerHTML = '<div class="empty">' + escHtml(data.error) + '</div>';
            return;
        }

        if (data.invites.length === 0) {
            el.innerHTML = '<div class="empty">No invitations sent yet.</div>';
            return;
        }

        var statusStyles = {
            pending: 'background:rgba(255,152,0,0.2);color:#ffb74d;',
            accepted: 'background:rgba(76,175,80,0.2);color:#81c784;',
            declined: 'background:rgba(244,67,54,0.2);color:#ef9a9a;',
            expired: 'background:rgba(158,158,158,0.2);color:#bbb;'
        };

        var html = '';
        data.invites.forEach(function(inv) {
            var style = statusStyles[inv.status] || 'color:#aaa;';
            html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.85rem;flex-wrap:wrap;">' +
                '<span style="color:#eee;min-width:180px;">' + escHtml(inv.email) +
                    ' <span onclick="copyToClip(\'' + escHtml(inv.email).replace(/'/g, "\\'") + '\')" style="cursor:pointer;opacity:0.5;font-size:0.75rem;" title="Copy email">📋</span>' +
                '</span>' +
                '<span style="padding:2px 8px;border-radius:8px;font-size:0.75rem;font-weight:600;' + style + '">' + inv.status + '</span>' +
                '<span style="color:#888;font-size:0.75rem;">by ' + escHtml(inv.invited_by_name) + '</span>' +
                '<span style="color:#666;font-size:0.7rem;">' + inv.created_at.substring(0, 16) + '</span>' +
            '</div>';
        });

        el.innerHTML = html;
    }

    loadGroupDetail();
    <?php endif; ?>

    function copyToClip(text) {
        navigator.clipboard.writeText(text).then(function() {
            showStatus('Copied: ' + text, 'success');
        });
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    </script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
