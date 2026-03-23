<?php
/**
 * Help Center — Index
 * ===================
 * Lists all auto-generated user guides + links to existing help pages.
 * Auto-discovers guides by scanning help/data/*.json.
 */

$config = require dirname(__DIR__) . '/config.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { $pdo = null; }

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : false;
$isLoggedIn = (bool)$dbUser;

$pageTitle = 'Help Center | The People\'s Branch';
$ogDescription = 'Step-by-step guides to help you get started and make the most of The People\'s Branch.';
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'help';

// Auto-discover guides from help/data/*.json
$guides = [];
$dataDir = __DIR__ . '/data';
if (is_dir($dataDir)) {
    foreach (glob($dataDir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $guides[] = $data;
        }
    }
}
// Pin "purpose" and "philosophy" to top, rest alphabetical by title
usort($guides, function($a, $b) {
    $pinOrder = ['metaphysics' => 0, 'purpose' => 1, 'philosophy' => 2];
    $aPin = $pinOrder[$a['id']] ?? 99;
    $bPin = $pinOrder[$b['id']] ?? 99;
    if ($aPin !== $bPin) return $aPin - $bPin;
    return strcasecmp($a['title'], $b['title']);
});

// Icons per guide ID — uses the same emoji system as modal_config.php getIconEmoji()
// Map each guide to its closest modal icon type for cross-site consistency
require_once dirname(__DIR__) . '/config/modal_config.php';
$guideIconMap = [
    'onboarding'   => 'new',         // 🚀
    'talk'         => 'social',      // 👥
    'elections'    => 'feature',     // 🎯
    'polls'        => 'tip',         // 💡
    'volunteer'    => 'social',      // 👥
    'civic-points' => 'important',   // ⭐
    'metaphysics'  => 'philosophy',  // 🎪
    'purpose'      => 'philosophy',  // 🎪
    'philosophy'   => 'philosophy',  // 🎪
    'profile'      => 'info',        // ℹ️
];
// Resolve to actual emoji via the centralized function
$guideIcons = [];
foreach ($guideIconMap as $id => $type) {
    $guideIcons[$id] = getIconEmoji($type);
}

$pageStyles = <<<'CSS'
.help-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
}
.help-header {
    text-align: center;
    margin-bottom: 2rem;
}
.help-header h1 {
    color: #d4af37;
    font-size: 1.8rem;
    margin-bottom: 0.5rem;
}
.help-header p {
    color: #b0b0b0;
    font-size: 1rem;
}

.help-section-title {
    color: #fff;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin: 2rem 0 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #333;
}

.help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}
.help-card {
    display: block;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
    padding: 1.5rem;
    text-decoration: none;
    transition: border-color 0.2s, transform 0.2s;
}
.help-card:hover {
    border-color: #d4af37;
    transform: translateY(-2px);
}
.help-card.dragging {
    opacity: 0.4;
    transform: scale(0.95);
}
.help-card.drag-over {
    border-color: #d4af37;
    box-shadow: 0 0 8px rgba(212,175,55,0.4);
}
.help-grid-header {
    display: flex; justify-content: space-between; align-items: center;
}
.drag-hint {
    color: #666; font-size: 0.75rem; font-weight: normal; letter-spacing: 0;
    text-transform: none;
}
.help-card .card-icon {
    font-size: 3rem;
    margin-bottom: 0.75rem;
    line-height: 1;
}
.help-card h3 {
    color: #fff;
    font-size: 1.1rem;
    margin-bottom: 0.4rem;
}
.help-card p {
    color: #b0b0b0;
    font-size: 0.9rem;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}
.help-card .card-meta {
    color: #b0b0b0;
    font-size: 0.75rem;
}

/* Existing help links */
.help-links {
    list-style: none;
    padding: 0;
}
.help-links li {
    margin-bottom: 0.5rem;
}
.help-links a {
    display: block;
    padding: 0.75rem 1rem;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    color: #90caf9;
    text-decoration: none;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}
.help-links a:hover {
    border-color: #90caf9;
}
.help-links .link-desc {
    color: #b0b0b0;
    font-size: 0.8rem;
    margin-top: 2px;
}

