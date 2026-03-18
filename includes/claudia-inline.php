<?php
/**
 * Claudia Inline — Discuss & Draft + Summary
 * ============================================
 * Reusable component embedded on Talk, Fight, Town, and Group pages.
 *
 * Required from calling page:
 *   $pdo        — PDO connection
 *   $dbUser     — from getUser($pdo) or false
 *   $isLoggedIn — (bool)$dbUser
 *
 * Config: set $claudiaInlineConfig before requiring this file.
 *   'title'         => heading text (default: 'Discuss & Draft')
 *   'placeholder'   => prompt placeholder
 *   'group'         => group name filter (e.g. 'The Fight') — resolves to group_id
 *   'group_id'      => direct group_id (overrides 'group' name lookup)
 *   'group_name'    => group display name (for title)
 *   'group_mode'    => bool — show ALL categories in summary, not just mandates
 *   'is_standard'   => bool — civic topic group (open to all verified users)
 *   'group_status'  => group status string (e.g. 'active', 'archived')
 *   'user_role'     => user's role in the group ('facilitator', 'member', 'observer', null)
 *   'default_scope' => pre-checked scope ('federal', 'state', 'town', or null)
 */

$_ciDefaults = [
    'title'         => 'Discuss & Draft',
    'placeholder'   => "What matters most to you?",
    'group'         => null,
    'group_id'      => null,
    'group_name'    => null,
    'group_mode'    => false,
    'is_standard'   => false,
    'group_status'  => null,
    'user_role'     => null,
    'default_scope' => null,
];
$_ci = array_merge($_ciDefaults, $claudiaInlineConfig ?? []);

$_ciUserLevel = $dbUser ? (int)($dbUser['identity_level_id'] ?? 1) : 0;
$_ciCanPost = $isLoggedIn && $_ciUserLevel >= 2;

// Group access rules override
$_ciGroupMode = (bool)$_ci['group_mode'];
$_ciGroupId = $_ci['group_id'] ? (int)$_ci['group_id'] : null;

if ($_ciGroupMode && $_ciGroupId) {
    $groupStatus = $_ci['group_status'] ?? 'active';
    $isStandard = (bool)$_ci['is_standard'];
    $userRole = $_ci['user_role'];

    if ($groupStatus === 'archived') {
        // Archived: no drafting for anyone
        $_ciCanPost = false;
    } elseif ($isStandard) {
        // Civic topic: any verified user can draft
        $_ciCanPost = $isLoggedIn && $_ciUserLevel >= 2;
    } else {
        // User-created group: members and facilitators only
        $_ciCanPost = $isLoggedIn && $_ciUserLevel >= 2 && in_array($userRole, ['member', 'facilitator']);
    }
}

// Resolve group name → group_id for mandate saves (if not already set)
if (!$_ciGroupId && !empty($_ci['group']) && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT id FROM idea_groups WHERE name = ? LIMIT 1");
    $stmt->execute([$_ci['group']]);
    $_ciGroupId = $stmt->fetchColumn() ?: null;
    if ($_ciGroupId) $_ciGroupId = (int)$_ciGroupId;
}

// User geo data
$_ciUserStateId  = $dbUser ? ($dbUser['current_state_id'] ?? null) : null;
$_ciUserTownId   = $dbUser ? ($dbUser['current_town_id'] ?? null) : null;
$_ciUserDistrict = $dbUser ? ($dbUser['us_congress_district'] ?? null) : null;
$_ciUserTownName  = $dbUser ? ($dbUser['town_name'] ?? null) : null;
$_ciUserStateName = $dbUser ? ($dbUser['state_name'] ?? null) : null;
$_ciUserStateAbbr = $dbUser ? ($dbUser['state_abbrev'] ?? null) : null;

// Load CSS once per page
if (!defined('CLAUDIA_INLINE_LOADED')) {
    define('CLAUDIA_INLINE_LOADED', true);
    $_ciCssVer = file_exists(__DIR__ . '/../assets/claudia/claudia-inline.css')
        ? filemtime(__DIR__ . '/../assets/claudia/claudia-inline.css') : 0;
    echo '<link rel="stylesheet" href="/assets/claudia/claudia-inline.css?v=' . $_ciCssVer . '">' . "\n";
}
?>

