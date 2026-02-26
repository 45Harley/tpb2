<?php
/**
 * TPB Poll System — Threat Roll Call
 * ===================================
 * 300+ severity threats as poll questions.
 * Citizens: "Is this acceptable?" | Reps: "Will you act on this?"
 * Three auth paths: remembered citizen, magic link, rep verification.
 */

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
require_once __DIR__ . '/../includes/severity.php';

$dbUser = getUser($pdo);
$pageLoadTime = time();

// Auth state detection
$isRep = $dbUser && !empty($dbUser['official_id']);
$isRemembered = $dbUser && !$isRep;
$isVisitor = !$dbUser;
$canVote = false;

if ($dbUser) {
    if ($isRep) {
        $canVote = true; // Reps can always vote
    } elseif ($dbUser['email_verified']) {
        if ($dbUser['age_bracket'] === '13-17') {
            $canVote = !empty($dbUser['parent_consent']);
        } else {
            $canVote = true;
        }
    }
}

// Handle vote submission
$voteMessage = '';
$voteError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canVote && isset($_POST['vote_choice'])) {
    $honeypot = $_POST[$config['bot_detection']['honeypot_field']] ?? '';
    $loadTime = $_POST['load_time'] ?? 0;
    $timeDiff = time() - intval($loadTime);

    $isBot = (!empty($honeypot) || $timeDiff < $config['bot_detection']['min_submit_time']);

    if ($isBot && $config['bot_detection']['enabled']) {
        $stmt = $pdo->prepare("INSERT INTO bot_attempts (ip_address, user_agent, attempt_type, details) VALUES (?, ?, 'poll_vote', ?)");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? '', json_encode(['honeypot' => !empty($honeypot), 'time_diff' => $timeDiff])]);
        $voteError = 'Vote could not be processed.';
    } else {
        $pollId = intval($_POST['poll_id'] ?? 0);
        $voteChoice = $_POST['vote_choice'] ?? '';

        if ($pollId > 0 && in_array($voteChoice, ['yea', 'nay', 'abstain'])) {
            $stmt = $pdo->prepare("SELECT poll_id, active, poll_type FROM polls WHERE poll_id = ?");
            $stmt->execute([$pollId]);
            $poll = $stmt->fetch();

            if ($poll && $poll['active']) {
                $stmt = $pdo->prepare("SELECT poll_vote_id FROM poll_votes WHERE poll_id = ? AND user_id = ?");
                $stmt->execute([$pollId, $dbUser['user_id']]);
                $existingVote = $stmt->fetch();

                $repVote = $isRep ? 1 : 0;

                if ($existingVote) {
                    $stmt = $pdo->prepare("UPDATE poll_votes SET vote_choice = ?, is_rep_vote = ?, updated_at = NOW() WHERE poll_vote_id = ?");
                    $stmt->execute([$voteChoice, $repVote, $existingVote['poll_vote_id']]);
                    $voteMessage = 'Your vote has been updated.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO poll_votes (poll_id, user_id, vote_choice, is_rep_vote) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$pollId, $dbUser['user_id'], $voteChoice, $repVote]);

                    require_once __DIR__ . '/../includes/point-logger.php';
                    PointLogger::init($pdo);
                    $pointResult = PointLogger::award($dbUser['user_id'], 'poll_voted', 'poll', $pollId);
                    $pollPoints = $pointResult['points_earned'] ?? 0;
                    $dbUser['civic_points'] += $pollPoints;

                    $voteMessage = $pollPoints > 0
                        ? "Your vote has been recorded. +{$pollPoints} civic points!"
                        : 'Your vote has been recorded.';
                }
            } else {
                $voteError = 'This poll is no longer active.';
            }
        }
    }
}

