<?php
/**
 * User Guide Renderer
 * ===================
 * Reads a JSON manifest from help/data/ and renders a step-by-step
 * visual guide with site header/footer/nav.
 *
 * Usage: /help/guide.php?flow=onboarding
 */

$config = require dirname(__DIR__) . '/config.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { $pdo = null; }

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : false;
$isLoggedIn = (bool)$dbUser;

// Sanitize flow parameter
$flowId = isset($_GET['flow']) ? preg_replace('/[^a-z0-9-]/', '', $_GET['flow']) : '';
$dataFile = __DIR__ . '/data/' . $flowId . '.json';

if (!$flowId || !file_exists($dataFile)) {
    http_response_code(404);
    $pageTitle = 'Guide Not Found | The People\'s Branch';
    $navVars = getNavVarsForUser($dbUser);
    extract($navVars);
    $currentPage = 'help';
    require dirname(__DIR__) . '/includes/header.php';
    require dirname(__DIR__) . '/includes/nav.php';
    echo '<div style="max-width:600px;margin:4rem auto;text-align:center;color:#888;">';
    echo '<h2 style="color:#ff6666;">Guide not found</h2>';
    echo '<p>The guide you requested doesn\'t exist.</p>';
    echo '<p><a href="/help/" style="color:#d4af37;">Browse all guides</a></p>';
    echo '</div>';
    require dirname(__DIR__) . '/includes/footer.php';
    exit;
}

$guide = json_decode(file_get_contents($dataFile), true);
if (!$guide) {
    http_response_code(500);
    die('Error reading guide data.');
}

// Page setup
$pageTitle = $guide['title'] . ' — User Guide | The People\'s Branch';
$ogDescription = $guide['subtitle'];
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'help';

$pageStyles = <<<'CSS'
.guide-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
}
.guide-header {
    text-align: center;
    margin-bottom: 2.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #333;
}
.guide-header h1 {
    color: #d4af37;
    font-size: 1.8rem;
    margin-bottom: 0.5rem;
}
.guide-header .guide-subtitle {
    color: #888;
    font-size: 1rem;
    margin-bottom: 0.75rem;
}
.guide-header .guide-meta {
    color: #555;
    font-size: 0.75rem;
}
.guide-steps {
    list-style: none;
    padding: 0;
    margin: 0;
}
.guide-step {
    display: flex;
    gap: 1rem;
    margin-bottom: 2.5rem;
    align-items: flex-start;
}
.step-number {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    background: #d4af37;
    color: #000;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    margin-top: 2px;
}
.step-content {
    flex: 1;
    min-width: 0;
}
.step-content h3 {
    color: #fff;
    font-size: 1.15rem;
    margin-bottom: 0.4rem;
}
.step-content p {
    color: #bbb;
    font-size: 0.95rem;
    line-height: 1.65;
    margin-bottom: 0.75rem;
}
.step-content img {
    width: 100%;
    border-radius: 8px;
    border: 1px solid #333;
    box-shadow: 0 2px 12px rgba(0,0,0,0.4);
    margin-top: 0.5rem;
}
.guide-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid #333;
    color: #666;
    font-size: 0.85rem;
}
.guide-footer a { color: #d4af37; text-decoration: none; }
.guide-footer a:hover { text-decoration: underline; }

/* No-screenshot step: info card */
.step-info-card {
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 8px;
    padding: 1rem 1.25rem;
    margin-top: 0.5rem;
    color: #aaa;
    font-size: 0.9rem;
    line-height: 1.5;
}

@media (max-width: 600px) {
    .guide-step { gap: 0.75rem; }
    .step-number { width: 32px; height: 32px; font-size: 0.9rem; }
    .step-content h3 { font-size: 1rem; }
    .step-content p { font-size: 0.9rem; }
    .guide-header h1 { font-size: 1.4rem; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<div class="guide-container">
    <div class="guide-header">
        <h1><?= htmlspecialchars($guide['title']) ?></h1>
        <p class="guide-subtitle"><?= htmlspecialchars($guide['subtitle']) ?></p>
        <p class="guide-meta"><?= $guide['stepCount'] ?> steps &middot; Updated <?= date('M j, Y', strtotime($guide['generated'])) ?></p>
    </div>

    <ol class="guide-steps">
<?php foreach ($guide['steps'] as $step): ?>
        <li class="guide-step">
            <div class="step-number"><?= $step['number'] ?></div>
            <div class="step-content">
                <h3><?= htmlspecialchars($step['title']) ?></h3>
                <p><?= htmlspecialchars($step['description']) ?></p>
<?php if ($step['screenshot']): ?>
                <img src="/help/screenshots/<?= htmlspecialchars($step['screenshot']) ?>"
                     alt="<?= htmlspecialchars($step['alt'] ?? $step['title']) ?>"
                     loading="lazy">
<?php endif; ?>
<?php if (!empty($step['link'])): ?>
                <div class="step-info-card">
                    <a href="<?= htmlspecialchars($step['link']['url']) ?>" style="color:#d4af37;text-decoration:none;font-weight:600;"><?= htmlspecialchars($step['link']['label']) ?> &rarr;</a>
                </div>
<?php endif; ?>
            </div>
        </li>
<?php endforeach; ?>
    </ol>

    <div class="guide-footer">
        <p><a href="/help/">&larr; All Guides</a></p>
        <p>Screenshots auto-generated from the live site.</p>
    </div>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
