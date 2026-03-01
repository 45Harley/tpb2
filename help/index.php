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

// Icons per guide ID (extend as new guides are added)
$guideIcons = [
    'onboarding' => '&#x1F680;',
    'talk'       => '&#x1F4AC;',
    'elections'  => '&#x1F5F3;',
    'polls'      => '&#x1F4CA;',
    'volunteer'  => '&#x1F91D;',
];

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
    color: #888;
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
.help-card .card-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
}
.help-card h3 {
    color: #fff;
    font-size: 1.1rem;
    margin-bottom: 0.4rem;
}
.help-card p {
    color: #888;
    font-size: 0.9rem;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}
.help-card .card-meta {
    color: #555;
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
    color: #666;
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
    <h2 class="help-section-title">Visual Guides</h2>
    <div class="help-grid">
<?php foreach ($guides as $g): ?>
        <a href="/help/guide.php?flow=<?= htmlspecialchars($g['id']) ?>" class="help-card">
            <div class="card-icon"><?= $guideIcons[$g['id']] ?? '&#x1F4D6;' ?></div>
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
    </ul>
</div>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
