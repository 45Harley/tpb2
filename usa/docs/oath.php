<?php
/**
 * Oath of Office — Presidential oath per Article II, Section 1 of the Constitution.
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Oath of Office — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive-overview.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$pageStyles = <<<'CSS'
.doc-page { max-width: 800px; margin: 0 auto; padding: 40px 32px; }
.doc-page h1 { font-size: 28px; margin-bottom: 8px; }
.doc-page .subtitle { color: #8892a8; font-size: 15px; margin-bottom: 32px; }
.doc-section {
    background: #141929;
    border: 1px solid #252d44;
    border-radius: 10px;
    margin-bottom: 24px;
    overflow: hidden;
}
.doc-section .section-header {
    background: #0d1220;
    padding: 16px 24px;
    border-bottom: 1px solid #252d44;
}
.doc-section .section-header h2 {
    color: #d4af37;
    font-size: 18px;
    font-weight: 500;
    margin: 0;
}
.doc-section .section-body { padding: 24px; }
.original-text {
    color: #e8eaf0;
    font-size: 1.15rem;
    line-height: 2;
    padding: 24px;
    background: #0d1220;
    border-left: 3px solid #d4af37;
    border-radius: 4px;
    font-family: Georgia, serif;
}
.doc-note {
    color: #8892a8;
    font-size: 14px;
    line-height: 1.7;
    margin-top: 16px;
}
.doc-note strong { color: #e8eaf0; }
.doc-back { margin-top: 32px; font-size: 14px; }
.doc-back a { color: #8892a8; text-decoration: none; }
.doc-back a:hover { color: #d4af37; }
@media (max-width: 600px) {
    .doc-page { padding: 24px 16px; }
    .original-text { padding: 16px; font-size: 1.05rem; }
}
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="doc-page">
    <h1>Oath of Office</h1>
    <p class="subtitle">Article II, Section 1, Clause 8 of the United States Constitution</p>

    <div class="doc-section">
        <div class="section-header">
            <h2>Presidential Oath of Office</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                "I do solemnly swear (or affirm) that I will faithfully execute the Office of President of the United States, and will to the best of my Ability, preserve, protect and defend the Constitution of the United States."
            </div>
            <div class="doc-note">
                <strong>35 words.</strong> Every president since George Washington has taken this oath before assuming office. The Constitution specifies the exact wording. The phrase "So help me God" is traditionally added but is not part of the constitutional text.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Congressional Oath of Office</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                "I do solemnly swear (or affirm) that I will support and defend the Constitution of the United States against all enemies, foreign and domestic; that I will bear true faith and allegiance to the same; that I take this obligation freely, without any mental reservation or purpose of evasion; and that I will well and faithfully discharge the duties of the office on which I am about to enter. So help me God."
            </div>
            <div class="doc-note">
                <strong>Required by Article VI.</strong> All senators, representatives, and other government officers take this oath. The current form was established by the 37th Congress in 1862 during the Civil War, replacing a simpler 14-word version from 1789.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Judicial Oath</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                "I do solemnly swear (or affirm) that I will administer justice without respect to persons, and do equal right to the poor and to the rich, and that I will faithfully and impartially discharge and perform all the duties incumbent upon me under the Constitution and laws of the United States. So help me God."
            </div>
            <div class="doc-note">
                <strong>Established by the Judiciary Act of 1789.</strong> Federal judges take both this judicial oath and the constitutional oath required of all government officers. The promise to do "equal right to the poor and to the rich" reflects the foundational principle of equal justice under law.
            </div>
        </div>
    </div>

    <div class="doc-back">
        <a href="/usa/docs/">&larr; All Documents</a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