<div class="mandate-wrap" id="top">

    <!-- Header -->
    <div class="mandate-header">
        <h1><?= htmlspecialchars($_ci['title']) ?></h1>
        <div class="mandate-header-links">
            <a href="/help/guide.php?flow=mandate-chat" class="mandate-help-btn" title="Learn how the Discuss & Draft workflow works">&#x1F393; How It Works</a>
<?php if (!$_ciGroupMode): ?>
            <a href="/mandate-summary.php?scope=federal&value=<?= htmlspecialchars(urlencode($_ciUserDistrict ?? '')) ?>"
               class="mandate-pulse-link" title="View full statistics and topic breakdown">The People's Pulse &rarr;</a>
<?php endif; ?>
        </div>
<?php if (!$_ciGroupMode && $dbUser && ($_ciUserTownName || $_ciUserStateName || $_ciUserDistrict)): ?>
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
            <button class="delegation-popup-close" id="claudia-delegation-close" title="Close this popup">&times;</button>
        </div>
        <div id="claudia-delegation-body">
            <div class="delegation-loading">Loading...</div>
        </div>
    </div>
<?php endif; ?>

<?php if ($_ciCanPost): ?>
    <!-- Discuss & Draft -->
    <?php
    $mandateChatConfig = [
        'placeholder'   => $_ci['placeholder'],
        'group_id'      => $_ciGroupId,
        'default_scope' => $_ci['default_scope'],
    ];
    require __DIR__ . '/mandate-chat.php';
    ?>

<?php elseif (!$isLoggedIn): ?>
    <div class="mandate-auth-nudge">
        <a href="/join.php">Join</a> or <a href="/login.php">log in</a> to draft your mandate.
    </div>
<?php elseif ($_ciUserLevel < 2): ?>
    <div class="mandate-auth-nudge">
        <a href="/profile.php#email">Verify your email</a> to draft your mandate.
    </div>
<?php endif; ?>

<?php if ($_ciGroupMode): ?>
    <!-- Group Stream -->
    <div class="mandate-summary" id="claudia-inline-summary">
        <div class="mandate-summary-header">
            <h3 id="claudia-inline-summary-title" title="All items from this group">Group: <?= htmlspecialchars($_ci['group_name'] ?? 'Unknown') ?></h3>
        </div>
        <div id="claudia-inline-summary-body" style="padding: 1.5rem;">
            <p>Loading group stream...</p>
        </div>
<?php if ($_ci['group_status'] !== 'archived'): ?>
        <div style="text-align:center; padding: 8px 12px; border-top: 1px solid rgba(255,255,255,0.06);">
            <a href="/talk/" style="color:#d4af37; font-size:0.8rem; text-decoration:none;"
               title="View public mandates from your area">View public mandates &rarr;</a>
        </div>
<?php endif; ?>
    </div>
<?php else: ?>
    <!-- Public Mandate Summary -->
    <div class="mandate-summary" id="claudia-inline-summary">
        <div class="mandate-summary-header">
            <h3 id="claudia-inline-summary-title" title="Community contributions from your area">View All</h3>
        </div>
        <!-- Level Filter Tabs -->
        <div class="level-tabs" id="claudia-inline-level-tabs">
            <button class="level-tab active" data-level="" title="Show all from your area">View All</button>
            <button class="level-tab" data-level="mandate-federal" title="U.S. Congress &mdash; House &amp; Senate">Federal</button>
            <button class="level-tab" data-level="mandate-state" title="State legislature &mdash; your state reps">State</button>
            <button class="level-tab" data-level="mandate-town" title="Local town government &mdash; selectmen, council">Town</button>
            <button class="level-tab" data-level="mine" title="Only mandates you have saved">My Mandates</button>
            <button class="level-tab" data-level="my-ideas" title="Your private ideas">My Ideas</button>
        </div>
        <div style="text-align:right; padding: 4px 12px;">
            <a href="/mandate-summary.php?scope=federal&value=<?= htmlspecialchars(urlencode($_ciUserDistrict ?? '')) ?>"
               style="color:#d4af37; font-size:0.8rem; text-decoration:none;"
               title="View full statistics and topic breakdown">The People's Pulse &rarr;</a>
        </div>
        <div id="claudia-inline-summary-body" style="padding: 1.5rem;">
            <p>Loading...</p>
        </div>
    </div>
