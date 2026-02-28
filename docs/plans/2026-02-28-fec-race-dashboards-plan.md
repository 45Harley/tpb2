# FEC Race Dashboards Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a "Races" page to the elections section showing admin-curated competitive 2026 federal races with FEC campaign finance data (candidates, fundraising, top donors), synced nightly via cron.

**Architecture:** Admin picks races to track in admin.php. A nightly cron (`sync-fec-data.php`) pulls candidate and donor data from the OpenFEC API into three local DB cache tables (`fec_races`, `fec_candidates`, `fec_top_contributors`). The public `elections/races.php` page reads from cache — never hits FEC live. All elections pages get a new "Races" link in their sub-nav.

**Tech Stack:** PHP 8.4, MySQL (`sandge5_election` database), OpenFEC API (`api.open.fec.gov/v1/`), existing TPB includes (header, nav, get-user, site-settings, set-cookie)

---

### Task 1: DB Migration — Create FEC Tables

**Files:**
- Create: `scripts/db/add-fec-tables.sql`

**Step 1: Write the migration SQL**

```sql
-- ============================================================
-- FEC Race Dashboard Tables — Schema
-- Competitive race tracking with cached FEC data.
-- Run on sandge5_election database.
-- ============================================================

CREATE TABLE IF NOT EXISTS fec_races (
  race_id INT AUTO_INCREMENT PRIMARY KEY,
  cycle SMALLINT NOT NULL DEFAULT 2026,
  office ENUM('H','S') NOT NULL,
  state CHAR(2) NOT NULL,
  district CHAR(2) DEFAULT NULL,
  rating VARCHAR(20) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_race (cycle, office, state, district)
);

CREATE TABLE IF NOT EXISTS fec_candidates (
  fec_candidate_id VARCHAR(20) PRIMARY KEY,
  race_id INT NOT NULL,
  official_id INT DEFAULT NULL,
  committee_id VARCHAR(12) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  party VARCHAR(10) DEFAULT NULL,
  incumbent_challenge CHAR(1) DEFAULT NULL,
  total_receipts DECIMAL(14,2) DEFAULT 0,
  total_disbursements DECIMAL(14,2) DEFAULT 0,
  cash_on_hand DECIMAL(14,2) DEFAULT 0,
  last_filing_date DATE DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_race (race_id)
);

CREATE TABLE IF NOT EXISTS fec_top_contributors (
  contributor_id INT AUTO_INCREMENT PRIMARY KEY,
  fec_candidate_id VARCHAR(20) NOT NULL,
  contributor_name VARCHAR(200) NOT NULL,
  contributor_type ENUM('individual','pac') NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  employer VARCHAR(200) DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_candidate (fec_candidate_id)
);
```

**Step 2: Run migration on staging**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_election', \$c['username'], \$c['password']);
\$sql = file_get_contents('/home/sandge5/tpb2.sandgems.net/scripts/db/add-fec-tables.sql');
foreach (explode(';', \$sql) as \$stmt) {
    \$stmt = trim(\$stmt);
    if (\$stmt) \$p->exec(\$stmt);
}
echo 'Migration done. Tables: ';
\$r = \$p->query("SHOW TABLES LIKE 'fec_%'");
\$tables = \$r->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', \$tables) . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `Migration done. Tables: fec_candidates, fec_races, fec_top_contributors`

**Note:** Migration must be run AFTER pushing the SQL file to staging via `git push` + `git pull`.

**Step 3: Commit**

```bash
git add scripts/db/add-fec-tables.sql
git commit -m "db: add FEC race dashboard tables (fec_races, fec_candidates, fec_top_contributors)"
```

---

### Task 2: Add FEC API Key to Server Config

**Files:**
- Modify (on server only): `config.php`

**Step 1: Add `fec_api_key` to staging config.php**

The user's FEC API key needs to be added. If the registered key hasn't arrived yet, use `DEMO_KEY` temporarily (30 req/hr limit). The key goes in the `config.php` array:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
echo isset(\$c['fec_api_key']) ? 'FEC key exists: ' . substr(\$c['fec_api_key'], 0, 8) . '...' : 'FEC key NOT set';
echo PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

If not set, add it manually:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat >> /home/sandge5/tpb2.sandgems.net/config.php << 'APPEND'

// FEC API key (api.data.gov) — 1000 req/hr
// Sign up: https://api.data.gov/signup/
// 'fec_api_key' => 'YOUR_KEY_HERE',
APPEND"
```

**Important:** The key must be inside the return array in config.php, not appended at the end. The human should edit this manually or confirm the approach. For now, the cron script will fall back to `DEMO_KEY` if the config key is missing.

**Step 2: Verify**

Re-run the check script above. Expected: `FEC key exists: DEMO_KEY...` (or the real key prefix).

---

### Task 3: FEC Data Sync Cron Script

**Files:**
- Create: `scripts/maintenance/sync-fec-data.php`
- Reference: `scripts/maintenance/send-threat-bulletin.php` (same bootstrap pattern)
- Reference: `includes/site-settings.php` (`getSiteSetting`)

**Step 1: Write the cron script**

The script:
1. Bootstraps (config, PDO to `sandge5_election`)
2. Checks kill switch (`fec_sync_enabled` in site_settings on `sandge5_tpb2`)
3. For each active race in `fec_races`:
   a. Calls FEC `/v1/elections/` to get candidates + financials
   b. Upserts into `fec_candidates`
   c. For each candidate, calls `/v1/schedules/schedule_a/` for top contributors
   d. Replaces `fec_top_contributors` for that candidate
4. Throttles 500ms between API calls
5. Logs summary

```php
<?php
/**
 * FEC Data Sync — Nightly Cron
 *
 * Pulls candidate and contributor data from OpenFEC API
 * into local cache tables for the race dashboard.
 *
 * Run via cPanel cron: 0 7 * * * (2:00 AM ET = 7:00 AM UTC)
 *
 * Requirements:
 *   - site_settings.fec_sync_enabled = '1'
 *   - config.php has 'fec_api_key' (falls back to DEMO_KEY)
 *   - fec_races table has at least one active race
 *
 * Usage:
 *   cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/sync-fec-data.php
 */

