<?php
/**
 * Talk Stream — Reusable Include
 * ==============================
 * Embeds a Talk group's stream on any page.
 *
 * Required variables before including:
 *   $pdo        — PDO connection to sandge5_tpb2
 *   $dbUser     — from getUser($pdo) or false
 *   $isLoggedIn — (bool)$dbUser
 *
 * Optional variables:
 *   $talkStreamGroup    — group name to look up (default: 'The Fight')
 *   $talkStreamScope    — scope filter (default: 'federal')
 *   $talkStreamTitle    — section title (default: 'Community Stream')
 *   $talkStreamSubtitle — subtitle text (default: 'Evidence. Ideas. Action...')
 *   $talkStreamPlaceholder — textarea placeholder
 */

// Defaults
$talkStreamGroup       = $talkStreamGroup ?? 'The Fight';
$talkStreamScope       = $talkStreamScope ?? 'federal';
$talkStreamTitle       = $talkStreamTitle ?? 'Community Stream';
$talkStreamSubtitle    = $talkStreamSubtitle ?? 'Evidence. Ideas. Action. A debate where everyone wins.';
$talkStreamPlaceholder = $talkStreamPlaceholder ?? 'What does the Golden Rule demand right now?';

// Look up group
$_tsGroup = $pdo->prepare("SELECT id FROM idea_groups WHERE name = ? AND scope = ? LIMIT 1");
$_tsGroup->execute([$talkStreamGroup, $talkStreamScope]);
$_tsRow = $_tsGroup->fetch();
$_tsGroupId = $_tsRow ? (int)$_tsRow['id'] : 0;

if (!$_tsGroupId) return; // No group found, silently skip

// Check membership
$_tsMember = false;
$_tsUserId = $dbUser ? (int)$dbUser['user_id'] : null;
if ($isLoggedIn && $_tsGroupId) {
    $_tsStmt = $pdo->prepare("SELECT role FROM idea_group_members WHERE group_id = ? AND user_id = ? AND status = 'active'");
    $_tsStmt->execute([$_tsGroupId, $_tsUserId]);
    $_tsMember = (bool)$_tsStmt->fetch();
}

// User JSON for JS
$_tsUserJson = $dbUser ? json_encode([
    'user_id' => (int)$dbUser['user_id'],
    'display_name' => getDisplayName($dbUser),
    'identity_level_id' => (int)($dbUser['identity_level_id'] ?? 1)
]) : 'null';

// Unique prefix for DOM IDs (allows multiple streams on one page)
$_tsPrefix = 'ts' . $_tsGroupId;
?>

