#!/usr/bin/env php
<?php
/**
 * OpenStates Import Script for The People's Branch
 * =================================================
 * All-in-one: Status, Diagnostics, Fixes, and Import
 * 
 * Usage:
 *   ?key=tpb2025import              - Show status dashboard
 *   ?key=tpb2025import&batch=6      - Import specific batch
 *   ?key=tpb2025import&batch=all    - Import all states
 *   ?key=tpb2025import&state=ia     - Import single state
 *   ?key=tpb2025import&fix=1        - Fix missing mappings
 *   ?key=tpb2025import&probe=1      - Query API for expected counts
 */

// ============================================================================
// CONFIG & SETUP
// ============================================================================

$secretKey = 'tpb2025import';
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (($_GET['key'] ?? '') !== $secretKey) {
        http_response_code(403);
        die('Access denied. Use ?key=tpb2025import');
    }
}

$config = [
    'db_host' => 'localhost',
    'db_name' => 'sandge5_tpb2',
    'db_user' => 'sandge5_tpb2',
    'db_pass' => '.YeO6kSJAHh5',
    
    'api_key' => 'dfbdcccc-5fc7-4630-a2b0-c21d8a310bd0',
    'api_base' => 'https://v3.openstates.org',
    
    'delay_between_states' => 1,
    'per_page' => 50,
    'dry_run' => false,
];

// Expected counts (from API probe + known legislature sizes)
$expectedCounts = [
    'AL' => 137, 'AK' => 62, 'AZ' => 92, 'AR' => 137, 'CA' => 121, 'CO' => 102,
    'CT' => 189, 'DE' => 61, 'DC' => 12, 'FL' => 161, 'GA' => 237, 'HI' => 77,
    'ID' => 106, 'IL' => 178, 'IN' => 151, 'IA' => 151, 'KS' => 166, 'KY' => 139,
    'LA' => 145, 'ME' => 188, 'MD' => 188, 'MA' => 200, 'MI' => 148, 'MN' => 201,
    'MS' => 174, 'MO' => 197, 'MT' => 150, 'NE' => 49, 'NV' => 63, 'NH' => 424,
    'NJ' => 120, 'NM' => 112, 'NY' => 213, 'NC' => 170, 'ND' => 141, 'OH' => 132,
    'OK' => 149, 'OR' => 90, 'PA' => 253, 'PR' => 78, 'RI' => 113, 'SC' => 170,
    'SD' => 105, 'TN' => 132, 'TX' => 181, 'UT' => 104, 'VT' => 180, 'VA' => 140,
    'WA' => 147, 'WV' => 134, 'WI' => 132, 'WY' => 90
];