// Get all threat polls with threat data + vote counts
$threatPolls = $pdo->query("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score, et.threat_date, et.official_id,
           eo.full_name as official_name,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_votes,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_votes,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_votes
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
")->fetchAll();

// Get tags per threat
$threatTags = [];
$r = $pdo->query("
    SELECT tm.threat_id, t.tag_name, t.tag_label, t.color
    FROM threat_tag_map tm
    JOIN threat_tags t ON tm.tag_id = t.tag_id
    WHERE t.is_active = 1
");
while ($row = $r->fetch()) {
    $threatTags[$row['threat_id']][] = $row;
}

// Get user's existing votes
$userVotes = [];
if ($dbUser) {
    $stmt = $pdo->prepare("SELECT poll_id, vote_choice FROM poll_votes WHERE user_id = ?");
    $stmt->execute([$dbUser['user_id']]);
    while ($row = $stmt->fetch()) {
        $userVotes[$row['poll_id']] = $row['vote_choice'];
    }
}

// Rep info (if verified rep)
$repInfo = null;
if ($isRep) {
    $stmt = $pdo->prepare("SELECT full_name, title, state_code, party FROM elected_officials WHERE official_id = ?");
    $stmt->execute([$dbUser['official_id']]);
    $repInfo = $stmt->fetch();
}

// Get all states for rep verification dropdown
$states = $pdo->query("SELECT abbreviation, state_name FROM states ORDER BY state_name")->fetchAll();

// Get unique tags for filter
$allTags = $pdo->query("
    SELECT DISTINCT t.tag_name, t.tag_label, t.color
    FROM threat_tags t
    JOIN threat_tag_map tm ON t.tag_id = tm.tag_id
    JOIN executive_threats et ON tm.threat_id = et.threat_id
    WHERE t.is_active = 1 AND et.severity_score >= 300
    ORDER BY t.tag_label
")->fetchAll();

$question = $isRep ? 'Will you act on this?' : 'Is this acceptable?';

$pageTitle = 'Polls';
$currentPage = 'poll';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
?>
<?php include __DIR__ . '/../includes/header.php'; ?>
<?php include __DIR__ . '/../includes/nav.php'; ?>

    <style>
        .polls-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

        /* Header */
        .page-header { margin-bottom: 1.5rem; }
        .page-header h1 { color: #d4af37; margin-bottom: 0.25rem; }
        .page-header .subtitle { color: #888; font-size: 1rem; }

        /* View links */
        .view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .view-links a {
            padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600;
            text-decoration: none; border: 1px solid #444; color: #aaa; transition: all 0.2s;
        }
        .view-links a:hover { border-color: #d4af37; color: #d4af37; }
        .view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

        /* Rep badge */
        .rep-badge {
            display: inline-block; background: #1a5276; color: #fff; padding: 0.4rem 0.8rem;
            border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem;
        }
        .rep-badge strong { color: #d4af37; }

        /* Controls */
        .controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center; }
        .controls select {
            padding: 0.4rem 0.8rem; border-radius: 6px; border: 1px solid #444;
            background: #1a1a2e; color: #e0e0e0; font-size: 0.85rem;
        }
        .controls .count { color: #888; font-size: 0.85rem; margin-left: auto; }

        /* Poll card */
        .poll-card {
            background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
            padding: 1.25rem; margin-bottom: 1rem; transition: border-color 0.2s;
        }
        .poll-card:hover { border-color: #555; }
        .poll-card-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; flex-wrap: wrap; }

        /* Severity badge */
        .severity-badge {
            display: inline-block; padding: 0.2rem 0.6rem; border-radius: 4px;
            font-size: 0.75rem; font-weight: 700; color: #fff; white-space: nowrap;
        }
        .official-name { font-size: 0.8rem; color: #888; }

        /* Tag pills */
        .tag-pills { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-bottom: 0.5rem; }
        .tag-pill {
            font-size: 0.65rem; padding: 0.15rem 0.5rem; border-radius: 10px;
            color: #fff; opacity: 0.85; cursor: pointer;
        }
        .tag-pill:hover { opacity: 1; }

        .poll-title { font-size: 1rem; font-weight: 600; color: #e0e0e0; margin-bottom: 0.5rem; line-height: 1.4; }
        .poll-prompt { font-size: 0.9rem; color: #d4af37; font-weight: 600; margin-bottom: 0.75rem; font-style: italic; }

        /* Vote buttons */
        .vote-buttons { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; }
        .vote-btn {
            flex: 1; padding: 0.6rem; font-size: 0.9rem; font-weight: 600;
            border: 2px solid; border-radius: 6px; cursor: pointer; transition: all 0.2s;
            background: transparent;
        }
        .vote-btn.yea { border-color: #4caf50; color: #4caf50; }
        .vote-btn.yea:hover, .vote-btn.yea.selected { background: #4caf50; color: #fff; }
        .vote-btn.nay { border-color: #f44336; color: #f44336; }
        .vote-btn.nay:hover, .vote-btn.nay.selected { background: #f44336; color: #fff; }
        .vote-btn.abstain-btn { border-color: #888; color: #888; }
        .vote-btn.abstain-btn:hover, .vote-btn.abstain-btn.selected { background: #888; color: #fff; }

        .your-vote { font-size: 0.85rem; color: #d4af37; font-weight: 500; margin-top: 0.4rem; }

        /* Alerts */
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .alert-success { background: rgba(76,175,80,0.2); color: #4caf50; border: 1px solid #4caf50; }
        .alert-error { background: rgba(244,67,54,0.2); color: #f44336; border: 1px solid #f44336; }
        .alert-warning { background: rgba(255,152,0,0.2); color: #ff9800; border: 1px solid #ff9800; }
        .alert a { color: #d4af37; }

        /* Magic link prompt */
        .magic-link-box {
            background: #1a1a2e; border: 1px solid #d4af37; border-radius: 8px;
            padding: 1.5rem; margin-bottom: 1.5rem; text-align: center;
        }
        .magic-link-box h3 { color: #d4af37; margin-bottom: 0.5rem; }
        .magic-link-box p { color: #aaa; margin-bottom: 1rem; font-size: 0.9rem; }
        .magic-link-box input[type="email"] {
            padding: 0.6rem 1rem; width: 100%; max-width: 320px; border: 1px solid #444;
            border-radius: 6px; background: #0a0a0f; color: #e0e0e0; font-size: 1rem;
            margin-bottom: 0.75rem;
        }
        .magic-link-box button {
            padding: 0.6rem 1.5rem; background: #d4af37; color: #000; border: none;
            border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.95rem;
        }
        .magic-link-box button:hover { background: #e0c068; }
        .magic-link-box .ml-status { margin-top: 0.75rem; font-size: 0.85rem; }

        /* Rep verification */
        .rep-verify-toggle { margin-top: 1rem; color: #888; font-size: 0.85rem; }
        .rep-verify-toggle label { cursor: pointer; }
        .rep-verify-toggle input[type="checkbox"] { margin-right: 0.4rem; }
        .rep-verify-form {
            display: none; background: #1a1a2e; border: 1px solid #1a5276; border-radius: 8px;
            padding: 1.25rem; margin-top: 0.75rem;
        }
        .rep-verify-form.visible { display: block; }
        .rep-verify-form h4 { color: #1a5276; margin-bottom: 0.75rem; }
        .rep-verify-form .form-row { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
        .rep-verify-form input, .rep-verify-form select {
            padding: 0.5rem; border: 1px solid #444; border-radius: 4px;
            background: #0a0a0f; color: #e0e0e0; font-size: 0.9rem; flex: 1; min-width: 120px;
        }
        .rep-verify-form button {
            padding: 0.5rem 1.25rem; background: #1a5276; color: #fff; border: none;
            border-radius: 4px; font-weight: 600; cursor: pointer;
        }
        .rep-verify-form .rv-status { margin-top: 0.5rem; font-size: 0.85rem; }

        /* Honeypot */
        .hp-field { position: absolute; left: -9999px; }

        /* Responsive */
        @media (max-width: 600px) {
            .vote-buttons { flex-direction: column; gap: 0.5rem; }
            .poll-card-header { flex-direction: column; align-items: flex-start; }
            .controls { flex-direction: column; }
            .rep-verify-form .form-row { flex-direction: column; }
        }
    </style>

    <main class="polls-container">
        <div class="page-header">
            <h1>Polls</h1>
            <p class="subtitle">Hold power accountable.</p>
        </div>

        <!-- View links -->
        <div class="view-links">
            <a href="/poll/" class="active">Vote</a>
            <a href="/poll/national/">National</a>
            <a href="/poll/by-state/">By State</a>
            <a href="/poll/by-rep/">By Rep</a>
        </div>

        <!-- Intro explainer + criminality scale -->
        <?php require_once __DIR__ . '/../includes/criminality-scale.php'; ?>

        <?php if ($voteMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($voteMessage) ?></div>
        <?php endif; ?>
        <?php if ($voteError): ?>
            <div class="alert alert-error"><?= htmlspecialchars($voteError) ?></div>
        <?php endif; ?>

        <?php if ($isRep && $repInfo): ?>
            <div class="rep-badge">
                Verified: <strong><?= htmlspecialchars($repInfo['full_name']) ?></strong>
                (<?= htmlspecialchars($repInfo['title']) ?>, <?= htmlspecialchars($repInfo['state_code']) ?>)
            </div>
        <?php endif; ?>

        <!-- Visitor: magic link prompt -->
        <?php if ($isVisitor): ?>
            <div class="magic-link-box" id="magicLinkBox">
                <h3>Verify your email to vote</h3>
                <p>Enter your email and we'll send a verification link. One click and you're in.</p>
                <input type="email" id="mlEmail" placeholder="your@email.com">
                <br>
                <button onclick="sendMagicLink()">Send Verification Link</button>
                <div class="ml-status" id="mlStatus"></div>

                <div class="rep-verify-toggle">
                    <label><input type="checkbox" id="repToggle" onchange="toggleRepForm()"> I am a U.S. Representative/Senator</label>
                </div>
                <div class="rep-verify-form" id="repForm">
                    <h4>Congressional Verification</h4>
                    <div class="form-row">
                        <input type="text" id="rvBioguide" placeholder="Bioguide ID (e.g. B001277)">
                        <input type="text" id="rvLastName" placeholder="Last Name">
                        <select id="rvState">
                            <option value="">State...</option>
                            <?php foreach ($states as $s): ?>
                            <option value="<?= $s['abbreviation'] ?>"><?= htmlspecialchars($s['state_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button onclick="verifyRep()">Verify</button>
                    <div class="rv-status" id="rvStatus"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sort/filter controls -->
        <div class="controls">
            <select id="sortSelect" onchange="sortPolls()">
                <option value="severity">Sort: Severity (highest)</option>
                <option value="date">Sort: Date (newest)</option>
                <option value="votes">Sort: Most votes</option>
            </select>
            <select id="tagFilter" onchange="filterByTag()">
                <option value="">Filter: All categories</option>
                <?php foreach ($allTags as $tag): ?>
                <option value="<?= htmlspecialchars($tag['tag_name']) ?>"><?= htmlspecialchars($tag['tag_label']) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="count"><?= count($threatPolls) ?> threats scored 300+</span>
        </div>

        <p style="color:#d4af37; font-weight:600; font-size:0.95rem; margin-bottom:1rem; text-transform:uppercase; letter-spacing:0.05em;">Vote and scroll. Vote and scroll. Every vote counts.</p>

        <!-- Threat poll cards -->
        <div id="pollList">
        <?php foreach ($threatPolls as $tp):
            $zone = getSeverityZone($tp['severity_score']);
            $userVote = $userVotes[$tp['poll_id']] ?? null;
            $tags = $threatTags[$tp['threat_id']] ?? [];
            $tagNames = array_map(function($t) { return $t['tag_name']; }, $tags);
        ?>
            <div class="poll-card" data-severity="<?= $tp['severity_score'] ?>" data-date="<?= $tp['threat_date'] ?>" data-votes="<?= (int)$tp['total_votes'] ?>" data-tags="<?= htmlspecialchars(implode(',', $tagNames)) ?>">
                <div class="poll-card-header">
                    <span class="severity-badge" style="background: <?= $zone['color'] ?>">
                        <?= $tp['severity_score'] ?> &mdash; <?= $zone['label'] ?>
                    </span>
                    <?php if ($tp['official_name']): ?>
                        <span class="official-name"><?= htmlspecialchars($tp['official_name']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($tags): ?>
                <div class="tag-pills">
                    <?php foreach ($tags as $tag): ?>
                    <span class="tag-pill" style="background: <?= $tag['color'] ?>" onclick="document.getElementById('tagFilter').value='<?= $tag['tag_name'] ?>'; filterByTag();"><?= htmlspecialchars($tag['tag_label']) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="poll-title"><a href="/usa/executive.php#threat-<?= $tp['threat_id'] ?>" style="color:#d4af37;text-decoration:underline;" title="View full threat detail"><?= htmlspecialchars($tp['title']) ?></a></div>
                <div class="poll-prompt"><?= $question ?></div>

                <?php if ($canVote): ?>
                    <form method="POST" class="vote-form">
                        <input type="hidden" name="poll_id" value="<?= $tp['poll_id'] ?>">
                        <input type="hidden" name="load_time" value="<?= $pageLoadTime ?>">
                        <input type="text" name="<?= $config['bot_detection']['honeypot_field'] ?>" class="hp-field" tabindex="-1" autocomplete="off">
                        <div class="vote-buttons">
                            <button type="submit" name="vote_choice" value="yea" class="vote-btn yea <?= $userVote === 'yea' ? 'selected' : '' ?>">Yea</button>
                            <button type="submit" name="vote_choice" value="nay" class="vote-btn nay <?= $userVote === 'nay' ? 'selected' : '' ?>">Nay</button>
                            <button type="submit" name="vote_choice" value="abstain" class="vote-btn abstain-btn <?= $userVote === 'abstain' ? 'selected' : '' ?>">Abstain</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($userVote): ?>
                    <div class="your-vote">You voted: <?= ucfirst($userVote) ?> <span style="color:#666;font-weight:normal">(click a button to change)</span></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <?php if (empty($threatPolls)): ?>
            <div class="alert alert-warning">No threat polls available at this time.</div>
        <?php endif; ?>

        <p style="text-align: center; margin-top: 2rem;">
            <a href="/poll/closed/" style="color: #d4af37;">View closed polls &rarr;</a>
        </p>
    </main>

    <script>
    // Magic link flow
    function sendMagicLink() {
        const email = document.getElementById('mlEmail').value.trim();
        const status = document.getElementById('mlStatus');
        if (!email) { status.innerHTML = '<span style="color:#f44336">Please enter your email.</span>'; return; }

        status.innerHTML = '<span style="color:#aaa">Sending...</span>';

        const sessionId = document.cookie.split(';').map(c => c.trim()).find(c => c.startsWith('tpb_civic_session='));
        const sid = sessionId ? sessionId.split('=')[1] : 'civic_' + Date.now();

        fetch('/api/send-magic-link.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email: email,
                session_id: sid,
                return_url: '/poll/',
                _form_load_time: <?= $pageLoadTime ?>
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                status.innerHTML = '<span style="color:#4caf50">Check your email! Click the link to verify, then return here to vote.</span>';
            } else {
                status.innerHTML = '<span style="color:#f44336">' + (data.message || 'Error sending link.') + '</span>';
            }
        })
        .catch(() => { status.innerHTML = '<span style="color:#f44336">Network error. Please try again.</span>'; });
    }

    // Rep verification toggle
    function toggleRepForm() {
        const form = document.getElementById('repForm');
        form.classList.toggle('visible', document.getElementById('repToggle').checked);
    }

    // Rep verification
    function verifyRep() {
        const bioguide = document.getElementById('rvBioguide').value.trim();
        const lastName = document.getElementById('rvLastName').value.trim();
        const state = document.getElementById('rvState').value;
        const status = document.getElementById('rvStatus');

        if (!bioguide || !lastName || !state) {
            status.innerHTML = '<span style="color:#f44336">All fields required.</span>';
            return;
        }

        status.innerHTML = '<span style="color:#aaa">Verifying...</span>';

        const sessionId = document.cookie.split(';').map(c => c.trim()).find(c => c.startsWith('tpb_civic_session='));
        const sid = sessionId ? sessionId.split('=')[1] : 'civic_' + Date.now();

        fetch('/api/verify-rep.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                bioguide_id: bioguide,
                last_name: lastName,
                state_code: state,
                session_id: sid
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                status.innerHTML = '<span style="color:#4caf50">' + data.message + ' Reloading...</span>';
                setTimeout(() => location.reload(), 1500);
            } else {
                status.innerHTML = '<span style="color:#f44336">' + (data.message || 'Verification failed.') + '</span>';
            }
        })
        .catch(() => { status.innerHTML = '<span style="color:#f44336">Network error. Please try again.</span>'; });
    }

    // Sort polls
    function sortPolls() {
        const container = document.getElementById('pollList');
        const cards = Array.from(container.querySelectorAll('.poll-card'));
        const sort = document.getElementById('sortSelect').value;

        cards.sort((a, b) => {
            if (sort === 'severity') return (parseInt(b.dataset.severity) || 0) - (parseInt(a.dataset.severity) || 0);
            if (sort === 'date') return (b.dataset.date || '').localeCompare(a.dataset.date || '');
            if (sort === 'votes') return (parseInt(b.dataset.votes) || 0) - (parseInt(a.dataset.votes) || 0);
            return 0;
        });

        cards.forEach(c => container.appendChild(c));
    }

    // Filter by tag
    function filterByTag() {
        const tag = document.getElementById('tagFilter').value;
        const cards = document.querySelectorAll('.poll-card');
        let visible = 0;

        cards.forEach(c => {
            if (!tag || (c.dataset.tags && c.dataset.tags.split(',').includes(tag))) {
                c.style.display = '';
                visible++;
            } else {
                c.style.display = 'none';
            }
        });

        document.querySelector('.controls .count').textContent = visible + ' threat' + (visible !== 1 ? 's' : '') + ' shown';
    }
    </script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
