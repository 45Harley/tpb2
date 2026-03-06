# Mandate Summary Layer 1 — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add AI dual-tagging to mandates at save time, build a topic-grouped aggregation API, and create a public summary page showing statistics a representative would use.

**Architecture:** Mandates get `citizen_summary` (plain language) and `policy_topic` (committee taxonomy) assigned by Claude Haiku at save time. A new API groups by topic with counts/percentages. A server-rendered PHP page displays scoreboard + topic breakdown + mandate list + CSV export.

**Tech Stack:** PHP 8.4, MySQL/MariaDB, Anthropic Claude API (Haiku), vanilla CSS (dark theme)

---

### Task 1: Schema Migration Script

**Files:**
- Create: `scripts/db/add-mandate-summary-tables.sql`

**Step 1: Write the SQL migration file**

```sql
-- Mandate Summary Schema
-- Run on sandge5_tpb2 database

-- 1. Add dual-tag columns to idea_log
ALTER TABLE idea_log
  ADD COLUMN citizen_summary VARCHAR(200) DEFAULT NULL AFTER tags,
  ADD COLUMN policy_topic VARCHAR(60) DEFAULT NULL AFTER citizen_summary;

-- 2. Index for fast topic aggregation
CREATE INDEX idx_idea_log_policy_topic ON idea_log (policy_topic, category, deleted_at);

-- 3. Create mandate_summaries table (Layer 3 prep, empty for now)
CREATE TABLE IF NOT EXISTS mandate_summaries (
  summary_id        INT AUTO_INCREMENT PRIMARY KEY,
  scope_type        ENUM('federal','state','town') NOT NULL,
  scope_value       VARCHAR(50) NOT NULL,
  period_start      DATE NOT NULL,
  period_end        DATE NOT NULL,
  mandate_count     INT DEFAULT 0,
  contributor_count INT DEFAULT 0,
  topic_breakdown   JSON,
  trending_topics   JSON,
  gap_analysis      JSON,
  narrative         TEXT,
  town_hall_agenda  TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Step 2: Run migration on server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec(\"ALTER TABLE idea_log ADD COLUMN citizen_summary VARCHAR(200) DEFAULT NULL AFTER tags, ADD COLUMN policy_topic VARCHAR(60) DEFAULT NULL AFTER citizen_summary\");
echo 'ALTER done'.PHP_EOL;
\$p->exec(\"CREATE INDEX idx_idea_log_policy_topic ON idea_log (policy_topic, category, deleted_at)\");
echo 'INDEX done'.PHP_EOL;
\$p->exec(\"CREATE TABLE IF NOT EXISTS mandate_summaries (summary_id INT AUTO_INCREMENT PRIMARY KEY, scope_type ENUM('federal','state','town') NOT NULL, scope_value VARCHAR(50) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, mandate_count INT DEFAULT 0, contributor_count INT DEFAULT 0, topic_breakdown JSON, trending_topics JSON, gap_analysis JSON, narrative TEXT, town_hall_agenda TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)\");
echo 'TABLE done'.PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `ALTER done`, `INDEX done`, `TABLE done`

**Step 3: Verify columns exist**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('DESCRIBE idea_log');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'].' | '.\$row['Type'].PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: Output includes `citizen_summary | varchar(200)` and `policy_topic | varchar(60)`

**Step 4: Commit**

```bash
git add scripts/db/add-mandate-summary-tables.sql
git commit -m "schema: add citizen_summary, policy_topic to idea_log + mandate_summaries table"
```

---

### Task 2: Topic Taxonomy Config

**Files:**
- Create: `config/mandate-topics.php`

**Step 1: Create the taxonomy constant file**

```php
<?php
/**
 * Mandate Policy Topics — Fixed Taxonomy
 *
 * Used by AI tagging at save time and by aggregation queries.
 * Maps committee-style policy areas that representatives use.
 *
 * To add a topic: append to this array and backfill existing mandates.
 */

