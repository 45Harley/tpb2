<?php
/**
 * Import complete SIC codes from GitHub CSV into sic_codes table.
 * Compares existing data, inserts missing codes, reports results.
 *
 * Usage: php scripts/db/sic-import.php
 */

$config = require __DIR__ . '/../../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Division descriptions
$divisionDescs = [
    'A' => 'Agriculture, Forestry, Fishing',
    'B' => 'Mining',
    'C' => 'Construction',
    'D' => 'Manufacturing',
    'E' => 'Transportation, Communications, Utilities',
    'F' => 'Wholesale Trade',
    'G' => 'Retail Trade',
    'H' => 'Finance, Insurance, Real Estate',
    'I' => 'Services',
    'J' => 'Public Administration',
];

// Major group descriptions (from OSHA)
$majorGroupDescs = [];
$mgFile = __DIR__ . '/../../tmp/sic-major-groups.csv';
if (!file_exists($mgFile)) {
    // Download if not present
    $mgCsv = file_get_contents('https://raw.githubusercontent.com/saintsjd/sic4-list/master/major-groups.csv');
    @mkdir(dirname($mgFile), 0755, true);
    file_put_contents($mgFile, $mgCsv);
}
$mgHandle = fopen($mgFile, 'r');
$mgHeader = fgetcsv($mgHandle); // skip header
while (($row = fgetcsv($mgHandle)) !== false) {
    $majorGroupDescs[$row[1]] = trim($row[2]);
}
fclose($mgHandle);

// Get existing codes
$existing = [];
$stmt = $pdo->query("SELECT sic_code FROM sic_codes");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $existing[$row[0]] = true;
}
echo "Existing codes in DB: " . count($existing) . "\n";

// Read full SIC CSV
$sicFile = __DIR__ . '/../../tmp/sic-codes.csv';
if (!file_exists($sicFile)) {
    $sicCsv = file_get_contents('https://raw.githubusercontent.com/saintsjd/sic4-list/master/sic-codes.csv');
    file_put_contents($sicFile, $sicCsv);
}

$handle = fopen($sicFile, 'r');
$header = fgetcsv($handle); // Division,Major Group,Industry Group,SIC,Description

$insert = $pdo->prepare("
    INSERT INTO sic_codes (sic_code, description, division, division_desc, major_group, major_group_desc)
    VALUES (?, ?, ?, ?, ?, ?)
");

$inserted = 0;
$skipped = 0;
$byDivision = [];

while (($row = fgetcsv($handle)) !== false) {
    $division = trim($row[0]);
    $majorGroup = trim($row[1]);
    $sicCode = trim($row[3]);
    $description = trim($row[4]);

    if (isset($existing[$sicCode])) {
        $skipped++;
        continue;
    }

    $divDesc = $divisionDescs[$division] ?? $division;
    $mgDesc = $majorGroupDescs[$majorGroup] ?? $majorGroup;

    $insert->execute([$sicCode, $description, $division, $divDesc, $majorGroup, $mgDesc]);
    $inserted++;

    if (!isset($byDivision[$division])) $byDivision[$division] = 0;
    $byDivision[$division]++;
}
fclose($handle);

echo "Inserted: $inserted\n";
echo "Skipped (already existed): $skipped\n";
echo "\nInserted by division:\n";
ksort($byDivision);
foreach ($byDivision as $div => $count) {
    echo "  $div ({$divisionDescs[$div]}): $count\n";
}

// Final count
$stmt = $pdo->query("SELECT COUNT(*) FROM sic_codes");
echo "\nTotal codes in DB now: " . $stmt->fetchColumn() . "\n";
