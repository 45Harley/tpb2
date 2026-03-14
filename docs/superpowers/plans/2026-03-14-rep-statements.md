# Rep Statements Tracking System — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Track presidential statements from all sources (Truth Social, press, interviews), scored on dual criminality+benefit scales, tagged by policy topic and tense, with citizen agree/disagree voting.

**Architecture:** New `rep_statements` table + `rep_statement_votes` table. `benefit_score` column added to existing `executive_threats`. Automated collection via 3-step local `claude -p` pipeline (mirrors threat collection). New benefit scale component mirrors existing criminality scale. Display page at `elections/statements.php`.

**Tech Stack:** PHP 8.4, MySQL/MariaDB, vanilla JS, existing XAMPP/InMotion hosting

**Spec:** `docs/superpowers/specs/2026-03-14-rep-statements-design.md`

---

## Chunk 1: Database Schema + Benefit Scale Components

### Task 1: Create database migration script

**Files:**
- Create: `scripts/db/create-rep-statements.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Rep Statements Tracking System
-- Run on sandge5_tpb2 database

-- 1. New table: rep_statements
CREATE TABLE IF NOT EXISTS rep_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    source VARCHAR(100) NOT NULL COMMENT 'Truth Social, Press Conference, Interview, WH Statement, etc.',
    source_url VARCHAR(500) DEFAULT NULL,
    content TEXT NOT NULL COMMENT 'Full quote or statement text',
    summary VARCHAR(500) DEFAULT NULL COMMENT 'AI-generated one-liner',
    policy_topic VARCHAR(100) DEFAULT NULL COMMENT 'One of 16 mandate policy topics',
    tense ENUM('future', 'present', 'past') DEFAULT NULL,
    severity_score SMALLINT DEFAULT NULL COMMENT 'Criminality scale 0-1000',
    benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000',
    statement_date DATE NOT NULL COMMENT 'When the statement was made',
    related_threat_id INT DEFAULT NULL COMMENT 'FK to executive_threats if statement links to an action',
    agree_count INT NOT NULL DEFAULT 0,
    disagree_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (official_id) REFERENCES elected_officials(official_id),
    FOREIGN KEY (related_threat_id) REFERENCES executive_threats(threat_id),
    INDEX idx_official_date (official_id, statement_date DESC),
    INDEX idx_policy_topic (policy_topic),
    INDEX idx_tense (tense)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. New table: rep_statement_votes
CREATE TABLE IF NOT EXISTS rep_statement_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('agree', 'disagree') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (statement_id, user_id),
    FOREIGN KEY (statement_id) REFERENCES rep_statements(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add benefit_score to executive_threats
ALTER TABLE executive_threats
    ADD COLUMN benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000'
    AFTER severity_score;
```

- [ ] **Step 2: Run migration on local MySQL**

Run: Open phpMyAdmin at `http://localhost/phpmyadmin`, select `sandge5_tpb2`, run the SQL.

Verify: `SHOW COLUMNS FROM rep_statements;` should show all 16 columns.
Verify: `SHOW COLUMNS FROM executive_threats LIKE 'benefit_score';` should show the new column.

- [ ] **Step 3: Run migration on staging server**

```bash
# Upload and run
scp -P 2222 c:/tpb2/scripts/db/create-rep-statements.sql sandge5@ecngx308.inmotionhosting.com:~/tmp_migration.sql
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "mysql sandge5_tpb2 < ~/tmp_migration.sql && rm ~/tmp_migration.sql && echo 'Migration complete'"
```

- [ ] **Step 4: Commit**

```bash
git add scripts/db/create-rep-statements.sql
git commit -m "feat: add rep_statements schema + benefit_score on executive_threats"
```

---

### Task 2: Create benefit severity helper

**Files:**
- Create: `includes/benefit-severity.php`
- Reference: `includes/severity.php` (mirror this structure exactly)

- [ ] **Step 1: Write benefit-severity.php**

```php
<?php
/**
 * Benefit Scale — Benefit Zone Helper
 * Mirror of criminality scale (severity.php), reversed color palette.
 *
 * Scale: 0-1000 geometric. Rates the ACT's positive impact on citizens.
 * Returns ['label' => string, 'color' => hex, 'class' => css-class]
 */
function getBenefitZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#9e9e9e', 'class' => 'unscored'];
    if ($score === 0) return ['label' => 'Neutral', 'color' => '#9e9e9e', 'class' => 'neutral'];
    if ($score <= 10) return ['label' => 'Minor Positive', 'color' => '#c8e6c9', 'class' => 'minor-positive'];
    if ($score <= 30) return ['label' => 'Helpful', 'color' => '#a5d6a7', 'class' => 'helpful'];
    if ($score <= 70) return ['label' => 'Significant', 'color' => '#81c784', 'class' => 'significant'];
    if ($score <= 150) return ['label' => 'Major Benefit', 'color' => '#66bb6a', 'class' => 'major-benefit'];
    if ($score <= 300) return ['label' => 'Transformative', 'color' => '#4caf50', 'class' => 'transformative'];
    if ($score <= 500) return ['label' => 'Historic', 'color' => '#43a047', 'class' => 'historic'];
    if ($score <= 700) return ['label' => 'Landmark', 'color' => '#388e3c', 'class' => 'landmark'];
    if ($score <= 900) return ['label' => 'Epochal', 'color' => '#2e7d32', 'class' => 'epochal'];
    return ['label' => 'Civilizational', 'color' => '#1b5e20', 'class' => 'civilizational'];
}
```

- [ ] **Step 2: Commit**

```bash
git add includes/benefit-severity.php
git commit -m "feat: add benefit scale severity zone helper (mirrors criminality scale)"
```

---

### Task 3: Create benefit scale display component

**Files:**
- Create: `includes/benefit-scale.php`
- Reference: `includes/criminality-scale.php` (mirror layout, use benefit-severity.php colors)

- [ ] **Step 1: Write benefit-scale.php**