<?php endif; ?>

    <!-- ── Summary JS — edit/delete/vote/tabs ──── -->
    <script>
    (function() {
        var userDistrict  = <?= json_encode($_ciUserDistrict ?: null) ?>;
        var userStateId   = <?= json_encode($_ciUserStateId ?: null) ?>;
        var userTownId    = <?= json_encode($_ciUserTownId ?: null) ?>;
        var userTownName  = <?= json_encode($_ciUserTownName ?: '') ?>;
        var userStateName = <?= json_encode($_ciUserStateAbbr ?: $_ciUserStateName ?: '') ?>;
        var userId        = <?= json_encode($dbUser ? (int)$dbUser['user_id'] : 0) ?>;
        var groupFilter   = <?= json_encode($_ci['group'] ?: null) ?>;
        var groupMode     = <?= json_encode($_ciGroupMode) ?>;
        var groupId       = <?= json_encode($_ciGroupId ?: null) ?>;
        var groupArchived = <?= json_encode(($_ci['group_status'] ?? '') === 'archived') ?>;

        var titleEl = document.getElementById('claudia-inline-summary-title');
        var bodyEl  = document.getElementById('claudia-inline-summary-body');

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function buildUrl(level) {
            var url;
            if (groupMode) {
                // Group mode: single query, all categories
                url = '/api/mandate-aggregate.php?level=group&group_id=' + encodeURIComponent(groupId);
                if (userId) url += '&viewer_user_id=' + encodeURIComponent(userId);
                return url;
            }
            if (level === 'my-ideas') {
                url = '/api/mandate-aggregate.php?level=my-ideas&user_id=' + encodeURIComponent(userId);
            } else if (level === 'mine') {
                url = '/api/mandate-aggregate.php?level=mine&user_id=' + encodeURIComponent(userId);
            } else if (level === 'all') {
                url = '/api/mandate-aggregate.php?level=all';
                if (userDistrict) url += '&district=' + encodeURIComponent(userDistrict);
                if (userStateId) url += '&state_id=' + encodeURIComponent(userStateId);
                if (userTownId) url += '&town_id=' + encodeURIComponent(userTownId);
            } else {
                url = '/api/mandate-aggregate.php?level=' + encodeURIComponent(level);
                switch (level) {
                    case 'federal':
                        if (userDistrict) url += '&district=' + encodeURIComponent(userDistrict);
                        break;
                    case 'state':
                        if (userStateId) url += '&state_id=' + encodeURIComponent(userStateId);
                        break;
                    case 'town':
                        if (userTownId) url += '&town_id=' + encodeURIComponent(userTownId);
                        break;
                }
            }
            if (userId) url += '&viewer_user_id=' + encodeURIComponent(userId);
            if (groupFilter) url += '&group=' + encodeURIComponent(groupFilter);
            return url;
        }

        function buildTitle(level) {
            switch (level) {
                case 'my-ideas':
                    return 'My Ideas';
                case 'mine':
                    return 'My Mandates';
                case 'federal':
                    return userDistrict
                        ? 'Federal &mdash; ' + escapeHtml(userDistrict)
                        : 'Federal';
                case 'state':
                    return userStateName
                        ? 'State &mdash; ' + escapeHtml(userStateName)
                        : 'State';
                case 'town':
                    return userTownName
                        ? 'Town &mdash; ' + escapeHtml(userTownName)
                        : 'Town';
                default:
                    return 'View All';
            }
        }

        function loadSummary(level) {
            if (!groupMode) titleEl.innerHTML = buildTitle(level);
            bodyEl.innerHTML = '<p style="color:#b0b0b0;">Loading...</p>';

            fetch(buildUrl(level))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || data.item_count === 0) {
                        bodyEl.innerHTML = '<p style="color:#b0b0b0;">No items yet for this scope.</p>';
                        return;
                    }

                    var html = '<p style="color:#81c784; font-size:0.95rem; margin-bottom:0.75rem;">'
                        + data.contributor_count + ' constituent'
                        + (data.contributor_count !== 1 ? 's' : '')
                        + ' ha' + (data.contributor_count !== 1 ? 've' : 's')
                        + ' spoken.</p>';

                    html += '<ol style="text-align:left; padding-left:1.5rem; margin:0;">';
                    data.items.forEach(function(item) {
                        var isOwner = item.user_id && item.user_id === userId;
                        html += '<li style="color:#ccc; margin-bottom:0.5rem;" data-idea-id="' + item.id + '">';
                        if (item.level) {
                            html += '<span style="color:#d4af37; font-size:0.75rem; text-transform:uppercase; letter-spacing:1px; margin-right:6px;">'
                                + escapeHtml(item.level) + '</span> ';
                        }
                        html += '<span class="mandate-text">' + escapeHtml(item.content) + '</span>';
                        var authorStr = '#' + item.user_id;
                        if (item.author_display) authorStr += ' ' + escapeHtml(item.author_display);
                        if (item.age_bracket) authorStr += ' (' + escapeHtml(item.age_bracket) + ')';
                        html += '<span style="color:#999; font-size:0.8rem; display:block; margin-top:2px;">&mdash; ' + authorStr + '</span>';
                        if (item.tags) {
                            html += ' <span style="color:#999; font-size:0.85rem;">('
                                + escapeHtml(item.tags) + ')</span>';
                        }
                        if (isOwner && !groupArchived) {
                            html += '<span class="mandate-owner-actions">'
                                + '<button class="mandate-edit-btn" data-id="' + item.id + '" title="Edit">&#9998;</button>'
                                + '<button class="mandate-delete-btn" data-id="' + item.id + '" title="Delete">&times;</button>'
                                + '</span>';
                        }
                        // Vote buttons + count (hidden for archived groups)
                        if (!groupArchived) {
                            var agreeActive = item.my_vote === 'agree' ? ' vote-active' : '';
                            var disagreeActive = item.my_vote === 'disagree' ? ' vote-active' : '';
                            html += '<div class="mandate-vote-row">'
                                + '<button class="mandate-vote-btn agree' + agreeActive + '" data-id="' + item.id + '" data-type="agree" title="Agree">'
                                + '&#x1F44D; <span class="vote-count">' + (item.agree_count || 0) + '</span></button>'
                                + '<button class="mandate-vote-btn disagree' + disagreeActive + '" data-id="' + item.id + '" data-type="disagree" title="Disagree">'
                                + '&#x1F44E; <span class="vote-count">' + (item.disagree_count || 0) + '</span></button>'
                                + '</div>';
                        }
                        html += '<div class="mandate-top-link"><a href="#top" title="Back to top">&uarr; Top</a></div>';
                        html += '</li>';
                    });
                    html += '</ol>';

                    bodyEl.innerHTML = html;
                })
                .catch(function() {
                    bodyEl.innerHTML = '<p style="color:#e63946;">Failed to load mandate summary.</p>';
                });
        }

        // Expose so MandateChat can refresh after saving
        window.refreshMandateSummary = loadSummary;

        // ── Initial load ──────────────────────────────────────
        var currentLevel = groupMode ? 'group' : 'all';
        loadSummary(currentLevel);

        // ── Edit / Delete / Vote handlers (delegated) ─────────
        bodyEl.addEventListener('click', function(e) {
            var editBtn = e.target.closest('.mandate-edit-btn');
            var deleteBtn = e.target.closest('.mandate-delete-btn');

            if (editBtn) {
                var li = editBtn.closest('li');
                var ideaId = editBtn.dataset.id;
                var textEl = li.querySelector('.mandate-text');
                if (!textEl || li.querySelector('.mandate-edit-input')) return;

                var input = document.createElement('textarea');
                input.className = 'mandate-edit-input';
                input.value = textEl.textContent;
                input.rows = 2;

                var saveBtn = document.createElement('button');
                saveBtn.className = 'mandate-edit-save';
                saveBtn.textContent = 'Save';

                var cancelBtn = document.createElement('button');
                cancelBtn.className = 'mandate-edit-cancel';
                cancelBtn.textContent = 'Cancel';

                var wrap = document.createElement('div');
                wrap.className = 'mandate-edit-wrap';
                wrap.appendChild(input);
                wrap.appendChild(saveBtn);
                wrap.appendChild(cancelBtn);

                textEl.style.display = 'none';
                textEl.parentNode.insertBefore(wrap, textEl.nextSibling);
                input.focus();

                saveBtn.addEventListener('click', function() {
                    var newContent = input.value.trim();
                    if (!newContent) return;
                    fetch('/talk/api.php?action=edit', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({idea_id: parseInt(ideaId), content: newContent})
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.success) {
                            loadSummary(currentLevel);
                        } else {
                            alert(data.error || 'Failed to edit');
                            wrap.remove();
                            textEl.style.display = '';
                        }
                    });
                });
                cancelBtn.addEventListener('click', function() {
                    wrap.remove();
                    textEl.style.display = '';
                });
            }

            if (deleteBtn) {
                var ideaId = deleteBtn.dataset.id;
                if (!confirm('Delete this mandate?')) return;
                fetch('/talk/api.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({idea_id: parseInt(ideaId)})
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        loadSummary(currentLevel);
                    } else {
                        alert(data.error || 'Failed to delete');
                    }
                });
            }

            // Vote handler
            var voteBtn = e.target.closest('.mandate-vote-btn');
            if (voteBtn) {
                if (!userId) { alert('Log in to vote'); return; }
                var ideaId = parseInt(voteBtn.dataset.id);
                var voteType = voteBtn.dataset.type;
                fetch('/talk/api.php?action=vote', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({idea_id: ideaId, vote_type: voteType})
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.success) {
                        var row = voteBtn.closest('.mandate-vote-row');
                        var agreeBtn = row.querySelector('.agree');
                        var disagreeBtn = row.querySelector('.disagree');
                        agreeBtn.querySelector('.vote-count').textContent = data.agree_count;
                        disagreeBtn.querySelector('.vote-count').textContent = data.disagree_count;
                        agreeBtn.classList.toggle('vote-active', data.user_vote === 'agree');
                        disagreeBtn.classList.toggle('vote-active', data.user_vote === 'disagree');
                    } else {
                        alert(data.error || 'Vote failed');
                    }
                });
            }
        });

        // ── Tab click handlers ────────────────────────────────
        var tabs = document.querySelectorAll('#claudia-inline-level-tabs .level-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                tabs.forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');

                var dataLevel = tab.dataset.level || '';
                var summaryLevel;
                switch (dataLevel) {
                    case 'mandate-federal': summaryLevel = 'federal'; break;
                    case 'mandate-state':   summaryLevel = 'state';   break;
                    case 'mandate-town':    summaryLevel = 'town';    break;
                    case 'mine':            summaryLevel = 'mine';    break;
                    case 'my-ideas':        summaryLevel = 'my-ideas'; break;
                    default:                summaryLevel = 'all';     break;
                }
                currentLevel = summaryLevel;
                loadSummary(summaryLevel);
            });
        });
    })();
    </script>

    <!-- ── Delegation Popup Logic ──────────────────────────────── -->
