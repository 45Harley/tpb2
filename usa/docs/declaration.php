<?php
/**
 * Declaration of Independence — The People's Branch
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Declaration of Independence — The People\'s Branch';

$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/congressional-overview.php'],
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
.grievance-list {
    color: #a0aec0;
    font-size: 14px;
    line-height: 1.8;
    padding-left: 20px;
    margin-top: 12px;
}
.grievance-list li { margin-bottom: 8px; }
.signers { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.signers span {
    background: #0d1220;
    color: #8892a8;
    font-size: 12px;
    padding: 3px 10px;
    border-radius: 4px;
}
.doc-back { margin-top: 32px; font-size: 14px; }
.doc-back a { color: #8892a8; text-decoration: none; }
.doc-back a:hover { color: #d4af37; }
.doc-source { font-size: 12px; color: #6b7394; text-align: center; margin-top: 24px; }
.doc-source a { color: #8892a8; }
@media (max-width: 600px) {
    .doc-page { padding: 24px 16px; }
    .original-text { padding: 16px; font-size: 1rem; }
}
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="doc-page">
    <h1>The Declaration of Independence</h1>
    <p class="subtitle">July 4, 1776 — The unanimous Declaration of the thirteen united States of America</p>

    <div class="doc-section">
        <div class="section-header">
            <h2>The Preamble</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                We hold these truths to be self-evident, that all men are created equal, that they are endowed by their Creator with certain unalienable Rights, that among these are Life, Liberty and the pursuit of Happiness. &mdash; That to secure these rights, Governments are instituted among Men, deriving their just powers from the consent of the governed, &mdash; That whenever any Form of Government becomes destructive of these ends, it is the Right of the People to alter or to abolish it, and to institute new Government, laying its foundation on such principles and organizing its powers in such form, as to them shall seem most likely to effect their Safety and Happiness.
            </div>
            <div class="doc-note">
                <strong>The foundation of American democracy.</strong> These 110 words established three revolutionary ideas: (1) rights come from being human, not from government; (2) government exists only by the consent of the people; (3) the people have the right to change their government when it fails them.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>The Grievances</h2>
        </div>
        <div class="section-body">
            <div class="doc-note">
                The Declaration listed 27 specific grievances against King George III, establishing a factual case for independence. Key themes include:
            </div>
            <ul class="grievance-list">
                <li>Refusing to approve laws necessary for the public good</li>
                <li>Dissolving representative legislatures</li>
                <li>Obstructing the administration of justice</li>
                <li>Imposing taxes without consent</li>
                <li>Depriving citizens of trial by jury</li>
                <li>Cutting off trade with all parts of the world</li>
                <li>Quartering armed troops among the population</li>
            </ul>
            <div class="doc-note" style="margin-top: 16px;">
                <strong>A legal argument, not just a declaration.</strong> Jefferson structured the document like a legal brief: statement of principles, evidence of violations, and a conclusion. This was deliberate &mdash; the colonies were making their case before the world.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>The Resolution</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                We, therefore, the Representatives of the united States of America, in General Congress, Assembled, appealing to the Supreme Judge of the world for the rectitude of our intentions, do, in the Name, and by Authority of the good People of these Colonies, solemnly publish and declare, That these United Colonies are, and of Right ought to be Free and Independent States.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>The Signers</h2>
        </div>
        <div class="section-body">
            <div class="doc-note"><strong>56 delegates</strong> signed the Declaration, representing the 13 colonies:</div>
            <div class="signers">
                <span>John Hancock</span>
                <span>Benjamin Franklin</span>
                <span>John Adams</span>
                <span>Thomas Jefferson</span>
                <span>Roger Sherman</span>
                <span>Robert R. Livingston</span>
                <span>Samuel Adams</span>
                <span>John Witherspoon</span>
                <span>Richard Henry Lee</span>
                <span>George Clymer</span>
                <span>Benjamin Rush</span>
                <span>Charles Carroll</span>
                <span>George Walton</span>
                <span>William Whipple</span>
                <span>+ 42 others</span>
            </div>
        </div>
    </div>

    <div class="doc-source">
        Full text: <a href="https://www.archives.gov/founding-docs/declaration-transcript" target="_blank">National Archives</a>
    </div>

    <div class="doc-back">
        <a href="/usa/docs/">&larr; All Documents</a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