$startTime = microtime(true);

// Bootstrap
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/site-settings.php';

// TPB2 DB for site_settings check
$pdoTpb = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Check kill switch
if (getSiteSetting($pdoTpb, 'fec_sync_enabled', '0') !== '1') {
    echo "FEC sync is disabled. Exiting.\n";
    exit(0);
}

// Election DB for FEC tables
$pdo = new PDO(
    "mysql:host={$config['host']};dbname=sandge5_election;charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$apiKey = $config['fec_api_key'] ?? 'DEMO_KEY';
$apiBase = 'https://api.open.fec.gov/v1';

// Get active races
$races = $pdo->query("
    SELECT race_id, cycle, office, state, district
    FROM fec_races
    WHERE is_active = 1
    ORDER BY state, district
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($races)) {
    echo "No active races to sync. Exiting.\n";
    exit(0);
}

// Prepared statements
$upsertCandidate = $pdo->prepare("
    INSERT INTO fec_candidates (fec_candidate_id, race_id, committee_id, name, party, incumbent_challenge,
                                total_receipts, total_disbursements, cash_on_hand, last_filing_date, last_synced_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        race_id = VALUES(race_id),
        committee_id = VALUES(committee_id),
        name = VALUES(name),
        party = VALUES(party),
        incumbent_challenge = VALUES(incumbent_challenge),
        total_receipts = VALUES(total_receipts),
        total_disbursements = VALUES(total_disbursements),
        cash_on_hand = VALUES(cash_on_hand),
        last_filing_date = VALUES(last_filing_date),
        last_synced_at = NOW()
");

$deleteContributors = $pdo->prepare("DELETE FROM fec_top_contributors WHERE fec_candidate_id = ?");
$insertContributor = $pdo->prepare("
    INSERT INTO fec_top_contributors (fec_candidate_id, contributor_name, contributor_type, total_amount, employer, last_synced_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

$totalCandidates = 0;
$totalContributors = 0;
$errors = 0;

foreach ($races as $race) {
    $officeParam = $race['office'] === 'H' ? 'house' : 'senate';
    $params = [
        'api_key' => $apiKey,
        'cycle' => $race['cycle'],
        'office' => $officeParam,
        'state' => $race['state'],
        'per_page' => 20,
    ];
    if ($race['office'] === 'H' && $race['district']) {
        $params['district'] = $race['district'];
    }

    // Fetch candidates for this race
    $url = $apiBase . '/elections/?' . http_build_query($params);
    $response = @file_get_contents($url);
    usleep(500000); // 500ms throttle

    if ($response === false) {
        error_log("FEC sync: failed to fetch {$race['state']}-{$race['district']} ({$race['office']})");
        $errors++;
        continue;
    }

    $data = json_decode($response, true);
    $results = $data['results'] ?? [];

    foreach ($results as $cand) {
        $candId = $cand['candidate_id'] ?? null;
        if (!$candId) continue;

        $upsertCandidate->execute([
            $candId,
            $race['race_id'],
            $cand['candidate_pcc_id'] ?? null,
            $cand['candidate_name'] ?? 'Unknown',
            $cand['party'] ?? null,
            $cand['incumbent_challenge_full'] ? substr($cand['incumbent_challenge_full'], 0, 1) : null,
            $cand['total_receipts'] ?? 0,
            $cand['total_disbursements'] ?? 0,
            $cand['cash_on_hand_end_period'] ?? 0,
            $cand['coverage_end_date'] ?? null,
        ]);
        $totalCandidates++;

        // Fetch top contributors if we have a committee ID
        $committeeId = $cand['candidate_pcc_id'] ?? null;
        if ($committeeId) {
            $contribUrl = $apiBase . '/schedules/schedule_a/?' . http_build_query([
                'api_key' => $apiKey,
                'committee_id' => $committeeId,
                'two_year_transaction_period' => $race['cycle'],
                'sort' => '-contribution_receipt_amount',
                'per_page' => 10,
                'is_individual' => 'true',
            ]);
            $contribResponse = @file_get_contents($contribUrl);
            usleep(500000); // 500ms throttle

            if ($contribResponse !== false) {
                $contribData = json_decode($contribResponse, true);
                $contributions = $contribData['results'] ?? [];

                // Replace old contributors
                $deleteContributors->execute([$candId]);

                foreach ($contributions as $contrib) {
                    $insertContributor->execute([
                        $candId,
                        $contrib['contributor_name'] ?? 'Unknown',
                        ($contrib['entity_type'] ?? '') === 'IND' ? 'individual' : 'pac',
                        $contrib['contribution_receipt_amount'] ?? 0,
                        $contrib['contributor_employer'] ?? null,
                    ]);
                    $totalContributors++;
                }
            }
        }
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "FEC sync complete. Races: " . count($races) . ", Candidates: {$totalCandidates}, Contributors: {$totalContributors}, Errors: {$errors}, Time: {$elapsed}s\n";
```

**Step 2: Test the cron script**

First, seed at least one test race (after migration is run):

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_election', \$c['username'], \$c['password']);
\$p->exec("INSERT IGNORE INTO fec_races (cycle, office, state, district, rating) VALUES (2026, 'H', 'NY', '19', 'Toss-Up')");
\$p->exec("INSERT IGNORE INTO fec_races (cycle, office, state, district, rating) VALUES (2026, 'S', 'GA', NULL, 'Toss-Up')");
echo 'Seeded ' . \$p->query('SELECT COUNT(*) FROM fec_races')->fetchColumn() . ' races' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Then enable FEC sync in site_settings:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('fec_sync_enabled', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
echo 'FEC sync enabled' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Then run the sync:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/sync-fec-data.php"
```

Expected: `FEC sync complete. Races: 2, Candidates: N, Contributors: N, Errors: 0, Time: Xs`

**Step 3: Verify data**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_election', \$c['username'], \$c['password']);
echo '--- Candidates ---' . PHP_EOL;
\$r = \$p->query('SELECT fec_candidate_id, name, party, incumbent_challenge, total_receipts, cash_on_hand FROM fec_candidates ORDER BY total_receipts DESC LIMIT 10');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
echo PHP_EOL . '--- Top Contributors (first 5) ---' . PHP_EOL;
\$r = \$p->query('SELECT c.name, t.contributor_name, t.total_amount, t.contributor_type FROM fec_top_contributors t JOIN fec_candidates c ON t.fec_candidate_id = c.fec_candidate_id ORDER BY t.total_amount DESC LIMIT 5');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: Candidate rows with financial data, contributor rows with names/amounts.

**Step 4: Commit**

```bash
git add scripts/maintenance/sync-fec-data.php
git commit -m "feat: add FEC data sync cron for race dashboards"
```

---

### Task 4: Admin Races Tab — POST Handler and Data Queries

**Files:**
- Modify: `admin.php`

This task adds the server-side logic. The next task adds the HTML tab.

**Step 1: Add election DB connection**

After the existing PDO connection (~line 16-25), add a second connection for the election database:

```php
// Election DB (for FEC race data)
try {
    $pdoElection = new PDO(
        "mysql:host={$config['host']};dbname=sandge5_election;charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    $pdoElection = null; // Graceful degradation — races tab just won't work
}
```

**Step 2: Add POST handlers for race management**

After the existing `save_settings` POST handler (line ~339), add:

```php
// Add a race
if (isset($_POST['add_race']) && $pdoElection) {
    validateCsrf();

    $office = $_POST['office'] ?? '';
    $state = strtoupper(trim($_POST['state'] ?? ''));
    $district = $office === 'H' ? str_pad(trim($_POST['district'] ?? ''), 2, '0', STR_PAD_LEFT) : null;
    $rating = $_POST['rating'] ?? null;
    $cycle = (int)($_POST['cycle'] ?? 2026);

    if (in_array($office, ['H', 'S']) && preg_match('/^[A-Z]{2}$/', $state)) {
        try {
            $stmt = $pdoElection->prepare("
                INSERT INTO fec_races (cycle, office, state, district, rating)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$cycle, $office, $state, $district, $rating]);
            logAdminAction($pdo, $adminUserId, 'add_race', 'fec_race', $pdoElection->lastInsertId(), [
                'office' => $office, 'state' => $state, 'district' => $district, 'rating' => $rating
            ]);
            $message = "Race added: {$state}" . ($district ? "-{$district}" : "") . " ({$office})";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'Duplicate') ? "Race already exists" : "Error adding race";
            $messageType = 'error';
        }
    } else {
        $message = "Invalid race data";
        $messageType = 'error';
    }
}

// Toggle race active/inactive
if (isset($_POST['toggle_race']) && $pdoElection) {
    validateCsrf();
    $raceId = (int)$_POST['race_id'];
    $newStatus = (int)$_POST['new_status'];
    $pdoElection->prepare("UPDATE fec_races SET is_active = ? WHERE race_id = ?")->execute([$newStatus, $raceId]);
    logAdminAction($pdo, $adminUserId, 'toggle_race', 'fec_race', $raceId, ['is_active' => $newStatus]);
    $message = "Race " . ($newStatus ? 'activated' : 'deactivated');
    $messageType = 'success';
}

// Delete a race (and its candidates/contributors)
if (isset($_POST['delete_race']) && $pdoElection) {
    validateCsrf();
    $raceId = (int)$_POST['race_id'];
    // Delete contributors for candidates in this race
    $pdoElection->prepare("DELETE t FROM fec_top_contributors t JOIN fec_candidates c ON t.fec_candidate_id = c.fec_candidate_id WHERE c.race_id = ?")->execute([$raceId]);
    $pdoElection->prepare("DELETE FROM fec_candidates WHERE race_id = ?")->execute([$raceId]);
    $pdoElection->prepare("DELETE FROM fec_races WHERE race_id = ?")->execute([$raceId]);
    logAdminAction($pdo, $adminUserId, 'delete_race', 'fec_race', $raceId);
    $message = "Race deleted";
    $messageType = 'success';
}
```

**Step 3: Add data queries for the Races tab**

In the stats/data section (after ~line 399, before the HTML output), add:

```php
// FEC Race data (for Races tab)
$fecRaces = [];
$fecCandidateCounts = [];
if ($pdoElection) {
    try {
        $fecRaces = $pdoElection->query("
            SELECT r.*,
                   (SELECT COUNT(*) FROM fec_candidates c WHERE c.race_id = r.race_id) as candidate_count,
                   (SELECT MAX(c.last_synced_at) FROM fec_candidates c WHERE c.race_id = r.race_id) as last_synced
            FROM fec_races r
            ORDER BY r.state, r.district
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $fecRaces = [];
    }
}
```

**Step 4: Commit**

```bash
git add admin.php
git commit -m "feat(admin): add race management POST handlers and data queries"
```

---

### Task 5: Admin Races Tab — HTML/UI

**Files:**
- Modify: `admin.php`

**Step 1: Add "Races" link to the tab nav**

Find the nav section (~line 856-865). Add the Races link after the Docs link:

```php
<a href="?tab=docs" class="<?= $tab === 'docs' ? 'active' : '' ?>">Docs</a>
<a href="?tab=races" class="<?= $tab === 'races' ? 'active' : '' ?>">Races</a>
<a href="?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
```

**Step 2: Add the Races tab content**

Before the `<?php elseif ($tab === 'settings'): ?>` block (~line 1546), add:

```php
<?php elseif ($tab === 'races'): ?>
    <!-- RACES TAB -->
    <h2 class="section-title">FEC Race Tracking</h2>

    <?php if (!$pdoElection): ?>
        <p style="color:#ef5350;">Election database connection failed. Cannot manage races.</p>
    <?php else: ?>

    <!-- Add Race Form -->
    <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;margin-bottom:20px;">
        <h3 style="color:#d4af37;margin:0 0 15px;">Add Race</h3>
        <form method="POST" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="add_race" value="1">

            <div>
                <label style="color:#888;font-size:0.85em;display:block;margin-bottom:4px;">Office</label>
                <select name="office" required style="background:#252525;color:#e0e0e0;border:1px solid #444;padding:8px 12px;border-radius:4px;">
                    <option value="H">House</option>
                    <option value="S">Senate</option>
                </select>
            </div>

            <div>
                <label style="color:#888;font-size:0.85em;display:block;margin-bottom:4px;">State</label>
                <input type="text" name="state" maxlength="2" placeholder="NY" required
                    style="background:#252525;color:#e0e0e0;border:1px solid #444;padding:8px 12px;border-radius:4px;width:60px;text-transform:uppercase;">
            </div>

            <div>
                <label style="color:#888;font-size:0.85em;display:block;margin-bottom:4px;">District (House only)</label>
                <input type="text" name="district" maxlength="2" placeholder="19"
                    style="background:#252525;color:#e0e0e0;border:1px solid #444;padding:8px 12px;border-radius:4px;width:60px;">
            </div>

            <div>
                <label style="color:#888;font-size:0.85em;display:block;margin-bottom:4px;">Rating</label>
                <select name="rating" style="background:#252525;color:#e0e0e0;border:1px solid #444;padding:8px 12px;border-radius:4px;">
                    <option value="">-- None --</option>
                    <option value="Toss-Up">Toss-Up</option>
                    <option value="Lean D">Lean D</option>
                    <option value="Lean R">Lean R</option>
                    <option value="Likely D">Likely D</option>
                    <option value="Likely R">Likely R</option>
                </select>
            </div>

            <div>
                <label style="color:#888;font-size:0.85em;display:block;margin-bottom:4px;">Cycle</label>
                <input type="number" name="cycle" value="2026" min="2024" max="2030"
                    style="background:#252525;color:#e0e0e0;border:1px solid #444;padding:8px 12px;border-radius:4px;width:80px;">
            </div>

            <button type="submit" style="background:#d4af37;color:#000;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Add Race</button>
        </form>
    </div>

    <!-- Race List -->
    <?php if (empty($fecRaces)): ?>
        <p style="color:#888;text-align:center;padding:20px;">No races tracked yet. Add one above.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr>
                <th>State</th>
                <th>District</th>
                <th>Office</th>
                <th>Rating</th>
                <th>Candidates</th>
                <th>Last Sync</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($fecRaces as $race): ?>
                <?php
                    $ratingColors = [
                        'Toss-Up' => '#9c27b0',
                        'Lean D' => '#42a5f5',
                        'Lean R' => '#ef5350',
                        'Likely D' => '#1565c0',
                        'Likely R' => '#c62828',
                    ];
                    $rColor = $ratingColors[$race['rating'] ?? ''] ?? '#555';
                ?>
                <tr>
                    <td><?= htmlspecialchars($race['state']) ?></td>
                    <td><?= $race['district'] ? htmlspecialchars($race['district']) : '—' ?></td>
                    <td><?= $race['office'] === 'H' ? 'House' : 'Senate' ?></td>
                    <td>
                        <?php if ($race['rating']): ?>
                            <span style="background:<?= $rColor ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:0.85em;"><?= htmlspecialchars($race['rating']) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $race['candidate_count'] ?></td>
                    <td style="color:#888;font-size:0.85em;"><?= $race['last_synced'] ? date('M j g:ia', strtotime($race['last_synced'])) : 'Never' ?></td>
                    <td>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="toggle_race" value="1">
                            <input type="hidden" name="race_id" value="<?= $race['race_id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $race['is_active'] ? 0 : 1 ?>">
                            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:0.9em;color:<?= $race['is_active'] ? '#4caf50' : '#ef5350' ?>;">
                                <?= $race['is_active'] ? 'Active' : 'Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this race and all its cached data?')">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="delete_race" value="1">
                            <input type="hidden" name="race_id" value="<?= $race['race_id'] ?>">
                            <button type="submit" style="background:none;border:none;cursor:pointer;color:#ef5350;font-size:0.85em;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>

    <!-- FEC Sync Settings -->
    <div style="background:#1a1a1a;border:1px solid #333;border-radius:8px;padding:20px;margin-top:20px;">
        <h3 style="color:#d4af37;margin:0 0 15px;">FEC Sync</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="save_settings" value="1">
            <?php $fecSyncEnabled = getSiteSetting($pdo, 'fec_sync_enabled', '0'); ?>
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:15px;">
                <input type="checkbox" name="fec_sync_enabled" value="1" <?= $fecSyncEnabled === '1' ? 'checked' : '' ?>
                    style="width:18px;height:18px;accent-color:#d4af37;">
                <span style="color:#e0e0e0;">Enable nightly FEC data sync</span>
            </label>
            <div style="color:#888;font-size:0.9em;margin-bottom:15px;">
                Status: <strong style="color:<?= $fecSyncEnabled === '1' ? '#4caf50' : '#ef5350' ?>;"><?= $fecSyncEnabled === '1' ? 'ON' : 'OFF' ?></strong>
                &nbsp;|&nbsp; Cron: <code style="background:#252525;padding:2px 6px;border-radius:3px;">0 7 * * *</code> (2 AM ET)
            </div>
            <button type="submit" style="background:#d4af37;color:#000;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Save</button>
        </form>
    </div>

    <?php endif; ?>
```

**Note on the save_settings handler:** The existing handler only saves `threat_bulletin_enabled`. It needs to be updated to also handle `fec_sync_enabled`. Add this inside the existing `save_settings` POST block (after the `threat_bulletin_enabled` line ~331):

```php
if (isset($_POST['fec_sync_enabled'])) {
    $fecSyncEnabled = !empty($_POST['fec_sync_enabled']) ? '1' : '0';
    setSiteSetting($pdo, 'fec_sync_enabled', $fecSyncEnabled, $adminUserId);
    logAdminAction($pdo, $adminUserId, 'update_setting', 'site_setting', null, [
        'key' => 'fec_sync_enabled',
        'value' => $fecSyncEnabled
    ]);
}
```

**Step 3: Commit**

```bash
git add admin.php
git commit -m "feat(admin): add Races tab with add/toggle/delete and FEC sync settings"
```

---

### Task 6: Public Races Page

**Files:**
- Create: `elections/races.php`
- Reference: `elections/threats.php` (page structure pattern)
- Reference: `elections/index.php` (view-links nav, share buttons, OG tags)

**Step 1: Write the page**

The page:
1. Connects to both DBs (tpb2 for auth, election for FEC data)
2. Queries all active races with their candidates and top contributors
3. Renders expandable race cards with fundraising bars and donor lists
4. Includes elections sub-nav, share buttons, OG meta tags

```php
<?php
/**
 * Race Dashboard — 2026 Competitive Races
 * ========================================
 * Shows admin-curated competitive federal races with
 * FEC campaign finance data (cached nightly by cron).
 */

$c = require dirname(__DIR__) . '/config.php';
$pdo = new PDO('mysql:host='.$c['host'].';dbname='.$c['database'], $c['username'], $c['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'elections';
$pageTitle = '2026 Races — The People\'s Branch';
$ogTitle = '2026 Competitive Races — Follow the Money';
$ogDescription = 'Track competitive federal races, candidate fundraising, and top donors. See who funds the people who want power.';

// Election DB
$pdoE = new PDO('mysql:host='.$c['host'].';dbname=sandge5_election', $c['username'], $c['password']);
$pdoE->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get active races
$races = $pdoE->query("
    SELECT r.*
    FROM fec_races r
    WHERE r.is_active = 1
    ORDER BY r.state, r.district
")->fetchAll(PDO::FETCH_ASSOC);

// Get all candidates grouped by race
$candidates = [];
if (!empty($races)) {
    $raceIds = array_column($races, 'race_id');
    $placeholders = implode(',', array_fill(0, count($raceIds), '?'));
    $stmt = $pdoE->prepare("
        SELECT c.*
        FROM fec_candidates c
        WHERE c.race_id IN ({$placeholders})
        ORDER BY c.total_receipts DESC
    ");
    $stmt->execute($raceIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
        $candidates[$cand['race_id']][] = $cand;
    }
}

// Get all contributors indexed by candidate
$contributors = [];
if (!empty($candidates)) {
    $allCandIds = [];
    foreach ($candidates as $raceCands) {
        foreach ($raceCands as $cand) {
            $allCandIds[] = $cand['fec_candidate_id'];
        }
    }
    if (!empty($allCandIds)) {
        $placeholders = implode(',', array_fill(0, count($allCandIds), '?'));
        $stmt = $pdoE->prepare("
            SELECT * FROM fec_top_contributors
            WHERE fec_candidate_id IN ({$placeholders})
            ORDER BY total_amount DESC
        ");
        $stmt->execute($allCandIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $contrib) {
            $contributors[$contrib['fec_candidate_id']][] = $contrib;
        }
    }
}

$raceCount = count($races);
$siteUrl = $c['base_url'] ?? 'https://tpb2.sandgems.net';
$shareText = "{$raceCount} competitive races. See who's funding your candidates. Follow the money.";

// Rating badge colors
function getRatingColor($rating) {
    return match($rating) {
        'Toss-Up' => '#9c27b0',
        'Lean D' => '#42a5f5',
        'Lean R' => '#ef5350',
        'Likely D' => '#1565c0',
        'Likely R' => '#c62828',
        default => '#555',
    };
}

function formatMoney($amount) {
    if ($amount >= 1000000) return '$' . number_format($amount / 1000000, 1) . 'M';
    if ($amount >= 1000) return '$' . number_format($amount / 1000, 0) . 'K';
    return '$' . number_format($amount, 0);
}

$pageStyles = <<<'CSS'
.races-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; }

.view-links { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.view-links a {
    padding: 0.4rem 1rem; border-radius: 20px; text-decoration: none;
    color: #888; border: 1px solid #333; font-size: 0.9rem; transition: all 0.2s;
}
.view-links a:hover { border-color: #d4af37; color: #d4af37; }
.view-links a.active { background: #d4af37; color: #000; border-color: #d4af37; }

.races-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #0a0a0f 100%);
    padding: 2rem; text-align: center; border-radius: 8px;
    border-bottom: 3px solid #d4af37; margin-bottom: 2rem;
}
.races-hero h1 { color: #d4af37; font-size: 2em; margin: 0 0 0.5rem; }
.races-hero p { color: #aaa; margin: 0; font-size: 1.05rem; }
.races-hero .stat { color: #fff; font-size: 1.8em; font-weight: bold; }

.race-card {
    background: #1a1a1a; border: 1px solid #333; border-radius: 8px;
    margin-bottom: 1rem; overflow: hidden;
}
.race-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px; cursor: pointer; transition: background 0.2s;
}
.race-header:hover { background: #222; }
.race-title { font-size: 1.15em; font-weight: 700; color: #e0e0e0; }
.race-meta { display: flex; gap: 10px; align-items: center; }
.rating-badge {
    padding: 3px 10px; border-radius: 4px; font-size: 0.8em;
    font-weight: 600; color: #fff;
}
.expand-icon { color: #888; font-size: 1.2em; transition: transform 0.2s; }
.race-card.open .expand-icon { transform: rotate(180deg); }

.race-body { display: none; padding: 0 20px 20px; border-top: 1px solid #333; }
.race-card.open .race-body { display: block; }

.candidate-row {
    display: flex; gap: 20px; padding: 12px 0;
    border-bottom: 1px solid #2a2a2a;
}
.candidate-row:last-child { border-bottom: none; }
.candidate-info { flex: 0 0 200px; }
.candidate-name { font-weight: 600; color: #e0e0e0; font-size: 1em; }
.candidate-meta { color: #888; font-size: 0.85em; margin-top: 2px; }
.party-badge {
    display: inline-block; padding: 1px 6px; border-radius: 3px;
    font-size: 0.75em; font-weight: 600; color: #fff;
}
.party-dem { background: #1565c0; }
.party-rep { background: #c62828; }
.party-other { background: #555; }

.finance-bars { flex: 1; min-width: 0; }
.bar-row { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-size: 0.85em; }
.bar-label { width: 55px; color: #888; text-align: right; flex-shrink: 0; }
.bar-track { flex: 1; height: 18px; background: #252525; border-radius: 3px; overflow: hidden; position: relative; }
.bar-fill { height: 100%; border-radius: 3px; transition: width 0.5s ease; }
.bar-fill.raised { background: #4caf50; }
.bar-fill.spent { background: #ef5350; }
.bar-fill.cash { background: #42a5f5; }
.bar-amount { width: 70px; color: #ccc; font-weight: 600; flex-shrink: 0; }

.donors-toggle {
    background: none; border: 1px solid #444; color: #888; padding: 6px 14px;
    border-radius: 4px; cursor: pointer; font-size: 0.85em; margin-top: 8px;
    transition: all 0.2s;
}
.donors-toggle:hover { border-color: #d4af37; color: #d4af37; }
.donors-list { display: none; margin-top: 10px; }
.donors-list.open { display: block; }
.donor-row {
    display: flex; justify-content: space-between; padding: 4px 0;
    font-size: 0.85em; border-bottom: 1px solid #222;
}
.donor-name { color: #ccc; }
.donor-amount { color: #4caf50; font-weight: 600; }
.donor-type { color: #888; font-size: 0.8em; }

.share-section { text-align: center; margin: 2rem 0; }
.share-row { display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap; margin: 1rem 0; }
.share-btn {
    padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none;
    font-weight: 600; font-size: 0.85rem; transition: transform 0.2s, opacity 0.2s;
    display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer;
}
.share-btn:hover { transform: translateY(-2px); opacity: 0.9; }
.share-btn.x { background: #000; color: #fff; border: 1px solid #333; }
.share-btn.bsky { background: #0085ff; color: #fff; }
.share-btn.fb { background: #1877f2; color: #fff; }
.share-btn.email { background: #333; color: #e0e0e0; border: 1px solid #555; }

.no-races { text-align: center; padding: 3rem; color: #888; }

@media (max-width: 600px) {
    .candidate-row { flex-direction: column; gap: 8px; }
    .candidate-info { flex: none; }
    .bar-label { width: 45px; }
    .bar-amount { width: 60px; font-size: 0.8em; }
}
CSS;

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/nav.php';
?>

<main class="races-container">

    <div class="view-links">
        <a href="/elections/">Elections</a>
        <a href="/elections/the-fight.php">The Fight</a>
        <a href="/elections/the-amendment.php">The War</a>
        <a href="/elections/threats.php">Threats</a>
        <a href="/elections/races.php" class="active">Races</a>
    </div>

    <section class="races-hero">
        <h1>Follow the Money</h1>
        <p><span class="stat"><?= $raceCount ?></span> competitive races tracked</p>
        <p style="margin-top:0.5rem;">Who's funding the people who want power over you?</p>
    </section>

    <?php if (empty($races)): ?>
        <div class="no-races">
            <p>No races are being tracked yet. Check back soon.</p>
        </div>
    <?php else: ?>
        <?php foreach ($races as $race):
            $raceCands = $candidates[$race['race_id']] ?? [];
            $ratingColor = getRatingColor($race['rating']);
            $label = $race['state'] . ($race['district'] ? '-' . $race['district'] : '') . ' ' . ($race['office'] === 'H' ? 'House' : 'Senate');
            // Find max receipts for bar scaling
            $maxReceipts = 1;
            foreach ($raceCands as $cand) {
                $maxReceipts = max($maxReceipts, $cand['total_receipts']);
            }
        ?>
        <div class="race-card" id="race-<?= $race['race_id'] ?>">
            <div class="race-header" onclick="this.parentElement.classList.toggle('open')">
                <div>
                    <span class="race-title"><?= htmlspecialchars($label) ?></span>
                    <span style="color:#888;font-size:0.85em;margin-left:8px;"><?= count($raceCands) ?> candidates</span>
                </div>
                <div class="race-meta">
                    <?php if ($race['rating']): ?>
                        <span class="rating-badge" style="background:<?= $ratingColor ?>"><?= htmlspecialchars($race['rating']) ?></span>
                    <?php endif; ?>
                    <span class="expand-icon">&#9660;</span>
                </div>
            </div>
            <div class="race-body">
                <?php if (empty($raceCands)): ?>
                    <p style="color:#888;padding:12px 0;">No candidate data synced yet. Data will appear after the next FEC sync.</p>
                <?php else: ?>
                    <?php foreach ($raceCands as $cand):
                        $partyClass = match(strtoupper($cand['party'] ?? '')) {
                            'DEM' => 'party-dem',
                            'REP' => 'party-rep',
                            default => 'party-other',
                        };
                        $challengeLabel = match($cand['incumbent_challenge']) {
                            'I' => 'Incumbent',
                            'C' => 'Challenger',
                            'O' => 'Open Seat',
                            default => '',
                        };
                        $receiptsW = $maxReceipts > 0 ? round(($cand['total_receipts'] / $maxReceipts) * 100) : 0;
                        $spentW = $maxReceipts > 0 ? round(($cand['total_disbursements'] / $maxReceipts) * 100) : 0;
                        $cashW = $maxReceipts > 0 ? round(($cand['cash_on_hand'] / $maxReceipts) * 100) : 0;
                        $candContribs = $contributors[$cand['fec_candidate_id']] ?? [];
                    ?>
                    <div class="candidate-row">
                        <div class="candidate-info">
                            <div class="candidate-name"><?= htmlspecialchars($cand['name']) ?></div>
                            <div class="candidate-meta">
                                <span class="party-badge <?= $partyClass ?>"><?= htmlspecialchars($cand['party'] ?? '?') ?></span>
                                <?php if ($challengeLabel): ?>
                                    <span style="margin-left:4px;"><?= $challengeLabel ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="finance-bars">
                            <div class="bar-row">
                                <span class="bar-label">Raised</span>
                                <div class="bar-track"><div class="bar-fill raised" style="width:<?= $receiptsW ?>%"></div></div>
                                <span class="bar-amount"><?= formatMoney($cand['total_receipts']) ?></span>
                            </div>
                            <div class="bar-row">
                                <span class="bar-label">Spent</span>
                                <div class="bar-track"><div class="bar-fill spent" style="width:<?= $spentW ?>%"></div></div>
                                <span class="bar-amount"><?= formatMoney($cand['total_disbursements']) ?></span>
                            </div>
                            <div class="bar-row">
                                <span class="bar-label">Cash</span>
                                <div class="bar-track"><div class="bar-fill cash" style="width:<?= $cashW ?>%"></div></div>
                                <span class="bar-amount"><?= formatMoney($cand['cash_on_hand']) ?></span>
                            </div>

                            <?php if (!empty($candContribs)): ?>
                                <button class="donors-toggle" onclick="this.nextElementSibling.classList.toggle('open'); this.textContent = this.nextElementSibling.classList.contains('open') ? 'Hide Top Donors' : 'Show Top Donors';">Show Top Donors</button>
                                <div class="donors-list">
                                    <?php foreach ($candContribs as $contrib): ?>
                                        <div class="donor-row">
                                            <span class="donor-name">
                                                <?= htmlspecialchars($contrib['contributor_name']) ?>
                                                <span class="donor-type">(<?= $contrib['contributor_type'] ?>)</span>
                                            </span>
                                            <span class="donor-amount"><?= formatMoney($contrib['total_amount']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Share -->
    <div class="share-section">
        <p style="color:#888;margin-bottom:0.5rem;">Share the race tracker</p>
        <div class="share-row">
            <a href="https://twitter.com/intent/tweet?text=<?= urlencode($shareText . ' ' . $siteUrl . '/elections/races.php') ?>" target="_blank" class="share-btn x">X / Twitter</a>
            <a href="https://bsky.app/intent/compose?text=<?= urlencode($shareText . ' ' . $siteUrl . '/elections/races.php') ?>" target="_blank" class="share-btn bsky">Bluesky</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($siteUrl . '/elections/races.php') ?>" target="_blank" class="share-btn fb">Facebook</a>
            <a href="mailto:?subject=<?= rawurlencode('2026 Race Tracker — Follow the Money') ?>&body=<?= rawurlencode($shareText . "\n\n" . $siteUrl . '/elections/races.php') ?>" class="share-btn email">Email</a>
        </div>
    </div>

</main>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
```

**Step 2: Commit**

```bash
git add elections/races.php
git commit -m "feat: add public race dashboard page with FEC financial data"
```

---

### Task 7: Add "Races" Link to Elections Sub-Nav

**Files:**
- Modify: `elections/index.php` (~line 161-166)
- Modify: `elections/the-fight.php` (~line 226-231)
- Modify: `elections/the-amendment.php` (~line 172-177)
- Modify: `elections/threats.php` (~line 266-271)

**Step 1: Update all four elections pages**

In each file, find the `<div class="view-links">` block and add the Races link. The updated block for each file:

**`elections/index.php` (line ~161-166):**
```html
<div class="view-links">
    <a href="/elections/" class="active">Elections</a>
    <a href="/elections/the-fight.php">The Fight</a>
    <a href="/elections/the-amendment.php">The War</a>
    <a href="/elections/threats.php">Threats</a>
    <a href="/elections/races.php">Races</a>
</div>
```

**`elections/the-fight.php` (line ~226-231):**
```html
<div class="view-links">
    <a href="/elections/">Elections</a>
    <a href="/elections/the-fight.php" class="active">The Fight</a>
    <a href="/elections/the-amendment.php">The War</a>
    <a href="/elections/threats.php">Threats</a>
    <a href="/elections/races.php">Races</a>
</div>
```

**`elections/the-amendment.php` (line ~172-177):**
```html
<div class="view-links">
    <a href="/elections/">Elections</a>
    <a href="/elections/the-fight.php">The Fight</a>
    <a href="/elections/the-amendment.php" class="active">The War</a>
    <a href="/elections/threats.php">Threats</a>
    <a href="/elections/races.php">Races</a>
</div>
```

**`elections/threats.php` (line ~266-271):**
```html
<div class="view-links">
    <a href="/elections/">Elections</a>
    <a href="/elections/the-fight.php">The Fight</a>
    <a href="/elections/the-amendment.php">The War</a>
    <a href="/elections/threats.php" class="active">Threats</a>
    <a href="/elections/races.php">Races</a>
</div>
```

**Step 2: Commit**

```bash
git add elections/index.php elections/the-fight.php elections/the-amendment.php elections/threats.php
git commit -m "feat: add Races link to all elections sub-nav pages"
```

---

### Task 8: Push, Deploy, Migrate, Seed, and Test

**Step 1: Push to staging**

```bash
git push origin master
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 2: Run DB migration**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_election', \$c['username'], \$c['password']);
\$sql = file_get_contents('/home/sandge5/tpb2.sandgems.net/scripts/db/add-fec-tables.sql');
foreach (explode(';', \$sql) as \$stmt) {
    \$stmt = trim(\$stmt);
    if (\$stmt) \$p->exec(\$stmt);
}
echo 'Migration done. Tables: ';
\$r = \$p->query("SHOW TABLES LIKE 'fec_%'");
\$tables = \$r->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', \$tables) . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `Migration done. Tables: fec_candidates, fec_races, fec_top_contributors`

**Step 3: Seed test races via admin.php**

Navigate to `https://tpb2.sandgems.net/admin.php?tab=races` and add:
- NY-19 House, Toss-Up
- GA Senate, Toss-Up

Or via CLI:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_election', \$c['username'], \$c['password']);
\$p->exec("INSERT IGNORE INTO fec_races (cycle, office, state, district, rating) VALUES (2026, 'H', 'NY', '19', 'Toss-Up')");
\$p->exec("INSERT IGNORE INTO fec_races (cycle, office, state, district, rating) VALUES (2026, 'S', 'GA', NULL, 'Toss-Up')");
echo 'Seeded ' . \$p->query('SELECT COUNT(*) FROM fec_races')->fetchColumn() . ' races' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

**Step 4: Enable FEC sync and run**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('fec_sync_enabled', '1') ON DUPLICATE KEY UPDATE setting_value='1'");
echo 'FEC sync enabled' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Then run the sync:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/sync-fec-data.php"
```

Expected: `FEC sync complete. Races: 2, Candidates: N, Contributors: N, Errors: 0, Time: Xs`

**Step 5: Verify the public page**

Visit: `https://tpb2.sandgems.net/elections/races.php`

Expected:
- Page loads with "Follow the Money" hero
- 2 race cards (NY-19 House, GA Senate) with Toss-Up badges
- Click a card to expand — see candidates with fundraising bars
- "Show Top Donors" button expands donor list
- Share buttons at bottom
- Elections sub-nav shows Races link as active
- Other elections pages (threats, the-fight, the-amendment, index) now show "Races" in their sub-nav

**Step 6: Set up cPanel cron**

Add to cPanel cron jobs:
```
0 7 * * * cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/maintenance/sync-fec-data.php >> /home/sandge5/logs/fec-sync.log 2>&1
```

---

## Verification Checklist

1. `fec_races`, `fec_candidates`, `fec_top_contributors` tables exist in `sandge5_election`
2. Admin Races tab shows race list, add form, and FEC sync toggle
3. Adding/toggling/deleting races works from admin UI
4. Cron script fetches FEC data and populates candidate + contributor tables
5. `elections/races.php` renders race cards with financial bars
6. Expanding a card shows candidates with Raised/Spent/Cash bars
7. "Show Top Donors" expands donor list per candidate
8. Share buttons work (X, Bluesky, Facebook, Email)
9. All 5 elections pages show "Races" in sub-nav
10. Page works on mobile (responsive layout)
