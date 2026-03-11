<?php
/**
 * Local Cron Status Dashboard
 * Shows threat collection + race rating check run history and stats.
 * Access: http://localhost/tpb2/cron-status.php
 */

// ─── Parse threat collection log ─────────────────────────────────────────
$threatLogFile = __DIR__ . '/scripts/maintenance/logs/collect-threats-local-bat.log';
$threatBatLog = file_exists($threatLogFile) ? file_get_contents($threatLogFile) : '';
$threatRuns = [];
$blocks = preg_split('/={10,}/', $threatBatLog);

foreach ($blocks as $block) {
    $block = trim($block);
    if (empty($block)) continue;

    $run = ['raw' => $block, 'steps' => []];

    if (preg_match('/START:\s*(.+)/', $block, $m)) $run['start'] = trim($m[1]);
    if (preg_match('/END:\s*(.+)/', $block, $m)) $run['end'] = trim($m[1]);

    if (preg_match('/Step 1 complete/', $block)) $run['steps'][] = 'gather';
    if (preg_match('/Step 2 complete/', $block)) $run['steps'][] = 'claude';
    if (preg_match('/Step 3 complete/', $block)) $run['steps'][] = 'insert';

    if (preg_match('/Threats inserted:\s*(\d+)/', $block, $m)) $run['inserted'] = (int)$m[1];
    if (preg_match('/Tags applied:\s*(\d+)/', $block, $m)) $run['tags'] = (int)$m[1];
    if (preg_match('/Polls created:\s*(\d+)/', $block, $m)) $run['polls'] = (int)$m[1];
    if (preg_match('/Total threats in DB:\s*(\d+)/', $block, $m)) $run['total_threats'] = (int)$m[1];
    if (preg_match('/(\d+) duplicates skipped/', $block, $m)) $run['skipped'] = (int)$m[1];
    if (preg_match('/Prompt written:\s*(\d+) chars/', $block, $m)) $run['prompt_size'] = (int)$m[1];
    if (preg_match('/Window:\s*(.+)/', $block, $m)) $run['window'] = trim($m[1]);
    if (preg_match('/Search summary:\s*(.+)/s', $block, $m)) {
        $summary = trim($m[1]);
        $summary = preg_replace('/\n\[.*/', '', $summary);
        $run['search_summary'] = $summary;
    }

    if (preg_match('/ERROR:\s*(.+)/', $block, $m)) $run['error'] = trim($m[1]);
    if (preg_match('/disabled/', $block)) $run['disabled'] = true;

    if (isset($run['disabled'])) {
        $run['status'] = 'disabled';
    } elseif (isset($run['error'])) {
        $run['status'] = 'error';
    } elseif (count($run['steps']) === 3) {
        $run['status'] = 'success';
    } elseif (!empty($run['steps'])) {
        $run['status'] = 'partial';
    } else {
        continue;
    }

    $threatRuns[] = $run;
}
$threatRuns = array_reverse($threatRuns);

$recentInserts = [];
if (preg_match_all('/Inserted threat #(\d+) \(score (\d+)\): (.+)/', $threatBatLog, $matches, PREG_SET_ORDER)) {
    foreach (array_slice(array_reverse($matches), 0, 20) as $m) {
        $recentInserts[] = ['id' => $m[1], 'score' => $m[2], 'title' => trim($m[3])];
    }
}

// ─── Parse race rating check log ─────────────────────────────────────────
$ratingLogFile = __DIR__ . '/scripts/maintenance/logs/check-race-ratings-local-bat.log';
$ratingBatLog = file_exists($ratingLogFile) ? file_get_contents($ratingLogFile) : '';
$ratingRuns = [];
$rBlocks = preg_split('/={10,}/', $ratingBatLog);