// All jurisdictions
$allJurisdictions = [
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

// Batches (3 states each)
$batches = array_chunk(array_keys($allJurisdictions), 3, false);

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================================
// ROUTING
// ============================================================================

$action = 'status'; // default
if (isset($_GET['batch']) || isset($_GET['state'])) {
    $action = 'import';
} elseif (isset($_GET['fix'])) {
    $action = 'fix';
} elseif (isset($_GET['probe'])) {
    $action = 'probe';
}

// ============================================================================
// HTML HEADER
// ============================================================================

if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>OpenStates Import</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace; 
               background: #0f0f23; color: #ccc; padding: 20px; margin: 0; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #00cc00; margin-bottom: 5px; }
        h2 { color: #74b9ff; border-bottom: 1px solid #333; padding-bottom: 10px; margin-top: 30px; }
        .subtitle { color: #666; margin-bottom: 20px; }
        
        /* Navigation */
        .nav { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .nav a, .nav button { 
            background: #1a1a2e; color: #74b9ff; padding: 10px 20px; 
            text-decoration: none; border: 1px solid #333; cursor: pointer;
            font-size: 14px; border-radius: 4px;
        }
        .nav a:hover, .nav button:hover { background: #16213e; border-color: #74b9ff; }
        .nav a.active { background: #74b9ff; color: #000; }
        .nav a.danger { border-color: #ff6b6b; color: #ff6b6b; }
        .nav a.success { border-color: #51cf66; color: #51cf66; }
        
        /* Tables */
        table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        th, td { border: 1px solid #333; padding: 8px 12px; text-align: left; }
        th { background: #1a1a2e; color: #74b9ff; }
        tr:nth-child(even) { background: #0a0a15; }
        tr:hover { background: #1a1a2e; }
        
        /* Status colors */
        .ok { color: #51cf66; }
        .warn { color: #ffd43b; }
        .error { color: #ff6b6b; }
        .zero { color: #ff6b6b; font-weight: bold; }
        
        /* Cards */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-card { background: #1a1a2e; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #00cc00; }
        .stat-card .label { color: #888; font-size: 14px; }
        
        /* Log output */
        .log { background: #0a0a15; border: 1px solid #333; padding: 15px; 
               font-family: monospace; font-size: 13px; max-height: 500px; 
               overflow-y: auto; white-space: pre-wrap; margin: 15px 0; }
        .log .time { color: #666; }
        .log .success { color: #51cf66; }
        .log .error { color: #ff6b6b; }
        .log .info { color: #74b9ff; }
        
        /* Batch grid */
        .batch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
        .batch-card { background: #1a1a2e; padding: 15px; border-radius: 4px; border: 1px solid #333; }
        .batch-card h3 { margin: 0 0 10px 0; color: #74b9ff; font-size: 14px; }
        .batch-card .states { color: #888; font-size: 13px; }
        .batch-card a { display: inline-block; margin-top: 10px; }
    </style>
    </head><body><div class="container">';
    
    echo '<h1>🏛️ OpenStates Import</h1>';
    echo '<p class="subtitle">The People\'s Branch — State Legislators</p>';
    
    // Navigation
    $currentKey = "?key=$secretKey";
    echo '<div class="nav">';
    echo '<a href="' . $currentKey . '"' . ($action == 'status' ? ' class="active"' : '') . '>📊 Status</a>';
    echo '<a href="' . $currentKey . '&fix=1"' . ($action == 'fix' ? ' class="active"' : '') . '>🔧 Fix Mappings</a>';
    echo '<a href="' . $currentKey . '&probe=1"' . ($action == 'probe' ? ' class="active"' : '') . '>🔍 Probe API</a>';
    echo '<a href="' . $currentKey . '&batch=all" class="danger" onclick="return confirm(\'Import ALL states? This takes ~30 minutes.\')">🚀 Import All</a>';
    echo '</div>';
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function fetchFromApi($url, $apiKey) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            "X-API-KEY: $apiKey",
            "Accept: application/json"
        ],
        CURLOPT_USERAGENT => 'TPB-OpenStates-Import/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        return ['error' => true, 'code' => $httpCode];
    }
    
    return json_decode($response, true);
}

function mapLegislator(array $leg, int $orgId, ?int $branchId, string $stateAbbr): array {
    $currentRole = $leg['current_role'] ?? null;
    $chamber = $currentRole['org_classification'] ?? '';
    $district = $currentRole['district'] ?? '';
    
    $title = 'State Legislator';
    if ($chamber === 'upper') $title = 'State Senator';
    elseif ($chamber === 'lower') $title = 'State Representative';
    
    $officeName = strtoupper($stateAbbr) . " " . ucfirst($chamber) . " District $district";
    
    $email = null;
    $phone = null;
    foreach ($leg['offices'] ?? [] as $office) {
        if (!$email && !empty($office['email'])) $email = $office['email'];
        if (!$phone && !empty($office['voice'])) $phone = $office['voice'];
    }
    
    $website = null;
    foreach ($leg['links'] ?? [] as $link) {
        if (!empty($link['url'])) { $website = $link['url']; break; }
    }
    
    return [
        'openstates_id' => $leg['id'] ?? null,
        'full_name' => $leg['name'] ?? 'Unknown',
        'title' => $title,
        'org_id' => $orgId,
        'branch_id' => $branchId,
        'term_start' => null,
        'term_end' => null,
        'is_current' => 1,
        'appointment_type' => 'elected',
        'party' => $leg['party'] ?? null,
        'office_name' => $officeName,
        'ocd_id' => $currentRole['division_id'] ?? null,
        'state_code' => strtoupper($stateAbbr),
        'email' => $email,
        'phone' => $phone,
        'website' => $website,
        'photo_url' => $leg['image'] ?? null,
    ];
}

function getOrgMappings($pdo) {
    // Build state abbreviation -> state_id map
    $stateMap = [];
    foreach ($pdo->query("SELECT state_id, LOWER(abbreviation) as abbr FROM states") as $row) {
        $stateMap[$row['abbr']] = $row['state_id'];
    }
    
    // Build state abbreviation -> org_id map
    $orgMap = [];
    $orgStmt = $pdo->query("SELECT org_id, state_id FROM governing_organizations WHERE org_type = 'State' AND state_id IS NOT NULL");
    foreach ($orgStmt as $row) {
        foreach ($stateMap as $abbr => $sid) {
            if ($sid == $row['state_id']) {
                $orgMap[$abbr] = $row['org_id'];
                break;
            }
        }
    }
    
    // Build org_id -> branch_id map
    $branchMap = [];
    foreach ($pdo->query("SELECT branch_id, org_id FROM branches_departments WHERE branch_type = 'Legislative'") as $row) {
        $branchMap[$row['org_id']] = $row['branch_id'];
    }
    
    return [$stateMap, $orgMap, $branchMap];
}

function log_msg($msg, $type = 'info') {
    global $isCli;
    $time = date('H:i:s');
    if ($isCli) {
        echo "[$time] $msg\n";
    } else {
        echo "<span class='time'>[$time]</span> <span class='$type'>$msg</span>\n";
    }
    if (!$isCli) { ob_flush(); flush(); }
}

// ============================================================================
// ACTION: STATUS (default)
// ============================================================================

if ($action == 'status') {
    list($stateMap, $orgMap, $branchMap) = getOrgMappings($pdo);
    
    // Get counts per state
    $countsSql = "SELECT state_code, COUNT(*) as total,
                  SUM(CASE WHEN openstates_id IS NOT NULL THEN 1 ELSE 0 END) as openstates
                  FROM elected_officials WHERE state_code IS NOT NULL
                  GROUP BY state_code";
    $dbCounts = [];
    foreach ($pdo->query($countsSql) as $row) {
        $dbCounts[$row['state_code']] = (int)$row['openstates'];
    }
    
    // Summary stats
    $totalImported = array_sum($dbCounts);
    $totalExpected = array_sum($expectedCounts);
    $statesComplete = 0;
    $statesMissing = 0;
    $statesNoMapping = 0;
    
    foreach ($allJurisdictions as $abbr => $name) {
        $upper = strtoupper($abbr);
        $count = $dbCounts[$upper] ?? 0;
        $expected = $expectedCounts[$upper] ?? 0;
        
        if (!isset($orgMap[$abbr])) $statesNoMapping++;
        elseif ($count == 0) $statesMissing++;
        elseif ($count >= $expected * 0.8) $statesComplete++;
    }
    
    // Federal count
    $fedCount = (int)$pdo->query("SELECT COUNT(*) FROM elected_officials WHERE bioguide_id IS NOT NULL")->fetchColumn();
    
    if (!$isCli) {
        echo '<h2>📊 Overview</h2>';
        echo '<div class="stats">';
        echo '<div class="stat-card"><div class="number">' . number_format($totalImported) . '</div><div class="label">State Legislators Imported</div></div>';
        echo '<div class="stat-card"><div class="number">' . number_format($totalExpected) . '</div><div class="label">Expected Total</div></div>';
        echo '<div class="stat-card"><div class="number">' . $statesComplete . '</div><div class="label">States Complete</div></div>';
        echo '<div class="stat-card"><div class="number">' . $fedCount . '</div><div class="label">Federal (Congress.gov)</div></div>';
        echo '</div>';
        
        if ($statesNoMapping > 0) {
            echo '<p class="error">⚠️ ' . $statesNoMapping . ' states missing org_id mapping — <a href="?key=' . $secretKey . '&fix=1">click here to fix</a></p>';
        }
        
        // State table
        echo '<h2>📋 State-by-State Status</h2>';
        echo '<table><tr><th>State</th><th>Name</th><th>Imported</th><th>Expected</th><th>%</th><th>Status</th><th>Action</th></tr>';
        
        foreach ($allJurisdictions as $abbr => $name) {
            $upper = strtoupper($abbr);
            $count = $dbCounts[$upper] ?? 0;
            $expected = $expectedCounts[$upper] ?? 100;
            $pct = $expected > 0 ? round($count / $expected * 100) : 0;
            $hasMapping = isset($orgMap[$abbr]);
            
            if (!$hasMapping) {
                $status = '<span class="error">❌ No Mapping</span>';
                $class = 'zero';
            } elseif ($count == 0) {
                $status = '<span class="error">❌ Empty</span>';
                $class = 'zero';
            } elseif ($pct < 80) {
                $status = '<span class="warn">⚠️ Incomplete</span>';
                $class = 'warn';
            } else {
                $status = '<span class="ok">✅ OK</span>';
                $class = 'ok';
            }
            
            $actionLink = $hasMapping ? "<a href='?key=$secretKey&state=$abbr'>Import</a>" : "<a href='?key=$secretKey&fix=1'>Fix</a>";
            
            echo "<tr><td>$upper</td><td>$name</td><td class='$class'>$count</td><td>$expected</td><td>$pct%</td><td>$status</td><td>$actionLink</td></tr>";
        }
        echo '</table>';
        
        // Batch cards
        echo '<h2>📦 Import by Batch</h2>';
        echo '<div class="batch-grid">';
        foreach ($batches as $i => $batchStates) {
            $batchNum = $i + 1;
            $stateList = implode(', ', array_map('strtoupper', $batchStates));
            $batchCount = 0;
            $batchExpected = 0;
            foreach ($batchStates as $s) {
                $batchCount += $dbCounts[strtoupper($s)] ?? 0;
                $batchExpected += $expectedCounts[strtoupper($s)] ?? 0;
            }
            $batchPct = $batchExpected > 0 ? round($batchCount / $batchExpected * 100) : 0;
            $batchStatus = $batchPct >= 80 ? '✅' : ($batchCount > 0 ? '⚠️' : '❌');
            
            echo '<div class="batch-card">';
            echo "<h3>Batch $batchNum $batchStatus</h3>";
            echo "<div class='states'>$stateList</div>";
            echo "<div>$batchCount / $batchExpected ($batchPct%)</div>";
            echo "<a href='?key=$secretKey&batch=$batchNum' class='nav'>▶ Run</a>";
            echo '</div>';
        }
        echo '</div>';
    }
}

// ============================================================================
// ACTION: FIX MAPPINGS
// ============================================================================

if ($action == 'fix') {
    list($stateMap, $orgMap, $branchMap) = getOrgMappings($pdo);
    
    echo $isCli ? "=== FIX MAPPINGS ===\n\n" : '<h2>🔧 Fix Missing Mappings</h2>';
    
    $issues = [];
    $fixed = 0;
    
    foreach ($allJurisdictions as $abbr => $name) {
        $upper = strtoupper($abbr);
        
        // Check state
        if (!isset($stateMap[$abbr])) {
            $issues[] = "$upper: Missing from states table";
            if (isset($_GET['apply'])) {
                $pdo->exec("INSERT INTO states (state_name, abbreviation) VALUES ('$name', '$upper')");
                $stateMap[$abbr] = $pdo->lastInsertId();
                $fixed++;
            }
        }
        
        // Check org
        if (isset($stateMap[$abbr]) && !isset($orgMap[$abbr])) {
            $issues[] = "$upper: Missing from governing_organizations";
            if (isset($_GET['apply'])) {
                $sid = $stateMap[$abbr];
                $stmt = $pdo->prepare("INSERT INTO governing_organizations (org_name, org_type, state_id, description) VALUES (?, 'State', ?, ?)");
                $stmt->execute(["State of $name", $sid, "$name state government"]);
                $orgMap[$abbr] = $pdo->lastInsertId();
                $fixed++;
            }
        }
        
        // Check branch
        if (isset($orgMap[$abbr]) && !isset($branchMap[$orgMap[$abbr]])) {
            $issues[] = "$upper: Missing legislative branch";
            if (isset($_GET['apply'])) {
                $oid = $orgMap[$abbr];
                $stmt = $pdo->prepare("INSERT INTO branches_departments (org_id, branch_name, branch_type, description) VALUES (?, 'General Assembly', 'Legislative', ?)");
                $stmt->execute([$oid, "$name State Legislature"]);
                $fixed++;
            }
        }
    }
    
    if (!$isCli) {
        if (empty($issues)) {
            echo '<p class="ok">✅ All mappings are correct! No fixes needed.</p>';
        } else {
            echo '<table><tr><th>Issue</th></tr>';
            foreach ($issues as $issue) {
                echo "<tr><td class='error'>$issue</td></tr>";
            }
            echo '</table>';
            
            if (isset($_GET['apply'])) {
                echo "<p class='ok'>✅ Applied $fixed fixes!</p>";
                echo "<p><a href='?key=$secretKey'>← Back to Status</a></p>";
            } else {
                echo '<p>Found ' . count($issues) . ' issues.</p>';
                echo "<a href='?key=$secretKey&fix=1&apply=1' class='nav success' onclick=\"return confirm('Apply all fixes?')\">✅ Apply Fixes</a>";
            }
        }
    }
}

// ============================================================================
// ACTION: PROBE API
// ============================================================================

if ($action == 'probe') {
    echo $isCli ? "=== PROBE API ===\n" : '<h2>🔍 Probe OpenStates API</h2><p>Querying actual legislator counts (takes ~1 min, rate limited)...</p>';
    echo $isCli ? '' : '<div class="log">';
    
    ob_implicit_flush(true);
    if (ob_get_level()) ob_end_flush();
    
    $results = [];
    $total = 0;
    
    foreach ($allJurisdictions as $abbr => $name) {
        $url = "{$config['api_base']}/people?jurisdiction=$abbr&per_page=1";
        $data = fetchFromApi($url, $config['api_key']);
        
        if (isset($data['error'])) {
            log_msg(strtoupper($abbr) . " ($name): ERROR {$data['code']}", 'error');
            $results[$abbr] = 'ERROR';
        } else {
            $count = $data['pagination']['total_items'] ?? 0;
            $results[$abbr] = $count;
            $total += $count;
            log_msg(strtoupper($abbr) . " ($name): $count legislators", 'success');
        }
        
        usleep(1100000); // Rate limit
    }
    
    echo $isCli ? '' : '</div>';
    
    log_msg("TOTAL: $total legislators across all states", 'info');
    
    // Output PHP array
    if (!$isCli) {
        echo '<h3>Updated Expected Counts</h3><pre>$expectedCounts = [' . "\n";
        foreach ($results as $abbr => $count) {
            if (is_numeric($count)) {
                echo "    '" . strtoupper($abbr) . "' => $count,\n";
            }
        }
        echo '];</pre>';
    }
}

// ============================================================================
// ACTION: IMPORT
// ============================================================================

if ($action == 'import') {
    // Determine which states to import
    $statesToImport = [];
    
    if (isset($_GET['state'])) {
        $s = strtolower($_GET['state']);
        if (isset($allJurisdictions[$s])) {
            $statesToImport[$s] = $allJurisdictions[$s];
        }
    } elseif (isset($_GET['batch'])) {
        $batch = $_GET['batch'];
        if ($batch === 'all') {
            $statesToImport = $allJurisdictions;
        } else {
            $batchNum = (int)$batch - 1;
            if (isset($batches[$batchNum])) {
                foreach ($batches[$batchNum] as $abbr) {
                    $statesToImport[$abbr] = $allJurisdictions[$abbr];
                }
            }
        }
    }
    
    if (empty($statesToImport)) {
        echo $isCli ? "No states to import.\n" : '<p class="error">No states to import.</p>';
    } else {
        list($stateMap, $orgMap, $branchMap) = getOrgMappings($pdo);
        
        echo $isCli ? '' : '<h2>🚀 Importing</h2><p>States: ' . implode(', ', array_map('strtoupper', array_keys($statesToImport))) . '</p>';
        echo $isCli ? '' : '<div class="log">';
        
        ob_implicit_flush(true);
        if (ob_get_level()) ob_end_flush();
        
        // Ensure openstates_id column exists
        $columns = $pdo->query("SHOW COLUMNS FROM elected_officials LIKE 'openstates_id'")->fetchAll();
        if (empty($columns)) {
            log_msg("Adding openstates_id column...", 'info');
            $pdo->exec("ALTER TABLE elected_officials ADD COLUMN openstates_id VARCHAR(50) NULL AFTER bioguide_id");
            $pdo->exec("ALTER TABLE elected_officials ADD UNIQUE INDEX idx_openstates (openstates_id)");
        }
        
        // Prepare insert statement
        $insertSql = "INSERT INTO elected_officials (
            openstates_id, full_name, title, org_id, branch_id, 
            term_start, term_end, is_current, appointment_type, 
            party, office_name, ocd_id, state_code, email, phone, website, photo_url
        ) VALUES (
            :openstates_id, :full_name, :title, :org_id, :branch_id,
            :term_start, :term_end, :is_current, :appointment_type,
            :party, :office_name, :ocd_id, :state_code, :email, :phone, :website, :photo_url
        ) ON DUPLICATE KEY UPDATE
            full_name = VALUES(full_name), title = VALUES(title), party = VALUES(party),
            office_name = VALUES(office_name), email = VALUES(email), phone = VALUES(phone),
            website = VALUES(website), photo_url = VALUES(photo_url), updated_at = CURRENT_TIMESTAMP";
        $stmt = $pdo->prepare($insertSql);
        
        $totals = ['states' => 0, 'legislators' => 0, 'errors' => 0, 'skipped' => 0];
        
        foreach ($statesToImport as $abbr => $name) {
            log_msg("Processing $name (" . strtoupper($abbr) . ")...", 'info');
            
            if (!isset($orgMap[$abbr])) {
                log_msg("  ❌ No org_id mapping — skipping (run Fix Mappings first)", 'error');
                $totals['skipped']++;
                continue;
            }
            
            $orgId = $orgMap[$abbr];
            $branchId = $branchMap[$orgId] ?? null;
            
            $page = 1;
            $stateCount = 0;
            
            do {
                $url = "{$config['api_base']}/people?jurisdiction=$abbr&per_page={$config['per_page']}&page=$page";
                $data = fetchFromApi($url, $config['api_key']);
                
                if (isset($data['error'])) {
                    log_msg("  ❌ API error (HTTP {$data['code']})", 'error');
                    $totals['errors']++;
                    break;
                }
                
                $results = $data['results'] ?? [];
                $maxPage = $data['pagination']['max_page'] ?? 1;
                
                foreach ($results as $leg) {
                    try {
                        $record = mapLegislator($leg, $orgId, $branchId, $abbr);
                        $stmt->execute($record);
                        $stateCount++;
                    } catch (Exception $e) {
                        $totals['errors']++;
                    }
                }
                
                $page++;
                sleep(1); // Rate limit
                
            } while ($page <= $maxPage && $page <= 20);
            
            log_msg("  ✅ Imported $stateCount legislators", 'success');
            $totals['legislators'] += $stateCount;
            $totals['states']++;
            
            sleep($config['delay_between_states']);
        }
        
        echo $isCli ? '' : '</div>';
        
        echo "<p><strong>Complete:</strong> {$totals['states']} states | {$totals['legislators']} legislators | {$totals['skipped']} skipped | {$totals['errors']} errors</p>";
        
        if (!$isCli) {
            echo "<p><a href='?key=$secretKey' class='nav'>← Back to Status</a></p>";
        }
    }
}

// ============================================================================
// HTML FOOTER
// ============================================================================

if (!$isCli) {
    echo '</div></body></html>';
}