```php
<?php
/**
 * Benefit Scale Explainer — Shared Include
 * =========================================
 * Mirror of criminality-scale.php with green color palette.
 * Self-contained: emits its own CSS (once) + HTML block.
 *
 * Usage:
 *   <?php require_once __DIR__ . '/../includes/benefit-scale.php'; ?>
 */

require_once __DIR__ . '/benefit-severity.php';

$_bsZones = [
    ['label' => 'Neutral', 'range' => '0', 'color' => '#9e9e9e', 'min' => 0],
    ['label' => 'Minor Positive', 'range' => '1-10', 'color' => '#c8e6c9', 'min' => 1],
    ['label' => 'Helpful', 'range' => '11-30', 'color' => '#a5d6a7', 'min' => 11],
    ['label' => 'Significant', 'range' => '31-70', 'color' => '#81c784', 'min' => 31],
    ['label' => 'Major Benefit', 'range' => '71-150', 'color' => '#66bb6a', 'min' => 71],
    ['label' => 'Transformative', 'range' => '151-300', 'color' => '#4caf50', 'min' => 151],
    ['label' => 'Historic', 'range' => '301-500', 'color' => '#43a047', 'min' => 301],
    ['label' => 'Landmark', 'range' => '501-700', 'color' => '#388e3c', 'min' => 501],
    ['label' => 'Epochal', 'range' => '701-900', 'color' => '#2e7d32', 'min' => 701],
    ['label' => 'Civilizational', 'range' => '901-1000', 'color' => '#1b5e20', 'min' => 901]
];

if (!defined('BENEFIT_SCALE_CSS_EMITTED')) {
    define('BENEFIT_SCALE_CSS_EMITTED', true);
?>
<style>
.benefit-scale-container { background: #0a0f0a; padding: 1.5rem; border-radius: 8px; border: 1px solid #2e4a2e; margin-bottom: 0; border-bottom: none; border-radius: 8px 8px 0 0; }
.benefit-scale-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.benefit-scale-header h3 { color: #4caf50; margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 1px; }
.benefit-threshold-marker { color: #4caf50; font-weight: bold; font-size: 0.8rem; text-transform: uppercase; border: 1px solid #4caf50; padding: 2px 6px; border-radius: 4px; }
.benefit-bar { display: flex; height: 12px; border-radius: 6px; overflow: hidden; margin-bottom: 1rem; background: #222; }
.benefit-segment { height: 100%; transition: transform 0.3s; cursor: help; }
.benefit-labels { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
.benefit-label-item { font-size: 0.7rem; color: #888; display: flex; align-items: center; gap: 5px; }
.bs-dot { width: 8px; height: 8px; border-radius: 50%; }
.benefit-scale-box {
    background: #0f1a0f; border: 1px solid #2e4a2e; border-top: none; border-radius: 0 0 8px 8px;
    padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; color: #ccc;
    font-size: 0.9rem; line-height: 1.6;
}
.benefit-scale-box p { margin: 0 0 0.5rem; }
@media (max-width: 600px) { .benefit-labels { grid-template-columns: repeat(2, 1fr); } }
</style>
<?php } ?>

<div class="benefit-scale-container" id="benefit-scale">
    <div class="benefit-scale-header">
        <h3>Benefit Scale</h3>
        <span class="benefit-threshold-marker">Citizen Impact: 0-1000</span>
    </div>
    <div class="benefit-bar">
        <?php foreach ($_bsZones as $z):
            $width = ($z['label'] == 'Neutral') ? 5 : 10.5;
        ?>
            <div class="benefit-segment"
                 style="width: <?= $width ?>%; background: <?= $z['color'] ?>;"
                 title="<?= $z['label'] ?> (<?= $z['range'] ?>)"></div>
        <?php endforeach; ?>
    </div>
    <div class="benefit-labels">
        <?php foreach ($_bsZones as $z): ?>
            <div class="benefit-label-item">
                <span class="bs-dot" style="background: <?= $z['color'] ?>"></span>
                <span><strong><?= $z['range'] ?></strong> <?= $z['label'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="benefit-scale-box">
    <p>The <strong style="color:#4caf50">benefit scale</strong> measures positive citizen impact &mdash; how much an action or statement helps people. It mirrors the criminality scale: same 0-1000 geometric range, same zone boundaries, opposite direction. An action can score on both scales independently.</p>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add includes/benefit-scale.php
git commit -m "feat: add benefit scale display component (mirrors criminality-scale.php)"
```

---

## Chunk 2: Vote API + Display Page

### Task 4: Create vote-statement API endpoint

**Files:**
- Create: `api/vote-statement.php`
- Reference: `api/vote-thought.php` (follow same pattern)
- Reference: `includes/get-user.php` (auth)
- Reference: `includes/point-logger.php` (civic points)

- [ ] **Step 1: Write vote-statement.php**

