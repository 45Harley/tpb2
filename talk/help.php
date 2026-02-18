<?php
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
try {
    $pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbUser = getUser($pdo);
} catch (PDOException $e) { $dbUser = false; }

// Geo context passthrough (so nav links preserve state/town)
$geoStateId = isset($_GET['state']) ? (int)$_GET['state'] : null;
$geoTownId  = isset($_GET['town'])  ? (int)$_GET['town']  : null;
$geoLabel = 'USA';
if ($geoTownId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT t.town_name, s.abbreviation, s.state_id FROM towns t JOIN states s ON t.state_id = s.state_id WHERE t.town_id = ?");
    $stmt->execute([$geoTownId]);
    $geo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($geo) { $geoLabel = $geo['town_name'] . ', ' . $geo['abbreviation']; $geoStateId = (int)$geo['state_id']; }
} elseif ($geoStateId && isset($pdo)) {
    $stmt = $pdo->prepare("SELECT state_name FROM states WHERE state_id = ?");
    $stmt->execute([$geoStateId]);
    $geo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($geo) { $geoLabel = $geo['state_name']; }
}
$geoQuery = $geoTownId ? '?town=' . $geoTownId : ($geoStateId ? '?state=' . $geoStateId : '');

// Nav setup
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'talk';
$pageTitle = 'Help - ' . ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk | The People\'s Branch';
$secondaryNavBrand = ($geoLabel !== 'USA' ? $geoLabel . ' ' : '') . 'Talk';
$secondaryNav = [
    ['label' => 'Stream',  'url' => '/talk/' . $geoQuery],
    ['label' => 'Groups',  'url' => '/talk/groups.php' . $geoQuery],
    ['label' => 'Help',    'url' => '/talk/help.php' . $geoQuery],
];

