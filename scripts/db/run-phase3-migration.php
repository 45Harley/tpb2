<?php
/**
 * Run Civic Engine Phase 3 migration: public_opinions table.
 *
 * Usage:
 *   php scripts/db/run-phase3-migration.php
 *
 * Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS).
 */

$config = require __DIR__ . '/../../config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=' . ($config['charset'] ?? 'utf8mb4'),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to {$config['database']}.\n";

    $sql = file_get_contents(__DIR__ . '/civic-engine-phase3.sql');
    if (!$sql) {
        die("ERROR: Could not read civic-engine-phase3.sql\n");
    }

    // Split on semicolons, skip empty statements and comments-only blocks
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !preg_match('/^--/', $s)
    );

    foreach ($statements as $stmt) {
        if (empty($stmt) || preg_match('/^\s*--/', $stmt)) continue;
        echo "Executing: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "...\n";
        $pdo->exec($stmt);
    }

    echo "\nPhase 3 migration complete.\n";

    // Verify
    $result = $pdo->query("DESCRIBE public_opinions");
    $cols = $result->fetchAll(PDO::FETCH_COLUMN);
    echo "public_opinions columns: " . implode(', ', $cols) . "\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
