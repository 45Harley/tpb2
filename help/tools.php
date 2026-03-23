<?php
/**
 * TPB Tools Dashboard
 * ====================
 * Lists all 48+ tools from the tools table, grouped by agent, searchable.
 *
 * tpb-tags: help, tools, dashboard
 * tpb-roles: developer, admin
 */

$config = require dirname(__DIR__) . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    $pdo = null;
}

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : false;

$pageTitle = 'Tools Dashboard — TPB';
$ogDescription = 'All tools, scripts, pipelines, and capabilities available in The People\'s Branch platform.';

$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'help';

// Fetch all tools
$tools = [];
$agents = [];
if ($pdo) {
    $stmt = $pdo->query('SELECT * FROM tools ORDER BY agent, tool_name');
    $tools = $stmt->fetchAll();
    $agents = array_unique(array_column($tools, 'agent'));
    sort($agents);
}

$agentLabels = [
    'any' => 'General',
    'claude_code' => 'Claude Code',
    'claudia' => 'Claudia',
    'server_cron' => 'Server Cron',
];

$agentColors = [
    'any' => '#90caf9',
    'claude_code' => '#d4af37',
    'claudia' => '#e4bf47',
    'server_cron' => '#66bb6a',
];

$pageStyles = <<<'CSS'
.tools-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
}
.tools-container h1 {
    color: #d4af37;
    margin-bottom: 0.25rem;
    font-size: 1.8rem;
}
.tools-subtitle {
    color: #b0b0b0;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}
.tools-search {
    width: 100%;
    padding: 0.7rem 1rem;
    background: #0a0a0f;
    border: 1px solid #333;
    color: #e0e0e0;
    border-radius: 8px;
    font-size: 0.95rem;
    margin-bottom: 1.5rem;
    transition: border-color 0.2s;
}
.tools-search:focus {
    outline: none;
    border-color: #d4af37;
}
.tools-stats {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.stat-chip {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 20px;
    padding: 0.3rem 0.8rem;
    font-size: 0.8rem;
    color: #ccc;
    cursor: pointer;
    transition: all 0.2s;
}
.stat-chip:hover, .stat-chip.active {
    border-color: #d4af37;
    color: #d4af37;
}
.stat-chip .count {
    font-weight: 700;
    margin-left: 0.3rem;
}
.agent-group {
    margin-bottom: 2rem;
}
.agent-group h2 {
    font-size: 1.1rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.agent-badge {
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}
.tool-card {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-bottom: 0.75rem;
    transition: border-color 0.2s;
}
.tool-card:hover {
    border-color: #d4af37;
}
.tool-card.disabled {
    opacity: 0.5;
}
.tool-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.4rem;
}
.tool-name {
    color: #fff;
    font-weight: 600;
    font-size: 0.95rem;
}
.tool-key {
    color: #888;
    font-family: monospace;
    font-size: 0.75rem;
    background: rgba(255,255,255,0.05);
    padding: 2px 6px;
    border-radius: 4px;
}
.tool-desc {
    color: #b0b0b0;
    font-size: 0.85rem;
    line-height: 1.5;
    margin-bottom: 0.6rem;
}
.tool-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.78rem;
}
.tool-meta-item {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}
.tool-meta-label {
    color: #888;
}
.tool-meta-value {
    color: #ccc;
    font-family: monospace;
    font-size: 0.75rem;
    background: rgba(255,255,255,0.05);
    padding: 1px 5px;
    border-radius: 3px;
    max-width: 400px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.tool-invocation {
    margin-top: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: #0a0a0f;
    border: 1px solid #2a2a3e;
    border-radius: 6px;
    font-family: monospace;
    font-size: 0.75rem;
    color: #b0b0b0;
    white-space: pre-wrap;
    word-break: break-all;
    display: none;
}
.tool-card.expanded .tool-invocation {
    display: block;
}
.tool-expand {
    color: #888;
    font-size: 0.75rem;
    cursor: pointer;
    margin-top: 0.4rem;
    display: inline-block;
}
.tool-expand:hover {
    color: #d4af37;
}
.no-results {
    color: #888;
    text-align: center;
    padding: 2rem;
    font-style: italic;
}
@media (max-width: 600px) {
    .tools-container { padding: 1rem 0.75rem 2rem; }
    .tools-container h1 { font-size: 1.4rem; }
    .tool-header { flex-direction: column; gap: 0.3rem; }
    .tool-meta { flex-direction: column; }
    .tool-meta-value { max-width: 250px; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<div class="tools-container">
    <h1>Tools Dashboard</h1>
    <p class="tools-subtitle"><?= count($tools) ?> tools, scripts, pipelines, and capabilities across the platform.</p>

    <input type="text" class="tools-search" id="toolsSearch" placeholder="Search tools by name, description, location, or key...">

    <div class="tools-stats">
        <span class="stat-chip active" data-filter="all">All <span class="count"><?= count($tools) ?></span></span>
        <?php foreach ($agents as $agent): ?>
        <?php
            $label = $agentLabels[$agent] ?? ucfirst($agent);
            $agentCount = count(array_filter($tools, fn($t) => $t['agent'] === $agent));
            $color = $agentColors[$agent] ?? '#ccc';
        ?>
        <span class="stat-chip" data-filter="<?= htmlspecialchars($agent) ?>" style="--chip-color: <?= $color ?>"><?= htmlspecialchars($label) ?> <span class="count"><?= $agentCount ?></span></span>
        <?php endforeach; ?>
    </div>

    <?php
    $grouped = [];
    foreach ($tools as $t) {
        $grouped[$t['agent']][] = $t;
    }
    ?>

    <?php foreach ($grouped as $agent => $agentTools): ?>
    <?php
        $label = $agentLabels[$agent] ?? ucfirst($agent);
        $color = $agentColors[$agent] ?? '#ccc';
    ?>
    <div class="agent-group" data-agent="<?= htmlspecialchars($agent) ?>">
        <h2 style="color: <?= $color ?>">
            <?= htmlspecialchars($label) ?>
            <span class="agent-badge" style="background: <?= $color ?>20; color: <?= $color ?>; border: 1px solid <?= $color ?>50;"><?= count($agentTools) ?> tools</span>
        </h2>

        <?php foreach ($agentTools as $t): ?>
        <div class="tool-card <?= $t['enabled'] ? '' : 'disabled' ?>"
             data-name="<?= htmlspecialchars(strtolower($t['tool_name'])) ?>"
             data-key="<?= htmlspecialchars(strtolower($t['tool_key'])) ?>"
             data-desc="<?= htmlspecialchars(strtolower($t['description'] ?? '')) ?>"
             data-location="<?= htmlspecialchars(strtolower($t['location'] ?? '')) ?>"
             data-agent="<?= htmlspecialchars($t['agent']) ?>">
            <div class="tool-header">
                <span class="tool-name"><?= htmlspecialchars($t['tool_name']) ?><?= $t['enabled'] ? '' : ' (disabled)' ?></span>
                <span class="tool-key"><?= htmlspecialchars($t['tool_key']) ?></span>
            </div>
            <div class="tool-desc"><?= htmlspecialchars($t['description'] ?? '') ?></div>
            <div class="tool-meta">
                <?php if ($t['location']): ?>
                <div class="tool-meta-item">
                    <span class="tool-meta-label">Location:</span>
                    <span class="tool-meta-value" title="<?= htmlspecialchars($t['location']) ?>"><?= htmlspecialchars($t['location']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($t['prerequisites']): ?>
                <div class="tool-meta-item">
                    <span class="tool-meta-label">Requires:</span>
                    <span class="tool-meta-value"><?= htmlspecialchars($t['prerequisites']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($t['last_used_at']): ?>
                <div class="tool-meta-item">
                    <span class="tool-meta-label">Last used:</span>
                    <span class="tool-meta-value"><?= htmlspecialchars($t['last_used_at']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($t['invocation']): ?>
            <span class="tool-expand" onclick="this.parentElement.classList.toggle('expanded')">Show invocation ▾</span>
            <div class="tool-invocation"><?= htmlspecialchars($t['invocation']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="no-results" id="noResults" style="display:none;">No tools match your search.</div>
</div>

<script>
(function() {
    var search = document.getElementById('toolsSearch');
    var cards = document.querySelectorAll('.tool-card');
    var groups = document.querySelectorAll('.agent-group');
    var chips = document.querySelectorAll('.stat-chip');
    var noResults = document.getElementById('noResults');
    var activeFilter = 'all';

    function filterTools() {
        var q = search.value.toLowerCase().trim();
        var visible = 0;

        cards.forEach(function(card) {
            var matchesSearch = !q ||
                card.dataset.name.indexOf(q) !== -1 ||
                card.dataset.key.indexOf(q) !== -1 ||
                card.dataset.desc.indexOf(q) !== -1 ||
                card.dataset.location.indexOf(q) !== -1;
            var matchesAgent = activeFilter === 'all' || card.dataset.agent === activeFilter;
            var show = matchesSearch && matchesAgent;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        groups.forEach(function(g) {
            var hasVisible = g.querySelectorAll('.tool-card:not([style*="display: none"])').length > 0;
            g.style.display = hasVisible ? '' : 'none';
        });

        noResults.style.display = visible === 0 ? '' : 'none';
    }

    search.addEventListener('input', filterTools);

    chips.forEach(function(chip) {
        chip.addEventListener('click', function() {
            chips.forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            activeFilter = chip.dataset.filter;
            filterTools();
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
