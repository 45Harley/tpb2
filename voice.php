<?php
/**
 * The People's Branch - My Voice
 * ===============================
 * Thoughts and actions: what you say, what happens.
 */

$config = require 'config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Session handling
$sessionId = isset($_COOKIE['tpb_civic_session']) ? $_COOKIE['tpb_civic_session'] : null;

// Load user data
$dbUser = null;
$userState = null;
$userTown = null;

if ($sessionId) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name, 
               u.current_state_id, u.current_town_id, u.civic_points,
               u.latitude, u.longitude,
               u.age_bracket, u.parent_consent,
               u.identity_level_id,
               s.abbreviation as state_abbrev, s.state_name,
               tw.town_name,
               il.level_name as identity_level_name,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        LEFT JOIN identity_levels il ON u.identity_level_id = il.level_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute(array($sessionId));
    $dbUser = $stmt->fetch();
    
    if ($dbUser) {
        $userState = $dbUser['state_abbrev'];
        $userTown = $dbUser['town_name'];
    }
}

// Trust level and permissions
require_once __DIR__ . '/includes/get-user.php';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$canPost = false;
$nextStep = 'Verify email to post';
$isMinor = false;
$needsParentConsent = false;

if ($dbUser) {
    // Check if minor needing parent consent
    $isMinor = ($dbUser['age_bracket'] === '13-17');
    $needsParentConsent = $isMinor && !$dbUser['parent_consent'];
    
    if ($dbUser['phone_verified'] || $dbUser['email_verified']) {
        $canPost = !$needsParentConsent;
        $nextStep = $needsParentConsent ? 'Waiting for parent approval' : '';
    }
}

// Points for nav
$points = $dbUser ? (int)$dbUser['civic_points'] : 0;

// Browse mode - show all thoughts for a state without location filter
$browseState = isset($_GET['browse']) ? strtoupper($_GET['browse']) : null;
$browseMode = false;
$browseStateName = '';

if ($browseState) {
    // Validate state
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
    $stmt->execute(array($browseState));
    $browseStateName = $stmt->fetchColumn();
    if ($browseStateName) {
        $browseMode = true;
    }
}

// Get thoughts - filtered by jurisdiction (or browse mode)
$thoughtsQuery = "
    SELECT 
        t.thought_id,
        t.user_id,
        t.content,
        t.jurisdiction_level,
        t.is_local,
        t.is_state,
        t.is_federal,
        t.created_at,
        t.upvotes,
        t.downvotes,
        c.category_name,
        c.icon,
        u.first_name,
        u.last_name,
        u.show_first_name,
        u.show_last_name,
        s.abbreviation as state_abbrev,
        tw.town_name
    FROM user_thoughts t
    LEFT JOIN thought_categories c ON t.category_id = c.category_id
    LEFT JOIN users u ON t.user_id = u.user_id
    LEFT JOIN states s ON t.state_id = s.state_id
    LEFT JOIN towns tw ON t.town_id = tw.town_id
    WHERE t.status = 'published'
";

if ($browseMode) {
    // Browse mode: show all thoughts for the browse state (federal + state + local)
    $thoughtsQuery .= " AND (t.is_federal = 1 OR s.abbreviation = ?)";
    $stmt = $pdo->prepare($thoughtsQuery . " ORDER BY t.created_at DESC LIMIT 50");
    $stmt->execute(array($browseState));
    $thoughts = $stmt->fetchAll();
} elseif ($userTown && $userState) {
    $thoughtsQuery .= " AND (
        t.is_federal = 1 
        OR (t.is_state = 1 AND s.abbreviation = ?)
        OR (t.is_local = 1 AND tw.town_name = ? AND s.abbreviation = ?)
    )";
    $stmt = $pdo->prepare($thoughtsQuery . " ORDER BY t.created_at DESC LIMIT 30");
    $stmt->execute(array($userState, $userTown, $userState));
    $thoughts = $stmt->fetchAll();
} elseif ($userState) {
    $thoughtsQuery .= " AND (t.is_federal = 1 OR (t.is_state = 1 AND s.abbreviation = ?))";
    $stmt = $pdo->prepare($thoughtsQuery . " ORDER BY t.created_at DESC LIMIT 30");
    $stmt->execute(array($userState));
    $thoughts = $stmt->fetchAll();
} else {
    $thoughtsQuery .= " AND t.is_federal = 1";
    $thoughts = $pdo->query($thoughtsQuery . " ORDER BY t.created_at DESC LIMIT 30")->fetchAll();
}