const MANDATE_POLICY_TOPICS = [
    'Economy & Jobs',
    'Healthcare',
    'Education',
    'Infrastructure & Transportation',
    'Environment & Energy',
    'Public Safety & Justice',
    'Housing & Cost of Living',
    'Campaign Finance & Elections',
    'Civil Rights & Liberties',
    'Government Accountability',
    'Veterans & Military',
    'Immigration',
    'Technology & Privacy',
    'Agriculture',
    'Other',
];
```

**Step 2: Commit**

```bash
git add config/mandate-topics.php
git commit -m "feat: add mandate policy topic taxonomy config"
```

---

### Task 3: Save-Time AI Dual Tagging

**Files:**
- Modify: `talk/api.php:285-323` (after INSERT, before autoClassify block)

**Step 1: Add mandate classify block after line 285**

Find this code in `talk/api.php` (around line 285):

```php
    $id = (int)$pdo->lastInsertId();

    // Auto-classify via AI if requested
    $autoClassify = (bool)($input['auto_classify'] ?? false);
```

Insert between `$id = ...` and `// Auto-classify`:

```php
    $id = (int)$pdo->lastInsertId();

    // ── Mandate dual-tagging: AI assigns citizen_summary + policy_topic ──
    $mandateCategories = ['mandate-federal', 'mandate-state', 'mandate-town'];
    if (in_array($category, $mandateCategories) && $content !== '') {
        try {
            require_once __DIR__ . '/../config-claude.php';
            require_once __DIR__ . '/../config/mandate-topics.php';

            $topicList = implode(', ', MANDATE_POLICY_TOPICS);
            $classifySystem = "You classify citizen mandates. Respond with ONLY a JSON object on one line, nothing else:\n"
                . "{\"citizen_summary\": \"<plain language topic, 5-10 words, citizen's voice>\", "
                . "\"policy_topic\": \"<exactly one of: {$topicList}>\"}";

            $classifyMsgs = [['role' => 'user', 'content' => $content]];
            $resp = talkCallClaudeAPI($classifySystem, $classifyMsgs, 'claude-haiku-4-5-20251001', false);

            if (!isset($resp['error'])) {
                $aiText = '';
                foreach (($resp['content'] ?? []) as $block) {
                    if ($block['type'] === 'text') $aiText .= $block['text'];
                }
                if (preg_match('/\{[^}]+\}/', trim($aiText), $m)) {
                    $parsed = json_decode($m[0], true);
                    if ($parsed) {
                        $cSummary = trim($parsed['citizen_summary'] ?? '');
                        $pTopic   = trim($parsed['policy_topic'] ?? '');
                        // Validate topic is in taxonomy
                        if ($pTopic && !in_array($pTopic, MANDATE_POLICY_TOPICS)) {
                            $pTopic = 'Other';
                        }
                        if ($cSummary || $pTopic) {
                            $pdo->prepare("UPDATE idea_log SET citizen_summary = ?, policy_topic = ? WHERE id = ?")
                                ->execute([$cSummary ?: null, $pTopic ?: null, $id]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Tagging failed — mandate is saved, tags stay NULL for backfill
        }
    }

    // Auto-classify via AI if requested
    $autoClassify = (bool)($input['auto_classify'] ?? false);
```

**Step 2: Test manually**

