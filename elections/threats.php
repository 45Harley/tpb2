<?php
/**
 * Threat Stream — Live Feed of Democracy Threats
 * ================================================
 * Reverse-chronological stream of all active threats.
 * Pulsing severity badges, action scripts, share buttons per card.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/severity.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'Threat Stream — The People\'s Branch';
$ogTitle = 'Threat Stream — Live Democracy Threats';
$ogDescription = 'Real-time feed of threats to constitutional order. Scored on a 0-1000 criminality scale. See what you can do.';

// --- Data ---
$threats = $pdo->query("
    SELECT et.*, eo.full_name as official_name
    FROM executive_threats et
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    WHERE et.is_active = 1
    ORDER BY et.threat_date DESC, et.threat_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Tags
$allTags = $pdo->query("SELECT * FROM threat_tags WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tagsById = [];
foreach ($allTags as $tag) $tagsById[$tag['tag_id']] = $tag;
$threatTags = [];
$r = $pdo->query("SELECT tm.threat_id, tm.tag_id FROM threat_tag_map tm JOIN threat_tags t ON tm.tag_id = t.tag_id WHERE t.is_active = 1");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $threatTags[$row['threat_id']][] = $tagsById[$row['tag_id']];
}

// Stats
$totalThreats = count($threats);
$highCrimeCount = 0;
$totalSeverity = 0;
$scored = 0;
foreach ($threats as $t) {
    if ($t['severity_score'] !== null) {
        $totalSeverity += $t['severity_score'];
        $scored++;
        if ($t['severity_score'] >= 300) $highCrimeCount++;
    }
}
$avgSeverity = $scored > 0 ? round($totalSeverity / $scored) : 0;
$avgZone = getSeverityZone($avgSeverity);
$newestDate = $threats[0]['threat_date'] ?? 'N/A';

$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
$shareText = "$totalThreats documented threats to democracy. $highCrimeCount score \"High Crime\" or above. See the live stream and what you can do.";

$pageStyles = <<<'CSS'
.stream-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

/* View links */
.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.4rem 1rem; border: 1px solid #333; border-radius: 6px;
    color: #888; text-decoration: none; font-size: 0.9rem; transition: all 0.2s;
}
.view-links a:hover { color: #e0e0e0; border-color: #555; }
.view-links a.active { color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); }

