<?php
/**
 * Where I Need My Government — Civic Positions
 * ==============================================
 * Citizens rank 17 policy categories and write positions at town/state/federal levels.
 * Drag to reorder, checkbox to rank/unrank, positions saved to DB via API.
 */
$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'civic-positions';
$pageTitle = 'Where I Need My Government — The People\'s Branch';
$isLoggedIn = (bool)$dbUser;

// Load categories from DB
$categories = $pdo->query("SELECT * FROM civic_categories WHERE is_active = 1 ORDER BY display_order")->fetchAll();
$categoriesJson = json_encode($categories);

// Load user positions if logged in
$userPositions = ['town' => [], 'state' => [], 'federal' => []];
$userBio = '';
if ($isLoggedIn) {
    $stmt = $pdo->prepare("SELECT * FROM civic_positions WHERE user_id = ? ORDER BY level, rank_order");
    $stmt->execute([$dbUser['user_id']]);
    foreach ($stmt->fetchAll() as $p) {
        $userPositions[$p['level']][] = $p;
    }
    $userBio = $dbUser['bio'] ?? '';
}
$positionsJson = json_encode($userPositions);

$pageStyles = <<<'CSS'
:root {
    --bg-deep: #0a0a0f;
    --bg-card: #1a1a2e;
    --bg-active: #2a2a45;
    --bg-input: #0f0f1a;
    --accent: #d4af37;
    --accent-glow: rgba(212, 175, 55, 0.15);
    --text-main: #f5f5f5;
    --text-muted: #b0b0c5;
    --border: #44445a;
    --success: #4caf50;
}

.cp-page { max-width: 800px; margin: 0 auto; padding: 20px 20px 60px; }
.cp-header { text-align: center; margin-bottom: 30px; }
.cp-header h1 { color: var(--accent); font-size: 2em; margin-bottom: 8px; }
.cp-header .lead { color: var(--text-muted); font-size: 1.05em; }

.bio-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}
.bio-section h2 { color: var(--accent); font-size: 1.1em; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px; }
.bio-section textarea {
    width: 100%;
    min-height: 80px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: #fff;
    padding: 12px;
    font-size: 1em;
    resize: vertical;
    line-height: 1.5;
}
.bio-section textarea:focus { outline: none; border-color: var(--accent); background: #151525; }
.bio-hint { color: var(--text-muted); font-size: 0.8em; margin-top: 6px; }

.cp-instructions {
    background: var(--accent-glow);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 8px;
    padding: 12px 15px;
    margin-bottom: 20px;
    color: var(--text-muted);
    font-size: 0.9em;
    line-height: 1.5;
}
.cp-instructions strong { color: var(--accent); }

.level-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 16px;
    border-bottom: 2px solid var(--border);
}
.level-tab {
    padding: 10px 20px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 1em;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}
.level-tab:hover { color: var(--text-main); }
.level-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.level-tab .tab-count { font-size: 0.75em; color: var(--success); margin-left: 4px; font-weight: 800; }
.level-panel { display: none; }
.level-panel.active { display: block; }

.positions-list { display: flex; flex-direction: column; gap: 10px; }

