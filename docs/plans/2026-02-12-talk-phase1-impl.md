# /talk Phase 1 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Upgrade /talk from a flat idea logger to a session-aware, user-attributed, threadable idea system with end-to-end UI wiring.

**Architecture:** Vanilla PHP API with action-based routing (`?action=X`), user identification via shared `getUser()`, client-side session UUIDs in `sessionStorage`. No framework, no new dependencies.

**Tech Stack:** PHP 8.4, MySQL, vanilla JS, existing shared includes (`get-user.php`)

**Design doc:** `docs/plans/2026-02-12-talk-phase1-design.md`

---

## Task 1: Database Migration Script

**Files:**
- Create: `scripts/db/talk-phase1-alter-idea-log.sql`

**Step 1: Write the migration SQL**

Create `scripts/db/talk-phase1-alter-idea-log.sql`:

```sql
-- /talk Phase 1: Expand idea_log for sessions, users, threading, status
-- Run against: sandge5_tpb2
-- Date: 2026-02-12

-- 1. Convert category from ENUM to VARCHAR(50)
ALTER TABLE idea_log MODIFY category VARCHAR(50) DEFAULT 'idea';

-- 2. Add new columns
ALTER TABLE idea_log ADD COLUMN user_id INT NULL AFTER id;
ALTER TABLE idea_log ADD COLUMN session_id VARCHAR(36) NULL AFTER user_id;
ALTER TABLE idea_log ADD COLUMN parent_id INT NULL AFTER session_id;
ALTER TABLE idea_log ADD COLUMN status ENUM('raw','refining','distilled','actionable','archived') DEFAULT 'raw' AFTER category;
ALTER TABLE idea_log ADD COLUMN ai_response TEXT NULL AFTER content;
ALTER TABLE idea_log ADD COLUMN tags VARCHAR(500) NULL AFTER status;
ALTER TABLE idea_log ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 3. Indexes
ALTER TABLE idea_log ADD INDEX idx_user (user_id);
ALTER TABLE idea_log ADD INDEX idx_session (session_id);
ALTER TABLE idea_log ADD INDEX idx_parent (parent_id);
ALTER TABLE idea_log ADD INDEX idx_status (status);

-- 4. Foreign keys
ALTER TABLE idea_log ADD FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE idea_log ADD FOREIGN KEY (parent_id) REFERENCES idea_log(id) ON DELETE SET NULL;
```

**Step 2: Run migration on local XAMPP**

Run each ALTER statement via phpMyAdmin at `localhost/phpmyadmin` against the local `sandge5_tpb2` database. Verify with:

```sql
DESCRIBE idea_log;
```

Expected: all 7 new columns visible (user_id, session_id, parent_id, status, ai_response, tags, updated_at). Category type = varchar(50). Existing rows have status='raw', all new columns NULL.

