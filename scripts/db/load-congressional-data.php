<?php
/**
 * Load all congressional data for a given Congress
 * =================================================
 *
 * Sources:
 *   - Bills:          Congress.gov API v3
 *   - House votes:    clerk.house.gov XML
 *   - Senate votes:   senate.gov XML
 *   - Amendments:     Congress.gov API v3
 *   - Reports:        Congress.gov API v3
 *   - Meetings:       Congress.gov API v3
 *   - Hearings:       Congress.gov API v3
 *   - Nominations:    Congress.gov API v3
 *   - Communications: Congress.gov API v3
 *
 * Usage:
 *   php scripts/db/load-congressional-data.php [--congress=119] [--step=all] [--dry-run]
 *
 * Steps: bills, house-votes, senate-votes, extras, all
 *
 * Tables: tracked_bills, roll_call_votes, member_votes, amendments,
 *         committee_reports, committee_meetings, hearings, nominations,
 *         congressional_communications
 *
 * Requirements:
 *   - config.php must have apis.congress_gov.key
 *   - Tables created by scripts/db/create-congressional-tables.sql
 */

set_time_limit(0);
$opts = getopt('', ['congress:', 'step:', 'dry-run']);
$congress = (int)($opts['congress'] ?? 119);
$step = $opts['step'] ?? 'all';
$dryRun = isset($opts['dry-run']);

$c = require dirname(__DIR__, 2) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$apiKey = $c['apis']['congress_gov']['key'] ?? null;
if (!$apiKey) die("ERROR: No Congress.gov API key in config.php\n");

$ctx = stream_context_create(['http' => [
    'timeout' => 30,
    'header' => "User-Agent: TPB-Civic-Platform\r\nAccept: application/json\r\n"
]]);
$xmlCtx = stream_context_create(['http' => [
    'timeout' => 30,
    'header' => "User-Agent: TPB-Civic-Platform\r\n"
]]);

// Build official_id lookup from bioguide
$officialLookup = [];
$r = $pdo->query("SELECT official_id, bioguide_id FROM elected_officials WHERE bioguide_id IS NOT NULL AND bioguide_id != ''");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $officialLookup[$row['bioguide_id']] = $row['official_id'];

function apiGet($url, $apiKey, $ctx) {
    $sep = strpos($url, '?') !== false ? '&' : '?';
    $full = $url . $sep . "api_key=$apiKey&format=json";
    $resp = @file_get_contents($full, false, $ctx);
    if ($resp === false) return null;
    return json_decode($resp, true);
}

function apiGetAll($baseUrl, $apiKey, $ctx, $dataKey, $limit = 250) {
    $all = [];
    $offset = 0;
    while (true) {
        $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
        $url = $baseUrl . $sep . "limit=$limit&offset=$offset";
        $data = apiGet($url, $apiKey, $ctx);
        if (!$data || !isset($data[$dataKey]) || empty($data[$dataKey])) break;
        $all = array_merge($all, $data[$dataKey]);
        $total = $data['pagination']['count'] ?? 0;
        $offset += $limit;
        echo "    Fetched $offset / $total\r";
        if ($offset >= $total) break;
        usleep(150000);
    }
    echo PHP_EOL;
    return $all;
}

/**
 * Update pre-computed party tallies on roll_call_votes for a single vote.
 * Called after member_votes are inserted/re-imported for a vote.
 */
