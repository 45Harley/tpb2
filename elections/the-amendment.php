<?php
/**
 * Winning the War — The People's Accountability Amendment
 * =======================================================
 * Ported from tpb.sandgems.net/war.php
 * Strategy page: the 28th Amendment, 70% recall, structural reforms.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = 'Winning the War — The People\'s Branch';

$pageStyles = <<<'CSS'
.war-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

.war-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #252540 100%);
    padding: 3rem 2rem; text-align: center; border-bottom: 3px solid #c53030;
    border-radius: 8px; margin-bottom: 2rem;
}
.war-hero h1 { font-size: 2.5em; text-transform: uppercase; letter-spacing: 4px; color: #c53030; margin-bottom: 0.5rem; }
.war-hero .number { font-size: 5rem; font-weight: 900; color: #d4af37; line-height: 1; }
.war-hero .tagline { font-size: 1.1rem; color: #aaa; margin-top: 0.75rem; }

.war-section-title {
    color: #7ab8e0; font-size: 1.4rem; margin: 2.5rem 0 1rem;
    padding-bottom: 0.5rem; border-bottom: 2px solid #d4af37;
}

.war-block {
    background: #252540; border: 1px solid #333; border-radius: 8px;
    padding: 1.5rem; margin: 1rem 0;
}
.war-block h3 { color: #d4af37; font-size: 1.25rem; margin-bottom: 0.75rem; }
.war-block p { color: #aaa; line-height: 1.8; font-size: 1rem; margin-bottom: 0.5rem; }
.war-block ul { margin: 0.75rem 0 0 1.5rem; color: #aaa; line-height: 2; }
.war-block li { margin-bottom: 0.4rem; }
.war-block a { color: #d4af37; text-decoration: none; }
.war-block a:hover { text-decoration: underline; }

.war-highlight {
    background: rgba(197, 48, 48, 0.15); border: 2px solid #c53030;
    border-radius: 8px; padding: 1.5rem; margin: 1rem 0; text-align: center;
}
.war-highlight h3 { color: #c53030; font-size: 1.3rem; margin-bottom: 0.5rem; }
.war-highlight p { color: #e0e0e0; font-size: 1.1rem; }

.war-cta {
    background: linear-gradient(135deg, #c53030 0%, #9b2c2c 100%);
    padding: 2.5rem 2rem; text-align: center; margin: 2rem 0; border-radius: 8px;
}
.war-cta h2 { color: #fff; border: none; margin: 0 0 0.75rem; font-size: 1.5rem; }
.war-cta p { color: rgba(255,255,255,0.9); margin-bottom: 1.25rem; }
.war-cta-btn {
    display: inline-block; background: #d4af37; color: #1a1a2e;
    padding: 0.75rem 2.5rem; border-radius: 4px; text-decoration: none;
    font-weight: 700; font-size: 1.1rem; transition: transform 0.2s;
}
.war-cta-btn:hover { transform: scale(1.05); }

.share-buttons { margin-top: 1.25rem; }
.share-buttons a, .share-buttons button {
    display: inline-block; background: rgba(255,255,255,0.2); color: #fff;
    padding: 0.5rem 1rem; border-radius: 4px; text-decoration: none;
    margin: 0.25rem; font-size: 0.85rem; border: none; cursor: pointer;
}
.share-buttons a:hover, .share-buttons button:hover { background: rgba(255,255,255,0.3); }

.war-details {
    background: #252540; border: 1px solid #333; border-radius: 8px;
    margin: 1rem 0; overflow: hidden;
}
.war-details summary {
    padding: 1.25rem; cursor: pointer; color: #7ab8e0;
    font-size: 1.1rem; font-weight: 600;
}
.war-details summary:hover { background: rgba(122, 184, 224, 0.1); }
.war-details-content { padding: 0 1.5rem 1.5rem; }
.war-details-content h4 { color: #d4af37; margin: 1.25rem 0 0.5rem; font-size: 0.9rem; text-transform: uppercase; }
.war-details-content p { color: #aaa; line-height: 1.8; margin-bottom: 0.5rem; }
.war-details-content .subsection { margin-left: 1.5rem; }

.comparison-table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
.comparison-table th { background: #333; color: #7ab8e0; padding: 0.75rem; text-align: left; }
.comparison-table td { padding: 0.75rem; border-bottom: 1px solid #333; color: #aaa; }
.comparison-table tr:nth-child(even) { background: rgba(255,255,255,0.02); }
.comparison-table .old { color: #c53030; }
.comparison-table .new { color: #38a169; font-weight: 600; }

.trigger-paths { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0; }
.trigger-path {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    padding: 1.25rem; text-align: center;
}
.trigger-path h4 { color: #7ab8e0; margin-bottom: 0.5rem; font-size: 0.9rem; }
.trigger-path .number { font-size: 2.5rem; font-weight: 900; color: #d4af37; }
.trigger-path .label { color: #aaa; font-size: 0.85rem; }

.objection {
    background: #1a1a2e; border-left: 3px solid #d4af37;
    padding: 0.75rem 1.25rem; margin: 0.75rem 0;
}
.objection h4 { color: #c53030; margin-bottom: 0.4rem; font-size: 0.95rem; }
.objection p { color: #aaa; font-size: 0.9rem; line-height: 1.6; }

.email-modal {
    display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.8); z-index: 9999; align-items: center;
    justify-content: center; overflow-y: auto; padding: 20px;
}
.email-modal.show { display: flex; }
.email-modal-content {
    background: #1a1a2e; padding: 2rem; border-radius: 12px;
    max-width: 500px; width: 90%; position: relative; text-align: center;
    max-height: 90vh; overflow-y: auto;
}
.email-modal-content h3 { color: #fff; margin-bottom: 1rem; }
.modal-close { position: absolute; top: 10px; right: 15px; background: none; border: none; color: #888; font-size: 1.5rem; cursor: pointer; }
.modal-close:hover { color: #fff; }
.email-preview { text-align: left; margin-bottom: 1.25rem; }
.preview-label { color: #888; font-size: 0.8rem; margin-bottom: 0.5rem; }
.preview-box {
    background: #fff; color: #333; padding: 1rem; border-radius: 8px;
    font-size: 0.8rem; line-height: 1.6; max-height: 200px; overflow-y: auto;
}
.email-input {
    width: 100%; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #444;
    background: #252540; color: #fff; font-size: 1rem; margin-bottom: 1rem;
}
.email-input:focus { outline: none; border-color: #7ab8e0; }
.send-btn {
    width: 100%; padding: 0.75rem; background: linear-gradient(135deg, #38a169, #2f855a);
    color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer;
}
.send-btn:hover { opacity: 0.9; }
.email-status { margin-top: 1rem; font-size: 0.85rem; }
.email-status.success { color: #38a169; }
.email-status.error { color: #c53030; }

@media (max-width: 600px) {
    .war-hero h1 { font-size: 1.8em; }
    .war-hero .number { font-size: 3.5rem; }
    .trigger-paths { grid-template-columns: 1fr; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';

$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
?>

<main class="war-container">

    <section class="war-hero">
        <h1>Winning the War</h1>
        <div class="number">70%</div>
        <p class="tagline">If 70% of Americans agree you must go, YOU MUST GO.</p>
    </section>

    <!-- 1. THE WAR -->
    <h2 class="war-section-title">1. THE WAR</h2>
    <div class="war-block">
        <h3>What We're Fighting</h3>
        <p>A corrupt administration that has seized power and is dismantling democracy. The existing system has failed to stop them. We need a new weapon.</p>
    </div>

    <!-- 2. THE WEAPON -->
    <h2 class="war-section-title">2. THE WEAPON</h2>
    <div class="war-block">
        <h3>The People's Accountability Amendment</h3>
        <p>A proposed 28th Amendment to the Constitution that gives the people the power to remove <strong>any</strong> federal official &mdash; President, Vice President, Supreme Court Justices, judges, cabinet members &mdash; with a 70% national vote.</p>
        <p>No more relying on 67 Senators who protect their own. <strong>The people decide.</strong></p>
    </div>

    <!-- 3. THE BATTLE -->
    <h2 class="war-section-title">3. THE BATTLE</h2>
    <div class="war-block">
        <h3>The Fight Happening Now</h3>
        <ul>
            <li><strong>Pledges</strong> &mdash; Commit to take action</li>
            <li><strong>Knockouts</strong> &mdash; Prove you took action</li>
            <li><strong>Pressure on Congress</strong> &mdash; Demand they impeach</li>
            <li><strong>The Amendment</strong> &mdash; Reinforces impeachment: they act, or WE remove them</li>
        </ul>
        <p style="margin-top: 1rem;"><a href="/elections/">&#8594; Join The Fight</a></p>
    </div>

    <!-- 4. WIN THE BATTLE -->
    <h2 class="war-section-title">4. WIN THE BATTLE</h2>
    <div class="war-highlight">
        <h3>Remove the Mob from Power</h3>
        <p>Use every tool available &mdash; impeachment, elections, the amendment &mdash; to remove those who have betrayed the public trust.</p>
    </div>

    <!-- 5. WIN THE WAR -->
    <h2 class="war-section-title">5. WIN THE WAR</h2>
    <div class="war-block">
        <h3>Ensure They Never Seize Power Again</h3>
        <ul>
            <li><strong>End gerrymandering</strong> &mdash; they rigged the map</li>
            <li><strong>End Citizens United</strong> &mdash; they bought the elections</li>
            <li><strong>Abolish Electoral College</strong> &mdash; they lost the people, won the power</li>
            <li><strong>Strengthen voting rights</strong> &mdash; they suppressed the vote</li>
            <li><strong>Term limits</strong> &mdash; they became entrenched</li>
            <li><strong>Anti-corruption laws</strong> &mdash; they looted without consequence</li>
            <li><strong>Protect free press</strong> &mdash; they attacked the truth</li>
            <li><strong>Civics education</strong> &mdash; they thrived on ignorance</li>
        </ul>
    </div>

    <!-- 6. THE STANDING ARMY -->
    <h2 class="war-section-title">6. THE STANDING ARMY</h2>
    <div class="war-block">
        <h3>Permanent Accountability</h3>
        <p>The 70% recall is the standing army &mdash; always ready, always watching.</p>
        <p style="font-size: 1.15rem; color: #d4af37;"><strong>Once they know we can remove them at any time, the war is won.</strong></p>
    </div>

    <!-- CTA -->
    <section class="war-cta">
        <h2>Take Action Now</h2>
        <p>Join The People's Branch. Make your pledges. Score your knockouts.</p>
        <a href="/elections/" class="war-cta-btn">Join The Fight</a>
        <div class="share-buttons">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode("70% - The People's Threshold. If 70% of Americans agree you must go, YOU MUST GO. #4tpb {$siteUrl}/elections/the-amendment.php") ?>" target="_blank">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode("70% - The People's Threshold. If 70% of Americans agree you must go, YOU MUST GO. #4tpb {$siteUrl}/elections/the-amendment.php") ?>" target="_blank">Share on Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("{$siteUrl}/elections/the-amendment.php") ?>" target="_blank">Share on Facebook</a>
            <button type="button" onclick="openEmailModal()">Email a Friend</button>
        </div>
    </section>

    <!-- THE DETAILS -->
    <h2 class="war-section-title" id="details">THE DETAILS</h2>

    <!-- Full Amendment Text -->
    <details class="war-details" id="secret-weapon">
        <summary>Full Amendment Text &mdash; THE SECRET WEAPON</summary>
        <div class="war-details-content">
            <h4>SECTION 1 &mdash; POPULAR RECALL</h4>
            <p>Upon a national popular vote of seventy percent (70%) or greater, any federal official&mdash;including the President, Vice President, Justices of the Supreme Court, judges of inferior courts, and all appointed officers of the United States&mdash;shall be immediately removed from office. Members of Congress shall be subject to recall by voters of their respective state or district under procedures established by state law.</p>

            <h4>SECTION 2 &mdash; TRIGGERING A RECALL ELECTION</h4>
            <p>A national recall election shall be scheduled within ninety (90) days when either:</p>
            <p class="subsection">(a) A petition bearing verified signatures equal to five percent (5%) of the total votes cast in the most recent presidential election is certified by the Federal Election Commission; or</p>
            <p class="subsection">(b) Two-thirds (2/3) of the state legislatures pass resolutions demanding a recall vote on the same official or officials.</p>
            <p>Multiple officials may be included on a single recall ballot. Each official shall be voted upon separately.</p>

            <h4>SECTION 3 &mdash; SPECIAL ELECTION TO FILL VACANCY</h4>
            <p>Upon removal of any official under this amendment:</p>
            <p class="subsection">(a) A special election to fill the vacancy shall be held within one hundred twenty (120) days.</p>
            <p class="subsection">(b) No interim appointment to the vacated office shall extend beyond this period.</p>
            <p class="subsection">(c) The removed official is permanently barred from holding any office of honor, trust, or profit under the United States.</p>
            <p class="subsection">(d) For judicial vacancies, the special election shall select from candidates nominated by a nonpartisan judicial commission established by law.</p>

            <h4>SECTION 4 &mdash; NO PARDON, NO IMMUNITY</h4>
            <p class="subsection">(a) No official removed under this amendment may be pardoned, by any authority, for any offense connected to their conduct in office.</p>
            <p class="subsection">(b) No claim of executive privilege, legislative immunity, judicial immunity, or any other immunity shall prevent investigation, indictment, or prosecution of a removed official.</p>
            <p class="subsection">(c) The President's pardon power shall not extend to any official facing a pending recall election under this amendment.</p>

            <h4>SECTION 5 &mdash; SELF-EXECUTING</h4>
            <p class="subsection">(a) This amendment is self-executing upon ratification.</p>
            <p class="subsection">(b) Congress shall have no power to limit, delay, or obstruct its implementation.</p>
            <p class="subsection">(c) The Federal Election Commission shall establish procedures for petition verification and recall elections within sixty (60) days of ratification.</p>
            <p class="subsection">(d) Any legal challenge to a recall election shall be resolved by the Supreme Court within thirty (30) days of filing, with original and exclusive jurisdiction.</p>

            <h4>SECTION 6 &mdash; ONLINE VOTING</h4>
            <p class="subsection">(a) All recall elections under this amendment shall be conducted via secure online voting, accessible to every registered voter, in addition to traditional in-person voting.</p>
            <p class="subsection">(b) The Federal Election Commission shall establish and maintain a national online voting system.</p>
            <p class="subsection">(c) No citizen shall be denied access to online voting due to lack of personal internet access. Public libraries and Post Offices shall provide free, private, accessible voting terminals.</p>
            <p class="subsection">(d) In-person voting shall remain available for any voter who prefers it.</p>
            <p class="subsection">(e) The voting period for any recall election shall be no less than fourteen (14) days.</p>
            <p class="subsection">(f) Funding: mandatory allocation of 0.1% of federal income tax revenues, automatically appropriated without Congressional action.</p>

            <h4>SECTION 7 &mdash; CONGRESSIONAL ENFORCEMENT</h4>
            <p>The Congress shall have power to enforce this article by appropriate legislation, provided that no such legislation shall diminish the rights herein guaranteed to the people.</p>
        </div>
    </details>

    <!-- By The Numbers -->
    <details class="war-details">
        <summary>By The Numbers</summary>
        <div class="war-details-content">
            <table class="comparison-table">
                <thead>
                    <tr>
                        <th>Current System</th>
                        <th>People's Amendment</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="old">535 politicians decide</td><td class="new">155+ million voters decide</td></tr>
                    <tr><td class="old">67 Senators needed to convict</td><td class="new">70% of voters (~108 million)</td></tr>
                    <tr><td class="old">Months or years of proceedings</td><td class="new">90 days to vote</td></tr>
                    <tr><td class="old">President can pardon co-conspirators</td><td class="new">NO pardons for removed officials</td></tr>
                    <tr><td class="old">Immunity claims delay justice</td><td class="new">Immunity explicitly stripped</td></tr>
                    <tr><td class="old">No replacement timeline</td><td class="new">Special election within 120 days</td></tr>
                </tbody>
            </table>
        </div>
    </details>

    <!-- How to Trigger -->
    <details class="war-details">
        <summary>How To Trigger A Recall</summary>
        <div class="war-details-content">
            <div class="trigger-paths">
                <div class="trigger-path">
                    <h4>PATH A: Citizen Petition</h4>
                    <div class="number">7.75M</div>
                    <div class="label">signatures needed</div>
                    <p style="margin-top: 0.5rem; font-size: 0.8rem; color: #aaa;">5% of votes from last presidential election</p>
                </div>
                <div class="trigger-path">
                    <h4>PATH B: State Legislatures</h4>
                    <div class="number">34</div>
                    <div class="label">states must pass resolutions</div>
                    <p style="margin-top: 0.5rem; font-size: 0.8rem; color: #aaa;">2/3 of state legislatures</p>
                </div>
            </div>
        </div>
    </details>

    <!-- Objections Answered -->
    <details class="war-details">
        <summary>Objections Answered</summary>
        <div class="war-details-content">
            <div class="objection">
                <h4>"70% is impossible to achieve"</h4>
                <p>Good. That's the point. This isn't for partisan disagreements&mdash;it's for genuine betrayal of public trust. The threshold ensures this tool is used only when there's true national consensus.</p>
            </div>
            <div class="objection">
                <h4>"Online voting isn't secure"</h4>
                <p>We bank online. We file taxes online. The security technology exists&mdash;what's lacked is political will. Open-source code, encryption, paper trails, and public audits make it secure.</p>
            </div>
            <div class="objection">
                <h4>"This could be abused by mobs"</h4>
                <p>The 70% threshold protects against mob rule. No purely partisan effort can reach 70%&mdash;it requires genuine, cross-party consensus.</p>
            </div>
            <div class="objection">
                <h4>"The Founders didn't include this"</h4>
                <p>The Founders also didn't include women's suffrage, direct election of Senators, or presidential term limits. The Constitution is designed to be amended.</p>
            </div>
        </div>
    </details>

    <!-- Path to Ratification -->
    <details class="war-details">
        <summary>Path To Ratification</summary>
        <div class="war-details-content">
            <p><strong>Path 1: Congressional Proposal</strong><br>
            2/3 of House AND 2/3 of Senate must propose, then 3/4 of states ratify.<br>
            <em style="color: #c53030;">Reality: Congress will never vote to give away its own power.</em></p>

            <p style="margin-top: 1rem;"><strong>Path 2: State Convention &#10003;</strong><br>
            2/3 of state legislatures (34 states) call a constitutional convention. Convention proposes amendment. 3/4 of states (38) ratify.<br>
            <em style="color: #38a169;">This is how the people bypass a corrupt Congress.</em></p>

            <p style="margin-top: 1rem;"><strong>Our Strategy:</strong> Build grassroots pressure state-by-state. Win state legislatures. Call the convention. Ratify the amendment. Take back our power.</p>
        </div>
    </details>

</main>

<!-- Email Modal -->
<div id="email-modal" class="email-modal">
    <div class="email-modal-content">
        <button class="modal-close" onclick="closeEmailModal()">&times;</button>
        <h3>Email This to a Friend</h3>
        <div class="email-preview">
            <div class="preview-label">Preview:</div>
            <div class="preview-box">
                <strong>Subject:</strong> The People's Accountability Amendment - How We Fix Democracy<br><br>
                <span style="color:#d97706; font-size:1.2em; font-weight:bold;">70%</span> &mdash; The People's Threshold<br><br>
                If 70% of Americans agree you must go, <strong>YOU MUST GO.</strong><br><br>
                No more waiting for Congress to impeach. No more hoping politicians hold their own accountable. No more watching corruption go unpunished.<br><br>
                The People's Accountability Amendment gives citizens the power to recall any federal official &mdash; including the President &mdash; with a 70% vote.<br><br>
                Plus: <span style="color:#c53030;"><strong>NO pardons</strong></span> for removed officials. <span style="color:#c53030;"><strong>NO immunity</strong></span> claims to delay justice.<br><br>
                <em>19 states already allow recall of governors. Most democracies can remove leaders through no-confidence votes. Why can't Americans recall a president who betrays us?</em><br><br>
                <strong>It's time to bring America into the 21st century.</strong>
            </div>
        </div>
        <input type="email" id="recruit-email" autocomplete="email" placeholder="friend@example.com" class="email-input">
        <button type="button" onclick="sendRecruitEmail()" class="send-btn">Send Email</button>
        <div id="email-status" class="email-status"></div>
    </div>
</div>

<script>
function openEmailModal() {
    document.getElementById('email-modal').classList.add('show');
    document.getElementById('recruit-email').focus();
}

function closeEmailModal() {
    document.getElementById('email-modal').classList.remove('show');
    document.getElementById('email-status').textContent = '';
    document.getElementById('recruit-email').value = '';
}

function sendRecruitEmail() {
    const email = document.getElementById('recruit-email').value.trim();
    const status = document.getElementById('email-status');

    if (!email) {
        status.textContent = 'Please enter an email address';
        status.className = 'email-status error';
        return;
    }

    status.textContent = 'Sending...';
    status.className = 'email-status';

    fetch('/api/email-recruit.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ to: email, type: 'amendment' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            status.textContent = 'Email sent!';
            status.className = 'email-status success';
            setTimeout(closeEmailModal, 2000);
        } else {
            status.textContent = data.error || 'Failed to send';
            status.className = 'email-status error';
        }
    })
    .catch(() => {
        status.textContent = 'Failed to send email';
        status.className = 'email-status error';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('email-modal').addEventListener('click', function(e) {
        if (e.target === this) closeEmailModal();
    });
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
