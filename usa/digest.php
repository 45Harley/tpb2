<?php
/**
 * Congressional Scorecard — driven by rep_scorecard + congressional data tables.
 * Usage: /usa/digest.php              (digest — default)
 *        /usa/digest.php?state=VT    (state delegation view)
 *        /usa/digest.php?rep=441     (rep detail view)
 *        /usa/digest.php?vote=123    (vote detail)
 *        /usa/digest.php?bill=hr-144 (bill detail)
 *        /usa/digest.php?committee=1049 (committee detail)
 *        /usa/digest.php?nom=650     (nomination detail)
 */
$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once dirname(__DIR__) . '/includes/congressional-glossary.php';

// Shared nav setup
require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'usa';
$secondaryNavBrand = 'USA';
$secondaryNav = [
    ['label' => 'Map', 'url' => '/usa/'],
    ['label' => 'Congressional', 'url' => '/usa/digest.php'],
    ['label' => 'Executive', 'url' => '/usa/executive.php'],
    ['label' => 'Judicial', 'url' => '/usa/judicial.php'],
    ['label' => 'Documents', 'url' => '/usa/docs/'],
    ['label' => 'Glossary', 'url' => '/usa/glossary.php'],
];

$congress = (int)($_GET['congress'] ?? 119);
$repId = isset($_GET['rep']) ? (int)$_GET['rep'] : null;
$voteId = isset($_GET['vote']) ? (int)$_GET['vote'] : null;
$billParam = $_GET['bill'] ?? null;  // e.g. "hr-144"
$committeeId = isset($_GET['committee']) ? (int)$_GET['committee'] : null;
$nomNumber = $_GET['nom'] ?? null;
$stateParam = $_GET['state'] ?? null;
$digestView = !$repId && !$voteId && !$billParam && !$committeeId && !$nomNumber && !$stateParam;

// ── Helper functions ──
function partyClass($party) { $p = substr($party,0,1); return $p==='D'?'dem':($p==='R'?'rep':'ind'); }
function partyLabel($party) { $p = substr($party,0,1); return $p==='D'?'Democrat':($p==='R'?'Republican':'Independent'); }
function partyColor($party) { $p = substr($party,0,1); return $p==='D'?'blue-dem':($p==='R'?'red-rep':'purple'); }
function shortCommittee($name) {
    $name = preg_replace('/^(House|Senate) (Committee on (the )?|Permanent Select Committee on )/', '', $name);
    $name = preg_replace('/^United States Senate Caucus on /', '', $name);
    return $name;
}
function district($officeName) {
    if (preg_match('/District (\d+)/', $officeName, $m)) return $m[1] === '0' ? 'AL' : $m[1];
    return '';
}

/**
 * Enrich an array of vote rows with subject (bill title / nomination description)
 * parsed from vote_question + bill_type/bill_number columns.
 * Adds _subject, _subject_url, _action (short procedural label) to each row.
 */