/* Stats bar */
.stats-bar {
    display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap;
    background: linear-gradient(135deg, #1a1a2e 0%, #252540 100%);
    padding: 1.5rem; border-radius: 8px; margin-bottom: 1.5rem;
    border: 1px solid #333;
}
.stat-box { text-align: center; min-width: 120px; }
.stat-number {
    font-size: 2.5rem; font-weight: 900; line-height: 1;
    font-family: 'Courier New', monospace;
}
.stat-number.pulse-red {
    color: #f44336;
    animation: statsPulse 2s ease-in-out infinite;
}
.stat-number.pulse-gold {
    color: #d4af37;
    animation: statsPulse 2.5s ease-in-out infinite;
}
.stat-label { color: #888; font-size: 0.8rem; margin-top: 0.3rem; text-transform: uppercase; letter-spacing: 1px; }

@keyframes statsPulse {
    0%, 100% { opacity: 1; text-shadow: none; }
    50% { opacity: 0.7; text-shadow: 0 0 20px currentColor; }
}

/* Subscribe bar */
.subscribe-bar {
    display: flex; align-items: center; justify-content: center; gap: 0.75rem;
    padding: 0.75rem 1rem; margin-bottom: 1rem;
    background: #0a0a0f; border: 1px solid #333; border-radius: 8px;
}
.subscribe-bar .sub-label { color: #aaa; font-size: 0.9rem; }
.subscribe-bar .sub-hint { color: #666; font-size: 0.8rem; font-style: italic; }
.subscribe-bar .sub-status { color: #4caf50; font-size: 0.8rem; display: none; }
.toggle-switch {
    position: relative; width: 44px; height: 24px; cursor: pointer;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: #333; border-radius: 24px; transition: 0.3s;
}
.toggle-slider:before {
    content: ''; position: absolute; width: 18px; height: 18px;
    left: 3px; bottom: 3px; background: #888; border-radius: 50%; transition: 0.3s;
}
.toggle-switch input:checked + .toggle-slider { background: #4caf50; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); background: #fff; }

/* Filter controls */
.filter-bar {
    display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;
    margin-bottom: 1.5rem; padding: 1rem; background: #1a1a2e;
    border-radius: 8px; border: 1px solid #333;
}
.filter-bar label { color: #888; font-size: 0.85rem; }
.filter-pills { display: flex; gap: 0.4rem; flex-wrap: wrap; flex: 1; }
.filter-pill {
    padding: 0.25rem 0.6rem; border: 1px solid #444; border-radius: 12px;
    font-size: 0.75rem; cursor: pointer; transition: all 0.2s; color: #888;
}
.filter-pill:hover { border-color: #888; }
.filter-pill.active { border-color: currentColor; background: rgba(255,255,255,0.08); font-weight: 600; }
.sort-select {
    background: #0a0a0f; border: 1px solid #444; color: #e0e0e0;
    padding: 0.3rem 0.5rem; border-radius: 6px; font-size: 0.85rem;
}

/* Threat cards */
.threat-card {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem; margin-bottom: 1rem; transition: border-color 0.3s;
}
.threat-card:hover { border-color: #555; }

.tc-header { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.5rem; }

.severity-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 3px 10px; border-radius: 4px; font-weight: 700;
    font-family: 'Courier New', monospace; font-size: 0.85rem;
    white-space: nowrap;
}
.severity-badge.pulsing {
    animation: severityPulse 2s ease-in-out infinite;
}
@keyframes severityPulse {
    0%, 100% { box-shadow: 0 0 4px rgba(244,67,54,0.3); }
    50% { box-shadow: 0 0 16px rgba(244,67,54,0.7), 0 0 30px rgba(244,67,54,0.3); }
}

.tc-official { color: #aaa; font-size: 0.85rem; }
.tc-branch {
    font-size: 0.7rem; padding: 2px 6px; border-radius: 3px;
    text-transform: uppercase; letter-spacing: 1px; font-weight: 600;
}
.tc-branch.executive { background: #c5303022; color: #c53030; }
.tc-branch.congressional { background: #2980b922; color: #2980b9; }
.tc-branch.judicial { background: #7d3c9822; color: #7d3c98; }
.tc-date { color: #666; font-size: 0.8rem; margin-left: auto; }

.tc-title {
    font-size: 1.1rem; font-weight: 600; color: #e0e0e0;
    margin-bottom: 0.5rem; line-height: 1.4;
}
.tc-title a { color: #e0e0e0; text-decoration: none; }
.tc-title a:hover { color: #d4af37; }

.tc-desc { color: #999; font-size: 0.9rem; line-height: 1.6; margin-bottom: 0.75rem; }

.tag-pills { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
.tag-pill {
    font-size: 0.7rem; padding: 2px 8px; border-radius: 10px;
    border: 1px solid; cursor: pointer; transition: opacity 0.2s;
}
.tag-pill:hover { opacity: 0.8; }

/* Action script box */
.action-box {
    background: #0a0a0f; border: 2px solid #d4af37; border-radius: 6px;
    padding: 1rem; margin-bottom: 0.75rem;
}
.action-box-label {
    color: #d4af37; font-size: 0.75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px; margin-bottom: 0.5rem;
}
.action-box p { color: #ccc; font-size: 0.9rem; line-height: 1.6; font-style: italic; }

/* Per-card share */
.tc-share { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.tc-share a, .tc-share button {
    font-size: 0.75rem; padding: 3px 10px; border-radius: 4px;
    text-decoration: none; border: 1px solid #333; background: transparent;
    color: #888; cursor: pointer; transition: all 0.2s;
}
.tc-share a:hover, .tc-share button:hover { color: #e0e0e0; border-color: #666; }

/* Bottom CTA */
.stream-cta {
    text-align: center; padding: 2rem; margin-top: 2rem;
    background: linear-gradient(135deg, #1a1a2e 0%, #252540 100%);
    border-radius: 8px; border: 1px solid #333;
}
.stream-cta h3 { color: #d4af37; margin-bottom: 1rem; }
.stream-cta p { color: #aaa; margin-bottom: 1.5rem; }

.share-row { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
.share-btn {
    padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.85rem;
    text-decoration: none; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer;
}
.share-btn.x { background: #000; color: #fff; border: 1px solid #333; }
.share-btn.bsky { background: #0085ff; color: #fff; }
.share-btn.fb { background: #1877f2; color: #fff; }
.share-btn.email { background: #38a169; color: #fff; }
.share-btn:hover { transform: translateY(-1px); opacity: 0.9; }

/* Email modal */
.email-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center;
}
.email-modal-overlay.open { display: flex; }
.email-modal {
    background: #1a1a2e; border: 1px solid #444; border-radius: 12px;
    padding: 2rem; max-width: 500px; width: 90%;
}
.email-modal h3 { color: #d4af37; margin-bottom: 1rem; }
.email-modal input {
    width: 100%; padding: 0.75rem; background: #0a0a0f; border: 1px solid #333;
    border-radius: 8px; color: #e0e0e0; font-size: 1rem; margin-bottom: 1rem;
}
.email-modal input:focus { outline: none; border-color: #d4af37; }
.email-modal .btn-row { display: flex; gap: 0.5rem; justify-content: flex-end; }

/* Responsive */
@media (max-width: 600px) {
    .stats-bar { gap: 1rem; padding: 1rem; }
    .stat-number { font-size: 1.8rem; }
    .tc-header { flex-direction: column; align-items: flex-start; }
    .tc-date { margin-left: 0; }
    .filter-pills { max-height: 100px; overflow-y: auto; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<main class="stream-container">

    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php" class="active">Threats</a>
    </div>

    <?php require_once dirname(__DIR__) . '/includes/criminality-scale.php'; ?>

    <!-- Stats Bar -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-number pulse-red"><?= $totalThreats ?></div>
            <div class="stat-label">Active Threats</div>
        </div>
        <div class="stat-box">
            <div class="stat-number pulse-red"><?= $highCrimeCount ?></div>
            <div class="stat-label">High Crime+</div>
        </div>
        <div class="stat-box">
            <div class="stat-number pulse-gold" style="color:<?= $avgZone['color'] ?>"><?= $avgSeverity ?></div>
            <div class="stat-label">Avg Severity</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:#aaa;font-size:1.2rem;padding-top:0.5rem"><?= date('M j, Y', strtotime($newestDate)) ?></div>
            <div class="stat-label">Latest Threat</div>
        </div>
    </div>

    <!-- Subscribe to daily alerts -->
    <div class="subscribe-bar">
    <?php if ($dbUser && ($dbUser['identity_level_id'] ?? 0) >= 2): ?>
        <span class="sub-label">Daily threat alerts by email</span>
        <label class="toggle-switch">
            <input type="checkbox" id="bulletinToggle" <?= !empty($dbUser['notify_threat_bulletin']) ? 'checked' : '' ?> onchange="toggleBulletin(this.checked)">
            <span class="toggle-slider"></span>
        </label>
        <span class="sub-status" id="bulletinStatus"></span>
    <?php else: ?>
        <span class="sub-hint">Sign in and verify your email to get daily threat alerts</span>
    <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <label>Filter:</label>
        <div class="filter-pills">
            <span class="filter-pill active" data-tag="all" onclick="filterByTag('all',this)" style="color:#d4af37">All</span>
            <?php foreach ($allTags as $tag): ?>
            <span class="filter-pill" data-tag="<?= $tag['tag_name'] ?>" onclick="filterByTag('<?= $tag['tag_name'] ?>',this)" style="color:<?= $tag['color'] ?>"><?= htmlspecialchars($tag['tag_label']) ?></span>
            <?php endforeach; ?>
        </div>
        <select class="sort-select" onchange="sortThreats(this.value)">
            <option value="date">Newest First</option>
            <option value="severity">Most Severe</option>
            <option value="date-asc">Oldest First</option>
        </select>
    </div>

    <!-- Threat Stream -->
    <div id="threat-stream">
    <?php foreach ($threats as $t):
        $tid = $t['threat_id'];
        $score = (int)($t['severity_score'] ?? 0);
        $zone = getSeverityZone($t['severity_score']);
        $isPulsing = $score >= 300;
        $textColor = $score > 500 ? '#fff' : '#000';
        $tags = $threatTags[$tid] ?? [];
        $branch = $t['branch'] ?? 'executive';
        $threatShareText = urlencode($t['title'] . " — Severity: $score ({$zone['label']}). See all threats:");
        $threatShareUrl = urlencode("$siteUrl/usa/executive.php#threat-$tid");
    ?>
        <div class="threat-card" data-severity="<?= $score ?>" data-date="<?= $t['threat_date'] ?>" data-tags="<?= implode(',', array_map(fn($tg) => $tg['tag_name'], $tags)) ?>">
            <div class="tc-header">
                <span class="severity-badge <?= $isPulsing ? 'pulsing' : '' ?>" style="background:<?= $zone['color'] ?>;color:<?= $textColor ?>">
                    <?= $score ?> <?= $zone['label'] ?>
                </span>
                <span class="tc-branch <?= $branch ?>"><?= $branch ?></span>
                <span class="tc-official"><?= htmlspecialchars($t['official_name'] ?? 'Unknown') ?></span>
                <span class="tc-date"><?= date('M j, Y', strtotime($t['threat_date'])) ?></span>
            </div>

            <div class="tc-title">
                <a href="/usa/executive.php#threat-<?= $tid ?>"><?= htmlspecialchars($t['title']) ?></a>
            </div>

            <?php if ($t['description']): ?>
            <div class="tc-desc"><?= nl2br(htmlspecialchars($t['description'])) ?></div>
            <?php endif; ?>

            <?php if ($tags): ?>
            <div class="tag-pills">
                <?php foreach ($tags as $tg): ?>
                <span class="tag-pill" style="background:<?= $tg['color'] ?>22;color:<?= $tg['color'] ?>;border-color:<?= $tg['color'] ?>44" onclick="filterByTag('<?= $tg['tag_name'] ?>')"><?= htmlspecialchars($tg['tag_label']) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($t['action_script']): ?>
            <div class="action-box">
                <div class="action-box-label">What You Can Do</div>
                <p><?= nl2br(htmlspecialchars($t['action_script'])) ?></p>
            </div>
            <?php endif; ?>

            <div class="tc-share">
                <a href="https://twitter.com/intent/tweet?text=<?= $threatShareText ?>&url=<?= $threatShareUrl ?>" target="_blank">Share on X</a>
                <a href="https://bsky.app/intent/compose?text=<?= $threatShareText ?>%20<?= $threatShareUrl ?>" target="_blank">Bluesky</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $threatShareUrl ?>" target="_blank">Facebook</a>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- Bottom CTA -->
    <div class="stream-cta">
        <h3>Share This Stream</h3>
        <p><?= $totalThreats ?> threats documented. <?= $highCrimeCount ?> score "High Crime" or above. Average severity: <?= $avgSeverity ?> (<?= $avgZone['label'] ?>).</p>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>&url=<?= urlencode("$siteUrl/elections/threats.php") ?>" target="_blank" class="share-btn x">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText . " $siteUrl/elections/threats.php") ?>" target="_blank" class="share-btn bsky">Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("$siteUrl/elections/threats.php") ?>" target="_blank" class="share-btn fb">Facebook</a>
            <button type="button" class="share-btn email" onclick="openEmailModal()">Email a Friend</button>
        </div>
    </div>

</main>

<!-- Email Modal -->
<div class="email-modal-overlay" id="emailModal" onclick="if(event.target===this)closeEmailModal()">
    <div class="email-modal">
        <h3>Email This to a Friend</h3>
        <p style="color:#aaa;margin-bottom:1rem;font-size:0.9rem"><?= $totalThreats ?> documented threats to democracy — they need to see this.</p>
        <input type="email" id="friendEmail" placeholder="Friend's email address">
        <div id="emailStatus"></div>
        <div class="btn-row">
            <button class="btn btn-text" onclick="closeEmailModal()">Cancel</button>
            <button class="btn btn-primary" onclick="sendEmail()">Send</button>
        </div>
    </div>
</div>

<script>
function filterByTag(tag, pill) {
    // Update active pill
    if (pill) {
        document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
    } else {
        // Called from tag pill click — find matching filter pill
        document.querySelectorAll('.filter-pill').forEach(p => {
            p.classList.toggle('active', p.dataset.tag === tag);
        });
    }

    document.querySelectorAll('.threat-card').forEach(card => {
        if (tag === 'all') {
            card.style.display = '';
        } else {
            const tags = card.dataset.tags.split(',');
            card.style.display = tags.includes(tag) ? '' : 'none';
        }
    });
}

function sortThreats(sortBy) {
    const stream = document.getElementById('threat-stream');
    const cards = [...stream.querySelectorAll('.threat-card')];

    cards.sort((a, b) => {
        if (sortBy === 'severity') return (parseInt(b.dataset.severity)||0) - (parseInt(a.dataset.severity)||0);
        if (sortBy === 'date-asc') return a.dataset.date.localeCompare(b.dataset.date);
        return b.dataset.date.localeCompare(a.dataset.date); // date desc default
    });

    cards.forEach(card => stream.appendChild(card));
}

function toggleBulletin(checked) {
    const status = document.getElementById('bulletinStatus');
    fetch('/api/save-profile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ notify_threat_bulletin: checked ? 1 : 0 })
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            status.textContent = checked ? 'Subscribed!' : 'Unsubscribed';
            status.style.color = checked ? '#4caf50' : '#888';
            status.style.display = 'inline';
            setTimeout(() => { status.style.display = 'none'; }, 3000);
        }
    })
    .catch(() => {
        status.textContent = 'Error saving';
        status.style.color = '#f44336';
        status.style.display = 'inline';
    });
}

function openEmailModal() { document.getElementById('emailModal').classList.add('open'); }
function closeEmailModal() { document.getElementById('emailModal').classList.remove('open'); }

function sendEmail() {
    const email = document.getElementById('friendEmail').value.trim();
    const status = document.getElementById('emailStatus');
    if (!email || !email.includes('@')) {
        status.innerHTML = '<div class="status-msg error">Please enter a valid email.</div>';
        return;
    }
    status.innerHTML = '<div class="status-msg info">Sending...</div>';

    fetch('/api/email-recruit.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ email: email, source: 'threat-stream' })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            status.innerHTML = '<div class="status-msg success">Sent!</div>';
            document.getElementById('friendEmail').value = '';
            setTimeout(closeEmailModal, 2000);
        } else {
            status.innerHTML = '<div class="status-msg error">' + (d.error || 'Failed.') + '</div>';
        }
    })
    .catch(() => { status.innerHTML = '<div class="status-msg error">Network error.</div>'; });
}
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
