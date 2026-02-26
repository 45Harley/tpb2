<?php
/**
 * Letter from Birmingham Jail — The People's Branch
 * Dr. Martin Luther King Jr., April 16, 1963
 */
$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__, 2) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$pageTitle = 'Letter from Birmingham Jail — The People\'s Branch';

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
    .original-text { padding: 16px; font-size: 1rem; }
}
CSS;

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/nav.php';
?>

<div class="doc-page">
    <h1>Letter from Birmingham Jail</h1>
    <p class="subtitle">April 16, 1963 &mdash; Dr. Martin Luther King Jr., Birmingham, Alabama</p>

    <div class="doc-section">
        <div class="section-header">
            <h2>Context</h2>
        </div>
        <div class="section-body">
            <div class="doc-note">
                <strong>Written on scraps of newspaper and smuggled out of jail.</strong> Dr. King was arrested on April 12, 1963 for participating in nonviolent demonstrations against segregation in Birmingham, Alabama. Eight white clergymen had published a statement calling the protests "unwise and untimely." King's response, written in the margins of a newspaper, on toilet paper, and on scraps smuggled in by his lawyers, became one of the most important documents in American history.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>On Interconnectedness</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>"Injustice anywhere is a threat to justice everywhere. We are caught in an inescapable network of mutuality, tied in a single garment of destiny. Whatever affects one directly, affects all indirectly."</p>
            </div>
            <div class="doc-note">
                This is the Golden Rule expressed as civic truth. What happens to one citizen happens to all citizens. TPB is built on exactly this principle.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>On the Urgency of Now</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>"For years now I have heard the word 'Wait!' It rings in the ear of every Negro with piercing familiarity. This 'Wait' has almost always meant 'Never.' We must come to see, with one of our distinguished jurists, that 'justice too long delayed is justice denied.'"</p>
            </div>
            <div class="doc-note">
                King rejected the argument that change should wait for a "more convenient season." The letter insists that the time for justice is always now.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>On Just and Unjust Laws</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>"One has not only a legal but a moral responsibility to obey just laws. Conversely, one has a moral responsibility to disobey unjust laws."</p>
                <p>"A just law is a man-made code that squares with the moral law or the law of God. An unjust law is a code that is out of harmony with the moral law."</p>
                <p>"An unjust law is a code that a numerical or power majority group compels a minority group to obey but does not make binding on itself."</p>
            </div>
            <div class="doc-note">
                <strong>The moral framework for civic action.</strong> King didn't argue for lawlessness. He drew a careful distinction: a just law applies equally to everyone. An unjust law burdens some while exempting others. Citizens have a duty to challenge the second kind.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>On the White Moderate</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>"I have almost reached the regrettable conclusion that the Negro's great stumbling block in his stride toward freedom is not the White Citizen's Counciler or the Ku Klux Klanner, but the white moderate, who is more devoted to 'order' than to justice; who prefers a negative peace which is the absence of tension to a positive peace which is the presence of justice."</p>
            </div>
            <div class="doc-note">
                The most challenging passage in the letter. King argued that silence in the face of injustice is itself a choice &mdash; and the wrong one. Comfort is not the same as justice.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>On Nonviolent Direct Action</h2>
        </div>
        <div class="section-body">
            <div class="original-text">
                <p>"Nonviolent direct action seeks to create such a crisis and foster such a tension that a community which has constantly refused to negotiate is forced to confront the issue. It seeks so to dramatize the issue that it can no longer be ignored."</p>
            </div>
            <div class="doc-note">
                King's method was not passive. It was strategic, disciplined, and designed to make injustice visible. The goal was never to create conflict &mdash; it was to reveal the conflict that already existed.
            </div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-header">
            <h2>Why It Matters Today</h2>
        </div>
        <div class="section-body">
            <div class="doc-note">
                <strong>The letter is a blueprint for civic engagement.</strong> King laid out a four-step process: (1) collection of facts to determine whether injustice exists, (2) negotiation, (3) self-purification, and (4) direct action. This is the same process that drives effective civic participation today.
            </div>
            <div class="doc-note" style="margin-top: 16px;">
                <strong>7,000 words.</strong> Written over four days in a jail cell. No notes, no research materials, no library. King quoted Socrates, St. Augustine, Thomas Aquinas, Martin Buber, Paul Tillich, Abraham Lincoln, Thomas Jefferson, and T.S. Eliot &mdash; all from memory.
            </div>
        </div>
    </div>

    <div class="doc-source">
        Full text: <a href="https://www.africa.upenn.edu/Articles_Gen/Letter_Birmingham.html" target="_blank">University of Pennsylvania</a> &middot;
        <a href="https://kinginstitute.stanford.edu/letter-birmingham-jail" target="_blank">Stanford King Institute</a>
    </div>

    <div class="doc-back">
        <a href="/usa/docs/">&larr; All Documents</a>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