// Get categories for submit form
$categories = $pdo->query("SELECT * FROM thought_categories WHERE is_active = 1 AND (is_volunteer_only = 0 OR is_volunteer_only IS NULL) ORDER BY display_order")->fetchAll();

// Get user's votes
$userVotes = array();
if ($dbUser) {
    $stmt = $pdo->prepare("SELECT thought_id, vote_type FROM user_thought_votes WHERE user_id = ?");
    $stmt->execute(array($dbUser['user_id']));
    while ($row = $stmt->fetch()) {
        $userVotes[$row['thought_id']] = ($row['vote_type'] === 'upvote') ? 'up' : 'down';
    }
}

// Get user's own thoughts
$myThoughts = array();
if ($dbUser) {
    $stmt = $pdo->prepare("
        SELECT t.*, c.category_name, c.icon 
        FROM user_thoughts t
        LEFT JOIN thought_categories c ON t.category_id = c.category_id
        WHERE t.user_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute(array($dbUser['user_id']));
    $myThoughts = $stmt->fetchAll();
}

// Page config for includes
$pageTitle = $browseMode ? 'Browse ' . $browseStateName . ' - The People\'s Branch' : 'My Voice - The People\'s Branch';
$currentPage = 'voice';

// Nav variables for email and town
$userEmail = $dbUser ? ($dbUser['email'] ?? '') : '';
$userTownName = $dbUser ? ($dbUser['town_name'] ?? '') : '';
$userTownSlug = $userTownName ? strtolower(str_replace(' ', '-', $userTownName)) : '';
$userStateAbbr = $dbUser ? strtolower($dbUser['state_abbrev'] ?? '') : '';
$userStateDisplay = $dbUser ? ($dbUser['state_abbrev'] ?? '') : '';
$isLoggedIn = (bool)$dbUser;

// Page-specific styles
$pageStyles = '
/* Tabs */
.tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 1px solid #333;
    padding-bottom: 0.5rem;
}
.tab {
    padding: 0.5rem 1rem;
    background: transparent;
    border: none;
    color: #888;
    cursor: pointer;
    border-radius: 6px 6px 0 0;
    font-size: 1rem;
}
.tab:hover { color: #e0e0e0; }
.tab.active {
    color: #d4af37;
    background: #1a1a2e;
}
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}

/* Delete button */
.delete-thought-btn {
    background: transparent;
    border: 1px solid #444;
    border-radius: 4px;
    padding: 0.25rem 0.5rem;
    cursor: pointer;
    font-size: 0.9rem;
    opacity: 0.6;
    transition: all 0.2s;
}
.delete-thought-btn:hover {
    opacity: 1;
    border-color: #e74c3c;
    background: rgba(231, 76, 60, 0.1);
}

