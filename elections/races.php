<?php
/**
 * 2026 Competitive Races — Follow the Money
 * ==========================================
 * Public race dashboard showing competitive 2026 federal races
 * with FEC campaign finance data and top donor breakdowns.
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = '2026 Races — The People\'s Branch';
$ogTitle = '2026 Competitive Races — Follow the Money';
$ogDescription = 'Track competitive federal races, candidate fundraising, and top donors. See who funds the people who want power.';

// --- Election DB connection ---
$pdoE = new PDO('mysql:host='.$c['host'].';dbname=sandge5_election', $c['username'], $c['password']);
$pdoE->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- Helpers ---
function getRatingColor($rating) {
    return match($rating) {
        'Toss-Up' => '#9c27b0',
        'Lean D' => '#42a5f5',
        'Lean R' => '#ef5350',
        'Likely D' => '#1565c0',
        'Likely R' => '#c62828',
        default => '#555',
    };
}

function formatMoney($amount) {
    if ($amount >= 1000000) return '$' . number_format($amount / 1000000, 1) . 'M';
    if ($amount >= 1000) return '$' . number_format($amount / 1000, 0) . 'K';
    return '$' . number_format($amount, 0);
}

// --- Data queries ---

// 1. Active races
$races = $pdoE->query("
    SELECT * FROM fec_races
    WHERE is_active = 1
    ORDER BY state, district
")->fetchAll(PDO::FETCH_ASSOC);

$raceCount = count($races);
$raceIds = array_column($races, 'race_id');

// 2. Candidates for those races
$candidatesByRace = [];
$allCandidateIds = [];
if ($raceIds) {
    $placeholders = implode(',', array_fill(0, count($raceIds), '?'));
    $stmt = $pdoE->prepare("
        SELECT * FROM fec_candidates
        WHERE race_id IN ($placeholders)
        ORDER BY total_receipts DESC
    ");
    $stmt->execute($raceIds);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($candidates as $cand) {
        $candidatesByRace[$cand['race_id']][] = $cand;
        $allCandidateIds[] = $cand['fec_candidate_id'];
    }
}

// 3. Top contributors for those candidates
$contributorsByCandidate = [];
if ($allCandidateIds) {
    $placeholders = implode(',', array_fill(0, count($allCandidateIds), '?'));
    $stmt = $pdoE->prepare("
        SELECT * FROM fec_top_contributors
        WHERE fec_candidate_id IN ($placeholders)
        ORDER BY total_amount DESC
    ");
    $stmt->execute($allCandidateIds);
    $contributors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($contributors as $contrib) {
        $contributorsByCandidate[$contrib['fec_candidate_id']][] = $contrib;
    }
}

$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
$shareText = "$raceCount competitive 2026 races tracked with FEC campaign finance data. See who funds the people who want power over you.";

$pageStyles = <<<'CSS'
.races-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

/* View links */
.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.4rem 1rem; border: 1px solid #333; border-radius: 6px;
    color: #888; text-decoration: none; font-size: 0.9rem; transition: all 0.2s;
}
.view-links a:hover { color: #e0e0e0; border-color: #555; }
.view-links a.active { color: #d4af37; border-color: #d4af37; background: rgba(212,175,55,0.1); }

/* Hero */
.races-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #0a0a0f 100%);
    padding: 2.5rem 2rem; text-align: center; border-radius: 8px;
    margin-bottom: 2rem; border-bottom: 3px solid #d4af37;
}
.races-hero h1 {
    font-size: 2.2em; color: #d4af37; margin-bottom: 0.5rem;
    text-shadow: 0 2px 10px rgba(212,175,55,0.3);
}
.races-hero .race-count {
    font-size: 3rem; font-weight: 900; color: #d4af37;
    font-family: 'Courier New', monospace; line-height: 1;
    margin-bottom: 0.25rem;
}
.races-hero .race-count-label {
    font-size: 0.85rem; color: #888; text-transform: uppercase;
    letter-spacing: 1px; margin-bottom: 1rem;
}
.races-hero .tagline {
    color: #ccc; font-size: 1.05rem; max-width: 550px;
    margin: 0 auto; line-height: 1.6;
}

/* Race cards */
.race-card {
    background: #1a1a2e; border: 1px solid #333; border-radius: 8px;
    margin-bottom: 1rem; overflow: hidden; transition: border-color 0.3s;
}
.race-card:hover { border-color: #555; }

.race-header {
    display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
    padding: 1rem 1.25rem; cursor: pointer; user-select: none;
    transition: background 0.2s;
}
.race-header:hover { background: rgba(255,255,255,0.03); }

.race-label {
    font-size: 1.1rem; font-weight: 700; color: #e0e0e0;
    font-family: 'Courier New', monospace;
}
.race-candidate-count {
    font-size: 0.8rem; color: #888;
}
.rating-badge {
    padding: 3px 10px; border-radius: 4px; font-weight: 700;
    font-size: 0.8rem; white-space: nowrap; margin-left: auto;
}
.race-expand-icon {
    color: #555; font-size: 0.9rem; transition: transform 0.3s;
}
.race-card.open .race-expand-icon { transform: rotate(180deg); }

.race-body {
    display: none; padding: 0 1.25rem 1.25rem;
    border-top: 1px solid #2a2a3e;
}
.race-card.open .race-body { display: block; }

/* Candidate rows */
.candidate-row {
    display: flex; gap: 1.25rem; align-items: flex-start;
    padding: 1rem 0; border-bottom: 1px solid #252540;
}
.candidate-row:last-of-type { border-bottom: none; }

.candidate-info { min-width: 180px; flex-shrink: 0; }
.candidate-name { font-size: 1rem; font-weight: 600; color: #e0e0e0; margin-bottom: 0.3rem; }
.party-badge {
    display: inline-block; padding: 1px 8px; border-radius: 3px;
    font-size: 0.7rem; font-weight: 700; color: #fff; margin-right: 0.4rem;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.party-badge.dem { background: #1565c0; }
.party-badge.rep { background: #c62828; }
.party-badge.other { background: #555; }
.incumbent-label {
    font-size: 0.7rem; color: #aaa; padding: 1px 6px;
    border: 1px solid #444; border-radius: 3px; text-transform: uppercase;
    letter-spacing: 0.5px;
}

.candidate-finance { flex: 1; min-width: 0; }

.bar-row {
    display: flex; align-items: center; gap: 0.5rem;
    margin-bottom: 0.4rem;
}
.bar-label {
    width: 50px; font-size: 0.75rem; color: #888; text-align: right;
    flex-shrink: 0;
}
.bar-track {
    flex: 1; height: 18px; background: #0a0a0f; border-radius: 3px;
    overflow: hidden; position: relative;
}
.bar-fill {
    height: 100%; border-radius: 3px; transition: width 0.5s ease;
    display: flex; align-items: center; justify-content: flex-end;
    padding-right: 6px; min-width: fit-content;
}
.bar-fill span {
    font-size: 0.7rem; font-weight: 600; color: #fff;
    white-space: nowrap; text-shadow: 0 1px 2px rgba(0,0,0,0.5);
}
.bar-fill.raised { background: #4caf50; }
.bar-fill.spent { background: #ef5350; }
.bar-fill.cash { background: #42a5f5; }

/* Donors section */
.donors-toggle {
    background: none; border: 1px solid #444; color: #888;
    padding: 0.3rem 0.75rem; border-radius: 4px; font-size: 0.75rem;
    cursor: pointer; margin-top: 0.5rem; transition: all 0.2s;
}
.donors-toggle:hover { color: #e0e0e0; border-color: #666; }

.donors-list {
    display: none; margin-top: 0.5rem; padding: 0.75rem;
    background: #0a0a0f; border-radius: 6px; border: 1px solid #252540;
}
.donors-list.open { display: block; }

.donor-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.35rem 0; border-bottom: 1px solid #1a1a2e;
    font-size: 0.8rem;
}
.donor-row:last-child { border-bottom: none; }
.donor-name { color: #ccc; }
.donor-type {
    font-size: 0.65rem; color: #888; padding: 1px 5px;
    border: 1px solid #333; border-radius: 3px; margin-left: 0.4rem;
    text-transform: uppercase;
}
.donor-amount { color: #d4af37; font-weight: 600; font-family: 'Courier New', monospace; }
.no-donors { color: #555; font-size: 0.8rem; font-style: italic; padding: 0.5rem 0; }

/* Share section */
.races-cta {
    text-align: center; padding: 2rem; margin-top: 2rem;
    background: linear-gradient(135deg, #1a1a2e 0%, #252540 100%);
    border-radius: 8px; border: 1px solid #333;
}
.races-cta h3 { color: #d4af37; margin-bottom: 1rem; }
.races-cta p { color: #aaa; margin-bottom: 1.5rem; }

.share-row { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; }
.share-btn {
    padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.85rem;
    text-decoration: none; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer;
}
.share-btn.x { background: #000; color: #fff; border: 1px solid #333; }
.share-btn.bsky { background: #0085ff; color: #fff; }
.share-btn.fb { background: #1877f2; color: #fff; }
.share-btn.email { background: #333; color: #fff; }
.share-btn:hover { transform: translateY(-1px); opacity: 0.9; }

/* No races fallback */
.no-races {
    text-align: center; padding: 3rem 1rem; color: #888;
    font-size: 1.1rem;
}

/* Responsive */
@media (max-width: 600px) {
    .races-hero h1 { font-size: 1.6em; }
    .races-hero .race-count { font-size: 2.2rem; }
    .candidate-row { flex-direction: column; gap: 0.75rem; }
    .candidate-info { min-width: unset; width: 100%; }
    .bar-label { width: 42px; font-size: 0.7rem; }
    .race-header { gap: 0.5rem; }
    .rating-badge { margin-left: 0; }
}
CSS;

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
?>

<main class="races-container">

    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php">Threats</a>
        <a href="/elections/races.php" class="active">Races</a>
    </div>

    <!-- Hero -->
    <section class="races-hero">
        <h1>Follow the Money</h1>
        <div class="race-count"><?= $raceCount ?></div>
        <div class="race-count-label">Competitive Races Tracked</div>
        <p class="tagline">Who's funding the people who want power over you?</p>
    </section>

    <!-- Race Cards -->
    <?php if ($raceCount === 0): ?>
        <div class="no-races">No active races tracked yet. Check back soon.</div>
    <?php else: ?>
        <?php foreach ($races as $race):
            $raceId = $race['race_id'];
            $raceCandidates = $candidatesByRace[$raceId] ?? [];
            $candidateCount = count($raceCandidates);
            $rating = $race['rating'] ?? '';
            $ratingColor = getRatingColor($rating);

            // Build race label: e.g. "NY-19 House" or "GA Senate"
            $district = $race['district'] ?? '';
            $chamber = $race['office'] === 'S' ? 'Senate' : 'House';
            if ($chamber === 'Senate' || empty($district)) {
                $raceLabel = $race['state'] . ' ' . $chamber;
            } else {
                $raceLabel = $race['state'] . '-' . $district . ' ' . $chamber;
            }

            // Find max receipts in this race for bar scaling
            $maxReceipts = 0;
            foreach ($raceCandidates as $cand) {
                $receipts = (float)($cand['total_receipts'] ?? 0);
                if ($receipts > $maxReceipts) $maxReceipts = $receipts;
            }
        ?>
        <div class="race-card">
            <div class="race-header" onclick="this.parentElement.classList.toggle('open')">
                <span class="race-label"><?= htmlspecialchars($raceLabel) ?></span>
                <span class="race-candidate-count"><?= $candidateCount ?> candidate<?= $candidateCount !== 1 ? 's' : '' ?></span>
                <?php if ($rating): ?>
                <span class="rating-badge" style="background:<?= $ratingColor ?>;color:#fff"><?= htmlspecialchars($rating) ?></span>
                <?php endif; ?>
                <span class="race-expand-icon">&#9660;</span>
            </div>

            <div class="race-body">
                <?php if (empty($raceCandidates)): ?>
                    <p class="no-donors">No candidate data available yet.</p>
                <?php else: ?>
                    <?php foreach ($raceCandidates as $cand):
                        $candId = $cand['fec_candidate_id'];
                        $partyRaw = strtoupper($cand['party'] ?? '');
                        // FEC returns full names like "REPUBLICAN PARTY" or abbreviations like "REP"
                        $partyClass = (str_contains($partyRaw, 'DEM') ? 'dem' : (str_contains($partyRaw, 'REP') ? 'rep' : 'other'));
                        $party = $partyClass === 'dem' ? 'DEM' : ($partyClass === 'rep' ? 'REP' : ($partyRaw ?: '?'));
                        $isIncumbent = ($cand['incumbent_challenge'] ?? '') === 'I';
                        $totalReceipts = (float)($cand['total_receipts'] ?? 0);
                        $totalDisbursements = (float)($cand['total_disbursements'] ?? 0);
                        $cashOnHand = (float)($cand['cash_on_hand'] ?? 0);

                        // Bar widths as percentage of max in this race
                        $raisedPct = $maxReceipts > 0 ? ($totalReceipts / $maxReceipts * 100) : 0;
                        $spentPct = $maxReceipts > 0 ? ($totalDisbursements / $maxReceipts * 100) : 0;
                        $cashPct = $maxReceipts > 0 ? ($cashOnHand / $maxReceipts * 100) : 0;

                        // Clamp to 100% max
                        $raisedPct = min($raisedPct, 100);
                        $spentPct = min($spentPct, 100);
                        $cashPct = min($cashPct, 100);

                        // Ensure bars are at least wide enough to show label
                        $raisedPct = max($raisedPct, 8);
                        $spentPct = $totalDisbursements > 0 ? max($spentPct, 8) : 0;
                        $cashPct = $cashOnHand > 0 ? max($cashPct, 8) : 0;

                        $donors = $contributorsByCandidate[$candId] ?? [];
                    ?>
                    <div class="candidate-row">
                        <div class="candidate-info">
                            <div class="candidate-name"><?= htmlspecialchars($cand['name'] ?? 'Unknown') ?></div>
                            <div>
                                <span class="party-badge <?= $partyClass ?>"><?= htmlspecialchars($party ?: '?') ?></span>
                                <?php if ($isIncumbent): ?>
                                <span class="incumbent-label">Incumbent</span>
                                <?php else: ?>
                                <span class="incumbent-label">Challenger</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="candidate-finance">
                            <div class="bar-row">
                                <span class="bar-label">Raised</span>
                                <div class="bar-track">
                                    <div class="bar-fill raised" style="width:<?= number_format($raisedPct, 1) ?>%">
                                        <span><?= formatMoney($totalReceipts) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="bar-row">
                                <span class="bar-label">Spent</span>
                                <div class="bar-track">
                                    <?php if ($totalDisbursements > 0): ?>
                                    <div class="bar-fill spent" style="width:<?= number_format($spentPct, 1) ?>%">
                                        <span><?= formatMoney($totalDisbursements) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="bar-row">
                                <span class="bar-label">Cash</span>
                                <div class="bar-track">
                                    <?php if ($cashOnHand > 0): ?>
                                    <div class="bar-fill cash" style="width:<?= number_format($cashPct, 1) ?>%">
                                        <span><?= formatMoney($cashOnHand) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($donors)): ?>
                            <button type="button" class="donors-toggle" onclick="toggleDonors(this)">Show Top Donors</button>
                            <div class="donors-list">
                                <?php foreach ($donors as $donor): ?>
                                <div class="donor-row">
                                    <div>
                                        <span class="donor-name"><?= htmlspecialchars($donor['contributor_name'] ?? 'Unknown') ?></span>
                                        <?php
                                            $donorType = strtolower($donor['contributor_type'] ?? '');
                                            $donorTypeLabel = match(true) {
                                                str_contains($donorType, 'pac') => 'PAC',
                                                str_contains($donorType, 'individual') => 'Individual',
                                                str_contains($donorType, 'org') => 'Org',
                                                $donorType !== '' => ucfirst($donorType),
                                                default => '',
                                            };
                                        ?>
                                        <?php if ($donorTypeLabel): ?>
                                        <span class="donor-type"><?= htmlspecialchars($donorTypeLabel) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="donor-amount"><?= formatMoney((float)($donor['total_amount'] ?? 0)) ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Share Section -->
    <div class="races-cta">
        <h3>Share This Dashboard</h3>
        <p><?= $raceCount ?> competitive races tracked. Campaign finance data from FEC filings. See who funds the people who want power.</p>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText) ?>&url=<?= urlencode("$siteUrl/elections/races.php") ?>" target="_blank" class="share-btn x">Share on X</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText . " $siteUrl/elections/races.php") ?>" target="_blank" class="share-btn bsky">Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode("$siteUrl/elections/races.php") ?>" target="_blank" class="share-btn fb">Facebook</a>
            <a href="mailto:?subject=<?= urlencode('2026 Competitive Races — Follow the Money') ?>&body=<?= urlencode($shareText . "\n\n" . "$siteUrl/elections/races.php") ?>" class="share-btn email">Email</a>
        </div>
    </div>

</main>

<script>
function toggleDonors(btn) {
    var list = btn.nextElementSibling;
    if (list && list.classList.contains('donors-list')) {
        list.classList.toggle('open');
        btn.textContent = list.classList.contains('open') ? 'Hide Top Donors' : 'Show Top Donors';
    }
}
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
