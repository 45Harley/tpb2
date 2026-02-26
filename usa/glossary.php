<?php
/**
 * Congressional Glossary — standalone reference page.
 * 130+ terms in plain English, grouped by category.
 * Linked from scorecard/digest pages via cg() tooltip links.
 *
 * Usage:
 *   /usa/glossary.php               (full glossary)
 *   /usa/glossary.php?term=cloture  (jump to specific term)
 *   /usa/glossary.php?cat=Budget    (jump to category)
 *   /usa/glossary.php?q=filibuster  (search)
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/congressional-glossary.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Congressional Glossary';
$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$termSlug = $_GET['term'] ?? null;
$catFilter = $_GET['cat'] ?? null;
$search = trim($_GET['q'] ?? '');
$grouped = cg_grouped();
$allTerms = congressionalGlossary();

// Category display order and icons
$catMeta = [
    'Voting'              => ['icon' => "\xF0\x9F\x97\xB3", 'desc' => 'How Congress records decisions'],
    'Motions'             => ['icon' => "\xE2\x9A\x96",     'desc' => 'Procedural moves on the floor'],
    'Process'             => ['icon' => "\xF0\x9F\x94\x84", 'desc' => 'How a bill becomes law'],
    'Rules'               => ['icon' => "\xF0\x9F\x93\x8F", 'desc' => 'Vote thresholds and requirements'],
    'Bills & Resolutions' => ['icon' => "\xF0\x9F\x93\x9C", 'desc' => 'Types of legislation'],
    'Amendments'          => ['icon' => "\xE2\x9C\x8F",     'desc' => 'Changes to bills'],
    'Budget'              => ['icon' => "\xF0\x9F\x92\xB0", 'desc' => 'How Congress spends your money'],
    'Leadership'          => ['icon' => "\xF0\x9F\x8F\x9B",  'desc' => 'Who runs Congress'],
    'Structure'           => ['icon' => "\xF0\x9F\x8F\x97",  'desc' => 'How Congress is organized'],
    'Nominations'         => ['icon' => "\xF0\x9F\x91\xA4", 'desc' => 'Presidential appointments'],
    'Constitutional'      => ['icon' => "\xF0\x9F\x93\x9C", 'desc' => 'Powers and principles from the Constitution'],
    'Electoral'           => ['icon' => "\xF0\x9F\x93\x8D", 'desc' => 'Districts, elections, and representation'],
    'Metrics'             => ['icon' => "\xF0\x9F\x93\x8A", 'desc' => 'How we measure Congress'],
    'Documents'           => ['icon' => "\xF0\x9F\x93\x84", 'desc' => 'Official reports and communications'],
];

// Order grouped by catMeta order, then any remaining
$ordered = [];
foreach (array_keys($catMeta) as $cat) {
    if (isset($grouped[$cat])) $ordered[$cat] = $grouped[$cat];
}
foreach ($grouped as $cat => $terms) {
    if (!isset($ordered[$cat])) $ordered[$cat] = $terms;
}

// Search filter
if ($search) {
    $q = strtolower($search);
    $filtered = [];
    foreach ($ordered as $cat => $terms) {
        foreach ($terms as $slug => $entry) {
            if (str_contains(strtolower($entry['term']), $q) || str_contains(strtolower($entry['short']), $q) || str_contains($slug, $q)) {
                $filtered[$cat][$slug] = $entry;
            }
        }
    }
    $ordered = $filtered;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Congressional Glossary — The People's Branch</title>
<style>
:root {
    --bg: #0a0e1a; --card: #141929; --card-hover: #1a2035; --border: #252d44;
    --text: #e8eaf0; --muted: #8892a8; --accent: #4a9eff; --gold: #f0b429;
    --green: #34d399; --red: #f87171;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* Hero */
