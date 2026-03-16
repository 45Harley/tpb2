<?php
/**
 * TPB2 Site Map
 * =============
 * Full linked site map of all user-facing pages.
 */
$c = require __DIR__ . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'help';
$pageTitle = 'Site Map — The People\'s Branch';

$pageStyles = <<<'CSS'
.sitemap-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }
.sitemap-container h1 { color: #d4af37; margin-bottom: 0.5rem; }
.sitemap-container .subtitle { color: #b0b0b0; margin-bottom: 2rem; }
.sitemap-section { margin-bottom: 2rem; }
.sitemap-section h2 {
    color: #e0e0e0; font-size: 1.1rem; border-bottom: 1px solid #333;
    padding-bottom: 0.5rem; margin-bottom: 0.75rem;
}
.sitemap-section ul { list-style: none; padding: 0; margin: 0; }
.sitemap-section li { padding: 0.3rem 0; }
.sitemap-section a { color: #d4af37; text-decoration: none; font-size: 0.95rem; }
.sitemap-section a:hover { text-decoration: underline; }
.sitemap-section .desc { color: #888; font-size: 0.8rem; margin-left: 0.5rem; }
.sitemap-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
@media (max-width: 600px) { .sitemap-cols { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<main class="sitemap-container">
    <h1>Site Map</h1>
    <p class="subtitle">Every page on The People's Branch.</p>

    <div class="sitemap-cols">
        <div>
            <div class="sitemap-section">
                <h2>Home</h2>
                <ul>
                    <li><a href="/">Home</a> <span class="desc">Landing page</span></li>
                    <li><a href="/story.php">Our Story</a> <span class="desc">Mission &amp; origin</span></li>
                    <li><a href="/aspirations.php">Aspirations</a> <span class="desc">Vision for civic engagement</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>Your Account</h2>
                <ul>
                    <li><a href="/join.php">Join</a> <span class="desc">Create an account</span></li>
                    <li><a href="/login.php">Log In</a> <span class="desc">Email / magic link</span></li>
                    <li><a href="/profile.php">My Profile</a> <span class="desc">Location, identity, journey</span></li>
                    <li><a href="/voice.php">My Voice</a> <span class="desc">Your thoughts &amp; actions</span></li>
                    <li><a href="/reps.php">My Representatives</a> <span class="desc">Your elected officials</span></li>
                    <li><a href="/invite/">Invite a Friend</a> <span class="desc">Referral system</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>Talk</h2>
                <ul>
                    <li><a href="/talk/">Talk</a> <span class="desc">Civic dialogue by jurisdiction</span></li>
                    <li><a href="/talk/groups/">Groups</a> <span class="desc">Group deliberation</span></li>
                    <li><a href="/talk/brainstorm/">Brainstorm</a> <span class="desc">Idea generation</span></li>
                    <li><a href="/talk/help/">Talk Help</a> <span class="desc">How Talk works</span></li>
                    <li><a href="/mandate-poc.php">My Mandate</a> <span class="desc">Personal mandate builder</span></li>
                    <li><a href="/mandate-summary.php">The People's Pulse</a> <span class="desc">Aggregated mandates</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>Polls</h2>
                <ul>
                    <li><a href="/poll/">Vote</a> <span class="desc">Cast your vote on threat polls</span></li>
                    <li><a href="/poll/national/">National Results</a> <span class="desc">How the nation voted</span></li>
                    <li><a href="/poll/by-state/">By State</a> <span class="desc">State &amp; town polls</span></li>
                    <li><a href="/poll/by-rep/">By Rep</a> <span class="desc">Your reps vs. citizens</span></li>
                    <li><a href="/poll/closed/">Closed Polls</a> <span class="desc">Archive</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>Elections 2026</h2>
                <ul>
                    <li><a href="/elections/">Elections</a> <span class="desc">Landing page</span></li>
                    <li><a href="/elections/the-fight.php">The Fight</a> <span class="desc">Pledges &amp; knockouts</span></li>
                    <li><a href="/elections/the-amendment.php">The War</a> <span class="desc">28th Amendment</span></li>
                    <li><a href="/elections/threats.php">Reps Actions</a> <span class="desc">Documented threats scored on criminality scale</span></li>
                    <li><a href="/elections/statements.php">Reps Statements</a> <span class="desc">Presidential statements with truth scoring</span></li>
                    <li><a href="/elections/races.php">Races</a> <span class="desc">2026 race ratings</span></li>
                    <li><a href="/elections/impeachment-vote.php">Impeachment #1</a> <span class="desc">Impeachment vote tracker</span></li>
                </ul>
            </div>
        </div>

        <div>
            <div class="sitemap-section">
                <h2>USA &mdash; Federal Government</h2>
                <ul>
                    <li><a href="/usa/">USA Overview</a> <span class="desc">Interactive map</span></li>
                    <li><a href="/usa/congressional-overview.php">Congressional Overview</a> <span class="desc">All 541 members</span></li>
                    <li><a href="/usa/executive-overview.php">Executive Overview</a> <span class="desc">Cabinet &amp; officials</span></li>
                    <li><a href="/usa/judicial.php">Judicial Branch</a> <span class="desc">Supreme Court &amp; federal judges</span></li>
                    <li><a href="/usa/glossary.php">Glossary</a> <span class="desc">Congressional terms</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>USA &mdash; Founding Documents</h2>
                <ul>
                    <li><a href="/usa/docs/">Documents</a> <span class="desc">Landing page</span></li>
                    <li><a href="/usa/docs/constitution.php">The Constitution</a></li>
                    <li><a href="/usa/docs/declaration.php">Declaration of Independence</a></li>
                    <li><a href="/usa/docs/federalist.php">Federalist Papers</a></li>
                    <li><a href="/usa/docs/birmingham.php">Letter from Birmingham Jail</a></li>
                    <li><a href="/usa/docs/gettysburg.php">Gettysburg Address</a></li>
                    <li><a href="/usa/docs/oath.php">Oath of Office</a></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>State &amp; Town Pages</h2>
                <ul>
                    <li><a href="/ct/">Connecticut</a> <span class="desc">State landing</span></li>
                </ul>
                <h3 style="color:#b0b0b0;font-size:0.9rem;margin:0.75rem 0 0.5rem;">Quiet Corner, CT</h3>
                <ul>
                    <li><a href="/ct/putnam/">Putnam</a> <span class="desc">Model town</span></li>
                    <li><a href="/ct/putnam/calendar.php">Putnam Calendar</a> <span class="desc">Town events</span></li>
                    <li><a href="/ct/brooklyn/">Brooklyn</a></li>
                    <li><a href="/ct/killingly/">Killingly</a></li>
                    <li><a href="/ct/woodstock/">Woodstock</a></li>

                    <li><a href="/ct/pomfret/">Pomfret</a></li>
                    <li><a href="/ct/eastford/">Eastford</a></li>
                </ul>
                <p style="color:#888;font-size:0.8rem;margin-top:0.5rem;">All 50 states and 29,000+ towns are available dynamically. Visit <a href="/usa/" style="color:#d4af37">/usa/</a> to browse by state.</p>
            </div>

            <div class="sitemap-section">
                <h2>Volunteer</h2>
                <ul>
                    <li><a href="/volunteer/">Volunteer Dashboard</a> <span class="desc">Your workspace</span></li>
                    <li><a href="/volunteer/apply.php">Apply</a> <span class="desc">Volunteer application</span></li>
                </ul>
            </div>

            <div class="sitemap-section">
                <h2>Help</h2>
                <ul>
                    <li><a href="/help/">Help Center</a> <span class="desc">All guides</span></li>
                    <li><a href="/claudia.php">Claudia</a> <span class="desc">AI civic assistant</span></li>
                    <li><a href="/sitemap.php">Site Map</a> <span class="desc">This page</span></li>
                </ul>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
