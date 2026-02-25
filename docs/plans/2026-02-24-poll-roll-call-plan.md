# Poll Roll Call System — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the existing poll system into a threat-based roll call where citizens vote "Is this acceptable?" and verified congress members vote "Will you act on this?" on executive threats scored 300+, with three public report views (national, by-state, by-rep).

**Architecture:** Extend existing `polls`/`poll_votes` tables with threat linkage and rep flag columns. Auto-generate poll rows from `executive_threats` where `severity_score >= 300`. Three new PHP pages for report views. Magic link flow for new visitor authentication. Rep verification via bioguide_id match against `elected_officials`.

**Tech Stack:** PHP 8.4, MySQL, vanilla JS, existing TPB includes (get-user, smtp-mail, point-logger, nav, footer, header)

**Design doc:** `docs/plans/2026-02-24-poll-roll-call-design.md`

---

### Task 1: DB Schema Migration

**Files:**
- Create: `scripts/db/poll-roll-call-migration.sql`

**Step 1: Write the migration SQL**

```sql
-- Poll Roll Call Migration
-- Extends existing poll tables for threat-based roll call system

-- 1. polls table: add threat linkage
ALTER TABLE polls ADD COLUMN threat_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN poll_type ENUM('general', 'threat') DEFAULT 'general';
ALTER TABLE polls ADD INDEX idx_threat_id (threat_id);
ALTER TABLE polls ADD INDEX idx_poll_type (poll_type);

-- 2. poll_votes table: add abstain + rep vote flag
ALTER TABLE poll_votes MODIFY vote_choice ENUM('yes', 'no', 'yea', 'nay', 'abstain');
ALTER TABLE poll_votes ADD COLUMN is_rep_vote TINYINT(1) DEFAULT 0;

-- 3. users table: link user to elected_officials for rep verification
ALTER TABLE users ADD COLUMN official_id INT DEFAULT NULL;
ALTER TABLE users ADD INDEX idx_official_id (official_id);
```

**Step 2: Execute migration on staging**

```bash
# Upload and execute via SSH
scp -P 2222 scripts/db/poll-roll-call-migration.sql sandge5@ecngx308.inmotionhosting.com:/tmp/
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/run-migration.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$sql = file_get_contents('/tmp/poll-roll-call-migration.sql');
\$statements = array_filter(array_map('trim', explode(';', \$sql)));
foreach (\$statements as \$stmt) {
    if (\$stmt) { \$p->exec(\$stmt); echo \$stmt . ' ... OK' . PHP_EOL; }
}
SCRIPT
php /tmp/run-migration.php && rm /tmp/run-migration.php /tmp/poll-roll-call-migration.sql"
```

Expected: 7 ALTERs all report OK.