Save a mandate via the POC page, then verify tagging:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query(\"SELECT id, content, citizen_summary, policy_topic FROM idea_log WHERE category LIKE 'mandate-%' ORDER BY id DESC LIMIT 5\");
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: Recent mandate rows show non-NULL citizen_summary and policy_topic values.

**Step 3: Commit**

```bash
git add talk/api.php
git commit -m "feat: dual AI tagging (citizen_summary + policy_topic) on mandate save"
```

---

### Task 4: Backfill Existing Mandates

**Files:**
- Create: `scripts/maintenance/backfill-mandate-topics.php`

**Step 1: Write the backfill script**

```php
<?php
/**
 * Backfill Mandate Topics
 * =======================
 * One-shot script to classify existing mandates that have NULL policy_topic.
 * Safe to re-run — only processes untagged rows.
 *
 * Usage: php scripts/maintenance/backfill-mandate-topics.php
 * Run on server: php /home/sandge5/tpb2.sandgems.net/scripts/maintenance/backfill-mandate-topics.php
 */

$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config-claude.php';
require_once __DIR__ . '/../../config/mandate-topics.php';

// Reuse the talk API's Claude helper
require_once __DIR__ . '/../../talk/api.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $pdo->prepare("
    SELECT id, content FROM idea_log
    WHERE category IN ('mandate-federal','mandate-state','mandate-town')
      AND policy_topic IS NULL AND deleted_at IS NULL
    ORDER BY id
");
$stmt->execute();
$rows = $stmt->fetchAll();

echo count($rows) . " mandates need tagging.\n";

$topicList = implode(', ', MANDATE_POLICY_TOPICS);
$systemPrompt = "You classify citizen mandates. Respond with ONLY a JSON object on one line, nothing else:\n"
    . "{\"citizen_summary\": \"<plain language topic, 5-10 words, citizen's voice>\", "
    . "\"policy_topic\": \"<exactly one of: {$topicList}>\"}";

$updated = 0;
$failed  = 0;

foreach ($rows as $row) {
    echo "  #{$row['id']}: ";
    try {
        $msgs = [['role' => 'user', 'content' => $row['content']]];
        $resp = talkCallClaudeAPI($systemPrompt, $msgs, 'claude-haiku-4-5-20251001', false);

        if (isset($resp['error'])) {
            echo "API error — skipped\n";
            $failed++;
            continue;
        }

        $aiText = '';
        foreach (($resp['content'] ?? []) as $block) {
            if ($block['type'] === 'text') $aiText .= $block['text'];
        }

        if (preg_match('/\{[^}]+\}/', trim($aiText), $m)) {
            $parsed = json_decode($m[0], true);
            $cSummary = trim($parsed['citizen_summary'] ?? '');
            $pTopic   = trim($parsed['policy_topic'] ?? '');
            if ($pTopic && !in_array($pTopic, MANDATE_POLICY_TOPICS)) {
                $pTopic = 'Other';
            }
            $pdo->prepare("UPDATE idea_log SET citizen_summary = ?, policy_topic = ? WHERE id = ?")
                ->execute([$cSummary ?: null, $pTopic ?: null, $row['id']]);
            echo "{$pTopic}\n";
            $updated++;
        } else {
            echo "no JSON in response — skipped\n";
            $failed++;
        }

        usleep(200000); // 200ms between calls to avoid rate limits
    } catch (\Throwable $e) {
        echo "error: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\nDone. Updated: {$updated}, Failed: {$failed}\n";
```

**Step 2: Commit (do NOT run yet — run after Task 3 is deployed)**

```bash
git add scripts/maintenance/backfill-mandate-topics.php
git commit -m "feat: backfill script for mandate topic classification"
```

**Step 3: Deploy and run**

After pushing to staging:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && php scripts/maintenance/backfill-mandate-topics.php"
```

Expected: Each mandate gets a policy_topic assigned, script reports "Updated: N, Failed: 0"

---

### Task 5: Summary Aggregation API

**Files:**
- Create: `api/mandate-summary.php`

**Step 1: Write the API endpoint**

```php
<?php
/**
 * Mandate Summary API
 * ===================
 * Returns topic-grouped aggregation for a geographic scope.
 *
 * GET parameters:
 *   scope       — federal | state | town (required)
 *   scope_value — district code, state_id, or town_id (required)
 *   period      — all | month | week (default: all)
 *
 * Response: { success, scope, scope_value, mandate_count, contributor_count,
 *             topics: [{policy_topic, count, pct, citizen_voices}],
 *             recent_activity: {this_week, last_week, trend},
 *             top_mandates: [{id, content, policy_topic, created_at}] }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$scope      = $_GET['scope'] ?? '';
$scopeValue = trim($_GET['scope_value'] ?? '');
$period     = $_GET['period'] ?? 'all';

// Validate
if (!in_array($scope, ['federal', 'state', 'town'], true) || $scopeValue === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scope (federal|state|town) and scope_value are required']);
    exit;
}
if (!in_array($period, ['all', 'month', 'week'], true)) {
    $period = 'all';
}

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Build scope filter
$categoryMap = ['federal' => 'mandate-federal', 'state' => 'mandate-state', 'town' => 'mandate-town'];
$category = $categoryMap[$scope];

$geoMap = ['federal' => 'u.us_congress_district', 'state' => 'u.current_state_id', 'town' => 'u.current_town_id'];
$geoCol = $geoMap[$scope];

$baseWhere = "i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL AND {$geoCol} = ?";
$baseParams = [$category, $scopeValue];

// Period filter
$periodWhere = '';
if ($period === 'month') {
    $periodWhere = ' AND i.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
} elseif ($period === 'week') {
    $periodWhere = ' AND i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
}

try {
    // 1. Total counts
    $sql = "SELECT COUNT(*) as cnt, COUNT(DISTINCT i.user_id) as contributors
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $counts = $stmt->fetch();
    $mandateCount     = (int)$counts['cnt'];
    $contributorCount = (int)$counts['contributors'];

    // 2. Topic breakdown
    $sql = "SELECT i.policy_topic, COUNT(*) as cnt
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere} AND i.policy_topic IS NOT NULL
            GROUP BY i.policy_topic
            ORDER BY cnt DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $topicRows = $stmt->fetchAll();

    $topics = [];
    foreach ($topicRows as $tr) {
        $pct = $mandateCount > 0 ? round(($tr['cnt'] / $mandateCount) * 100, 1) : 0;

        // Get citizen_voices for this topic (distinct, limit 5)
        $vSql = "SELECT DISTINCT i.citizen_summary
                 FROM idea_log i JOIN users u ON i.user_id = u.user_id
                 WHERE {$baseWhere}{$periodWhere} AND i.policy_topic = ? AND i.citizen_summary IS NOT NULL
                 LIMIT 5";
        $vStmt = $pdo->prepare($vSql);
        $vStmt->execute(array_merge($baseParams, [$tr['policy_topic']]));
        $voices = array_column($vStmt->fetchAll(), 'citizen_summary');

        $topics[] = [
            'policy_topic'  => $tr['policy_topic'],
            'count'         => (int)$tr['cnt'],
            'pct'           => $pct,
            'citizen_voices' => $voices,
        ];
    }

    // 3. Recent activity (always absolute, ignoring period filter)
    $thisWeekSql = "SELECT COUNT(*) FROM idea_log i JOIN users u ON i.user_id = u.user_id
                    WHERE {$baseWhere} AND i.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $pdo->prepare($thisWeekSql);
    $stmt->execute($baseParams);
    $thisWeek = (int)$stmt->fetchColumn();

    $lastWeekSql = "SELECT COUNT(*) FROM idea_log i JOIN users u ON i.user_id = u.user_id
                    WHERE {$baseWhere}
                      AND i.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      AND i.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $stmt = $pdo->prepare($lastWeekSql);
    $stmt->execute($baseParams);
    $lastWeek = (int)$stmt->fetchColumn();

    $trend = $thisWeek > $lastWeek ? 'up' : ($thisWeek < $lastWeek ? 'down' : 'flat');

    // 4. Top mandates (most recent 10)
    $sql = "SELECT i.id, i.content, i.policy_topic, i.citizen_summary, i.created_at
            FROM idea_log i JOIN users u ON i.user_id = u.user_id
            WHERE {$baseWhere}{$periodWhere}
            ORDER BY i.created_at DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($baseParams);
    $topMandates = [];
    foreach ($stmt->fetchAll() as $row) {
        $topMandates[] = [
            'id'              => (int)$row['id'],
            'content'         => $row['content'],
            'policy_topic'    => $row['policy_topic'],
            'citizen_summary' => $row['citizen_summary'],
            'created_at'      => $row['created_at'],
        ];
    }

    echo json_encode([
        'success'           => true,
        'scope'             => $scope,
        'scope_value'       => $scopeValue,
        'period'            => $period,
        'mandate_count'     => $mandateCount,
        'contributor_count' => $contributorCount,
        'topics'            => $topics,
        'recent_activity'   => ['this_week' => $thisWeek, 'last_week' => $lastWeek, 'trend' => $trend],
        'top_mandates'      => $topMandates,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
```

**Step 2: Test the API directly**

After deploy, test with curl or browser:
```
https://tpb2.sandgems.net/api/mandate-summary.php?scope=federal&scope_value=CT-2
```

Expected: JSON with topics array, counts, recent_activity.

**Step 3: Commit**

```bash
git add api/mandate-summary.php
git commit -m "feat: mandate summary API with topic grouping and activity stats"
```

---

### Task 6: Public Summary Page

**Files:**
- Create: `mandate-summary.php`

**Step 1: Write the public summary page**

```php
<?php
/**
 * Public Mandate Summary
 * ======================
 * Displays aggregated mandate statistics for a geographic scope.
 *
 * Routes:
 *   /mandate-summary.php?scope=federal&value=CT-2
 *   /mandate-summary.php?scope=state&value=7
 *   /mandate-summary.php?scope=town&value=42
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/get-user.php';
require_once __DIR__ . '/config/mandate-topics.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$dbUser    = getUser($pdo);
$isLoggedIn = (bool)$dbUser;
$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$scope      = $_GET['scope'] ?? 'federal';
$scopeValue = trim($_GET['value'] ?? '');

// Validate scope
if (!in_array($scope, ['federal', 'state', 'town'], true)) {
    $scope = 'federal';
}

// Resolve display name for the scope
$scopeDisplay = $scopeValue;
if ($scope === 'state' && ctype_digit($scopeValue)) {
    $s = $pdo->prepare("SELECT state_name FROM states WHERE state_id = ?");
    $s->execute([(int)$scopeValue]);
    $row = $s->fetch();
    if ($row) $scopeDisplay = $row['state_name'];
} elseif ($scope === 'town' && ctype_digit($scopeValue)) {
    $s = $pdo->prepare("SELECT town_name FROM towns WHERE town_id = ?");
    $s->execute([(int)$scopeValue]);
    $row = $s->fetch();
    if ($row) $scopeDisplay = $row['town_name'];
}

$scopeLabel = ucfirst($scope);
$pageTitle  = "Constituent Mandate Summary: {$scopeDisplay} | The People's Branch";
$currentPage = 'mandate';

$headLinks = '';

$pageStyles = <<<'CSS'

/* ── Summary page layout ───────────────────────────────── */
.summary-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 2rem 1rem;
}
.summary-header {
    text-align: center;
    margin-bottom: 2rem;
}
.summary-header h1 {
    color: #d4af37;
    font-size: 1.6rem;
    margin-bottom: 0.25rem;
}
.summary-header .scope-label {
    color: #b0b0b0;
    font-size: 0.95rem;
}

