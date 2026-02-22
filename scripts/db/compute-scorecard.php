<?php
/**
 * Compute rep_scorecard — pre-calculated metrics for every federal member.
 * Run after loading/refreshing congressional data.
 *
 * Usage: php scripts/db/compute-scorecard.php [--congress=119]
 */

$congress = 119;
foreach ($argv as $arg) {
    if (preg_match('/^--congress=(\d+)$/', $arg, $m)) $congress = (int)$m[1];
}

$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Computing scorecard for Congress $congress..." . PHP_EOL;

// ── Step 1: Ensure indexes exist ──
echo "  Adding composite indexes (if needed)..." . PHP_EOL;
$indexes = [
    ['member_votes', 'idx_official_vote', '(official_id, vote)'],
    ['member_votes', 'idx_party_vote', '(party, vote_id, vote)'],
    ['member_votes', 'idx_vote_official', '(vote_id, official_id, vote)'],
];
foreach ($indexes as [$table, $name, $cols]) {
    try {
        $pdo->exec("ALTER TABLE $table ADD KEY $name $cols");
        echo "    Added $name" . PHP_EOL;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "    $name already exists" . PHP_EOL;
        } else {
            throw $e;
        }
    }
}

// ── Step 2: Ensure rep_scorecard table exists ──
echo "  Creating rep_scorecard table (if needed)..." . PHP_EOL;
$pdo->exec("CREATE TABLE IF NOT EXISTS rep_scorecard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    congress INT NOT NULL,
    chamber ENUM('House','Senate') NOT NULL,
    total_roll_calls INT DEFAULT 0,
    votes_cast INT DEFAULT 0,
    missed_votes INT DEFAULT 0,
    yea_count INT DEFAULT 0,
    nay_count INT DEFAULT 0,
    present_count INT DEFAULT 0,
    participation_pct DECIMAL(5,1) DEFAULT 0,
    party_loyalty_pct DECIMAL(5,1) DEFAULT 0,
    bipartisan_pct DECIMAL(5,1) DEFAULT 0,
    bills_sponsored INT DEFAULT 0,
    bills_substantive INT DEFAULT 0,
    bills_resolutions INT DEFAULT 0,
    amendments_sponsored INT DEFAULT 0,
    chamber_rank_participation SMALLINT DEFAULT NULL,
    chamber_rank_loyalty SMALLINT DEFAULT NULL,
    chamber_rank_bipartisan SMALLINT DEFAULT NULL,
    chamber_rank_bills SMALLINT DEFAULT NULL,
    state_rank_participation SMALLINT DEFAULT NULL,
    chamber_avg_participation DECIMAL(5,1) DEFAULT 0,
    chamber_avg_loyalty DECIMAL(5,1) DEFAULT 0,
    chamber_avg_bipartisan DECIMAL(5,1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_scorecard (official_id, congress),
    KEY idx_chamber (chamber),
    KEY idx_congress (congress),
    KEY idx_participation (participation_pct),
    KEY idx_bipartisan (bipartisan_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Step 3: Pre-compute party majorities per vote ──
echo "  Computing party majorities..." . PHP_EOL;
$demMaj = $pdo->query("
    SELECT vote_id, IF(SUM(vote='Yea')>SUM(vote='Nay'),'Yea','Nay') as maj
    FROM member_votes WHERE party='D' AND vote IN('Yea','Nay') GROUP BY vote_id
")->fetchAll(PDO::FETCH_KEY_PAIR);

$repMaj = $pdo->query("
    SELECT vote_id, IF(SUM(vote='Yea')>SUM(vote='Nay'),'Yea','Nay') as maj
    FROM member_votes WHERE party='R' AND vote IN('Yea','Nay') GROUP BY vote_id
")->fetchAll(PDO::FETCH_KEY_PAIR);

echo "    D majorities: " . count($demMaj) . " votes, R majorities: " . count($repMaj) . " votes" . PHP_EOL;

// ── Step 4: Get all federal members with votes ──
$members = $pdo->query("
    SELECT eo.official_id, eo.full_name, eo.party, eo.state_code, eo.bioguide_id,
        CASE WHEN eo.title = 'U.S. Senator' THEN 'Senate' ELSE 'House' END as chamber
    FROM elected_officials eo
    WHERE eo.title IN ('U.S. Senator','U.S. Representative')
    AND eo.official_id IN (SELECT DISTINCT official_id FROM member_votes WHERE official_id IS NOT NULL)
")->fetchAll(PDO::FETCH_ASSOC);

echo "  Computing metrics for " . count($members) . " members..." . PHP_EOL;

// Roll call counts per chamber
$chamberCounts = [];
foreach (['House','Senate'] as $ch) {
    $chamberCounts[$ch] = $pdo->query("SELECT COUNT(*) FROM roll_call_votes WHERE chamber = '$ch'")->fetchColumn();
}

// Prepare insert
$insert = $pdo->prepare("INSERT INTO rep_scorecard
    (official_id, congress, chamber, total_roll_calls, votes_cast, missed_votes,
     yea_count, nay_count, present_count, participation_pct,
     party_loyalty_pct, bipartisan_pct,
     bills_sponsored, bills_substantive, bills_resolutions, amendments_sponsored)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
     chamber=VALUES(chamber), total_roll_calls=VALUES(total_roll_calls),
     votes_cast=VALUES(votes_cast), missed_votes=VALUES(missed_votes),
     yea_count=VALUES(yea_count), nay_count=VALUES(nay_count),
     present_count=VALUES(present_count), participation_pct=VALUES(participation_pct),
     party_loyalty_pct=VALUES(party_loyalty_pct), bipartisan_pct=VALUES(bipartisan_pct),
     bills_sponsored=VALUES(bills_sponsored), bills_substantive=VALUES(bills_substantive),
     bills_resolutions=VALUES(bills_resolutions), amendments_sponsored=VALUES(amendments_sponsored)");

$count = 0;
foreach ($members as $m) {
    $partyCode = substr($m['party'], 0, 1);
    $oppCode = $partyCode === 'D' ? 'R' : ($partyCode === 'R' ? 'D' : 'R');
    $ownMaj = ($partyCode === 'D') ? $demMaj : $repMaj;
    $oppMajArr = ($partyCode === 'D') ? $repMaj : $demMaj;

    // Vote counts
    $v = $pdo->prepare("SELECT COUNT(*) as total,
        SUM(vote='Yea') as yea, SUM(vote='Nay') as nay,
        SUM(vote='Present') as present, SUM(vote='Not Voting') as nv
        FROM member_votes WHERE official_id = ?");
    $v->execute([$m['official_id']]);
    $votes = $v->fetch(PDO::FETCH_ASSOC);

    $votesCast = $votes['yea'] + $votes['nay'] + $votes['present'];
    $missed = $votes['nv'];
    $participation = $votes['total'] > 0 ? round($votesCast / $votes['total'] * 100, 1) : 0;

    // Loyalty & bipartisanship from pre-computed majorities
    $memberVotes = $pdo->prepare("SELECT vote_id, vote FROM member_votes WHERE official_id = ? AND vote IN ('Yea','Nay')");
    $memberVotes->execute([$m['official_id']]);
    $loyaltyTotal = 0; $loyaltyWith = 0;
    $bipartTotal = 0; $bipartWith = 0;
    while ($row = $memberVotes->fetch(PDO::FETCH_ASSOC)) {
        $vid = $row['vote_id'];
        if (isset($ownMaj[$vid])) {
            $loyaltyTotal++;
            if ($row['vote'] === $ownMaj[$vid]) $loyaltyWith++;
        }
        if (isset($oppMajArr[$vid])) {
            $bipartTotal++;
            if ($row['vote'] === $oppMajArr[$vid]) $bipartWith++;
        }
    }
    $loyaltyPct = $loyaltyTotal > 0 ? round($loyaltyWith / $loyaltyTotal * 100, 1) : 0;
    $bipartPct = $bipartTotal > 0 ? round($bipartWith / $bipartTotal * 100, 1) : 0;

    // Bills
    $b = $pdo->prepare("SELECT COUNT(*) as cnt,
        COALESCE(SUM(bill_type IN ('hr','s')),0) as sub,
        COALESCE(SUM(bill_type NOT IN ('hr','s')),0) as res
        FROM tracked_bills WHERE sponsor_bioguide = ?");
    $b->execute([$m['bioguide_id']]);
    $bills = $b->fetch(PDO::FETCH_ASSOC);

    // Amendments
    $a = $pdo->prepare("SELECT COUNT(*) FROM amendments WHERE sponsor_bioguide = ?");
    $a->execute([$m['bioguide_id']]);
    $amendments = $a->fetchColumn();

    $insert->execute([
        $m['official_id'], $congress, $m['chamber'],
        $chamberCounts[$m['chamber']], $votesCast, $missed,
        $votes['yea'], $votes['nay'], $votes['present'], $participation,
        $loyaltyPct, $bipartPct,
        $bills['cnt'], $bills['sub'], $bills['res'], $amendments
    ]);

    $count++;
    if ($count % 50 === 0) echo "    $count / " . count($members) . PHP_EOL;
}
echo "    $count / " . count($members) . " — done" . PHP_EOL;

// ── Step 5: Compute rankings ──
echo "  Computing rankings..." . PHP_EOL;
foreach (['House','Senate'] as $ch) {
    // Chamber ranks
    $pdo->exec("SET @r=0; UPDATE rep_scorecard SET chamber_rank_participation = (@r:=@r+1)
        WHERE congress=$congress AND chamber='$ch' ORDER BY participation_pct DESC");
    $pdo->exec("SET @r=0; UPDATE rep_scorecard SET chamber_rank_loyalty = (@r:=@r+1)
        WHERE congress=$congress AND chamber='$ch' ORDER BY party_loyalty_pct DESC");
    $pdo->exec("SET @r=0; UPDATE rep_scorecard SET chamber_rank_bipartisan = (@r:=@r+1)
        WHERE congress=$congress AND chamber='$ch' ORDER BY bipartisan_pct DESC");
    $pdo->exec("SET @r=0; UPDATE rep_scorecard SET chamber_rank_bills = (@r:=@r+1)
        WHERE congress=$congress AND chamber='$ch' ORDER BY bills_sponsored DESC");

    // Chamber averages
    $avg = $pdo->query("SELECT
        ROUND(AVG(participation_pct),1) as p,
        ROUND(AVG(party_loyalty_pct),1) as l,
        ROUND(AVG(bipartisan_pct),1) as b
        FROM rep_scorecard WHERE congress=$congress AND chamber='$ch'")->fetch(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE rep_scorecard SET
        chamber_avg_participation={$avg['p']},
        chamber_avg_loyalty={$avg['l']},
        chamber_avg_bipartisan={$avg['b']}
        WHERE congress=$congress AND chamber='$ch'");
    echo "    $ch: avg participation={$avg['p']}%, loyalty={$avg['l']}%, bipartisan={$avg['b']}%" . PHP_EOL;
}

// State ranks (within state, across chambers)
$states = $pdo->query("SELECT DISTINCT eo.state_code
    FROM rep_scorecard rs JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = $congress")->fetchAll(PDO::FETCH_COLUMN);
foreach ($states as $st) {
    $pdo->exec("SET @r=0; UPDATE rep_scorecard rs
        JOIN elected_officials eo ON rs.official_id = eo.official_id
        SET rs.state_rank_participation = (@r:=@r+1)
        WHERE rs.congress=$congress AND eo.state_code='$st'
        ORDER BY rs.participation_pct DESC");
}
echo "    State ranks computed for " . count($states) . " states" . PHP_EOL;

// ── Summary ──
echo PHP_EOL . "═══ SCORECARD COMPLETE ═══" . PHP_EOL;
$total = $pdo->query("SELECT COUNT(*) FROM rep_scorecard WHERE congress=$congress")->fetchColumn();
echo "  $total scorecards for Congress $congress" . PHP_EOL;

// Sample: CT delegation
echo PHP_EOL . "  CT delegation:" . PHP_EOL;
$r = $pdo->query("SELECT eo.full_name, rs.chamber, rs.participation_pct, rs.party_loyalty_pct,
    rs.bipartisan_pct, rs.bills_sponsored, rs.missed_votes,
    rs.chamber_rank_participation, rs.chamber_rank_bipartisan,
    rs.chamber_avg_participation
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress=$congress AND eo.state_code = 'CT'
    ORDER BY rs.chamber, eo.full_name");
echo "  " . str_pad("Name", 25) . str_pad("Chamber", 9) . str_pad("Part%", 8) . str_pad("Rank", 6)
    . str_pad("Loyal%", 8) . str_pad("Bipart%", 9) . str_pad("Bills", 7) . "Missed" . PHP_EOL;
echo "  " . str_repeat("─", 80) . PHP_EOL;
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    echo "  " . str_pad($row['full_name'], 25)
        . str_pad($row['chamber'], 9)
        . str_pad($row['participation_pct'] . '%', 8)
        . str_pad('#' . $row['chamber_rank_participation'], 6)
        . str_pad($row['party_loyalty_pct'] . '%', 8)
        . str_pad($row['bipartisan_pct'] . '%', 9)
        . str_pad($row['bills_sponsored'], 7)
        . $row['missed_votes'] . PHP_EOL;
}
