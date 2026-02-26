<?php
/**
 * Gettysburg Address — The People's Branch
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Gettysburg Address — The People\'s Branch';

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
.original-text p { margin-bottom: 20px; }
.original-text p:last-child { margin-bottom: 0; }
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
.doc-source { font-size: 12px; color: #6b7394; text-align: center; margin-top: 24px; }
.doc-source a { color: #8892a8; }
@media (max-width: 600px) {
    .doc-page { padding: 24px 16px; }
    .original-text { padding: 16px; font-size: 1.05rem; }
}
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="doc-page">
    <h1>The Gettysburg Address</h1>
    <p class="subtitle">November 19, 1863 — President Abraham Lincoln, Gettysburg, Pennsylvania</p>

    <div class="doc-section">
        <div class="section-header">
            <h2>The Address</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>Four score and seven years ago our fathers brought forth on this continent, a new nation, conceived in Liberty, and dedicated to the proposition that all men are created equal.</p>

                <p>Now we are engaged in a great civil conflict, testing whether that nation, or any nation so conceived and so dedicated, can long endure. We are met on a great field of that struggle. We have come to dedicate a portion of that field, as a final resting place for those who here gave their lives that that nation might live. It is altogether fitting and proper that we should do this.</p>

                <p>But, in a larger sense, we can not dedicate &mdash; we can not consecrate &mdash; we can not hallow &mdash; this ground. The brave men, living and departed, who struggled here, have consecrated it, far above our poor power to add or detract. The world will little note, nor long remember what we say here, but it can never forget what they did here. It is for us the living, rather, to be dedicated here to the unfinished work which they who struggled here have thus far so nobly advanced. It is rather for us to be here dedicated to the great task remaining before us &mdash; that from these honored departed we take increased devotion to that cause for which they gave the last full measure of devotion &mdash; that we here highly resolve that these shall not have given their lives in vain &mdash; that this nation, under God, shall have a new birth of freedom &mdash; and that government of the people, by the people, for the people, shall not perish from the earth.</p>
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Why It Matters</h2>
        </div>
        <div class="section-body">
            <div class="doc-note">
                <strong>272 words. Two minutes.</strong> Lincoln spoke for about two minutes at the dedication of the Soldiers' National Cemetery. The featured orator, Edward Everett, had spoken for two hours before him. Everett later wrote to Lincoln: "I should be glad if I could flatter myself that I came as near to the central idea of the occasion, in two hours, as you did in two minutes."
            </div>
            <div class="doc-note" style="margin-top: 16px;">
                <strong>"Government of the people, by the people, for the people."</strong> This closing phrase redefined the purpose of American democracy. Lincoln didn't just honor the fallen &mdash; he gave the nation a mission: to ensure that self-government endures. These nine words are the most quoted definition of democracy ever written.
            </div>
            <div class="doc-note" style="margin-top: 16px;">
                <strong>Five known copies</strong> of the Gettysburg Address exist in Lincoln's handwriting. The version above follows the Bliss copy, which is the only one Lincoln signed and dated, and is considered the standard text.
            </div>
        </div>
    </div>

    <div class="doc-source">
        Source: <a href="https://www.loc.gov/resource/rbpe.24404500/" target="_blank">Library of Congress</a> &middot;
        <a href="https://www.archives.gov/exhibits/american-originals/gettysburg.html" target="_blank">National Archives</a>
    </div>

    <div class="doc-back">
        <a href="/usa/docs/">&larr; All Documents</a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