```php
<?php
/**
 * Vote Statement API
 * ==================
 * Records agree/disagree vote on a rep statement (requires verified email).
 *
 * POST /api/vote-statement.php
 * Body: { "statement_id": 123, "vote_type": "agree" or "disagree" }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$config = require __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

$statementId = (int)($input['statement_id'] ?? 0);
$voteType = $input['vote_type'] ?? null;

if (!$statementId) {
    echo json_encode(['status' => 'error', 'message' => 'Statement ID required']);
    exit();
}

if (!in_array($voteType, ['agree', 'disagree'])) {
    echo json_encode(['status' => 'error', 'message' => 'Vote type must be "agree" or "disagree"']);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Centralized auth
    require_once __DIR__ . '/../includes/get-user.php';
    $user = getUser($pdo);

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Please log in to vote']);
        exit();
    }

    if (!$user['email_verified']) {
        echo json_encode(['status' => 'error', 'message' => 'Please verify your email to vote']);
        exit();
    }

    // Check if statement exists
    $stmt = $pdo->prepare("SELECT id, agree_count, disagree_count FROM rep_statements WHERE id = ?");
    $stmt->execute([$statementId]);
    $statement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$statement) {
        echo json_encode(['status' => 'error', 'message' => 'Statement not found']);
        exit();
    }

    // Check existing vote
    $stmt = $pdo->prepare("SELECT vote_id, vote_type FROM rep_statement_votes WHERE statement_id = ? AND user_id = ?");
    $stmt->execute([$statementId, $user['user_id']]);
    $existingVote = $stmt->fetch(PDO::FETCH_ASSOC);

    $userVote = null;
    $points = 0;

    if ($existingVote) {
        if ($existingVote['vote_type'] === $voteType) {
            // Same vote — toggle off (remove)
            $stmt = $pdo->prepare("DELETE FROM rep_statement_votes WHERE vote_id = ?");
            $stmt->execute([$existingVote['vote_id']]);

            $col = ($voteType === 'agree') ? 'agree_count' : 'disagree_count';
            $stmt = $pdo->prepare("UPDATE rep_statements SET {$col} = {$col} - 1 WHERE id = ?");
            $stmt->execute([$statementId]);

            $message = 'Vote removed';
            $userVote = null;
        } else {
            // Different vote — flip
            $stmt = $pdo->prepare("UPDATE rep_statement_votes SET vote_type = ? WHERE vote_id = ?");
            $stmt->execute([$voteType, $existingVote['vote_id']]);

            if ($voteType === 'agree') {
                $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count + 1, disagree_count = disagree_count - 1 WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE rep_statements SET agree_count = agree_count - 1, disagree_count = disagree_count + 1 WHERE id = ?");
            }
            $stmt->execute([$statementId]);

            $message = 'Vote changed';
            $userVote = $voteType;
        }
    } else {
        // New vote
        $stmt = $pdo->prepare("INSERT INTO rep_statement_votes (statement_id, user_id, vote_type, voted_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$statementId, $user['user_id'], $voteType]);

        $col = ($voteType === 'agree') ? 'agree_count' : 'disagree_count';
        $stmt = $pdo->prepare("UPDATE rep_statements SET {$col} = {$col} + 1 WHERE id = ?");
        $stmt->execute([$statementId]);

        // Award civic points
        require_once __DIR__ . '/../includes/point-logger.php';
        PointLogger::init($pdo);
        $pointResult = PointLogger::award($user['user_id'], 'vote_cast', 'vote', $statementId);
        $points = $pointResult['points_earned'] ?? 0;

        $message = 'Vote recorded';
        $userVote = $voteType;
    }

    // Get updated counts
    $stmt = $pdo->prepare("SELECT agree_count, disagree_count FROM rep_statements WHERE id = ?");
    $stmt->execute([$statementId]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'statement_id' => $statementId,
        'vote_type' => $voteType,
        'user_vote' => $userVote,
        'agree_count' => (int)$updated['agree_count'],
        'disagree_count' => (int)$updated['disagree_count'],
        'points_earned' => $points
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
```

- [ ] **Step 2: Commit**

```bash
git add api/vote-statement.php
git commit -m "feat: add vote-statement API endpoint with toggle/flip logic + civic points"
```

---

### Task 5: Create statements display page + add sub-nav link

**Files:**
- Create: `elections/statements.php`
- Modify: `elections/threats.php` (add Statements link to sub-nav)
- Reference: `elections/threats.php` (mirror page structure, nav links, dark theme)
- Reference: `includes/nav.php` (include for page header)
- Reference: `includes/severity.php` + `includes/benefit-severity.php` (dual score badges)

- [ ] **Step 1: Read elections/threats.php fully**

Read the entire file to understand the page shell, sub-nav links, CSS structure, and card layout. The statements page must mirror this structure closely.

- [ ] **Step 2: Write elections/statements.php as a complete file**

The file must be complete and runnable — not fragments. It should contain these sections in order:

**PHP data section (top):**

```php
<?php
/**
 * Rep Statements — What Officials Say
 * ====================================
 * Displays statements from elected officials with dual scoring + voting.
 * POC: President only (official_id = 326 = Trump).
 */

$pageTitle = 'Statements';
$currentPage = 'statements';

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$userId = $dbUser ? (int)$dbUser['user_id'] : 0;

require_once __DIR__ . '/../includes/severity.php';
require_once __DIR__ . '/../includes/benefit-severity.php';

// Filters
$filterTopic = $_GET['topic'] ?? '';
$filterTense = $_GET['tense'] ?? '';
$filterSource = $_GET['source'] ?? '';

// Build query
$where = ['1=1'];
$params = [];

// POC: President only (official_id = 326)
$where[] = 'rs.official_id = 326';

if ($filterTopic) {
    $where[] = 'rs.policy_topic = ?';
    $params[] = $filterTopic;
}
if ($filterTense && in_array($filterTense, ['future', 'present', 'past'])) {
    $where[] = 'rs.tense = ?';
    $params[] = $filterTense;
}
if ($filterSource) {
    $where[] = 'rs.source = ?';
    $params[] = $filterSource;
}

$whereClause = implode(' AND ', $where);

$sql = "
    SELECT rs.*, eo.full_name AS official_name, eo.title AS official_title
    FROM rep_statements rs
    JOIN elected_officials eo ON rs.official_id = eo.official_id
    WHERE {$whereClause}
    ORDER BY rs.statement_date DESC, rs.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$statements = $stmt->fetchAll();

// Get viewer's votes
$myVotes = [];
if ($userId && count($statements) > 0) {
    $ids = array_column($statements, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $vStmt = $pdo->prepare("SELECT statement_id, vote_type FROM rep_statement_votes WHERE statement_id IN ({$ph}) AND user_id = ?");
    $vStmt->execute(array_merge($ids, [$userId]));
    foreach ($vStmt->fetchAll() as $v) {
        $myVotes[(int)$v['statement_id']] = $v['vote_type'];
    }
}

// Get distinct sources for filter dropdown
$sources = $pdo->query("SELECT DISTINCT source FROM rep_statements ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

// Policy topics (same 16 as mandates)
$policyTopics = [
    'Economy & Jobs', 'Healthcare', 'Education', 'Environment & Climate',
    'Immigration', 'National Security', 'Criminal Justice', 'Housing',
    'Infrastructure', 'Social Services', 'Tax Policy', 'Civil Rights',
    'Technology & Privacy', 'Foreign Policy', 'Agriculture', 'Government Reform'
];
```

**HTML page shell** — copy the `<!DOCTYPE html>` through `</head>` from `threats.php`, update title. Include `includes/nav.php`. Add the elections sub-nav links block (copy from `threats.php` view-links, add Statements link with active class):

```html
<!-- Sub-nav: copy from threats.php view-links, add Statements -->
<div class="view-links" style="text-align:center; margin:1rem 0;">
    <a href="/elections/">Elections</a>
    <a href="/elections/the-fight.php">The Fight</a>
    <a href="/elections/the-amendment.php">The War</a>
    <a href="/elections/threats.php">Threats</a>
    <a href="/elections/statements.php" class="active">Statements</a>
    <a href="/elections/races.php">Races</a>
    <a href="/elections/impeachment-vote.php">Impeachment #1</a>
</div>
```

**Filter form:**

