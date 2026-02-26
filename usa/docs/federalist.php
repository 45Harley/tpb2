<?php
/**
 * Federalist Papers — The People's Branch
 * Index of the 85 essays by Hamilton, Madison, and Jay.
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Federalist Papers — The People\'s Branch';

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
    font-size: 1.1rem;
    line-height: 1.9;
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
.paper-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.paper-list li {
    padding: 10px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    display: flex;
    gap: 12px;
    font-size: 14px;
    color: #a0aec0;
    line-height: 1.5;
}
.paper-list li:last-child { border-bottom: none; }
.paper-num {
    color: #d4af37;
    font-weight: 600;
    min-width: 28px;
    text-align: right;
}
.paper-author {
    color: #6b7394;
    font-size: 12px;
    margin-left: auto;
    white-space: nowrap;
}
.author-tag {
    display: inline-block;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}
.author-hamilton { background: rgba(59,130,246,0.15); color: #60a5fa; }
.author-madison { background: rgba(52,211,153,0.15); color: #34d399; }
.author-jay { background: rgba(240,180,41,0.15); color: #f0b429; }
.author-hm { background: rgba(167,139,250,0.15); color: #a78bfa; }
.doc-back { margin-top: 32px; font-size: 14px; }
.doc-back a { color: #8892a8; text-decoration: none; }
.doc-back a:hover { color: #d4af37; }
.doc-source { font-size: 12px; color: #6b7394; text-align: center; margin-top: 24px; }
.doc-source a { color: #8892a8; }
@media (max-width: 600px) {
    .doc-page { padding: 24px 16px; }
    .paper-list li { flex-wrap: wrap; gap: 4px; }
    .paper-author { margin-left: 40px; }
}
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="doc-page">
    <h1>The Federalist Papers</h1>
    <p class="subtitle">1787&ndash;1788 &mdash; 85 essays by Alexander Hamilton, James Madison, and John Jay</p>

    <div class="doc-section">
        <div class="section-header">
            <h2>About</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                Published under the pen name "Publius," the Federalist Papers were written to persuade New York citizens to ratify the new Constitution. They remain the most authoritative commentary on the Constitution's meaning and intent.
            </div>
            <div class="doc-note">
                <strong>Authors:</strong>
                <span class="author-tag author-hamilton">Hamilton</span> wrote 51 essays,
                <span class="author-tag author-madison">Madison</span> wrote 29,
                <span class="author-tag author-jay">Jay</span> wrote 5.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Key Papers</h2>
        </div>
        <div class="section-body">
            <ul class="paper-list">
                <li><span class="paper-num">1</span> General Introduction <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
                <li><span class="paper-num">10</span> The problem of factions and the case for a large republic <span class="paper-author"><span class="author-tag author-madison">Madison</span></span></li>
                <li><span class="paper-num">14</span> The size of the republic is not an obstacle to union <span class="paper-author"><span class="author-tag author-madison">Madison</span></span></li>
                <li><span class="paper-num">39</span> The new government is both national and federal <span class="paper-author"><span class="author-tag author-madison">Madison</span></span></li>
                <li><span class="paper-num">47</span> Separation of powers explained <span class="paper-author"><span class="author-tag author-madison">Madison</span></span></li>
                <li><span class="paper-num">51</span> Checks and balances: "Ambition must be made to counteract ambition" <span class="paper-author"><span class="author-tag author-madison">Madison</span></span></li>
                <li><span class="paper-num">68</span> The Electoral College <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
                <li><span class="paper-num">70</span> The case for a strong executive <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
                <li><span class="paper-num">78</span> Judicial review and the independence of courts <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
                <li><span class="paper-num">84</span> Why a Bill of Rights is unnecessary (and potentially dangerous) <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
                <li><span class="paper-num">85</span> Concluding remarks <span class="paper-author"><span class="author-tag author-hamilton">Hamilton</span></span></li>
            </ul>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Most Quoted Passages</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                "If men were angels, no government would be necessary. If angels were to govern men, neither external nor internal controls on government would be necessary."
            </div>
            <div class="doc-note">&mdash; Federalist No. 51, <span class="author-tag author-madison">Madison</span></div>

            <div class="original-text" style="margin-top: 20px;">
                "The accumulation of all powers, legislative, executive, and judiciary, in the same hands, whether of one, a few, or many, and whether hereditary, self-appointed, or elective, may justly be pronounced the very definition of tyranny."
            </div>
            <div class="doc-note">&mdash; Federalist No. 47, <span class="author-tag author-madison">Madison</span></div>

            <div class="original-text" style="margin-top: 20px;">
                "Energy in the Executive is a leading character in the definition of good government."
            </div>
            <div class="doc-note">&mdash; Federalist No. 70, <span class="author-tag author-hamilton">Hamilton</span></div>
        </div>
    </div>

    <div class="doc-source">
        Full text: <a href="https://guides.loc.gov/federalist-papers/full-text" target="_blank">Library of Congress</a> &middot;
        <a href="https://avalon.law.yale.edu/subject_menus/fed.asp" target="_blank">Yale Avalon Project</a>
    </div>

    <div class="doc-back">
        <a href="/usa/docs/">&larr; All Documents</a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