foreach ($rBlocks as $block) {
    $block = trim($block);
    if (empty($block)) continue;

    $run = ['raw' => $block, 'steps' => []];

    if (preg_match('/START:\s*(.+)/', $block, $m)) $run['start'] = trim($m[1]);
    if (preg_match('/END:\s*(.+)/', $block, $m)) $run['end'] = trim($m[1]);

    if (preg_match('/Step 1 complete/', $block)) $run['steps'][] = 'gather';
    if (preg_match('/Step 2 complete/', $block)) $run['steps'][] = 'claude';
    if (preg_match('/Step 3 complete/', $block)) $run['steps'][] = 'insert';

    if (preg_match('/Races to check:\s*(\d+)/', $block, $m)) $run['races_checked'] = (int)$m[1];
    if (preg_match('/Changed:\s*(\d+)/', $block, $m)) $run['changes_applied'] = (int)$m[1];
    if (preg_match('/Skipped:\s*(\d+)/', $block, $m)) $run['skipped'] = (int)$m[1];
    if (preg_match('/UPDATED\s+(.+?):\s+(.+?)\s+.*?\s+(.+?)\s+\(source:\s+(.+?)\)/', $block, $m)) {
        $run['updates'][] = ['label' => $m[1], 'old' => $m[2], 'new' => $m[3], 'source' => $m[4]];
    }

    if (preg_match('/ERROR:\s*(.+)/', $block, $m)) $run['error'] = trim($m[1]);
    if (preg_match('/disabled/', $block)) $run['disabled'] = true;
    if (preg_match('/No active races/', $block)) $run['no_races'] = true;

    if (isset($run['disabled'])) {
        $run['status'] = 'disabled';
    } elseif (isset($run['no_races'])) {
        $run['status'] = 'no_races';
    } elseif (isset($run['error'])) {
        $run['status'] = 'error';
    } elseif (count($run['steps']) === 3) {
        $run['status'] = 'success';
    } elseif (!empty($run['steps'])) {
        $run['status'] = 'partial';
    } else {
        continue;
    }

    $ratingRuns[] = $run;
}
$ratingRuns = array_reverse($ratingRuns);

// Parse all UPDATED lines from rating log
$recentRatingChanges = [];
if (preg_match_all('/UPDATED\s+(.+?):\s+(.+?)\s+→\s+(.+?)\s+\(source:\s+(.+?)\)/', $ratingBatLog, $matches, PREG_SET_ORDER)) {
    foreach (array_slice(array_reverse($matches), 0, 20) as $m) {
        $recentRatingChanges[] = ['label' => trim($m[1]), 'old' => trim($m[2]), 'new' => trim($m[3]), 'source' => trim($m[4])];
    }
}

