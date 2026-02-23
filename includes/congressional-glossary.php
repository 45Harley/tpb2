<?php
/**
 * Congressional Glossary — plain-English definitions for legislative jargon.
 * Used for hover tooltips across the platform.
 *
 * Usage:
 *   require_once __DIR__ . '/congressional-glossary.php';
 *   echo cg('cloture');          // Returns tooltip-wrapped HTML
 *   echo cg_def('cloture');      // Returns just the definition text
 *   echo cg_js();                // Outputs the tooltip JS/CSS (once per page)
 */

/**
 * Master glossary array.
 * Keys are lowercase lookup slugs. Each entry has:
 *   'term'  => display text (proper capitalization)
 *   'short' => one-line plain-English definition (shown on hover)
 *   'cat'   => category for grouping
 */
function congressionalGlossary(): array {
    static $g = null;
    if ($g !== null) return $g;

    $g = [

        // ── Voting & Procedures ──
        'roll call' => [
            'term'  => 'Roll Call',
            'short' => 'A recorded vote where every member\'s Yes or No is logged by name. This is how we know exactly how your rep voted.',
            'cat'   => 'Voting',
        ],
        'yea' => [
            'term'  => 'Yea',
            'short' => 'The official word for "Yes" in Congress. When a member votes Yea, they are voting in favor.',
            'cat'   => 'Voting',
        ],
        'nay' => [
            'term'  => 'Nay',
            'short' => 'The official word for "No" in Congress. When a member votes Nay, they are voting against.',
            'cat'   => 'Voting',
        ],
        'not voting' => [
            'term'  => 'Not Voting',
            'short' => 'The member was absent or chose not to cast a vote. This counts as a missed vote in their attendance record.',
            'cat'   => 'Voting',
        ],
        'present' => [
            'term'  => 'Present',
            'short' => 'The member was there but chose not to vote Yes or No — often to avoid a conflict of interest or to make a political statement.',
            'cat'   => 'Voting',
        ],
        'voice vote' => [
            'term'  => 'Voice Vote',
            'short' => 'Members shout "Yea" or "Nay" together and the presiding officer decides which side was louder. No individual votes are recorded.',
            'cat'   => 'Voting',
        ],
        'recorded vote' => [
            'term'  => 'Recorded Vote',
            'short' => 'Same as a roll call — every member\'s vote is documented individually so the public can see it.',
            'cat'   => 'Voting',
        ],
        'on passage' => [
            'term'  => 'On Passage',
            'short' => 'The final vote on whether a bill passes. If it gets enough Yes votes, it moves to the other chamber or to the President.',
            'cat'   => 'Voting',
        ],

        // ── Motions ──
        'cloture' => [
            'term'  => 'Cloture',
            'short' => 'A vote to end debate and stop a filibuster. Requires 60 out of 100 senators (a "3/5 majority"). If cloture fails, the bill is effectively blocked.',
            'cat'   => 'Motions',
        ],
        'filibuster' => [
            'term'  => 'Filibuster',
            'short' => 'When senators use extended debate to delay or block a vote. The only way to stop one is a cloture vote (60 senators needed).',
            'cat'   => 'Motions',
        ],
        'motion to proceed' => [
            'term'  => 'Motion to Proceed',
            'short' => 'A vote on whether to even start discussing a bill. If it fails, the bill never gets debated on the floor.',
            'cat'   => 'Motions',
        ],
        'motion to table' => [
            'term'  => 'Motion to Table',
            'short' => 'A vote to set something aside — essentially killing it without a direct up-or-down vote. It\'s a common tactic to avoid going on record.',
            'cat'   => 'Motions',
        ],
        'motion to recommit' => [
            'term'  => 'Motion to Recommit',
            'short' => 'A last-chance move (usually by the minority party) to send a bill back to committee — either to add changes or to kill it.',
            'cat'   => 'Motions',
        ],
        'motion to suspend the rules' => [
            'term'  => 'Suspend the Rules',
            'short' => 'A shortcut in the House to skip normal debate and pass a bill quickly. Requires 2/3 majority, so it\'s used for non-controversial bills.',
            'cat'   => 'Motions',
        ],
        'motion to concur' => [
            'term'  => 'Motion to Concur',
            'short' => 'A vote to accept changes the other chamber made to a bill. Both House and Senate must pass identical text before it goes to the President.',
            'cat'   => 'Motions',
        ],
        'motion to discharge' => [
            'term'  => 'Motion to Discharge',
            'short' => 'A way to force a bill out of committee and onto the floor for a vote, even if the committee chair is blocking it. Rarely succeeds.',
            'cat'   => 'Motions',
        ],
        'motion to commit' => [
            'term'  => 'Motion to Commit',
            'short' => 'A vote to send a bill to a specific committee for further review, which often delays or kills it.',
            'cat'   => 'Motions',
        ],
        'motion to refer' => [
            'term'  => 'Motion to Refer',
            'short' => 'Similar to motion to commit — sends a matter to a committee for study, often used to delay action.',
            'cat'   => 'Motions',
        ],
        'motion to reconsider' => [
            'term'  => 'Motion to Reconsider',
            'short' => 'A request to vote again on something that already passed or failed. Usually "laid on the table" immediately to lock in the result.',
            'cat'   => 'Motions',
        ],
        'motion to adjourn' => [
            'term'  => 'Motion to Adjourn',
            'short' => 'A vote to end the day\'s session. Sometimes used as a delay tactic or protest move.',
            'cat'   => 'Motions',
        ],
        'previous question' => [
            'term'  => 'Previous Question',
            'short' => 'A House procedure to cut off debate and force an immediate vote. If it passes, no more amendments or discussion — straight to the vote.',
            'cat'   => 'Motions',
        ],
        'laid on the table' => [
            'term'  => 'Laid on the Table',
            'short' => 'Setting a matter aside. In the Senate, this effectively kills it. "Motion to reconsider laid on the table" means the vote result is final.',
            'cat'   => 'Motions',
        ],
        'point of order' => [
            'term'  => 'Point of Order',
            'short' => 'A member\'s objection that a rule is being broken. The presiding officer decides if they\'re right. Can be used to block bills that violate budget rules.',
            'cat'   => 'Motions',
        ],
        'decision of chair' => [
            'term'  => 'Decision of the Chair',
            'short' => 'The presiding officer\'s ruling on a point of order. Members can vote to overturn it ("not sustained") or uphold it ("sustained").',
            'cat'   => 'Motions',
        ],
        'veto override' => [
            'term'  => 'Veto Override',
            'short' => 'When Congress votes to pass a bill the President rejected. Requires 2/3 of both House and Senate — very hard to achieve.',
            'cat'   => 'Motions',
        ],
        'en bloc' => [
            'term'  => 'En Bloc',
            'short' => 'French for "as a group." Congress votes on multiple items bundled together in a single vote instead of voting on each one separately. Often used for amendments or nominations to save time.',
            'cat'   => 'Motions',
        ],

        // ── Thresholds & Rules ──
        'simple majority' => [
            'term'  => 'Simple Majority',
            'short' => 'More than half the votes — the default threshold for most bills. In the Senate, that\'s 51 of 100 (or 50 + Vice President).',
            'cat'   => 'Rules',
        ],
        'supermajority' => [
            'term'  => 'Supermajority',
            'short' => 'A higher-than-normal vote threshold. "3/5 majority" means 60 of 100 senators (for cloture). "2/3 majority" means 67 (for veto overrides).',
            'cat'   => 'Rules',
        ],
        '3/5 majority' => [
            'term'  => '3/5 Majority Required',
            'short' => '60 out of 100 senators must vote Yes. This is the threshold to end a filibuster (cloture). Many important bills fail because they can\'t reach 60.',
            'cat'   => 'Rules',
        ],
        'quorum' => [
            'term'  => 'Quorum',
            'short' => 'The minimum number of members who must be present to conduct business. In the House, that\'s 218 of 435; in the Senate, 51 of 100.',
            'cat'   => 'Rules',
        ],

        // ── Bills & Resolutions ──
        // KEY DISTINCTION: Bills (H.R., S.) can become law. Resolutions (H.Res., S.Res.) CANNOT — they're internal rules or statements.
        // Joint resolutions (H.J.Res., S.J.Res.) are the exception: they CAN become law.
        'bill' => [
            'term'  => 'Bill',
            'short' => 'A proposed law. Bills (H.R. and S.) must pass both chambers and be signed by the President to become law. This is how most laws are made.',
            'cat'   => 'Bills & Resolutions',
        ],
        'resolution' => [
            'term'  => 'Resolution',
            'short' => 'NOT a bill and does NOT become law. A resolution is an internal rule or statement by one chamber (H.Res. or S.Res.). Don\'t confuse with joint resolutions (H.J.Res./S.J.Res.), which DO become law.',
            'cat'   => 'Bills & Resolutions',
        ],
        'hr' => [
            'term'  => 'H.R. (House Bill)',
            'short' => 'A bill that starts in the House of Representatives. If it passes both chambers and the President signs it, it becomes law.',
            'cat'   => 'Bills & Resolutions',
        ],
        's' => [
            'term'  => 'S. (Senate Bill)',
            'short' => 'A bill that starts in the Senate. Same process as a House bill — needs to pass both chambers and get the President\'s signature.',
            'cat'   => 'Bills & Resolutions',
        ],
        'hres' => [
            'term'  => 'H.Res. (House Resolution)',
            'short' => 'NOT a bill. A resolution that only applies to the House itself — changing its own rules, or making a statement. Does NOT become law and is NOT sent to the President.',
            'cat'   => 'Bills & Resolutions',
        ],
        'sres' => [
            'term'  => 'S.Res. (Senate Resolution)',
            'short' => 'NOT a bill. A resolution that only applies to the Senate — internal rules or statements of opinion. Does NOT become law and is NOT sent to the President.',
            'cat'   => 'Bills & Resolutions',
        ],
        'hjres' => [
            'term'  => 'H.J.Res. (House Joint Resolution)',
            'short' => 'Unlike a regular resolution, a joint resolution HAS the force of law — it goes through both chambers and the President signs it. Used for constitutional amendments and emergency measures.',
            'cat'   => 'Bills & Resolutions',
        ],
        'sjres' => [
            'term'  => 'S.J.Res. (Senate Joint Resolution)',
            'short' => 'Unlike a regular resolution, a joint resolution HAS the force of law. Same as H.J.Res. but starts in the Senate. Also used for constitutional amendments.',
            'cat'   => 'Bills & Resolutions',
        ],
        'hconres' => [
            'term'  => 'H.Con.Res. (House Concurrent Resolution)',
            'short' => 'Passed by both chambers but NOT sent to the President. Used for budget resolutions and joint statements. Does NOT become law.',
            'cat'   => 'Bills & Resolutions',
        ],
        'sconres' => [
            'term'  => 'S.Con.Res. (Senate Concurrent Resolution)',
            'short' => 'Same as House concurrent resolution, starting in the Senate. Used for budgets and shared positions. Does NOT become law.',
            'cat'   => 'Bills & Resolutions',
        ],

        // ── Amendments ──
        'amendment' => [
            'term'  => 'Amendment',
            'short' => 'A proposed change to a bill before it gets a final vote. Can add, remove, or rewrite sections of the bill.',
            'cat'   => 'Amendments',
        ],
        'samdt' => [
            'term'  => 'S.Amdt. (Senate Amendment)',
            'short' => 'An amendment proposed in the Senate to change a bill under consideration.',
            'cat'   => 'Amendments',
        ],
        'hamdt' => [
            'term'  => 'H.Amdt. (House Amendment)',
            'short' => 'An amendment proposed in the House to change a bill under consideration.',
            'cat'   => 'Amendments',
        ],

        // ── Scorecard Metrics ──
        'participation' => [
            'term'  => 'Participation',
            'short' => 'How often this member shows up to vote when a roll call happens. 100% means they voted every time. The national average is about 96%.',
            'cat'   => 'Metrics',
        ],
        'party loyalty' => [
            'term'  => 'Party Loyalty',
            'short' => 'How often this member votes the same way as the majority of their own party. High loyalty = a reliable party voter. Low loyalty = more independent.',
            'cat'   => 'Metrics',
        ],
        'bipartisanship' => [
            'term'  => 'Bipartisanship',
            'short' => 'How often this member votes the same way as the majority of the opposing party. Higher = more willing to work across the aisle.',
            'cat'   => 'Metrics',
        ],
        'bills sponsored' => [
            'term'  => 'Bills Sponsored',
            'short' => 'Legislation this member formally introduced. Being the sponsor means they wrote it (or their staff did) and put their name on it.',
            'cat'   => 'Metrics',
        ],
        'substantive' => [
            'term'  => 'Substantive Bills',
            'short' => 'Actual proposed laws (H.R. or S. bills) that would change something if passed — as opposed to symbolic resolutions that just make statements.',
            'cat'   => 'Metrics',
        ],
        'chamber average' => [
            'term'  => 'Chamber Average',
            'short' => 'The average score across all members of the House (435) or Senate (100). Shown as a marker on the bar so you can see how your rep compares.',
            'cat'   => 'Metrics',
        ],
        'chamber rank' => [
            'term'  => 'Chamber Rank',
            'short' => 'Where this member stands compared to everyone else in their chamber. #1 is the best. Out of 435 in the House or 100 in the Senate.',
            'cat'   => 'Metrics',
        ],

        // ── Structure ──
        'chamber' => [
            'term'  => 'Chamber',
            'short' => 'Congress has two chambers: the House of Representatives (435 members, based on population) and the Senate (100 members, 2 per state).',
            'cat'   => 'Structure',
        ],
        'congress' => [
            'term'  => 'Congress',
            'short' => 'A two-year session of the legislature. The 119th Congress runs January 2025 to January 2027. Elections happen every even year.',
            'cat'   => 'Structure',
        ],
        'session' => [
            'term'  => 'Session',
            'short' => 'Each Congress has two sessions — one per year. The 1st session is the first year, the 2nd session is the second year.',
            'cat'   => 'Structure',
        ],
        'committee' => [
            'term'  => 'Committee',
            'short' => 'A small group of members who specialize in one topic (like Armed Services, or Finance). Bills go to committee before the full chamber votes.',
            'cat'   => 'Structure',
        ],
        'subcommittee' => [
            'term'  => 'Subcommittee',
            'short' => 'An even smaller group within a committee that focuses on a specific subtopic. For example, the Armed Services Committee has a Cybersecurity subcommittee.',
            'cat'   => 'Structure',
        ],
        'ranking member' => [
            'term'  => 'Ranking Member',
            'short' => 'The most senior member of the minority party on a committee. They\'re basically the "shadow chair" — they lead the opposition\'s strategy on that committee.',
            'cat'   => 'Structure',
        ],
        'chairman' => [
            'term'  => 'Chairman / Chair',
            'short' => 'The member who leads a committee. Always from the majority party. They decide which bills get hearings and which ones get ignored.',
            'cat'   => 'Structure',
        ],
        'ex officio' => [
            'term'  => 'Ex Officio',
            'short' => 'A member who sits on a committee "by virtue of their position" — like a party leader who can attend any committee but may not vote.',
            'cat'   => 'Structure',
        ],
        'vice chair' => [
            'term'  => 'Vice Chair',
            'short' => 'Second-in-command on a committee. Runs the meeting when the Chair is absent.',
            'cat'   => 'Structure',
        ],

        // ── Reports & Communications ──
        'hrpt' => [
            'term'  => 'H.Rpt. (House Report)',
            'short' => 'A written document from a House committee explaining a bill — what it does, why it\'s needed, and how the committee voted on it.',
            'cat'   => 'Documents',
        ],
        'srpt' => [
            'term'  => 'S.Rpt. (Senate Report)',
            'short' => 'Same as a House report but from a Senate committee. These documents explain the committee\'s reasoning on a bill.',
            'cat'   => 'Documents',
        ],
        'ec' => [
            'term'  => 'EC (Executive Communication)',
            'short' => 'A message from the President or an executive agency to Congress — like a budget proposal, a report, or a request for action.',
            'cat'   => 'Documents',
        ],
        'pm' => [
            'term'  => 'PM (Presidential Message)',
            'short' => 'A formal message from the President to Congress, such as notifying them about a military action, treaty, or nomination.',
            'cat'   => 'Documents',
        ],
        'ml' => [
            'term'  => 'ML (Memorial)',
            'short' => 'A formal statement from a state legislature asking Congress to take action on something — like a petition from the states.',
            'cat'   => 'Documents',
        ],
        'pom' => [
            'term'  => 'POM (Petition or Memorial)',
            'short' => 'A petition or request sent to Congress from citizens, state legislatures, or organizations asking for specific action.',
            'cat'   => 'Documents',
        ],
        'pt' => [
            'term'  => 'PT (Presidential Text)',
            'short' => 'The text of a presidential message submitted to Congress for the official record.',
            'cat'   => 'Documents',
        ],

        // ── Executive Terms ──
        'executive calendar' => [
            'term'  => 'Executive Calendar',
            'short' => 'The Senate\'s schedule for nominations and treaties — separate from the regular legislative calendar. When a nomination is "placed on the Executive Calendar," it\'s ready for a Senate floor vote.',
            'cat'   => 'Process',
        ],
        'executive order' => [
            'term'  => 'Executive Order',
            'short' => 'NOT legislation. An executive order is a directive from the President to federal agencies. It does NOT go through Congress, though Congress can pass laws to override one.',
            'cat'   => 'Process',
        ],
        'executive session' => [
            'term'  => 'Executive Session',
            'short' => 'When the Senate switches from regular business to handle nominations and treaties. Despite the name, it\'s usually open to the public — "executive" refers to executive branch appointments.',
            'cat'   => 'Process',
        ],

        // ── Nominations ──
        'nomination' => [
            'term'  => 'Nomination',
            'short' => 'When the President picks someone for a federal position (judges, ambassadors, cabinet members). The Senate must vote to confirm them.',
            'cat'   => 'Nominations',
        ],
        'confirmed' => [
            'term'  => 'Confirmed',
            'short' => 'The Senate voted to approve the President\'s nominee. They can now take the position.',
            'cat'   => 'Nominations',
        ],

        // ── Process / Actions ──
        'referred to committee' => [
            'term'  => 'Referred to Committee',
            'short' => 'A bill has been assigned to a committee for review. This is the first step for most new bills. Many bills die here and never get a hearing.',
            'cat'   => 'Process',
        ],
        'read twice' => [
            'term'  => 'Read Twice',
            'short' => 'A Senate formality. Bills must be "read" twice on two separate days before being assigned to a committee. Usually happens in seconds.',
            'cat'   => 'Process',
        ],
        'sponsor' => [
            'term'  => 'Sponsor',
            'short' => 'The member of Congress who officially introduced the bill. Their name goes on it and they champion it through the process.',
            'cat'   => 'Process',
        ],
        'cosponsor' => [
            'term'  => 'Cosponsor',
            'short' => 'A member who signs on to support someone else\'s bill. More cosponsors = more support, which helps a bill get attention.',
            'cat'   => 'Process',
        ],
        'hearing' => [
            'term'  => 'Hearing',
            'short' => 'When a committee invites experts and officials to testify about a bill or issue. It\'s how Congress gathers information before deciding.',
            'cat'   => 'Process',
        ],
        'markup' => [
            'term'  => 'Markup',
            'short' => 'When a committee goes through a bill line by line, debating and voting on changes. This is where bills get shaped before going to the full chamber.',
            'cat'   => 'Process',
        ],
        'engrossed' => [
            'term'  => 'Engrossed',
            'short' => 'The official final version of a bill as passed by one chamber, with all amendments incorporated. It\'s then sent to the other chamber.',
            'cat'   => 'Process',
        ],
        'enrolled' => [
            'term'  => 'Enrolled',
            'short' => 'The final, final version of a bill after both chambers have agreed on identical text. This is the copy that goes to the President for signing.',
            'cat'   => 'Process',
        ],
        'conference committee' => [
            'term'  => 'Conference Committee',
            'short' => 'A temporary group of House and Senate members who meet to work out differences when both chambers passed different versions of the same bill.',
            'cat'   => 'Process',
        ],
        'unanimous consent' => [
            'term'  => 'Unanimous Consent',
            'short' => 'A request to skip the normal rules and do something quickly — like pass a non-controversial bill. If even ONE member objects, it fails.',
            'cat'   => 'Process',
        ],
        'yielding' => [
            'term'  => 'Yielding',
            'short' => 'When a member gives up some of their speaking time to let another member talk. "I yield 2 minutes to the gentleman from Ohio."',
            'cat'   => 'Process',
        ],
        'germane' => [
            'term'  => 'Germane',
            'short' => 'An amendment must be "germane" — meaning it\'s actually related to the bill. In the House, this rule is strict. In the Senate, it\'s often ignored.',
            'cat'   => 'Process',
        ],
        'rider' => [
            'term'  => 'Rider',
            'short' => 'An unrelated provision attached to a bill that would never pass on its own. A classic example: sneaking a pet project into a must-pass spending bill.',
            'cat'   => 'Process',
        ],
        'omnibus' => [
            'term'  => 'Omnibus Bill',
            'short' => 'A giant bill that bundles many different topics together. Common for spending bills. Members often complain they had no time to read it all.',
            'cat'   => 'Process',
        ],
        'floor' => [
            'term'  => 'Floor',
            'short' => 'The main meeting area of the House or Senate where all members gather to debate and vote. "On the floor" means something is being debated by the full chamber.',
            'cat'   => 'Process',
        ],
        'tabling' => [
            'term'  => 'Tabling',
            'short' => 'In Congress, tabling something means killing it — the opposite of the everyday meaning. A motion "laid on the table" is essentially dead.',
            'cat'   => 'Process',
        ],

        // ── Parties & Leadership ──
        'speaker of the house' => [
            'term'  => 'Speaker of the House',
            'short' => 'The most powerful member of Congress. Elected by House members, always from the majority party. Controls which bills get votes, assigns committees, and is 2nd in line for the presidency.',
            'cat'   => 'Leadership',
        ],
        'majority leader' => [
            'term'  => 'Majority Leader',
            'short' => 'The head of the majority party. In the Senate, this is the most powerful person — they control the floor schedule. In the House, they\'re second to the Speaker.',
            'cat'   => 'Leadership',
        ],
        'minority leader' => [
            'term'  => 'Minority Leader',
            'short' => 'The head of the minority party in either chamber. They lead the opposition strategy and speak for their party on the floor.',
            'cat'   => 'Leadership',
        ],
        'whip' => [
            'term'  => 'Whip',
            'short' => 'A party leader whose job is to count votes and pressure members to vote with the party. The name comes from fox hunting — "whipping in" the hounds.',
            'cat'   => 'Leadership',
        ],
        'caucus' => [
            'term'  => 'Caucus',
            'short' => 'A group of members who share a common interest or identity. The Congressional Black Caucus, the Freedom Caucus, or simply all Democrats or all Republicans meeting together.',
            'cat'   => 'Leadership',
        ],
        'majority party' => [
            'term'  => 'Majority Party',
            'short' => 'The party with more than half the seats in a chamber. They control committee chairs, the floor schedule, and which bills get votes.',
            'cat'   => 'Leadership',
        ],
        'minority party' => [
            'term'  => 'Minority Party',
            'short' => 'The party with fewer seats. They can slow things down (especially in the Senate with filibusters) but can\'t pass bills on their own.',
            'cat'   => 'Leadership',
        ],
        'freshman' => [
            'term'  => 'Freshman',
            'short' => 'A member serving their first term in Congress. Freshmen usually get less desirable committee assignments and less floor time.',
            'cat'   => 'Leadership',
        ],
        'president pro tempore' => [
            'term'  => 'President Pro Tempore',
            'short' => 'The Senate\'s presiding officer when the Vice President is absent (which is almost always). By tradition, it\'s the longest-serving senator of the majority party. Third in line for the presidency.',
            'cat'   => 'Leadership',
        ],
        'presiding officer' => [
            'term'  => 'Presiding Officer',
            'short' => 'The person running the session from the chair — maintaining order, recognizing speakers, ruling on procedures. In the Senate, usually a junior member filling in.',
            'cat'   => 'Leadership',
        ],

        // ── Budget & Money ──
        'appropriations' => [
            'term'  => 'Appropriations',
            'short' => 'The process of deciding how much money each government program actually gets. Authorization says "you CAN spend up to $X." Appropriations says "here\'s the check."',
            'cat'   => 'Budget',
        ],
        'authorization' => [
            'term'  => 'Authorization',
            'short' => 'A law that creates or continues a government program and sets a spending limit. But no money flows until a separate appropriations bill funds it.',
            'cat'   => 'Budget',
        ],
        'continuing resolution' => [
            'term'  => 'Continuing Resolution (CR)',
            'short' => 'A temporary spending bill that keeps the government funded at last year\'s levels when Congress can\'t agree on new appropriations by the deadline.',
            'cat'   => 'Budget',
        ],
        'government shutdown' => [
            'term'  => 'Government Shutdown',
            'short' => 'When Congress fails to pass spending bills by the deadline. Non-essential federal workers are furloughed, national parks close, and some services stop.',
            'cat'   => 'Budget',
        ],
        'debt ceiling' => [
            'term'  => 'Debt Ceiling',
            'short' => 'The legal limit on how much the U.S. government can borrow. Congress must vote to raise it, or the government risks defaulting on its debts — a potential economic catastrophe.',
            'cat'   => 'Budget',
        ],
        'reconciliation' => [
            'term'  => 'Reconciliation',
            'short' => 'A special budget process that lets the Senate pass certain tax and spending bills with just 51 votes instead of 60. It bypasses the filibuster, so it\'s a powerful tool for the majority party.',
            'cat'   => 'Budget',
        ],
        'sequestration' => [
            'term'  => 'Sequestration',
            'short' => 'Automatic, across-the-board spending cuts triggered when Congress can\'t agree on deficit reduction. Designed to be so painful that it forces a deal.',
            'cat'   => 'Budget',
        ],
        'cbo score' => [
            'term'  => 'CBO Score',
            'short' => 'The Congressional Budget Office\'s estimate of what a bill will cost (or save) over 10 years. A non-partisan reality check — members often cite it to support or attack a bill.',
            'cat'   => 'Budget',
        ],
        'fiscal year' => [
            'term'  => 'Fiscal Year',
            'short' => 'The government\'s budget year runs October 1 to September 30 — NOT January to December. "FY2026" started on October 1, 2025.',
            'cat'   => 'Budget',
        ],
        'discretionary spending' => [
            'term'  => 'Discretionary Spending',
            'short' => 'The ~30% of the federal budget that Congress votes on each year — defense, education, infrastructure, science. The rest is mandatory (Social Security, Medicare, interest).',
            'cat'   => 'Budget',
        ],
        'mandatory spending' => [
            'term'  => 'Mandatory Spending',
            'short' => 'The ~70% of the federal budget that runs on autopilot — Social Security, Medicare, Medicaid, interest on the debt. Congress doesn\'t vote on it annually; changing it requires new legislation.',
            'cat'   => 'Budget',
        ],
        'earmark' => [
            'term'  => 'Earmark',
            'short' => 'Money set aside in a spending bill for a specific local project — a bridge, a hospital, a research lab. Critics call them "pork barrel spending." Supporters say members know their districts best.',
            'cat'   => 'Budget',
        ],

        // ── Legal / Constitutional ──
        'impeachment' => [
            'term'  => 'Impeachment',
            'short' => 'The House votes to formally charge a federal official (President, judge, etc.) with misconduct. It\'s like an indictment — the Senate then holds the trial.',
            'cat'   => 'Constitutional',
        ],
        'articles of impeachment' => [
            'term'  => 'Articles of Impeachment',
            'short' => 'The specific charges against an official. Each article is a separate charge — "abuse of power," "obstruction of Congress," etc. The House votes on each one.',
            'cat'   => 'Constitutional',
        ],
        'treaty' => [
            'term'  => 'Treaty',
            'short' => 'A formal agreement with another country. The President negotiates it, but 2/3 of the Senate (67 votes) must approve it — one of the Senate\'s unique powers.',
            'cat'   => 'Constitutional',
        ],
        'war powers' => [
            'term'  => 'War Powers',
            'short' => 'Only Congress can officially declare war (Article I). The War Powers Resolution of 1973 requires the President to notify Congress within 48 hours of deploying troops.',
            'cat'   => 'Constitutional',
        ],
        'separation of powers' => [
            'term'  => 'Separation of Powers',
            'short' => 'The Constitution divides government into three branches: Congress (makes laws), the President (enforces laws), and the Courts (interpret laws). Each checks the others.',
            'cat'   => 'Constitutional',
        ],
        'checks and balances' => [
            'term'  => 'Checks and Balances',
            'short' => 'Each branch can limit the others. Congress passes laws, the President can veto them, Congress can override the veto, and courts can strike laws down as unconstitutional.',
            'cat'   => 'Constitutional',
        ],
        'advice and consent' => [
            'term'  => 'Advice and Consent',
            'short' => 'The Senate\'s constitutional power to approve or reject presidential nominations and treaties. "Advice" means the Senate can consult; "consent" means they must approve.',
            'cat'   => 'Constitutional',
        ],
        'recess appointment' => [
            'term'  => 'Recess Appointment',
            'short' => 'When the President fills a position while the Senate is on break, skipping the confirmation vote. The appointment expires at the end of the next Senate session.',
            'cat'   => 'Constitutional',
        ],
        'subpoena' => [
            'term'  => 'Subpoena',
            'short' => 'A legal order from Congress compelling someone to testify or produce documents. Ignoring a congressional subpoena can lead to contempt of Congress charges.',
            'cat'   => 'Constitutional',
        ],
        'contempt of congress' => [
            'term'  => 'Contempt of Congress',
            'short' => 'A charge against someone who refuses to comply with a congressional subpoena or obstructs an investigation. Can result in fines or even jail time.',
            'cat'   => 'Constitutional',
        ],

        // ── Nominations (expanded) ──
        'senatorial courtesy' => [
            'term'  => 'Senatorial Courtesy',
            'short' => 'An unwritten tradition: the President consults with senators from the nominee\'s home state before nominating federal judges there. If the home-state senator objects, the nomination often stalls.',
            'cat'   => 'Nominations',
        ],
        'blue slip' => [
            'term'  => 'Blue Slip',
            'short' => 'A literal blue piece of paper. Home-state senators return it to signal approval of a judicial nominee. If a senator withholds their blue slip, the nomination may never get a hearing.',
            'cat'   => 'Nominations',
        ],
        'confirmation hearing' => [
            'term'  => 'Confirmation Hearing',
            'short' => 'A committee hearing where senators question a presidential nominee before voting whether to send the nomination to the full Senate. Can be routine or politically explosive.',
            'cat'   => 'Nominations',
        ],
        'hold' => [
            'term'  => 'Hold',
            'short' => 'When a single senator privately asks the leader to delay action on a bill or nomination. It\'s an informal practice but very powerful — one person can block the whole Senate.',
            'cat'   => 'Nominations',
        ],

        // ── Electoral & Districts ──
        'congressional district' => [
            'term'  => 'Congressional District',
            'short' => 'One of 435 geographic areas, each represented by one House member. Redrawn every 10 years after the census. Each district has roughly 760,000 people.',
            'cat'   => 'Electoral',
        ],
        'apportionment' => [
            'term'  => 'Apportionment',
            'short' => 'How the 435 House seats are divided among the 50 states based on population. After each census, fast-growing states gain seats and shrinking states lose them.',
            'cat'   => 'Electoral',
        ],
        'redistricting' => [
            'term'  => 'Redistricting',
            'short' => 'Redrawing congressional district boundaries after the census. In most states, the state legislature draws the maps — which is why gerrymandering happens.',
            'cat'   => 'Electoral',
        ],
        'gerrymandering' => [
            'term'  => 'Gerrymandering',
            'short' => 'Drawing district boundaries to give one party an unfair advantage. Named after Gov. Elbridge Gerry in 1812, whose district looked like a salamander. Both parties do it.',
            'cat'   => 'Electoral',
        ],
        'at-large' => [
            'term'  => 'At-Large',
            'short' => 'A representative who serves an entire state instead of a specific district. The 7 least-populated states (Alaska, Wyoming, etc.) have just one at-large representative.',
            'cat'   => 'Electoral',
        ],
        'midterm election' => [
            'term'  => 'Midterm Election',
            'short' => 'Congressional elections held halfway through a president\'s 4-year term. All 435 House seats and about 1/3 of Senate seats are on the ballot. The president\'s party historically loses seats.',
            'cat'   => 'Electoral',
        ],
        'lame duck' => [
            'term'  => 'Lame Duck',
            'short' => 'The period between an election and when new members take office (November to January). Outgoing members still vote but have less political leverage since they\'re leaving.',
            'cat'   => 'Electoral',
        ],
    ];

    return $g;
}