<?php if ($dbUser): ?>
    <script>
    (function() {
        var triggers = [
            document.getElementById('claudia-inline-geo'),
            document.getElementById('claudia-inline-summary-title')
        ];
        var popup    = document.getElementById('claudia-inline-delegation');
        var body     = document.getElementById('claudia-delegation-body');
        var closeBtn = document.getElementById('claudia-delegation-close');
        var handle   = document.getElementById('claudia-delegation-drag');
        if (!popup) return;

        var stateAbbr = <?= json_encode(strtolower($_ciUserStateAbbr ?: '')) ?>;
        var district  = <?= json_encode($_ciUserDistrict ?: '') ?>;
        var loaded = false;

        function openPopup(e) {
            e.stopPropagation();
            if (popup.classList.contains('open')) {
                popup.classList.remove('open');
                return;
            }
            var rect = e.currentTarget.getBoundingClientRect();
            popup.style.top  = (rect.bottom + 8) + 'px';
            popup.style.left = Math.max(10, rect.left) + 'px';
            popup.classList.add('open');
            if (!loaded) loadDelegation();
        }
        for (var i = 0; i < triggers.length; i++) {
            if (triggers[i]) {
                triggers[i].style.cursor = 'pointer';
                triggers[i].title = triggers[i].title || 'Click to see your elected representatives';
                triggers[i].addEventListener('click', openPopup);
            }
        }

        closeBtn.addEventListener('click', function() { popup.classList.remove('open'); });

        document.addEventListener('click', function(e) {
            if (!popup.classList.contains('open')) return;
            if (popup.contains(e.target)) return;
            for (var j = 0; j < triggers.length; j++) {
                if (triggers[j] && triggers[j].contains(e.target)) return;
            }
            popup.classList.remove('open');
        });

        function loadDelegation() {
            loaded = true;
            var url = '/api/get-delegation.php?state=' + encodeURIComponent(stateAbbr)
                    + '&district=' + encodeURIComponent(district);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var html = '';
                if (data.federal && data.federal.length) html += buildGroup('Federal', data.federal);
                if (data.state && data.state.length) html += buildGroup('State', data.state);
                if (!html) html = '<div class="delegation-loading">No representatives found.</div>';
                body.innerHTML = html;
            }).catch(function() {
                body.innerHTML = '<div class="delegation-loading">Failed to load.</div>';
            });
        }

        function buildGroup(label, officials) {
            var html = '<div class="delegation-group"><div class="delegation-group-title">' + label + '</div>';
            for (var i = 0; i < officials.length; i++) {
                var o = officials[i];
                var partyClass = (o.party || '').toLowerCase().indexOf('democrat') >= 0 ? 'dem'
                               : (o.party || '').toLowerCase().indexOf('republican') >= 0 ? 'rep' : 'ind';
                var partyShort = partyClass === 'dem' ? 'D' : partyClass === 'rep' ? 'R' : 'I';
                html += '<div class="delegation-card">';
                if (o.photo) {
                    html += '<img class="delegation-photo" src="' + escH(o.photo) + '" alt="' + escH(o.name) + '" onerror="this.outerHTML=\'<div class=delegation-photo-placeholder>&#x1F464;</div>\'">';
                } else {
                    html += '<div class="delegation-photo-placeholder">&#x1F464;</div>';
                }
                html += '<div class="delegation-info">';
                html += '<div class="delegation-name">' + escH(o.name) + '</div>';
                html += '<div class="delegation-title">' + escH(o.title) + '</div>';
                html += '<span class="delegation-party ' + partyClass + '">' + partyShort + ' — ' + escH(o.party) + '</span>';
                html += '<div class="delegation-links">';
                if (o.phone) html += '<a href="tel:' + escH(o.phone) + '">&#x1F4DE; ' + escH(o.phone) + '</a>';
                if (o.website) html += '<a href="' + escH(o.website) + '" target="_blank">&#x1F310; Website</a>';
                html += '</div></div></div>';
            }
            html += '</div>';
            return html;
        }

        function escH(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(s));
            return d.innerHTML;
        }

        // ── Draggable ──
        var isDragging = false, dragX = 0, dragY = 0;
        handle.addEventListener('mousedown', startDrag);
        handle.addEventListener('touchstart', startDragTouch, {passive: false});

        function startDrag(e) {
            isDragging = true;
            dragX = e.clientX - popup.offsetLeft;
            dragY = e.clientY - popup.offsetTop;
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', stopDrag);
            e.preventDefault();
        }
        function startDragTouch(e) {
            isDragging = true;
            var t = e.touches[0];
            dragX = t.clientX - popup.offsetLeft;
            dragY = t.clientY - popup.offsetTop;
            document.addEventListener('touchmove', onDragTouch, {passive: false});
            document.addEventListener('touchend', stopDrag);
            e.preventDefault();
        }
        function onDrag(e) {
            if (!isDragging) return;
            popup.style.left = (e.clientX - dragX) + 'px';
            popup.style.top  = (e.clientY - dragY) + 'px';
        }
        function onDragTouch(e) {
            if (!isDragging) return;
            var t = e.touches[0];
            popup.style.left = (t.clientX - dragX) + 'px';
            popup.style.top  = (t.clientY - dragY) + 'px';
            e.preventDefault();
        }
        function stopDrag() {
            isDragging = false;
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup', stopDrag);
            document.removeEventListener('touchmove', onDragTouch);
            document.removeEventListener('touchend', stopDrag);
        }
    })();
    </script>
<?php endif; ?>

</div>