// ─── Active tab ──────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'threats';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TPB Local Cron Status</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0d0d1a;
            color: #ccc;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }
        h1 { color: #fff; margin-bottom: 5px; font-size: 1.5em; }
        .subtitle { color: #b0b0b0; margin-bottom: 20px; font-size: 0.9em; }

        .tabs {
            display: flex;
            gap: 0;
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
        }
        .tab {
            padding: 10px 20px;
            color: #888;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9em;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tab:hover { color: #ccc; }
        .tab.active { color: #d4af37; border-bottom-color: #d4af37; }
        .tab .count {
            background: #333;
            color: #b0b0b0;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 0.8em;
            margin-left: 6px;
        }
        .tab.active .count { background: #3a321b; color: #d4af37; }

        .stats-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .stat-box {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 12px 18px;
            min-width: 120px;
        }
        .stat-box .label { color: #b0b0b0; font-size: 0.75em; text-transform: uppercase; }
        .stat-box .value { color: #fff; font-size: 1.4em; font-weight: 600; margin-top: 2px; }
        .stat-box .value.green { color: #4caf50; }
        .stat-box .value.gold { color: #ffc107; }
        .stat-box .value.red { color: #f44336; }

        .run {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 12px;
        }
        .run.success { border-left: 4px solid #4caf50; }
        .run.error { border-left: 4px solid #f44336; }
        .run.partial { border-left: 4px solid #ff9800; }
        .run.disabled, .run.no_races { border-left: 4px solid #666; }

        .run-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .run-time { color: #fff; font-weight: 600; }
        .run-badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
        }
        .run-badge.success { background: #1b3a1b; color: #4caf50; }
        .run-badge.error { background: #3a1b1b; color: #f44336; }
        .run-badge.partial { background: #3a2e1b; color: #ff9800; }
        .run-badge.disabled, .run-badge.no_races { background: #2a2a2a; color: #888; }

        .run-stats {
            display: flex;
            gap: 20px;
            color: #b0b0b0;
            font-size: 0.85em;
            margin-bottom: 6px;
        }
        .run-stats span { white-space: nowrap; }
        .run-stats .num { color: #fff; font-weight: 600; }

        .run-window { color: #b0b0b0; font-size: 0.8em; }
        .run-error { color: #f44336; font-size: 0.85em; margin-top: 5px; }
        .run-summary { color: #999; font-size: 0.8em; margin-top: 6px; line-height: 1.4; }

        .section-title {
            color: #fff;
            font-size: 1.1em;
            margin: 25px 0 12px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }

        .threat-row, .rating-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid #1f1f35;
            font-size: 0.85em;
        }
        .threat-id { color: #888; min-width: 40px; }
        .threat-score {
            min-width: 45px;
            text-align: center;
            padding: 1px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.8em;
        }
        .score-high { background: #3a1b1b; color: #f44336; }
        .score-med { background: #3a2e1b; color: #ff9800; }
        .score-low { background: #1b3a1b; color: #4caf50; }
        .threat-title { color: #ccc; flex: 1; }

        .rating-label { color: #fff; min-width: 140px; font-weight: 600; }
        .rating-old { color: #888; min-width: 80px; }
        .rating-arrow { color: #d4af37; }
        .rating-new { color: #fff; min-width: 80px; font-weight: 600; }
        .rating-source { color: #888; font-size: 0.85em; }

        .refresh { color: #888; font-size: 0.8em; text-align: right; margin-top: 15px; }
        .refresh a { color: #6a9fff; text-decoration: none; }

        .empty { color: #666; font-style: italic; padding: 20px; text-align: center; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
    <h1>Local Cron Status</h1>
    <p class="subtitle">Local pipelines via Windows Task Scheduler + claude -p (no API cost)</p>

    <div class="tabs">
        <a href="?tab=threats" class="tab <?= $tab === 'threats' ? 'active' : '' ?>">
            Threat Collection <span class="count"><?= count($threatRuns) ?></span>
        </a>
        <a href="?tab=ratings" class="tab <?= $tab === 'ratings' ? 'active' : '' ?>">
            Race Ratings <span class="count"><?= count($ratingRuns) ?></span>
        </a>
    </div>

    <!-- ─── THREATS TAB ─────────────────────────────────────────────────── -->
    <div class="tab-content <?= $tab === 'threats' ? 'active' : '' ?>">
        <?php
        $tTotal = count($threatRuns);
        $tSuccess = count(array_filter($threatRuns, fn($r) => $r['status'] === 'success'));
        $tErrors = count(array_filter($threatRuns, fn($r) => $r['status'] === 'error'));
        $tInserted = array_sum(array_column($threatRuns, 'inserted'));
        $tLast = $threatRuns[0] ?? null;
        ?>

        <div class="stats-bar">
            <div class="stat-box">
                <div class="label">Total Runs</div>
                <div class="value"><?= $tTotal ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Successful</div>
                <div class="value green"><?= $tSuccess ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Errors</div>
                <div class="value <?= $tErrors > 0 ? 'red' : '' ?>"><?= $tErrors ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Total Inserted</div>
                <div class="value gold"><?= $tInserted ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Last Run</div>
                <div class="value" style="font-size: 0.9em;"><?= $tLast ? ($tLast['start'] ?? '?') : 'Never' ?></div>
            </div>
        </div>

        <div class="section-title">Run History</div>

        <?php if (empty($threatRuns)): ?>
            <div class="empty">No runs yet. Run collect-threats-local.bat to start.</div>
        <?php else: ?>
            <?php foreach ($threatRuns as $run): ?>
            <div class="run <?= $run['status'] ?>">
                <div class="run-header">
                    <span class="run-time"><?= htmlspecialchars($run['start'] ?? 'Unknown') ?></span>
                    <span class="run-badge <?= $run['status'] ?>"><?= $run['status'] ?></span>
                </div>
                <?php if ($run['status'] === 'success'): ?>
                <div class="run-stats">
                    <span>Inserted: <span class="num"><?= $run['inserted'] ?? 0 ?></span></span>
                    <span>Skipped: <span class="num"><?= $run['skipped'] ?? 0 ?></span></span>
                    <span>Polls: <span class="num"><?= $run['polls'] ?? 0 ?></span></span>
                    <span>Tags: <span class="num"><?= $run['tags'] ?? 0 ?></span></span>
                    <span>DB Total: <span class="num"><?= $run['total_threats'] ?? '?' ?></span></span>
                </div>
                <?php endif; ?>
                <?php if (isset($run['window'])): ?>
                    <div class="run-window">Window: <?= htmlspecialchars($run['window']) ?> | Prompt: <?= number_format($run['prompt_size'] ?? 0) ?> chars</div>
                <?php endif; ?>
                <?php if (isset($run['error'])): ?>
                    <div class="run-error"><?= htmlspecialchars($run['error']) ?></div>
                <?php endif; ?>
                <?php if (isset($run['search_summary'])): ?>
                    <div class="run-summary"><?= htmlspecialchars($run['search_summary']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section-title">Recent Threats Inserted (Last 20)</div>

        <?php if (empty($recentInserts)): ?>
            <div class="empty">No threats inserted yet.</div>
        <?php else: ?>
            <?php foreach ($recentInserts as $t):
                $scoreClass = $t['score'] >= 500 ? 'score-high' : ($t['score'] >= 300 ? 'score-med' : 'score-low');
            ?>
            <div class="threat-row">
                <span class="threat-id">#<?= $t['id'] ?></span>
                <span class="threat-score <?= $scoreClass ?>"><?= $t['score'] ?></span>
                <span class="threat-title"><?= htmlspecialchars($t['title']) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ─── RATINGS TAB ─────────────────────────────────────────────────── -->
    <div class="tab-content <?= $tab === 'ratings' ? 'active' : '' ?>">
        <?php
        $rTotal = count($ratingRuns);
        $rSuccess = count(array_filter($ratingRuns, fn($r) => $r['status'] === 'success'));
        $rErrors = count(array_filter($ratingRuns, fn($r) => $r['status'] === 'error'));
        $rTotalChanges = array_sum(array_column($ratingRuns, 'changes_applied'));
        $rLast = $ratingRuns[0] ?? null;
        ?>

        <div class="stats-bar">
            <div class="stat-box">
                <div class="label">Total Runs</div>
                <div class="value"><?= $rTotal ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Successful</div>
                <div class="value green"><?= $rSuccess ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Errors</div>
                <div class="value <?= $rErrors > 0 ? 'red' : '' ?>"><?= $rErrors ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Rating Shifts</div>
                <div class="value gold"><?= $rTotalChanges ?></div>
            </div>
            <div class="stat-box">
                <div class="label">Last Run</div>
                <div class="value" style="font-size: 0.9em;"><?= $rLast ? ($rLast['start'] ?? '?') : 'Never' ?></div>
            </div>
        </div>

        <div class="section-title">Run History</div>

        <?php if (empty($ratingRuns)): ?>
            <div class="empty">No runs yet. Run check-race-ratings-local.bat to start.</div>
        <?php else: ?>
            <?php foreach ($ratingRuns as $run): ?>
            <div class="run <?= $run['status'] ?>">
                <div class="run-header">
                    <span class="run-time"><?= htmlspecialchars($run['start'] ?? 'Unknown') ?></span>
                    <span class="run-badge <?= $run['status'] ?>"><?= str_replace('_', ' ', $run['status']) ?></span>
                </div>
                <?php if ($run['status'] === 'success'): ?>
                <div class="run-stats">
                    <span>Races checked: <span class="num"><?= $run['races_checked'] ?? '?' ?></span></span>
                    <span>Changes applied: <span class="num"><?= $run['changes_applied'] ?? 0 ?></span></span>
                    <span>Skipped: <span class="num"><?= $run['skipped'] ?? 0 ?></span></span>
                </div>
                <?php endif; ?>
                <?php if (isset($run['error'])): ?>
                    <div class="run-error"><?= htmlspecialchars($run['error']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section-title">Recent Rating Changes (Last 20)</div>

        <?php if (empty($recentRatingChanges)): ?>
            <div class="empty">No rating changes detected yet.</div>
        <?php else: ?>
            <?php foreach ($recentRatingChanges as $rc): ?>
            <div class="rating-row">
                <span class="rating-label"><?= htmlspecialchars($rc['label']) ?></span>
                <span class="rating-old"><?= htmlspecialchars($rc['old']) ?></span>
                <span class="rating-arrow">&rarr;</span>
                <span class="rating-new"><?= htmlspecialchars($rc['new']) ?></span>
                <span class="rating-source"><?= htmlspecialchars($rc['source']) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="refresh">
        <a href="javascript:location.reload()">Refresh now</a> |
        Page loaded: <?= date('g:i:s A') ?>
    </div>
</body>
</html>