```html
<div class="statement-filters" style="display:flex; gap:1rem; flex-wrap:wrap; margin:1rem 0;">
    <select onchange="location.href=updateFilter('topic', this.value)">
        <option value="">All Topics</option>
        <?php foreach ($policyTopics as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $filterTopic === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
        <?php endforeach; ?>
    </select>
    <select onchange="location.href=updateFilter('tense', this.value)">
        <option value="">All Tenses</option>
        <option value="future" <?= $filterTense === 'future' ? 'selected' : '' ?>>Future</option>
        <option value="present" <?= $filterTense === 'present' ? 'selected' : '' ?>>Present</option>
        <option value="past" <?= $filterTense === 'past' ? 'selected' : '' ?>>Past</option>
    </select>
    <select onchange="location.href=updateFilter('source', this.value)">
        <option value="">All Sources</option>
        <?php foreach ($sources as $src): ?>
            <option value="<?= htmlspecialchars($src) ?>" <?= $filterSource === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
        <?php endforeach; ?>
    </select>
</div>
```

**Empty state:**

```html
<?php if (empty($statements)): ?>
    <div style="text-align:center; color:#b0b0b0; padding:3rem;">
        <p>No statements found<?= ($filterTopic || $filterTense || $filterSource) ? ' matching your filters' : ' yet' ?>.</p>
    </div>
<?php else: ?>
```

**Statement cards** (loop through `$statements`):

```html
<?php foreach ($statements as $s): ?>
<div class="statement-card" data-id="<?= $s['id'] ?>">
    <div class="statement-meta">
        <span class="source-badge"><?= htmlspecialchars($s['source']) ?></span>
        <span class="tense-badge tense-<?= $s['tense'] ?>"><?= ucfirst($s['tense'] ?? 'Unknown') ?></span>
        <span class="topic-tag"><?= htmlspecialchars($s['policy_topic'] ?? 'Untagged') ?></span>
        <span class="statement-date"><?= date('M j, Y', strtotime($s['statement_date'])) ?></span>
    </div>
    <blockquote class="statement-content"><?= htmlspecialchars($s['content']) ?></blockquote>
    <?php if ($s['summary']): ?>
        <p class="statement-summary"><?= htmlspecialchars($s['summary']) ?></p>
    <?php endif; ?>
    <?php if ($s['source_url']): ?>
        <a href="<?= htmlspecialchars($s['source_url']) ?>" target="_blank" rel="noopener" class="source-link">Source</a>
    <?php endif; ?>
    <div class="dual-scores">
        <?php if ($s['severity_score'] !== null): $sz = getSeverityZone($s['severity_score']); ?>
            <span class="score-badge" style="background: <?= $sz['color'] ?>; color: #000;">
                Harm: <?= $s['severity_score'] ?> (<?= $sz['label'] ?>)
            </span>
        <?php endif; ?>
        <?php if ($s['benefit_score'] !== null): $bz = getBenefitZone($s['benefit_score']); ?>
            <span class="score-badge" style="background: <?= $bz['color'] ?>; color: #000;">
                Benefit: <?= $s['benefit_score'] ?> (<?= $bz['label'] ?>)
            </span>
        <?php endif; ?>
    </div>
    <div class="vote-row">
        <?php $myVote = $myVotes[$s['id']] ?? null; ?>
        <button class="vote-btn agree<?= $myVote === 'agree' ? ' vote-active' : '' ?>"
                data-id="<?= $s['id'] ?>" data-type="agree">
            &#x1F44D; <span class="vote-count"><?= $s['agree_count'] ?></span>
        </button>
        <button class="vote-btn disagree<?= $myVote === 'disagree' ? ' vote-active' : '' ?>"
                data-id="<?= $s['id'] ?>" data-type="disagree">
            &#x1F44E; <span class="vote-count"><?= $s['disagree_count'] ?></span>
        </button>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
```

**CSS block** (in `<style>` tag, dark theme):

```css
body { background: #0d0d1a; color: #ccc; }
.statement-card { background: #1a1a2e; border: 1px solid #333; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
.statement-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.source-badge { background: #2a2a4a; color: #d4af37; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; }
.tense-badge { padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold; }
.tense-future { background: #1a3a5c; color: #64b5f6; }
.tense-present { background: #3a3a1a; color: #d4af37; }
.tense-past { background: #2a2a2a; color: #b0b0b0; }
.topic-tag { background: #1a2e1a; color: #81c784; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; }
.statement-date { color: #b0b0b0; font-size: 0.8rem; margin-left: auto; }
.statement-content { border-left: 3px solid #d4af37; padding-left: 1rem; margin: 0.75rem 0; font-style: italic; color: #ddd; line-height: 1.6; }
.statement-summary { color: #b0b0b0; font-size: 0.9rem; margin: 0.5rem 0; }
.source-link { color: #64b5f6; font-size: 0.85rem; text-decoration: none; }
.source-link:hover { text-decoration: underline; }
.dual-scores { display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0.75rem 0; }
.score-badge { padding: 3px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: bold; }
.vote-row { display: flex; gap: 0.75rem; margin-top: 0.5rem; }
.vote-btn { background: #2a2a4a; border: 1px solid #444; color: #ccc; padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 0.9rem; }
.vote-btn:hover { border-color: #888; }
.vote-btn.vote-active.agree { background: #1b5e20; border-color: #4caf50; color: #fff; }
.vote-btn.vote-active.disagree { background: #b71c1c; border-color: #ef5350; color: #fff; }
.vote-count { font-weight: bold; }
.statement-filters select { background: #1a1a2e; color: #ccc; border: 1px solid #444; padding: 6px 12px; border-radius: 6px; }
```

**JS block** (in `<script>` tag):

```javascript
// Filter helper — updates URL query param
function updateFilter(key, value) {
    var url = new URL(window.location);
    if (value) { url.searchParams.set(key, value); } else { url.searchParams.delete(key); }
    return url.toString();
}

// Vote handler
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.vote-btn');
    if (!btn) return;
    var userId = <?= $userId ?>;
    if (!userId) { alert('Log in to vote'); return; }
    var statementId = parseInt(btn.dataset.id);
    var voteType = btn.dataset.type;
    fetch('/api/vote-statement.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({statement_id: statementId, vote_type: voteType})
    }).then(function(r) { return r.json(); }).then(function(data) {
        if (data.status === 'success') {
            var card = btn.closest('.statement-card');
            var agreeBtn = card.querySelector('.agree');
            var disagreeBtn = card.querySelector('.disagree');
            agreeBtn.querySelector('.vote-count').textContent = data.agree_count;
            disagreeBtn.querySelector('.vote-count').textContent = data.disagree_count;
            agreeBtn.classList.toggle('vote-active', data.user_vote === 'agree');
            disagreeBtn.classList.toggle('vote-active', data.user_vote === 'disagree');
        }
    });
});
```

