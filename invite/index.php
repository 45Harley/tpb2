<?php
/**
 * Invite a Friend — Invitor's Page
 * =================================
 * Logged-in members (identity_level >= 2) send invitations here.
 *
 * Features:
 *  - Email input with add/remove list (client-side)
 *  - Live "already a member" check via /api/check-invite-email.php
 *  - Collapsible email preview (rendered via buildInviteEmail)
 *  - Send invitations via /api/send-invite.php
 *  - Invite history table with stats
 */

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser || $dbUser['identity_level_id'] < 2) {
    header('Location: /profile.php');
    exit;
}

$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'invite';
$pageTitle = 'Invite a Friend | The People\'s Branch';

// Load invite history
$historyStmt = $pdo->prepare("
    SELECT invitee_email, status, points_awarded, created_at, joined_at
    FROM invitations WHERE invitor_user_id = ? ORDER BY created_at DESC
");
$historyStmt->execute([$dbUser['user_id']]);
$history = $historyStmt->fetchAll();

// Stats
$totalPointsFromInvites = array_sum(array_map(fn($h) => $h['points_awarded'] ? 100 : 0, $history));
$joinedCount = count(array_filter($history, fn($h) => $h['status'] === 'joined'));
$sentCount = count($history);
$acceptanceRate = $sentCount > 0 ? round(($joinedCount / $sentCount) * 100) : 0;

// Build email preview
require_once dirname(__DIR__) . '/includes/invite-email.php';
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$previewHtml = buildInviteEmail($dbUser['email'], '#', $baseUrl);

$pageStyles = <<<'CSS'
/* Invite page styles */
.invite-container {
    max-width: 700px;
    margin: 0 auto;
    padding: 2rem 1.5rem 3rem;
}

/* Header */
.invite-header {
    text-align: center;
    margin-bottom: 2rem;
}
.invite-header h1 {
    color: #d4af37;
    font-size: 1.8rem;
    margin-bottom: 0.3rem;
}
.invite-header .subtitle {
    color: #aaa;
    font-size: 1rem;
    margin-bottom: 0;
}
.invite-header .points-highlight {
    display: inline-block;
    margin-top: 0.75rem;
    background: rgba(212,175,55,0.15);
    border: 1px solid rgba(212,175,55,0.4);
    padding: 0.4rem 1rem;
    border-radius: 8px;
    color: #f5c842;
    font-weight: 600;
    font-size: 0.95rem;
}

/* How it works */
.how-it-works {
    background: #2a1d12;
    border: 1px solid #4a3525;
    border-radius: 8px;
    padding: 1.25rem;
    margin-bottom: 2rem;
}
.how-it-works h3 {
    color: #d4af37;
    font-size: 1rem;
    margin-bottom: 0.75rem;
}
.how-it-works ol {
    padding-left: 1.25rem;
    color: #bbb;
    font-size: 0.9rem;
}
.how-it-works ol li {
    margin-bottom: 0.5rem;
    line-height: 1.5;
}
.how-it-works ol li strong { color: #e0e0e0; }
.how-it-works .tip {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid #4a3525;
    color: #c8a415;
    font-size: 0.85rem;
    font-style: italic;
}

/* Form section */
.form-section {
    margin-bottom: 2rem;
}
.form-section h2 {
    color: #fff;
    font-size: 1.1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #333;
}
.email-input-row {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}
.email-input-row input {
    flex: 1;
    padding: 0.7rem 1rem;
    background: #1a1a2e;
    border: 1px solid #444;
    border-radius: 6px;
    color: #e0e0e0;
    font-size: 0.95rem;
    outline: none;
    width: auto;
}
.email-input-row input:focus {
    border-color: #d4af37;
}
.email-input-row input::placeholder { color: #666; }
.btn-add {
    padding: 0.7rem 1.25rem;
    background: #1a1a2e;
    border: 1px solid #d4af37;
    border-radius: 6px;
    color: #d4af37;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    white-space: nowrap;
}
.btn-add:hover { background: rgba(212,175,55,0.15); }

/* Email list */
.email-list-container {
    margin-bottom: 1rem;
}

/* Send button */
.btn-send {
    display: block;
    width: 100%;
    padding: 0.9rem;
    background: #c8a415;
    border: none;
    border-radius: 6px;
    color: #fff;
    font-weight: 700;
    font-size: 1.05rem;
    cursor: pointer;
    letter-spacing: 0.3px;
}
.btn-send:hover { background: #d4af37; }
.btn-send:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.btn-send:disabled:hover { background: #c8a415; }

/* Preview section */
.preview-section {
    margin-bottom: 1.5rem;
}
.preview-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #888;
    font-size: 0.9rem;
    cursor: pointer;
    margin-bottom: 0.75rem;
    padding: 0.5rem 0;
    border: none;
    background: none;
}
.preview-toggle:hover { color: #d4af37; }
.preview-toggle .arrow { font-size: 0.7rem; }
.preview-frame {
    border: 1px solid rgba(212,175,55,0.4);
    border-radius: 8px;
    overflow: hidden;
    max-height: 560px;
    overflow-y: auto;
    background: #1a1a2e;
    padding: 2px;
}
.preview-frame-label {
    display: block;
    font-size: 0.75rem;
    color: #888;
    text-align: center;
    padding: 8px 0 6px;
    background: #1a1a2e;
    border-bottom: 1px solid rgba(212,175,55,0.2);
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.preview-frame iframe {
    width: 100%;
    min-height: 600px;
    border: none;
    border-radius: 0 0 6px 6px;
}

/* History section */
.history-section {
    margin-top: 2.5rem;
}
.history-section h2 {
    color: #fff;
    font-size: 1.1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #333;
}
.history-table {
    width: 100%;
    border-collapse: collapse;
}
.history-table th {
    text-align: left;
    color: #888;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0.5rem 0.75rem;
    border-bottom: 1px solid #333;
}
.history-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #1a1a2e;
    font-size: 0.9rem;
}
.history-table .email-col { color: #88c0d0; }
.history-table .date-col { color: #888; }
.history-table .status-pending { color: #ff9800; }
.history-table .status-joined { color: #4caf50; font-weight: 600; }
.history-table .points-col { color: #f5c842; font-weight: 600; }
.history-table .points-col.none { color: #333; font-weight: normal; }

/* Stats */
.invite-stats {
    text-align: center;
    color: #888;
    font-size: 0.85rem;
    margin-top: 1rem;
}
.invite-stats strong { color: #f5c842; }

/* Empty history */
.history-empty {
    text-align: center;
    padding: 2rem;
    color: #666;
    font-size: 0.9rem;
}

/* Already-member note */
.already-member-note {
    text-align: center;
    color: #666;
    font-size: 0.8rem;
    margin-top: 0.5rem;
}

@media (max-width: 600px) {
    .invite-header h1 { font-size: 1.4rem; }
    .email-input-row { flex-direction: column; }
    .btn-add { width: 100%; text-align: center; }
    .history-table { font-size: 0.8rem; }
    .history-table th, .history-table td { padding: 0.5rem; }
}
CSS;

include dirname(__DIR__) . '/includes/header.php';
include dirname(__DIR__) . '/includes/nav.php';
?>

<div class="invite-container">

    <!-- Header -->
    <div class="invite-header">
        <h1>Invite a Friend</h1>
        <p class="subtitle">Grow the movement. One person at a time.</p>
        <div class="points-highlight">&#x2B50; Earn 100 Civic Points for every friend who joins</div>
    </div>

    <!-- How It Works -->
    <div class="how-it-works">
        <h3>&#x2696; How It Works</h3>
        <ol>
            <li><strong>Add emails below</strong> &mdash; enter one or more friends you think would care about democracy.</li>
            <li><strong>Preview &amp; send</strong> &mdash; each friend gets a personal email from TPB showing your email so they know who invited them. You can preview exactly what they'll receive.</li>
            <li><strong>They click "Accept Invitation"</strong> &mdash; they're in. Account created, email verified, ready to go.</li>
            <li><strong>You earn 100 Civic Points</strong> &mdash; added to your total the moment they join. We'll email you to let you know.</li>
        </ol>
        <div class="tip">
            &#x1F4A1; Tip: Let your friend know to expect this email. A heads-up from you makes all the difference.
        </div>
    </div>

    <!-- Form -->
    <div class="form-section">
        <h2>Send Invitations</h2>

        <div class="email-input-row">
            <input type="email" id="invite-email-input" placeholder="Enter friend's email address..." onkeydown="if(event.key==='Enter'){event.preventDefault();addEmail();}">
            <button class="btn-add" onclick="addEmail()">+ Add</button>
        </div>

        <div id="email-list" class="email-list-container"></div>

        <!-- Preview toggle -->
        <div class="preview-section">
            <button class="preview-toggle" id="preview-toggle" onclick="togglePreview()">
                <span class="arrow">&#x25BC;</span> Preview what your friend will receive
            </button>
            <div id="email-preview" class="preview-frame" style="display:none;">
                <span class="preview-frame-label">Preview of email your friend will receive</span>
                <iframe srcdoc="<?= htmlspecialchars($previewHtml) ?>"></iframe>
            </div>
        </div>

        <button class="btn-send" id="send-btn" onclick="sendInvitations()" disabled>Send 0 Invitations</button>
        <p class="already-member-note" id="member-note" style="display:none;"></p>
    </div>

    <!-- History -->
    <?php if (count($history) > 0): ?>
    <div class="history-section">
        <h2>Your Invitations</h2>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Invited</th>
                    <th>Sent</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                <tr>
                    <td class="email-col"><?= htmlspecialchars($row['invitee_email']) ?></td>
                    <td class="date-col"><?= date('M j', strtotime($row['created_at'])) ?></td>
                    <?php if ($row['status'] === 'joined'): ?>
                        <td class="status-joined">Joined!</td>
                        <td class="points-col">+100 pts</td>
                    <?php else: ?>
                        <td class="status-pending">Pending</td>
                        <td class="points-col none">&mdash;</td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="invite-stats">
            <strong><?= $totalPointsFromInvites ?> pts</strong> earned from invitations
            &nbsp;&bull;&nbsp;
            <?= $joinedCount ?> of <?= $sentCount ?> accepted
            <?php if ($sentCount > 0): ?>
                &nbsp;&bull;&nbsp;
                <?= $acceptanceRate ?>% acceptance rate
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div class="history-section">
        <h2>Your Invitations</h2>
        <div class="history-empty">
            No invitations sent yet. Add an email above to get started!
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
let emailList = [];

async function addEmail() {
    const input = document.getElementById('invite-email-input');
    const email = input.value.trim().toLowerCase();
    if (!email || !email.includes('@') || !email.includes('.')) {
        alert('Please enter a valid email address.');
        return;
    }
    if (emailList.find(e => e.email === email)) {
        alert('Email already in list.');
        return;
    }
    // Check if already a member
    try {
        const resp = await fetch('/api/check-invite-email.php?email=' + encodeURIComponent(email));
        const data = await resp.json();
        emailList.push({ email, isMember: data.exists });
    } catch (err) {
        // If check fails, assume not a member and let the server handle it
        emailList.push({ email, isMember: false });
    }
    renderEmailList();
    input.value = '';
    input.focus();
}

function removeEmail(email) {
    emailList = emailList.filter(e => e.email !== email);
    renderEmailList();
}

function renderEmailList() {
    const container = document.getElementById('email-list');
    const sendable = emailList.filter(e => !e.isMember);
    const memberEmails = emailList.filter(e => e.isMember);
    const sendBtn = document.getElementById('send-btn');
    const memberNote = document.getElementById('member-note');

    sendBtn.textContent = 'Send ' + sendable.length + ' Invitation' + (sendable.length !== 1 ? 's' : '');
    sendBtn.disabled = sendable.length === 0;

    // Show note about already-member emails
    if (memberEmails.length > 0) {
        const names = memberEmails.map(e => e.email).join(', ');
        memberNote.textContent = names + (memberEmails.length === 1 ? ' is' : ' are') + ' already a member and won\'t receive an email.';
        memberNote.style.display = 'block';
    } else {
        memberNote.style.display = 'none';
    }

    container.innerHTML = emailList.map(e => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#1a1a2e;border:1px solid #333;border-radius:6px;margin-bottom:6px;">
            <span style="color:#88c0d0;font-size:14px;">${escapeHtml(e.email)}</span>
            <span style="display:flex;align-items:center;gap:8px;">
                ${e.isMember
                    ? '<span style="color:#ff9800;font-size:12px;background:rgba(255,152,0,0.15);padding:3px 10px;border-radius:4px;border:1px solid rgba(255,152,0,0.3);font-weight:600;">Already a member</span>'
                    : '<span style="color:#4caf50;font-size:12px;background:rgba(76,175,80,0.15);padding:3px 10px;border-radius:4px;border:1px solid rgba(76,175,80,0.3);font-weight:600;">Ready</span>'}
                <button onclick="removeEmail('${escapeHtml(e.email)}')" style="background:none;border:none;color:#666;cursor:pointer;font-size:1.1rem;padding:0 4px;" onmouseover="this.style.color='#f44336'" onmouseout="this.style.color='#666'">&times;</button>
            </span>
        </div>
    `).join('');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

async function sendInvitations() {
    const sendable = emailList.filter(e => !e.isMember).map(e => e.email);
    if (!sendable.length) return;

    const btn = document.getElementById('send-btn');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    try {
        const resp = await fetch('/api/send-invite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ emails: sendable })
        });
        const data = await resp.json();

        // Show results briefly, then reload
        let msg = data.results.map(r => r.email + ': ' + r.status).join('\n');
        alert('Results:\n' + msg);
        location.reload();
    } catch (err) {
        alert('Something went wrong. Please try again.');
        btn.disabled = false;
        btn.textContent = 'Send ' + sendable.length + ' Invitation' + (sendable.length !== 1 ? 's' : '');
    }
}

function togglePreview() {
    const preview = document.getElementById('email-preview');
    const btn = document.getElementById('preview-toggle');
    if (preview.style.display === 'none') {
        preview.style.display = 'block';
        btn.innerHTML = '<span class="arrow">&#x25B2;</span> Hide email preview';
    } else {
        preview.style.display = 'none';
        btn.innerHTML = '<span class="arrow">&#x25BC;</span> Preview what your friend will receive';
    }
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
</body>
</html>