function enrichVotes(array &$votes, PDO $pdo, int $congress): void {
    static $billCache = [], $nomCache = [];
    foreach ($votes as &$v) {
        $v['_subject'] = '';
        $v['_subject_url'] = '';
        $v['_full_title'] = ''; // longer description for hover
        // Parse the procedural action into a short label
        $q = $v['vote_question'] ?? '';
        if (preg_match('/^On (the )?(Motion to |)(Passage|Commit|Recommit|Agreeing|Ordering|Cloture|Table|Suspend|Reconsider|Amendment|Nomination|Joint Resolution)/i', $q, $am)) {
            $v['_action'] = $am[2] . $am[3];
        } else {
            $v['_action'] = substr($q, 0, 25);
        }

        // 1) Bill — from columns or parsed from question text
        $bt = $v['bill_type'] ?? '';
        $bn = $v['bill_number'] ?? '';
        if (!$bt && preg_match('/S\.J\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'sjres'; $bn = $m[1]; }
        if (!$bt && preg_match('/H\.J\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'hjres'; $bn = $m[1]; }
        if (!$bt && preg_match('/S\.Con\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'sconres'; $bn = $m[1]; }
        if (!$bt && preg_match('/H\.Con\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'hconres'; $bn = $m[1]; }
        if (!$bt && preg_match('/S\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'sres'; $bn = $m[1]; }
        if (!$bt && preg_match('/H\.Res\.\s*(\d+)/i', $q, $m)) { $bt = 'hres'; $bn = $m[1]; }
        if (!$bt && preg_match('/H\.R\.\s*(\d+)/', $q, $m)) { $bt = 'hr'; $bn = $m[1]; }
        if (!$bt && preg_match('/\bS\.\s+(\d+)/', $q, $m)) { $bt = 's'; $bn = $m[1]; }
        if ($bt && $bn) {
            $ck = "{$bt}_{$bn}";
            if (!isset($billCache[$ck])) {
                $s = $pdo->prepare("SELECT short_title, title FROM tracked_bills WHERE bill_type=? AND bill_number=? AND congress=? LIMIT 1");
                $s->execute([$bt, $bn, $congress]);
                $billCache[$ck] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $label = strtoupper($bt) . ' ' . $bn;
            if ($billCache[$ck]) {
                $shortT = $billCache[$ck]['short_title'] ?: substr($billCache[$ck]['title'], 0, 100);
                $v['_subject'] = $label . ': ' . $shortT;
                // Full title for hover — use the long title if different from short
                $v['_full_title'] = $billCache[$ck]['title'] ?: '';
            } else {
                $v['_subject'] = $label;
            }
            $v['_subject_url'] = "?bill={$bt}-{$bn}";
        }

        // 2) Nomination — parse PN number
        if (!$v['_subject'] && preg_match('/PN(\d+)/', $q, $m)) {
            $pn = $m[1];
            if (!isset($nomCache[$pn])) {
                $s = $pdo->prepare("SELECT description FROM nominations WHERE nomination_number=? AND congress=? LIMIT 1");
                $s->execute([$pn, $congress]);
                $nomCache[$pn] = $s->fetchColumn() ?: '';
            }
            if ($nomCache[$pn]) {
                $v['_subject'] = 'PN' . $pn . ': ' . substr($nomCache[$pn], 0, 80);
                $v['_full_title'] = $nomCache[$pn]; // full description for hover
            } else {
                $v['_subject'] = 'Nomination PN' . $pn;
            }
            $v['_subject_url'] = "?nom={$pn}";
        }

        // 3) Amendment — "S.Amdt. 2382 to H.R. 1" → look up the target bill
        if (!$v['_subject'] && preg_match('/S\.Amdt\.\s*(\d+)\s+to\s+(?:S\.Amdt\.\s*\d+\s+to\s+)?/i', $q, $m)) {
            $amdtNum = $m[1];
            // Try to find the target bill in the rest of the question
            $targetBt = ''; $targetBn = '';
            if (preg_match('/to H\.R\.\s*(\d+)/i', $q, $tm)) { $targetBt = 'hr'; $targetBn = $tm[1]; }
            elseif (preg_match('/to S\.\s+(\d+)/i', $q, $tm)) { $targetBt = 's'; $targetBn = $tm[1]; }
            if ($targetBt && $targetBn) {
                $ck2 = "{$targetBt}_{$targetBn}";
                if (!isset($billCache[$ck2])) {
                    $s = $pdo->prepare("SELECT short_title, title FROM tracked_bills WHERE bill_type=? AND bill_number=? AND congress=? LIMIT 1");
                    $s->execute([$targetBt, $targetBn, $congress]);
                    $billCache[$ck2] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $billLabel = strtoupper($targetBt) . ' ' . $targetBn;
                $billTitle = $billCache[$ck2] ? ($billCache[$ck2]['short_title'] ?: substr($billCache[$ck2]['title'], 0, 80)) : '';
                $v['_subject'] = "Amdt. {$amdtNum} to {$billLabel}" . ($billTitle ? ": {$billTitle}" : '');
                $v['_subject_url'] = "?bill={$targetBt}-{$targetBn}";
            } else {
                $v['_subject'] = "Senate Amdt. {$amdtNum}";
            }
        }

        // 4) Fallback — extract parenthetical, or build a descriptive label
        if (!$v['_subject']) {
            if (preg_match('/\(([^)]+)\)/', $q, $m) && $m[1] !== 'No short title on file') {
                $v['_subject'] = $m[1];
            } elseif (preg_match('/Amendment/', $q)) {
                $ch = $v['chamber'] ?? '';
                $rc = $v['roll_call_number'] ?? '';
                $v['_subject'] = "{$ch} Amendment Vote" . ($rc ? " (Roll Call #{$rc})" : '');
            } else {
                $v['_subject'] = $q;
            }
        }
    }
    unset($v);
}
/**
 * Group enriched vote rows by subject — one entry per bill/nomination.
 * Returns array of groups, each with: subject, subject_url, chamber, votes[], final_result, etc.
 */
function groupVotesBySubject(array $votes): array {
    $groups = [];
    foreach ($votes as $v) {
        $key = $v['_subject_url'] ?: ('_' . $v['vote_id']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'subject'     => $v['_subject'],
                'subject_url' => $v['_subject_url'],
                'full_title'  => $v['_full_title'] ?? '',
                'chamber'     => $v['chamber'] ?? '',
                'votes'       => [],
                'final_result'=> $v['vote_result'],
                'final_action'=> $v['_action'],
                'last_date'   => $v['vote_date'],
                'first_date'  => $v['vote_date'],
                'r_yea' => $v['r_yea'] ?? 0, 'r_nay' => $v['r_nay'] ?? 0,
                'd_yea' => $v['d_yea'] ?? 0, 'd_nay' => $v['d_nay'] ?? 0,
                'yea_total' => $v['yea_total'] ?? 0, 'nay_total' => $v['nay_total'] ?? 0,
                'margin' => $v['margin'] ?? null,
            ];
        }
        $groups[$key]['votes'][] = $v;
        if ($v['vote_date'] < $groups[$key]['first_date']) {
            $groups[$key]['first_date'] = $v['vote_date'];
        }
        if (empty($groups[$key]['full_title']) && !empty($v['_full_title'])) {
            $groups[$key]['full_title'] = $v['_full_title'];
        }
    }
    return $groups;
}

function initials($name) { return implode('', array_map(fn($w) => $w[0] ?? '', array_slice(explode(' ', $name), 0, 2))); }
function fallbackImg($name) {
    $i = initials($name);
    return "data:image/svg+xml," . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 80"><rect fill="#252d44" width="64" height="80"/><text x="32" y="48" fill="#8892a8" text-anchor="middle" font-size="20">'.$i.'</text></svg>');
}

// ── Shared page shell ──
function pageHead(string $title): void {
    global $currentPage, $secondaryNavBrand, $secondaryNav, $dbUser,
           $userId, $trustLevel, $userTrustLevel, $points, $isLoggedIn,
           $userEmail, $userTownName, $userTownSlug, $userTownId,
           $userStateAbbr, $userStateDisplay, $userStateId;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($title) . ' — Scorecard | TPB</title>';
    echo '<style>
  :root { --bg:#0a0e1a; --card:#141929; --card-hover:#1a2035; --border:#252d44; --text:#e8eaf0; --muted:#8892a8; --accent:#4a9eff; --gold:#f0b429; --green:#34d399; --red:#f87171; --blue-dem:#3b82f6; --red-rep:#ef4444; --purple:#a78bfa; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
  a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }
  .breadcrumb { padding:12px 32px; background:#0d1220; border-bottom:1px solid var(--border); font-size:13px; color:var(--muted); }
  .breadcrumb a { color:var(--muted); } .breadcrumb a:hover { color:var(--text); }
  .breadcrumb .sep { margin:0 8px; opacity:0.4; }
  .page-hero { padding:32px; background:linear-gradient(135deg,#0f1628,#1a2544); border-bottom:1px solid var(--border); }
  .page-hero h1 { font-size:24px; margin-bottom:8px; }
  .page-hero .subtitle { font-size:14px; color:var(--muted); }
  .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border); }
  .detail-section { background:var(--card); padding:24px 32px; }
  .detail-section h2 { font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin-bottom:16px; }
  .detail-section.full { grid-column:1/-1; }
  .stat-row { display:flex; gap:24px; margin:16px 0; flex-wrap:wrap; }
  .stat-box { text-align:center; min-width:100px; }
  .stat-box .sv { font-size:28px; font-weight:700; }
  .stat-box .sl { font-size:11px; color:var(--muted); text-transform:uppercase; }
  .data-tbl { width:100%; border-collapse:collapse; font-size:13px; }
  .data-tbl th { text-align:left; color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:0.5px; padding:8px 12px; border-bottom:1px solid var(--border); }
  .data-tbl td { padding:8px 12px; border-bottom:1px solid rgba(255,255,255,0.04); }
  .data-tbl tr:hover td { background:rgba(255,255,255,0.02); }
  .tag { display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; }
  .tag-dem { background:rgba(59,130,246,0.2); color:var(--blue-dem); }
  .tag-rep { background:rgba(239,68,68,0.2); color:var(--red-rep); }
  .tag-ind { background:rgba(167,139,250,0.2); color:var(--purple); }
  .tag-yea { background:rgba(52,211,153,0.1); color:var(--green); }
  .tag-nay { background:rgba(248,113,113,0.1); color:var(--red); }
  .tag-nv { background:rgba(255,255,255,0.05); color:var(--muted); }
  .tag-pass { background:rgba(52,211,153,0.15); color:var(--green); }
  .tag-fail { background:rgba(248,113,113,0.15); color:var(--red); }
  .tag-gold { background:rgba(240,180,41,0.15); color:var(--gold); }
  .footer { padding:16px 32px; font-size:11px; color:var(--muted); text-align:center; border-top:1px solid var(--border); }
  .party-bar { height:32px; display:flex; border-radius:6px; overflow:hidden; margin:12px 0; }
  .party-bar .pb-d { background:var(--blue-dem); } .party-bar .pb-r { background:var(--red-rep); } .party-bar .pb-i { background:var(--purple); } .party-bar .pb-nv { background:#374151; }
  @media (max-width:768px) { .detail-grid { grid-template-columns:1fr; } .page-hero { padding:20px; } }
</style>';
    echo cg_js();
    echo '</head><body>';
    $pageTitle = $title;
    require dirname(__DIR__) . '/includes/header.php';
    require dirname(__DIR__) . '/includes/nav.php';
}
function breadcrumb(array $links): void {
    echo '<div class="breadcrumb">';
    foreach ($links as $i => $link) {
        if ($i > 0) echo '<span class="sep">&rsaquo;</span>';
        if (isset($link['url'])) echo '<a href="' . $link['url'] . '">' . $link['label'] . '</a>';
        else echo '<span style="color:var(--text)">' . $link['label'] . '</span>';
    }
    echo '</div>';
}
function pageFooter(string $stateCode = ''): void {
    $back = $stateCode ? '<a href="?state=' . $stateCode . '">&larr; ' . $stateCode . ' delegation</a> &middot; ' : '';
    echo '<div class="footer">' . $back . '119th Congress &middot; <strong style="color:var(--gold)">The People\'s Branch</strong></div></body></html>';
}

// ═══════════════════════════════════════════
// VOTE DETAIL VIEW
// ═══════════════════════════════════════════
if ($voteId):

$vote = $pdo->prepare("SELECT * FROM roll_call_votes WHERE vote_id = ?");
$vote->execute([$voteId]);
$vote = $vote->fetch(PDO::FETCH_ASSOC);
if (!$vote) die("<h1>Vote not found</h1>");

// Get all individual member votes
$members = $pdo->prepare("
    SELECT mv.*, eo.full_name, eo.party, eo.state_code, eo.office_name, eo.official_id, eo.photo_url
    FROM member_votes mv
    JOIN elected_officials eo ON mv.official_id = eo.official_id
    WHERE mv.vote_id = ?
    ORDER BY mv.vote, eo.party, eo.full_name
");
$members->execute([$voteId]);
$memberVotes = $members->fetchAll(PDO::FETCH_ASSOC);

// Party breakdown
$partyBreakdown = ['D' => ['Yea'=>0,'Nay'=>0,'Not Voting'=>0,'Present'=>0], 'R' => ['Yea'=>0,'Nay'=>0,'Not Voting'=>0,'Present'=>0], 'I' => ['Yea'=>0,'Nay'=>0,'Not Voting'=>0,'Present'=>0]];
foreach ($memberVotes as $mv) {
    $p = substr($mv['party'], 0, 1);
    if (!isset($partyBreakdown[$p])) $p = 'I';
    $partyBreakdown[$p][$mv['vote']]++;
}

// Enrich: what is this vote about?
$subject = ''; $subjectUrl = '';
$billTypeUrlMap = ['hr'=>'house-bill','s'=>'senate-bill','hjres'=>'house-joint-resolution','sjres'=>'senate-joint-resolution','hconres'=>'house-concurrent-resolution','sconres'=>'senate-concurrent-resolution','hres'=>'house-resolution','sres'=>'senate-resolution'];
$bt = $vote['bill_type'] ?? ''; $bn = $vote['bill_number'] ?? '';
if (!$bt && preg_match('/S\.J\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'sjres'; $bn = $m[1]; }
if (!$bt && preg_match('/H\.J\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'hjres'; $bn = $m[1]; }
if (!$bt && preg_match('/S\.Con\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'sconres'; $bn = $m[1]; }
if (!$bt && preg_match('/H\.Con\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'hconres'; $bn = $m[1]; }
if (!$bt && preg_match('/S\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'sres'; $bn = $m[1]; }
if (!$bt && preg_match('/H\.Res\.\s*(\d+)/i', $vote['vote_question'], $m)) { $bt = 'hres'; $bn = $m[1]; }
if (!$bt && preg_match('/H\.R\.\s*(\d+)/', $vote['vote_question'], $m)) { $bt = 'hr'; $bn = $m[1]; }
if (!$bt && preg_match('/\bS\.\s+(\d+)/', $vote['vote_question'], $m)) { $bt = 's'; $bn = $m[1]; }
if ($bt && $bn) {
    $tbq = $pdo->prepare("SELECT short_title, title, congress_url FROM tracked_bills WHERE bill_type=? AND bill_number=? AND congress=? LIMIT 1");
    $tbq->execute([$bt, $bn, $congress]);
    $bill = $tbq->fetch(PDO::FETCH_ASSOC);
    if ($bill) {
        $subject = strtoupper($bt) . ' ' . $bn . ': ' . ($bill['short_title'] ?: $bill['title']);
        $subjectUrl = $bill['congress_url'] ?: "https://www.congress.gov/bill/{$congress}th-congress/{$billTypeUrlMap[$bt]}/{$bn}";
    } else {
        $subject = strtoupper($bt) . ' ' . $bn;
        $subjectUrl = isset($billTypeUrlMap[$bt]) ? "https://www.congress.gov/bill/{$congress}th-congress/{$billTypeUrlMap[$bt]}/{$bn}" : '';
    }
}
if (!$subject && preg_match('/PN(\d+)/', $vote['vote_question'], $m)) {
    $pn = $m[1];
    $nq = $pdo->prepare("SELECT description FROM nominations WHERE nomination_number=? AND congress=? LIMIT 1");
    $nq->execute([$pn, $congress]);
    $desc = $nq->fetchColumn();
    $subject = $desc ? 'PN' . $pn . ': ' . $desc : 'Nomination PN' . $pn;
    $subjectUrl = "?nom={$pn}";
}
$billDetailUrl = ($bt && $bn) ? "?bill={$bt}-{$bn}" : '';

$passed = str_contains($vote['vote_result'], 'Agreed') || str_contains($vote['vote_result'], 'Passed') || str_contains($vote['vote_result'], 'Confirmed');
$failed = str_contains($vote['vote_result'], 'Rejected') || str_contains($vote['vote_result'], 'Failed') || str_contains($vote['vote_result'], 'Defeated');

pageHead($vote['vote_question']);
breadcrumb([
    ['label' => 'Congress', 'url' => '?'],
    ['label' => $vote['chamber'], 'url' => '#'],
    ['label' => 'Roll Call #' . $vote['roll_call_number']],
]);
?>

<div class="page-hero">
  <h1><?= cg('roll call', 'Roll Call') ?> #<?= $vote['roll_call_number'] ?> &mdash; <?= $vote['chamber'] ?></h1>
  <?php if ($subject): ?>
    <div style="font-size:18px;font-weight:600;margin:12px 0">
      <?php if ($subjectUrl): ?><a href="<?= htmlspecialchars($subjectUrl) ?>"><?= htmlspecialchars($subject) ?></a><?php else: ?><?= htmlspecialchars($subject) ?><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="subtitle"><?= htmlspecialchars($vote['vote_question']) ?></div>
  <div style="margin-top:12px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <span class="tag <?= $passed ? 'tag-pass' : ($failed ? 'tag-fail' : '') ?>" style="font-size:14px;padding:4px 14px"><?= htmlspecialchars($vote['vote_result']) ?></span>
    <span style="color:var(--muted)"><?= $vote['vote_date'] ?><?= $vote['vote_time'] ? ' at ' . $vote['vote_time'] : '' ?></span>
    <?php if ($vote['source_url']): ?><a href="<?= htmlspecialchars($vote['source_url']) ?>" target="_blank" style="font-size:12px">Official record &rarr;</a><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <!-- VOTE TOTALS -->
  <div class="detail-section">
    <h2>Vote Totals</h2>
    <div class="stat-row">
      <div class="stat-box"><div class="sv" style="color:var(--green)"><?= $vote['yea_total'] ?></div><div class="sl"><?= cg('yea') ?></div></div>
      <div class="stat-box"><div class="sv" style="color:var(--red)"><?= $vote['nay_total'] ?></div><div class="sl"><?= cg('nay') ?></div></div>
      <?php if ($vote['present_total'] > 0): ?><div class="stat-box"><div class="sv" style="color:var(--purple)"><?= $vote['present_total'] ?></div><div class="sl"><?= cg('present') ?></div></div><?php endif; ?>
      <div class="stat-box"><div class="sv" style="color:var(--muted)"><?= $vote['not_voting_total'] ?></div><div class="sl"><?= cg('not voting') ?></div></div>
    </div>
    <?php $totalV = $vote['yea_total'] + $vote['nay_total'] + $vote['present_total'] + $vote['not_voting_total']; ?>
    <div class="party-bar">
      <div style="width:<?= round($vote['yea_total']/$totalV*100,1) ?>%;background:var(--green)"></div>
      <div style="width:<?= round($vote['nay_total']/$totalV*100,1) ?>%;background:var(--red)"></div>
      <?php if ($vote['present_total'] > 0): ?><div style="width:<?= round($vote['present_total']/$totalV*100,1) ?>%;background:var(--purple)"></div><?php endif; ?>
      <div style="width:<?= round($vote['not_voting_total']/$totalV*100,1) ?>%;background:#374151"></div>
    </div>
  </div>

  <!-- PARTY BREAKDOWN -->
  <div class="detail-section">
    <h2>By Party</h2>
    <table class="data-tbl">
      <tr><th>Party</th><th><?= cg('yea') ?></th><th><?= cg('nay') ?></th><th><?= cg('not voting', 'NV') ?></th><th><?= cg('present', 'P') ?></th></tr>
      <?php foreach (['D' => 'Democrat', 'R' => 'Republican', 'I' => 'Independent'] as $pk => $pl):
        $pb = $partyBreakdown[$pk];
        if (array_sum($pb) === 0) continue;
        $pc = $pk === 'D' ? 'dem' : ($pk === 'R' ? 'rep' : 'ind');
      ?>
      <tr>
        <td><span class="tag tag-<?= $pc ?>"><?= $pl ?></span></td>
        <td style="color:var(--green);font-weight:600"><?= $pb['Yea'] ?></td>
        <td style="color:var(--red);font-weight:600"><?= $pb['Nay'] ?></td>
        <td style="color:var(--muted)"><?= $pb['Not Voting'] ?></td>
        <td style="color:var(--muted)"><?= $pb['Present'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($billDetailUrl): ?>
      <div style="margin-top:16px"><a href="<?= $billDetailUrl ?>" style="font-size:13px">View full bill details &rarr;</a></div>
    <?php endif; ?>
  </div>

  <!-- ALL MEMBER VOTES -->
  <div class="detail-section full">
    <h2>All Votes (<?= count($memberVotes) ?> members)</h2>
    <?php
    $grouped = ['Yea' => [], 'Nay' => [], 'Not Voting' => [], 'Present' => []];
    foreach ($memberVotes as $mv) $grouped[$mv['vote']][] = $mv;
    foreach ($grouped as $voteType => $voters):
      if (empty($voters)) continue;
      $vc = $voteType === 'Yea' ? 'tag-yea' : ($voteType === 'Nay' ? 'tag-nay' : 'tag-nv');
    ?>
    <div style="margin-bottom:20px">
      <h3 style="font-size:13px;margin-bottom:8px"><span class="tag <?= $vc ?>"><?= $voteType ?> (<?= count($voters) ?>)</span></h3>
      <div style="display:flex;flex-wrap:wrap;gap:4px">
        <?php foreach ($voters as $mv):
          $pc = partyClass($mv['party']);
          $dist = district($mv['office_name']);
          $label = $mv['full_name'] . ' (' . substr($mv['party'],0,1) . '-' . $mv['state_code'] . ($dist ? '-' . $dist : '') . ')';
        ?>
        <a href="?rep=<?= $mv['official_id'] ?>" class="tag tag-<?= $pc ?>" style="font-weight:400;font-size:12px" title="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($mv['full_name']) ?> <span style="opacity:0.6"><?= substr($mv['party'],0,1) ?>-<?= $mv['state_code'] ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php pageFooter(); exit; endif;

// ═══════════════════════════════════════════
// BILL DETAIL VIEW
// ═══════════════════════════════════════════
if ($billParam):

// Parse bill param like "hr-144" into type + number
[$billType, $billNum] = explode('-', $billParam, 2) + [1 => ''];
$bill = $pdo->prepare("SELECT * FROM tracked_bills WHERE bill_type = ? AND bill_number = ? AND congress = ?");
$bill->execute([$billType, (int)$billNum, $congress]);
$bill = $bill->fetch(PDO::FETCH_ASSOC);
if (!$bill) die("<h1>Bill not found: " . htmlspecialchars($billParam) . "</h1>");

// Sponsor info
$sponsor = null;
if ($bill['sponsor_bioguide']) {
    $sq = $pdo->prepare("SELECT official_id, full_name, party, state_code, office_name, photo_url FROM elected_officials WHERE bioguide_id = ?");
    $sq->execute([$bill['sponsor_bioguide']]);
    $sponsor = $sq->fetch(PDO::FETCH_ASSOC);
}

// All votes on this bill
$billVotes = $pdo->prepare("
    SELECT rv.* FROM roll_call_votes rv
    WHERE rv.congress = ? AND rv.bill_type = ? AND rv.bill_number = ?
    ORDER BY rv.vote_date DESC, rv.roll_call_number DESC
");
$billVotes->execute([$congress, $billType, (int)$billNum]);
$billVoteList = $billVotes->fetchAll(PDO::FETCH_ASSOC);

// Also find votes that reference this bill in question text but don't have bill_type/bill_number set
$billLabel = strtoupper($billType);
$searchPatterns = ['hr' => 'H.R. '.$billNum, 's' => 'S. '.$billNum, 'hjres' => 'H.J.Res. '.$billNum, 'sjres' => 'S.J.Res. '.$billNum, 'hres' => 'H.Res. '.$billNum, 'sres' => 'S.Res. '.$billNum, 'hconres' => 'H.Con.Res. '.$billNum, 'sconres' => 'S.Con.Res. '.$billNum];
$sp = $searchPatterns[$billType] ?? '';
if ($sp) {
    $existIds = array_column($billVoteList, 'vote_id');
    $txtVotes = $pdo->prepare("SELECT * FROM roll_call_votes WHERE congress = ? AND vote_question LIKE ? ORDER BY vote_date DESC");
    $txtVotes->execute([$congress, '%' . $sp . '%']);
    while ($tv = $txtVotes->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($tv['vote_id'], $existIds)) $billVoteList[] = $tv;
    }
}

// Amendments to this bill
$amendments = $pdo->prepare("SELECT * FROM amendments WHERE amended_bill_type = ? AND amended_bill_number = ? AND congress = ? ORDER BY latest_action_date DESC");
$amendments->execute([$billType, (int)$billNum, $congress]);
$amendmentList = $amendments->fetchAll(PDO::FETCH_ASSOC);

$title = $bill['short_title'] ?: $bill['title'];
$billTypeUrlMap = ['hr'=>'house-bill','s'=>'senate-bill','hjres'=>'house-joint-resolution','sjres'=>'senate-joint-resolution','hconres'=>'house-concurrent-resolution','sconres'=>'senate-concurrent-resolution','hres'=>'house-resolution','sres'=>'senate-resolution'];

pageHead(strtoupper($billType) . ' ' . $billNum . ': ' . substr($title, 0, 60));
breadcrumb([
    ['label' => 'Congress', 'url' => '?'],
    ['label' => 'Bills'],
    ['label' => strtoupper($billType) . ' ' . $billNum],
]);
?>

<div class="page-hero">
  <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
    <h1><?= cg($billType, strtoupper($billType)) ?> <?= $billNum ?></h1>
    <?php if ($bill['congress_url']): ?><a href="<?= htmlspecialchars($bill['congress_url']) ?>" target="_blank" style="font-size:13px">Congress.gov &rarr;</a><?php endif; ?>
  </div>
  <div style="font-size:16px;margin-top:8px"><?= htmlspecialchars($title) ?></div>
  <?php if ($sponsor): ?>
    <div style="margin-top:12px;display:flex;align-items:center;gap:10px">
      <span style="color:var(--muted)">Sponsored by</span>
      <a href="?rep=<?= $sponsor['official_id'] ?>" style="display:flex;align-items:center;gap:8px">
        <img src="<?= htmlspecialchars($sponsor['photo_url'] ?? '') ?>" style="width:28px;height:35px;border-radius:4px;object-fit:cover" onerror="this.style.display='none'">
        <?= htmlspecialchars($sponsor['full_name']) ?>
      </a>
      <span class="tag tag-<?= partyClass($sponsor['party']) ?>"><?= partyLabel($sponsor['party']) ?> — <?= $sponsor['state_code'] ?></span>
    </div>
  <?php elseif ($bill['sponsor_name']): ?>
    <div style="margin-top:8px;color:var(--muted)">Sponsored by <?= htmlspecialchars($bill['sponsor_name']) ?></div>
  <?php endif; ?>
</div>

<div class="detail-grid">
  <div class="detail-section">
    <h2>Status</h2>
    <table class="data-tbl">
      <tr><td style="color:var(--muted)">Introduced</td><td><?= $bill['introduced_date'] ?></td></tr>
      <tr><td style="color:var(--muted)">Origin</td><td><?= htmlspecialchars($bill['origin_chamber'] ?? '') ?></td></tr>
      <tr><td style="color:var(--muted)">Status</td><td><?= htmlspecialchars($bill['status'] ?? 'In Progress') ?></td></tr>
      <tr><td style="color:var(--muted)">Last Action</td><td><?= $bill['last_action_date'] ?><br><span style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($bill['last_action_text'] ?? '') ?></span></td></tr>
    </table>
  </div>

  <div class="detail-section">
    <h2>Votes on This Bill (<?= count($billVoteList) ?>)</h2>
    <?php if ($billVoteList): ?>
    <table class="data-tbl">
      <tr><th>Date</th><th>Question</th><th>Result</th><th></th></tr>
      <?php foreach ($billVoteList as $bv):
        $rPass = str_contains($bv['vote_result'], 'Agreed') || str_contains($bv['vote_result'], 'Passed') || str_contains($bv['vote_result'], 'Confirmed');
        $rFail = str_contains($bv['vote_result'], 'Rejected') || str_contains($bv['vote_result'], 'Failed');
      ?>
      <tr>
        <td style="white-space:nowrap"><?= $bv['vote_date'] ?></td>
        <td><?= htmlspecialchars(substr($bv['vote_question'], 0, 80)) ?></td>
        <td><span class="tag <?= $rPass ? 'tag-pass' : ($rFail ? 'tag-fail' : '') ?>"><?= htmlspecialchars($bv['vote_result']) ?></span></td>
        <td><a href="?vote=<?= $bv['vote_id'] ?>">Details &rarr;</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <p style="color:var(--muted)">No recorded roll call votes yet.</p>
    <?php endif; ?>
  </div>

  <?php if ($amendmentList): ?>
  <div class="detail-section full">
    <h2><?= cg('amendment', 'Amendments') ?> (<?= count($amendmentList) ?>)</h2>
    <table class="data-tbl">
      <tr><th>Amendment</th><th>Sponsor</th><th>Last Action</th><th>Date</th></tr>
      <?php foreach ($amendmentList as $amdt): ?>
      <tr>
        <td><?= strtoupper($amdt['amendment_type']) ?> <?= $amdt['amendment_number'] ?></td>
        <td><?= htmlspecialchars($amdt['sponsor_name'] ?? '—') ?></td>
        <td style="font-size:12px"><?= htmlspecialchars(substr($amdt['latest_action_text'] ?? '', 0, 100)) ?></td>
        <td><?= $amdt['latest_action_date'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php pageFooter(); exit; endif;

// ═══════════════════════════════════════════
// COMMITTEE DETAIL VIEW
// ═══════════════════════════════════════════
if ($committeeId):

$comm = $pdo->prepare("SELECT * FROM committees WHERE committee_id = ?");
$comm->execute([$committeeId]);
$comm = $comm->fetch(PDO::FETCH_ASSOC);
if (!$comm) die("<h1>Committee not found</h1>");

// Parent committee if this is a subcommittee
$parent = null;
if ($comm['parent_id']) {
    $pq = $pdo->prepare("SELECT * FROM committees WHERE committee_id = ?");
    $pq->execute([$comm['parent_id']]);
    $parent = $pq->fetch(PDO::FETCH_ASSOC);
}

// Subcommittees
$subs = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM committee_memberships cm WHERE cm.committee_id = c.committee_id) as member_count FROM committees c WHERE c.parent_id = ? ORDER BY c.name");
$subs->execute([$committeeId]);
$subList = $subs->fetchAll(PDO::FETCH_ASSOC);

// Members with roles
$members = $pdo->prepare("
    SELECT cm.role, eo.official_id, eo.full_name, eo.party, eo.state_code, eo.office_name, eo.photo_url
    FROM committee_memberships cm
    JOIN elected_officials eo ON cm.official_id = eo.official_id
    WHERE cm.committee_id = ?
    ORDER BY FIELD(cm.role, 'Chairman', 'Chairwoman', 'Chair', 'Vice Chairman', 'Vice Chairwoman', 'Vice Chair', 'Ranking Member', 'Ex Officio', 'Cochairman', 'Member'), eo.party, eo.full_name
");
$members->execute([$committeeId]);
$memberList = $members->fetchAll(PDO::FETCH_ASSOC);

$leaders = array_filter($memberList, fn($m) => $m['role'] !== 'Member');
$regularMembers = array_filter($memberList, fn($m) => $m['role'] === 'Member');

// Party breakdown
$partyCount = ['D' => 0, 'R' => 0, 'I' => 0];
foreach ($memberList as $m) { $p = substr($m['party'],0,1); $partyCount[$p] = ($partyCount[$p] ?? 0) + 1; }

$shortName = shortCommittee($comm['name']);
pageHead($shortName);
$crumbs = [['label' => 'Congress', 'url' => '?'], ['label' => 'Committees', 'url' => '#']];
if ($parent) $crumbs[] = ['label' => shortCommittee($parent['name']), 'url' => '?committee=' . $parent['committee_id']];
$crumbs[] = ['label' => $shortName];
breadcrumb($crumbs);
?>

<?php
// Website URL: use this committee's or fall back to parent's
$websiteUrl = $comm['website_url'] ?: ($parent['website_url'] ?? '');
?>
<div class="page-hero">
  <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
    <h1><?= htmlspecialchars($shortName) ?></h1>
    <?php if ($websiteUrl): ?><a href="<?= htmlspecialchars($websiteUrl) ?>" target="_blank" style="font-size:13px">Official website &rarr;</a><?php endif; ?>
  </div>
  <div class="subtitle">
    <?= $comm['chamber'] ?> &middot; <?= $comm['committee_type'] ?>
    <?php if ($parent): ?> &middot; Subcommittee of <a href="?committee=<?= $parent['committee_id'] ?>"><?= htmlspecialchars(shortCommittee($parent['name'])) ?></a><?php endif; ?>
  </div>
  <div class="stat-row">
    <div class="stat-box"><div class="sv"><?= count($memberList) ?></div><div class="sl">Members</div></div>
    <?php if ($subList): ?><div class="stat-box"><div class="sv"><?= count($subList) ?></div><div class="sl"><?= cg('subcommittee', 'Subcommittees') ?></div></div><?php endif; ?>
    <div class="stat-box"><div class="sv" style="color:var(--blue-dem)"><?= $partyCount['D'] ?></div><div class="sl">Democrats</div></div>
    <div class="stat-box"><div class="sv" style="color:var(--red-rep)"><?= $partyCount['R'] ?></div><div class="sl">Republicans</div></div>
    <?php if ($partyCount['I']): ?><div class="stat-box"><div class="sv" style="color:var(--purple)"><?= $partyCount['I'] ?></div><div class="sl">Independent</div></div><?php endif; ?>
  </div>
</div>

<div class="detail-grid">
  <?php if ($leaders): ?>
  <div class="detail-section">
    <h2>Leadership</h2>
    <table class="data-tbl">
      <?php foreach ($leaders as $ldr): ?>
      <tr>
        <td style="width:36px"><img src="<?= htmlspecialchars($ldr['photo_url'] ?? '') ?>" style="width:32px;height:40px;border-radius:4px;object-fit:cover" onerror="this.style.display='none'"></td>
        <td><a href="?rep=<?= $ldr['official_id'] ?>"><?= htmlspecialchars($ldr['full_name']) ?></a></td>
        <td><span class="tag tag-<?= partyClass($ldr['party']) ?>"><?= substr($ldr['party'],0,1) ?>-<?= $ldr['state_code'] ?></span></td>
        <td><span class="tag tag-gold"><?= cg(strtolower($ldr['role']), $ldr['role']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($subList): ?>
  <div class="detail-section">
    <h2><?= cg('subcommittee', 'Subcommittees') ?> (<?= count($subList) ?>)</h2>
    <table class="data-tbl">
      <?php foreach ($subList as $sub): ?>
      <tr>
        <td><a href="?committee=<?= $sub['committee_id'] ?>"><?= htmlspecialchars(shortCommittee($sub['name'])) ?></a></td>
        <td style="color:var(--muted)"><?= $sub['member_count'] ?> members</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="detail-section full">
    <h2>All Members (<?= count($regularMembers) ?>)</h2>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach ($regularMembers as $m):
        $dist = district($m['office_name']);
      ?>
      <a href="?rep=<?= $m['official_id'] ?>" class="tag tag-<?= partyClass($m['party']) ?>" style="font-weight:400;font-size:12px">
        <?= htmlspecialchars($m['full_name']) ?> <span style="opacity:0.6"><?= substr($m['party'],0,1) ?>-<?= $m['state_code'] ?><?= $dist ? '-'.$dist : '' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php pageFooter(); exit; endif;

// ═══════════════════════════════════════════
// NOMINATION DETAIL VIEW
// ═══════════════════════════════════════════
if ($nomNumber):

$nom = $pdo->prepare("SELECT * FROM nominations WHERE nomination_number = ? AND congress = ?");
$nom->execute([$nomNumber, $congress]);
$nom = $nom->fetch(PDO::FETCH_ASSOC);
if (!$nom) die("<h1>Nomination PN" . htmlspecialchars($nomNumber) . " not found</h1>");

// Find votes related to this nomination
$nomVotes = $pdo->prepare("SELECT rv.* FROM roll_call_votes rv WHERE rv.congress = ? AND rv.vote_question LIKE ? ORDER BY rv.vote_date");
$nomVotes->execute([$congress, '%PN' . $nomNumber . '%']);
$nomVoteList = $nomVotes->fetchAll(PDO::FETCH_ASSOC);

// For each vote, get the party breakdown
$nomVoteDetails = [];
foreach ($nomVoteList as $nv) {
    $mvq = $pdo->prepare("SELECT mv.vote, eo.party FROM member_votes mv JOIN elected_officials eo ON mv.official_id=eo.official_id WHERE mv.vote_id=?");
    $mvq->execute([$nv['vote_id']]);
    $pb = ['D' => ['Yea'=>0,'Nay'=>0,'Not Voting'=>0,'Present'=>0], 'R' => ['Yea'=>0,'Nay'=>0,'Not Voting'=>0,'Present'=>0]];
    while ($mv = $mvq->fetch(PDO::FETCH_ASSOC)) {
        $p = substr($mv['party'],0,1);
        if (!isset($pb[$p])) $p = 'I';
        if (isset($pb[$p][$mv['vote']])) $pb[$p][$mv['vote']]++;
    }
    $nomVoteDetails[$nv['vote_id']] = $pb;
}

$confirmed = false;
foreach ($nomVoteList as $nv) {
    if (str_contains($nv['vote_result'], 'Confirmed')) { $confirmed = true; break; }
}

pageHead('PN' . $nomNumber . ': ' . substr($nom['description'] ?? '', 0, 60));
breadcrumb([
    ['label' => 'Congress', 'url' => '?'],
    ['label' => cg('nomination', 'Nominations')],
    ['label' => 'PN' . $nomNumber],
]);
?>

<div class="page-hero">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <h1>PN<?= htmlspecialchars($nomNumber) ?></h1>
    <span class="tag <?= $confirmed ? 'tag-pass' : 'tag-gold' ?>" style="font-size:14px;padding:4px 14px"><?= $confirmed ? cg('confirmed', 'Confirmed') : 'Pending' ?></span>
  </div>
  <div style="font-size:16px;margin-top:12px"><?= htmlspecialchars($nom['description'] ?? '') ?></div>
  <div style="margin-top:12px;display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:var(--muted)">
    <?php if ($nom['received_date']): ?><span>Received: <?= $nom['received_date'] ?></span><?php endif; ?>
    <?php if ($nom['latest_action_date']): ?><span>Last action: <?= $nom['latest_action_date'] ?></span><?php endif; ?>
    <a href="https://www.congress.gov/nomination/<?= $congress ?>th-congress/<?= $nomNumber ?>" target="_blank">Congress.gov &rarr;</a>
  </div>
</div>

<div class="detail-grid">
  <div class="detail-section<?= empty($nomVoteList) ? ' full' : '' ?>">
    <h2>Action History</h2>
    <table class="data-tbl">
      <tr><td style="color:var(--muted)">Received</td><td><?= $nom['received_date'] ?? '—' ?></td></tr>
      <tr><td style="color:var(--muted)">Latest Action</td><td><?= htmlspecialchars($nom['latest_action_text'] ?? '—') ?></td></tr>
      <tr><td style="color:var(--muted)">Latest Date</td><td><?= $nom['latest_action_date'] ?? '—' ?></td></tr>
      <?php if ($nom['organization']): ?><tr><td style="color:var(--muted)">Organization</td><td><?= htmlspecialchars($nom['organization']) ?></td></tr><?php endif; ?>
    </table>
  </div>

  <?php if ($nomVoteList): ?>
  <div class="detail-section">
    <h2>Senate Votes (<?= count($nomVoteList) ?>)</h2>
    <?php foreach ($nomVoteList as $nv):
      $rPass = str_contains($nv['vote_result'], 'Agreed') || str_contains($nv['vote_result'], 'Confirmed');
      $rFail = str_contains($nv['vote_result'], 'Rejected') || str_contains($nv['vote_result'], 'Failed');
      $pb = $nomVoteDetails[$nv['vote_id']] ?? [];
    ?>
    <div style="margin-bottom:16px;padding:12px;background:rgba(255,255,255,0.02);border-radius:8px">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <a href="?vote=<?= $nv['vote_id'] ?>" style="font-weight:600"><?= htmlspecialchars($nv['vote_question']) ?></a>
        <span class="tag <?= $rPass ? 'tag-pass' : ($rFail ? 'tag-fail' : '') ?>"><?= htmlspecialchars($nv['vote_result']) ?></span>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:6px">
        <?= $nv['vote_date'] ?> &middot; #<?= $nv['roll_call_number'] ?>
        <?php if (!empty($pb)): ?>
          &middot; <span style="color:var(--blue-dem)">D: <?= $pb['D']['Yea'] ?>Y-<?= $pb['D']['Nay'] ?>N</span>
          &middot; <span style="color:var(--red-rep)">R: <?= $pb['R']['Yea'] ?>Y-<?= $pb['R']['Nay'] ?>N</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php pageFooter(); exit; endif;

// ═══════════════════════════════════════════
// REP DETAIL VIEW
// ═══════════════════════════════════════════
if ($repId):

$rep = $pdo->prepare("
    SELECT eo.*, rs.*
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = ? AND eo.official_id = ?
");
$rep->execute([$congress, $repId]);
$rep = $rep->fetch(PDO::FETCH_ASSOC);
if (!$rep) die("<h1>Rep not found</h1>");

$stateCode = $rep['state_code'];
$stateName = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
$stateName->execute([$stateCode]);
$stateName = $stateName->fetchColumn() ?: $stateCode;

// Committees (full list including subcommittees)
$committees = $pdo->prepare("SELECT c.committee_id, c.name, cm.role, c.parent_id FROM committee_memberships cm
    JOIN committees c ON cm.committee_id = c.committee_id WHERE cm.official_id = ? ORDER BY c.parent_id, cm.role DESC, c.name");
$committees->execute([$repId]);
$allComms = $committees->fetchAll(PDO::FETCH_ASSOC);
$parentComms = array_filter($allComms, fn($c) => !$c['parent_id']);
$subComms = array_filter($allComms, fn($c) => $c['parent_id']);

// Recent bills sponsored
$bills = $pdo->prepare("SELECT bill_type, bill_number, title, short_title, last_action_date, last_action_text, status, congress_url
    FROM tracked_bills WHERE sponsor_bioguide = ? ORDER BY last_action_date DESC LIMIT 20");
$bills->execute([$rep['bioguide_id']]);
$billList = $bills->fetchAll(PDO::FETCH_ASSOC);

// Recent votes (last 20) — include bill_type/bill_number for context
$recentVotes = $pdo->prepare("SELECT rv.vote_id, rv.vote_question, rv.vote_date, rv.vote_result, mv.vote as member_vote,
    rv.roll_call_number, rv.chamber, rv.bill_type, rv.bill_number, rv.source_url
    FROM member_votes mv JOIN roll_call_votes rv ON mv.vote_id = rv.vote_id
    WHERE mv.official_id = ? ORDER BY rv.vote_date DESC, rv.roll_call_number DESC LIMIT 20");
$recentVotes->execute([$repId]);
$voteList = $recentVotes->fetchAll(PDO::FETCH_ASSOC);

// Enrich votes with bill titles, nomination descriptions, and links
$billTitleCache = [];
$nomCache = [];
// Map bill_type slugs to congress.gov URL path segments
$billTypeUrlMap = [
    'hr' => 'house-bill', 's' => 'senate-bill',
    'hjres' => 'house-joint-resolution', 'sjres' => 'senate-joint-resolution',
    'hconres' => 'house-concurrent-resolution', 'sconres' => 'senate-concurrent-resolution',
    'hres' => 'house-resolution', 'sres' => 'senate-resolution',
];
foreach ($voteList as &$v) {
    $v['_subject'] = '';     // What this vote is actually about
    $v['_subject_url'] = ''; // Link to the bill/nomination on congress.gov

    // 1) Bill title — from bill_type+bill_number columns (House) or parsed from question (Senate)
    $bt = $v['bill_type'] ?? '';
    $bn = $v['bill_number'] ?? '';
    // Parse bill references from question — most specific patterns first
    if (!$bt && preg_match('/S\.J\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'sjres'; $bn = $m[1]; }
    if (!$bt && preg_match('/H\.J\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'hjres'; $bn = $m[1]; }
    if (!$bt && preg_match('/S\.Con\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'sconres'; $bn = $m[1]; }
    if (!$bt && preg_match('/H\.Con\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'hconres'; $bn = $m[1]; }
    if (!$bt && preg_match('/S\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'sres'; $bn = $m[1]; }
    if (!$bt && preg_match('/H\.Res\.\s*(\d+)/i', $v['vote_question'], $m)) { $bt = 'hres'; $bn = $m[1]; }
    if (!$bt && preg_match('/H\.R\.\s*(\d+)/', $v['vote_question'], $m)) { $bt = 'hr'; $bn = $m[1]; }
    if (!$bt && preg_match('/\bS\.\s+(\d+)/', $v['vote_question'], $m)) { $bt = 's'; $bn = $m[1]; }
    if ($bt && $bn) {
        $cacheKey = "{$bt}_{$bn}";
        if (!isset($billTitleCache[$cacheKey])) {
            $tbq = $pdo->prepare("SELECT short_title, title, bill_type, bill_number, congress_url FROM tracked_bills WHERE bill_type=? AND bill_number=? AND congress=? LIMIT 1");
            $tbq->execute([$bt, $bn, $congress]);
            $billTitleCache[$cacheKey] = $tbq->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($billTitleCache[$cacheKey]) {
            $b = $billTitleCache[$cacheKey];
            $title = $b['short_title'] ?: substr($b['title'], 0, 120);
            $v['_subject'] = strtoupper($b['bill_type']) . ' ' . $b['bill_number'] . ': ' . $title;
            $v['_subject_url'] = "?bill={$bt}-{$bn}";
        } elseif (isset($billTypeUrlMap[$bt])) {
            $v['_subject'] = strtoupper($bt) . ' ' . $bn;
            $v['_subject_url'] = "?bill={$bt}-{$bn}";
        }
    }

    // 2) Nomination — parse PN number from question
    if (!$v['_subject'] && preg_match('/PN(\d+)/', $v['vote_question'], $m)) {
        $pn = $m[1];
        if (!isset($nomCache[$pn])) {
            $nq = $pdo->prepare("SELECT description FROM nominations WHERE nomination_number=? AND congress=? LIMIT 1");
            $nq->execute([$pn, $congress]);
            $nomCache[$pn] = $nq->fetchColumn() ?: '';
        }
        if ($nomCache[$pn]) {
            $v['_subject'] = 'PN' . $pn . ': ' . $nomCache[$pn];
        } else {
            $v['_subject'] = 'Nomination PN' . $pn;
        }
        $v['_subject_url'] = "?nom={$pn}";
    }
}
unset($v);

$dist = district($rep['office_name']);
$titleLine = $rep['chamber'] === 'House' && $dist ? "$stateCode-$dist" : 'Senator';
$pClass = partyClass($rep['party']);
$total = $rep['total_roll_calls'];
$yeaPct = $total > 0 ? round($rep['yea_count']/$total*100, 1) : 0;
$nayPct = $total > 0 ? round($rep['nay_count']/$total*100, 1) : 0;
$nvPct  = $total > 0 ? round($rep['missed_votes']/$total*100, 1) : 0;
$partColor = $rep['participation_pct'] >= $rep['chamber_avg_participation'] ? 'green' : 'red';
$loyColor = partyColor($rep['party']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($rep['full_name']) ?> — Scorecard | TPB</title>
<style>
  :root { --bg:#0a0e1a; --card:#141929; --card-hover:#1a2035; --border:#252d44; --text:#e8eaf0; --muted:#8892a8; --accent:#4a9eff; --gold:#f0b429; --green:#34d399; --red:#f87171; --blue-dem:#3b82f6; --red-rep:#ef4444; --purple:#a78bfa; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg); color:var(--text); line-height:1.6; }
  a { color:var(--accent); text-decoration:none; } a:hover { text-decoration:underline; }

  .back-bar { padding:12px 32px; background:#0d1220; border-bottom:1px solid var(--border); font-size:13px; }
  .back-bar a { color:var(--muted); } .back-bar a:hover { color:var(--text); }

  .rep-hero { display:flex; gap:32px; padding:32px; background:linear-gradient(135deg,#0f1628,#1a2544); border-bottom:1px solid var(--border); align-items:flex-start; flex-wrap:wrap; }
  .hero-photo { width:140px; height:175px; border-radius:10px; object-fit:cover; border:3px solid var(--border); }
  .hero-info { flex:1; min-width:250px; }
  .hero-info h1 { font-size:28px; margin-bottom:4px; }
  .hero-info .hero-title { font-size:16px; color:var(--muted); margin-bottom:8px; }
  .rep-party { display:inline-block; font-size:12px; font-weight:600; padding:3px 12px; border-radius:12px; }
  .rep-party.dem { background:rgba(59,130,246,0.2); color:var(--blue-dem); }
  .rep-party.rep { background:rgba(239,68,68,0.2); color:var(--red-rep); }
  .rep-party.ind { background:rgba(167,139,250,0.2); color:var(--purple); }

  .hero-stats { display:flex; gap:24px; margin-top:20px; flex-wrap:wrap; }
  .hero-stat { text-align:center; }
  .hero-stat .hs-value { font-size:32px; font-weight:700; }
  .hero-stat .hs-label { font-size:11px; color:var(--muted); text-transform:uppercase; }
  .hero-stat .hs-rank { font-size:10px; color:var(--muted); }

  .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border); }
  .detail-section { background:var(--card); padding:24px 32px; }
  .detail-section h2 { font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--muted); margin-bottom:16px; }
  .detail-section.full { grid-column:1/-1; }

  .metric { margin-bottom:16px; }
  .metric .metric-header { display:flex; justify-content:space-between; margin-bottom:4px; }
  .metric .metric-label { font-size:12px; color:var(--muted); }
  .metric .metric-value { font-size:16px; font-weight:700; }
  .metric .metric-sub { font-size:11px; color:var(--muted); margin-top:2px; }
  .bar-track { height:8px; background:rgba(255,255,255,0.08); border-radius:4px; overflow:visible; position:relative; }
  .bar-fill { height:100%; border-radius:4px; }
  .bar-fill.green { background:linear-gradient(90deg,#059669,#34d399); }
  .bar-fill.blue { background:linear-gradient(90deg,#2563eb,#60a5fa); }
  .bar-fill.purple { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
  .bar-fill.gold { background:linear-gradient(90deg,#d97706,#f0b429); }
  .bar-fill.red { background:linear-gradient(90deg,#dc2626,#f87171); }
  .bar-track .avg-marker { position:absolute; top:-3px; width:2px; height:14px; background:var(--text); opacity:0.5; border-radius:1px; }

  .vb-bar { height:28px; display:flex; border-radius:6px; overflow:hidden; margin:8px 0; }
  .vb-yea { background:#059669; } .vb-nay { background:#dc2626; } .vb-present { background:#6366f1; } .vb-nv { background:#374151; }
  .vote-legend { display:flex; gap:16px; font-size:12px; color:var(--muted); }
  .vote-legend span::before { content:''; display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:middle; }
  .vote-legend .ly::before { background:#059669; } .vote-legend .ln::before { background:#dc2626; }
  .vote-legend .lp::before { background:#6366f1; } .vote-legend .lnv::before { background:#374151; }

  .comm-list { list-style:none; }
  .comm-list li { padding:6px 0; border-bottom:1px solid rgba(255,255,255,0.05); font-size:13px; }
  .comm-list li:last-child { border:none; }
  .comm-list .role-badge { font-size:10px; font-weight:600; padding:1px 6px; border-radius:8px; margin-left:6px; }
  .comm-list .role-badge.ranking { background:rgba(240,180,41,0.15); color:var(--gold); }
  .comm-list .sub { color:var(--muted); padding-left:16px; font-size:12px; }

  .vote-cards { display:flex; flex-direction:column; gap:2px; }
  .vote-card { display:flex; gap:16px; padding:12px 0; border-bottom:1px solid rgba(255,255,255,0.05); align-items:flex-start; }
  .vote-card:last-child { border:none; }
  .vc-vote { font-size:14px; font-weight:700; min-width:56px; text-align:center; padding:4px 8px; border-radius:6px; }
  .vc-vote.v-yea { color:var(--green); background:rgba(52,211,153,0.1); }
  .vc-vote.v-nay { color:var(--red); background:rgba(248,113,113,0.1); }
  .vc-vote.v-nv { color:var(--muted); background:rgba(255,255,255,0.05); }
  .vc-body { flex:1; min-width:0; }
  .vc-subject { font-size:14px; font-weight:600; color:var(--text); margin-bottom:3px; }
  .vc-subject-link { color:var(--accent); text-decoration:none; transition:color 0.2s; }
  .vc-subject-link:hover { color:var(--gold); text-decoration:underline; }
  .vc-source-link { color:inherit; text-decoration:none; }
  .vc-source-link:hover { color:var(--accent); text-decoration:underline; }
  .vc-procedure { font-size:12px; color:var(--muted); }
  .vc-meta { font-size:11px; color:var(--muted); margin-top:4px; }
  .vr-pass { color:var(--green); }
  .vr-fail { color:var(--red); }

  .bill-list { list-style:none; }
  .bill-list li { padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.05); }
  .bill-list li:last-child { border:none; }
  .bill-list .bill-id { font-weight:600; color:var(--accent); font-size:13px; }
  .bill-list .bill-title { font-size:13px; color:var(--text); margin-top:2px; }
  .bill-list .bill-meta { font-size:11px; color:var(--muted); margin-top:2px; }

  .data-footer { padding:16px 32px; font-size:11px; color:var(--muted); text-align:center; border-top:1px solid var(--border); }

  @media (max-width:768px) { .detail-grid { grid-template-columns:1fr; } .rep-hero { padding:20px; } .hero-photo { width:100px; height:125px; } }
</style>
<?= cg_js() ?>
</head>
<body>
<?php $pageTitle = htmlspecialchars($rep['full_name']) . ' — Scorecard'; require dirname(__DIR__) . '/includes/header.php'; require dirname(__DIR__) . '/includes/nav.php'; ?>

<div class="breadcrumb" style="padding:12px 32px;background:#0d1220;border-bottom:1px solid var(--border);font-size:13px">
  <a href="?" style="color:var(--muted)">Congress</a>
  <span style="margin:0 8px;opacity:0.4">&rsaquo;</span>
  <a href="?state=<?= $stateCode ?>" style="color:var(--muted)"><?= htmlspecialchars($stateName) ?></a>
  <span style="margin:0 8px;opacity:0.4">&rsaquo;</span>
  <span style="color:var(--text)"><?= htmlspecialchars($rep['full_name']) ?></span>
</div>

<div class="rep-hero">
  <img class="hero-photo" src="<?= htmlspecialchars($rep['photo_url'] ?? '') ?>" alt="<?= htmlspecialchars($rep['full_name']) ?>" onerror="this.src='<?= fallbackImg($rep['full_name']) ?>'">
  <div class="hero-info">
    <h1><?= htmlspecialchars($rep['full_name']) ?></h1>
    <div class="hero-title"><?= $titleLine ?> &middot; <?= htmlspecialchars($stateName) ?> &middot; <?= $rep['chamber'] ?></div>
    <span class="rep-party <?= $pClass ?>"><?= partyLabel($rep['party']) ?></span>

    <div class="hero-stats">
      <div class="hero-stat">
        <div class="hs-value" style="color:var(--<?= $partColor ?>)"><?= $rep['participation_pct'] ?>%</div>
        <div class="hs-label"><?= cg('participation') ?></div>
        <div class="hs-rank"><?= cg('chamber rank', '#' . $rep['chamber_rank_participation'] . ' in ' . $rep['chamber']) ?></div>
      </div>
      <div class="hero-stat">
        <div class="hs-value" style="color:var(--<?= $loyColor ?>)"><?= $rep['party_loyalty_pct'] ?>%</div>
        <div class="hs-label"><?= cg('party loyalty') ?></div>
        <div class="hs-rank"><?= cg('chamber rank', '#' . $rep['chamber_rank_loyalty'] . ' in ' . $rep['chamber']) ?></div>
      </div>
      <div class="hero-stat">
        <div class="hs-value" style="color:var(--purple)"><?= $rep['bipartisan_pct'] ?>%</div>
        <div class="hs-label"><?= cg('bipartisanship', 'Bipartisan') ?></div>
        <div class="hs-rank"><?= cg('chamber rank', '#' . $rep['chamber_rank_bipartisan'] . ' in ' . $rep['chamber']) ?></div>
      </div>
      <div class="hero-stat">
        <div class="hs-value" style="color:var(--gold)"><?= $rep['bills_sponsored'] ?></div>
        <div class="hs-label"><?= cg('bills sponsored', 'Bills') ?></div>
        <div class="hs-rank"><?= cg('chamber rank', '#' . $rep['chamber_rank_bills'] . ' in ' . $rep['chamber']) ?></div>
      </div>
      <div class="hero-stat">
        <div class="hs-value"><?= $rep['missed_votes'] ?></div>
        <div class="hs-label"><?= cg('not voting', 'Missed') ?></div>
        <div class="hs-rank">of <?= number_format($total) ?> <?= cg('roll call', 'roll calls') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="detail-grid">
  <!-- VOTE METRICS -->
  <div class="detail-section">
    <h2>Voting Record</h2>
    <div class="metric">
      <div class="metric-header">
        <span class="metric-label"><?= cg('participation', 'Participation Rate') ?></span>
        <span class="metric-value" style="color:var(--<?= $partColor ?>)"><?= $rep['participation_pct'] ?>%</span>
      </div>
      <div class="bar-track">
        <div class="bar-fill <?= $partColor ?>" style="width:<?= $rep['participation_pct'] ?>%"></div>
        <div class="avg-marker" style="left:<?= $rep['chamber_avg_participation'] ?>%" title="<?= $rep['chamber'] ?> avg"></div>
      </div>
      <div class="metric-sub"><?= cg('chamber average', $rep['chamber'] . ' average') ?>: <?= $rep['chamber_avg_participation'] ?>%</div>
    </div>
    <div class="metric">
      <div class="metric-header">
        <span class="metric-label"><?= cg('party loyalty') ?></span>
        <span class="metric-value" style="color:var(--<?= $loyColor ?>)"><?= $rep['party_loyalty_pct'] ?>%</span>
      </div>
      <div class="bar-track">
        <div class="bar-fill <?= partyClass($rep['party']) === 'dem' ? 'blue' : (partyClass($rep['party']) === 'rep' ? 'red' : 'purple') ?>" style="width:<?= $rep['party_loyalty_pct'] ?>%"></div>
        <div class="avg-marker" style="left:<?= $rep['chamber_avg_loyalty'] ?>%"></div>
      </div>
      <div class="metric-sub"><?= cg('chamber average', $rep['chamber'] . ' average') ?>: <?= $rep['chamber_avg_loyalty'] ?>%</div>
    </div>
    <div class="metric">
      <div class="metric-header">
        <span class="metric-label"><?= cg('bipartisanship') ?></span>
        <span class="metric-value" style="color:var(--purple)"><?= $rep['bipartisan_pct'] ?>%</span>
      </div>
      <div class="bar-track">
        <div class="bar-fill purple" style="width:<?= min($rep['bipartisan_pct'], 100) ?>%"></div>
        <div class="avg-marker" style="left:<?= $rep['chamber_avg_bipartisan'] ?>%"></div>
      </div>
      <div class="metric-sub"><?= cg('chamber average', $rep['chamber'] . ' average') ?>: <?= $rep['chamber_avg_bipartisan'] ?>%</div>
    </div>

    <div style="margin-top:20px">
      <div class="metric-label" style="font-size:12px;color:var(--muted);margin-bottom:8px">VOTE BREAKDOWN</div>
      <div class="vb-bar">
        <div class="vb-yea" style="width:<?= $yeaPct ?>%"></div>
        <div class="vb-nay" style="width:<?= $nayPct ?>%"></div>
        <?php if ($rep['present_count'] > 0): ?><div class="vb-present" style="width:<?= round($rep['present_count']/$total*100,1) ?>%"></div><?php endif; ?>
        <div class="vb-nv" style="width:<?= $nvPct ?>%"></div>
      </div>
      <div class="vote-legend">
        <span class="ly"><?= cg('yea') ?> <?= number_format($rep['yea_count']) ?></span>
        <span class="ln"><?= cg('nay') ?> <?= number_format($rep['nay_count']) ?></span>
        <?php if ($rep['present_count'] > 0): ?><span class="lp"><?= cg('present') ?> <?= $rep['present_count'] ?></span><?php endif; ?>
        <span class="lnv"><?= cg('not voting') ?> <?= $rep['missed_votes'] ?></span>
      </div>
    </div>
  </div>

  <!-- COMMITTEES -->
  <div class="detail-section">
    <h2><?= cg('committee', 'Committees') ?> (<?= count($parentComms) ?>) &amp; <?= cg('subcommittee', 'Subcommittees') ?> (<?= count($subComms) ?>)</h2>
    <ul class="comm-list">
      <?php foreach ($parentComms as $comm): ?>
        <li>
          <a href="?committee=<?= $comm['committee_id'] ?>"><?= htmlspecialchars(shortCommittee($comm['name'])) ?></a>
          <?php if ($comm['role'] !== 'Member'): ?>
            <span class="role-badge ranking"><?= cg(strtolower($comm['role']), $comm['role']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if ($subComms): ?>
        <?php foreach ($subComms as $sub): ?>
          <li class="sub">
            <a href="?committee=<?= $sub['committee_id'] ?>"><?= htmlspecialchars(shortCommittee($sub['name'])) ?></a>
            <?php if ($sub['role'] !== 'Member'): ?>
              <span class="role-badge ranking"><?= cg(strtolower($sub['role']), $sub['role']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </div>

  <!-- RECENT VOTES -->
  <div class="detail-section full">
    <h2>Recent Votes (last 20)</h2>
    <div class="vote-cards">
      <?php foreach ($voteList as $v):
        $vc = $v['member_vote'] === 'Yea' ? 'v-yea' : ($v['member_vote'] === 'Nay' ? 'v-nay' : 'v-nv');
        $vq = $v['vote_question'] ?? '';
        // Build the procedural line with glossary tooltips
        $vqDisplay = htmlspecialchars($vq);
        $vqDisplay = preg_replace('/\bOn Passage\b/', cg('on passage', 'On Passage'), $vqDisplay);
        $vqDisplay = preg_replace('/\bCloture\b/', cg('cloture', 'Cloture'), $vqDisplay);
        $vqDisplay = preg_replace('/\bMotion to Proceed\b/', cg('motion to proceed', 'Motion to Proceed'), $vqDisplay);
        $vqDisplay = preg_replace('/\bMotion to Table\b/', cg('motion to table', 'Motion to Table'), $vqDisplay);
        $vqDisplay = preg_replace('/\bMotion to Recommit\b/', cg('motion to recommit', 'Motion to Recommit'), $vqDisplay);
        $vqDisplay = preg_replace('/\bSuspend the Rules\b/', cg('motion to suspend the rules', 'Suspend the Rules'), $vqDisplay);
        $vqDisplay = preg_replace('/\bMotion to Discharge\b/', cg('motion to discharge', 'Motion to Discharge'), $vqDisplay);
        $vqDisplay = preg_replace('/\bPrevious Question\b/', cg('previous question', 'Previous Question'), $vqDisplay);
        $vqDisplay = preg_replace('/\bPoint of Order\b/', cg('point of order', 'Point of Order'), $vqDisplay);
        $vqDisplay = preg_replace('/\bNomination\b/', cg('nomination', 'Nomination'), $vqDisplay);
        $vqDisplay = preg_replace('/\ben bloc\b/i', cg('en bloc', 'En Bloc'), $vqDisplay);
        $vqDisplay = preg_replace('/\bResolution\b/', cg('resolution', 'Resolution'), $vqDisplay);
        // Result with tooltips
        $vr = htmlspecialchars($v['vote_result'] ?? '');
        if (str_contains($vr, '3/5 majority')) $vr = str_replace('3/5 majority required', cg('3/5 majority', '3/5 majority required'), $vr);
      ?>
      <div class="vote-card">
        <div class="vc-vote <?= $vc ?>"><?= $v['member_vote'] ?></div>
        <div class="vc-body">
          <?php if ($v['_subject']): ?>
            <div class="vc-subject"><?php if ($v['_subject_url']): ?><a href="<?= htmlspecialchars($v['_subject_url']) ?>" class="vc-subject-link"><?= htmlspecialchars($v['_subject']) ?></a><?php else: ?><?= htmlspecialchars($v['_subject']) ?><?php endif; ?></div>
          <?php endif; ?>
          <div class="vc-procedure"><?= $vqDisplay ?></div>
          <div class="vc-meta">
            <?= $v['vote_date'] ?> &middot;
            <a href="?vote=<?= $v['vote_id'] ?>" class="vc-source-link"><?= cg('roll call', '#' . $v['roll_call_number']) ?></a>
            &middot;
            <span class="<?= str_contains($vr, 'Agreed') || str_contains($vr, 'Passed') || str_contains($vr, 'Confirmed') ? 'vr-pass' : (str_contains($vr, 'Rejected') || str_contains($vr, 'Failed') || str_contains($vr, 'Defeated') ? 'vr-fail' : '') ?>"><?= $vr ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($billList): ?>
  <!-- BILLS SPONSORED -->
  <div class="detail-section full">
    <h2><?= cg('bills sponsored') ?> (<?= count($billList) ?>)</h2>
    <ul class="bill-list">
      <?php foreach ($billList as $bill):
        $btSlug = strtolower($bill['bill_type']);
      ?>
      <li>
        <a href="?bill=<?= $btSlug ?>-<?= $bill['bill_number'] ?>" class="bill-id"><?= cg($btSlug, strtoupper($bill['bill_type'])) ?> <?= $bill['bill_number'] ?></a>
        <div class="bill-title"><?= htmlspecialchars($bill['short_title'] ?: substr($bill['title'], 0, 120)) ?></div>
        <div class="bill-meta"><?= $bill['last_action_date'] ?> &middot; <?= htmlspecialchars($bill['last_action_text'] ?? '') ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>

<div class="data-footer">
  <a href="?state=<?= $stateCode ?>">&larr; <?= htmlspecialchars($stateName) ?> delegation</a> &middot;
  <?= cg('congress', $congress . 'th Congress') ?> &middot; <strong style="color:var(--gold)">The People's Branch</strong>
</div>

</body>
</html>
<?php exit; endif;

// ═══════════════════════════════════════════
// CONGRESSIONAL DIGEST VIEW
// ═══════════════════════════════════════════
if ($digestView):

// --- Aggregate stats ---
$totalVotes = (int)$pdo->query("SELECT COUNT(*) FROM roll_call_votes WHERE congress = {$congress}")->fetchColumn();
$totalBills = (int)$pdo->query("SELECT COUNT(*) FROM tracked_bills WHERE congress = {$congress}")->fetchColumn();
$totalNoms  = (int)$pdo->query("SELECT COUNT(*) FROM nominations WHERE congress = {$congress}")->fetchColumn();
$totalMembers = (int)$pdo->query("SELECT COUNT(DISTINCT eo.official_id) FROM rep_scorecard rs JOIN elected_officials eo ON rs.official_id = eo.official_id WHERE rs.congress = {$congress}")->fetchColumn();
$latestVote = $pdo->query("SELECT MAX(vote_date) FROM roll_call_votes WHERE congress = {$congress}")->fetchColumn();
$latestBill = $pdo->query("SELECT MAX(last_action_date) FROM tracked_bills WHERE congress = {$congress}")->fetchColumn();

// --- Recent roll call votes with pre-computed party breakdown ---
$recentVotes = $pdo->query("
    SELECT vote_id, vote_date, chamber, vote_question, vote_result,
        yea_total, nay_total, present_total, not_voting_total,
        bill_type, bill_number, roll_call_number,
        r_yea, r_nay, d_yea, d_nay
    FROM roll_call_votes
    ORDER BY vote_date DESC, roll_call_number DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
enrichVotes($recentVotes, $pdo, $congress);
$recentGrouped = groupVotesBySubject($recentVotes);

// --- Close votes (margin <= 5, with pre-computed party breakdown) ---
$closeVotes = $pdo->query("
    SELECT vote_id, vote_date, chamber, vote_question, vote_result,
        yea_total, nay_total, bill_type, bill_number,
        r_yea, r_nay, d_yea, d_nay,
        ABS(yea_total - nay_total) as margin
    FROM roll_call_votes
    WHERE congress = {$congress} AND yea_total > 0 AND nay_total > 0
    ORDER BY margin ASC, vote_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
enrichVotes($closeVotes, $pdo, $congress);
$closeGrouped = groupVotesBySubject($closeVotes);

// --- VP Tiebreakers ---
$vpVotes = $pdo->query("
    SELECT vote_id, vote_date, chamber, vote_question, vote_result, yea_total, nay_total,
        bill_type, bill_number, r_yea, r_nay, d_yea, d_nay
    FROM roll_call_votes
    WHERE congress = {$congress} AND vote_result LIKE '%Vice President%'
    ORDER BY vote_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
enrichVotes($vpVotes, $pdo, $congress);
$vpGrouped = groupVotesBySubject($vpVotes);

// --- Bipartisan votes (both parties >30% yea among those who voted) ---
$bipartisanVotes = $pdo->query("
    SELECT vote_id, vote_date, chamber, vote_question, vote_result,
        bill_type, bill_number, r_yea, r_nay, d_yea, d_nay
    FROM roll_call_votes
    WHERE congress = {$congress}
        AND r_yea > (r_yea+r_nay)*0.3
        AND d_yea > (d_yea+d_nay)*0.3
        AND (r_yea+r_nay) > 50
    ORDER BY vote_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
enrichVotes($bipartisanVotes, $pdo, $congress);
$bipartisanGrouped = groupVotesBySubject($bipartisanVotes);

// --- Most voted-on bills ---
$hotBills = $pdo->query("
    SELECT rv.bill_type, rv.bill_number, COUNT(*) as vote_cnt,
        tb.title, tb.short_title, tb.sponsor_name, tb.last_action_date, tb.last_action_text
    FROM roll_call_votes rv
    LEFT JOIN tracked_bills tb ON rv.bill_type=tb.bill_type AND rv.bill_number=tb.bill_number AND rv.congress=tb.congress
    WHERE rv.congress = {$congress} AND rv.bill_type IS NOT NULL
    GROUP BY rv.bill_type, rv.bill_number
    ORDER BY vote_cnt DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// --- Recent nominations ---
$recentNoms = $pdo->query("
    SELECT nomination_number, description, received_date, latest_action_text, latest_action_date, organization
    FROM nominations
    WHERE congress = {$congress}
    ORDER BY received_date DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// --- Confirmed nominations (from roll call votes) ---
$confirmedNoms = $pdo->query("
    SELECT vote_id, vote_date, chamber, vote_question, vote_result, yea_total, nay_total,
        bill_type, bill_number, r_yea, r_nay, d_yea, d_nay
    FROM roll_call_votes
    WHERE congress = {$congress} AND vote_result LIKE '%Confirmed%'
    ORDER BY vote_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
enrichVotes($confirmedNoms, $pdo, $congress);
$confirmedGrouped = groupVotesBySubject($confirmedNoms);

// --- Top participants ---
$topParticipants = $pdo->query("
    SELECT eo.full_name, eo.party, eo.state_code, eo.photo_url, eo.official_id,
        rs.votes_cast, rs.participation_pct, rs.chamber
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = {$congress}
    ORDER BY rs.participation_pct DESC, rs.votes_cast DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// --- Top bill sponsors ---
$topSponsors = $pdo->query("
    SELECT eo.full_name, eo.party, eo.state_code, eo.photo_url, eo.official_id,
        rs.bills_sponsored, rs.chamber
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = {$congress} AND rs.bills_sponsored > 0
    ORDER BY rs.bills_sponsored DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// --- Chamber monthly activity ---
$monthlyActivity = $pdo->query("
    SELECT DATE_FORMAT(vote_date, '%Y-%m') as month,
        SUM(CASE WHEN chamber='House' THEN 1 ELSE 0 END) as house_cnt,
        SUM(CASE WHEN chamber='Senate' THEN 1 ELSE 0 END) as senate_cnt
    FROM roll_call_votes
    WHERE congress = {$congress}
    GROUP BY month
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Find max for scaling bars
$maxMonthly = max(array_map(fn($m) => max($m['house_cnt'], $m['senate_cnt']), $monthlyActivity) ?: [1]);

pageHead("Congress — {$congress}th Congress");
?>

<div class="page-hero">
  <h1>Congressional Digest</h1>
  <p class="subtitle"><?= cg('congress', $congress . 'th Congress') ?> &mdash; What's happening on Capitol Hill</p>
</div>

<!-- Overview Stats -->
<div class="detail-grid">
  <div class="detail-section full">
    <h2>At a Glance</h2>
    <div class="stat-row" style="justify-content:center;">
      <div class="stat-box"><div class="sv" style="color:var(--accent)"><?= number_format($totalVotes) ?></div><div class="sl"><?= cg('roll call', 'Roll Call Votes') ?></div></div>
      <div class="stat-box"><div class="sv" style="color:var(--green)"><?= number_format($totalBills) ?></div><div class="sl"><?= cg('bill', 'Bills') ?> Tracked</div></div>
      <div class="stat-box"><div class="sv" style="color:var(--gold)"><?= number_format($totalNoms) ?></div><div class="sl"><?= cg('nomination', 'Nominations') ?></div></div>
      <div class="stat-box"><div class="sv"><?= number_format($totalMembers) ?></div><div class="sl">Members</div></div>
    </div>
    <p style="text-align:center; font-size:12px; color:var(--muted); margin-top:8px;">
      Latest vote: <?= $latestVote ?> &middot; Latest bill action: <?= $latestBill ?>
    </p>
  </div>
</div>

<!-- Chamber Activity Timeline -->
<div class="detail-grid">
  <div class="detail-section full">
    <h2>Chamber Activity by Month</h2>
    <div style="display:flex; gap:2px; align-items:end; height:120px; margin:16px 0;">
      <?php foreach ($monthlyActivity as $m):
        $hPct = $maxMonthly > 0 ? ($m['house_cnt'] / $maxMonthly * 100) : 0;
        $sPct = $maxMonthly > 0 ? ($m['senate_cnt'] / $maxMonthly * 100) : 0;
        $monthLabel = date('M', strtotime($m['month'] . '-01'));
      ?>
      <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:1px;">
        <div style="display:flex; gap:1px; align-items:end; height:100px; width:100%;">
          <div style="flex:1; background:var(--blue-dem); height:<?= max(2, $hPct) ?>%; border-radius:2px 2px 0 0; opacity:0.8;" title="House: <?= $m['house_cnt'] ?>"></div>
          <div style="flex:1; background:var(--red-rep); height:<?= max(2, $sPct) ?>%; border-radius:2px 2px 0 0; opacity:0.8;" title="Senate: <?= $m['senate_cnt'] ?>"></div>
        </div>
        <div style="font-size:9px; color:var(--muted); white-space:nowrap;"><?= $monthLabel ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="font-size:11px; color:var(--muted); text-align:center;">
      <span style="display:inline-block; width:10px; height:10px; background:var(--blue-dem); border-radius:2px; vertical-align:middle; opacity:0.8;"></span> House
      &nbsp;&nbsp;
      <span style="display:inline-block; width:10px; height:10px; background:var(--red-rep); border-radius:2px; vertical-align:middle; opacity:0.8;"></span> Senate
    </div>
  </div>
</div>

<!-- Recent Floor Action — grouped by bill/nomination -->
<div class="detail-grid">
  <div class="detail-section full">
    <h2>Recent Floor Action</h2>
    <?php foreach ($recentGrouped as $g):
      $passed = stripos($g['final_result'], 'Passed') !== false || stripos($g['final_result'], 'Agreed') !== false || stripos($g['final_result'], 'Confirmed') !== false;
      $cnt = count($g['votes']);
      $subjectText = substr($g['subject'], 0, 100);
      $subject = htmlspecialchars($subjectText);
      if (strlen($g['subject']) > 100) $subject .= '&hellip;';
      $hover = $g['full_title'];
      // Party-line vs bipartisan from the final vote's tallies
      $rTotal = ($g['r_yea'] ?: 0) + ($g['r_nay'] ?: 0);
      $dTotal = ($g['d_yea'] ?: 0) + ($g['d_nay'] ?: 0);
      $rYeaPct = $rTotal > 0 ? $g['r_yea'] / $rTotal : 0;
      $dYeaPct = $dTotal > 0 ? $g['d_yea'] / $dTotal : 0;
      $isPartyLine = ($rYeaPct > 0.9 && $dYeaPct < 0.1) || ($dYeaPct > 0.9 && $rYeaPct < 0.1);
      $isBipartisan = $rYeaPct > 0.3 && $dYeaPct > 0.3 && $rTotal > 50;
      // Link to the final vote, or the bill detail if available
      $linkUrl = $g['subject_url'] ?: '?vote=' . $g['votes'][0]['vote_id'];
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04); display:flex; align-items:baseline; gap:10px;">
      <span class="tag <?= $g['chamber']==='Senate' ? 'tag-rep' : 'tag-dem' ?>" style="flex-shrink:0; font-size:10px;"><?= substr($g['chamber'],0,1) ?></span>
      <div style="flex:1; min-width:0;">
        <a href="<?= htmlspecialchars($linkUrl) ?>" style="font-size:13px;"<?= $hover ? ' title="' . htmlspecialchars($hover) . '"' : '' ?>><?= $subject ?></a>
        <div style="font-size:11px; color:var(--muted); margin-top:2px;">
          <span class="tag <?= $passed ? 'tag-pass' : 'tag-fail' ?>" style="font-size:10px;"><?= $passed ? 'Passed' : 'Failed' ?></span>
          <?php if ($cnt > 1): ?>
            <span style="font-size:10px; margin-left:4px;"><?= $cnt ?> roll calls</span>
          <?php endif; ?>
          <?php if ($isPartyLine): ?>
            <span style="color:var(--red); font-size:10px; margin-left:4px;">Party-line</span>
          <?php elseif ($isBipartisan): ?>
            <span style="color:var(--green); font-size:10px; margin-left:4px;">Bipartisan</span>
          <?php endif; ?>
          <span style="color:var(--muted); font-size:10px; margin-left:4px;"><?= $g['last_date'] ?></span>
        </div>
      </div>
      <div style="flex-shrink:0; text-align:right; font-size:11px; white-space:nowrap;">
        <?php if ($g['r_yea'] || $g['r_nay']): ?>
          <span style="color:var(--red-rep)">R</span> <span style="color:var(--green)"><?= $g['r_yea'] ?></span>-<span style="color:var(--red)"><?= $g['r_nay'] ?></span>
          &nbsp;
          <span style="color:var(--blue-dem)">D</span> <span style="color:var(--green)"><?= $g['d_yea'] ?></span>-<span style="color:var(--red)"><?= $g['d_nay'] ?></span>
        <?php else: ?>
          <span style="color:var(--muted)">Voice</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Hot Bills + Close Votes side by side -->
<div class="detail-grid">
  <div class="detail-section">
    <h2>Hot Bills (Most Votes)</h2>
    <?php foreach ($hotBills as $hb):
      $shortTitle = $hb['short_title'] ?: substr($hb['title'] ?? '(untitled)', 0, 60);
      if (strlen($hb['title'] ?? '') > 60 && !$hb['short_title']) $shortTitle .= '&hellip;';
      $fullTitle = $hb['title'] ?? '';
      $slug = $hb['bill_type'] . '-' . $hb['bill_number'];
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <a href="?bill=<?= $slug ?>" style="font-weight:600; font-size:13px;"<?= $fullTitle ? ' title="' . htmlspecialchars($fullTitle) . '"' : '' ?>>
        <?= strtoupper($hb['bill_type']) ?> <?= $hb['bill_number'] ?>: <?= htmlspecialchars($shortTitle) ?>
      </a>
      <span class="tag tag-gold" style="margin-left:8px;"><?= $hb['vote_cnt'] ?> votes</span>
      <?php if ($hb['sponsor_name']): ?>
        <div style="font-size:11px; color:var(--muted); margin-top:2px;">Sponsor: <?= htmlspecialchars($hb['sponsor_name']) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="detail-section">
    <h2>Close Votes (Tightest Margins)</h2>
    <?php foreach ($closeGrouped as $g):
      $passed = stripos($g['final_result'], 'Passed') !== false || stripos($g['final_result'], 'Agreed') !== false || stripos($g['final_result'], 'Confirmed') !== false;
      $subject = htmlspecialchars(substr($g['subject'], 0, 80));
      if (strlen($g['subject']) > 80) $subject .= '&hellip;';
      $cnt = count($g['votes']);
      $linkUrl = $g['subject_url'] ?: '?vote=' . $g['votes'][0]['vote_id'];
      $hasVP = false;
      foreach ($g['votes'] as $v) { if (stripos($v['vote_result'], 'Vice President') !== false) { $hasVP = true; break; } }
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <div style="display:flex; align-items:baseline; gap:8px;">
        <span class="tag <?= $g['yea_total'] == $g['nay_total'] ? 'tag-gold' : 'tag-nv' ?>" style="font-size:10px; flex-shrink:0;">
          <?= $g['yea_total'] ?>-<?= $g['nay_total'] ?>
        </span>
        <a href="<?= htmlspecialchars($linkUrl) ?>" style="font-size:13px;"<?= $g['full_title'] ? ' title="' . htmlspecialchars($g['full_title']) . '"' : '' ?>><?= $subject ?></a>
      </div>
      <div style="font-size:11px; color:var(--muted); margin-top:3px; padding-left:48px;">
        <span class="tag <?= $passed ? 'tag-pass' : 'tag-fail' ?>" style="font-size:10px;"><?= $passed ? 'Passed' : 'Failed' ?></span>
        <?php if ($cnt > 1): ?><span style="font-size:10px; margin-left:4px;"><?= $cnt ?> roll calls</span><?php endif; ?>
        <span class="tag <?= $g['chamber']==='Senate' ? 'tag-rep' : 'tag-dem' ?>" style="font-size:10px; margin-left:4px;"><?= $g['chamber'] ?></span>
        <?php if ($hasVP): ?>
          <span style="color:var(--gold); font-size:10px; margin-left:4px;">VP tiebreaker</span>
        <?php endif; ?>
        <span style="margin-left:4px;"><?= $g['last_date'] ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Bipartisan + VP Tiebreakers -->
<div class="detail-grid">
  <div class="detail-section">
    <h2>Bipartisan Votes</h2>
    <p style="font-size:11px; color:var(--muted); margin-bottom:12px;">Votes where both parties had &gt;30% support</p>
    <?php if (empty($bipartisanGrouped)): ?>
      <p style="color:var(--muted); font-size:13px;">No qualifying bipartisan votes found.</p>
    <?php else: foreach ($bipartisanGrouped as $g):
      $rTotal = ($g['r_yea'] ?: 0) + ($g['r_nay'] ?: 0);
      $dTotal = ($g['d_yea'] ?: 0) + ($g['d_nay'] ?: 0);
      $rPct = $rTotal > 0 ? round($g['r_yea']/$rTotal*100) : 0;
      $dPct = $dTotal > 0 ? round($g['d_yea']/$dTotal*100) : 0;
      $subject = htmlspecialchars(substr($g['subject'], 0, 70));
      if (strlen($g['subject']) > 70) $subject .= '&hellip;';
      $cnt = count($g['votes']);
      $linkUrl = $g['subject_url'] ?: '?vote=' . $g['votes'][0]['vote_id'];
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <a href="<?= htmlspecialchars($linkUrl) ?>" style="font-size:13px;"<?= $g['full_title'] ? ' title="' . htmlspecialchars($g['full_title']) . '"' : '' ?>><?= $subject ?></a>
      <div style="margin-top:4px; font-size:11px;">
        <span class="tag tag-rep" style="font-size:10px;">R: <?= $rPct ?>% Yea</span>
        <span class="tag tag-dem" style="font-size:10px;">D: <?= $dPct ?>% Yea</span>
        <?php if ($cnt > 1): ?><span style="font-size:10px; margin-left:4px;"><?= $cnt ?> roll calls</span><?php endif; ?>
        <span style="color:var(--muted); margin-left:4px;"><?= $g['last_date'] ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <div class="detail-section">
    <h2>VP Tiebreakers</h2>
    <p style="font-size:11px; color:var(--muted); margin-bottom:12px;">Votes decided by the Vice President</p>
    <?php if (empty($vpGrouped)): ?>
      <p style="color:var(--muted); font-size:13px;">No VP tiebreaker votes this Congress.</p>
    <?php else: foreach ($vpGrouped as $g):
      $passed = stripos($g['final_result'], 'Agreed') !== false || stripos($g['final_result'], 'Passed') !== false || stripos($g['final_result'], 'Confirmed') !== false;
      $subject = htmlspecialchars(substr($g['subject'], 0, 70));
      if (strlen($g['subject']) > 70) $subject .= '&hellip;';
      $cnt = count($g['votes']);
      $linkUrl = $g['subject_url'] ?: '?vote=' . $g['votes'][0]['vote_id'];
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <a href="<?= htmlspecialchars($linkUrl) ?>" style="font-size:13px;"<?= $g['full_title'] ? ' title="' . htmlspecialchars($g['full_title']) . '"' : '' ?>><?= $subject ?></a>
      <div style="margin-top:4px; font-size:11px;">
        <span class="tag tag-gold" style="font-size:10px;"><?= $g['yea_total'] ?>-<?= $g['nay_total'] ?></span>
        <span class="tag <?= $passed ? 'tag-pass' : 'tag-fail' ?>" style="font-size:10px;"><?= $passed ? 'Passed' : 'Failed' ?></span>
        <?php if ($cnt > 1): ?><span style="font-size:10px; margin-left:4px;"><?= $cnt ?> roll calls</span><?php endif; ?>
        <span style="color:var(--muted); margin-left:4px;"><?= $g['last_date'] ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Nominations -->
<div class="detail-grid">
  <div class="detail-section">
    <h2>Recent Nominations</h2>
    <?php foreach ($recentNoms as $rn): ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <a href="?nom=<?= urlencode($rn['nomination_number']) ?>" style="font-size:13px; font-weight:600;">
        PN<?= htmlspecialchars($rn['nomination_number']) ?>
      </a>
      <span style="font-size:11px; color:var(--muted); margin-left:8px;"><?= $rn['received_date'] ?></span>
      <?php if ($rn['description']): ?>
        <div style="font-size:12px; color:var(--text); margin-top:2px;"><?= htmlspecialchars(substr($rn['description'], 0, 100)) ?><?= strlen($rn['description']) > 100 ? '&hellip;' : '' ?></div>
      <?php endif; ?>
      <?php if ($rn['latest_action_text']): ?>
        <div style="font-size:11px; color:var(--muted); margin-top:2px;"><?= htmlspecialchars(substr($rn['latest_action_text'], 0, 80)) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="detail-section">
    <h2>Confirmed by the Senate</h2>
    <?php if (empty($confirmedGrouped)): ?>
      <p style="color:var(--muted); font-size:13px;">No confirmed nominations with roll call votes yet.</p>
    <?php else: foreach ($confirmedGrouped as $g):
      $subject = htmlspecialchars(substr($g['subject'], 0, 80));
      if (strlen($g['subject']) > 80) $subject .= '&hellip;';
      $linkUrl = $g['subject_url'] ?: '?vote=' . $g['votes'][0]['vote_id'];
    ?>
    <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.04);">
      <a href="<?= htmlspecialchars($linkUrl) ?>" style="font-size:13px;"<?= $g['full_title'] ? ' title="' . htmlspecialchars($g['full_title']) . '"' : '' ?>><?= $subject ?></a>
      <div style="font-size:11px; margin-top:3px;">
        <span class="tag tag-pass" style="font-size:10px;">Confirmed</span>
        <span class="tag tag-gold" style="font-size:10px;"><?= $g['yea_total'] ?>-<?= $g['nay_total'] ?></span>
        <span style="color:var(--muted); margin-left:4px;"><?= $g['last_date'] ?></span>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Top Members -->
<div class="detail-grid">
  <div class="detail-section">
    <h2>Most Active (Participation)</h2>
    <table class="data-tbl">
      <tr><th></th><th>Member</th><th>Party</th><th>Votes</th><th>Rate</th></tr>
      <?php foreach ($topParticipants as $i => $tp): ?>
      <tr>
        <td style="font-size:11px; color:var(--muted)"><?= $i+1 ?></td>
        <td>
          <a href="?rep=<?= $tp['official_id'] ?>"><?= htmlspecialchars($tp['full_name']) ?></a>
          <span style="font-size:11px; color:var(--muted)">(<?= $tp['state_code'] ?>)</span>
        </td>
        <td><span class="tag tag-<?= partyClass($tp['party']) ?>"><?= substr($tp['party'],0,1) ?></span></td>
        <td><?= number_format($tp['votes_cast']) ?></td>
        <td style="color:var(--green)"><?= $tp['participation_pct'] ?>%</td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="detail-section">
    <h2>Top Bill Sponsors</h2>
    <table class="data-tbl">
      <tr><th></th><th>Member</th><th>Party</th><th>Bills</th></tr>
      <?php foreach ($topSponsors as $i => $ts): ?>
      <tr>
        <td style="font-size:11px; color:var(--muted)"><?= $i+1 ?></td>
        <td>
          <a href="?rep=<?= $ts['official_id'] ?>"><?= htmlspecialchars($ts['full_name']) ?></a>
          <span style="font-size:11px; color:var(--muted)">(<?= $ts['state_code'] ?>)</span>
        </td>
        <td><span class="tag tag-<?= partyClass($ts['party']) ?>"><?= substr($ts['party'],0,1) ?></span></td>
        <td style="color:var(--gold)"><?= $ts['bills_sponsored'] ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<!-- Browse by State -->
<div class="detail-grid">
  <div class="detail-section full">
    <h2>Browse by State</h2>
    <div style="display:flex; flex-wrap:wrap; gap:4px; justify-content:center;">
      <?php
      $states = $pdo->query("SELECT DISTINCT eo.state_code FROM rep_scorecard rs JOIN elected_officials eo ON rs.official_id = eo.official_id WHERE rs.congress = {$congress} ORDER BY eo.state_code")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($states as $st):
      ?>
        <a href="?state=<?= $st ?>" style="display:inline-block; padding:4px 10px; background:var(--card-hover); border:1px solid var(--border); border-radius:4px; font-size:12px; font-weight:600; color:var(--text);"><?= $st ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div style="text-align:center; padding:24px; font-size:12px; color:var(--muted);">
  <a href="glossary.php" style="color:var(--gold); font-weight:600;">Congressional Glossary</a> &mdash; <?= count(congressionalGlossary()) ?> terms in plain English
  <br>
  <?= cg('congress', $congress . 'th Congress') ?> &middot;
  <strong style="color:var(--gold)">The People's Branch</strong>
</div>

</body></html>
<?php exit; endif;

// ═══════════════════════════════════════════
// STATE VIEW (existing code, with clickable names/photos)
// ═══════════════════════════════════════════
$stateCode = strtoupper($stateParam ?? 'CT');

$stateName = $pdo->prepare("SELECT state_name FROM states WHERE abbreviation = ?");
$stateName->execute([$stateCode]);
$stateName = $stateName->fetchColumn() ?: $stateCode;

$q = $pdo->prepare("
    SELECT eo.full_name, eo.party, eo.title, eo.office_name, eo.photo_url, eo.bioguide_id, eo.official_id,
        rs.chamber, rs.participation_pct, rs.party_loyalty_pct, rs.bipartisan_pct,
        rs.total_roll_calls, rs.votes_cast, rs.missed_votes,
        rs.yea_count, rs.nay_count, rs.present_count,
        rs.bills_sponsored, rs.bills_substantive, rs.bills_resolutions, rs.amendments_sponsored,
        rs.chamber_rank_participation, rs.chamber_rank_loyalty, rs.chamber_rank_bipartisan, rs.chamber_rank_bills,
        rs.state_rank_participation,
        rs.chamber_avg_participation, rs.chamber_avg_loyalty, rs.chamber_avg_bipartisan
    FROM rep_scorecard rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE rs.congress = ? AND eo.state_code = ?
    ORDER BY FIELD(rs.chamber, 'Senate', 'House'), eo.full_name
");
$q->execute([$congress, $stateCode]);
$reps = $q->fetchAll(PDO::FETCH_ASSOC);

if (!$reps) die("<h1>No data for $stateCode in Congress $congress</h1>");

$commQ = $pdo->prepare("SELECT c.committee_id, c.name, cm.role FROM committee_memberships cm
    JOIN committees c ON cm.committee_id = c.committee_id
    WHERE cm.official_id = ? AND c.parent_id IS NULL ORDER BY cm.role DESC, c.name");
$subQ = $pdo->prepare("SELECT COUNT(*) FROM committee_memberships cm
    JOIN committees c ON cm.committee_id = c.committee_id WHERE cm.official_id = ? AND c.parent_id IS NOT NULL");

$senators = array_filter($reps, fn($r) => $r['chamber'] === 'Senate');
$house = array_filter($reps, fn($r) => $r['chamber'] === 'House');
$avgPart = count($reps) > 0 ? round(array_sum(array_column($reps, 'participation_pct')) / count($reps), 1) : 0;
$avgLoyalty = count($reps) > 0 ? round(array_sum(array_column($reps, 'party_loyalty_pct')) / count($reps), 1) : 0;
$avgBipart = count($reps) > 0 ? round(array_sum(array_column($reps, 'bipartisan_pct')) / count($reps), 1) : 0;
$totalBills = array_sum(array_column($reps, 'bills_sponsored'));
$totalSubstantive = array_sum(array_column($reps, 'bills_substantive'));
$totalVotesCast = array_sum(array_column($reps, 'votes_cast'));
$totalPossible = 0;
foreach ($reps as $r) $totalPossible += $r['total_roll_calls'];

$partyCounts = [];
foreach ($reps as $r) { $p = substr($r['party'],0,1); $partyCounts[$p] = ($partyCounts[$p] ?? 0) + 1; }
arsort($partyCounts);
$flagColor = key($partyCounts) === 'D' ? '#3b82f6' : (key($partyCounts) === 'R' ? '#ef4444' : '#a78bfa');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($stateName) ?> — Federal Delegation Scorecard | TPB</title>
<style>
  :root { --bg:#0a0e1a; --card:#141929; --card-hover:#1a2035; --border:#252d44; --text:#e8eaf0; --muted:#8892a8; --accent:#4a9eff; --gold:#f0b429; --green:#34d399; --red:#f87171; --blue-dem:#3b82f6; --red-rep:#ef4444; --purple:#a78bfa; }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:var(--bg); color:var(--text); line-height:1.5; }
  a { color:inherit; text-decoration:none; }

  .state-header { background:linear-gradient(135deg,#0f1628,#1a2544); border-bottom:1px solid var(--border); padding:24px 32px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  .state-flag { width:60px; height:40px; border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:700; color:#fff; letter-spacing:-1px; }
  .state-header h1 { font-size:24px; font-weight:600; }
  .state-header h1 span { color:var(--muted); font-weight:400; font-size:16px; margin-left:12px; }
  .state-meta { margin-left:auto; display:flex; gap:24px; font-size:13px; color:var(--muted); }
  .state-meta strong { color:var(--text); font-size:18px; display:block; }

  .mode-tabs { display:flex; gap:2px; padding:0 32px; background:#0d1220; border-bottom:1px solid var(--border); }
  .mode-tab { padding:12px 20px; font-size:13px; font-weight:500; color:var(--muted); cursor:pointer; border-bottom:2px solid transparent; transition:all 0.2s; }
  .mode-tab:hover { color:var(--text); }
  .mode-tab.active { color:var(--gold); border-bottom-color:var(--gold); }

  .summary-strip { display:flex; gap:16px; padding:20px 32px; background:#0d1220; border-bottom:1px solid var(--border); flex-wrap:wrap; }
  .summary-card { flex:1; min-width:140px; background:var(--card); border:1px solid var(--border); border-radius:8px; padding:14px 18px; text-align:center; }
  .summary-card .label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; }
  .summary-card .value { font-size:26px; font-weight:700; margin-top:4px; }
  .summary-card .sub { font-size:11px; color:var(--muted); margin-top:2px; }

  .section-label { padding:16px 32px 8px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:1px; color:var(--muted); border-top:1px solid var(--border); }
  .reps-grid { display:grid; grid-template-columns:1fr; gap:1px; background:var(--border); }

  .rep-card { background:var(--card); padding:24px 32px; display:grid; grid-template-columns:64px 200px 1fr 1fr; gap:24px; align-items:start; transition:background 0.2s; }
  .rep-card:hover { background:var(--card-hover); }
  .rep-photo { width:64px; height:80px; border-radius:6px; object-fit:cover; border:2px solid var(--border); cursor:pointer; transition:border-color 0.2s; }
  .rep-photo:hover { border-color:var(--accent); }
  .rep-info h3 { font-size:16px; font-weight:600; margin-bottom:2px; }
  .rep-info h3 a { color:var(--text); transition:color 0.2s; }
  .rep-info h3 a:hover { color:var(--accent); }
  .rep-info .rep-title { font-size:13px; color:var(--muted); }
  .rep-party { display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; margin-top:6px; }
  .rep-party.dem { background:rgba(59,130,246,0.2); color:var(--blue-dem); }
  .rep-party.rep { background:rgba(239,68,68,0.2); color:var(--red-rep); }
  .rep-party.ind { background:rgba(167,139,250,0.2); color:var(--purple); }
  .rep-committees { margin-top:8px; }
  .comm { display:inline-block; background:rgba(255,255,255,0.05); padding:2px 8px; border-radius:4px; margin:2px 2px 2px 0; font-size:11px; color:var(--muted); }
  .comm.ranking { background:rgba(240,180,41,0.15); color:var(--gold); }

  .metrics-col { display:flex; flex-direction:column; gap:12px; }
  .metric { display:flex; flex-direction:column; gap:4px; }
  .metric .metric-header { display:flex; justify-content:space-between; align-items:baseline; }
  .metric .metric-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:0.3px; }
  .metric .metric-value { font-size:14px; font-weight:700; }
  .metric .metric-sub { font-size:10px; color:var(--muted); }
  .bar-track { height:6px; background:rgba(255,255,255,0.08); border-radius:3px; overflow:visible; position:relative; }
  .bar-fill { height:100%; border-radius:3px; transition:width 0.8s ease; }
  .bar-fill.green { background:linear-gradient(90deg,#059669,#34d399); }
  .bar-fill.blue { background:linear-gradient(90deg,#2563eb,#60a5fa); }
  .bar-fill.purple { background:linear-gradient(90deg,#7c3aed,#a78bfa); }
  .bar-fill.gold { background:linear-gradient(90deg,#d97706,#f0b429); }
  .bar-fill.red { background:linear-gradient(90deg,#dc2626,#f87171); }
  .bar-track .avg-marker { position:absolute; top:-2px; width:2px; height:10px; background:var(--text); opacity:0.5; border-radius:1px; }

  .vote-breakdown { display:flex; gap:6px; align-items:center; font-size:11px; }
  .vb-bar { flex:1; height:20px; display:flex; border-radius:4px; overflow:hidden; }
  .vb-yea { background:#059669; } .vb-nay { background:#dc2626; } .vb-present { background:#6366f1; } .vb-nv { background:#374151; }
  .vote-legend { display:flex; gap:12px; font-size:10px; color:var(--muted); margin-top:2px; }
  .vote-legend span::before { content:''; display:inline-block; width:8px; height:8px; border-radius:2px; margin-right:4px; vertical-align:middle; }
  .vote-legend .ly::before { background:#059669; } .vote-legend .ln::before { background:#dc2626; }
  .vote-legend .lp::before { background:#6366f1; } .vote-legend .lnv::before { background:#374151; }

  .data-footer { padding:16px 32px; font-size:11px; color:var(--muted); text-align:center; border-top:1px solid var(--border); }
  .data-footer a { color:var(--accent); }
</style>
<?= cg_js() ?>
<style>
  /* Photo lightbox */
  .lightbox { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center; cursor:pointer; }
  .lightbox.active { display:flex; }
  .lightbox img { max-width:400px; max-height:80vh; border-radius:12px; border:3px solid var(--border); }

  @media (max-width:900px) {
    .rep-card { grid-template-columns:56px 1fr; gap:12px; }
    .metrics-col { grid-column:1/-1; }
    .state-meta { margin-left:0; width:100%; }
  }
</style>
</head>
<body>
<?php $pageTitle = htmlspecialchars($stateName) . ' — Delegation'; require dirname(__DIR__) . '/includes/header.php'; require dirname(__DIR__) . '/includes/nav.php'; ?>

<div style="padding:12px 32px; background:#0d1220; border-bottom:1px solid var(--border); font-size:13px; color:var(--muted);">
  <a href="?" style="color:var(--muted)">Congress</a>
  <span style="margin:0 8px; opacity:0.4">&rsaquo;</span>
  <span style="color:var(--text)"><?= htmlspecialchars($stateName) ?></span>
</div>

<div class="state-header">
  <div class="state-flag" style="background:<?= $flagColor ?>"><?= $stateCode ?></div>
  <h1><?= htmlspecialchars($stateName) ?> <span>Federal Delegation — <?= cg('congress', $congress . 'th Congress') ?></span></h1>
  <div class="state-meta">
    <div><strong><?= count($reps) ?></strong>Members</div>
    <div><strong><?= count($senators) ?></strong>Senators</div>
    <div><strong><?= count($house) ?></strong>Reps</div>
  </div>
</div>

<div class="mode-tabs">
  <div class="mode-tab">Overview</div>
  <div class="mode-tab active">Scorecard</div>
  <div class="mode-tab">Votes</div>
  <div class="mode-tab">Bills</div>
  <div class="mode-tab">Committees</div>
</div>

<div class="summary-strip">
  <div class="summary-card"><div class="label">Avg <?= cg('participation') ?></div><div class="value" style="color:var(--green)"><?= $avgPart ?>%</div><div class="sub">National avg: ~96.7%</div></div>
  <div class="summary-card"><div class="label">Avg <?= cg('party loyalty') ?></div><div class="value" style="color:var(--blue-dem)"><?= $avgLoyalty ?>%</div></div>
  <div class="summary-card"><div class="label">Avg <?= cg('bipartisanship') ?></div><div class="value" style="color:var(--purple)"><?= $avgBipart ?>%</div></div>
  <div class="summary-card"><div class="label"><?= cg('bills sponsored') ?></div><div class="value" style="color:var(--gold)"><?= $totalBills ?></div><div class="sub"><?= $totalSubstantive ?> <?= cg('substantive') ?></div></div>
  <div class="summary-card"><div class="label">Votes Cast</div><div class="value"><?= number_format($totalVotesCast) ?></div><div class="sub">of <?= number_format($totalPossible) ?> possible</div></div>
</div>

<?php
$currentChamber = '';
foreach ($reps as $rep):
    if ($rep['chamber'] !== $currentChamber):
        if ($currentChamber) echo '</div>';
        $currentChamber = $rep['chamber'];
        echo '<div class="section-label">U.S. ' . ($currentChamber === 'Senate' ? 'Senators' : 'Representatives') . '</div>';
        echo '<div class="reps-grid">';
    endif;

    $commQ->execute([$rep['official_id']]);
    $committees = $commQ->fetchAll(PDO::FETCH_ASSOC);
    $subQ->execute([$rep['official_id']]);
    $subCount = $subQ->fetchColumn();

    $dist = district($rep['office_name']);
    $titleLine = $rep['chamber'] === 'House' && $dist ? "$stateCode-$dist &middot; U.S. Representative" : "U.S. Senator";
    $pClass = partyClass($rep['party']);
    $partColor = $rep['participation_pct'] >= $rep['chamber_avg_participation'] ? 'green' : ($rep['participation_pct'] >= $rep['chamber_avg_participation'] - 3 ? 'gold' : 'red');
    $partNote = $rep['participation_pct'] >= $rep['chamber_avg_participation'] ? '' : ' (below avg)';
    $loyColor = $pClass === 'dem' ? 'blue' : ($pClass === 'rep' ? 'red' : 'purple');
    $total = $rep['total_roll_calls'];
    $yeaPct = $total > 0 ? round($rep['yea_count']/$total*100,1) : 0;
    $nayPct = $total > 0 ? round($rep['nay_count']/$total*100,1) : 0;
    $presPct = $total > 0 ? round($rep['present_count']/$total*100,1) : 0;
    $nvPct  = $total > 0 ? round($rep['missed_votes']/$total*100,1) : 0;
    $detailUrl = "?rep={$rep['official_id']}";
    $photoUrl = htmlspecialchars($rep['photo_url'] ?? '');
    $fb = fallbackImg($rep['full_name']);
    $commShort = shortCommittee('');
?>
  <div class="rep-card">
    <img class="rep-photo" src="<?= $photoUrl ?>" alt="<?= htmlspecialchars($rep['full_name']) ?>" onclick="openLightbox(this.src, '<?= htmlspecialchars(addslashes($rep['full_name'])) ?>')" onerror="this.src='<?= $fb ?>'">
    <div class="rep-info">
      <h3><a href="<?= $detailUrl ?>"><?= htmlspecialchars($rep['full_name']) ?></a></h3>
      <div class="rep-title"><?= $titleLine ?></div>
      <span class="rep-party <?= $pClass ?>"><?= partyLabel($rep['party']) ?></span>
      <div class="rep-committees" style="margin-top:10px">
        <?php foreach ($committees as $comm): ?>
          <a href="?committee=<?= $comm['committee_id'] ?>" class="comm<?= $comm['role'] !== 'Member' ? ' ranking' : '' ?>"><?= htmlspecialchars(shortCommittee($comm['name'])) ?><?= $comm['role'] !== 'Member' ? ' [' . cg(strtolower($comm['role']), $comm['role']) . ']' : '' ?></a>
        <?php endforeach; ?>
        <?php if ($subCount > 0): ?>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">+ <?= $subCount ?> <?= cg('subcommittee', 'subcommittees') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="metrics-col">
      <div class="metric">
        <div class="metric-header"><span class="metric-label"><?= cg('participation') ?></span><span class="metric-value" style="color:var(--<?= $partColor ?>)"><?= $rep['participation_pct'] ?>%</span></div>
        <div class="bar-track"><div class="bar-fill <?= $partColor ?>" style="width:<?= $rep['participation_pct'] ?>%"></div><div class="avg-marker" style="left:<?= $rep['chamber_avg_participation'] ?>%"></div></div>
        <div class="metric-sub"><?= number_format($rep['votes_cast']) ?> of <?= number_format($total) ?> — <?= $rep['missed_votes'] ?> missed<?= $partNote ? " <span style='color:var(--$partColor)'>$partNote</span>" : '' ?></div>
      </div>
      <div class="metric">
        <div class="metric-header"><span class="metric-label"><?= cg('party loyalty') ?></span><span class="metric-value" style="color:var(--<?= $loyColor ?>)"><?= $rep['party_loyalty_pct'] ?>%</span></div>
        <div class="bar-track"><div class="bar-fill <?= $loyColor ?>" style="width:<?= $rep['party_loyalty_pct'] ?>%"></div></div>
        <div class="metric-sub"><?= cg('chamber rank', '#' . $rep['chamber_rank_loyalty'] . ' in ' . $rep['chamber']) ?></div>
      </div>
      <div class="metric">
        <div class="metric-header"><span class="metric-label"><?= cg('bipartisanship') ?></span><span class="metric-value" style="color:var(--purple)"><?= $rep['bipartisan_pct'] ?>%</span></div>
        <div class="bar-track"><div class="bar-fill purple" style="width:<?= min($rep['bipartisan_pct'],100) ?>%"></div><div class="avg-marker" style="left:<?= $rep['chamber_avg_bipartisan'] ?>%"></div></div>
        <div class="metric-sub"><?= cg('chamber rank', '#' . $rep['chamber_rank_bipartisan'] . ' in ' . $rep['chamber']) ?></div>
      </div>
    </div>
    <div class="metrics-col">
      <div class="metric">
        <div class="metric-header"><span class="metric-label">Vote Breakdown</span></div>
        <div class="vote-breakdown"><div class="vb-bar">
          <div class="vb-yea" style="width:<?= $yeaPct ?>%"></div><div class="vb-nay" style="width:<?= $nayPct ?>%"></div>
          <?php if ($rep['present_count'] > 0): ?><div class="vb-present" style="width:<?= $presPct ?>%"></div><?php endif; ?>
          <div class="vb-nv" style="width:<?= $nvPct ?>%"></div>
        </div></div>
        <div class="vote-legend">
          <span class="ly"><?= cg('yea') ?> <?= number_format($rep['yea_count']) ?></span>
          <span class="ln"><?= cg('nay') ?> <?= number_format($rep['nay_count']) ?></span>
          <?php if ($rep['present_count'] > 0): ?><span class="lp"><?= cg('present', 'P') ?> <?= $rep['present_count'] ?></span><?php endif; ?>
          <span class="lnv"><?= cg('not voting', 'NV') ?> <?= $rep['missed_votes'] ?></span>
        </div>
      </div>
      <div class="metric">
        <div class="metric-header"><span class="metric-label"><?= cg('bills sponsored') ?></span><span class="metric-value" style="color:<?= $rep['bills_sponsored'] > 0 ? 'var(--gold)' : 'var(--muted)' ?>"><?= $rep['bills_sponsored'] ?></span></div>
        <?php if ($rep['bills_sponsored'] > 0): ?><div class="metric-sub"><?= $rep['bills_substantive'] ?> <?= cg('substantive') ?><?= $rep['bills_resolutions'] > 0 ? ' + ' . $rep['bills_resolutions'] . ' ' . cg('hres', 'res') : '' ?></div><?php endif; ?>
      </div>
      <?php if ($rep['amendments_sponsored'] > 0): ?>
      <div class="metric"><div class="metric-header"><span class="metric-label"><?= cg('amendment', 'Amendments') ?></span><span class="metric-value" style="color:var(--gold)"><?= $rep['amendments_sponsored'] ?></span></div></div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Photo lightbox -->
<div class="lightbox" id="lightbox" onclick="this.classList.remove('active')">
  <img id="lightbox-img" src="" alt="">
</div>

<div class="data-footer">
  <a href="?">&larr; Congress</a> &middot;
  Data from <a href="https://api.congress.gov">Congress.gov API</a>, <a href="https://clerk.house.gov">clerk.house.gov</a>, and <a href="https://www.senate.gov">senate.gov</a> &middot;
  <?= cg('congress', $congress . 'th Congress') ?> &middot;
  <strong style="color:var(--gold)">The People's Branch</strong>
</div>

<script>
function openLightbox(src, name) {
  const lb = document.getElementById('lightbox');
  const img = document.getElementById('lightbox-img');
  img.src = src;
  img.alt = name;
  lb.classList.add('active');
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.getElementById('lightbox').classList.remove('active');
});
</script>
</body>
</html>