/**
 * Get a definition by slug.
 */
function cg_def(string $slug): ?string {
    $g = congressionalGlossary();
    return $g[strtolower($slug)]['short'] ?? null;
}

/**
 * Wrap a term in a clickable glossary link with hover tooltip.
 * $slug  = glossary key
 * $text  = optional display text (defaults to the glossary term)
 */
function cg(string $slug, ?string $text = null): string {
    $g = congressionalGlossary();
    $key = strtolower($slug);
    if (!isset($g[$key])) return htmlspecialchars($text ?? $slug);
    $entry = $g[$key];
    $display = htmlspecialchars($text ?? $entry['term']);
    $def = htmlspecialchars($entry['short']);
    $href = '/usa/glossary.php?term=' . urlencode($key);
    return "<a class=\"cg-tip\" href=\"$href\" data-def=\"$def\">$display</a>";
}

/**
 * Output the tooltip CSS + JS. Call once per page, in <head> or before </body>.
 */
function cg_js(): string {
    return <<<'HTML'
<style>
.cg-tip {
    border-bottom: 1px dotted rgba(255,255,255,0.4);
    cursor: help;
    position: relative;
    color: inherit;
    text-decoration: none;
}
.cg-tip:hover {
    border-bottom-color: #f0b429;
    color: inherit;
    text-decoration: none;
}
.cg-popup {
    position: fixed;
    max-width: 340px;
    padding: 12px 16px;
    background: #1a2035;
    border: 1px solid #3a4560;
    border-radius: 8px;
    color: #e8eaf0;
    font-size: 13px;
    line-height: 1.5;
    z-index: 9999;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.15s;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
}
.cg-popup.visible { opacity: 1; }
.cg-popup::before {
    content: 'DEFINITION';
    display: block;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: #f0b429;
    margin-bottom: 6px;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var popup = document.createElement('div');
    popup.className = 'cg-popup';
    document.body.appendChild(popup);
    var hideTimer;

    document.addEventListener('mouseover', function(e) {
        var tip = e.target.closest('.cg-tip');
        if (!tip) return;
        clearTimeout(hideTimer);
        // Reset content — rebuild with the DEFINITION label via ::before
        popup.textContent = '';
        popup.appendChild(document.createTextNode(tip.dataset.def));
        popup.classList.add('visible');

        var rect = tip.getBoundingClientRect();
        var top = rect.bottom + 8;
        var left = rect.left;

        // Keep popup on screen
        if (left + 340 > window.innerWidth - 12) left = window.innerWidth - 340 - 12;
        if (left < 12) left = 12;
        if (top + 120 > window.innerHeight) top = rect.top - popup.offsetHeight - 8;

        popup.style.top = top + 'px';
        popup.style.left = left + 'px';
    });

    document.addEventListener('mouseout', function(e) {
        var tip = e.target.closest('.cg-tip');
        if (!tip) return;
        hideTimer = setTimeout(function(){ popup.classList.remove('visible'); }, 100);
    });

    // Click navigates to glossary page (the <a> handles it)
    // Hide popup when clicking elsewhere
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.cg-tip') && !e.target.closest('.cg-popup')) {
            popup.classList.remove('visible');
        }
    });
});
</script>
HTML;
}

/**
 * Return the full glossary grouped by category — useful for a glossary page.
 */
function cg_grouped(): array {
    $groups = [];
    foreach (congressionalGlossary() as $slug => $entry) {
        $groups[$entry['cat']][$slug] = $entry;
    }
    ksort($groups);
    return $groups;
}
