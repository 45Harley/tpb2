<?php
/**
 * Build Log — The People's Branch Development Journal
 * ====================================================
 * Public-facing daily log of what was built, generated from git history.
 */

$config = require dirname(__DIR__) . '/config.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (PDOException $e) { $pdo = null; }

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = $pdo ? getUser($pdo) : false;
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'help';

$pageTitle = 'Build Log | The People\'s Branch';
$ogTitle = 'Build Log — The People\'s Branch';
$ogDescription = 'The daily story of building a civic platform — one commit at a time.';

$pageStyles = <<<'CSS'
.build-log { max-width: 800px; margin: 0 auto; padding: 2rem 1rem; }
.build-log-header { text-align: center; margin-bottom: 2.5rem; }
.build-log-header h1 { font-size: 2rem; color: #e0e0e0; margin-bottom: 0.5rem; }
.build-log-header .subtitle { color: #b0b0b0; font-size: 1rem; }
.build-log-header .stats {
    display: flex; gap: 2rem; justify-content: center; margin-top: 1.5rem; flex-wrap: wrap;
}
.build-log-header .stat {
    text-align: center;
}
.build-log-header .stat-num {
    font-size: 2rem; font-weight: 700; color: #d4af37;
    font-family: 'Courier New', monospace;
}
.build-log-header .stat-label { font-size: 0.8rem; color: #888; text-transform: uppercase; }

.day-card {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.5rem; margin-bottom: 1rem; transition: border-color 0.3s;
}
.day-card:hover { border-color: #555; }
.day-card.milestone { border-left: 3px solid #d4af37; }

.day-header {
    display: flex; align-items: baseline; gap: 0.75rem; margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.day-number {
    font-size: 0.75rem; font-weight: 700; color: #d4af37;
    background: rgba(212,175,55,0.1); border: 1px solid rgba(212,175,55,0.3);
    padding: 2px 8px; border-radius: 10px; white-space: nowrap;
}
.day-date {
    font-size: 0.85rem; color: #888; font-family: 'Courier New', monospace;
}
.day-title {
    font-size: 1.15rem; font-weight: 600; color: #e0e0e0;
}

.day-body {
    color: #ccc; font-size: 0.9rem; line-height: 1.7;
}

.day-commits {
    margin-top: 0.5rem; font-size: 0.75rem; color: #888;
}

/* Timeline connector */
.timeline { position: relative; padding-left: 0; }

/* Filter */
.log-filter {
    display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 1.5rem; flex-wrap: wrap;
}
.log-filter a {
    padding: 0.35rem 0.9rem; border: 1px solid #333; border-radius: 16px;
    color: #b0b0b0; text-decoration: none; font-size: 0.8rem; transition: all 0.2s;
}
.log-filter a:hover { color: #e0e0e0; border-color: #555; }
.log-filter a.active { color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); }

@media (max-width: 600px) {
    .day-header { flex-direction: column; gap: 0.3rem; }
    .build-log-header .stats { flex-direction: column; gap: 0.75rem; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';

// Build log entries — each day's story
$entries = [
    ['day' => 1, 'date' => '2026-02-09', 'title' => 'The Beginning', 'commits' => 8, 'milestone' => true,
     'body' => 'Initial commit. Set up deployment pipeline to staging. Wrote CLAUDE.md project instructions. Fixed the first bug (logout 500 error). Added collaborative git workflow. The foundation is laid.'],

    ['day' => 2, 'date' => '2026-02-10', 'title' => 'Infrastructure', 'commits' => 4,
     'body' => 'Media management, documentation tree, scripts structure. Fixed dictation stutter. Removed hardcoded credentials. Separated staging from production environments.'],

    ['day' => 3, 'date' => '2026-02-11', 'title' => 'Security + First Features', 'commits' => 9,
     'body' => 'Security hardening with Playwright testing and bot blocking. Built the Connecticut state page. Ethics Foundation document written. State Builder Kit created. Signup flow now captures district and coordinates.'],

    ['day' => 4, 'date' => '2026-02-12', 'title' => 'Talk Is Born', 'commits' => 11, 'milestone' => true,
     'body' => 'Designed and built the Talk system in one day. Phase 1: API foundation with save, history, promote, and link. Phase 2: AI Brainstorm Clerk. Citizens can now submit ideas and brainstorm with AI. The civic input pipeline begins.'],

    ['day' => 5, 'date' => '2026-02-13', 'title' => 'Groups + Crystallization', 'commits' => 10,
     'body' => 'Groups as circuits. Facilitator roles. Gatherer clerk auto-detects reply threads. Crystallization output distills group deliberation. Child group aggregation enables state-level synthesis.'],

    ['day' => 6, 'date' => '2026-02-14', 'title' => 'Valentine\'s Day Build', 'commits' => 15,
     'body' => 'Hero civic metrics on town and state pages. Help system with FAQ and Ask AI. Edit and delete ideas with staleness detection. Group roles: Facilitator, Member, Observer. Display names and group invites with email. Wrote "The Valentine Fly" story.'],

    ['day' => 7, 'date' => '2026-02-15', 'title' => 'Talk Unified', 'commits' => 24, 'milestone' => true,
     'body' => 'One-page redesign: unified input, AI classify, live stream. Member management. Anonymous invites with auto-account creation. Continuous mic toggle. Centralized auth &mdash; every file now uses getUser(). Redesigned admin dashboard with role-based auth, CSRF protection, and audit logging. Soft-delete for users. 24 commits.'],

    ['day' => 8, 'date' => '2026-02-16', 'title' => 'Voting + AI Memory', 'commits' => 26,
     'body' => 'Agree/disagree voting on ideas. Bot detection. Status filters. AI brainstorm gets user identity and idea history context. Claude API memory for persistent conversations. Geo-scoping for groups. Reply-to threading. 26 commits &mdash; the most productive day yet.'],

    ['day' => 9, 'date' => '2026-02-17', 'title' => 'Stabilization', 'commits' => 4,
     'body' => 'Timestamps. Edit description. Default AI off. Fixed UTC timezone parsing, AI chat in group history, duplicate card race condition. Sometimes the best work is fixing what you built yesterday.'],

    ['day' => 10, 'date' => '2026-02-18', 'title' => 'Geo-Streams', 'commits' => 14,
     'body' => 'Standard Interest Category groups with access gates. Geo-aware navigation. Department mappings for town, state, and national levels. Category filter pills. Brainstorming ground rules. Every page now knows where you are.'],

    ['day' => 11, 'date' => '2026-02-19', 'title' => 'Federal Civic Structure', 'commits' => 18,
     'body' => 'Federal groups with proper government categories. 28 documents seeded into the documentation registry. Admin Docs tab. AI thinking spinner. Profile nudge banners for incomplete profiles. The platform starts to feel like a real civic tool.'],

    ['day' => 12, 'date' => '2026-02-20', 'title' => 'Polish', 'commits' => 2,
     'body' => 'Prompt box border styling. Added "Democracy that works" subtitle under the logo. Some days are about the details.'],

    ['day' => 13, 'date' => '2026-02-21', 'title' => 'Civic Points + Nav Overhaul', 'commits' => 11, 'milestone' => true,
     'body' => 'Dynamic gold badge with pulse animation for civic points. Wired 6 new point actions. My TPB dropdown consolidates personal items. USA dropdown replaces My Government. Committee tracking tables. The WHO&rarr;WHERE&rarr;WHAT&rarr;WHY&rarr;NOW WHAT civic framework designed.'],

    ['day' => 14, 'date' => '2026-02-22', 'title' => 'Congressional Data', 'commits' => 3,
     'body' => 'Built congressional data loader &mdash; bills, votes, and records pulled from the Congress.gov API. Rep scorecard table with composite indexes. Real government data starts flowing in.'],

    ['day' => 15, 'date' => '2026-02-23', 'title' => 'USA Section', 'commits' => 1,
     'body' => 'Congressional digest, scorecard, and glossary pages go live. Plain-English definitions for legislative jargon with hover tooltips. If you don\'t understand the words, you can\'t hold them accountable.'],

    ['day' => 16, 'date' => '2026-02-24', 'title' => 'The Accountability System', 'commits' => 20, 'milestone' => true,
     'body' => 'Founding documents section: Constitution, Declaration, Gettysburg Address, and more. Executive branch page with the criminality scale (0&ndash;1,000). Threat tags and severity scoring. Poll roll call system &mdash; citizens vote on executive threats. 20 commits building the core accountability loop.'],

    ['day' => 17, 'date' => '2026-02-25', 'title' => 'Visual Clarity', 'commits' => 5,
     'body' => 'Fixed contrast issues throughout. Criminality scale links. Threat detail deep links from polls. Auto-expand and scroll to specific threats. Accessibility matters.'],

    ['day' => 18, 'date' => '2026-02-26', 'title' => 'The Full Picture', 'commits' => 22, 'milestone' => true,
     'body' => 'All three branches wired. Party delegation map colors. Executive overview with photo cards. Congressional overview with state delegation cards. Rep detail page with voting records. Federal judges overview loaded from Court Listener. First threat collections: 9 executive, 15 judicial, 11 congressional. Elections section added.'],

    ['day' => 19, 'date' => '2026-02-27', 'title' => 'Elections', 'commits' => 12,
     'body' => 'Elections section born. The Fight (citizen pledges) and The War (constitutional amendment) ported in. Social share previews with Open Graph tags. Threat Stream page with pulsing severity badges. The election accountability toolkit takes shape.'],

    ['day' => 20, 'date' => '2026-02-28', 'title' => 'The Machine Turns On', 'commits' => 30, 'milestone' => true,
     'body' => 'Daily threat bulletin email with criminality scale legend. Auto-login via email tokens. FEC race dashboards with sync cron and public display. 7 user guides generated with Playwright screenshots. Talk stream component refactored from 1,350 lines of inline code to a shared include. 30 commits &mdash; the platform becomes self-sustaining.'],

    ['day' => 21, 'date' => '2026-03-01', 'title' => 'Philosophy', 'commits' => 3,
     'body' => '"Our Philosophy" guide written &mdash; Golden Rule to Liquid Democracy to Justice. Poll tallies show yea/nay/abstain counts. The why behind the what.'],

    ['day' => 22, 'date' => '2026-03-02', 'title' => 'Invite System + Impeachment', 'commits' => 22, 'milestone' => true,
     'body' => 'Full invite pipeline: email builder, send API, accept page with account creation and civic points. House impeachment vote tracker with rep contact popovers, draggable cards, and dynamic tallies. Citizens can now invite others and track how their reps voted on impeachment.'],

    ['day' => 23, 'date' => '2026-03-03', 'title' => 'Automation', 'commits' => 13,
     'body' => 'Automated daily threat collection via Claude API with programmatic dedup, adaptive lookback window, success tracking, and failure alerts. Race rating checker with email alerts. Party Shift Tracker. "Purpose" guide written in the founder\'s own words.'],

    ['day' => 24, 'date' => '2026-03-04', 'title' => 'The Mandate', 'commits' => 18, 'milestone' => true,
     'body' => 'Phone-based login. Voice commands. Text-to-speech readback. AI-assisted mandate CRUD with policy topic taxonomy and dual AI tagging. Public mandate summary with aggregation API. "The People\'s Pulse" goes live &mdash; citizens can now tell their government what they need.'],

    ['day' => 25, 'date' => '2026-03-05', 'title' => 'Voice-First Civic Participation', 'commits' => 32,
     'body' => 'Mandate chat component with ephemeral chat, pin, and save. 30+ commits on voice: continuous dictation, fuzzy command matching, phone and email voice login, TTS confirmation. Delegation popup. My Mandates filter. Summary API with CSV export. 32 commits &mdash; the platform learns to listen.'],

    ['day' => 26, 'date' => '2026-03-06', 'title' => 'The Theory', 'commits' => 3,
     'body' => '"The Metaphysics of Democracy" &mdash; foundational theory document. Civic Engine design doc with page architecture and file inventory. Sometimes you have to stop building and think about what you\'re building.'],

    ['day' => 27, 'date' => '2026-03-08', 'title' => 'The Arc', 'commits' => 2,
     'body' => 'Deconstruction Arc design &mdash; the full American democracy narrative from founding to present crisis to renewal. 11-task implementation plan.'],

    ['day' => 28, 'date' => '2026-03-10', 'title' => 'Claudia Rises', 'commits' => 15, 'milestone' => true,
     'body' => 'Claudia widget rebuilt from the ground up. Module loader, toggle gates, conversational login, onboarding flow. Pop-out standalone page with cross-tab messaging. Draggable bubble. Persistent chat history across pages. Navigate and open-tab actions. The AI assistant comes alive.'],

    ['day' => 29, 'date' => '2026-03-11', 'title' => 'Claudia Unified', 'commits' => 11,
     'body' => 'One widget. One voice. One pipe. Talk mode and Mandate mode. Reverse SSH tunnel for local Claude routing &mdash; no API cost. Port battles (9876 to 9877 to 9878 to 9999 and back). The IPv4 vs IPv6 lesson learned the hard way: always use 127.0.0.1, never localhost.'],

    ['day' => 30, 'date' => '2026-03-12', 'title' => 'Data Model Clarity', 'commits' => 8,
     'body' => 'Civic data model defined. First derivatives: Ideas, Thoughts, Threats (raw civic input). Second derivatives: Mandates, Ballots, Polls, Digests (distilled output). Merged user_thoughts into idea_log for one unified civic input table. Restored thought streams on 3 pages that were accidentally stripped.'],

    ['day' => 31, 'date' => '2026-03-14', 'title' => 'Statements Pipeline', 'commits' => 27, 'milestone' => true,
     'body' => 'Rep statements system &mdash; dual scoring on harm (0&ndash;1,000) and benefit (0&ndash;1,000). Citizen agree/disagree voting. 3-step collection pipeline: gather DB context, Claude web search, parse and insert. Benefit scores added to threat cards. Discuss &amp; Draft embedded on Talk, Fight, and town pages. 27 commits wiring the accountability loop.'],

    ['day' => 32, 'date' => '2026-03-15', 'title' => 'Truthfulness', 'commits' => 4,
     'body' => 'Truthfulness scoring pipeline added &mdash; cluster overlapping statements, score 0 (Lie) to 1,000 (Truth), track direction over time. Renamed Threats to Actions in the elections nav. Actions speak louder.'],

    ['day' => 33, 'date' => '2026-03-16', 'title' => 'Spectrum Bars', 'commits' => 12,
     'body' => 'Replaced score badges with visual spectrum bars: Harm, Benefit, Truth &mdash; each with hover tooltips. Fixed the rep card lie (showing "0/219 responded" when data wasn\'t tracked). State and town dropdowns on polls. Full site map page published.'],

    ['day' => 34, 'date' => '2026-03-17', 'title' => 'Discuss &amp; Draft', 'commits' => 6, 'milestone' => true,
     'body' => 'Replaced the old mandate chat with a CRUD bubble workspace. Add directly, Include AI, or use the Mic. Scope checkboxes: Federal, State, Town, Idea. Geo scope filter tabs on The People\'s Pulse. Graceful AI failure handling &mdash; system says "AI unavailable" instead of crashing.'],

    ['day' => 35, 'date' => '2026-03-18', 'title' => 'Video Walkthroughs', 'commits' => 17,
     'body' => 'Playwright video generator built. Edge TTS neural voice narration. Interactive profile walkthrough with hovers, dropdowns, and toggles. Time-synced narration across all videos. Full 7-step video production pipeline documented. Claudia AI assistant guide published.'],

    ['day' => 36, 'date' => '2026-03-19', 'title' => 'Admin Hardening', 'commits' => 9,
     'body' => 'Renamed admin.php to mgr.php after ModSecurity started blocking "admin" URLs. Fixed charset collation issues. Last-run status on all Settings cards. Drag-and-drop help guide reordering. Right-click Claudia bubble to read any page aloud. Getting Started narrated video walkthrough.'],

    ['day' => 37, 'date' => '2026-03-20', 'title' => 'Delegation + How TPB Works', 'commits' => 18,
     'body' => 'Pages and nav items tables for dynamic site structure. Subscribe banner for threat bulletin. Floating threat alerts bubble on homepage. Select-text-to-read-aloud on every page. Elected officials schema with delegation view &mdash; see who represents you. "How TPB Works" &mdash; 12-step citizen explainer for the full civic cycle.'],

    ['day' => 38, 'date' => '2026-03-21', 'title' => 'My Delegation, Tracked', 'commits' => 3, 'milestone' => true,
     'body' => '"Where I Need My Government" &mdash; 17 policy categories ranked at town, state, and federal levels. Statement pipeline refactored for multiple officials. Researched and collected 99 statements from the full federal delegation: President Trump (63), Sen. Blumenthal (18), Sen. Murphy (11), Rep. Courtney (7). Statements page upgraded with official selector tabs and "My Delegation" view. Court Listener API connected &mdash; 1,563 federal judges, 8.2 million opinions back to 1700. DEI added to the glossary: the infinite expression of good.'],
];

$totalCommits = array_sum(array_column($entries, 'commits'));
$totalDays = count($entries);
$firstDate = $entries[0]['date'];
$lastDate = end($entries)['date'];

// Month filter
$filterMonth = $_GET['month'] ?? '';
?>

<?php require dirname(__DIR__) . '/includes/header.php'; ?>
<?php require dirname(__DIR__) . '/includes/nav.php'; ?>

<main class="build-log">
    <div class="build-log-header">
        <h1>Build Log</h1>
        <p class="subtitle">The People's Branch &mdash; built in public, one day at a time.</p>
        <div class="stats">
            <div class="stat">
                <div class="stat-num"><?= $totalCommits ?></div>
                <div class="stat-label">Commits</div>
            </div>
            <div class="stat">
                <div class="stat-num"><?= $totalDays ?></div>
                <div class="stat-label">Days</div>
            </div>
            <div class="stat">
                <div class="stat-num"><?= round($totalCommits / $totalDays) ?></div>
                <div class="stat-label">Per Day</div>
            </div>
        </div>
    </div>

    <div class="log-filter">
        <a href="?month=" class="<?= !$filterMonth ? 'active' : '' ?>">All</a>
        <a href="?month=02" class="<?= $filterMonth === '02' ? 'active' : '' ?>">February</a>
        <a href="?month=03" class="<?= $filterMonth === '03' ? 'active' : '' ?>">March</a>
    </div>

    <div class="timeline">
        <?php foreach (array_reverse($entries) as $entry):
            $month = substr($entry['date'], 5, 2);
            if ($filterMonth && $month !== $filterMonth) continue;
            $isMilestone = !empty($entry['milestone']);
        ?>
        <div class="day-card <?= $isMilestone ? 'milestone' : '' ?>">
            <div class="day-header">
                <span class="day-number">Day <?= $entry['day'] ?></span>
                <span class="day-date"><?= date('M j, Y', strtotime($entry['date'])) ?></span>
                <span class="day-title"><?= $entry['title'] ?></span>
            </div>
            <div class="day-body">
                <?= $entry['body'] ?>
            </div>
            <div class="day-commits"><?= $entry['commits'] ?> commits</div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