@media (max-width: 600px) {
    .help-grid { grid-template-columns: 1fr; }
    .help-header h1 { font-size: 1.4rem; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<div class="help-container">
    <div class="help-header">
        <h1>Help Center</h1>
        <p>Step-by-step guides to help you make the most of The People's Branch.</p>
    </div>

<?php if (!empty($guides)): ?>
    <div class="help-grid-header">
        <h2 class="help-section-title" style="margin-bottom:0;border-bottom:none;">Visual Guides</h2>
        <span class="drag-hint">Drag cards to reorder &middot; <a href="#" id="resetOrder" style="color:#d4af37;text-decoration:none;font-size:0.75rem;">Reset</a></span>
    </div>
    <div style="border-bottom:1px solid #333; margin-bottom:1rem;"></div>
    <div class="help-grid" id="helpGrid">
<?php foreach ($guides as $g): ?>
        <a href="/help/guide.php?flow=<?= htmlspecialchars($g['id']) ?>" class="help-card" draggable="true" data-guide-id="<?= htmlspecialchars($g['id']) ?>">
            <div class="card-icon"><?= $guideIcons[$g['id']] ?? getIconEmoji('docs') ?></div>
            <h3><?= htmlspecialchars($g['title']) ?></h3>
            <p><?= htmlspecialchars($g['subtitle']) ?></p>
            <span class="card-meta"><?= $g['stepCount'] ?> steps</span>
        </a>
<?php endforeach; ?>
    </div>
<?php endif; ?>

    <h2 class="help-section-title">More Help</h2>
    <ul class="help-links">
        <li>
            <a href="/help/tpb-getting-started-tutorial.html">
                Getting Started Tutorial
                <div class="link-desc">Interactive overview of The People's Branch</div>
            </a>
        </li>
        <li>
            <a href="/talk/help.php">
                Talk Help
                <div class="link-desc">How the civic brainstorming stream works — rules, groups, facilitating</div>
            </a>
        </li>
        <li>
            <a href="/help/tools.php">
                Tools Dashboard
                <div class="link-desc">All 56 tools, scripts, pipelines, and capabilities — searchable by agent</div>
            </a>
        </li>
    </ul>
</div>

<script>
(function() {
    const grid = document.getElementById('helpGrid');
    if (!grid) return;
    const STORAGE_KEY = 'tpb_help_guide_order';
    let dragCard = null;

    // Restore saved order
    function restoreOrder() {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (!saved) return;
        try {
            const order = JSON.parse(saved);
            const cards = {};
            grid.querySelectorAll('.help-card').forEach(c => { cards[c.dataset.guideId] = c; });
            order.forEach(id => { if (cards[id]) grid.appendChild(cards[id]); });
        } catch(e) {}
    }

    function saveOrder() {
        const order = Array.from(grid.querySelectorAll('.help-card')).map(c => c.dataset.guideId);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(order));
    }

    // Drag events
    grid.addEventListener('dragstart', e => {
        const card = e.target.closest('.help-card');
        if (!card) return;
        dragCard = card;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.guideId);
    });

    grid.addEventListener('dragend', e => {
        const card = e.target.closest('.help-card');
        if (card) card.classList.remove('dragging');
        grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
        dragCard = null;
    });

    grid.addEventListener('dragover', e => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.help-card');
        if (!target || target === dragCard) return;
        grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
        target.classList.add('drag-over');
    });

    grid.addEventListener('dragleave', e => {
        const target = e.target.closest('.help-card');
        if (target) target.classList.remove('drag-over');
    });

    grid.addEventListener('drop', e => {
        e.preventDefault();
        const target = e.target.closest('.help-card');
        if (!target || !dragCard || target === dragCard) return;
        target.classList.remove('drag-over');

        // Determine position: insert before or after target
        const cards = Array.from(grid.querySelectorAll('.help-card'));
        const dragIdx = cards.indexOf(dragCard);
        const targetIdx = cards.indexOf(target);
        if (dragIdx < targetIdx) {
            target.after(dragCard);
        } else {
            target.before(dragCard);
        }
        saveOrder();
    });

    // Touch support for mobile
    let touchCard = null;
    let touchClone = null;
    let touchStarted = false;
    let touchTimer = null;

    grid.addEventListener('touchstart', e => {
        const card = e.target.closest('.help-card');
        if (!card) return;
        touchTimer = setTimeout(() => {
            touchStarted = true;
            touchCard = card;
            card.classList.add('dragging');
            e.preventDefault();
        }, 300);
    }, {passive: false});

    grid.addEventListener('touchmove', e => {
        if (!touchStarted || !touchCard) { clearTimeout(touchTimer); return; }
        e.preventDefault();
        const touch = e.touches[0];
        const el = document.elementFromPoint(touch.clientX, touch.clientY);
        const target = el ? el.closest('.help-card') : null;
        grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
        if (target && target !== touchCard) target.classList.add('drag-over');
    }, {passive: false});

    grid.addEventListener('touchend', e => {
        clearTimeout(touchTimer);
        if (!touchStarted || !touchCard) { touchStarted = false; return; }
        const overCard = grid.querySelector('.drag-over');
        if (overCard && overCard !== touchCard) {
            const cards = Array.from(grid.querySelectorAll('.help-card'));
            const dragIdx = cards.indexOf(touchCard);
            const targetIdx = cards.indexOf(overCard);
            if (dragIdx < targetIdx) {
                overCard.after(touchCard);
            } else {
                overCard.before(touchCard);
            }
            saveOrder();
        }
        touchCard.classList.remove('dragging');
        grid.querySelectorAll('.drag-over').forEach(c => c.classList.remove('drag-over'));
        touchCard = null;
        touchStarted = false;
        e.preventDefault();
    });

    // Reset link
    document.getElementById('resetOrder').addEventListener('click', e => {
        e.preventDefault();
        localStorage.removeItem(STORAGE_KEY);
        location.reload();
    });

    // Prevent navigation during drag
    grid.querySelectorAll('.help-card').forEach(card => {
        card.addEventListener('click', e => {
            if (card.classList.contains('dragging')) e.preventDefault();
        });
    });

    restoreOrder();
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