.page-hero {
    padding: 32px;
    background: linear-gradient(135deg, #0f1628, #1a2544);
    border-bottom: 1px solid var(--border);
}
.page-hero h1 { font-size: 24px; margin-bottom: 8px; }
.page-hero .subtitle { font-size: 14px; color: var(--muted); max-width: 600px; }
.page-hero .stats { margin-top: 12px; font-size: 13px; color: var(--muted); }
.page-hero .stats strong { color: var(--gold); }

/* Search */
.search-bar {
    padding: 16px 32px;
    background: #0d1220;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.search-bar input {
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 14px;
    border-radius: 6px;
    font-size: 14px;
    width: 300px;
    max-width: 100%;
    outline: none;
}
.search-bar input:focus { border-color: var(--accent); }
.search-bar input::placeholder { color: var(--muted); }
.search-clear { font-size: 12px; color: var(--muted); }

/* Category nav */
.cat-nav {
    padding: 12px 32px;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.cat-chip {
    display: inline-block;
    padding: 4px 12px;
    font-size: 12px;
    border-radius: 14px;
    background: rgba(255,255,255,0.05);
    color: var(--muted);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}
.cat-chip:hover, .cat-chip.active {
    background: rgba(74,158,255,0.15);
    color: var(--accent);
    text-decoration: none;
}
.cat-chip .chip-count {
    font-size: 10px;
    opacity: 0.6;
    margin-left: 4px;
}

/* Category sections */
.cat-section {
    border-bottom: 1px solid var(--border);
}
.cat-header {
    padding: 20px 32px 12px;
    background: var(--card);
    position: sticky;
    top: 0;
    z-index: 10;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.cat-header h2 {
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cat-header h2 .cat-icon { font-size: 18px; }
.cat-header .cat-desc {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
    margin-left: 28px;
}

/* Term cards */
.term-list { padding: 0 32px 16px; background: var(--card); }
.term-card {
    padding: 14px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 16px;
    align-items: baseline;
}
.term-card:last-child { border-bottom: none; }
.term-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
}
.term-name .term-slug {
    display: block;
    font-size: 11px;
    font-weight: 400;
    color: rgba(136,146,168,0.5);
    font-family: monospace;
    margin-top: 2px;
}
.term-def {
    font-size: 14px;
    color: var(--muted);
    line-height: 1.7;
}
.term-highlight {
    background: var(--card-hover);
    margin: 0 -32px;
    padding: 14px 32px;
    border-left: 3px solid var(--gold);
    border-bottom: 1px solid rgba(255,255,255,0.04);
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 16px;
    align-items: baseline;
}
.term-highlight .term-name { color: var(--gold); }
.term-highlight .term-def { color: var(--text); }

/* Empty state */
.empty-state {
    padding: 60px 32px;
    text-align: center;
    color: var(--muted);
}
.empty-state h3 { font-size: 18px; margin-bottom: 8px; color: var(--text); }

/* Footer */
.footer {
    padding: 20px 32px;
    font-size: 12px;
    color: var(--muted);
    text-align: center;
    border-top: 1px solid var(--border);
}

/* Back link */
.breadcrumb {
    padding: 12px 32px;
    background: #0d1220;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    color: var(--muted);
}
.breadcrumb a { color: var(--muted); }
.breadcrumb a:hover { color: var(--text); }

/* Responsive */
@media (max-width: 768px) {
    .page-hero, .search-bar, .cat-nav, .cat-header, .term-list { padding-left: 16px; padding-right: 16px; }
    .term-card, .term-highlight {
        grid-template-columns: 1fr;
        gap: 4px;
    }
    .term-highlight { margin: 0 -16px; padding: 14px 16px; }
    .search-bar input { width: 100%; }
}
</style>
</head>
<body>
<?php require dirname(__DIR__) . '/includes/header.php'; require dirname(__DIR__) . '/includes/nav.php'; ?>

<div class="breadcrumb">
    <a href="/usa/">USA</a> &rsaquo; <a href="/usa/digest.php" style="color:var(--muted)">Digest</a> &rsaquo; <span style="color:var(--text)">Glossary</span>
</div>

<div class="page-hero">
    <h1>Congressional Glossary</h1>
    <p class="subtitle">Every term Congress uses, translated into plain English. Because democracy shouldn't require a law degree.</p>
    <div class="stats">
        <strong><?= count($allTerms) ?></strong> terms across <strong><?= count($grouped) ?></strong> categories
        <?php if ($search): ?> &mdash; showing matches for "<strong style="color:var(--accent)"><?= htmlspecialchars($search) ?></strong>"<?php endif; ?>
    </div>
</div>

<!-- Search -->
<div class="search-bar">
    <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;width:100%;">
        <input type="text" name="q" placeholder="Search terms or definitions..." value="<?= htmlspecialchars($search) ?>" autofocus>
        <?php if ($search): ?>
            <a href="glossary.php" class="search-clear">Clear search</a>
        <?php endif; ?>
    </form>
</div>

<!-- Category jump nav -->
<?php if (!$search): ?>
<nav class="cat-nav">
    <?php foreach ($ordered as $cat => $terms): ?>
        <a class="cat-chip<?= ($catFilter === $cat) ? ' active' : '' ?>" href="#cat-<?= urlencode($cat) ?>">
            <?= $catMeta[$cat]['icon'] ?? '' ?> <?= $cat ?><span class="chip-count"><?= count($terms) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<!-- Terms -->
<?php if (empty($ordered)): ?>
    <div class="empty-state">
        <h3>No matches found</h3>
        <p>Try a different search term, or <a href="glossary.php">browse all <?= count($allTerms) ?> terms</a>.</p>
    </div>
<?php else: ?>
    <?php foreach ($ordered as $cat => $terms):
        // Sort terms alphabetically within category
        uasort($terms, fn($a, $b) => strcasecmp($a['term'], $b['term']));
    ?>
    <div class="cat-section" id="cat-<?= urlencode($cat) ?>">
        <div class="cat-header">
            <h2><span class="cat-icon"><?= $catMeta[$cat]['icon'] ?? '' ?></span> <?= htmlspecialchars($cat) ?></h2>
            <?php if (isset($catMeta[$cat]['desc'])): ?>
                <div class="cat-desc"><?= htmlspecialchars($catMeta[$cat]['desc']) ?></div>
            <?php endif; ?>
        </div>
        <div class="term-list">
            <?php foreach ($terms as $slug => $entry):
                $isTarget = ($termSlug === $slug);
                $cardClass = $isTarget ? 'term-highlight' : 'term-card';
            ?>
            <div class="<?= $cardClass ?>" id="term-<?= urlencode($slug) ?>">
                <div class="term-name">
                    <?= htmlspecialchars($entry['term']) ?>
                </div>
                <div class="term-def"><?= htmlspecialchars($entry['short']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<div class="footer">
    119th Congress &middot; <strong style="color:var(--gold)">The People's Branch</strong>
    &middot; <a href="/usa/">Map</a> &middot; <a href="/usa/digest.php">Digest</a>
</div>

<?php if ($termSlug): ?>
<script>
// Scroll to highlighted term on load
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('term-<?= urlencode($termSlug) ?>');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
</script>
<?php endif; ?>

</body>
</html>