Close with `</body></html>` and `<?php require_once __DIR__ . '/../includes/footer.php'; ?>` if footer include exists.

- [ ] **Step 3: Add Statements link to threats.php sub-nav**

Read `elections/threats.php`, find the view-links section. Add after the Threats link:

```html
<a href="/elections/statements.php" class="<?= ($currentPage ?? '') === 'statements' ? 'active' : '' ?>">Statements</a>
```

- [ ] **Step 4: Test page loads locally**

Visit: `http://localhost/tpb2/elections/statements.php`
Expected: Page loads with no errors, shows empty state ("No statements found yet."), filters render, sub-nav shows with Statements active.

Visit: `http://localhost/tpb2/elections/threats.php`
Expected: Statements link appears in sub-nav.

- [ ] **Step 5: Commit**

```bash
git add elections/statements.php elections/threats.php
git commit -m "feat: add statements display page with dual scoring, voting, and sub-nav link"
```

---

## Chunk 3: Collection Pipeline

### Task 7: Create step 1 — gather context from server

**Files:**
- Create: `scripts/maintenance/collect-statements-step1-gather.php`
- Reference: `scripts/maintenance/collect-threats-step1-gather.php` (mirror structure)

- [ ] **Step 1: Write collect-statements-step1-gather.php**

```php
<?php
/**
 * Step 1: Gather context from DB for statement collection prompt.
 * Runs on the SERVER via SSH. Outputs JSON with prompt + context.
 *
 * Usage: php collect-statements-step1-gather.php
 */

$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Check kill switch
$enabled = getSiteSetting($pdo, 'statement_collect_local_enabled', '0');
if ($enabled !== '1') {
    echo json_encode(['status' => 'disabled', 'message' => 'statement_collect_local_enabled is not 1']);
    exit(0);
}

// Adaptive date window
$lastSuccess = getSiteSetting($pdo, 'statement_collect_last_success', '');
if ($lastSuccess) {
    $daysSinceSuccess = (int)((time() - strtotime($lastSuccess)) / 86400);
    $lookbackDays = max(1, $daysSinceSuccess);
} else {
    $lookbackDays = 2;
}
if ($lookbackDays > 7) $lookbackDays = 7;

$today = date('Y-m-d');
$windowStart = date('Y-m-d', strtotime("-{$lookbackDays} day"));

// Get existing statements for dedup
$recentStatements = $pdo->query("
    SELECT id, statement_date, LEFT(content, 200) AS content_preview, source, official_id
    FROM rep_statements ORDER BY statement_date DESC LIMIT 40
")->fetchAll();

$dedupLines = [];
foreach ($recentStatements as $s) {
    $dedupLines[] = "#{$s['id']} ({$s['statement_date']}) [{$s['source']}] " . substr($s['content_preview'], 0, 100);
}
$dedupContext = implode("\n", $dedupLines);

// Policy topics
$policyTopics = 'Economy & Jobs, Healthcare, Education, Environment & Climate, Immigration, National Security, Criminal Justice, Housing, Infrastructure, Social Services, Tax Policy, Civil Rights, Technology & Privacy, Foreign Policy, Agriculture, Government Reform';

// Officials (POC: President)
$officialContext = "Official IDs: 326=Trump (President). Use 326 for all statements in POC.";

// Build prompt
$systemPrompt = <<<PROMPT
You are a civic researcher for The People's Branch (TPB).

Your job: Search for NEW public statements by President Trump from {$windowStart} to {$today} that are NOT already in our database.

## What Counts as a Statement
Public words by the President that:
- State a position on policy
- Make a promise or announcement about future action
- Claim results or credit for past actions
- React to events, court rulings, or criticism
- Attack or praise institutions, individuals, or groups

## Sources to Search (ALL of these)
- Truth Social posts (primary — highest volume)
- Press conferences and pool sprays (transcripts)
- Interviews (Fox News, podcasts, rallies)
- Official White House statements (whitehouse.gov)
- Media quotes from events

## Existing Recent Statements (DO NOT DUPLICATE)
{$dedupContext}

## Deduplication Rules
- Skip if content is substantially the same as an existing statement
- Skip if same source URL already captured
- Multiple statements on different topics from the same day are SEPARATE entries
- Retweets/reposts of others are NOT presidential statements unless he adds commentary

## Tense Tagging
Each statement gets a tense:
- "future" — promises, intentions, "we're going to...", "I will..."
- "present" — current actions, "I am ordering...", "today we are..."
- "past" — retrospective claims, "we saved...", "last week I..."

## Policy Topic Tagging
Assign exactly ONE topic from: {$policyTopics}
Choose the BEST fit. If unclear, use the most specific match.

## Dual Scoring

### Severity Score (Criminality Scale, 0-1000)
How harmful is this statement? Does it threaten institutions, rights, or democratic norms?
- 0: No harm
- 1-30: Misleading or divisive rhetoric
- 31-70: Attacks on institutions or rule of law
- 71-150: Incitement or threats against individuals/groups
- 151-300: Calls for unconstitutional action
- 301+: Direct incitement to violence or insurrection
Most statements will score 0-70. Reserve high scores for genuinely dangerous rhetoric.

### Benefit Score (Benefit Scale, 0-1000)
How much does this statement signal positive citizen impact?
- 0: No benefit
- 1-30: Minor positive gesture
- 31-70: Meaningful commitment to help a group
- 71-150: Broad positive impact announced
- 151-300: Structural positive change promised or announced
- 301+: Historic level positive commitment
Score based on the CONTENT of what's promised/announced, not whether you believe it will happen.

## {$officialContext}

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
If you find NO new statements, return: {"statements": []}

If you find statements, return:
{
  "statements": [
    {
      "statement_date": "YYYY-MM-DD",
      "content": "Full quote or statement text. Use exact words when possible.",
      "summary": "One-sentence summary of position taken.",
      "source": "Truth Social | Press Conference | Interview | WH Statement | Rally",
      "source_url": "https://link-to-source-if-available",
      "policy_topic": "One of the 16 topics",
      "tense": "future | present | past",
      "official_id": 326,
      "severity_score": 0,
      "benefit_score": 0
    }
  ],
  "search_summary": "Brief note on what sources you checked"
}

## Writing Standards
- Content: Use exact quotes when possible. If paraphrasing, note "[paraphrased]"
- Summary: One sentence, factual, no editorializing
- Source: Pick the best category from the list
- Scores: Be calibrated. Most statements are 0-70 on both scales. High scores are rare.
PROMPT;

$userMessage = "Search for presidential statements from {$windowStart} to {$today}. Check Truth Social, press conferences, WH.gov, and major news sources. Return structured JSON with all new statements found.";

echo json_encode([
    'status' => 'ready',
    'system_prompt' => $systemPrompt,
    'user_message' => $userMessage,
    'lookback_days' => $lookbackDays,
    'window_start' => $windowStart,
    'today' => $today,
    'statement_count' => count($recentStatements)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

- [ ] **Step 2: Commit**

```bash
git add scripts/maintenance/collect-statements-step1-gather.php
git commit -m "feat: add statement collection step 1 — gather DB context for prompt"
```

---

### Task 8: Create step 2 — extract prompt for claude -p

**Files:**
- Create: `scripts/maintenance/collect-statements-step2-extract.php`
- Reference: `scripts/maintenance/collect-threats-step2-extract.php` (identical pattern)

- [ ] **Step 1: Write collect-statements-step2-extract.php**

```php
<?php
/**
 * Step 2 helper: Extract prompt from step1 JSON and write to text file for claude -p.
 * Runs LOCALLY on Windows.
 *
 * Usage: php collect-statements-step2-extract.php <prompt-json> <output-txt>
 */

