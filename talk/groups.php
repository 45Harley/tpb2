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

$groupId = (int)($_GET['id'] ?? 0);
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
            </div>
        </header>

        <div id="statusMsg"></div>

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
        var roleBadge = g.user_role ? '<span class="badge ' + g.user_role + '">' + g.user_role + '</span>' : '';

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
            html += '<a href="brainstorm.php?group=' + g.id + '" class="btn btn-primary">🧠 Brainstorm in this group</a>';
        }
        if (!isMember && g.access_level !== 'closed') {
            html += '<button class="btn btn-primary" onclick="joinGroup(' + g.id + ')">Join Group</button>';
        }
        if (isMember && !isFacilitator) {
            html += '<button class="btn btn-danger" onclick="leaveGroup(' + g.id + ')">Leave</button>';
        }
        if (isFacilitator && (g.status === 'active' || g.status === 'crystallizing')) {
            html += '<button class="btn btn-secondary" onclick="runGatherer(' + g.id + ')">🔗 Run Gatherer</button>';
            html += '<button class="btn btn-secondary" onclick="crystallize(' + g.id + ')">💎 Crystallize</button>';
        }
        if (isFacilitator && g.status === 'forming') {
            html += '<button class="btn btn-secondary" onclick="updateStatus(' + g.id + ', \'active\')">Activate Group</button>';
        }
        html += '</div>';

        // Members
        html += '<div class="section" style="margin-top:1.5rem;"><h2>Members</h2><div class="members-list">';
        members.forEach(function(m) {
            html += '<div class="member-chip">' + escHtml(m.first_name || 'User') +
                ' <span class="badge ' + m.role + '">' + m.role + '</span></div>';
        });
        html += '</div></div>';

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

        // Ideas feed
        html += '<div class="section"><h2>Shareable Ideas (' + ideas.length + ')</h2><div class="idea-feed">';
        if (ideas.length > 0) {
            ideas.forEach(function(idea) {
                var clerkBadge = idea.clerk_key ? ' <span style="background:rgba(124,77,255,0.2);color:#b388ff;padding:1px 6px;border-radius:8px;font-size:0.65rem;font-weight:600;text-transform:uppercase;">' + escHtml(idea.clerk_key) + '</span>' : '';
                html += '<div class="idea">' +
                    '<div class="author">' + (idea.clerk_key ? 'AI' : escHtml(idea.user_first_name || 'Anonymous')) + clerkBadge + '</div>' +
                    '<div class="content">' + escHtml(idea.content).substring(0, 300) + (idea.content.length > 300 ? '...' : '') + '</div>' +
                    '<div class="idea-meta">' +
                        '<span>' + idea.category + '</span>' +
                        '<span>' + idea.status + '</span>' +
                        (idea.link_count > 0 ? '<span>🔗 ' + idea.link_count + ' links</span>' : '') +
                    '</div>' +
                '</div>';
            });
        } else {
            html += '<div class="empty">No shareable ideas yet. Members need to mark ideas as shareable.</div>';
        }
        html += '</div></div>';

        el.innerHTML = html;
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

    async function crystallize(id) {
        if (!confirm('Crystallize this group into a proposal? This will change the group status.')) return;
        showStatus('Crystallizing...', 'success');
        var data = await apiPost('crystallize', { group_id: id });
        if (data.success) {
            showStatus('Proposal created! Idea #' + data.idea_id + ' — ' + data.file_path, 'success');
            loadGroupDetail();
        } else {
            showStatus(data.error, 'error');
        }
    }

    loadGroupDetail();
    <?php endif; ?>

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    </script>
</body>
</html>
