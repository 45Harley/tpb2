<?php
/**
 * TPB Database Integrity Check
 * Run: php ~/tpb2.sandgems.net/db-integrity-check.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== TPB DATABASE INTEGRITY CHECK ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Load config
$config = require __DIR__ . '/config.php';
echo "Database: {$config['database']}\n";
echo "Host: {$config['host']}\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✓ Database connection successful\n\n";
} catch (PDOException $e) {
    die("✗ Database connection FAILED: " . $e->getMessage() . "\n");
}

// ============================================
// 1. TABLE COUNTS
// ============================================
echo "=== 1. TABLE COUNTS ===\n";
$tables = ['elected_officials', 'governing_organizations', 'states', 'towns', 'districts', 'users'];
foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo sprintf("%-30s %6d rows\n", $table, $count);
    } catch (Exception $e) {
        echo sprintf("%-30s ERROR: %s\n", $table, $e->getMessage());
    }
}

// ============================================
// 2. ELECTED_OFFICIALS ANALYSIS
// ============================================
echo "\n=== 2. ELECTED_OFFICIALS ANALYSIS ===\n";

// is_current breakdown
$result = $pdo->query("SELECT is_current, COUNT(*) as cnt FROM elected_officials GROUP BY is_current")->fetchAll(PDO::FETCH_ASSOC);
echo "By is_current:\n";
foreach ($result as $row) {
    echo "  is_current={$row['is_current']}: {$row['cnt']}\n";
}

// org_id validity check
echo "\nOrg_id validity:\n";
$valid = $pdo->query("SELECT COUNT(*) FROM elected_officials eo JOIN governing_organizations go ON eo.org_id = go.org_id")->fetchColumn();
$invalid = $pdo->query("SELECT COUNT(*) FROM elected_officials eo LEFT JOIN governing_organizations go ON eo.org_id = go.org_id WHERE go.org_id IS NULL")->fetchColumn();
$total = $pdo->query("SELECT COUNT(*) FROM elected_officials")->fetchColumn();
echo "  Valid org_id (has match):   $valid\n";
echo "  Invalid org_id (no match):  $invalid\n";
echo "  Total:                      $total\n";

// Show invalid org_ids
if ($invalid > 0) {
    echo "\n⚠ Officials with invalid org_id:\n";
    $bad = $pdo->query("SELECT official_id, full_name, org_id, title FROM elected_officials eo LEFT JOIN governing_organizations go ON eo.org_id = go.org_id WHERE go.org_id IS NULL LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bad as $row) {
        echo "  ID:{$row['official_id']} org_id:{$row['org_id']} - {$row['full_name']} ({$row['title']})\n";
    }
}

// ============================================
// 3. GOVERNING_ORGANIZATIONS CHECK
// ============================================
echo "\n=== 3. GOVERNING_ORGANIZATIONS ===\n";
$result = $pdo->query("SELECT org_type, COUNT(*) as cnt FROM governing_organizations GROUP BY org_type ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($result as $row) {
    echo "  {$row['org_type']}: {$row['cnt']}\n";
}

// ============================================
// 4. THE ACTUAL REPS.PHP QUERY TEST
// ============================================
echo "\n=== 4. REPS.PHP QUERY SIMULATION ===\n";

// This is the exact query from reps.php (no filters)
$sql = "
    SELECT eo.*,
           go.org_type, go.org_name,
           s.state_name,
           t.town_name as org_town_name
    FROM elected_officials eo
    JOIN governing_organizations go ON eo.org_id = go.org_id
    LEFT JOIN states s ON eo.state_code = s.abbreviation
    LEFT JOIN towns t ON go.town_id = t.town_id
    WHERE eo.is_current = 1
    ORDER BY go.org_type, eo.full_name
    LIMIT 20
";

try {
    $result = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo "Query returned: " . count($result) . " rows\n";
    if (count($result) > 0) {
        echo "\nFirst 10 results:\n";
        foreach (array_slice($result, 0, 10) as $row) {
            echo "  [{$row['org_type']}] {$row['full_name']} - {$row['title']}\n";
        }
    }
} catch (Exception $e) {
    echo "Query FAILED: " . $e->getMessage() . "\n";
}

// Full count without limit
$fullCount = $pdo->query("
    SELECT COUNT(*) 
    FROM elected_officials eo
    JOIN governing_organizations go ON eo.org_id = go.org_id
    WHERE eo.is_current = 1
")->fetchColumn();
echo "\nTotal current officials with valid org: $fullCount\n";

// ============================================
// 5. FOREIGN KEY INTEGRITY
// ============================================
echo "\n=== 5. FOREIGN KEY INTEGRITY ===\n";

// elected_officials.state_code -> states.abbreviation
$orphanStates = $pdo->query("
    SELECT COUNT(*) FROM elected_officials eo 
    LEFT JOIN states s ON eo.state_code = s.abbreviation 
    WHERE eo.state_code IS NOT NULL AND s.abbreviation IS NULL
")->fetchColumn();
echo "Officials with invalid state_code: $orphanStates\n";

// governing_organizations.town_id -> towns.town_id
$orphanTowns = $pdo->query("
    SELECT COUNT(*) FROM governing_organizations go 
    LEFT JOIN towns t ON go.town_id = t.town_id 
    WHERE go.town_id IS NOT NULL AND t.town_id IS NULL
")->fetchColumn();
echo "Orgs with invalid town_id: $orphanTowns\n";

// ============================================
// 6. DATA QUALITY CHECKS
// ============================================
echo "\n=== 6. DATA QUALITY ===\n";

// Missing names
$noName = $pdo->query("SELECT COUNT(*) FROM elected_officials WHERE full_name IS NULL OR full_name = ''")->fetchColumn();
echo "Officials without name: $noName\n";

// Missing title
$noTitle = $pdo->query("SELECT COUNT(*) FROM elected_officials WHERE title IS NULL OR title = ''")->fetchColumn();
echo "Officials without title: $noTitle\n";

// Duplicate officials (same name, same org)
$dupes = $pdo->query("
    SELECT full_name, org_id, COUNT(*) as cnt 
    FROM elected_officials 
    WHERE is_current = 1 
    GROUP BY full_name, org_id 
    HAVING cnt > 1
")->fetchAll(PDO::FETCH_ASSOC);
echo "Duplicate current officials (same name+org): " . count($dupes) . "\n";
if (count($dupes) > 0) {
    foreach (array_slice($dupes, 0, 5) as $d) {
        echo "  {$d['full_name']} (org:{$d['org_id']}) x{$d['cnt']}\n";
    }
}

// ============================================
// 7. SAMPLE RECORDS BY LEVEL
// ============================================
echo "\n=== 7. SAMPLE CURRENT OFFICIALS BY LEVEL ===\n";

$levels = ['Federal', 'State', 'Town'];
foreach ($levels as $level) {
    echo "\n$level:\n";
    $sample = $pdo->query("
        SELECT eo.full_name, eo.title, eo.state_code
        FROM elected_officials eo
        JOIN governing_organizations go ON eo.org_id = go.org_id
        WHERE eo.is_current = 1 AND go.org_type = '$level'
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($sample)) {
        echo "  (none found)\n";
    } else {
        foreach ($sample as $row) {
            echo "  {$row['full_name']} - {$row['title']} ({$row['state_code']})\n";
        }
    }
}

echo "\n=== CHECK COMPLETE ===\n";