$inputFile = $argv[1] ?? '';
$outputFile = $argv[2] ?? '';

if (!$inputFile || !file_exists($inputFile)) {
    echo "ERROR: Input file not found: {$inputFile}\n";
    exit(1);
}
if (!$outputFile) {
    echo "ERROR: No output file specified\n";
    exit(1);
}

$data = json_decode(file_get_contents($inputFile), true);
if (!$data || ($data['status'] ?? '') !== 'ready') {
    echo "ERROR: Bad prompt data or collection disabled\n";
    echo "Status: " . ($data['status'] ?? 'unknown') . "\n";
    if (isset($data['message'])) echo "Message: " . $data['message'] . "\n";
    exit(1);
}

$prompt = $data['system_prompt'] . "\n\n---\n\n" . $data['user_message'];
file_put_contents($outputFile, $prompt);

echo "Prompt written: " . strlen($prompt) . " chars\n";
echo "Window: " . $data['window_start'] . " to " . $data['today'] . "\n";
echo "Existing statements: " . $data['statement_count'] . "\n";
```

- [ ] **Step 2: Commit**

```bash
git add scripts/maintenance/collect-statements-step2-extract.php
git commit -m "feat: add statement collection step 2 — extract prompt for claude -p"
```

---

### Task 9: Create step 3 — parse and insert statements

**Files:**
- Create: `scripts/maintenance/collect-statements-step3-insert.php`
- Reference: `scripts/maintenance/collect-threats-step3-insert.php` (mirror dedup + insert pattern)

- [ ] **Step 1: Write collect-statements-step3-insert.php**

```php
<?php
/**
 * Step 3: Parse Claude's JSON response and insert statements into DB.
 * Runs on the SERVER via SSH. Reads JSON from file argument.
 *
 * Usage: php collect-statements-step3-insert.php /path/to/claude-response.json
 */

$startTime = microtime(true);

$base = '/home/sandge5/tpb2.sandgems.net';
$config = require $base . '/config.php';
require_once $base . '/includes/site-settings.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Logging
$logDir = $base . '/scripts/maintenance/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/collect-statements-local.log';

function logMsg($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMsg("=== Step 3: Insert statements (local pipeline) ===");

// Read Claude's response
$inputFile = $argv[1] ?? '';
if (!$inputFile || !file_exists($inputFile)) {
    logMsg("ERROR: No input file provided or file not found: {$inputFile}");
    exit(1);
}

$rawText = file_get_contents($inputFile);
logMsg("Read " . strlen($rawText) . " bytes from {$inputFile}");

// Parse JSON
$jsonStr = $rawText;
if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}
if (preg_match('/\{[\s\S]*"statements"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['statements'])) {
    logMsg("ERROR: Could not parse JSON. Raw (first 2000 chars): " . substr($rawText, 0, 2000));
    exit(1);
}

$statements = $parsed['statements'];
$searchSummary = $parsed['search_summary'] ?? 'No summary provided';
logMsg("Search summary: {$searchSummary}");

if (empty($statements)) {
    logMsg("No new statements found.");
    setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'statement_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'note' => 'No new statements found (local pipeline)',
        'elapsed' => round(microtime(true) - $startTime, 1)
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0]);
    exit(0);
}

logMsg("Found " . count($statements) . " candidate statements. Running dedup...");

// Dedup: exact match on (official_id, statement_date, LEFT(content, 200))
$existingStmt = $pdo->query("SELECT id, official_id, statement_date, LEFT(content, 200) AS content_start, source_url FROM rep_statements")->fetchAll();

$existingKeys = [];
$existingUrls = [];
foreach ($existingStmt as $es) {
    $key = $es['official_id'] . '|' . $es['statement_date'] . '|' . strtolower(trim($es['content_start']));
    $existingKeys[] = $key;
    if ($es['source_url']) $existingUrls[] = strtolower(trim($es['source_url']));
}

$dedupedStatements = [];
$skippedCount = 0;
foreach ($statements as $s) {
    $content = trim($s['content'] ?? '');
    $officialId = intval($s['official_id'] ?? 326);
    $date = trim($s['statement_date'] ?? date('Y-m-d'));
    $url = strtolower(trim($s['source_url'] ?? ''));

    // URL dedup
    if ($url && in_array($url, $existingUrls)) {
        logMsg("DEDUP SKIP (same URL): " . substr($content, 0, 80));
        $skippedCount++;
        continue;
    }

    // Content dedup
    $key = $officialId . '|' . $date . '|' . strtolower(substr($content, 0, 200));
    if (in_array($key, $existingKeys)) {
        logMsg("DEDUP SKIP (same content): " . substr($content, 0, 80));
        $skippedCount++;
        continue;
    }

    $dedupedStatements[] = $s;
}