/* Jurisdiction filter */
.filter-bar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.filter-btn {
    padding: 0.5rem 1rem;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 20px;
    color: #888;
    cursor: pointer;
    font-size: 0.9rem;
}
.filter-btn:hover { color: #e0e0e0; border-color: #555; }
.filter-btn.active { color: #d4af37; border-color: #d4af37; }

/* Thought cards */
.thought {
    background: #1a1a2e;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.thought-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}
.thought-meta {
    color: #888;
    font-size: 0.85rem;
}
.thought-category {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    background: #2a2a3e;
    border-radius: 4px;
    font-size: 0.8rem;
}
.thought-content {
    line-height: 1.6;
    margin-bottom: 0.75rem;
}
.thought-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.thought-location {
    color: #666;
    font-size: 0.85rem;
}
.thought-votes {
    display: flex;
    gap: 0.5rem;
}
.vote-btn {
    padding: 0.25rem 0.5rem;
    background: #0a0a0f;
    border: 1px solid #333;
    border-radius: 4px;
    color: #888;
    cursor: pointer;
    font-size: 0.9rem;
}
.vote-btn:hover { border-color: #555; color: #e0e0e0; }
.vote-btn.voted { color: #d4af37; border-color: #d4af37; }
.vote-btn:disabled { cursor: not-allowed; opacity: 0.5; }
';

// Include header
require 'includes/header.php';

// Include nav
require 'includes/nav.php';
?>
    
    <main class="main">
        <?php if ($browseMode): ?>
        <div class="alert alert-info" style="margin-bottom: 1.5rem;">
            👀 You're browsing <strong><?= htmlspecialchars($browseStateName) ?></strong> — see what citizens are saying!
            <br><a href="profile.php" style="color: inherit;">Set your location</a> to join the conversation.
        </div>
        <h1>Voices of <?= htmlspecialchars($browseStateName) ?></h1>
        <p class="subtitle">What citizens are thinking</p>
        <?php else: ?>
        <h1>My Voice</h1>
        <p class="subtitle">Share thoughts, see progress</p>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" data-tab="read">Read</button>
            <button class="tab" data-tab="submit">Submit</button>
            <button class="tab" data-tab="mine">My Thoughts (<?= count($myThoughts) ?>)</button>
        </div>
        
        <!-- Read Thoughts Tab -->
        <div class="tab-content active" id="tab-read">
            <?php if (!$userTown): ?>
                <div class="alert alert-info">
                    📍 <a href="profile.php">Set your location</a> to see local and state thoughts.
                    Currently showing federal thoughts only.
                </div>
            <?php endif; ?>
            
            <div class="filter-bar">
                <button class="filter-btn active" data-filter="all">All</button>
                <?php if ($userTown): ?>
                    <button class="filter-btn" data-filter="local">🏘️ Local</button>
                <?php endif; ?>
                <?php if ($userState): ?>
                    <button class="filter-btn" data-filter="state">🗺️ <?= htmlspecialchars($userState) ?></button>
                <?php endif; ?>
                <button class="filter-btn" data-filter="federal">🇺🇸 Federal</button>
                <?php if ($dbUser && $dbUser['email_verified']): ?>
                <span style="margin-left: auto;"></span>
                <button class="filter-btn" id="filterMine" data-owner="mine">My Posts</button>
                <?php endif; ?>
            </div>
            
            <div id="thoughtsList">
                <?php if (empty($thoughts)): ?>
                    <div class="empty-state">
                        <div class="icon">💭</div>
                        <p>No thoughts yet. Be the first to share!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($thoughts as $thought): ?>
                        <?php
                        $jurisdiction = 'federal';
                        if ($thought['is_local']) $jurisdiction = 'local';
                        elseif ($thought['is_state']) $jurisdiction = 'state';
                        
                        $authorName = 'Anonymous';
                        if ($thought['show_first_name'] && $thought['first_name']) {
                            $authorName = htmlspecialchars($thought['first_name']);
                            if ($thought['show_last_name'] && $thought['last_name']) {
                                $authorName .= ' ' . htmlspecialchars(substr($thought['last_name'], 0, 1)) . '.';
                            }
                        }
                        
                        $userVote = isset($userVotes[$thought['thought_id']]) ? $userVotes[$thought['thought_id']] : null;
                        ?>
                        <div class="thought" data-jurisdiction="<?= $jurisdiction ?>" data-id="<?= $thought['thought_id'] ?>" data-user-id="<?= $thought['user_id'] ?>">
                            <div class="thought-header">
                                <div class="thought-meta">
                                    <?= $authorName ?> · <?= date('M j', strtotime($thought['created_at'])) ?>
                                </div>
                                <?php if ($thought['category_name']): ?>
                                    <div class="thought-category">
                                        <?= $thought['icon'] ?> <?= htmlspecialchars($thought['category_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="thought-content"><?= nl2br(htmlspecialchars($thought['content'])) ?></div>
                            <div class="thought-footer">
                                <div class="thought-location">
                                    <?php if ($thought['is_local'] && $thought['town_name']): ?>
                                        🏘️ <?= htmlspecialchars($thought['town_name']) ?>, <?= htmlspecialchars($thought['state_abbrev']) ?>
                                    <?php elseif ($thought['is_state'] && $thought['state_abbrev']): ?>
                                        🗺️ <?= htmlspecialchars($thought['state_abbrev']) ?>
                                    <?php else: ?>
                                        🇺🇸 Federal
                                    <?php endif; ?>
                                </div>
                                <div class="thought-votes">
                                    <button class="vote-btn <?= $userVote === 'up' ? 'voted' : '' ?>" 
                                            data-vote="up" <?= !$canPost ? 'disabled' : '' ?>>
                                        👍 <?= $thought['upvotes'] ?>
                                    </button>
                                    <button class="vote-btn <?= $userVote === 'down' ? 'voted' : '' ?>" 
                                            data-vote="down" <?= !$canPost ? 'disabled' : '' ?>>
                                        👎 <?= $thought['downvotes'] ?>
                                    </button>
                                    <?php if ($dbUser && $thought['user_id'] == $dbUser['user_id']): ?>
                                    <button class="delete-thought-btn" data-thought-id="<?= $thought['thought_id'] ?>" title="Delete thought" style="background: none; border: none; color: #888; cursor: pointer; margin-left: 10px; font-size: 0.9em;">🗑️</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Submit Thought Tab -->
        <div class="tab-content" id="tab-submit">
            <?php require __DIR__ . '/includes/thought-form.php'; ?>
        </div>
        
        <!-- My Thoughts Tab -->
        <div class="tab-content" id="tab-mine">
            <?php if (empty($myThoughts)): ?>
                <div class="empty-state">
                    <div class="icon">📝</div>
                    <p>You haven't submitted any thoughts yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($myThoughts as $thought): ?>
                    <div class="thought" data-id="<?= $thought['thought_id'] ?>">
                        <div class="thought-header">
                            <div class="thought-meta">
                                <?= date('M j, Y', strtotime($thought['created_at'])) ?>
                                · <?= ucfirst($thought['status']) ?>
                            </div>
                            <?php if ($thought['category_name']): ?>
                                <div class="thought-category">
                                    <?= $thought['icon'] ?> <?= htmlspecialchars($thought['category_name']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="thought-content"><?= nl2br(htmlspecialchars($thought['content'])) ?></div>
                        <div class="thought-footer">
                            <div class="thought-location">
                                <?php if ($thought['is_local']): ?>🏘️ Local
                                <?php elseif ($thought['is_state']): ?>🗺️ State
                                <?php else: ?>🇺🇸 Federal
                                <?php endif; ?>
                            </div>
                            <div class="thought-votes">
                                👍 <?= $thought['upvotes'] ?> · 👎 <?= $thought['downvotes'] ?>
                            </div>
                            <button class="delete-thought-btn" data-thought-id="<?= $thought['thought_id'] ?>" title="Delete this thought">🗑️</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
    (function() {
        'use strict';
        
        const API_BASE = 'api';
        
        // Session
        let sessionId = document.cookie.split('; ').find(row => row.startsWith('tpb_civic_session='));
        sessionId = sessionId ? sessionId.split('=')[1] : null;
        
        const canPost = <?= $canPost ? 'true' : 'false' ?>;
        const userId = <?= $dbUser ? (int)$dbUser['user_id'] : 'null' ?>;
        
        // Tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('tab-' + this.dataset.tab).classList.add('active');
            });
        });
        
        // Filter
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                document.querySelectorAll('.thought').forEach(thought => {
                    if (filter === 'all' || thought.dataset.jurisdiction === filter) {
                        thought.style.display = 'block';
                    } else {
                        thought.style.display = 'none';
                    }
                });
            });
        });
        
        // Vote
        document.querySelectorAll('.vote-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!canPost) return;
                
                const thought = this.closest('.thought');
                const thoughtId = thought.dataset.id;
                const voteType = this.dataset.vote;
                
                try {
                    const response = await fetch(API_BASE + '/vote-thought.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            thought_id: thoughtId,
                            vote_type: voteType === 'up' ? 'upvote' : 'downvote'
                        })
                    });
                    
                    const result = await response.json();
                    if (result.status === 'success') {
                        thought.querySelectorAll('.vote-btn').forEach(b => b.classList.remove('voted'));
                        if (result.action !== 'removed') {
                            this.classList.add('voted');
                        }
                        window.location.reload();
                    }
                } catch (err) {
                    console.error('Vote error:', err);
                }
            });
        });
        
        // Delete thought
        document.querySelectorAll('.delete-thought-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('Delete this thought?')) return;
                
                const thoughtId = this.dataset.thoughtId;
                const thought = this.closest('.thought');
                
                try {
                    const response = await fetch(API_BASE + '/delete-thought.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ thought_id: thoughtId })
                    });
                    
                    const data = await response.json();
                    if (data.status === 'success') {
                        thought.style.opacity = '0.5';
                        thought.style.transition = 'opacity 0.3s';
                        setTimeout(() => thought.remove(), 300);
                    } else {
                        alert(data.message || 'Could not delete thought.');
                    }
                } catch (err) {
                    console.error('Delete error:', err);
                    alert('Failed to delete. Please try again.');
                }
            });
        });
        
        // My Posts filter
        const filterMineBtn = document.getElementById('filterMine');
        if (filterMineBtn) {
            let showingMine = false;
            filterMineBtn.addEventListener('click', function() {
                showingMine = !showingMine;
                this.classList.toggle('active', showingMine);
                
                document.querySelectorAll('#tab-read .thought').forEach(thought => {
                    if (showingMine) {
                        thought.style.display = thought.dataset.userId == userId ? 'block' : 'none';
                    } else {
                        thought.style.display = 'block';
                    }
                });
            });
        }
        
    })();
    </script>
    
<?php require 'includes/footer.php'; ?>
