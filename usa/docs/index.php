<?php
/**
 * Founding Documents — listing page.
 * Links to Constitution, Declaration, Gettysburg Address, etc.
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Founding Documents — The People\'s Branch';

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
.docs-page { max-width: 900px; margin: 0 auto; padding: 40px 32px; }
.docs-page h1 { font-size: 28px; margin-bottom: 8px; }
.docs-page .subtitle { color: #8892a8; font-size: 15px; margin-bottom: 32px; }
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.doc-card {
    background: #141929;
    border: 1px solid #252d44;
    border-radius: 10px;
    padding: 24px;
    transition: border-color 0.2s, background 0.2s;
    text-decoration: none;
    display: block;
}
.doc-card:hover { border-color: #d4af37; background: #1a2035; text-decoration: none; }
.doc-card h3 { font-size: 18px; color: #f0f2f8; margin-bottom: 8px; }
.doc-card .doc-year { font-size: 13px; color: #d4af37; margin-bottom: 8px; }
.doc-card p { font-size: 13px; color: #8892a8; line-height: 1.5; }
.doc-card.coming { opacity: 0.5; pointer-events: none; }
.doc-card.coming h3::after { content: ' — Coming Soon'; font-size: 12px; color: #6b7394; }
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="docs-page">
    <h1>Founding Documents</h1>
    <p class="subtitle">The words that built a nation — in their original form, with plain-language interpretation.</p>

    <div class="doc-grid">
        <a class="doc-card" href="/usa/docs/oath.php">
            <h3>Oath of Office</h3>
            <div class="doc-year">1789</div>
            <p>The sacred promise every president makes to preserve, protect, and defend.</p>
        </a>

        <a class="doc-card" href="/usa/docs/declaration.php">
            <h3>Declaration of Independence</h3>
            <div class="doc-year">1776</div>
            <p>The document that declared a new nation and the rights of its people.</p>
        </a>

        <a class="doc-card" href="/usa/docs/constitution.php">
            <h3>The Constitution</h3>
            <div class="doc-year">1787</div>
            <p>The supreme law of the United States. Articles, amendments, and what they mean for you today.</p>
        </a>

        <a class="doc-card" href="/usa/docs/gettysburg.php">
            <h3>Gettysburg Address</h3>
            <div class="doc-year">1863</div>
            <p>Lincoln's 272 words that redefined the meaning of American democracy.</p>
        </a>

        <a class="doc-card" href="/usa/docs/federalist.php">
            <h3>Federalist Papers</h3>
            <div class="doc-year">1787&ndash;1788</div>
            <p>Hamilton, Madison, and Jay's arguments for ratifying the Constitution.</p>
        </a>

        <a class="doc-card" href="/usa/docs/birmingham.php">
            <h3>Letter from Birmingham Jail</h3>
            <div class="doc-year">1963</div>
            <p>Dr. King's 7,000-word blueprint for civic engagement, written on scraps of newspaper in a jail cell.</p>
        </a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