$statements = $dedupedStatements;
logMsg("After dedup: " . count($statements) . " new statements ({$skippedCount} duplicates skipped)");

if (empty($statements)) {
    logMsg("All statements were duplicates.");
    $elapsed = round(microtime(true) - $startTime, 1);
    setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
    setSiteSetting($pdo, 'statement_collect_last_result', json_encode([
        'status' => 'success', 'timestamp' => date('Y-m-d H:i:s'),
        'inserted' => 0, 'skipped' => $skippedCount, 'note' => 'All duplicates (local pipeline)',
        'elapsed' => $elapsed
    ]));
    echo json_encode(['status' => 'success', 'inserted' => 0, 'skipped' => $skippedCount]);
    exit(0);
}

logMsg("Inserting " . count($statements) . " new statements...");

// Insert statements
$insertStmt = $pdo->prepare("
    INSERT INTO rep_statements
    (official_id, source, source_url, content, summary, policy_topic, tense, severity_score, benefit_score, statement_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$validTenses = ['future', 'present', 'past'];
$inserted = 0;

foreach ($statements as $s) {
    $officialId = intval($s['official_id'] ?? 326);
    $source = trim($s['source'] ?? 'Unknown');
    $sourceUrl = trim($s['source_url'] ?? '');
    $content = trim($s['content'] ?? '');
    $summary = trim($s['summary'] ?? '');
    $policyTopic = trim($s['policy_topic'] ?? '');
    $tense = in_array($s['tense'] ?? '', $validTenses) ? $s['tense'] : null;
    $severityScore = isset($s['severity_score']) ? intval($s['severity_score']) : null;
    $benefitScore = isset($s['benefit_score']) ? intval($s['benefit_score']) : null;
    $date = trim($s['statement_date'] ?? date('Y-m-d'));

    if (empty($content)) {
        logMsg("SKIP: Empty content");
        continue;
    }

    try {
        $insertStmt->execute([
            $officialId, $source, $sourceUrl ?: null, $content,
            $summary ?: null, $policyTopic ?: null, $tense,
            $severityScore, $benefitScore, $date
        ]);
        $statementId = $pdo->lastInsertId();
        $inserted++;
        logMsg("Inserted statement #{$statementId} [{$source}] [{$tense}]: " . substr($content, 0, 80));
    } catch (PDOException $e) {
        logMsg("ERROR inserting statement: " . $e->getMessage() . " — " . substr($content, 0, 80));
    }
}

// Summary
$elapsed = round(microtime(true) - $startTime, 1);
$totalStatements = $pdo->query("SELECT COUNT(*) FROM rep_statements")->fetchColumn();

logMsg("=== Collection complete (local pipeline) ===");
logMsg("Statements inserted: {$inserted}");
logMsg("Total statements in DB: {$totalStatements}");
logMsg("Elapsed: {$elapsed}s");

setSiteSetting($pdo, 'statement_collect_last_success', date('Y-m-d H:i:s'));
$result = json_encode([
    'status' => 'success',
    'timestamp' => date('Y-m-d H:i:s'),
    'inserted' => $inserted,
    'skipped' => $skippedCount,
    'total_statements' => (int)$totalStatements,
    'elapsed' => $elapsed,
    'pipeline' => 'local'
]);
setSiteSetting($pdo, 'statement_collect_last_result', $result);
logMsg("Success recorded to site_settings.");

echo json_encode(['status' => 'success', 'inserted' => $inserted, 'skipped' => $skippedCount]);
```

- [ ] **Step 2: Commit**

```bash
git add scripts/maintenance/collect-statements-step3-insert.php
git commit -m "feat: add statement collection step 3 — parse Claude JSON + insert into DB"
```

---

### Task 10: Create batch orchestrator (.bat)

**Files:**
- Create: `scripts/maintenance/collect-statements-local.bat`
- Reference: `scripts/maintenance/collect-threats-local.bat` (mirror exactly, change paths/names)

- [ ] **Step 1: Write collect-statements-local.bat**

```bat
@echo off
setlocal

:: ============================================================
:: Local Statement Collection Pipeline
:: Mirrors threat collection but for presidential statements.
::
:: Step 1: SSH - server PHP gathers DB context
:: Step 2: Local claude -p does web search for statements
:: Step 3: SSH - server PHP parses + inserts into DB
:: ============================================================

set LOGFILE=c:\tpb2\scripts\maintenance\logs\collect-statements-local-bat.log
set TMPDIR=c:\tpb2\tmp
set PHP=C:\xampp\php\php.exe
set SSH=ssh sandge5@ecngx308.inmotionhosting.com -p 2222
set SCP=scp -P 2222
set REMOTE_TMP=~/tmp_statement

if not exist "c:\tpb2\scripts\maintenance\logs" mkdir "c:\tpb2\scripts\maintenance\logs"
if not exist "%TMPDIR%" mkdir "%TMPDIR%"

echo ============================================================ >> "%LOGFILE%"
echo START: %date% %time% >> "%LOGFILE%"

:: --- Step 1: Upload gather script and run on server ---
echo [%time%] Step 1: Gathering DB context from server... >> "%LOGFILE%"

%SCP% "c:\tpb2\scripts\maintenance\collect-statements-step1-gather.php" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-step1.php >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP step1 failed >> "%LOGFILE%"
    goto :done
)

%SSH% "/usr/local/bin/ea-php84 %REMOTE_TMP%-step1.php > %REMOTE_TMP%-prompt.json; rm %REMOTE_TMP%-step1.php" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SSH step1 failed >> "%LOGFILE%"
    goto :done
)

%SCP% sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-prompt.json "%TMPDIR%\statement-prompt.json" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP download prompt failed >> "%LOGFILE%"
    goto :done
)

:: Check if disabled
findstr /c:"\"status\":\"disabled\"" "%TMPDIR%\statement-prompt.json" >nul 2>&1
if %errorlevel% equ 0 (
    echo [%time%] Collection is disabled. Exiting. >> "%LOGFILE%"
    goto :done
)

echo [%time%] Step 1 complete. >> "%LOGFILE%"

:: --- Step 2: Extract prompt and call claude -p ---
echo [%time%] Step 2: Extracting prompt... >> "%LOGFILE%"

