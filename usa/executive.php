<?php
/**
 * Executive Branch — placeholder page.
 * Will cover: President, Cabinet, Executive Orders, Federal Agencies.
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Executive Branch — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$pageStyles = <<<'CSS'
.exec-page { max-width: 900px; margin: 0 auto; padding: 40px 32px; }
.exec-page h1 { font-size: 28px; margin-bottom: 12px; }
.exec-page .subtitle { color: #8892a8; font-size: 15px; margin-bottom: 32px; }
.exec-page .coming { background: #141929; border: 1px solid #252d44; border-radius: 10px; padding: 48px 32px; text-align: center; }
.exec-page .coming h2 { font-size: 20px; color: #f0b429; margin-bottom: 8px; }
.exec-page .coming p { color: #8892a8; font-size: 14px; }
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="exec-page">
    <h1>Executive Branch</h1>
    <p class="subtitle">The President, Cabinet, Executive Orders, and Federal Agencies</p>

    <div class="coming">
        <h2>Coming Soon</h2>
        <p>Executive branch tracking is under development.</p>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