/* ── Scoreboard ────────────────────────────────────────── */
.scoreboard {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 2rem;
}
.score-box {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    text-align: center;
    min-width: 160px;
    flex: 1;
    max-width: 220px;
}
.score-box .number {
    font-size: 2rem;
    font-weight: 700;
    color: #fff;
    display: block;
}
.score-box .label {
    font-size: 0.85rem;
    color: #b0b0b0;
    margin-top: 0.25rem;
}
.score-box .trend {
    font-size: 0.8rem;
    margin-top: 0.25rem;
}
.trend-up { color: #81c784; }
.trend-down { color: #e57373; }
.trend-flat { color: #b0b0b0; }

/* ── Topic breakdown ───────────────────────────────────── */
.topics-section {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.topics-section h2 {
    color: #d4af37;
    font-size: 1.1rem;
    margin: 0 0 1rem 0;
}
.topic-row {
    margin-bottom: 1rem;
}
.topic-label {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
}
.topic-name { color: #ccc; font-size: 0.9rem; }
.topic-stats { color: #b0b0b0; font-size: 0.85rem; }
.topic-bar-bg {
    background: rgba(255,255,255,0.08);
    border-radius: 4px;
    height: 22px;
    overflow: hidden;
}
.topic-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #d4af37 0%, #f0d060 100%);
    border-radius: 4px;
    transition: width 0.3s ease;
}
.citizen-voices {
    margin-top: 4px;
    padding-left: 0.5rem;
    font-size: 0.82rem;
    color: #999;
    font-style: italic;
}

/* ── Mandate list ──────────────────────────────────────── */
.mandates-section {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}
.mandates-section h2 {
    color: #d4af37;
    font-size: 1.1rem;
    margin: 0 0 1rem 0;
}
.mandate-item {
    border-bottom: 1px solid rgba(255,255,255,0.06);
    padding: 0.75rem 0;
}
.mandate-item:last-child { border-bottom: none; }
.mandate-topic-badge {
    display: inline-block;
    background: rgba(212,175,55,0.15);
    color: #d4af37;
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 10px;
    margin-right: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.mandate-content { color: #ccc; font-size: 0.9rem; line-height: 1.4; }
.mandate-meta { color: #888; font-size: 0.8rem; margin-top: 4px; }

/* ── Period tabs ───────────────────────────────────────── */
.period-tabs {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    margin-bottom: 1.5rem;
}
.period-tab {
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(212,175,55,0.15);
    color: #b0b0b0;
    padding: 6px 16px;
    border-radius: 20px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.period-tab:hover { border-color: rgba(212,175,55,0.4); color: #fff; }
.period-tab.active { background: rgba(212,175,55,0.15); color: #d4af37; border-color: #d4af37; }

/* ── Export ─────────────────────────────────────────────── */
.export-bar {
    text-align: center;
    margin-bottom: 2rem;
}
.export-btn {
    background: rgba(212,175,55,0.12);
    border: 1px solid rgba(212,175,55,0.3);
    color: #d4af37;
    padding: 8px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}
.export-btn:hover { background: rgba(212,175,55,0.2); }

/* ── Empty state ───────────────────────────────────────── */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #b0b0b0;
}
.empty-state p { margin: 0.5rem 0; }

/* ── Responsive ────────────────────────────────────────── */
@media (max-width: 600px) {
    .scoreboard { flex-direction: column; align-items: center; }
    .score-box { max-width: 100%; width: 100%; }
}

CSS;

require __DIR__ . '/includes/header.php';
?>

<div class="summary-wrap">

    <div class="summary-header">
        <h1>Constituent Mandate Summary</h1>
        <p class="scope-label"><?= htmlspecialchars($scopeLabel) ?> &mdash; <?= htmlspecialchars($scopeDisplay) ?></p>
    </div>

    <!-- Period Tabs -->
    <div class="period-tabs">
        <button class="period-tab active" data-period="all">All Time</button>
        <button class="period-tab" data-period="month">Last 30 Days</button>
        <button class="period-tab" data-period="week">This Week</button>
    </div>

    <!-- Scoreboard (JS-filled) -->
    <div class="scoreboard" id="scoreboard"></div>

    <!-- Topic Breakdown (JS-filled) -->
    <div class="topics-section" id="topicsSection" style="display:none;">
        <h2>What Constituents Care About</h2>
        <div id="topicsBody"></div>
    </div>

    <!-- Mandates List (JS-filled) -->
    <div class="mandates-section" id="mandatesSection" style="display:none;">
        <h2>Recent Mandates</h2>
        <div id="mandatesBody"></div>
    </div>

    <!-- Export -->
    <div class="export-bar">
        <a class="export-btn" id="csvExport" href="#">Download CSV</a>
    </div>

    <!-- Empty state -->
    <div class="empty-state" id="emptyState" style="display:none;">
        <p>No mandates yet for this area.</p>
        <p>Be the first to <a href="/mandate-poc.php" style="color:#d4af37;">submit your mandate</a>.</p>
    </div>

</div>

<script>
(function() {
    var scope      = <?= json_encode($scope) ?>;
    var scopeValue = <?= json_encode($scopeValue) ?>;
    var currentPeriod = 'all';

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function load(period) {
        currentPeriod = period;
        var url = '/api/mandate-summary.php?scope=' + encodeURIComponent(scope)
                + '&scope_value=' + encodeURIComponent(scopeValue)
                + '&period=' + encodeURIComponent(period);

        fetch(url).then(function(r) { return r.json(); }).then(render).catch(function() {
            document.getElementById('emptyState').style.display = 'block';
        });
    }

    function render(data) {
        if (!data.success || data.mandate_count === 0) {
            document.getElementById('scoreboard').innerHTML = '';
            document.getElementById('topicsSection').style.display = 'none';
            document.getElementById('mandatesSection').style.display = 'none';
            document.getElementById('emptyState').style.display = 'block';
            return;
        }
        document.getElementById('emptyState').style.display = 'none';

        // Scoreboard
        var act = data.recent_activity || {};
        var trendClass = act.trend === 'up' ? 'trend-up' : (act.trend === 'down' ? 'trend-down' : 'trend-flat');
        var trendArrow = act.trend === 'up' ? '&#9650;' : (act.trend === 'down' ? '&#9660;' : '&#8212;');
        document.getElementById('scoreboard').innerHTML =
            '<div class="score-box"><span class="number">' + data.mandate_count + '</span><span class="label">Total Mandates</span></div>'
          + '<div class="score-box"><span class="number">' + data.contributor_count + '</span><span class="label">Constituents</span></div>'
          + '<div class="score-box"><span class="number">' + act.this_week + '</span><span class="label">This Week</span>'
          + '<span class="trend ' + trendClass + '">' + trendArrow + ' vs last week (' + act.last_week + ')</span></div>';

        // Topics
        if (data.topics && data.topics.length) {
            document.getElementById('topicsSection').style.display = 'block';
            var h = '';
            data.topics.forEach(function(t) {
                h += '<div class="topic-row">'
                   + '<div class="topic-label"><span class="topic-name">' + escHtml(t.policy_topic) + '</span>'
                   + '<span class="topic-stats">' + t.count + ' (' + t.pct + '%)</span></div>'
                   + '<div class="topic-bar-bg"><div class="topic-bar-fill" style="width:' + t.pct + '%"></div></div>';
                if (t.citizen_voices && t.citizen_voices.length) {
                    h += '<div class="citizen-voices">"' + t.citizen_voices.map(escHtml).join('", "') + '"</div>';
                }
                h += '</div>';
            });
            document.getElementById('topicsBody').innerHTML = h;
        } else {
            document.getElementById('topicsSection').style.display = 'none';
        }

        // Mandates
        if (data.top_mandates && data.top_mandates.length) {
            document.getElementById('mandatesSection').style.display = 'block';
            var m = '';
            data.top_mandates.forEach(function(item) {
                m += '<div class="mandate-item">';
                if (item.policy_topic) {
                    m += '<span class="mandate-topic-badge">' + escHtml(item.policy_topic) + '</span>';
                }
                m += '<span class="mandate-content">' + escHtml(item.content) + '</span>';
                m += '<div class="mandate-meta">' + escHtml(item.created_at) + '</div>';
                m += '</div>';
            });
            document.getElementById('mandatesBody').innerHTML = m;
        } else {
            document.getElementById('mandatesSection').style.display = 'none';
        }

        // CSV link
        document.getElementById('csvExport').href =
            '/api/mandate-summary.php?scope=' + encodeURIComponent(scope)
            + '&scope_value=' + encodeURIComponent(scopeValue)
            + '&period=' + encodeURIComponent(currentPeriod)
            + '&format=csv';
    }

    // Period tabs
    document.querySelectorAll('.period-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.period-tab').forEach(function(t) { t.classList.remove('active'); });
            tab.classList.add('active');
            load(tab.dataset.period);
        });
    });

    // Initial load
    load('all');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
```

**Step 2: Commit**

```bash
git add mandate-summary.php
git commit -m "feat: public mandate summary page with scoreboard, topics, and mandate list"
```

---

### Task 7: CSV Export Support in API

**Files:**
- Modify: `api/mandate-summary.php` (add CSV format output at bottom)

**Step 1: Add CSV format handling**

At the top of `api/mandate-summary.php`, after the period validation, add:

```php
$format = $_GET['format'] ?? 'json';
```

Then replace the final `echo json_encode(...)` block (the success response) with:

```php
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="mandate-summary-' . $scope . '-' . $scopeValue . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Content', 'Policy Topic', 'Citizen Summary', 'Created At']);
        foreach ($topMandates as $row) {
            fputcsv($out, [$row['id'], $row['content'], $row['policy_topic'], $row['citizen_summary'], $row['created_at']]);
        }
        fclose($out);
        exit;
    }

    echo json_encode([
        'success'           => true,
        'scope'             => $scope,
        'scope_value'       => $scopeValue,
        'period'            => $period,
        'mandate_count'     => $mandateCount,
        'contributor_count' => $contributorCount,
        'topics'            => $topics,
        'recent_activity'   => ['this_week' => $thisWeek, 'last_week' => $lastWeek, 'trend' => $trend],
        'top_mandates'      => $topMandates,
    ]);
```

Note: For CSV, remove the LIMIT 10 from the top_mandates query when format=csv — export all rows. Add this before the top mandates query:

```php
    $mandateLimit = ($format === 'csv') ? 1000 : 10;
```

And change the LIMIT in the query to use the variable:

```sql
    ORDER BY i.created_at DESC LIMIT {$mandateLimit}
```

**Step 2: Test CSV download**

```
https://tpb2.sandgems.net/api/mandate-summary.php?scope=federal&scope_value=CT-2&format=csv
```

Expected: Browser downloads a CSV file.

**Step 3: Commit**

```bash
git add api/mandate-summary.php
git commit -m "feat: add CSV export format to mandate summary API"
```

---

### Task 8: Link from POC Page

**Files:**
- Modify: `mandate-poc.php` (around line 1135, the Public Mandate Summary section)

**Step 1: Add link to full summary page**

In the mandate summary section, after the filter tabs div, add a link:

Find (around line 1147):
```html
        </div>
        <div id="mandateSummaryBody" style="padding: 1.5rem;">
```

Add between them:
```html
        <div style="text-align:right; padding: 4px 12px;">
            <a href="/mandate-summary.php?scope=federal&value=<?= htmlspecialchars(urlencode($dbUser['us_congress_district'] ?? '')) ?>"
               style="color:#d4af37; font-size:0.8rem; text-decoration:none;"
               title="View full statistics and topic breakdown">View Full Summary &rarr;</a>
        </div>
```

**Step 2: Commit**

```bash
git add mandate-poc.php
git commit -m "feat: link from mandate POC to full summary page"
```

---

### Task 9: Deploy & Verify

**Step 1: Push all commits**

```bash
git push origin master
```

**Step 2: Pull on staging**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 3: Run schema migration (Task 1)**

**Step 4: Run backfill (Task 4)**

**Step 5: Verify end-to-end**

1. Visit `https://tpb2.sandgems.net/mandate-poc.php` — save a new mandate, verify it gets tagged (check DB)
2. Visit `https://tpb2.sandgems.net/api/mandate-summary.php?scope=federal&scope_value=CT-2` — verify JSON response with topics
3. Visit `https://tpb2.sandgems.net/mandate-summary.php?scope=federal&value=CT-2` — verify page renders with scoreboard, topics, mandates
4. Click "Download CSV" — verify file downloads
5. Click "View Full Summary" link on POC page — verify it navigates correctly

**Step 6: Final commit (if any fixes needed)**

```bash
git commit -m "fix: address issues found during verification"
```