%PHP% "c:\tpb2\scripts\maintenance\collect-statements-step2-extract.php" "%TMPDIR%\statement-prompt.json" "%TMPDIR%\statement-claude-input.txt" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: Prompt extraction failed >> "%LOGFILE%"
    goto :done
)

echo [%time%] Calling claude -p with web search... >> "%LOGFILE%"

type "%TMPDIR%\statement-claude-input.txt" | claude -p --allowedTools "WebSearch,WebFetch" > "%TMPDIR%\statement-claude-response.json" 2>> "%LOGFILE%"
if %errorlevel% neq 0 (
    echo [%time%] ERROR: claude -p failed >> "%LOGFILE%"
    goto :done
)

echo [%time%] Step 2 complete. >> "%LOGFILE%"

:: --- Step 3: Upload response and run insert on server ---
echo [%time%] Step 3: Uploading response and inserting statements... >> "%LOGFILE%"

%SCP% "c:\tpb2\scripts\maintenance\collect-statements-step3-insert.php" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-step3.php >> "%LOGFILE%" 2>&1
%SCP% "%TMPDIR%\statement-claude-response.json" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-response.json >> "%LOGFILE%" 2>&1

if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP step3 failed >> "%LOGFILE%"
    goto :done
)

%SSH% "/usr/local/bin/ea-php84 %REMOTE_TMP%-step3.php %REMOTE_TMP%-response.json; rm %REMOTE_TMP%-step3.php %REMOTE_TMP%-response.json %REMOTE_TMP%-prompt.json" >> "%LOGFILE%" 2>&1

echo [%time%] Step 3 complete. >> "%LOGFILE%"

:done
echo END: %date% %time% >> "%LOGFILE%"
echo ============================================================ >> "%LOGFILE%"
endlocal
```

- [ ] **Step 2: Commit**

```bash
git add scripts/maintenance/collect-statements-local.bat
git commit -m "feat: add statement collection batch orchestrator for Windows Task Scheduler"
```

---

## Chunk 4: Admin Integration + benefit_score on Threats UI

### Task 11: Add statement collection toggles to admin settings

**Files:**
- Modify: `admin.php` — find the "Cron Jobs" section in the Settings tab and add statement collection toggles

- [ ] **Step 1: Read admin.php Settings/Cron section**

Find TWO locations in admin.php:

**Location A: Display section** — find the block that renders threat collection toggles (checkboxes for `threat_collect_local_enabled`, status displays for `threat_collect_last_result`). Add matching entries below for statements:

- Checkbox: `statement_collect_local_enabled` — "Enable Statement Collection (Local)"
- Status: `statement_collect_last_success` — last successful run timestamp
- Status: `statement_collect_last_result` — JSON result display

**Location B: POST save handler** — find the block (~line 413) that saves threat settings via `setSiteSetting()` and `logAdminAction()`. Add a matching block for statements:

```php
$statementCollectEnabled = !empty($_POST['statement_collect_local_enabled']) ? '1' : '0';
setSiteSetting($pdo, 'statement_collect_local_enabled', $statementCollectEnabled, $adminUserId);
logAdminAction($pdo, $adminUserId, 'update_setting', 'site_setting', null, [
    'key' => 'statement_collect_local_enabled',
    'value' => $statementCollectEnabled
]);
```

Both locations must be updated — the checkbox renders in Location A, and saving happens in Location B.

- [ ] **Step 2: Test in admin panel**

Visit: `http://localhost/tpb2/admin.php` → Settings tab → Cron Jobs section
Expected: Statement collection toggle appears, can be checked/unchecked, saves to `site_settings`.

- [ ] **Step 3: Commit**

```bash
git add admin.php
git commit -m "feat: add statement collection toggles to admin Settings tab"
```

---

### Task 12: Add benefit_score display to threats page

**Files:**
- Modify: `elections/threats.php` — add benefit score badge next to severity badge on threat cards
- Reference: `includes/benefit-severity.php` (for `getBenefitZone()`)

- [ ] **Step 1: Read threats.php card rendering section**

Find where severity badges are rendered on threat cards. Add benefit score badge alongside when `benefit_score` is not null:

```php
<?php if ($threat['benefit_score'] !== null):
    $bz = getBenefitZone($threat['benefit_score']);
?>
    <span class="score-badge benefit" style="background: <?= $bz['color'] ?>; color: #000;">
        Benefit: <?= $threat['benefit_score'] ?> (<?= $bz['label'] ?>)
    </span>
<?php endif; ?>
```

Also add `require_once __DIR__ . '/../includes/benefit-severity.php';` near the top.

Add `benefit_score` to the SELECT query for threats.

- [ ] **Step 2: Update "Threats" heading text to "Actions"**

Change the page heading from "Executive Threats" to "Executive Actions" (or add both labels).

- [ ] **Step 3: Commit**

```bash
git add elections/threats.php
git commit -m "feat: add benefit score badges to threat cards + rebrand to Actions"
```

---

### Task 13: Set up Windows Task Scheduler for twice-daily collection

- [ ] **Step 1: Create Task Scheduler entry**

Open Task Scheduler → Create Task:
- Name: `TPB Statement Collection (Morning)`
- Trigger: Daily at 6:00 AM
- Action: Start a program → `c:\tpb2\scripts\maintenance\collect-statements-local.bat`
- Start in: `c:\tpb2\scripts\maintenance`

Create second task:
- Name: `TPB Statement Collection (Evening)`
- Trigger: Daily at 6:00 PM
- Same action/start-in

- [ ] **Step 2: Enable the site setting**

In admin.php → Settings → check "Enable Statement Collection (Local)" to set `statement_collect_local_enabled = 1`.

- [ ] **Step 3: Test manually**

Run: `c:\tpb2\scripts\maintenance\collect-statements-local.bat` from command prompt.
Check: `scripts/maintenance/logs/collect-statements-local-bat.log` for output.
Check: `scripts/maintenance/logs/collect-statements-local.log` for step 3 insert log.
Check: `http://localhost/tpb2/elections/statements.php` for new statements.

---

### Task 14: Deploy to staging

- [ ] **Step 1: Push all changes**

```bash
git push origin master
```

- [ ] **Step 2: Pull on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

- [ ] **Step 3: Verify staging**

Visit: `https://tpb2.sandgems.net/elections/statements.php`
Expected: Page loads, shows statements (if collection has run), filters work, voting works.
