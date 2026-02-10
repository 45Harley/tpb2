#!/usr/bin/env php
<?php
/**
 * OpenStates Probe Script
 * =======================
 * Queries OpenStates API to get actual legislator counts per state.
 * Does NOT import anything - just reports totals.
 * 
 * Usage: probe_openstates.php?key=tpb2025import
 */

$secretKey = 'tpb2025import';
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (($_GET['key'] ?? '') !== $secretKey) {
        http_response_code(403);
        die('Access denied. Use ?key=tpb2025import');
    }
    echo '<!DOCTYPE html><html><head><title>OpenStates Probe</title>
    <style>
        body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 20px; }
        table { border-collapse: collapse; width: 100%; max-width: 600px; }
        th, td { border: 1px solid #444; padding: 8px; text-align: left; }
        th { background: #16213e; }
        tr:nth-child(even) { background: #1a1a2e; }
        tr:nth-child(odd) { background: #0f0f23; }
        h1 { color: #74b9ff; }
        .total { font-weight: bold; background: #16213e !important; }
        #progress { color: #ffd43b; margin: 10px 0; }
    </style>
    </head><body>
    <h1>🔍 OpenStates Legislator Counts</h1>
    <p id="progress">Querying API... this takes ~1 minute (rate limited)</p>
    <table><tr><th>State</th><th>Name</th><th>Legislators</th></tr>';
    ob_flush(); flush();
}

// OpenStates API config
$apiKey = 'dfbdcccc-5fc7-4630-a2b0-c21d8a310bd0';
$apiBase = 'https://v3.openstates.org';

// All jurisdictions
$states = [
    'al' => 'Alabama', 'ak' => 'Alaska', 'az' => 'Arizona', 'ar' => 'Arkansas',
    'ca' => 'California', 'co' => 'Colorado', 'ct' => 'Connecticut', 'de' => 'Delaware',
    'dc' => 'District of Columbia', 'fl' => 'Florida', 'ga' => 'Georgia', 'hi' => 'Hawaii',
    'id' => 'Idaho', 'il' => 'Illinois', 'in' => 'Indiana', 'ia' => 'Iowa',
    'ks' => 'Kansas', 'ky' => 'Kentucky', 'la' => 'Louisiana', 'me' => 'Maine',
    'md' => 'Maryland', 'ma' => 'Massachusetts', 'mi' => 'Michigan', 'mn' => 'Minnesota',
    'ms' => 'Mississippi', 'mo' => 'Missouri', 'mt' => 'Montana', 'ne' => 'Nebraska',
    'nv' => 'Nevada', 'nh' => 'New Hampshire', 'nj' => 'New Jersey', 'nm' => 'New Mexico',
    'ny' => 'New York', 'nc' => 'North Carolina', 'nd' => 'North Dakota', 'oh' => 'Ohio',
    'ok' => 'Oklahoma', 'or' => 'Oregon', 'pa' => 'Pennsylvania', 'pr' => 'Puerto Rico',
    'ri' => 'Rhode Island', 'sc' => 'South Carolina', 'sd' => 'South Dakota', 'tn' => 'Tennessee',
    'tx' => 'Texas', 'ut' => 'Utah', 'vt' => 'Vermont', 'va' => 'Virginia',
    'wa' => 'Washington', 'wv' => 'West Virginia', 'wi' => 'Wisconsin', 'wy' => 'Wyoming'
];

$results = [];
$total = 0;
$errors = [];

foreach ($states as $abbr => $name) {
    // Just fetch page 1 with per_page=1 to get total_items quickly
    $url = "$apiBase/people?jurisdiction=$abbr&per_page=1";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: $apiKey",
            "Accept: application/json"
        ],
        CURLOPT_USERAGENT => 'TPB-Probe/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        $count = $data['pagination']['total_items'] ?? 0;
        $results[$abbr] = ['name' => $name, 'count' => $count];
        $total += $count;
        
        if (!$isCli) {
            echo "<tr><td>" . strtoupper($abbr) . "</td><td>$name</td><td>$count</td></tr>";
            ob_flush(); flush();
        }
    } else {
        $results[$abbr] = ['name' => $name, 'count' => 'ERROR'];
        $errors[] = $abbr;
        
        if (!$isCli) {
            echo "<tr><td>" . strtoupper($abbr) . "</td><td>$name</td><td style='color:#ff6b6b'>ERROR ($httpCode)</td></tr>";
            ob_flush(); flush();
        }
    }
    
    // Rate limit - 1 request per second
    usleep(1100000); // 1.1 seconds to be safe
}

if ($isCli) {
    echo "\nSTATE | NAME                  | COUNT\n";
    echo "------+-----------------------+------\n";
    foreach ($results as $abbr => $data) {
        printf("%-5s | %-21s | %s\n", strtoupper($abbr), $data['name'], $data['count']);
    }
    echo "\n";
    echo "TOTAL LEGISLATORS: $total\n";
    echo "ERRORS: " . count($errors) . "\n";
    
    // Output PHP array format for copy/paste
    echo "\n// Copy this into check_import_status.php:\n";
    echo "\$expectedCounts = [\n";
    foreach ($results as $abbr => $data) {
        if (is_numeric($data['count'])) {
            echo "    '" . strtoupper($abbr) . "' => " . $data['count'] . ",\n";
        }
    }
    echo "];\n";
} else {
    echo "<tr class='total'><td colspan='2'>TOTAL</td><td>$total</td></tr>";
    echo "</table>";
    
    echo "<script>document.getElementById('progress').innerHTML = '✅ Complete!';</script>";
    
    if (count($errors) > 0) {
        echo "<p style='color:#ff6b6b'>Errors: " . implode(', ', array_map('strtoupper', $errors)) . "</p>";
    }
    
    // Output PHP array for copy/paste
    echo "<h2>PHP Array (copy into check_import_status.php)</h2>";
    echo "<pre style='background:#0f0f23; padding:10px; overflow-x:auto'>";
    echo "\$expectedCounts = [\n";
    foreach ($results as $abbr => $data) {
        if (is_numeric($data['count'])) {
            echo "    '" . strtoupper($abbr) . "' => " . $data['count'] . ",\n";
        }
    }
    echo "];</pre>";
    
    echo "</body></html>";
}