**Step 3: Verify migration**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query(\"DESCRIBE polls\"); while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'].' | '.\$row['Type'].PHP_EOL;
echo '---'.PHP_EOL;
\$r = \$p->query(\"DESCRIBE poll_votes\"); while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'].' | '.\$row['Type'].PHP_EOL;
echo '---'.PHP_EOL;
\$r = \$p->query(\"SHOW COLUMNS FROM users LIKE 'official_id'\"); echo \$r->rowCount().' official_id column(s)'.PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `polls` shows `threat_id` and `poll_type` columns. `poll_votes` shows `vote_choice` with 5 enum values and `is_rep_vote`. `users` shows 1 `official_id` column.

**Step 4: Commit**

```bash
git add -f scripts/db/poll-roll-call-migration.sql
git commit -m "db: add poll roll call schema migration

Add threat_id + poll_type to polls, abstain + is_rep_vote to poll_votes,
official_id to users for rep verification."
```

---

### Task 2: Auto-Generate Threat Polls

**Files:**
- Create: `scripts/db/generate-threat-polls.php`

**Purpose:** One-time script (re-runnable) that creates a `polls` row for every `executive_threats` row with `severity_score >= 300`. Uses `INSERT IGNORE` so it's safe to re-run when new threats are added.

**Step 1: Write the generator script**

```php
<?php
/**
 * Generate poll rows for all executive threats scoring 300+.
 * Safe to re-run — skips threats that already have polls.
 * Run: php scripts/db/generate-threat-polls.php
 */
$config = require __DIR__ . '/../../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get all 300+ threats that don't yet have a poll
$threats = $pdo->query("
    SELECT et.threat_id, et.title, et.severity_score
    FROM executive_threats et
    LEFT JOIN polls p ON p.threat_id = et.threat_id AND p.poll_type = 'threat'
    WHERE et.severity_score >= 300
      AND et.is_active = 1
      AND p.poll_id IS NULL
    ORDER BY et.severity_score DESC
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($threats)) {
    echo "No new threat polls to generate.\n";
    exit(0);
}

$stmt = $pdo->prepare("
    INSERT INTO polls (slug, question, poll_type, threat_id, active, created_by)
    VALUES (?, ?, 'threat', ?, 1, NULL)
");

$count = 0;
foreach ($threats as $t) {
    $slug = 'threat-' . $t['threat_id'];
    $question = $t['title']; // Display uses threat title directly
    $stmt->execute([$slug, $question, $t['threat_id']]);
    $count++;
    echo "Created poll for threat #{$t['threat_id']} (severity {$t['severity_score']}): {$t['title']}\n";
}

echo "\nDone. Created {$count} threat polls.\n";

// Summary
$total = $pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat'")->fetchColumn();
echo "Total threat polls in DB: {$total}\n";
```

**Step 2: Upload and run on staging**

```bash
scp -P 2222 scripts/db/generate-threat-polls.php sandge5@ecngx308.inmotionhosting.com:/home/sandge5/tpb2.sandgems.net/scripts/db/
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && /usr/local/bin/ea-php84 scripts/db/generate-threat-polls.php"
```

Expected: ~113 polls created (one per 300+ threat).

**Step 3: Verify**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query(\"SELECT COUNT(*) as cnt, poll_type FROM polls GROUP BY poll_type\");
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['poll_type'].': '.\$row['cnt'].PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `general: 21`, `threat: 113` (approximately).

**Step 4: Commit**

```bash
git add scripts/db/generate-threat-polls.php
git commit -m "scripts: add threat poll auto-generator

Creates poll rows for executive threats scoring 300+.
Safe to re-run for new threats."
```

---

### Task 3: Shared Severity Helper

**Files:**
- Create: `includes/severity.php`

**Purpose:** Extract `getSeverityZone()` from `usa/executive.php` into a shared include so both executive.php and poll pages can use it.

**Step 1: Create the shared include**

```php
<?php
/**
 * Criminality Scale — Severity Zone Helper
 * Shared across executive.php and poll/ pages.
 *
 * Scale: 0-1000 geometric. Rates the ACT, not the actor.
 * Returns ['label' => string, 'color' => hex, 'class' => css-class]
 */
function getSeverityZone($score) {
    if ($score === null) return ['label' => 'Unscored', 'color' => '#555', 'class' => 'unscored'];
    if ($score === 0) return ['label' => 'Clean', 'color' => '#4caf50', 'class' => 'clean'];
    if ($score <= 10) return ['label' => 'Questionable', 'color' => '#8bc34a', 'class' => 'questionable'];
    if ($score <= 30) return ['label' => 'Misconduct', 'color' => '#cddc39', 'class' => 'misconduct'];
    if ($score <= 70) return ['label' => 'Misdemeanor', 'color' => '#ffeb3b', 'class' => 'misdemeanor'];
    if ($score <= 150) return ['label' => 'Felony', 'color' => '#ff9800', 'class' => 'felony'];
    if ($score <= 300) return ['label' => 'Serious Felony', 'color' => '#ff5722', 'class' => 'serious-felony'];
    if ($score <= 500) return ['label' => 'High Crime', 'color' => '#f44336', 'class' => 'high-crime'];
    if ($score <= 700) return ['label' => 'Atrocity', 'color' => '#d32f2f', 'class' => 'atrocity'];
    if ($score <= 900) return ['label' => 'Crime Against Humanity', 'color' => '#b71c1c', 'class' => 'crime-humanity'];
    return ['label' => 'Genocide', 'color' => '#000', 'class' => 'genocide'];
}
```

**Step 2: Update executive.php to use the shared include**

In `usa/executive.php`, replace the inline `getSeverityZone()` function (lines 87-99) with:

```php
require_once dirname(__DIR__) . '/includes/severity.php';
```

Delete the `function getSeverityZone(...)` block from executive.php.

**Step 3: Verify executive.php still works**

```bash
/c/xampp/php/php.exe -l usa/executive.php
```

Expected: No syntax errors.

**Step 4: Commit**

```bash
git add includes/severity.php usa/executive.php
git commit -m "refactor: extract getSeverityZone into shared include

Moves severity zone helper from executive.php to includes/severity.php
so poll pages can reuse it."
```

---

### Task 4: Rep Verification API

**Files:**
- Create: `api/verify-rep.php`

**Purpose:** POST endpoint. Accepts bioguide_id, last_name, state_code. Matches against `elected_officials`. On match, creates user account (or links existing) with `official_id` set. Returns success with rep details.

**Step 1: Write the API endpoint**

```php
<?php
/**
 * Rep Verification API
 * ====================
 * Verifies a congressional member by matching bioguide_id + last_name + state_code
 * against the elected_officials table.
 *
 * POST /api/verify-rep.php
 * Body: {
 *   "bioguide_id": "B001277",
 *   "last_name": "Blumenthal",
 *   "state_code": "CT",
 *   "session_id": "civic_xxx"
 * }
 *
 * On match: creates/links user account with official_id set.
 * Returns: { status, official, user_id }
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$input = json_decode(file_get_contents('php://input'), true);
$bioguideId = strtoupper(trim($input['bioguide_id'] ?? ''));
$lastName = trim($input['last_name'] ?? '');
$stateCode = strtoupper(trim($input['state_code'] ?? ''));
$sessionId = $input['session_id'] ?? null;

if (!$bioguideId || !$lastName || !$stateCode || !$sessionId) {
    echo json_encode(['status' => 'error', 'message' => 'All fields required: bioguide_id, last_name, state_code, session_id']);
    exit();
}

// Match against elected_officials
$stmt = $pdo->prepare("
    SELECT official_id, full_name, title, party, state_code, bioguide_id
    FROM elected_officials
    WHERE bioguide_id = ?
      AND LOWER(SUBSTRING_INDEX(full_name, ' ', -1)) = LOWER(?)
      AND state_code = ?
      AND is_current = 1
");
$stmt->execute([$bioguideId, $lastName, $stateCode]);
$official = $stmt->fetch();

if (!$official) {
    echo json_encode(['status' => 'error', 'message' => 'No matching representative found. Please verify your Bioguide ID, last name, and state.']);
    exit();
}

// Check if another user already claimed this official
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE official_id = ? AND deleted_at IS NULL");
$stmt->execute([$official['official_id']]);
if ($stmt->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'This representative account has already been verified.']);
    exit();
}

// Check if session has an existing user
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);

if ($dbUser) {
    // Link existing user to official
    $stmt = $pdo->prepare("UPDATE users SET official_id = ? WHERE user_id = ?");
    $stmt->execute([$official['official_id'], $dbUser['user_id']]);
    $userId = $dbUser['user_id'];
} else {
    // Create new rep user account
    $username = 'rep_' . strtolower($bioguideId);
    $email = null; // Rep accounts don't require email initially

    $stmt = $pdo->prepare("
        INSERT INTO users (username, official_id, session_id, civic_points)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([$username, $official['official_id'], $sessionId]);
    $userId = $pdo->lastInsertId();

    // Link device
    $stmt = $pdo->prepare("
        INSERT INTO user_devices (user_id, device_session)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE user_id = ?, is_active = 1
    ");
    $stmt->execute([$userId, $sessionId, $userId]);

    // Set cookie via include
    require_once __DIR__ . '/../includes/set-cookie.php';
}

echo json_encode([
    'status' => 'success',
    'message' => "Verified: {$official['full_name']} ({$official['title']}, {$official['state_code']})",
    'user_id' => $userId,
    'official' => [
        'official_id' => $official['official_id'],
        'full_name' => $official['full_name'],
        'title' => $official['title'],
        'party' => $official['party'],
        'state_code' => $official['state_code'],
        'bioguide_id' => $official['bioguide_id']
    ]
]);
```

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l api/verify-rep.php
```

**Step 3: Commit**

```bash
git add api/verify-rep.php
git commit -m "feat: add rep verification API

Verifies congressional members via bioguide_id + last name + state
match against elected_officials table."
```

---

### Task 5: Update poll/.htaccess

**Files:**
- Modify: `poll/.htaccess`

**Step 1: Add rewrite rules for new pages**

Replace entire file with:

```apache
# TPB Poll System
RewriteEngine On
RewriteBase /poll/

# Report views
RewriteRule ^national/?$ national.php [L]
RewriteRule ^by-state/?$ by-state.php [L]
RewriteRule ^by-state/([a-z]{2})/?$ by-state.php?state=$1 [L]
RewriteRule ^by-rep/?$ by-rep.php [L]
RewriteRule ^by-rep/([A-Z0-9]+)/?$ by-rep.php?bioguide=$1 [L]

# Existing
RewriteRule ^closed/?$ closed.php [L]
RewriteRule ^admin/?$ admin.php [L]
```

**Step 2: Commit**

```bash
git add poll/.htaccess
git commit -m "feat: add poll rewrite rules for report views

Routes /poll/national/, /poll/by-state/XX/, /poll/by-rep/ID/"
```

---

### Task 6: Nav Update

**Files:**
- Modify: `includes/nav.php:525`

**Step 1: Replace "Amendment 28" with "Polls"**

At line 525 of `includes/nav.php`, change:

```php
<a href="/28/" <?= $currentPage === 'action' ? 'class="active"' : '' ?>>Amendment 28</a>
```

To:

```php
<a href="/poll/" <?= $currentPage === 'poll' ? 'class="active"' : '' ?>>Polls</a>
```

**Step 2: Commit**

```bash
git add includes/nav.php
git commit -m "nav: replace Amendment 28 with Polls link"
```

---

### Task 7: poll/index.php — Full Redesign

**Files:**
- Modify: `poll/index.php`

This is the largest task. The page needs to:

1. **Query threat polls** — join `polls` → `executive_threats` for 300+ threats with severity scores and tags
2. **Detect auth state** — remembered citizen, new visitor, verified rep (via `$dbUser` and `$dbUser['official_id']`)
3. **Handle vote submission** — POST with `poll_id` + `vote_choice` (yea/nay/abstain), set `is_rep_vote` if user has `official_id`
4. **Magic link flow** — for new visitors, show email input that calls `api/send-magic-link.php` with `return_url=/poll/`
5. **Rep verification** — "I am a U.S. Representative/Senator" checkbox reveals form, calls `api/verify-rep.php`
6. **Display threat poll cards** — severity badge, threat title, question (varies by audience), vote buttons, results bar
7. **Sort controls** — severity (default), date, tag

**Key data queries:**

```php
// Get all threat polls with threat data
$stmt = $pdo->query("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score, et.threat_date, et.official_id,
           eo.full_name as official_name,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_votes,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_votes,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_votes
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN elected_officials eo ON et.official_id = eo.official_id
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
");
```

**Key auth detection:**

```php
$isRep = $dbUser && $dbUser['official_id'];
$isRemembered = $dbUser && !$isRep;
$isVisitor = !$dbUser;
$question = $isRep ? 'Will you act on this?' : 'Is this acceptable?';
```

**Key HTML structure for each poll card:**

```html
<div class="poll-card" data-severity="<?= $threat['severity_score'] ?>" data-date="<?= $threat['threat_date'] ?>">
    <div class="severity-badge" style="background: <?= $zone['color'] ?>">
        <?= $threat['severity_score'] ?> — <?= $zone['label'] ?>
    </div>
    <div class="poll-question"><?= htmlspecialchars($threat['title']) ?></div>
    <div class="poll-prompt"><?= $question ?></div>

    <!-- Vote buttons (if authenticated) -->
    <!-- OR magic link prompt (if visitor) -->
    <!-- Results bar (after voting or if visitor) -->
</div>
```

**Step 1: Rewrite poll/index.php**

Full rewrite. Preserve the existing general poll display in a separate section below the threat polls, or link to it. The threat polls are the primary content.

Reference files for patterns:
- `usa/executive.php` — severity badges, tag pills, data queries
- `poll/index.php` (current) — vote submission, bot protection, civic points
- `api/send-magic-link.php` — magic link flow (call via fetch() from JS)

CSS should match existing TPB dark theme: `#0a0a0f` background, `#1a1a2e` cards, `#d4af37` gold accents, `#e0e0e0` text.

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l poll/index.php
```

**Step 3: Commit**

```bash
git add poll/index.php
git commit -m "feat: redesign poll/index.php for threat roll call

300+ severity threats as poll cards with severity badges.
Three auth paths: remembered citizen, magic link, rep verification.
Citizens: 'Is this acceptable?' Reps: 'Will you act on this?'
Yea/Nay/Abstain voting with results bars."
```

---

### Task 8: poll/national.php — National View

**Files:**
- Create: `poll/national.php`

**Purpose:** Aggregate all citizen (non-rep) votes across all states for each threat poll.

**Key queries:**

```php
// National aggregate — citizen votes only (is_rep_vote = 0)
$stmt = $pdo->query("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score, et.threat_date,
           COUNT(pv.poll_vote_id) as total_votes,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_votes,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_votes,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_votes
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id AND pv.is_rep_vote = 0
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
");
```

**Display:**
- Summary stats header: total threats polled, total votes cast, overall yea/nay ratio
- Each threat: severity badge + title + three-segment results bar (yea green / nay red / abstain gray) + vote count
- Sort dropdown: severity (default), most votes, date
- View links at top: **National** (active) | By State | By Rep
- Uses `includes/severity.php` for badges
- Standard nav.php + footer.php

**Step 1: Write poll/national.php**

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l poll/national.php
```

**Step 3: Commit**

```bash
git add poll/national.php
git commit -m "feat: add national poll results view

Aggregate citizen votes across all states for 300+ threats.
Sorted by severity with three-segment results bars."
```

---

### Task 9: poll/by-state.php — State View

**Files:**
- Create: `poll/by-state.php`

**Purpose:** Two modes:
1. **Landing** (`/poll/by-state/`): 50-state table showing total votes per state
2. **State detail** (`/poll/by-state/ct/`): each threat with that state's citizen votes + national comparison

**Key queries (state detail):**

```php
$stateCode = strtoupper($_GET['state'] ?? '');

// Get state info
$stmt = $pdo->prepare("SELECT state_id, state_name, abbreviation FROM states WHERE abbreviation = ?");
$stmt->execute([$stateCode]);
$state = $stmt->fetch();

// State votes per threat
$stmt = $pdo->prepare("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score,
           COUNT(pv.poll_vote_id) as state_votes,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as state_yea,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as state_nay,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as state_abstain
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN poll_votes pv ON p.poll_id = pv.poll_id
        AND pv.is_rep_vote = 0
        AND pv.user_id IN (SELECT user_id FROM users WHERE current_state_id = ?)
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
");
$stmt->execute([$state['state_id']]);
```

**Landing query:**

```php
// Votes per state
$stmt = $pdo->query("
    SELECT s.abbreviation, s.state_name, COUNT(pv.poll_vote_id) as total_votes
    FROM states s
    LEFT JOIN users u ON u.current_state_id = s.state_id AND u.deleted_at IS NULL
    LEFT JOIN poll_votes pv ON pv.user_id = u.user_id AND pv.is_rep_vote = 0
    LEFT JOIN polls p ON pv.poll_id = p.poll_id AND p.poll_type = 'threat'
    GROUP BY s.state_id
    ORDER BY s.state_name
");
```

**Display:**
- Landing: state table with vote counts, each state links to `/poll/by-state/XX/`
- Detail: threat list with "Your State" vs "National" columns
- View links at top: National | **By State** (active) | By Rep
- Breadcrumb on detail: By State > Connecticut

**Step 1: Write poll/by-state.php**

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l poll/by-state.php
```

**Step 3: Commit**

```bash
git add poll/by-state.php
git commit -m "feat: add by-state poll results view

50-state landing with drill-down. Shows state vs national comparison
for each 300+ threat."
```

---

### Task 10: poll/by-rep.php — Rep View

**Files:**
- Create: `poll/by-rep.php`

**Purpose:** Two modes:
1. **Landing** (`/poll/by-rep/`): all reps listed with vote record summary, silence rate
2. **Rep detail** (`/poll/by-rep/B001277/`): full roll call — every threat with rep's position vs state citizen vote

**Key queries (rep detail):**

```php
$bioguide = $_GET['bioguide'] ?? '';

// Get rep info
$stmt = $pdo->prepare("
    SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code, eo.bioguide_id
    FROM elected_officials eo
    WHERE eo.bioguide_id = ? AND eo.is_current = 1
");
$stmt->execute([$bioguide]);
$rep = $stmt->fetch();

// Get rep's user account (if they've verified)
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE official_id = ? AND deleted_at IS NULL");
$stmt->execute([$rep['official_id']]);
$repUser = $stmt->fetch();

// Get all threats with rep's vote + state citizen votes
$stmt = $pdo->prepare("
    SELECT p.poll_id, p.threat_id,
           et.title, et.severity_score,
           rep_vote.vote_choice as rep_position,
           COUNT(citizen_vote.poll_vote_id) as state_votes,
           SUM(CASE WHEN citizen_vote.vote_choice = 'yea' THEN 1 ELSE 0 END) as state_yea,
           SUM(CASE WHEN citizen_vote.vote_choice = 'nay' THEN 1 ELSE 0 END) as state_nay
    FROM polls p
    JOIN executive_threats et ON p.threat_id = et.threat_id
    LEFT JOIN poll_votes rep_vote ON p.poll_id = rep_vote.poll_id
        AND rep_vote.user_id = ? AND rep_vote.is_rep_vote = 1
    LEFT JOIN poll_votes citizen_vote ON p.poll_id = citizen_vote.poll_id
        AND citizen_vote.is_rep_vote = 0
        AND citizen_vote.user_id IN (
            SELECT user_id FROM users
            WHERE current_state_id = (SELECT state_id FROM states WHERE abbreviation = ?)
        )
    WHERE p.poll_type = 'threat' AND p.active = 1
    GROUP BY p.poll_id
    ORDER BY et.severity_score DESC
");
$stmt->execute([$repUser ? $repUser['user_id'] : 0, $rep['state_code']]);
```

**Landing query:**

```php
// All reps with their vote stats
$reps = $pdo->query("
    SELECT eo.official_id, eo.full_name, eo.title, eo.party, eo.state_code, eo.bioguide_id,
           u.user_id,
           COUNT(pv.poll_vote_id) as threats_responded,
           SUM(CASE WHEN pv.vote_choice = 'yea' THEN 1 ELSE 0 END) as yea_count,
           SUM(CASE WHEN pv.vote_choice = 'nay' THEN 1 ELSE 0 END) as nay_count,
           SUM(CASE WHEN pv.vote_choice = 'abstain' THEN 1 ELSE 0 END) as abstain_count
    FROM elected_officials eo
    LEFT JOIN users u ON u.official_id = eo.official_id AND u.deleted_at IS NULL
    LEFT JOIN poll_votes pv ON pv.user_id = u.user_id AND pv.is_rep_vote = 1
    WHERE eo.is_current = 1
      AND eo.bioguide_id IS NOT NULL
    GROUP BY eo.official_id
    ORDER BY eo.state_code, eo.full_name
")->fetchAll(PDO::FETCH_ASSOC);

$totalThreatPolls = $pdo->query("SELECT COUNT(*) FROM polls WHERE poll_type = 'threat' AND active = 1")->fetchColumn();
```

**Display:**
- Landing: rep table with name, state, party, threats responded X/113, silence rate, yea/nay/abstain %
- Detail: full roll call table — threat | severity | rep position | state citizens | gap
- Gap column: highlight when rep diverges from state majority (red for gap, green for aligned)
- Filter dropdowns: state, chamber (House/Senate), party
- View links at top: National | By State | **By Rep** (active)

**Step 1: Write poll/by-rep.php**

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l poll/by-rep.php
```

**Step 3: Commit**

```bash
git add poll/by-rep.php
git commit -m "feat: add by-rep poll results view

Rep roll call with silence rate, yea/nay/abstain record.
Drill-down shows rep position vs state citizen vote with gap column."
```

---

### Task 11: Update poll/admin.php

**Files:**
- Modify: `poll/admin.php`

**Changes:**
- Add section showing threat poll stats (total, vote counts)
- Add "Regenerate Threat Polls" button that runs the generator logic for new 300+ threats
- Keep existing general poll management untouched
- Show `poll_type` badge on each poll in the list (general vs threat)

**Step 1: Update poll/admin.php**

Add a "Threat Polls" section above the existing "All Polls" section:
- Count of threat polls, total votes on threat polls
- Button to sync (create polls for any new 300+ threats)
- In the poll table, show `[threat]` or `[general]` badge next to each poll

**Step 2: Verify syntax**

```bash
/c/xampp/php/php.exe -l poll/admin.php
```

**Step 3: Commit**

```bash
git add poll/admin.php
git commit -m "feat: add threat poll management to admin

Shows threat poll stats, sync button for new threats,
poll type badge in listing."
```

---

### Task 12: Deploy + Verify

**Step 1: Push all changes**

```bash
git push origin master
```

**Step 2: Pull on staging**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 3: Manual verification checklist**

- [ ] `tpb2.sandgems.net/poll/` — threat polls visible with severity badges
- [ ] Severity badges color-coded correctly (red for 300-500, dark red for 500+)
- [ ] Logged-in citizen can vote yea/nay/abstain
- [ ] New visitor sees magic link prompt
- [ ] "I am a U.S. Representative/Senator" checkbox reveals verification form
- [ ] `tpb2.sandgems.net/poll/national/` — aggregate results page loads
- [ ] `tpb2.sandgems.net/poll/by-state/` — 50-state table loads
- [ ] `tpb2.sandgems.net/poll/by-state/ct/` — Connecticut detail loads
- [ ] `tpb2.sandgems.net/poll/by-rep/` — rep listing loads
- [ ] Nav shows "Polls" instead of "Amendment 28"
- [ ] `tpb2.sandgems.net/poll/closed/` — still works for general polls
- [ ] `tpb2.sandgems.net/usa/executive.php` — still works (shared severity helper)

**Step 4: Commit any fixes from verification**

---

## Task Summary

| Task | What | Files |
|------|------|-------|
| 1 | DB schema migration | `scripts/db/poll-roll-call-migration.sql` |
| 2 | Auto-generate threat polls | `scripts/db/generate-threat-polls.php` |
| 3 | Shared severity helper | `includes/severity.php`, `usa/executive.php` |
| 4 | Rep verification API | `api/verify-rep.php` |
| 5 | URL rewrite rules | `poll/.htaccess` |
| 6 | Nav update | `includes/nav.php` |
| 7 | poll/index.php redesign | `poll/index.php` |
| 8 | National view | `poll/national.php` |
| 9 | By-state view | `poll/by-state.php` |
| 10 | By-rep view | `poll/by-rep.php` |
| 11 | Admin updates | `poll/admin.php` |
| 12 | Deploy + verify | staging server |
