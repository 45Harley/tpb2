<?php
/**
 * Run Civic Engine Phase 2 migration (Group Deliberation)
 * Usage: php run-phase2-migration.php [check|run]
 */

$mode = $argv[1] ?? 'check';
$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
$p = new PDO('mysql:host='.$c['host'].';dbname=sandge5_tpb2', $c['username'], $c['password']);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($mode === 'check') {
    // Check if declarations table exists
    $tables = [];
    $r = $p->query("SHOW TABLES LIKE 'declarations'");
    while ($row = $r->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

    if (in_array('declarations', $tables)) {
        echo "*** Migration already applied (declarations table exists) ***" . PHP_EOL;
        echo PHP_EOL . "=== DECLARATIONS COLUMNS ===" . PHP_EOL;
        $r = $p->query('DESCRIBE declarations');
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Default'] . PHP_EOL;
        }
        echo PHP_EOL . "=== DECLARATION COUNT ===" . PHP_EOL;
        $r = $p->query('SELECT COUNT(*) FROM declarations');
        echo $r->fetchColumn() . ' declarations' . PHP_EOL;
    } else {
        echo "*** Migration NOT yet applied (declarations table missing) ***" . PHP_EOL;
    }
    exit;
}

if ($mode === 'run') {
    $sqlFile = __DIR__ . '/civic-engine-phase2.sql';
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

echo "Usage: php run-phase2-migration.php [check|run]" . PHP_EOL;
