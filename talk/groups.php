<?php
/**
 * Talk Groups — /talk/groups.php
 * Browse, create, join, and manage deliberation groups (Phase 3)
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';

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
            // Check if already a member (may have joined independently)
            $stmt2 = $pdo->prepare("SELECT id FROM idea_group_members WHERE group_id = ? AND user_id = ?");
            $stmt2->execute([$invite['group_id'], $invite['user_id']]);
            if (!$stmt2->fetch()) {
                $pdo->prepare("INSERT INTO idea_group_members (group_id, user_id, role) VALUES (?, ?, 'member')")
                    ->execute([$invite['group_id'], $invite['user_id']]);
            }
            $pdo->prepare("UPDATE group_invites SET status = 'accepted', responded_at = NOW() WHERE id = ?")->execute([$invite['id']]);
            $inviteResult = [
                'type' => 'success',
                'message' => "You've joined \"{$invite['group_name']}\"!",
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <title><?= $groupId ? 'Group' : 'Groups' ?> - Talk</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }

        .container { max-width: 700px; margin: 0 auto; }

        header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }

        h1 { font-size: 1.3rem; color: #4fc3f7; }
        h2 { font-size: 1.1rem; color: #eee; margin-bottom: 0.75rem; }

        .header-links { display: flex; gap: 1rem; font-size: 0.9rem; }
        .header-links a { color: #4fc3f7; text-decoration: none; }
        .header-links a:hover { text-decoration: underline; }

        .user-status { font-size: 0.8rem; color: #81c784; text-align: right; margin-bottom: 0.75rem; }
        .user-status .dot { display: inline-block; width: 8px; height: 8px; background: #4caf50; border-radius: 50%; margin-right: 4px; }

        .section { margin-bottom: 2rem; }

        .group-card {
            background: rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border-left: 4px solid #4fc3f7;
            cursor: pointer;
            transition: background 0.2s;
        }
        .group-card:hover { background: rgba(255,255,255,0.12); }

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
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid rgba(255,255,255,0.15);
            border-radius: 8px; background: rgba(255,255,255,0.06); color: #eee;
            font-family: inherit; font-size: 0.9rem;
        }
        .form-group select option, select option { background: #1a1a2e; color: #eee; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
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
            display: flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 12px;
            background: rgba(255,255,255,0.08); font-size: 0.8rem;
        }

        .idea-feed .idea {
            background: rgba(255,255,255,0.06);
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 8px;
        }
        .idea .author { color: #4fc3f7; font-weight: 600; font-size: 0.85rem; }
        .idea .content { margin-top: 4px; font-size: 0.9rem; line-height: 1.5; }
        .idea .idea-meta { margin-top: 6px; font-size: 0.75rem; color: #999; display: flex; gap: 10px; }

        .actions { display: flex; gap: 10px; margin-top: 1rem; flex-wrap: wrap; }

        .status-msg { padding: 10px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
        .status-msg.success { background: rgba(76,175,80,0.15); color: #81c784; }
        .status-msg.error { background: rgba(244,67,54,0.15); color: #e57373; }

        .sub-groups { margin-top: 1rem; }
        .sub-group { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 6px; font-size: 0.85rem; }
        .sub-group a { color: #4fc3f7; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>👥 Groups</h1>
            <div class="header-links">
                <a href="brainstorm.php">🧠 Brainstorm</a>
                <a href="history.php">📚 History</a>
                <a href="index.php">← New thought</a>
                <a href="help.php">? Help</a>
                <a href="brainstorm.php?help">🤖 Ask AI</a>
            </div>
        </header>
<?php if ($dbUser): ?>
        <div class="user-status"><span class="dot"></span><?= htmlspecialchars(getDisplayName($dbUser)) ?></div>
<?php endif; ?>

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
                        <label>Access Level</label>
                        <select id="groupAccess">
                            <option value="observable">Observable (anyone can see, members contribute)</option>
                            <option value="open">Open (anyone can join and contribute)</option>
                            <option value="closed">Closed (invitation only)</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" onclick="createGroup()">Create Group</button>
                </div>
            <?php endif; ?>

            <div id="myGroups" class="section">
                <h2>My Groups</h2>
                <div id="myGroupsList"><div class="empty">Loading...</div></div>
            </div>

            <div id="discoverSection" class="section">
                <h2>Discover</h2>
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
        // My groups
        if (currentUserId) {
            var mine = await apiGet('list_groups', { mine: 1 });
            var myList = document.getElementById('myGroupsList');
            if (mine.success && mine.groups.length > 0) {
                myList.innerHTML = mine.groups.map(renderGroupCard).join('');
            } else {
                myList.innerHTML = '<div class="empty">No groups yet. Create one!</div>';
            }
        } else {
            document.getElementById('myGroups').style.display = 'none';
        }

        // Discover
        var all = await apiGet('list_groups', {});
        var discoverList = document.getElementById('discoverList');
        var discoverGroups = all.success ? all.groups.filter(function(g) { return !g.user_role; }) : [];
        if (discoverGroups.length > 0) {
            discoverList.innerHTML = discoverGroups.map(renderGroupCard).join('');
        } else {
            discoverList.innerHTML = '<div class="empty">No groups to discover yet.</div>';
        }
    }

    function renderGroupCard(g) {
        var tags = g.tags ? g.tags.split(',').map(function(t) {
            return '<span class="tag">' + t.trim() + '</span>';
        }).join('') : '';
        var roleLabels = { facilitator: '🎯 Group Facilitator', member: '💬 Group Member', observer: '👁 Group Observer' };
        var roleBadge = g.user_role ? '<span class="badge ' + g.user_role + '">' + (roleLabels[g.user_role] || g.user_role) + '</span>' : '';

        return '<div class="group-card" onclick="location.href=\'?id=' + g.id + '\'">' +
            '<div class="name">' + escHtml(g.name) + ' ' + roleBadge + '</div>' +
            (g.description ? '<div class="desc">' + escHtml(g.description) + '</div>' : '') +
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

        var data = await apiPost('create_group', {
            name: name,
            description: document.getElementById('groupDesc').value.trim(),
            tags: document.getElementById('groupTags').value.trim(),
            access_level: document.getElementById('groupAccess').value
        });

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
        var ideas = data.ideas || [];
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
            (g.description ? '<div class="desc">' + escHtml(g.description) + '</div>' : '') +
            '<div class="meta">' +
                '<span class="badge ' + g.status + '">' + g.status + '</span>' +
                '<span>' + members.length + ' member' + (members.length != 1 ? 's' : '') + '</span>' +
                '<span>' + g.access_level + '</span>' +
                (userRole ? '<span class="badge ' + userRole + '">You: ' + userRole + '</span>' : '') +
            '</div>' +
            (tags ? '<div class="tags" style="margin-top:6px;">' + tags + '</div>' : '') +
        '</div>';

        // Actions
        html += '<div class="actions">';
        if (isMember) {
            html += '<a href="index.php?group=' + g.id + '" class="btn btn-primary">Open in Talk</a>';
        }
        if (!isMember && g.access_level !== 'closed') {
            html += '<button class="btn btn-primary" onclick="joinGroup(' + g.id + ')">Join Group</button>';
        }
        if (isMember && !isFacilitator) {
            html += '<button class="btn btn-danger" onclick="leaveGroup(' + g.id + ')">Leave</button>';
        }
        if (isFacilitator && g.status === 'forming') {
            html += '<button class="btn btn-secondary" onclick="updateStatus(' + g.id + ', \'active\')">Activate Group</button>';
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
            html += '<div class="member-chip">' + escHtml(m.display_name || 'User') +
                ' <span class="badge ' + m.role + '">' + (roleLabels[m.role] || m.role) + '</span>';
            if (isFacilitator && !isMe) {
                html += ' <select onchange="changeMemberRole(' + g.id + ',' + m.user_id + ',this.value)" style="background:rgba(255,255,255,0.1);color:#eee;border:1px solid rgba(255,255,255,0.15);border-radius:4px;padding:1px 4px;font-size:0.7rem;cursor:pointer;margin-left:4px;">' +
                    '<option value="" disabled selected>...</option>' +
                    (m.role !== 'facilitator' ? '<option value="facilitator">→ Group Facilitator</option>' : '') +
                    (m.role !== 'member' ? '<option value="member">→ Group Member</option>' : '') +
                    (m.role !== 'observer' ? '<option value="observer">→ Group Observer</option>' : '') +
                    '<option value="__remove">✕ remove</option>' +
                '</select>';
            }
            html += '</div>';
        });
        html += '</div></div>';

        // Invite form (facilitator only)
        if (isFacilitator) {
            html += '<div class="section" style="margin-top:1.5rem;">' +
                '<h2>Invite Members</h2>' +
                '<div style="background:rgba(255,255,255,0.05);border-radius:10px;padding:14px;">' +
                    '<textarea id="inviteEmails" rows="3" placeholder="Enter email addresses (one per line, or comma-separated)" ' +
                        'style="width:100%;background:rgba(255,255,255,0.08);color:#eee;border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:10px;font-size:0.9rem;resize:vertical;font-family:inherit;"></textarea>' +
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

        // Ideas — link to Talk page
        html += '<div class="section"><h2>Group Ideas (' + ideas.length + ')</h2>' +
            '<a href="index.php?group=' + g.id + '" class="btn btn-secondary" style="margin-top:4px;">View ideas in Talk &rarr;</a>' +
        '</div>';

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

    async function runGatherer(id) {
        showStatus('Running gatherer...', 'success');
        var data = await apiPost('gather', { group_id: id });
        if (data.success) {
            var linkCount = data.actions.filter(function(a) { return a.action === 'LINK' && a.success; }).length;
            var summaryCount = data.actions.filter(function(a) { return a.action === 'SUMMARIZE' && a.success; }).length;
            showStatus('Gatherer found ' + linkCount + ' connections and created ' + summaryCount + ' digest(s)', 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    async function changeMemberRole(gId, uId, value) {
        if (value === '__remove') {
            if (!confirm('Remove this member from the group?')) { loadGroupDetail(); return; }
            var data = await apiPost('update_member', { group_id: gId, user_id: uId, remove: true });
        } else {
            var data = await apiPost('update_member', { group_id: gId, user_id: uId, role: value });
        }
        if (data.success) {
            showStatus(data.action === 'removed' ? 'Member removed' : 'Role changed to ' + data.role, 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
            loadGroupDetail();
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

    async function crystallize(id) {
        if (!confirm('Crystallize this group into a proposal? You can re-crystallize until the group is archived.')) return;
        showStatus('Crystallizing... this may take a moment.', 'success');
        var data = await apiPost('crystallize', { group_id: id });
        if (data.success) {
            showStatus('Proposal created! Idea #' + data.idea_id + ' — ' + data.file_path, 'success');
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
            not_found: { color: '#ef9a9a', label: 'No account found' },
            not_verified: { color: '#ffcc80', label: 'Email not verified' },
            already_member: { color: '#ffcc80', label: 'Already a member' },
            already_invited: { color: '#ffcc80', label: 'Already invited' }
        };

        var html = '';
        data.results.forEach(function(r) {
            var info = statusLabels[r.status] || { color: '#aaa', label: r.status };
            html += '<div style="font-size:0.85rem;padding:3px 0;">' +
                '<span style="color:' + info.color + ';">' + info.label + '</span> — ' +
                escHtml(r.email) +
                (r.status === 'invited' && r.mail_sent === false ? ' <span style="color:#ef9a9a;">(email failed)</span>' : '') +
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
</body>
</html>
