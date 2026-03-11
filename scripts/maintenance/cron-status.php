<?php
/**
 * Local Cron Status Dashboard
 * Shows threat collection run history and stats.
 * Access: http://localhost/tpb2/scripts/maintenance/cron-status.php
 */

$logFile = __DIR__ . '/logs/collect-threats-local-bat.log';
$serverLogFile = __DIR__ . '/logs/collect-threats-local.log';

// Parse bat log into runs
$batLog = file_exists($logFile) ? file_get_contents($logFile) : '';
$runs = [];
$blocks = preg_split('/={10,}/', $batLog);

foreach ($blocks as $block) {
    $block = trim($block);
    if (empty($block)) continue;

    $run = ['raw' => $block, 'steps' => []];

    if (preg_match('/START:\s*(.+)/', $block, $m)) $run['start'] = trim($m[1]);
    if (preg_match('/END:\s*(.+)/', $block, $m)) $run['end'] = trim($m[1]);

    // Extract steps
    if (preg_match('/Step 1 complete/', $block)) $run['steps'][] = 'gather';
    if (preg_match('/Step 2 complete/', $block)) $run['steps'][] = 'claude';
    if (preg_match('/Step 3 complete/', $block)) $run['steps'][] = 'insert';

    // Extract stats from step3 output
    if (preg_match('/Threats inserted:\s*(\d+)/', $block, $m)) $run['inserted'] = (int)$m[1];
    if (preg_match('/Tags applied:\s*(\d+)/', $block, $m)) $run['tags'] = (int)$m[1];
    if (preg_match('/Polls created:\s*(\d+)/', $block, $m)) $run['polls'] = (int)$m[1];
    if (preg_match('/Total threats in DB:\s*(\d+)/', $block, $m)) $run['total_threats'] = (int)$m[1];
    if (preg_match('/duplicates skipped/', $block)) {
        preg_match('/(\d+) duplicates skipped/', $block, $m);
        $run['skipped'] = (int)($m[1] ?? 0);
    }
    if (preg_match('/Prompt written:\s*(\d+) chars/', $block, $m)) $run['prompt_size'] = (int)$m[1];
    if (preg_match('/Window:\s*(.+)/', $block, $m)) $run['window'] = trim($m[1]);
    if (preg_match('/Search summary:\s*(.+)/s', $block, $m)) {
        $summary = trim($m[1]);
        // Cut at next log line
        $summary = preg_replace('/\n\[.*/', '', $summary);
        $run['search_summary'] = $summary;
    }

    // Check for errors
    if (preg_match('/ERROR:\s*(.+)/', $block, $m)) $run['error'] = trim($m[1]);
    if (preg_match('/disabled/', $block)) $run['disabled'] = true;

    // Status
    if (isset($run['disabled'])) {
        $run['status'] = 'disabled';
    } elseif (isset($run['error'])) {
        $run['status'] = 'error';
    } elseif (count($run['steps']) === 3) {
        $run['status'] = 'success';
    } elseif (!empty($run['steps'])) {
        $run['status'] = 'partial';
    } else {
        continue; // skip empty blocks
    }

    $runs[] = $run;
}

$runs = array_reverse($runs); // newest first

// Parse server-side log for inserted threats detail
$serverLog = file_exists($serverLogFile) ? file_get_contents($serverLogFile) : '';
$recentInserts = [];
if (preg_match_all('/Inserted threat #(\d+) \(score (\d+)\): (.+)/', $serverLog, $matches, PREG_SET_ORDER)) {
    foreach (array_slice(array_reverse($matches), 0, 20) as $m) {
        $recentInserts[] = ['id' => $m[1], 'score' => $m[2], 'title' => $m[3]];
    }
}
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
        .stat-box .label { color: #888; font-size: 0.75em; text-transform: uppercase; }
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
        .run.disabled { border-left: 4px solid #666; }

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
        .run-badge.disabled { background: #2a2a2a; color: #888; }

        .run-stats {
            display: flex;
            gap: 20px;
            color: #b0b0b0;
            font-size: 0.85em;
            margin-bottom: 6px;
        }
        .run-stats span { white-space: nowrap; }
        .run-stats .num { color: #fff; font-weight: 600; }

        .run-window { color: #888; font-size: 0.8em; }
        .run-error { color: #f44336; font-size: 0.85em; margin-top: 5px; }
        .run-summary { color: #999; font-size: 0.8em; margin-top: 6px; line-height: 1.4; }

        .section-title {
            color: #fff;
            font-size: 1.1em;
            margin: 25px 0 12px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }

        .threat-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 0;
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

        .refresh { color: #888; font-size: 0.8em; text-align: right; margin-top: 15px; }
        .refresh a { color: #6a9fff; text-decoration: none; }

        .empty { color: #666; font-style: italic; padding: 20px; text-align: center; }
    </style>
</head>
<body>
    <h1>Local Cron Status</h1>
    <p class="subtitle">Threat Collection Pipeline &mdash; runs via Windows Task Scheduler + claude -p</p>

    <?php
    $totalRuns = count($runs);
    $successRuns = count(array_filter($runs, fn($r) => $r['status'] === 'success'));
    $errorRuns = count(array_filter($runs, fn($r) => $r['status'] === 'error'));
    $totalInserted = array_sum(array_column($runs, 'inserted'));
    $lastRun = $runs[0] ?? null;
    ?>

    <div class="stats-bar">
        <div class="stat-box">
            <div class="label">Total Runs</div>
            <div class="value"><?= $totalRuns ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Successful</div>
            <div class="value green"><?= $successRuns ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Errors</div>
            <div class="value <?= $errorRuns > 0 ? 'red' : '' ?>"><?= $errorRuns ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Total Inserted</div>
            <div class="value gold"><?= $totalInserted ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Last Run</div>
            <div class="value" style="font-size: 0.9em;"><?= $lastRun ? ($lastRun['start'] ?? '?') : 'Never' ?></div>
        </div>
    </div>

    <div class="section-title">Run History</div>

    <?php if (empty($runs)): ?>
        <div class="empty">No runs yet. Run collect-threats-local.bat to start.</div>
    <?php else: ?>
        <?php foreach ($runs as $run): ?>
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

    <div class="refresh">
        Auto-refresh: <a href="javascript:location.reload()">Refresh now</a> |
        Page loaded: <?= date('g:i:s A') ?>
    </div>
</body>
</html>
