<?php
/**
 * Run Civic Engine Phase 1 migration
 * Usage: php run-phase1-migration.php [check|run]
 */

$mode = $argv[1] ?? 'check';
$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($mode === 'check') {
    echo "=== POLLS COLUMNS ===" . PHP_EOL;
    $r = $p->query('DESCRIBE polls');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . PHP_EOL;
    }
    echo PHP_EOL . "=== POLL_VOTES COLUMNS ===" . PHP_EOL;
    $r = $p->query('DESCRIBE poll_votes');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . PHP_EOL;
    }
    echo PHP_EOL . "=== POLL COUNT ===" . PHP_EOL;
    $r = $p->query('SELECT COUNT(*) FROM polls');
    echo $r->fetchColumn() . ' polls' . PHP_EOL;

    // Check if migration already ran
    $cols = [];
    $r = $p->query('DESCRIBE polls');
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) $cols[] = $row['Field'];
    if (in_array('scope_type', $cols)) {
        echo PHP_EOL . "*** Migration already applied (scope_type exists) ***" . PHP_EOL;
    } else {
        echo PHP_EOL . "*** Migration NOT yet applied ***" . PHP_EOL;
    }
    exit;
}

if ($mode === 'run') {
    $sqlFile = __DIR__ . '/civic-engine-phase1.sql';
    $sql = file_get_contents($sqlFile);

    // Split on semicolons, filter empties and comments
    $statements = preg_split('/;\s*$/m', $sql);
    $ok = 0;
    $err = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;
        // Skip pure comment blocks
        $lines = array_filter(explode("\n", $stmt), function($l) {
            $l = trim($l);
            return $l !== '' && strpos($l, '--') !== 0;
        });
        if (empty($lines)) continue;

        try {
            $p->exec($stmt);
            echo 'OK: ' . substr(implode(' ', $lines), 0, 70) . PHP_EOL;
            $ok++;
        } catch (Exception $e) {
            echo 'ERR: ' . $e->getMessage() . PHP_EOL;
            echo '  Statement: ' . substr(implode(' ', $lines), 0, 100) . PHP_EOL;
            $err++;
        }
    }
    echo PHP_EOL . "Done: $ok OK, $err errors" . PHP_EOL;
    exit;
}

echo "Usage: php run-phase1-migration.php [check|run]" . PHP_EOL;