**Step 3: Run migration on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec('ALTER TABLE idea_log MODIFY category VARCHAR(50) DEFAULT \'idea\'');
\$p->exec('ALTER TABLE idea_log ADD COLUMN user_id INT NULL AFTER id');
\$p->exec('ALTER TABLE idea_log ADD COLUMN session_id VARCHAR(36) NULL AFTER user_id');
\$p->exec('ALTER TABLE idea_log ADD COLUMN parent_id INT NULL AFTER session_id');
\$p->exec('ALTER TABLE idea_log ADD COLUMN status ENUM(\'raw\',\'refining\',\'distilled\',\'actionable\',\'archived\') DEFAULT \'raw\' AFTER category');
\$p->exec('ALTER TABLE idea_log ADD COLUMN ai_response TEXT NULL AFTER content');
\$p->exec('ALTER TABLE idea_log ADD COLUMN tags VARCHAR(500) NULL AFTER status');
\$p->exec('ALTER TABLE idea_log ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
\$p->exec('ALTER TABLE idea_log ADD INDEX idx_user (user_id)');
\$p->exec('ALTER TABLE idea_log ADD INDEX idx_session (session_id)');
\$p->exec('ALTER TABLE idea_log ADD INDEX idx_parent (parent_id)');
\$p->exec('ALTER TABLE idea_log ADD INDEX idx_status (status)');
\$p->exec('ALTER TABLE idea_log ADD FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL');
\$p->exec('ALTER TABLE idea_log ADD FOREIGN KEY (parent_id) REFERENCES idea_log(id) ON DELETE SET NULL');
\$r = \$p->query('DESCRIBE idea_log');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: DESCRIBE output shows all new columns.

**Step 4: Commit**

```bash
git add scripts/db/talk-phase1-alter-idea-log.sql
git commit -m "Add Phase 1 migration: expand idea_log for sessions, users, threading"
```

---

## Task 2: API — Save Action (rewrite api.php)

**Files:**
- Modify: `talk/api.php` (full rewrite)

**Context to read first:**
- `includes/get-user.php` — `getUser($pdo)` function (cookie-based user lookup)
- Current `talk/api.php` — the flat INSERT to replace

**Step 1: Rewrite api.php with action routing and save handler**

Replace the entire contents of `talk/api.php` with:

```php
<?php
/**
 * Talk API — /talk/api.php
 *
 * Action-based routing for the /talk brainstorming system.
 *
 * Actions:
 *   (none)/save  — POST: Save an idea
 *   history      — GET:  Read back ideas with filters
 *   promote      — POST: Advance idea status
 *   link         — POST: Set parent_id on an idea
 *
 * User identified server-side via getUser() from cookies.
 * Session ID provided by client (JS crypto.randomUUID()).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database
$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// User identification
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$userId = $dbUser ? (int)$dbUser['user_id'] : null;

// Determine action
$action = $_GET['action'] ?? '';

// Backward compatibility: old GET-based saves (?content=X&category=Y&source=Z)
if (!$action && isset($_GET['content'])) {
    $action = 'save';
}

// For POST requests without action, default to save
if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = 'save';
}

// Parse JSON body for POST requests
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

try {
    switch ($action) {
        case 'save':
            echo json_encode(handleSave($pdo, $input, $userId));
            break;
        case 'history':
            echo json_encode(handleHistory($pdo, $userId));
            break;
        case 'promote':
            echo json_encode(handlePromote($pdo, $input, $userId));
            break;
        case 'link':
            echo json_encode(handleLink($pdo, $input, $userId));
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}


// ── Save ───────────────────────────────────────────────────────────

function handleSave($pdo, $input, $userId) {
    // Support both JSON body and GET params (backward compat)
    $content   = trim($input['content']   ?? $_GET['content']   ?? '');
    $category  = $input['category']  ?? $_GET['category']  ?? 'idea';
    $source    = $input['source']    ?? $_GET['source']    ?? 'web';
    $sessionId = $input['session_id'] ?? null;
    $parentId  = $input['parent_id']  ?? null;
    $tags      = $input['tags']       ?? null;

    if ($content === '') {
        return ['success' => false, 'error' => 'Content is required'];
    }

    // Validate category
    $validCategories = ['idea', 'decision', 'todo', 'note', 'question', 'reaction', 'distilled', 'digest'];
    if (!in_array($category, $validCategories)) {
        $category = 'idea';
    }

    // Validate source
    $validSources = ['web', 'voice', 'claude-web', 'claude-desktop', 'api'];
    if (!in_array($source, $validSources)) {
        $source = 'web';
    }

    // Validate parent_id exists if provided
    if ($parentId !== null) {
        $parentId = (int)$parentId;
        $check = $pdo->prepare("SELECT id FROM idea_log WHERE id = ?");
        $check->execute([$parentId]);
        if (!$check->fetch()) {
            return ['success' => false, 'error' => 'Parent idea not found'];
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO idea_log (user_id, session_id, parent_id, content, category, status, tags, source)
        VALUES (:user_id, :session_id, :parent_id, :content, :category, 'raw', :tags, :source)
    ");

    $stmt->execute([
        ':user_id'    => $userId,
        ':session_id' => $sessionId,
        ':parent_id'  => $parentId,
        ':content'    => $content,
        ':category'   => $category,
        ':tags'       => $tags,
        ':source'     => $source
    ]);

    $id = (int)$pdo->lastInsertId();

    return [
        'success'    => true,
        'id'         => $id,
        'session_id' => $sessionId,
        'status'     => 'raw',
        'message'    => ucfirst($category) . ' #' . $id . ' saved (raw)'
    ];
}


// ── History ────────────────────────────────────────────────────────

function handleHistory($pdo, $userId) {
    $sessionId = $_GET['session_id'] ?? null;
    $category  = $_GET['category']   ?? null;
    $status    = $_GET['status']     ?? null;
    $limit     = min((int)($_GET['limit'] ?? 50), 200);
    $includeAi = (bool)($_GET['include_ai'] ?? false);

    if ($limit < 1) $limit = 50;

    $where = [];
    $params = [];

    // User-scoped by default if logged in
    if ($userId && !$sessionId) {
        $where[] = 'i.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    if ($sessionId) {
        $where[] = 'i.session_id = :session_id';
        $params[':session_id'] = $sessionId;
    }

    if ($category) {
        $where[] = 'i.category = :category';
        $params[':category'] = $category;
    }

    if ($status) {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $aiColumn = $includeAi ? ', i.ai_response' : '';

    $sql = "
        SELECT i.id, i.user_id, i.session_id, i.parent_id,
               i.content{$aiColumn}, i.category, i.status, i.tags, i.source,
               i.created_at, i.updated_at,
               u.first_name AS user_first_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id) AS children_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ideas = $stmt->fetchAll();

    return [
        'success' => true,
        'ideas'   => $ideas
    ];
}


// ── Promote ────────────────────────────────────────────────────────

function handlePromote($pdo, $input, $userId) {
    $ideaId   = (int)($input['idea_id'] ?? 0);
    $newStatus = $input['status'] ?? '';

    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }

    $validStatuses = ['raw', 'refining', 'distilled', 'actionable', 'archived'];
    if (!in_array($newStatus, $validStatuses)) {
        return ['success' => false, 'error' => 'Invalid status: ' . $newStatus];
    }

    // Fetch the idea
    $stmt = $pdo->prepare("SELECT id, user_id, status FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();

    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }

    // Owner check (skip if idea has no owner or user not logged in)
    if ($idea['user_id'] && $userId && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only promote your own ideas'];
    }

    // Forward-only check (archived allowed from any state)
    $statusOrder = ['raw' => 0, 'refining' => 1, 'distilled' => 2, 'actionable' => 3, 'archived' => 99];
    $currentRank = $statusOrder[$idea['status']] ?? 0;
    $newRank     = $statusOrder[$newStatus] ?? 0;

    if ($newRank <= $currentRank && $newStatus !== 'archived') {
        return ['success' => false, 'error' => 'Status can only advance forward. Current: ' . $idea['status']];
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $ideaId]);

    return [
        'success'    => true,
        'idea_id'    => $ideaId,
        'old_status' => $idea['status'],
        'new_status' => $newStatus
    ];
}


// ── Link ───────────────────────────────────────────────────────────

function handleLink($pdo, $input, $userId) {
    $ideaId   = (int)($input['idea_id']   ?? 0);
    $parentId = (int)($input['parent_id'] ?? 0);

    if (!$ideaId || !$parentId) {
        return ['success' => false, 'error' => 'idea_id and parent_id are required'];
    }

    if ($ideaId === $parentId) {
        return ['success' => false, 'error' => 'An idea cannot be its own parent'];
    }

    // Fetch both ideas
    $stmt = $pdo->prepare("SELECT id, user_id, parent_id FROM idea_log WHERE id IN (?, ?)");
    $stmt->execute([$ideaId, $parentId]);
    $rows = $stmt->fetchAll();

    $idea = null;
    $parent = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $ideaId)   $idea = $row;
        if ((int)$row['id'] === $parentId) $parent = $row;
    }

    if (!$idea)   return ['success' => false, 'error' => 'Idea not found'];
    if (!$parent) return ['success' => false, 'error' => 'Parent idea not found'];

    // Owner check
    if ($idea['user_id'] && $userId && (int)$idea['user_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only link your own ideas'];
    }

    // Circular reference check: walk up from parent to ensure we don't hit ideaId
    $current = $parentId;
    $visited = [];
    while ($current) {
        if ($current === $ideaId) {
            return ['success' => false, 'error' => 'Circular reference detected'];
        }
        if (in_array($current, $visited)) break; // safety: break infinite loop
        $visited[] = $current;
        $check = $pdo->prepare("SELECT parent_id FROM idea_log WHERE id = ?");
        $check->execute([$current]);
        $row = $check->fetch();
        $current = $row ? (int)$row['parent_id'] : 0;
    }

    $stmt = $pdo->prepare("UPDATE idea_log SET parent_id = ? WHERE id = ?");
    $stmt->execute([$parentId, $ideaId]);

    return [
        'success'   => true,
        'idea_id'   => $ideaId,
        'parent_id' => $parentId
    ];
}
```

**Step 2: Verify save action works (backward compat)**

Test the old GET-based save still works:

```
curl "http://localhost/talk/api.php?content=test+backward+compat&category=note&source=api"
```

Expected: `{"success":true,"id":N,"session_id":null,"status":"raw","message":"Note #N saved (raw)"}`

**Step 3: Verify save action works (new JSON POST)**

```
curl -X POST http://localhost/talk/api.php \
  -H "Content-Type: application/json" \
  -d "{\"content\":\"test new post\",\"category\":\"idea\",\"source\":\"web\",\"session_id\":\"test-session-001\"}"
```

Expected: `{"success":true,"id":N,"session_id":"test-session-001","status":"raw","message":"Idea #N saved (raw)"}`

**Step 4: Verify history action**

```
curl "http://localhost/talk/api.php?action=history&limit=5"
```

Expected: JSON with `success:true` and `ideas` array containing recent entries with `children_count`.

**Step 5: Commit**

```bash
git add talk/api.php
git commit -m "Rewrite talk/api.php: action routing, save, history, promote, link"
```

---

## Task 3: Frontend — Update index.php

**Files:**
- Modify: `talk/index.php`

**Context to read first:**
- Current `talk/index.php` — the UI to modify
- The approved design Section 3 (POST JSON, session_id, categories, source tracking)

**Step 1: Update the category buttons in the HTML**

Find the existing category-row div and replace it with the expanded set. Add `question` and `reaction` chips. The `reaction` chip gets a `disabled` class by default (no parent_id yet).

In the HTML section, replace the category-row:

```html
<div class="category-row">
    <button class="category-btn active" data-category="idea">💡 Idea</button>
    <button class="category-btn" data-category="decision">✅ Decision</button>
    <button class="category-btn" data-category="todo">📋 Todo</button>
    <button class="category-btn" data-category="note">📝 Note</button>
    <button class="category-btn" data-category="question">❓ Question</button>
    <button class="category-btn disabled" data-category="reaction" id="reactionBtn" title="Available when reacting to an idea">↩️ Reaction</button>
</div>
```

Add CSS for the disabled state:

```css
.category-btn.disabled {
    opacity: 0.3;
    pointer-events: none;
}
```

**Step 2: Update the JavaScript — session ID and POST JSON**

Replace the submit handler and add session management. Changes:

1. Generate session_id on page load, store in sessionStorage
2. Track source (voice vs typed) with a variable
3. POST JSON instead of GET query string
4. Show richer success message

In the `<script>` section:

```javascript
// Session ID — one per tab, persists across saves in same tab
let sessionId = sessionStorage.getItem('tpb_session');
if (!sessionId) {
    sessionId = crypto.randomUUID();
    sessionStorage.setItem('tpb_session', sessionId);
}

// Track input source
let lastInputSource = 'web';
```

Update the speech recognition `onresult` handler to set source:

```javascript
recognition.onresult = (event) => {
    let transcript = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
        transcript += event.results[i][0].transcript;
    }
    textInput.value = transcript;
    lastInputSource = 'voice';
};
```

Add a listener on the textarea to reset source when typing:

```javascript
textInput.addEventListener('input', () => {
    lastInputSource = 'web';
});
```

Replace the submit handler fetch call:

```javascript
submitBtn.addEventListener('click', async () => {
    const content = textInput.value.trim();

    if (!content) {
        showStatus('Please enter a thought first', 'error');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                content: content,
                category: selectedCategory,
                source: lastInputSource,
                session_id: sessionId,
                parent_id: null,
                tags: null
            })
        });
        const data = await response.json();

        if (data.success) {
            showStatus('✓ ' + data.message, 'success');
            textInput.value = '';
            lastInputSource = 'web';
        } else {
            showStatus('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showStatus('Network error - try again', 'error');
    }

    submitBtn.disabled = false;
    submitBtn.textContent = 'Save Thought';
});
```

**Step 3: Verify in browser**

1. Open `http://localhost/talk/` in Chrome
2. Type a thought, tap Save — should see "Idea #N saved (raw)"
3. Use mic, dictate — should save with source "voice"
4. Check DB: new row should have `session_id` populated, `user_id` if logged in
5. Open a new tab, save another — should get a different `session_id`
6. Refresh same tab, save — should keep the same `session_id`

**Step 4: Commit**

```bash
git add talk/index.php
git commit -m "Update talk/index.php: POST JSON, session_id, expanded categories"
```

---

## Task 4: Frontend — Update history.php

**Files:**
- Modify: `talk/history.php`

**Context to read first:**
- Current `talk/history.php` — the page to modify
- The approved design Section 4 (attribution, status, threading, promote)

**Step 1: Rewrite the PHP query section**

Replace the PHP block at the top of history.php. The new query JOINs users, adds children_count subquery, supports status filter and session filter, and scopes to the current user by default.

```php
<?php
/**
 * Talk History — /talk/history.php
 * View, filter, and promote your ideas
 */

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';

$thoughts = [];
$error = null;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $dbUser = getUser($pdo);
    $currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

    // Filters
    $category = $_GET['category'] ?? 'all';
    $status   = $_GET['status']   ?? 'all';
    $session  = $_GET['session']  ?? '';
    $showAll  = isset($_GET['all']);

    $where = [];
    $params = [];

    // User-scoped by default (unless ?all or filtering by session)
    if ($currentUserId && !$showAll && !$session) {
        $where[] = 'i.user_id = :user_id';
        $params[':user_id'] = $currentUserId;
    }

    if ($category !== 'all') {
        $where[] = 'i.category = :category';
        $params[':category'] = $category;
    }

    if ($status !== 'all') {
        $where[] = 'i.status = :status';
        $params[':status'] = $status;
    }

    if ($session) {
        $where[] = 'i.session_id = :session_id';
        $params[':session_id'] = $session;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT i.*,
               u.first_name AS user_first_name,
               (SELECT COUNT(*) FROM idea_log c WHERE c.parent_id = i.id) AS children_count
        FROM idea_log i
        LEFT JOIN users u ON i.user_id = u.user_id
        {$whereClause}
        ORDER BY i.created_at DESC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $thoughts = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error';
}

$icons = [
    'idea' => '💡', 'decision' => '✅', 'todo' => '📋', 'note' => '📝',
    'question' => '❓', 'reaction' => '↩️', 'distilled' => '✨', 'digest' => '📊'
];

$statusColors = [
    'raw' => '#888', 'refining' => '#4fc3f7', 'distilled' => '#4caf50',
    'actionable' => '#ffd700', 'archived' => '#666'
];

$statusOrder = ['raw' => 'refining', 'refining' => 'distilled', 'distilled' => 'actionable'];
?>
```

**Step 2: Update the HTML body**

Replace the entire HTML section with the updated version that includes status filters, status badges, thread indicators, user attribution, and promote buttons.

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <title>Talk History</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
            color: #eee;
        }

        .container { max-width: 700px; margin: 0 auto; }

        header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;
        }

        h1 { font-size: 1.3rem; color: #4fc3f7; }

        .header-links { display: flex; gap: 1rem; font-size: 0.9rem; }
        .header-links a { color: #4fc3f7; text-decoration: none; }

        .filters, .status-filters {
            display: flex; gap: 8px; margin-bottom: 0.75rem; flex-wrap: wrap;
        }

        .filter-btn {
            padding: 6px 14px; border: 2px solid #333; border-radius: 20px;
            background: transparent; color: #888; font-size: 0.8rem;
            cursor: pointer; text-decoration: none; transition: all 0.3s;
        }
        .filter-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }
        .filter-btn.active { background: #4fc3f7; border-color: #4fc3f7; color: #1a1a2e; }

        .thought {
            background: rgba(255,255,255,0.05); border-radius: 12px;
            padding: 15px; margin-bottom: 12px; border-left: 4px solid #4fc3f7;
        }
        .thought.decision { border-left-color: #4caf50; }
        .thought.todo { border-left-color: #ff9800; }
        .thought.note { border-left-color: #9c27b0; }
        .thought.question { border-left-color: #e91e63; }
        .thought.reaction { border-left-color: #00bcd4; }

        .thought-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px; font-size: 0.8rem; color: #888; flex-wrap: wrap; gap: 4px;
        }

        .thought-meta { display: flex; align-items: center; gap: 8px; }

        .status-badge {
            padding: 2px 8px; border-radius: 10px; font-size: 0.7rem;
            font-weight: 600; text-transform: uppercase;
        }

        .thought-content { font-size: 1rem; line-height: 1.5; }

        .thought-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 8px; font-size: 0.75rem; color: #666;
        }

        .thread-info a { color: #4fc3f7; text-decoration: none; }
        .thread-info a:hover { text-decoration: underline; }

        .promote-btn {
            background: none; border: 1px solid #555; color: #888;
            padding: 2px 8px; border-radius: 6px; font-size: 0.75rem;
            cursor: pointer; transition: all 0.3s;
        }
        .promote-btn:hover { border-color: #4fc3f7; color: #4fc3f7; }

        .user-name { color: #4fc3f7; font-weight: 600; }

        .empty { text-align: center; padding: 3rem; color: #666; }
        .error { background: rgba(244,67,54,0.2); color: #e57373; padding: 15px; border-radius: 8px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>📚 Thought History</h1>
            <div class="header-links">
                <?php if ($currentUserId && !$showAll): ?>
                    <a href="?all&category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">Show all</a>
                <?php elseif ($currentUserId && $showAll): ?>
                    <a href="?category=<?= urlencode($category) ?>&status=<?= urlencode($status) ?>">My ideas</a>
                <?php endif; ?>
                <a href="index.php">← New thought</a>
            </div>
        </header>

        <!-- Category filters -->
        <div class="filters">
            <?php
            $catFilters = ['all' => 'All', 'idea' => '💡 Ideas', 'decision' => '✅ Decisions', 'todo' => '📋 Todos', 'note' => '📝 Notes', 'question' => '❓ Questions'];
            foreach ($catFilters as $val => $label):
                $active = ($category === $val) ? 'active' : '';
                $extra = ($status !== 'all') ? '&status=' . urlencode($status) : '';
                $extra .= $showAll ? '&all' : '';
                $extra .= $session ? '&session=' . urlencode($session) : '';
            ?>
                <a href="?category=<?= $val ?><?= $extra ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Status filters -->
        <div class="status-filters">
            <?php
            $statFilters = ['all' => 'All Status', 'raw' => 'Raw', 'refining' => 'Refining', 'distilled' => 'Distilled', 'actionable' => 'Actionable'];
            foreach ($statFilters as $val => $label):
                $active = ($status === $val) ? 'active' : '';
                $extra = ($category !== 'all') ? '&category=' . urlencode($category) : '';
                $extra .= $showAll ? '&all' : '';
                $extra .= $session ? '&session=' . urlencode($session) : '';
            ?>
                <a href="?status=<?= $val ?><?= $extra ?>" class="filter-btn <?= $active ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($thoughts)): ?>
            <div class="empty">
                <p>No thoughts yet.</p>
                <p><a href="index.php" style="color: #4fc3f7;">Add your first one →</a></p>
            </div>
        <?php else: ?>
            <?php foreach ($thoughts as $t):
                $isOwner = $currentUserId && (int)($t['user_id'] ?? 0) === $currentUserId;
                $displayName = $isOwner ? 'You' : ($t['user_first_name'] ?? 'Anonymous');
                $statusColor = $statusColors[$t['status'] ?? 'raw'] ?? '#888';
                $nextStatus = $statusOrder[$t['status'] ?? 'raw'] ?? null;
                $childCount = (int)($t['children_count'] ?? 0);
            ?>
                <div class="thought <?= htmlspecialchars($t['category'] ?? 'idea') ?>" data-id="<?= (int)$t['id'] ?>">
                    <div class="thought-header">
                        <div class="thought-meta">
                            <span class="thought-category">
                                <?= $icons[$t['category'] ?? 'idea'] ?? '💭' ?>
                                <span class="user-name"><?= htmlspecialchars($displayName) ?></span>
                            </span>
                            <span class="status-badge" style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>;">
                                <?= htmlspecialchars($t['status'] ?? 'raw') ?>
                            </span>
                        </div>
                        <span><?= date('M j, g:ia', strtotime($t['created_at'])) ?></span>
                    </div>
                    <div class="thought-content">
                        <?= nl2br(htmlspecialchars($t['content'])) ?>
                    </div>
                    <div class="thought-footer">
                        <div class="thread-info">
                            <?php if ($t['parent_id']): ?>
                                <span>builds on <a href="?session=<?= urlencode($t['session_id'] ?? '') ?>#idea-<?= (int)$t['parent_id'] ?>">#<?= (int)$t['parent_id'] ?></a></span>
                            <?php endif; ?>
                            <?php if ($childCount > 0): ?>
                                <span><?= $childCount ?> build<?= $childCount > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <span>via <?= htmlspecialchars($t['source'] ?? 'web') ?></span>
                        </div>
                        <div>
                            <?php if ($isOwner && $nextStatus): ?>
                                <button class="promote-btn" onclick="promote(<?= (int)$t['id'] ?>, '<?= $nextStatus ?>')">
                                    ⬆ <?= $nextStatus ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
    async function promote(ideaId, newStatus) {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '...';

        try {
            const response = await fetch('api.php?action=promote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, status: newStatus })
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
                btn.disabled = false;
                btn.textContent = '⬆ ' + newStatus;
            }
        } catch (err) {
            alert('Network error');
            btn.disabled = false;
            btn.textContent = '⬆ ' + newStatus;
        }
    }
    </script>
</body>
</html>
```

**Step 3: Verify in browser**

1. Open `http://localhost/talk/history.php`
2. Should see recent ideas with status badges (all "raw" for existing)
3. If logged in, should see only your ideas with "Show all" toggle
4. Category and status filters should work
5. Promote button should appear on your ideas — tap it, should advance to "refining"
6. "builds on #X" should appear for ideas with parent_id

**Step 4: Commit**

```bash
git add talk/history.php
git commit -m "Update talk/history.php: attribution, status badges, threading, promote"
```

---

## Task 5: End-to-End Verification

**Files:** None (testing only)

**Step 1: Full capture flow**

1. Open `http://localhost/talk/` in Chrome (logged in)
2. Type "test idea for phase 1 verification", category = Idea, tap Save
3. Should see: "Idea #N saved (raw)"
4. Open `http://localhost/talk/history.php`
5. Should see the idea with: your name, "raw" badge, no thread links
6. Tap ⬆ refining on the idea
7. Badge should change to "refining" (blue)

**Step 2: Session grouping**

1. Save 2 more ideas in the same tab (same session)
2. In history, all 3 should share the same session_id
3. Copy the session_id from the URL or DB
4. Test `?session=UUID` filter — should show only those 3

**Step 3: Threading**

1. Note the ID of your first idea (e.g., #N)
2. In the DB (phpMyAdmin), manually set parent_id on your second idea to point to the first
3. Refresh history — second idea should show "builds on #N"
4. First idea should show "1 build"

**Step 4: Backward compatibility**

Test old GET-based save still works:

```
curl "http://localhost/talk/api.php?content=legacy+test&category=note&source=api"
```

Should return success with null session_id and null user_id.

**Step 5: API history endpoint**

```
curl "http://localhost/talk/api.php?action=history&limit=3&include_ai=1"
```

Should return JSON with ideas array.

**Step 6: Clean up test data**

Delete test rows from idea_log via phpMyAdmin.

**Step 7: Commit any fixes**

If any issues were found and fixed during verification:

```bash
git add -A
git commit -m "Fix issues found during Phase 1 end-to-end verification"
```

---

## Task 6: Push to Staging

**Step 1: Push commits to origin**

```bash
git push origin master
```

**Step 2: Pull on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 3: Verify on staging**

1. Open `https://tpb2.sandgems.net/talk/` — save a thought
2. Open `https://tpb2.sandgems.net/talk/history.php` — verify it appears with status and attribution
3. Test promote button

**Step 4: Verify DB migration ran on staging**

If the migration wasn't run in Task 1 Step 3, run it now. Otherwise verify:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('DESCRIBE idea_log');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo \$row['Field'].' | '.\$row['Type'].' | '.\$row['Null'].' | '.\$row['Default'].PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: All 7 new columns visible.