function updatePartyTallies(PDO $pdo, int $voteId): void {
    $pdo->prepare("
        UPDATE roll_call_votes rv SET
            r_yea = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Yea' AND eo.party LIKE 'R%'),
            r_nay = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Nay' AND eo.party LIKE 'R%'),
            d_yea = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Yea' AND eo.party LIKE 'D%'),
            d_nay = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Nay' AND eo.party LIKE 'D%'),
            i_yea = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Yea' AND eo.party NOT LIKE 'R%' AND eo.party NOT LIKE 'D%'),
            i_nay = (SELECT COUNT(*) FROM member_votes mv JOIN elected_officials eo ON mv.official_id = eo.official_id WHERE mv.vote_id = ? AND mv.vote = 'Nay' AND eo.party NOT LIKE 'R%' AND eo.party NOT LIKE 'D%')
        WHERE rv.vote_id = ?
    ")->execute([$voteId, $voteId, $voteId, $voteId, $voteId, $voteId, $voteId]);
}

echo "╔══════════════════════════════════════════════════════════╗" . PHP_EOL;
echo "║   CONGRESSIONAL DATA LOADER — Congress $congress              ║" . PHP_EOL;
echo "╚══════════════════════════════════════════════════════════╝" . PHP_EOL;
if ($dryRun) echo "  ** DRY RUN — no database changes **" . PHP_EOL;

// ============================================
// STEP 1: BILLS
// ============================================
if ($step === 'all' || $step === 'bills') {
    echo PHP_EOL . "=== STEP 1: Import Bills ===" . PHP_EOL;
    $types = ['hr', 's', 'hjres', 'sjres', 'hconres', 'sconres', 'hres', 'sres'];
    $totalBills = 0;

    if (!$dryRun) {
        $insert = $pdo->prepare("INSERT INTO tracked_bills
            (congress, bill_type, bill_number, title, last_action_date, last_action_text,
             origin_chamber, congress_url)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title), last_action_date=VALUES(last_action_date),
            last_action_text=VALUES(last_action_text), origin_chamber=VALUES(origin_chamber)");
    }

    foreach ($types as $type) {
        echo "  Fetching $type bills..." . PHP_EOL;
        $bills = apiGetAll("https://api.congress.gov/v3/bill/$congress/$type", $apiKey, $ctx, 'bills');
        echo "    Got " . count($bills) . " $type bills" . PHP_EOL;

        if (!$dryRun) {
            foreach ($bills as $b) {
                $num = (int)($b['number'] ?? 0);
                $title = $b['title'] ?? '';
                $actionDate = $b['latestAction']['actionDate'] ?? null;
                $actionText = $b['latestAction']['text'] ?? null;
                $origin = $b['originChamber'] ?? null;
                $url = "https://www.congress.gov/bill/{$congress}th-congress/" .
                    ($origin === 'House' ? 'house' : 'senate') . "-bill/$num";
                $insert->execute([$congress, $type, $num, $title, $actionDate, $actionText, $origin, $url]);
            }
        }
        $totalBills += count($bills);
    }
    echo "  Total bills: $totalBills" . PHP_EOL;

    // Enrich with sponsor data (batch by fetching bill details)
    if (!$dryRun) {
        echo "  Enriching sponsor data (sampling featured/recent)..." . PHP_EOL;
        // Get bills missing sponsor info — do in batches to avoid rate limits
        $missing = $pdo->query("SELECT bill_id, congress, bill_type, bill_number FROM tracked_bills
            WHERE congress = $congress AND sponsor_bioguide IS NULL
            ORDER BY last_action_date DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $enriched = 0;
        $updateSponsor = $pdo->prepare("UPDATE tracked_bills SET
            sponsor_bioguide=?, sponsor_name=?, sponsor_party=?, sponsor_state=?,
            introduced_date=?, short_title=?
            WHERE bill_id=?");

        foreach ($missing as $m) {
            $detail = apiGet("https://api.congress.gov/v3/bill/{$m['congress']}/{$m['bill_type']}/{$m['bill_number']}", $apiKey, $ctx);
            if ($detail && isset($detail['bill'])) {
                $b = $detail['bill'];
                $sponsor = $b['sponsors'][0] ?? null;
                $updateSponsor->execute([
                    $sponsor['bioguideId'] ?? null,
                    $sponsor['fullName'] ?? $sponsor['firstName'] ?? null,
                    $sponsor['party'] ?? null,
                    $sponsor['state'] ?? null,
                    $b['introducedDate'] ?? null,
                    $b['shortTitle'] ?? null,
                    $m['bill_id']
                ]);
                $enriched++;
                if ($enriched % 50 == 0) echo "    Enriched $enriched / " . count($missing) . "\r";
            }
            usleep(150000);
        }
        echo "    Enriched $enriched bills with sponsor data" . PHP_EOL;
    }
}

// ============================================
// STEP 2: HOUSE VOTES
// ============================================
if ($step === 'all' || $step === 'house-votes') {
    echo PHP_EOL . "=== STEP 2: Import House Roll Call Votes ===" . PHP_EOL;

    // Get list of all house votes
    $votes = apiGetAll("https://api.congress.gov/v3/house-vote/$congress", $apiKey, $ctx, 'houseRollCallVotes');
    echo "  House roll calls: " . count($votes) . PHP_EOL;

    if (!$dryRun) {
        $insertVote = $pdo->prepare("INSERT INTO roll_call_votes
            (congress, session_number, chamber, roll_call_number, bill_type, bill_number,
             vote_question, vote_result, vote_date, yea_total, nay_total, present_total,
             not_voting_total, source_url)
            VALUES (?,?,'House',?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE vote_result=VALUES(vote_result), yea_total=VALUES(yea_total),
            nay_total=VALUES(nay_total), present_total=VALUES(present_total),
            not_voting_total=VALUES(not_voting_total)");

        $insertMember = $pdo->prepare("INSERT INTO member_votes
            (vote_id, bioguide_id, official_id, member_name, party, state, district, vote)
            VALUES (?,?,?,?,?,?,?,?)");

        $processed = 0;
        $memberTotal = 0;

        foreach ($votes as $v) {
            $session = $v['sessionNumber'] ?? 1;
            $rollCall = $v['rollCallNumber'] ?? 0;
            $billType = strtolower($v['legislationType'] ?? '');
            $billNum = (int)($v['legislationNumber'] ?? 0);
            $xmlUrl = $v['sourceDataURL'] ?? '';

            // Download XML for individual votes
            $xml = @file_get_contents($xmlUrl, false, $xmlCtx);
            if (!$xml) {
                echo "    SKIP roll $rollCall — XML download failed" . PHP_EOL;
                continue;
            }

            $doc = new SimpleXMLElement($xml);
            $meta = $doc->{'vote-metadata'};
            $question = (string)($meta->{'vote-question'} ?? '');
            $result = (string)($meta->{'vote-result'} ?? $v['result'] ?? '');
            $dateStr = (string)($meta->{'action-date'} ?? '');
            $timeStr = (string)($meta->{'action-time'} ?? '');

            // Parse date
            $voteDate = null;
            if ($dateStr) {
                $d = date_create_from_format('d-M-Y', $dateStr);
                if ($d) $voteDate = $d->format('Y-m-d');
            }
            if (!$voteDate && isset($v['startDate'])) {
                $voteDate = substr($v['startDate'], 0, 10);
            }

            // Totals
            $yea = 0; $nay = 0; $present = 0; $notVoting = 0;
            if (isset($meta->{'vote-totals'}->{'totals-by-vote'})) {
                foreach ($meta->{'vote-totals'}->{'totals-by-vote'} as $t) {
                    $label = strtolower((string)$t->{'vote-type'} ?? '');
                    $count = (int)($t->{'vote-count'} ?? 0);
                    if (strpos($label, 'yea') !== false || strpos($label, 'aye') !== false) $yea = $count;
                    elseif (strpos($label, 'nay') !== false || strpos($label, 'no') !== false) $nay = $count;
                    elseif (strpos($label, 'present') !== false) $present = $count;
                    elseif (strpos($label, 'not voting') !== false) $notVoting = $count;
                }
            }

            $insertVote->execute([
                $congress, $session, $rollCall,
                $billType ?: null, $billNum ?: null,
                $question, $result, $voteDate,
                $yea, $nay, $present, $notVoting, $xmlUrl
            ]);
            $voteId = $pdo->lastInsertId();
            if (!$voteId) {
                // ON DUPLICATE — get existing id
                $voteId = $pdo->query("SELECT vote_id FROM roll_call_votes
                    WHERE congress=$congress AND chamber='House' AND session_number=$session
                    AND roll_call_number=$rollCall")->fetchColumn();
                // Clear old member votes for re-import
                $pdo->exec("DELETE FROM member_votes WHERE vote_id = $voteId");
            }

            // Member votes
            $memberCount = 0;
            if (isset($doc->{'vote-data'}->{'recorded-vote'})) {
                foreach ($doc->{'vote-data'}->{'recorded-vote'} as $rv) {
                    $leg = $rv->legislator;
                    $bioguide = (string)($leg['name-id'] ?? '');
                    $name = (string)($leg['unaccented-name'] ?? $leg['sorted-name'] ?? $leg);
                    $party = (string)($leg['party'] ?? '');
                    $state = (string)($leg['state'] ?? '');
                    $dist = (string)($leg['district'] ?? '');
                    $memberVote = (string)($rv->vote ?? '');

                    // Normalize vote
                    $normalVote = 'Not Voting';
                    $mv = strtolower($memberVote);
                    if ($mv === 'yea' || $mv === 'aye') $normalVote = 'Yea';
                    elseif ($mv === 'nay' || $mv === 'no') $normalVote = 'Nay';
                    elseif ($mv === 'present') $normalVote = 'Present';

                    $officialId = $officialLookup[$bioguide] ?? null;
                    $insertMember->execute([
                        $voteId, $bioguide ?: null, $officialId,
                        $name, $party ?: null, $state ?: null,
                        $dist !== '' ? (int)$dist : null, $normalVote
                    ]);
                    $memberCount++;
                }
            }

            // Compute party breakdown for this vote
            updatePartyTallies($pdo, $voteId);

            $memberTotal += $memberCount;
            $processed++;
            if ($processed % 10 == 0) echo "    House: $processed / " . count($votes) . " roll calls ($memberTotal member votes)\r";
            usleep(100000); // throttle XML downloads
        }
        echo "    House: $processed roll calls, $memberTotal member votes" . PHP_EOL;
    }
}

// ============================================
// STEP 3: SENATE VOTES
// ============================================
if ($step === 'all' || $step === 'senate-votes') {
    echo PHP_EOL . "=== STEP 3: Import Senate Roll Call Votes ===" . PHP_EOL;

    if (!$dryRun) {
        $insertVote = $pdo->prepare("INSERT INTO roll_call_votes
            (congress, session_number, chamber, roll_call_number, bill_type, bill_number,
             vote_question, vote_result, vote_date, yea_total, nay_total, present_total,
             not_voting_total, source_url)
            VALUES (?,?,'Senate',?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE vote_result=VALUES(vote_result), yea_total=VALUES(yea_total),
            nay_total=VALUES(nay_total), present_total=VALUES(present_total),
            not_voting_total=VALUES(not_voting_total)");

        $insertMember = $pdo->prepare("INSERT INTO member_votes
            (vote_id, bioguide_id, official_id, member_name, party, state, district, vote)
            VALUES (?,?,?,?,?,?,?,?)");

        // Find max senate vote number by probing
        echo "  Finding max Senate vote number..." . PHP_EOL;
        $maxVote = 0;
        for ($probe = 600; $probe >= 1; $probe -= 50) {
            $padded = str_pad($probe, 5, '0', STR_PAD_LEFT);
            $url = "https://www.senate.gov/legislative/LIS/roll_call_votes/vote1191/vote_119_1_$padded.xml";
            $test = @file_get_contents($url, false, $xmlCtx);
            if ($test) {
                // Refine
                for ($j = $probe + 49; $j > $probe; $j--) {
                    $p2 = str_pad($j, 5, '0', STR_PAD_LEFT);
                    $u2 = "https://www.senate.gov/legislative/LIS/roll_call_votes/vote1191/vote_119_1_$p2.xml";
                    $t2 = @file_get_contents($u2, false, $xmlCtx);
                    if ($t2) { $maxVote = $j; break; }
                }
                if (!$maxVote) $maxVote = $probe;
                break;
            }
        }
        echo "  Max Senate vote: $maxVote" . PHP_EOL;

        $processed = 0;
        $memberTotal = 0;
        $session = 1;

        for ($rollCall = 1; $rollCall <= $maxVote; $rollCall++) {
            $padded = str_pad($rollCall, 5, '0', STR_PAD_LEFT);
            $xmlUrl = "https://www.senate.gov/legislative/LIS/roll_call_votes/vote1191/vote_119_1_$padded.xml";
            $xml = @file_get_contents($xmlUrl, false, $xmlCtx);
            if (!$xml) {
                echo "    SKIP Senate vote $rollCall — download failed" . PHP_EOL;
                continue;
            }

            $doc = new SimpleXMLElement($xml);
            $question = (string)($doc->vote_question_text ?? '');
            $result = (string)($doc->vote_result_text ?? '');
            $dateStr = (string)($doc->vote_date ?? '');
            $voteDate = null;
            if ($dateStr && preg_match('/(\w+ \d+, \d{4})/', $dateStr, $dm)) {
                $d = date_create($dm[1]);
                if ($d) $voteDate = $d->format('Y-m-d');
            }

            // Parse bill reference from document
            $billType = null; $billNum = null;
            $docTitle = (string)($doc->vote_document_text ?? '');
            if (preg_match('/(H\.R\.|S\.|H\.J\.Res\.|S\.J\.Res\.)\s*(\d+)/i', $docTitle, $bm)) {
                $typeMap = ['h.r.' => 'hr', 's.' => 's', 'h.j.res.' => 'hjres', 's.j.res.' => 'sjres'];
                $billType = $typeMap[strtolower($bm[1])] ?? null;
                $billNum = (int)$bm[2];
            }

            // Totals from count elements
            $yea = (int)($doc->count->yeas ?? 0);
            $nay = (int)($doc->count->nays ?? 0);
            $present = (int)($doc->count->present ?? 0);
            $notVoting = (int)($doc->count->absent ?? 0);

            $insertVote->execute([
                $congress, $session, $rollCall,
                $billType, $billNum,
                $question, $result, $voteDate,
                $yea, $nay, $present, $notVoting, $xmlUrl
            ]);
            $voteId = $pdo->lastInsertId();
            if (!$voteId) {
                $voteId = $pdo->query("SELECT vote_id FROM roll_call_votes
                    WHERE congress=$congress AND chamber='Senate' AND session_number=$session
                    AND roll_call_number=$rollCall")->fetchColumn();
                $pdo->exec("DELETE FROM member_votes WHERE vote_id = $voteId");
            }

            // Member votes
            $memberCount = 0;
            if (isset($doc->members->member)) {
                foreach ($doc->members->member as $m) {
                    $bioguide = (string)($m->bioguide_id ?? '');
                    $name = trim((string)($m->first_name ?? '') . ' ' . (string)($m->last_name ?? ''));
                    $party = (string)($m->party ?? '');
                    $state = (string)($m->state ?? '');
                    $voteCast = (string)($m->vote_cast ?? '');

                    $normalVote = 'Not Voting';
                    $vc = strtolower($voteCast);
                    if ($vc === 'yea' || $vc === 'aye') $normalVote = 'Yea';
                    elseif ($vc === 'nay' || $vc === 'no' || $vc === 'guilty' || $vc === 'not guilty') $normalVote = 'Nay';
                    elseif ($vc === 'present') $normalVote = 'Present';
                    elseif ($vc === 'not voting') $normalVote = 'Not Voting';

                    // Special: "Guilty"/"Not Guilty" for impeachment
                    if ($vc === 'guilty') $normalVote = 'Yea';
                    if ($vc === 'not guilty') $normalVote = 'Nay';

                    $officialId = $officialLookup[$bioguide] ?? null;
                    $insertMember->execute([
                        $voteId, $bioguide ?: null, $officialId,
                        $name, $party ?: null, $state ?: null,
                        null, $normalVote
                    ]);
                    $memberCount++;
                }
            }

            // Compute party breakdown for this vote
            updatePartyTallies($pdo, $voteId);

            $memberTotal += $memberCount;
            $processed++;
            if ($processed % 10 == 0) echo "    Senate: $processed / $maxVote roll calls ($memberTotal member votes)\r";
            usleep(100000);
        }
        echo "    Senate: $processed roll calls, $memberTotal member votes" . PHP_EOL;
    }
}

// ============================================
// STEP 4: EXTRAS (amendments, reports, etc.)
// ============================================
if ($step === 'all' || $step === 'extras') {
    echo PHP_EOL . "=== STEP 4: Import Extras ===" . PHP_EOL;

    // --- AMENDMENTS ---
    echo "  Amendments..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/amendment/$congress", $apiKey, $ctx, 'amendments');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO amendments
            (congress, amendment_type, amendment_number, latest_action_date, latest_action_text, congress_url)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE latest_action_date=VALUES(latest_action_date), latest_action_text=VALUES(latest_action_text)");
        foreach ($items as $a) {
            $type = strtolower($a['type'] ?? '');
            $num = (int)($a['number'] ?? 0);
            $actionDate = $a['latestAction']['actionDate'] ?? null;
            $actionText = $a['latestAction']['text'] ?? null;
            $url = $a['url'] ?? null;
            if ($type && $num) $ins->execute([$congress, $type, $num, $actionDate, $actionText, $url]);
        }
    }

    // --- COMMITTEE REPORTS ---
    echo "  Committee reports..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/committee-report/$congress", $apiKey, $ctx, 'reports');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO committee_reports
            (congress, report_type, report_number, chamber, report_date, congress_url)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE report_date=VALUES(report_date)");
        foreach ($items as $r) {
            $type = strtolower($r['type'] ?? '');
            $num = (int)($r['number'] ?? 0);
            $chamber = $r['chamber'] ?? null;
            $date = $r['updateDate'] ?? null;
            $url = $r['url'] ?? null;
            if ($type && $num) $ins->execute([$congress, $type, $num, $chamber, $date ? substr($date, 0, 10) : null, $url]);
        }
    }

    // --- COMMITTEE MEETINGS ---
    echo "  Committee meetings..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/committee-meeting/$congress", $apiKey, $ctx, 'committeeMeetings');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO committee_meetings
            (congress, chamber, event_id, title, meeting_date, congress_url)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title), meeting_date=VALUES(meeting_date)");
        foreach ($items as $m) {
            $chamber = $m['chamber'] ?? null;
            $eventId = $m['eventId'] ?? null;
            $title = $m['title'] ?? null;
            $date = $m['date'] ?? null;
            $url = $m['url'] ?? null;
            if ($eventId) $ins->execute([$congress, $chamber, $eventId, $title, $date, $url]);
        }
    }

    // --- HEARINGS ---
    echo "  Hearings..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/hearing/$congress", $apiKey, $ctx, 'hearings');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO hearings
            (congress, chamber, hearing_number, title, congress_url)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title)");
        foreach ($items as $h) {
            $chamber = $h['chamber'] ?? null;
            $num = $h['number'] ?? null;
            $title = $h['title'] ?? null;
            $url = $h['url'] ?? null;
            if ($num) $ins->execute([$congress, $chamber, $num, $title, $url]);
        }
    }

    // --- NOMINATIONS ---
    echo "  Nominations..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/nomination/$congress", $apiKey, $ctx, 'nominations');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO nominations
            (congress, nomination_number, description, received_date, latest_action_date, latest_action_text, congress_url)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE latest_action_date=VALUES(latest_action_date), latest_action_text=VALUES(latest_action_text)");
        foreach ($items as $n) {
            $num = $n['number'] ?? $n['partNumber'] ?? null;
            $desc = $n['description'] ?? null;
            $received = $n['receivedDate'] ?? null;
            $actionDate = $n['latestAction']['actionDate'] ?? null;
            $actionText = $n['latestAction']['text'] ?? null;
            $url = $n['url'] ?? null;
            if ($num) $ins->execute([$congress, $num, $desc, $received, $actionDate, $actionText, $url]);
        }
    }

    // --- HOUSE COMMUNICATIONS ---
    echo "  House communications..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/house-communication/$congress", $apiKey, $ctx, 'houseCommunications');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO congressional_communications
            (congress, chamber, comm_type, comm_number, title, congress_url)
            VALUES (?,'House',?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title)");
        foreach ($items as $c2) {
            $type = $c2['communicationType']['code'] ?? null;
            $num = (int)($c2['number'] ?? 0);
            $title = $c2['title'] ?? null;
            $url = $c2['url'] ?? null;
            if ($num) $ins->execute([$congress, $type, $num, $title, $url]);
        }
    }

    // --- SENATE COMMUNICATIONS ---
    echo "  Senate communications..." . PHP_EOL;
    $items = apiGetAll("https://api.congress.gov/v3/senate-communication/$congress", $apiKey, $ctx, 'senateCommunications');
    echo "    Fetched: " . count($items) . PHP_EOL;
    if (!$dryRun && count($items) > 0) {
        $ins = $pdo->prepare("INSERT INTO congressional_communications
            (congress, chamber, comm_type, comm_number, title, congress_url)
            VALUES (?,'Senate',?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title)");
        foreach ($items as $c2) {
            $type = $c2['communicationType']['code'] ?? null;
            $num = (int)($c2['number'] ?? 0);
            $title = $c2['title'] ?? null;
            $url = $c2['url'] ?? null;
            if ($num) $ins->execute([$congress, $type, $num, $title, $url]);
        }
    }
}

