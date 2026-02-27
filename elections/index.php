<?php
/**
 * Elections — Coming Soon
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'Elections — The People\'s Branch';

$pageStyles = <<<'CSS'
.elections-placeholder {
    max-width: 700px;
    margin: 60px auto;
    padding: 48px 32px;
    text-align: center;
    background: #1a1a2e;
    border: 1px solid #333;
    border-radius: 10px;
}
.elections-placeholder h2 {
    color: #d4af37;
    font-size: 1.6em;
    margin-bottom: 12px;
}
.elections-placeholder p {
    color: #888;
    font-size: 0.95em;
    line-height: 1.6;
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<div class="elections-placeholder">
    <h2>Elections</h2>
    <p>Coming soon. Track races, candidates, and results across federal, state, and local elections.</p>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