.position-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 10px;
    transition: all 0.2s ease;
    cursor: grab;
}
.position-card:active { cursor: grabbing; }
.position-card.dragging { opacity: 0.5; border-color: var(--accent); }
.position-card.drag-over { border-color: var(--accent); box-shadow: 0 0 10px var(--accent-glow); }
.position-card.ranked { border-left: 4px solid var(--success); background: #1c1c32; }
.position-card.unranked { border-left: 4px solid transparent; opacity: 0.85; }
.position-card.norank { opacity: 0.45; border-left: 4px solid #2a2a2a; }
.position-card.norank .rank-badge { display: none; }
.position-card.norank .card-preview { display: none; }
.position-card.norank .card-title { color: var(--text-muted); }
.position-card.custom { border-left: 4px solid #88c0d0; }
.position-card:has(.card-body.open) { background: var(--bg-active); border-color: var(--accent); }

.card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    cursor: pointer;
}
.rank-check { width: 18px; height: 18px; accent-color: var(--success); cursor: pointer; flex-shrink: 0; }
.rank-badge {
    width: 26px; height: 26px; border-radius: 6px;
    background: var(--success); color: #000;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.8em; flex-shrink: 0;
}
.rank-badge.empty { background: #333; color: #888; }
.drag-handle { color: #555; font-size: 1.2em; cursor: grab; flex-shrink: 0; user-select: none; }
.card-title { flex: 1; font-size: 1.05em; color: #ffffff; font-weight: 600; }
.card-title input {
    background: transparent; border: none; border-bottom: 1px dashed #88c0d0;
    color: #88c0d0; font-size: 1.05em; font-weight: 600; width: 100%; padding: 2px 0;
}
.card-title input:focus { outline: none; border-bottom-color: var(--accent); }
.card-toggle { color: #666; font-size: 0.9em; flex-shrink: 0; }
.card-preview {
    color: var(--accent); font-size: 0.8em; font-style: italic; opacity: 0.8;
    max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.info-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    background: #333; color: var(--accent); font-size: 0.75em;
    font-weight: bold; cursor: pointer; flex-shrink: 0;
}
.info-icon:hover { background: #444; }

.card-body { display: none; padding: 0 16px 16px 60px; }
.card-body.open { display: block; }
.position-comment {
    width: 100%; min-height: 70px;
    background: var(--bg-input); border: 1px solid var(--border); border-radius: 8px;
    color: #fff; padding: 12px; font-size: 1rem; resize: vertical; line-height: 1.5;
    margin-top: 8px;
}
.position-comment:focus { outline: none; border-color: var(--accent); background: #151525; }
.comment-hint { color: var(--text-muted); font-size: 0.75em; margin-top: 4px; }

.norank-divider {
    color: var(--text-muted); font-size: 0.8em; text-align: center;
    padding: 12px 0 4px; text-transform: uppercase; letter-spacing: 1px;
}

.cp-actions {
    display: flex; gap: 12px; justify-content: center; margin-top: 20px;
}
.btn-cp {
    padding: 10px 28px; border: none; border-radius: 8px;
    font-size: 1em; cursor: pointer; font-weight: bold;
}
.btn-save { background: var(--accent); color: #000; }
.btn-save:hover { background: #e4bf47; }
.btn-reset { background: #333; color: var(--text-muted); }
.btn-reset:hover { background: #444; }
.save-status { text-align: center; color: var(--success); font-size: 0.9em; margin-top: 8px; display: none; }

.login-prompt {
    background: var(--accent-glow); border: 1px solid var(--accent);
    border-radius: 10px; padding: 30px; text-align: center; margin: 40px 0;
}
.login-prompt p { color: var(--accent); margin-bottom: 15px; font-size: 1.1em; }

@media (max-width: 600px) {
    .card-preview { display: none; }
    .card-header { padding: 12px; gap: 8px; }
    .card-body { padding: 0 12px 12px 44px; }
    .level-tab { padding: 8px 12px; font-size: 0.9em; }
}
CSS;

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>

<main class="main">
<div class="cp-page">
    <div class="cp-header">
        <h1>Where I Need My Government</h1>
        <p class="lead">"My town, my state, my country — I need them to work for me and mine. Here's where:"</p>
    </div>

    <?php if (!$isLoggedIn): ?>
    <div class="login-prompt">
        <p>Log in to create your civic positions</p>
        <a href="/login.php" class="btn-cp btn-save">Log In</a>
    </div>
    <?php else: ?>

    <div class="bio-section">
        <h2>About Me</h2>
        <textarea id="bio" placeholder="Who are you in 2-3 sentences? Not your resume — your situation. What should your officials know about your life?"><?= htmlspecialchars($userBio) ?></textarea>
        <div class="bio-hint">Example: "Retired veteran, 30 years in Putnam. Grandkids in the schools. Fixed income. I care about keeping this town affordable and honest."</div>
    </div>

    <div class="cp-instructions">
        <strong>How this works:</strong> Choose a level — Town, State, or Federal. Check the categories that matter to you at that level. Drag to rank them. Click to write your position. Each level has its own priorities.
    </div>

    <div id="globalTip"></div>

    <div class="level-tabs">
        <button class="level-tab active" onclick="switchLevel('town')" id="tab-town">🏘️ Town <span class="tab-count" id="count-town"></span></button>
        <button class="level-tab" onclick="switchLevel('state')" id="tab-state">🗺️ State <span class="tab-count" id="count-state"></span></button>
        <button class="level-tab" onclick="switchLevel('federal')" id="tab-federal">🇺🇸 Federal <span class="tab-count" id="count-federal"></span></button>
    </div>

    <div class="level-panel active" id="panel-town"><div class="positions-list" id="positionsList-town"></div></div>
    <div class="level-panel" id="panel-state"><div class="positions-list" id="positionsList-state"></div></div>
    <div class="level-panel" id="panel-federal"><div class="positions-list" id="positionsList-federal"></div></div>

    <div class="cp-actions">
        <button class="btn-cp btn-save" onclick="saveAll()">Save My Positions</button>
        <button class="btn-cp btn-reset" onclick="resetAll()">Reset</button>
    </div>
    <div class="save-status" id="saveStatus">Saved!</div>

    <?php endif; ?>
</div>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php if ($isLoggedIn): ?>
<script>
const dbCategories = <?= $categoriesJson ?>;
const dbPositions = <?= $positionsJson ?>;

const levels = ['town', 'state', 'federal'];
let data = { town: [], state: [], federal: [] };
let activeLevel = 'town';
let dragSrcIndex = null;
let activeTipIndex = -1;

function buildList(level) {
    // Get categories valid for this level
    const cats = dbCategories.filter(c => c.levels.includes(level));

    // Start with DB positions if they exist
    const existing = dbPositions[level] || [];
    const list = [];
    const usedIds = new Set();

    // Add saved positions first (in rank order)
    existing.forEach(p => {
        const cat = dbCategories.find(c => c.id == p.category_id);
        list.push({
            id: parseInt(p.category_id),
            name: cat ? cat.name : (p.category_name || 'Custom'),
            hint: cat ? cat.hint : 'Your custom topic',
            hover: cat ? (cat.hover || '') : '',
            comment: p.comment || '',
            isRanked: p.is_ranked == 1,
            custom: !cat || p.category_id == 0
        });
        usedIds.add(parseInt(p.category_id));
    });

    // Add remaining categories not yet in positions
    cats.forEach(c => {
        if (!usedIds.has(c.id)) {
            list.push({
                id: c.id,
                name: c.name,
                hint: c.hint,
                hover: c.hover || '',
                comment: '',
                isRanked: true,
                custom: false
            });
        }
    });

    // Add custom slots if not already present
    for (let i = 0; i < 2; i++) {
        const customInExisting = existing.filter(p => p.category_id == 0);
        if (i >= customInExisting.length && list.filter(l => l.custom).length < 2) {
            list.push({
                id: 0,
                name: '',
                hint: 'Your custom topic',
                hover: '',
                comment: '',
                isRanked: true,
                custom: true
            });
        }
    }

    return list;
}

function init() {
    levels.forEach(l => { data[l] = buildList(l); });
    document.getElementById('bio').value = document.getElementById('bio').value || '';
    updateCounts();
    renderLevel(activeLevel);
}

function switchLevel(level) {
    activeLevel = level;
    document.querySelectorAll('.level-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + level).classList.add('active');
    document.querySelectorAll('.level-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + level).classList.add('active');
    renderLevel(level);
    document.getElementById('globalTip').style.display = 'none';
    activeTipIndex = -1;
}

function updateCounts() {
    levels.forEach(l => {
        const filled = data[l].filter(p => p.isRanked && p.comment && p.comment.trim()).length;
        const ranked = data[l].filter(p => p.isRanked).length;
        const el = document.getElementById('count-' + l);
        el.textContent = filled > 0 ? `${filled}/${ranked}` : '';
    });
}

function renderLevel(level) {
    const list = document.getElementById('positionsList-' + level);
    list.innerHTML = '';

    const ranked = data[level].filter(p => p.isRanked !== false);
    const norank = data[level].filter(p => p.isRanked === false);
    data[level] = [...ranked, ...norank];

    let rankCounter = 0;
    let addedDivider = false;

    data[level].forEach((pos, index) => {
        const isRanked = pos.isRanked !== false;

        if (!isRanked && !addedDivider) {
            const divider = document.createElement('div');
            divider.className = 'norank-divider';
            divider.textContent = '\u2014 not ranked \u2014';
            list.appendChild(divider);
            addedDivider = true;
        }

        const card = document.createElement('div');
        const hasContent = pos.comment && pos.comment.trim();
        let cls = 'position-card';
        if (!isRanked) cls += ' norank';
        else if (hasContent) cls += ' ranked';
        else cls += ' unranked';
        if (pos.custom) cls += ' custom';
        card.className = cls;
        card.draggable = isRanked;

        if (isRanked) rankCounter++;
        const uid = level + '-' + index;
        const preview = hasContent && isRanked ? pos.comment.substring(0, 40) + (pos.comment.length > 40 ? '...' : '') : '';

        card.innerHTML = `
            <div class="card-header" onclick="toggleCard('${uid}')">
                <input type="checkbox" class="rank-check" ${isRanked ? 'checked' : ''}
                    onclick="event.stopPropagation();toggleRank('${level}',${index})">
                <span class="drag-handle">&#x2807;</span>
                ${isRanked ? `<span class="rank-badge ${hasContent ? '' : 'empty'}">${rankCounter}</span>` : ''}
                ${pos.custom
                    ? `<span class="card-title"><input type="text" value="${esc(pos.name)}" placeholder="Your topic..." onclick="event.stopPropagation()" onchange="updateCustomName('${level}',${index},this.value)"></span>`
                    : `<span class="card-title">${esc(pos.name)}</span>`
                }
                ${pos.hover && !pos.custom ? `<span class="info-icon" onclick="event.stopPropagation();toggleTip(event,'${level}',${index})">?</span>` : ''}
                ${preview ? `<span class="card-preview">${esc(preview)}</span>` : ''}
                <span class="card-toggle">&#x25B8;</span>
            </div>
            <div class="card-body" id="body-${uid}">
                <div style="color:var(--text-muted);font-size:0.8em;margin-bottom:6px">${esc(pos.hint)}</div>
                <textarea class="position-comment" id="comment-${uid}"
                    placeholder="What do you need from your ${level} government on this?"
                    oninput="updateComment('${level}',${index},this.value)">${esc(pos.comment || '')}</textarea>
                <div class="comment-hint">This is your mandate at the ${level} level.</div>
            </div>
        `;

        if (isRanked) {
            card.addEventListener('dragstart', e => { dragSrcIndex = index; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragend', () => { card.classList.remove('dragging'); list.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over')); });
            card.addEventListener('dragover', e => { e.preventDefault(); card.classList.add('drag-over'); });
            card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
            card.addEventListener('drop', e => {
                e.preventDefault(); card.classList.remove('drag-over');
                if (dragSrcIndex !== null && dragSrcIndex !== index) {
                    const item = data[level].splice(dragSrcIndex, 1)[0];
                    data[level].splice(index, 0, item);
                    renderLevel(level);
                }
            });
        }
        list.appendChild(card);
    });
}

function toggleCard(uid) {
    const body = document.getElementById('body-' + uid);
    const wasOpen = body.classList.contains('open');
    const panel = document.getElementById('panel-' + activeLevel);
    panel.querySelectorAll('.card-body').forEach(b => b.classList.remove('open'));
    panel.querySelectorAll('.card-toggle').forEach(t => t.innerHTML = '&#x25B8;');
    if (!wasOpen) {
        body.classList.add('open');
        body.closest('.position-card').querySelector('.card-toggle').innerHTML = '&#x25BE;';
        const ta = document.getElementById('comment-' + uid);
        if (ta) setTimeout(() => ta.focus(), 100);
    }
}

function toggleRank(level, index) {
    data[level][index].isRanked = !data[level][index].isRanked;
    if (!data[level][index].isRanked) data[level][index].comment = '';
    updateCounts();
    renderLevel(level);
}

function updateComment(level, index, value) {
    data[level][index].comment = value;
    updateCounts();
    const cards = document.getElementById('positionsList-' + level).querySelectorAll('.position-card');
    const card = cards[index];
    if (!card) return;
    if (value.trim()) { card.classList.add('ranked'); card.classList.remove('unranked'); card.querySelector('.rank-badge')?.classList.remove('empty'); }
    else { card.classList.remove('ranked'); card.classList.add('unranked'); card.querySelector('.rank-badge')?.classList.add('empty'); }
}

function updateCustomName(level, index, value) { data[level][index].name = value; }

async function saveAll() {
    const bio = document.getElementById('bio').value;
    const status = document.getElementById('saveStatus');

    for (const level of levels) {
        const positions = data[level].map((p, i) => ({
            category_id: p.id || 0,
            category_name: p.custom ? p.name : null,
            comment: p.comment || '',
            is_ranked: p.isRanked !== false,
            custom: !!p.custom
        }));

        await fetch('/api/civic-positions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ level, positions, bio })
        });
    }

    status.style.display = 'block';
    status.textContent = 'Saved!';
    setTimeout(() => { status.style.display = 'none'; }, 3000);
}

function resetAll() {
    if (!confirm('Reset ALL positions across all levels?')) return;
    levels.forEach(l => { data[l] = buildList(l); data[l].forEach(p => { p.comment = ''; p.isRanked = true; }); });
    document.getElementById('bio').value = '';
    updateCounts();
    renderLevel(activeLevel);
}

function toggleTip(e, level, index) {
    const tip = document.getElementById('globalTip');
    const key = level + '-' + index;
    if (activeTipIndex === key) { tip.style.display = 'none'; activeTipIndex = -1; return; }
    const pos = data[level][index];
    if (!pos || !pos.hover) return;
    tip.textContent = pos.hover;
    tip.style.cssText = 'display:block;position:fixed;width:340px;max-width:90vw;padding:20px;z-index:99999;font-size:15px;font-weight:400;line-height:1.6;background:#252535;color:#f5f5f5;border:2px solid #d4af37;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.8);';
    const rect = e.target.getBoundingClientRect();
    let top = rect.bottom + 10, left = rect.left - 150;
    if (left + 340 > window.innerWidth - 10) left = window.innerWidth - 350;
    if (left < 10) left = 10;
    if (top + 200 > window.innerHeight) top = rect.top - 200;
    tip.style.top = top + 'px';
    tip.style.left = left + 'px';
    activeTipIndex = key;
}

document.addEventListener('click', e => {
    if (!e.target.closest('.info-icon') && activeTipIndex !== -1) {
        document.getElementById('globalTip').style.display = 'none';
        activeTipIndex = -1;
    }
});

function esc(s) { return s ? s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }

init();
</script>
<?php endif; ?>