// ============================================
// SUMMARY
// ============================================
echo PHP_EOL . "=== SUMMARY ===" . PHP_EOL;
$tables = [
    'tracked_bills' => "SELECT COUNT(*) FROM tracked_bills WHERE congress = $congress",
    'roll_call_votes' => "SELECT COUNT(*) FROM roll_call_votes WHERE congress = $congress",
    'member_votes' => "SELECT COUNT(*) FROM member_votes mv JOIN roll_call_votes rv ON mv.vote_id = rv.vote_id WHERE rv.congress = $congress",
    'amendments' => "SELECT COUNT(*) FROM amendments WHERE congress = $congress",
    'committee_reports' => "SELECT COUNT(*) FROM committee_reports WHERE congress = $congress",
    'committee_meetings' => "SELECT COUNT(*) FROM committee_meetings WHERE congress = $congress",
    'hearings' => "SELECT COUNT(*) FROM hearings WHERE congress = $congress",
    'nominations' => "SELECT COUNT(*) FROM nominations WHERE congress = $congress",
    'congressional_communications' => "SELECT COUNT(*) FROM congressional_communications WHERE congress = $congress",
];
$grandTotal = 0;
foreach ($tables as $table => $sql) {
    $cnt = $pdo->query($sql)->fetchColumn();
    echo str_pad($table, 30) . number_format($cnt) . " rows" . PHP_EOL;
    $grandTotal += $cnt;
}
echo str_repeat("─", 45) . PHP_EOL;
echo str_pad("TOTAL", 30) . number_format($grandTotal) . " rows" . PHP_EOL;

// DB size
$size = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
    FROM information_schema.tables WHERE table_schema = '{$c['database']}'")->fetchColumn();
echo "Database size: {$size} MB" . PHP_EOL;
echo "Done." . PHP_EOL;