<style>
/* ── Talk Stream ── */
.talk-stream-section { margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #333; }
.talk-stream-section .ts-title { color: #d4af37; font-size: 1.3rem; text-align: center; margin-bottom: 0.25rem; }
.talk-stream-section .ts-subtitle { color: #888; font-size: 0.85rem; text-align: center; margin-bottom: 1.25rem; }
.talk-stream-section .ts-input-area {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1rem; margin-bottom: 1rem; position: relative;
}
.talk-stream-section .ts-input-row { display: flex; gap: 8px; align-items: flex-end; }
.talk-stream-section .ts-input-row textarea {
    flex: 1; min-height: 56px; max-height: 160px; padding: 10px 12px;
    border: 1px solid #444; border-radius: 8px; background: #0a0a0f;
    color: #eee; font-family: inherit; font-size: 0.9rem; resize: none; line-height: 1.4;
}
.talk-stream-section .ts-input-row textarea:focus { outline: none; border-color: #d4af37; }
.talk-stream-section .ts-input-row textarea::placeholder { color: #666; }
.talk-stream-section .ts-send-btn {
    width: 42px; height: 42px; border: none; border-radius: 8px; cursor: pointer;
    font-size: 1.1rem; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; background: linear-gradient(145deg, #d4af37, #b8962e);
    color: #000; font-weight: 600; transition: all 0.2s;
}
.talk-stream-section .ts-send-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(212,175,55,0.3); }
.talk-stream-section .ts-send-btn:disabled { background: #333; color: #666; cursor: not-allowed; transform: none; box-shadow: none; }
.talk-stream-section .ts-char-counter { text-align: right; font-size: 0.7rem; color: #666; padding: 2px 0 0; }
.talk-stream-section .ts-char-counter.warn { color: #ff9800; }
.talk-stream-section .ts-char-counter.over { color: #ef5350; }
.talk-stream-section .ts-login-prompt {
    background: #1a1a2e; border: 1px solid #d4af37; border-radius: 8px;
    padding: 1rem; text-align: center; color: #aaa; font-size: 0.9rem; margin-bottom: 1rem;
}
.talk-stream-section .ts-login-prompt a { color: #d4af37; }
.talk-stream-section .ts-cards { max-width: 100%; }
.talk-stream-section .ts-empty { text-align: center; padding: 2rem 1rem; color: #666; font-size: 0.9rem; }
.talk-stream-section .ts-card {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 12px 14px; margin-bottom: 10px; border-left: 3px solid #d4af37;
    animation: tsFadeIn 0.3s ease;
}
@keyframes tsFadeIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: translateY(0); } }
.talk-stream-section .ts-card .fc-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 6px; font-size: 0.8rem;
}
.talk-stream-section .ts-card .fc-author { color: #d4af37; font-weight: 600; }
.talk-stream-section .ts-card .fc-time { color: #666; }
.talk-stream-section .ts-card .fc-id { color: #888; font-size: 0.75rem; cursor: pointer; margin-right: 6px; }
.talk-stream-section .ts-card .fc-id:hover { color: #eee; text-decoration: underline; }
.talk-stream-section .ts-card .fc-content { font-size: 0.9rem; line-height: 1.5; word-break: break-word; color: #ddd; }
.talk-stream-section .ts-card .fc-content a { color: #4fc3f7; }
.talk-stream-section .ts-card .fc-content a:hover { text-decoration: underline; }
.talk-stream-section .ts-card .fc-content .yt-embed {
    position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;
    margin: 8px 0; border-radius: 6px;
}
.talk-stream-section .ts-card .fc-content .yt-embed iframe {
    position: absolute; top: 0; left: 0; width: 100%; height: 100%;
    border: none; border-radius: 6px;
}
.talk-stream-section .ts-card .fc-footer {
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 8px; font-size: 0.75rem;
}
.talk-stream-section .ts-card .fc-tags { display: flex; gap: 4px; flex-wrap: wrap; }
.talk-stream-section .ts-card .fc-tag {
    display: inline-block; padding: 1px 7px; border-radius: 8px; font-size: 0.7rem;
    background: rgba(212,175,55,0.12); color: #d4af37;
}
.talk-stream-section .ts-card .fc-actions { display: flex; gap: 6px; align-items: center; }
.talk-stream-section .ts-vote-btn {
    background: none; border: 1px solid rgba(255,255,255,0.1); border-radius: 6px;
    padding: 2px 8px; cursor: pointer; font-size: 0.8rem; color: #aaa;
    transition: all 0.15s; display: inline-flex; align-items: center; gap: 3px;
}
.talk-stream-section .ts-vote-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
.talk-stream-section .ts-vote-btn.active-agree { border-color: #4caf50; color: #4caf50; background: rgba(76,175,80,0.1); }
.talk-stream-section .ts-vote-btn.active-disagree { border-color: #ef5350; color: #ef5350; background: rgba(239,83,80,0.1); }
.talk-stream-section .ts-vote-btn .count { font-size: 0.75rem; color: #bbb; }
.talk-stream-section .ts-reply-btn {
    background: none; border: none; color: #888; cursor: pointer;
    font-size: 0.8rem; padding: 2px 6px; border-radius: 4px;
}
.talk-stream-section .ts-reply-btn:hover { color: #eee; background: rgba(255,255,255,0.08); }
.ts-toast {
    position: fixed; top: 20px; right: 20px; padding: 0.5rem 1rem; border-radius: 6px;
    font-weight: 600; font-size: 0.85rem; z-index: 1000; pointer-events: none;
    opacity: 0; transition: opacity 0.3s;
}
.ts-toast.show { opacity: 1; }
.ts-toast.success { background: #1a3a1a; color: #4caf50; border: 1px solid #2a4a2a; }
.ts-toast.error { background: #3a1a1a; color: #e63946; border: 1px solid #4a2a2a; }
@media (max-width: 600px) {
    .talk-stream-section .ts-input-row textarea { min-height: 48px; }
}
</style>

<!-- Talk Stream: <?= htmlspecialchars($talkStreamGroup) ?> -->
<div class="talk-stream-section" id="<?= $_tsPrefix ?>-stream">
    <h2 class="ts-title"><?= htmlspecialchars($talkStreamTitle) ?></h2>
    <p class="ts-subtitle"><?= htmlspecialchars($talkStreamSubtitle) ?></p>

    <?php if ($isLoggedIn && ($dbUser['identity_level_id'] ?? 1) >= 2): ?>
    <div class="ts-input-area">
        <div style="position:absolute;left:-9999px;">
            <input type="text" id="<?= $_tsPrefix ?>-hp" tabindex="-1" autocomplete="off">
        </div>
        <div class="ts-input-row">
            <textarea id="<?= $_tsPrefix ?>-input" placeholder="<?= htmlspecialchars($talkStreamPlaceholder) ?>" rows="2" maxlength="2000"></textarea>
            <button class="ts-send-btn" id="<?= $_tsPrefix ?>-send" title="Send">&#x27a4;</button>
        </div>
        <div class="ts-char-counter" id="<?= $_tsPrefix ?>-counter">0 / 2,000</div>
    </div>
    <?php elseif (!$isLoggedIn): ?>
    <div class="ts-login-prompt">
        <a href="/join.php">Join</a> or <a href="/login.php">log in</a> to share your thoughts.
    </div>
    <?php else: ?>
    <div class="ts-login-prompt">
        <a href="/verify-email.php">Verify your email</a> to participate in the discussion.
    </div>
    <?php endif; ?>

    <div class="ts-cards" id="<?= $_tsPrefix ?>-cards">
        <div class="ts-empty" id="<?= $_tsPrefix ?>-empty">Loading...</div>
    </div>
</div>
<div class="ts-toast" id="<?= $_tsPrefix ?>-toast"></div>

<script>
(function() {
    var P = '<?= $_tsPrefix ?>';
    var GROUP_ID = <?= $_tsGroupId ?>;
    var currentUser = <?= $_tsUserJson ?>;
    var isMember = <?= $_tsMember ? 'true' : 'false' ?>;
    var sessionId = sessionStorage.getItem('tpb_session');
    if (!sessionId) { sessionId = crypto.randomUUID(); sessionStorage.setItem('tpb_session', sessionId); }
    var formLoadTime = Math.floor(Date.now() / 1000);

    var stream = document.getElementById(P + '-cards');
    var streamEmpty = document.getElementById(P + '-empty');
    var inputEl = document.getElementById(P + '-input');
    var sendBtn = document.getElementById(P + '-send');
    var charCounter = document.getElementById(P + '-counter');
    var toastEl = document.getElementById(P + '-toast');

    var loadedIdeas = [];
    var pollTimer = null;
    var isSubmitting = false;
    var isPolling = false;

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatTime(ds) {
        if (!ds) return '';
        var d = new Date(ds.replace(' ', 'T'));
        var diff = (new Date() - d) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function showToast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'ts-toast show ' + type;
        clearTimeout(toastEl._t);
        toastEl._t = setTimeout(function() { toastEl.className = 'ts-toast'; }, 3000);
    }

    function transformContent(text) {
        var html = escHtml(text);
        html = html.replace(/https?:\/\/(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})(?:&amp;[^\s]*)*/g,
            '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" allowfullscreen loading="lazy"></iframe></div>');
        html = html.replace(/https?:\/\/youtu\.be\/([a-zA-Z0-9_-]{11})/g,
            '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" allowfullscreen loading="lazy"></iframe></div>');
        html = html.replace(/#threat:(\d+)/g, '<a href="/elections/threats.php#threat-$1">Threat #$1</a>');
        html = html.replace(/(^|[\s>])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
        return html;
    }

    function renderCard(idea) {
        var card = document.createElement('div');
        card.className = 'ts-card';
        card.id = P + '-idea-' + idea.id;
        card.dataset.createdAt = idea.created_at;

        var isOwn = currentUser && idea.user_id == currentUser.user_id;
        var authorName = idea.author_display || (isOwn ? 'You' : 'Citizen');

        var idBadge = '<span class="fc-id" onclick="window._tsReply(\'' + P + '\',' + idea.id + ')" title="Reply to #' + idea.id + '">#' + idea.id + '</span>';
        var header = '<div class="fc-header"><span class="fc-author">' + idBadge + escHtml(authorName) + '</span><span class="fc-time" title="' + escHtml(idea.created_at) + '">' + formatTime(idea.created_at) + '</span></div>';
        var content = '<div class="fc-content">' + transformContent(idea.content) + '</div>';

        var tagsHtml = '';
        if (idea.tags) {
            idea.tags.split(',').forEach(function(t) {
                t = t.trim();
                if (t) tagsHtml += '<span class="fc-tag">' + escHtml(t) + '</span>';
            });
        }

        var voteHtml = '';
        if (!idea.clerk_key) {
            var agreeA = idea.user_vote === 'agree' ? ' active-agree' : '';
            var disagreeA = idea.user_vote === 'disagree' ? ' active-disagree' : '';
            if (currentUser) {
                voteHtml = '<button class="ts-vote-btn' + agreeA + '" onclick="window._tsVote(\'' + P + '\',' + idea.id + ',\'agree\')">&#x1f44d; <span class="count">' + (idea.agree_count || 0) + '</span></button>' +
                           '<button class="ts-vote-btn' + disagreeA + '" onclick="window._tsVote(\'' + P + '\',' + idea.id + ',\'disagree\')">&#x1f44e; <span class="count">' + (idea.disagree_count || 0) + '</span></button>' +
                           '<button class="ts-reply-btn" onclick="window._tsReply(\'' + P + '\',' + idea.id + ')" title="Reply">Reply</button>';
            } else {
                voteHtml = '<span style="font-size:0.8rem;color:#888;">&#x1f44d; ' + (idea.agree_count || 0) + ' &middot; &#x1f44e; ' + (idea.disagree_count || 0) + '</span>';
            }
        }

        card.innerHTML = header + content + '<div class="fc-footer"><div class="fc-tags">' + tagsHtml + '</div><div class="fc-actions">' + voteHtml + '</div></div>';
        return card;
    }

    async function loadIdeas() {
        try {
            var resp = await fetch('/talk/api.php?action=history&group_id=' + GROUP_ID + '&include_chat=1&limit=30');
            var data = await resp.json();
            if (!data.success) { streamEmpty.textContent = data.error || 'Could not load stream'; return; }
            stream.innerHTML = ''; stream.appendChild(streamEmpty); loadedIdeas = [];
            if (data.ideas.length === 0) {
                streamEmpty.textContent = 'No posts yet. Be the first to start the conversation.';
                streamEmpty.style.display = 'block'; return;
            }
            streamEmpty.style.display = 'none';
            data.ideas.forEach(function(idea) { stream.appendChild(renderCard(idea)); loadedIdeas.push(idea); });
        } catch (e) { streamEmpty.textContent = 'Network error loading stream'; streamEmpty.style.display = 'block'; }
    }

    function startPolling() { stopPolling(); pollTimer = setInterval(pollForNew, 8000); }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    async function pollForNew() {
        if (isPolling || document.hidden) return;
        isPolling = true;
        var newest = loadedIdeas.length ? loadedIdeas[0].created_at : null;
        if (!newest) { isPolling = false; return; }
        try {
            var resp = await fetch('/talk/api.php?action=history&group_id=' + GROUP_ID + '&include_chat=1&since=' + encodeURIComponent(newest) + '&limit=20');
            var data = await resp.json();
            if (data.success && data.ideas.length > 0) {
                data.ideas.forEach(function(idea) {
                    if (loadedIdeas.some(function(i) { return i.id === idea.id; })) return;
                    streamEmpty.style.display = 'none';
                    stream.insertBefore(renderCard(idea), stream.firstChild);
                    loadedIdeas.unshift(idea);
                });
            }
        } catch (e) {}
        isPolling = false;
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) stopPolling(); else { pollForNew(); startPolling(); }
    });

    async function ensureMember() {
        if (isMember) return true;
        try {
            var resp = await fetch('/talk/api.php?action=join_group', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: GROUP_ID })
            });
            var data = await resp.json();
            if (data.success) { isMember = true; return true; }
            showToast(data.error || 'Could not join', 'error'); return false;
        } catch (e) { showToast('Network error', 'error'); return false; }
    }

    async function submitPost() {
        if (!inputEl || isSubmitting) return;
        var content = inputEl.value.trim();
        if (!content) return;
        if (!(await ensureMember())) return;

        isSubmitting = true; sendBtn.disabled = true; sendBtn.textContent = '...';
        try {
            var resp = await fetch('/talk/api.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: content, source: 'web', session_id: sessionId, group_id: GROUP_ID,
                    auto_classify: true, website_url: (document.getElementById(P + '-hp') || {}).value || '',
                    _form_load_time: formLoadTime
                })
            });
            var data = await resp.json();
            if (data.success && data.idea) {
                inputEl.value = ''; inputEl.style.height = 'auto'; updateCounter();
                streamEmpty.style.display = 'none';
                stream.insertBefore(renderCard(data.idea), stream.firstChild);
                loadedIdeas.unshift(data.idea);
            } else { showToast(data.error || 'Post failed', 'error'); }
        } catch (e) { showToast('Network error', 'error'); }
        isSubmitting = false; sendBtn.disabled = false; sendBtn.textContent = '\u27A4';
    }

    // Global handlers (namespaced by prefix)
    if (!window._tsStreams) window._tsStreams = {};
    window._tsStreams[P] = { loadedIdeas: loadedIdeas, renderCard: renderCard, showToast: showToast, inputEl: inputEl };

    window._tsVote = window._tsVote || async function(prefix, ideaId, voteType) {
        var s = window._tsStreams[prefix]; if (!s) return;
        if (!currentUser) { s.showToast('Log in to vote', 'error'); return; }
        try {
            var resp = await fetch('/talk/api.php?action=vote', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, vote_type: voteType })
            });
            var data = await resp.json();
            if (data.success) {
                var idea = s.loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea) {
                    idea.agree_count = data.agree_count; idea.disagree_count = data.disagree_count; idea.user_vote = data.user_vote;
                    var old = document.getElementById(prefix + '-idea-' + ideaId);
                    if (old) old.replaceWith(s.renderCard(idea));
                }
            } else { s.showToast(data.error || 'Vote failed', 'error'); }
        } catch (e) { s.showToast('Network error', 'error'); }
    };

    window._tsReply = window._tsReply || function(prefix, ideaId) {
        var s = window._tsStreams[prefix]; if (!s || !s.inputEl) return;
        var pre = 're: #' + ideaId + ' - ';
        if (s.inputEl.value.indexOf(pre) === 0) { s.inputEl.focus(); return; }
        s.inputEl.value = pre; s.inputEl.focus();
        s.inputEl.setSelectionRange(pre.length, pre.length);
        s.inputEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    function updateCounter() {
        if (!inputEl || !charCounter) return;
        var len = inputEl.value.length;
        charCounter.textContent = len.toLocaleString() + ' / 2,000';
        charCounter.className = 'ts-char-counter' + (len > 1800 ? (len > 2000 ? ' over' : ' warn') : '');
    }

    if (inputEl) {
        inputEl.addEventListener('input', function() {
            this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 160) + 'px'; updateCounter();
        });
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submitPost(); }
        });
    }
    if (sendBtn) sendBtn.addEventListener('click', submitPost);

    loadIdeas();
    startPolling();
})();
</script>
