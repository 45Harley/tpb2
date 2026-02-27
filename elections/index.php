<?php
/**
 * Elections Landing Page
 * ======================
 * Overview page linking to The Fight, The Amendment, and Polls.
 * Share actions scattered throughout. Stats from TPB2 tables.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/smtp-mail.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'Elections 2026 — The People\'s Branch';

$isLoggedIn = (bool)$dbUser;

// Stats from TPB2
$threatCount = $pdo->query("SELECT COUNT(*) FROM executive_threats WHERE severity_score >= 300")->fetchColumn();
$userCount = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$actionCount = $pdo->query("SELECT COUNT(*) FROM points_log")->fetchColumn();
$pollVoteCount = $pdo->query("SELECT COUNT(*) FROM poll_votes")->fetchColumn();

$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
$shareText = "The Trump mobsters are dismantling democracy TODAY. Track the threats. Take The Fight to The Mob. Make them pay. {$siteUrl}/elections/";

$pageStyles = <<<'CSS'
.el-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

.el-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #0a0a0f 100%);
    padding: 3rem 2rem; text-align: center; border-bottom: 3px solid #ff4444;
    border-radius: 8px; margin-bottom: 2rem;
}
.el-hero h1 { font-size: 2.5em; color: #ff4444; text-transform: uppercase; letter-spacing: 4px; margin-bottom: 0.5rem; text-shadow: 0 2px 10px rgba(255,68,68,0.3); }
.el-hero .hook { font-size: 1.1rem; color: #ccc; max-width: 600px; margin: 0 auto 1.5rem; line-height: 1.6; }
.el-hero .hook strong { color: #fff; }

.el-cta-buttons { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-bottom: 1.5rem; }
.el-cta-btn {
    padding: 0.75rem 2rem; border-radius: 6px; text-decoration: none;
    font-weight: 700; font-size: 1rem; transition: transform 0.2s, box-shadow 0.2s;
}
.el-cta-btn:hover { transform: translateY(-2px); }
.el-cta-btn.primary { background: linear-gradient(135deg, #cc0000, #990000); color: #fff; box-shadow: 0 4px 15px rgba(204,0,0,0.4); }
.el-cta-btn.secondary { background: #252540; color: #7ab8e0; border: 2px solid #7ab8e0; }
.el-cta-btn.secondary:hover { background: #7ab8e0; color: #0a0a0f; }

.share-row { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; margin: 1rem 0; }
.share-btn {
    padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none;
    font-weight: 600; font-size: 0.85rem; transition: transform 0.2s, opacity 0.2s;
    display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer;
}
.share-btn:hover { transform: translateY(-1px); opacity: 0.9; }
.share-btn.x { background: #000; color: #fff; border: 1px solid #333; }
.share-btn.bsky { background: #0085ff; color: #fff; }
.share-btn.fb { background: #1877f2; color: #fff; }
.share-btn.email { background: #38a169; color: #fff; }

.el-stats { display: flex; justify-content: center; gap: 2rem; padding: 1.5rem; background: #1a1a2e; border-radius: 8px; margin-bottom: 2rem; flex-wrap: wrap; }
.el-stat { text-align: center; }
.el-stat .num { font-size: 2.2em; font-weight: 700; color: #ff6b6b; }
.el-stat .lbl { font-size: 0.8rem; color: #888; margin-top: 0.25rem; }

.el-section-title { color: #d4af37; font-size: 1.3rem; margin: 2rem 0 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #333; }

.el-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
.el-card {
    background: #1a1a2e; border-radius: 8px; padding: 1.5rem;
    border-left: 4px solid #7ab8e0; transition: border-color 0.2s;
}
.el-card:hover { border-color: #d4af37; }
.el-card h3 { color: #7ab8e0; margin-bottom: 0.5rem; font-size: 1.05rem; }
.el-card p { color: #aaa; line-height: 1.6; font-size: 0.9rem; margin-bottom: 0.75rem; }
.el-card a { color: #d4af37; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
.el-card a:hover { text-decoration: underline; }

.el-action-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 2rem; }
.el-action-card {
    background: #252540; border: 1px solid #333; border-radius: 8px;
    padding: 1.5rem; text-align: center;
}
.el-action-card h3 { color: #ff4444; margin-bottom: 0.5rem; font-size: 1.1rem; }
.el-action-card p { color: #aaa; font-size: 0.85rem; line-height: 1.5; margin-bottom: 0.75rem; }
.el-action-card a {
    display: inline-block; padding: 0.5rem 1.5rem; background: #d4af37; color: #000;
    border-radius: 4px; text-decoration: none; font-weight: 700; font-size: 0.9rem;
}
.el-action-card a:hover { background: #e0c068; }

.el-bottom-cta {
    background: linear-gradient(135deg, #8b0000, #cc0000); padding: 2.5rem 2rem;
    text-align: center; border-radius: 8px; margin-bottom: 1.5rem;
}
.el-bottom-cta h2 { color: #fff; font-size: 1.5rem; margin-bottom: 0.5rem; }
.el-bottom-cta p { color: #ffcccc; margin-bottom: 1.25rem; font-size: 1rem; }

.email-modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.8); z-index: 9999; align-items: center;
    justify-content: center; overflow-y: auto; padding: 20px;
}
.email-modal.show { display: flex; }
.email-modal-content {
    background: #1a1a2e; padding: 2rem; border-radius: 12px;
    max-width: 500px; width: 90%; position: relative; text-align: center;
    max-height: 90vh; overflow-y: auto;
}
.email-modal-content h3 { color: #fff; margin-bottom: 1rem; }
.modal-close { position: absolute; top: 10px; right: 15px; background: none; border: none; color: #888; font-size: 1.5rem; cursor: pointer; }
.modal-close:hover { color: #fff; }
.email-preview { text-align: left; margin-bottom: 1.25rem; }
.preview-label { color: #888; font-size: 0.8rem; margin-bottom: 0.5rem; }
.preview-box {
    background: #fff; color: #333; padding: 1rem; border-radius: 8px;
    font-size: 0.8rem; line-height: 1.6; max-height: 200px; overflow-y: auto;
}
.email-input {
    width: 100%; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #444;
    background: #252540; color: #fff; font-size: 1rem; margin-bottom: 1rem;
}
.email-input:focus { outline: none; border-color: #7ab8e0; }
.send-btn {
    width: 100%; padding: 0.75rem; background: linear-gradient(135deg, #38a169, #2f855a);
    color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer;
}
.send-btn:hover { opacity: 0.9; }
.email-status { margin-top: 1rem; font-size: 0.85rem; }
.email-status.success { color: #38a169; }
.email-status.error { color: #c53030; }

.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
    text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
}
.view-links a:hover { border-color: #d4af37; color: #d4af37; }
.view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

@media (max-width: 600px) {
    .el-hero h1 { font-size: 1.8em; }
    .el-stats { gap: 1rem; }
    .el-action-grid { grid-template-columns: 1fr; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<main class="el-container">

    <div class="view-links">
        <a href="/elections/" class="active">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
    </div>

    <!-- Hero -->
    <section class="el-hero">
        <h1>Election 2026</h1>
        <p class="hook">
            <strong>Tired of all talk and no action?</strong><br><br>
            If you want action, you must act first.<br>
            You must help others act.<br>
            You must keep acting &mdash; and join others who see how it works.<br><br>
            <strong>If you care about the United States, if you care about the world... join now.</strong>
        </p>
        <div class="el-cta-buttons">
            <?php if ($isLoggedIn): ?>
            <a href="/elections/the-fight.php" class="el-cta-btn primary">Join The Fight</a>
            <a href="/poll/" class="el-cta-btn secondary">Vote on Threats</a>
            <?php else: ?>
            <a href="/join.php" class="el-cta-btn primary">Join The Fight</a>
            <a href="/poll/" class="el-cta-btn secondary">See the Threats</a>
            <?php endif; ?>
        </div>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>" target="_blank" class="share-btn x">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText) ?>" target="_blank" class="share-btn bsky">Share on Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("{$siteUrl}/elections/") ?>" target="_blank" class="share-btn fb">Share on Facebook</a>
            <button type="button" class="share-btn email" onclick="openEmailModal()">Email a Friend</button>
        </div>
    </section>

    <!-- Stats -->
    <div class="el-stats">
        <div class="el-stat">
            <div class="num"><?= number_format($threatCount) ?></div>
            <div class="lbl">Active Threats</div>
        </div>
        <div class="el-stat">
            <div class="num"><?= number_format($actionCount) ?></div>
            <div class="lbl">Actions Taken</div>
        </div>
        <div class="el-stat">
            <div class="num"><?= number_format($pollVoteCount) ?></div>
            <div class="lbl">Votes Cast</div>
        </div>
        <div class="el-stat">
            <div class="num"><?= number_format($userCount) ?></div>
            <div class="lbl">Citizens Ready</div>
        </div>
    </div>

    <!-- How It Works -->
    <h2 class="el-section-title">How It Works</h2>
    <div class="el-card-grid">
        <div class="el-card">
            <h3>Track Threats</h3>
            <p>We document every action that threatens our democracy &mdash; executive overreach, court defiance, attacks on institutions. Each one scored on the criminality scale.</p>
            <a href="/usa/executive.php">View threats &#8594;</a>
        </div>
        <div class="el-card">
            <h3>Vote on Every One</h3>
            <p>Every threat scoring 300+ becomes a poll. Citizens say "Is this acceptable?" Reps say "Will you act?" Every vote is on the record.</p>
            <a href="/poll/">Vote now &#8594;</a>
        </div>
        <div class="el-card">
            <h3>Make Pledges</h3>
            <p>Commit to act &mdash; vote in November, contact reps, register voters, share threats. Pledges are promises. Knockouts are proof.</p>
            <a href="/elections/the-fight.php">The Fight &#8594;</a>
        </div>
        <div class="el-card">
            <h3>The Secret Weapon</h3>
            <p>The People's Accountability Amendment &mdash; a proposed 28th Amendment. 70% of Americans vote to remove any federal official. No pardons. No immunity.</p>
            <a href="/elections/the-amendment.php">Read the plan &#8594;</a>
        </div>
    </div>

    <!-- Share mid-page -->
    <div style="text-align: center; margin: 1.5rem 0;">
        <p style="color: #888; font-size: 0.85rem; margin-bottom: 0.5rem;">Spread the word. Every share recruits another citizen.</p>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode("171 documented threats to democracy. Scored on a criminality scale. Your reps on the record. {$siteUrl}/poll/") ?>" target="_blank" class="share-btn x">Share Polls</a>
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode("70% — If 70% of Americans agree you must go, YOU MUST GO. The People's Accountability Amendment. {$siteUrl}/elections/the-amendment.php") ?>" target="_blank" class="share-btn x">Share the Amendment</a>
            <button type="button" class="share-btn email" onclick="openEmailModal()">Email a Friend</button>
        </div>
    </div>

    <!-- Take Action -->
    <h2 class="el-section-title">Take Action</h2>
    <div class="el-action-grid">
        <div class="el-action-card">
            <h3>The Fight</h3>
            <p>14 pledges. 14 knockouts. Track your progress. Earn civic points. Prove you showed up.</p>
            <a href="/elections/the-fight.php">Pledges &amp; Knockouts</a>
        </div>
        <div class="el-action-card">
            <h3>The Amendment</h3>
            <p>The 28th Amendment. 70% recall. No pardons. No immunity. The standing army that never sleeps.</p>
            <a href="/elections/the-amendment.php">Read the Strategy</a>
        </div>
    </div>

    <!-- Bottom CTA + Share -->
    <section class="el-bottom-cta">
        <h2>Democracy Doesn't Defend Itself</h2>
        <p>Every call matters. Every vote counts. Every share recruits.</p>
        <?php if ($isLoggedIn): ?>
        <a href="/elections/the-fight.php" class="el-cta-btn primary">Take Action Now</a>
        <?php else: ?>
        <a href="/join.php" class="el-cta-btn primary">Get Started Now</a>
        <?php endif; ?>
        <div class="share-row" style="margin-top: 1rem;">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>" target="_blank" class="share-btn x">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText) ?>" target="_blank" class="share-btn bsky">Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("{$siteUrl}/elections/") ?>" target="_blank" class="share-btn fb">Facebook</a>
            <button type="button" class="share-btn email" onclick="openEmailModal()">Email</button>
        </div>
    </section>

</main>

<!-- Email Modal -->
<div id="email-modal" class="email-modal">
    <div class="email-modal-content">
        <button class="modal-close" onclick="closeEmailModal()">&times;</button>
        <h3>Email This to a Friend</h3>
        <div class="email-preview">
            <div class="preview-label">Preview:</div>
            <div class="preview-box">
                <strong>Subject:</strong> America is stuck in the stone age - here's how we fix it<br><br>
                While the rest of the democratic world figured out how to fire bad leaders sometime around the invention of the steam engine, America is still stuck with a system where a president can burn the country down and we just have to... wait.<br><br>
                <span style="color:#2563eb;">Britain</span> can boot a Prime Minister before lunch.<br>
                <span style="color:#2563eb;">Germany</span> requires you to have a replacement ready &mdash; how civilized.<br>
                <span style="color:#2563eb;">Canada</span> has done it six times.<br><br>
                But here in the land of the free? We get <span style="color:#c53030;">impeachment</span> &mdash; a process so toothless that a president can be impeached TWICE and still finish their term, collect a pension, and run again.<br><br>
                <em>It's time to fix that with the People's Accountability Amendment.</em><br><br>
                Track the threats. Make your pledges. Land your knockouts.
            </div>
        </div>
        <input type="email" id="recruit-email" autocomplete="email" placeholder="friend@example.com" class="email-input">
        <button type="button" onclick="sendRecruitEmail()" class="send-btn">Send Email</button>
        <div id="email-status" class="email-status"></div>
    </div>
</div>

<script>
function openEmailModal() {
    document.getElementById('email-modal').classList.add('show');
    document.getElementById('recruit-email').focus();
}

function closeEmailModal() {
    document.getElementById('email-modal').classList.remove('show');
    document.getElementById('email-status').textContent = '';
    document.getElementById('recruit-email').value = '';
}

function sendRecruitEmail() {
    const email = document.getElementById('recruit-email').value.trim();
    const status = document.getElementById('email-status');

    if (!email) {
        status.textContent = 'Please enter an email address';
        status.className = 'email-status error';
        return;
    }

    status.textContent = 'Sending...';
    status.className = 'email-status';

    fetch('/api/email-recruit.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ to: email, type: 'general' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            status.textContent = 'Email sent!';
            status.className = 'email-status success';
            setTimeout(closeEmailModal, 2000);
        } else {
            status.textContent = data.error || 'Failed to send';
            status.className = 'email-status error';
        }
    })
    .catch(() => {
        status.textContent = 'Failed to send email';
        status.className = 'email-status error';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('email-modal').addEventListener('click', function(e) {
        if (e.target === this) closeEmailModal();
    });
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