$pageStyles = <<<'CSS'
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header h1 {
            font-size: 1.3rem;
            color: #ffffff;
        }

        .header-links {
            display: flex;
            gap: 1rem;
            font-size: 0.9rem;
        }

        .header-links a {
            color: #90caf9;
            text-decoration: none;
        }

        .header-links a:hover { text-decoration: underline; color: #bbdefb; }

        .user-status { font-size: 0.8rem; color: #81c784; text-align: right; margin-bottom: 0.75rem; }
        .user-status .dot { display: inline-block; width: 8px; height: 8px; background: #4caf50; border-radius: 50%; margin-right: 4px; }

        h2 {
            color: #d4af37;
            font-size: 1.2rem;
            margin: 2rem 0 0.75rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.25);
        }

        h2:first-of-type { margin-top: 1rem; }

        h3 {
            color: #4fc3f7;
            font-size: 1rem;
            margin: 1.25rem 0 0.5rem;
        }

        p, li {
            color: #ccc;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        p { margin-bottom: 0.75rem; }

        ul, ol {
            margin: 0.5rem 0 1rem 1.5rem;
        }

        li { margin-bottom: 0.4rem; }

        a { color: #4fc3f7; }

        .flow-diagram {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 16px 20px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #aaa;
            overflow-x: auto;
            white-space: pre;
            margin: 1rem 0;
            line-height: 1.5;
        }

        .page-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 16px 20px;
            margin: 0.75rem 0;
        }

        .page-card h3 {
            margin-top: 0;
        }

        .page-card .page-link {
            font-size: 0.8rem;
            color: #888;
        }

        strong { color: #eee; }

        .faq-item {
            margin-bottom: 1.25rem;
        }

        .faq-item summary {
            cursor: pointer;
            color: #eee;
            font-weight: 600;
            font-size: 0.95rem;
            padding: 8px 0;
            list-style: none;
        }

        .faq-item summary::-webkit-details-marker { display: none; }

        .faq-item summary::before {
            content: '+ ';
            color: #d4af37;
            font-weight: bold;
        }

        .faq-item[open] summary::before {
            content: '- ';
        }

        .faq-item .answer {
            padding: 8px 0 8px 18px;
            border-left: 2px solid rgba(212, 175, 55, 0.25);
            margin-left: 4px;
        }

        .quick-ref {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 1rem 0;
        }

        .quick-ref a {
            display: block;
            background: rgba(79, 195, 247, 0.08);
            border: 1px solid rgba(79, 195, 247, 0.2);
            border-radius: 8px;
            padding: 12px;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
        }

        .quick-ref a:hover {
            border-color: #4fc3f7;
            background: rgba(79, 195, 247, 0.15);
        }

        .quick-ref .qr-icon {
            display: block;
            font-size: 1.5rem;
            margin-bottom: 4px;
        }

        .quick-ref .qr-label {
            font-size: 0.85rem;
            color: #ccc;
        }

        .quick-ref .qr-desc {
            font-size: 0.7rem;
            color: #888;
            margin-top: 2px;
        }

        .status-flow {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin: 0.5rem 0 1rem;
            font-size: 0.85rem;
        }

        .status-pill {
            padding: 4px 10px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.08);
            color: #aaa;
        }

        .status-arrow { color: #555; }

        .card-demo {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 0.75rem 0;
        }
        .card-swatch {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #ccc;
        }
        .card-swatch .swatch-bar {
            width: 4px;
            height: 20px;
            border-radius: 2px;
        }

        .ground-rules {
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 1.5rem;
        }

        .ground-rules h2 {
            color: #d4af37;
            font-size: 1.15rem;
            margin: 0 0 0.5rem;
            padding-bottom: 0;
            border-bottom: none;
        }

        .ground-rules .golden-rule {
            color: #e0c868;
            font-style: italic;
            font-size: 0.95rem;
            margin-bottom: 0.75rem;
        }

        .ground-rules ol {
            margin: 0.5rem 0 0 1.5rem;
        }

        .ground-rules li {
            color: #ccc;
            font-size: 0.9rem;
            margin-bottom: 0.35rem;
            line-height: 1.5;
        }

        .ground-rules li strong {
            color: #eee;
        }

        @media (max-width: 480px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            .header-links { gap: 0.75rem; font-size: 0.8rem; }
            .quick-ref { grid-template-columns: 1fr 1fr; }
        }
CSS;

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/nav.php';
?>

    <div class="container">

        <!-- ═══════ GROUND RULES ═══════ -->

        <div class="ground-rules">
            <h2>Ground Rules</h2>
            <p class="golden-rule">"Do to others what you would have them do to you."</p>
            <p style="color: #aaa; font-size: 0.85rem; margin-bottom: 0.75rem;">
                Talk is a brainstorming space. These rules apply to every stream, every group, every conversation.
            </p>
            <ol>
                <li><strong>No criticism</strong> &mdash; every idea is valid</li>
                <li><strong>Build on ideas</strong> &mdash; "Yes, and&hellip;" not "No, but&hellip;"</li>
                <li><strong>Quantity over quality</strong> &mdash; get everything out</li>
                <li><strong>Wild ideas welcome</strong> &mdash; refine later</li>
                <li><strong>Stay on topic</strong> &mdash; keep it about your community</li>
            </ol>
        </div>

        <!-- ═══════ QUICK REFERENCE ═══════ -->

        <div class="quick-ref">
            <a href="index.php">
                <span class="qr-icon">&#x1f4ac;</span>
                <span class="qr-label">Talk</span>
                <span class="qr-desc">Ideas, AI, and stream</span>
            </a>
            <a href="groups.php">
                <span class="qr-icon">&#x1f465;</span>
                <span class="qr-label">Groups</span>
                <span class="qr-desc">Civic topics &amp; community</span>
            </a>
            <a href="index.php?state=7">
                <span class="qr-icon">&#x1f3db;</span>
                <span class="qr-label">CT Stream</span>
                <span class="qr-desc">Connecticut ideas</span>
            </a>
            <a href="index.php?town=119">
                <span class="qr-icon">&#x1f3e0;</span>
                <span class="qr-label">Putnam</span>
                <span class="qr-desc">Putnam, CT ideas</span>
            </a>
        </div>

        <!-- ═══════ HOW IT WORKS ═══════ -->

        <h2>How /talk Works</h2>

        <p>/talk turns your scattered thoughts into concrete proposals. Everything happens on one page:</p>

<div class="flow-diagram">Type or speak a thought &#x2500;&#x2500;&#x2192; AI classifies it (category + tags)
                              &#x2502;
Toggle AI respond on?  &#x2500;&#x2500;&#x2192; AI brainstorms back
                              &#x2502;
Stream builds live &#x2500;&#x2500;&#x2500;&#x2500;&#x2192; Your ideas + group members' ideas
                              &#x2502;
Facilitator clicks &#x2500;&#x2500;&#x2500;&#x2500;&#x2192; Gather (find connections)
                              &#x2502;
                         Crystallize (structured proposal)</div>

        <!-- Geo Streams -->
        <div class="page-card">
            <h3>&#x1f30e; Geographic Streams</h3>
            <p>Talk is organized by location. Every idea is automatically stamped with the poster's state and town. You can view ideas at three levels:</p>
            <ul>
                <li><strong>USA</strong> (<a href="index.php">/talk/</a>) &mdash; all ungrouped ideas from everyone</li>
                <li><strong>State</strong> (<a href="index.php?state=7">/talk/?state=7</a>) &mdash; ideas from people in that state</li>
                <li><strong>Town</strong> (<a href="index.php?town=119">/talk/?town=119</a>) &mdash; ideas from people in that town</li>
            </ul>
            <p>A breadcrumb at the top shows where you are: <strong>USA &rsaquo; Connecticut &rsaquo; Putnam</strong>. Click any level to zoom out.</p>
            <p>Town and state pages (like the <a href="/z-states/ct/putnam/">Putnam page</a>) have a <strong>Talk</strong> link in their navigation that takes you directly to the local stream.</p>
        </div>

        <!-- Access Requirements -->
        <div class="page-card">
            <h3>&#x1f512; Who Can Post</h3>
            <p>To post ideas in Talk, you need two things:</p>
            <ol>
                <li><strong>Verified email</strong> &mdash; <a href="/join.php">create an account</a> and verify your email address</li>
                <li><strong>Location set</strong> &mdash; <a href="/profile.php#town">set your town</a> in your profile so your ideas land in the right stream</li>
            </ol>
            <p>If you haven't done these steps, you'll see a banner at the top of Talk guiding you. The banner is clickable &mdash; it takes you directly to the right page.</p>
            <p>Everyone can <strong>read</strong> all streams, even without an account. Posting, voting, and joining groups require verification.</p>
        </div>

        <!-- Talk Page -->
        <div class="page-card">
            <h3>&#x1f4ac; Talk &mdash; The Main Page</h3>
            <span class="page-link">/talk/</span>
            <p>Input, AI, and history on one page. Type an idea, the AI silently classifies it (category and tags), and it appears in your stream. Toggle AI respond on to get a conversational brainstorm reply below each idea.</p>
            <ul>
                <li><strong>Context selector</strong> &mdash; switch between Personal and any of your groups. Sticky between sessions.</li>
                <li><strong>AI auto-classify</strong> &mdash; no buttons to pick a category. AI assigns idea/decision/todo/note/question + tags automatically.</li>
                <li><strong>AI respond toggle</strong> &mdash; the robot icon next to the input. When on, AI brainstorms back after each idea.</li>
                <li><strong>Voice input</strong> &mdash; tap the mic, speak naturally, it fills in the text.</li>
                <li><strong>Live stream</strong> &mdash; in group mode, new ideas from other members appear automatically (polls every 8 seconds).</li>
                <li><strong>Agree/Disagree</strong> &mdash; 👍 and 👎 buttons on each idea card. Tap to vote, tap again to remove. Shows how the group feels about each idea.</li>
                <li><strong>Status filter</strong> &mdash; filter bar above the stream: All, Raw, Refining, Distilled, Actionable. Focus on ideas at a specific maturity level.</li>
                <li><strong>Reply to an idea</strong> &mdash; click any card's <strong>#ID</strong> number to start a reply. The input fills with <code>re: #52 -&nbsp;</code> and focuses so you can type your response. The gatherer later uses these references to link ideas together.</li>
                <li><strong>Inline edit/delete</strong> &mdash; pencil to edit, &times; to delete your own ideas. Promote to advance maturity.</li>
                <li><strong>Ctrl+Enter</strong> (Cmd+Enter on Mac) submits without clicking Send.</li>
            </ul>
        </div>

        <!-- Card types -->
        <div class="page-card">
            <h3>Card Types in the Stream</h3>
            <p>Each card has a colored left border showing what it is:</p>
            <div class="card-demo">
                <span class="card-swatch"><span class="swatch-bar" style="background:#4fc3f7;"></span> Idea</span>
                <span class="card-swatch"><span class="swatch-bar" style="background:#4caf50;"></span> Decision</span>
                <span class="card-swatch"><span class="swatch-bar" style="background:#ff9800;"></span> Todo</span>
                <span class="card-swatch"><span class="swatch-bar" style="background:#9c27b0;"></span> Note</span>
                <span class="card-swatch"><span class="swatch-bar" style="background:#e91e63;"></span> Question</span>
            </div>
            <p>Special card styles:</p>
            <ul>
                <li><strong>AI responses</strong> &mdash; purple tint + purple border. Appear when AI respond is toggled on.</li>
                <li><strong>Digests</strong> &mdash; gold tint + bold gold border. Created by the gatherer.</li>
                <li><strong>Crystallizations</strong> &mdash; purple tint + bold border. Structured proposals.</li>
            </ul>
        </div>

        <!-- Idea maturity -->
        <div class="page-card">
            <h3>Idea Maturity</h3>
            <p>Ideas progress through stages as they develop:</p>
            <div class="status-flow">
                <span class="status-pill">Raw</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Refining</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Distilled</span>
                <span class="status-arrow">&#x2192;</span>
                <span class="status-pill">Actionable</span>
            </div>
            <p>Use the promote button (&#x2b06;) on any idea card to advance it one step. Use the <strong>status filter bar</strong> at the top of the stream to view only ideas at a specific stage.</p>
        </div>

        <!-- Voting -->
        <div class="page-card">
            <h3>👍 Agree / Disagree</h3>
            <p>Every idea card (except AI responses and digests) has agree and disagree buttons. This is civic agreement, not competitive voting &mdash; it shows where the group aligns.</p>
            <ul>
                <li><strong>Tap 👍</strong> to agree, tap again to remove your vote</li>
                <li><strong>Tap 👎</strong> to disagree, or switch from agree to disagree</li>
                <li><strong>One vote per idea</strong> &mdash; you can't agree and disagree at the same time</li>
                <li><strong>Login required</strong> &mdash; anonymous users can read but not vote</li>
            </ul>
            <p>Vote counts update instantly without reloading the page.</p>
        </div>

        <!-- Groups -->
        <div class="page-card">
            <h3>&#x1f465; Groups</h3>
            <span class="page-link">/talk/groups.php</span>
            <p>Groups are where individual thoughts become collective proposals. There are two kinds:</p>
            <ul>
                <li><strong>Civic Topics</strong> &mdash; standard groups auto-created for each community from SIC civic codes (public administration, courts, fire, education, etc.). These appear automatically when you visit a town or state's groups page.</li>
                <li><strong>Community Groups</strong> &mdash; created by users around any topic. These are the groups you create and manage yourself.</li>
            </ul>
            <p>Use the <strong>geo filter tabs</strong> at the top of the Groups page (All/USA, My State, My Town) to see groups scoped to your location.</p>
            <p><strong>The deliberation flow:</strong></p>
            <ol>
                <li><strong>Create</strong> a group with a clear purpose (on Groups page)</li>
                <li><strong>Invite</strong> members by email</li>
                <li><strong>Brainstorm</strong> &mdash; everyone contributes ideas via the Talk page</li>
                <li><strong>Gather</strong> &mdash; facilitator runs the AI gatherer (from Talk footer)</li>
                <li><strong>Crystallize</strong> &mdash; AI produces a structured proposal (from Talk footer)</li>
                <li><strong>Iterate</strong> &mdash; add more ideas, re-gather, re-crystallize</li>
                <li><strong>Archive</strong> &mdash; lock the final proposal</li>
            </ol>
            <p><strong>Roles:</strong> &#x1f3af; Group Facilitator (manages everything), &#x1f4ac; Group Member (contributes ideas), &#x1f441; Group Observer (read-only).</p>
            <p>See the <strong>Group Privacy &amp; Access</strong> section below for the full access model, including public reading/voting and the permissions matrix.</p>
        </div>

        <!-- Group Privacy & Access -->
        <div class="page-card">
            <h3>&#x1f512; Group Privacy &amp; Access</h3>
            <p>Groups have three layers of access control. Here's what each setting means:</p>

            <p><strong>1. Access level</strong> &mdash; who can find and join the group:</p>
            <ul>
                <li><strong>Open</strong> &mdash; anyone can discover and join. Good for broad community input.</li>
                <li><strong>Observable</strong> (default) &mdash; anyone can see it exists, but joining requires an invitation. Best for civic transparency.</li>
                <li><strong>Closed</strong> &mdash; invisible to non-members. Only accessible via direct invitation. Good for focused working groups.</li>
            </ul>

            <p><strong>2. Public access flags</strong> &mdash; what non-members can do (set by facilitator):</p>
            <ul>
                <li><strong>Public reading OFF</strong> (default) &mdash; only group members see the ideas. The group is private.</li>
                <li><strong>Public reading ON</strong> &mdash; verified non-members (phone-verified+) can browse the group's ideas in the Talk stream, read-only. They cannot post.</li>
                <li><strong>Public voting ON</strong> &mdash; verified non-members can also agree/disagree on ideas. Automatically enables public reading.</li>
            </ul>
            <p style="color:#aaa; font-size:0.85rem;">Non-members can never submit ideas, even with public access on. Only group members can contribute.</p>

            <p><strong>3. Member roles</strong> &mdash; what each member can do inside the group:</p>
            <ul>
                <li><strong>&#x1f3af; Facilitator</strong> &mdash; manages everything: members, invites, settings, gather, crystallize, archive</li>
                <li><strong>&#x1f4ac; Member</strong> &mdash; submits ideas, votes, participates in discussion</li>
                <li><strong>&#x1f441; Observer</strong> &mdash; read-only, cannot post or vote</li>
            </ul>

            <p><strong>Quick reference:</strong></p>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.85rem; color:#ccc; margin:0.5rem 0;">
                <thead>
                    <tr style="border-bottom:1px solid #444;">
                        <th style="text-align:left; padding:6px 8px; color:#d4af37;">Person</th>
                        <th style="text-align:center; padding:6px 4px;">See group</th>
                        <th style="text-align:center; padding:6px 4px;">Read ideas</th>
                        <th style="text-align:center; padding:6px 4px;">Post</th>
                        <th style="text-align:center; padding:6px 4px;">Vote</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">Not logged in</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                    </tr>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">Verified, not a member, public OFF</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                    </tr>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">Verified, not a member, public reading ON</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                    </tr>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">Verified, not a member, public voting ON</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                    </tr>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">&#x1f441; Observer</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2014;</td>
                    </tr>
                    <tr style="border-bottom:1px solid #333;">
                        <td style="padding:5px 8px;">&#x1f4ac; Member</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 8px;">&#x1f3af; Facilitator</td>
                        <td style="text-align:center; padding:5px 4px;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                        <td style="text-align:center; padding:5px 4px; color:#4caf50;">&#x2713;</td>
                    </tr>
                </tbody>
            </table>
            </div>
            <p style="color:#888; font-size:0.8rem;">For closed groups, non-members can't see the group at all. "Verified" means phone-verified (identity level 3+).</p>
        </div>

        <!-- ═══════ FACILITATOR GUIDE ═══════ -->

        <h2>Facilitator Guide</h2>

        <p>When you create a group, you become its <strong>&#x1f3af; Group Facilitator</strong>. You guide the group from scattered ideas to a concrete proposal.</p>

        <h3>Creating a group</h3>
        <p>Go to <a href="groups.php">Groups</a> and click "Create Group." Write a description that tells members what you're trying to figure out &mdash; "Affordable housing options for Putnam" is better than "Housing stuff." Add tags so people can discover your group.</p>
        <ul>
            <li><strong>Observable</strong> (default) &mdash; transparent, anyone can watch, only members contribute. Best for civic deliberation.</li>
            <li><strong>Open</strong> &mdash; anyone can jump in. Good for broad community input.</li>
            <li><strong>Closed</strong> &mdash; invitation only. Good for focused working groups.</li>
        </ul>

        <h3>Public access settings</h3>
        <p>Want outsiders to see what your group is working on? Two optional toggles let verified non-members (phone-verified or higher) access your group's ideas:</p>
        <ul>
            <li><strong>Allow reading</strong> &mdash; non-members can browse your group's ideas in the Talk stream (read-only, no input box)</li>
            <li><strong>Allow voting</strong> &mdash; non-members can also agree/disagree on ideas (automatically enables reading)</li>
        </ul>
        <p>Set these when creating the group, or change them later from the "Public Access" section on the group detail page. Only verified accounts qualify &mdash; anonymous and email-only users won't see public groups.</p>

        <h3>Managing members</h3>
        <ul>
            <li><strong>&#x1f3af; Group Facilitator</strong> &mdash; Full control: manage members, run AI tools, archive. You can promote members to co-facilitator.</li>
            <li><strong>&#x1f4ac; Group Member</strong> &mdash; The default role. Contributes ideas, participates in discussion.</li>
            <li><strong>&#x1f441; Group Observer</strong> &mdash; Read-only. Great for people who want to follow along without contributing.</li>
        </ul>
        <p>Facilitators can change any member's role, deactivate/reactivate members, or remove them from the group. Deactivated members stay listed but can't access the group until reactivated.</p>
        <p>Multiple facilitators are allowed and encouraged for larger groups. If the last facilitator leaves, the longest-tenured member is auto-promoted.</p>

        <h3>Inviting members</h3>
        <p>On the group detail page (Groups &rarr; click a group), enter email addresses (one per line or comma-separated) in the Invite Members form. The system:</p>
        <ul>
            <li>Sends each person an email with <strong>"Yes, I'll Join"</strong> and <strong>"No Thanks"</strong> buttons</li>
            <li>Works for existing TPB users <em>and</em> people who don't have an account yet</li>
            <li>New users who click "Yes, I'll Join" get an account created automatically &mdash; verified and logged in instantly</li>
            <li>Reports results per email: invited, already a member, already invited</li>
        </ul>
        <p>Invitees don't need to be logged in to respond &mdash; the link itself authenticates them. Invitations expire after 7 days.</p>

        <h3>Gather &amp; Crystallize</h3>
        <p>In group mode on the Talk page, facilitators see a footer bar with <strong>Gather</strong> and <strong>Crystallize</strong> buttons.</p>
        <ul>
            <li><strong>Gather</strong> &mdash; AI scans all group ideas, finds thematic connections, creates summary digests. It also detects <code>re: #xx</code> reply references and auto-creates links between those ideas, giving the AI confirmed connections to cluster around. Safe to run often (incremental).</li>
            <li><strong>Crystallize</strong> &mdash; AI produces a structured proposal document from all the group's ideas and digests. Re-runnable &mdash; each run improves on the last.</li>
        </ul>
        <p>The cycle: add ideas &rarr; gather &rarr; crystallize &rarr; repeat until satisfied &rarr; archive to lock.</p>

        <h3>Staleness warnings</h3>
        <p>If a member edits or deletes an idea <em>after</em> a gather or crystallize has run, you'll see an orange warning. Re-run the relevant tool to incorporate the changes.</p>

        <!-- ═══════ FAQ ═══════ -->

        <h2>Frequently Asked Questions</h2>

        <details class="faq-item">
            <summary>Do I need an account to use /talk?</summary>
            <div class="answer">
                <p>You can <strong>read</strong> all streams without an account. To <strong>post ideas</strong>, you need a verified email and a location set in your profile. <a href="/join.php">Create an account</a> to get started &mdash; it takes 30 seconds.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What happens to my thoughts if I'm not logged in?</summary>
            <div class="answer">
                <p>They're stored with a temporary session ID that lives in your browser tab. If you close the tab, switch browsers, or clear your data, the link is broken. The thoughts still exist in the database but nobody &mdash; including you &mdash; can find them again.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Can other people see my thoughts?</summary>
            <div class="answer">
                <p>In <strong>Personal mode</strong>, your thoughts are private &mdash; only you can see them. In <strong>Group mode</strong>, ideas you submit are visible to all group members. Switch modes using the context selector at the top of the Talk page.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What does the AI do automatically?</summary>
            <div class="answer">
                <p>When you submit an idea, the AI silently classifies it:</p>
                <ul>
                    <li><strong>Category</strong> &mdash; idea, decision, todo, note, or question</li>
                    <li><strong>Tags</strong> &mdash; 2-5 relevant keywords</li>
                </ul>
                <p>You don't need to pick a category or enter tags &mdash; the AI handles it. If the AI respond toggle is off, that's all it does. Your idea is filed and you move on.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What does the AI respond toggle do?</summary>
            <div class="answer">
                <p>The robot icon next to the input box. When toggled <strong>on</strong> (highlighted), the AI will brainstorm back after each idea you submit &mdash; asking follow-up questions, adding data, challenging assumptions. When <strong>off</strong>, the AI just classifies silently.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Does the AI cost money?</summary>
            <div class="answer">
                <p>Not to you. Each AI interaction costs TPB roughly one cent. That's why we ask you to <a href="/join.php">create an account</a> &mdash; so the value isn't wasted on throwaway sessions.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How do groups work?</summary>
            <div class="answer">
                <p>A group is 2+ people around a topic. Anyone with an account can create one on the <a href="groups.php">Groups page</a>. Once created, members select the group from the Talk page's context dropdown to contribute ideas.</p>
                <p>Groups have three access levels: <strong>Open</strong> (anyone can join), <strong>Observable</strong> (anyone can see, only members contribute), and <strong>Closed</strong> (invitation only).</p>
                <p>Facilitators can also enable <strong>public reading</strong> and/or <strong>public voting</strong> &mdash; letting verified non-members view ideas or vote without joining.</p>
                <p>Each idea belongs to exactly one group (or no group for personal ideas). Ideas don't leak across groups.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What is "crystallization"?</summary>
            <div class="answer">
                <p>Crystallization is when the AI reads all of a group's ideas, gatherer digests, and conversations, then produces a structured proposal document &mdash; with key findings, proposed actions, and attribution back to the people who contributed. Think of it as turning a messy whiteboard into a polished brief.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Can I edit or delete my ideas?</summary>
            <div class="answer">
                <p>Yes &mdash; in the stream, your own ideas show an edit (&#x270E;) and delete (&times;) button. You can also delete AI responses that were triggered by your ideas.</p>
                <p><strong>Editing</strong> is transparent: an "(edited)" tag appears on modified thoughts. The edit count is tracked.</p>
                <p><strong>Deleting</strong> is usually a soft delete &mdash; the idea is hidden but preserved so gathered outputs stay intact.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How does voting work?</summary>
            <div class="answer">
                <p>You can agree (👍) or disagree (👎) with any human-submitted idea. Voting is a toggle &mdash; tap the same button again to remove your vote, or tap the other to switch. You need to be logged in to vote. AI-generated cards and digests don't have vote buttons.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What does the status filter do?</summary>
            <div class="answer">
                <p>The filter bar above the stream lets you show only ideas at a specific maturity level: Raw, Refining, Distilled, or Actionable. Click "All" to see everything again. This is useful when a group has many ideas and you want to focus on the ones that have been refined.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Can I use /talk as a personal idea tracker?</summary>
            <div class="answer">
                <p>Yes. Stay in Personal mode and Talk works as a thought journal with AI classification. Toggle AI respond on to brainstorm 1-on-1. You never have to join a group &mdash; but the real power is in collective deliberation.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What about the old Brainstorm and History pages?</summary>
            <div class="answer">
                <p>They still work. The Talk page combines their functionality into one place. If you prefer the dedicated pages, you can still use <a href="brainstorm.php">Brainstorm</a> for AI chat and <a href="history.php">History</a> for filtering and threaded views.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How do geographic streams work?</summary>
            <div class="answer">
                <p>When you post an idea, it's automatically tagged with your state and town from your profile. Ideas then appear in the matching geographic streams:</p>
                <ul>
                    <li>A Putnam idea appears in the Putnam stream, the Connecticut stream, and the USA stream</li>
                    <li>A Hartford idea appears in the Hartford stream and CT stream, but NOT in the Putnam stream</li>
                </ul>
                <p>Group ideas (posted inside a group) only appear in that group &mdash; not in the geographic streams.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What are Civic Topics?</summary>
            <div class="answer">
                <p>Civic Topics are standard groups that are automatically created for each community based on SIC codes (Standard Industrial Classification). They cover civic functions like public administration, courts, fire protection, education, highways, and more.</p>
                <p>When you visit a town or state's <a href="groups.php">Groups page</a>, civic topics for that location are created automatically. Anyone can join and contribute to these standard groups.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Why do I need to set my location?</summary>
            <div class="answer">
                <p>Your location determines which geographic stream your ideas appear in. Without it, the system doesn't know where to place your contributions. Set your town at <a href="/profile.php#town">/profile.php#town</a> &mdash; it takes a few seconds.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>What's the difference between a private and public group?</summary>
            <div class="answer">
                <p>By default, groups are <strong>private</strong> &mdash; only members can see the ideas inside. The facilitator can flip two switches to make it public:</p>
                <ul>
                    <li><strong>Public reading</strong> &mdash; verified non-members can browse the group's ideas (read-only)</li>
                    <li><strong>Public voting</strong> &mdash; verified non-members can also agree/disagree on ideas</li>
                </ul>
                <p>Even with public access on, <strong>only members can post ideas</strong>. Non-members can watch and react, but they can't add to the conversation. "Verified" means phone-verified (identity level 3+).</p>
                <p>Standard Civic Topic groups are always public &mdash; open access, public reading and voting enabled.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>Is this partisan? Does it favor any political side?</summary>
            <div class="answer">
                <p>No. /talk is non-partisan by design. The AI describes but doesn't editorialize. It serves all citizens &mdash; left, right, center, or none of the above.</p>
            </div>
        </details>

        <details class="faq-item">
            <summary>How do I create an account?</summary>
            <div class="answer">
                <p>Go to <a href="/join.php">/join.php</a>. Enter your email &mdash; that's the only required field. We'll send a verification link. Click it, and you're in.</p>
            </div>
        </details>

        <div style="text-align: center; margin: 2rem 0 1rem;">
            <p style="color: #aaa; font-size: 0.9rem; margin-bottom: 0.75rem;">Still have questions?</p>
            <a href="index.php" style="display: inline-block; padding: 12px 28px; background: linear-gradient(145deg, #4fc3f7, #0288d1); color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; font-size: 1rem; transition: transform 0.2s, box-shadow 0.2s;">Open Talk</a>
        </div>

        <p style="color: #555; font-size: 0.8rem; margin-top: 2rem; text-align: center;">
            The People's Branch &middot; Your voice, aggregated
        </p>
    </div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
