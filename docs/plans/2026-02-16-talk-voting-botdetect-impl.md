# Talk: Agree/Disagree Voting + Bot Detection — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add agree/disagree voting and bot detection to /talk, plus a Bot tab in admin.php.

**Architecture:** New `idea_votes` table with cached counts on `idea_log`. Existing `api/bot-detect.php` integrated into talk save. New Bot tab in admin.php queries `bot_attempts` table.

**Tech Stack:** PHP 8.4, MySQL, vanilla JS (no framework)

**Design doc:** `docs/plans/2026-02-16-talk-voting-botdetect-design.md`

---

### Task 1: Database Migration — idea_votes table + idea_log columns

**Files:**
- No code files — run SQL on staging server

**Step 1: Create idea_votes table and add columns to idea_log**

Run via SSH:
```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec(\"
    CREATE TABLE IF NOT EXISTS idea_votes (
        vote_id INT AUTO_INCREMENT PRIMARY KEY,
        idea_id INT NOT NULL,
        user_id INT NOT NULL,
        vote_type ENUM('agree','disagree') NOT NULL,
        voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_vote (idea_id, user_id),
        FOREIGN KEY (idea_id) REFERENCES idea_log(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
\");
echo 'idea_votes created'.PHP_EOL;
\$p->exec(\"ALTER TABLE idea_log ADD COLUMN agree_count INT DEFAULT 0, ADD COLUMN disagree_count INT DEFAULT 0\");
echo 'idea_log columns added'.PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: `idea_votes created` and `idea_log columns added`

**Step 2: Verify schema**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('DESCRIBE idea_votes');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
echo '---'.PHP_EOL;
\$r = \$p->query(\"SHOW COLUMNS FROM idea_log WHERE Field IN ('agree_count','disagree_count')\");
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: Both tables show correct columns.

**Step 3: Commit**

No code changes in this task — DB only.

---

### Task 2: Vote API — handleVote() in talk/api.php

**Files:**
- Modify: `talk/api.php` — add case + handler function

**Step 1: Add vote case to switch block**

In `talk/api.php`, after line 148 (`case 'check_staleness':` block), add a new case before the Phase 5 section:

```php
        case 'vote':
            echo json_encode(handleVote($pdo, $input, $userId));
            break;
```

**Step 2: Add handleVote function**

Add after the `handleDelete()` function (find the exact location by searching for `// Phase 3: Idea Links`). Place the new function before that comment:

```php
// ── Vote ──────────────────────────────────────────────────────────

function handleVote($pdo, $input, $userId) {
    if (!$userId) {
        return ['success' => false, 'error' => 'Log in to vote'];
    }

    $ideaId = (int)($input['idea_id'] ?? 0);
    $voteType = $input['vote_type'] ?? '';

    if (!$ideaId) {
        return ['success' => false, 'error' => 'idea_id is required'];
    }
    if (!in_array($voteType, ['agree', 'disagree'])) {
        return ['success' => false, 'error' => 'vote_type must be "agree" or "disagree"'];
    }

    // Verify idea exists and is votable (not AI-generated, not digest/crystal)
    $stmt = $pdo->prepare("SELECT id, clerk_key, category FROM idea_log WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$ideaId]);
    $idea = $stmt->fetch();
    if (!$idea) {
        return ['success' => false, 'error' => 'Idea not found'];
    }
    if ($idea['clerk_key']) {
        return ['success' => false, 'error' => 'Cannot vote on AI-generated content'];
    }
    if ($idea['category'] === 'digest') {
        return ['success' => false, 'error' => 'Cannot vote on digests'];
    }

    // Check existing vote
    $stmt = $pdo->prepare("SELECT vote_id, vote_type FROM idea_votes WHERE idea_id = ? AND user_id = ?");
    $stmt->execute([$ideaId, $userId]);
    $existing = $stmt->fetch();

    $userVote = null;

    if ($existing) {
        if ($existing['vote_type'] === $voteType) {
            // Same vote — toggle off
            $pdo->prepare("DELETE FROM idea_votes WHERE vote_id = ?")->execute([$existing['vote_id']]);
            $col = $voteType === 'agree' ? 'agree_count' : 'disagree_count';
            $pdo->prepare("UPDATE idea_log SET {$col} = GREATEST({$col} - 1, 0) WHERE id = ?")->execute([$ideaId]);
            $userVote = null;
        } else {
            // Different vote — switch
            $pdo->prepare("UPDATE idea_votes SET vote_type = ?, voted_at = NOW() WHERE vote_id = ?")->execute([$voteType, $existing['vote_id']]);
            if ($voteType === 'agree') {
                $pdo->prepare("UPDATE idea_log SET agree_count = agree_count + 1, disagree_count = GREATEST(disagree_count - 1, 0) WHERE id = ?")->execute([$ideaId]);
            } else {
                $pdo->prepare("UPDATE idea_log SET disagree_count = disagree_count + 1, agree_count = GREATEST(agree_count - 1, 0) WHERE id = ?")->execute([$ideaId]);
            }
            $userVote = $voteType;
        }
    } else {
        // New vote
        $pdo->prepare("INSERT INTO idea_votes (idea_id, user_id, vote_type) VALUES (?, ?, ?)")->execute([$ideaId, $userId, $voteType]);
        $col = $voteType === 'agree' ? 'agree_count' : 'disagree_count';
        $pdo->prepare("UPDATE idea_log SET {$col} = {$col} + 1 WHERE id = ?")->execute([$ideaId]);
        $userVote = $voteType;
    }

    // Return updated counts
    $stmt = $pdo->prepare("SELECT agree_count, disagree_count FROM idea_log WHERE id = ?");
    $stmt->execute([$ideaId]);
    $counts = $stmt->fetch();

    return [
        'success' => true,
        'idea_id' => $ideaId,
        'user_vote' => $userVote,
        'agree_count' => (int)$counts['agree_count'],
        'disagree_count' => (int)$counts['disagree_count']
    ];
}
```

**Step 3: Verify by testing manually**

Open browser, log in to /talk on staging, then in browser console:
```javascript
fetch('/talk/api.php?action=vote', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({idea_id: 1, vote_type: 'agree'})
}).then(r => r.json()).then(console.log);
```

Expected: `{success: true, idea_id: 1, user_vote: "agree", agree_count: 1, disagree_count: 0}`

Run same command again — should toggle off: `{success: true, idea_id: 1, user_vote: null, agree_count: 0, disagree_count: 0}`

**Step 4: Commit**

```bash
git add talk/api.php
git commit -m "feat(talk): add agree/disagree vote API with toggle behavior"
```

---

### Task 3: History API — include vote counts and user_vote

**Files:**
- Modify: `talk/api.php` — update handleHistory()

**Step 1: Add agree_count, disagree_count to the SELECT in handleHistory**

In `talk/api.php` `handleHistory()`, find the SQL query (around line 394). Change:

```php
               i.created_at, i.updated_at,
```

to:

```php
               i.agree_count, i.disagree_count,
               i.created_at, i.updated_at,
```

**Step 2: Add user_vote lookup**

After the `foreach ($ideas as &$idea)` loop that computes `author_display` (around line 414-424), add inside the same loop (before the `unset` of name fields):

```php
        // Lookup user's vote on this idea
        $idea['user_vote'] = null;
        if ($userId && !$idea['clerk_key'] && $idea['category'] !== 'digest') {
            $vStmt = $pdo->prepare("SELECT vote_type FROM idea_votes WHERE idea_id = ? AND user_id = ?");
            $vStmt->execute([$idea['id'], $userId]);
            $uv = $vStmt->fetch();
            if ($uv) $idea['user_vote'] = $uv['vote_type'];
        }
```

**Step 3: Also add counts to the save response**

In `handleSave()`, find the return array `'idea' => [...]` (around line 297-312). Add two fields:

```php
            'agree_count'    => 0,
            'disagree_count' => 0,
```

**Step 4: Verify**

Fetch history in browser console:
```javascript
fetch('/talk/api.php?action=history&limit=5').then(r=>r.json()).then(d=>console.log(d.ideas[0]));
```

Expected: response includes `agree_count`, `disagree_count`, `user_vote` fields.

**Step 5: Commit**

```bash
git add talk/api.php
git commit -m "feat(talk): include vote counts and user_vote in history API"
```

---

### Task 4: Frontend — vote buttons on idea cards

**Files:**
- Modify: `talk/index.php` — update renderIdeaCard() and add voteIdea()

**Step 1: Add vote button CSS**

In the `<style>` section of `talk/index.php`, before the `/* ── Footer Bar ── */` comment (around line 335), add:

```css
        .vote-btn {
            background: none;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 6px;
            padding: 2px 8px;
            cursor: pointer;
            font-size: 0.8rem;
            color: #888;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .vote-btn:hover { border-color: rgba(255,255,255,0.25); color: #ccc; }
        .vote-btn.active-agree { border-color: #4caf50; color: #4caf50; background: rgba(76,175,80,0.1); }
        .vote-btn.active-disagree { border-color: #ef5350; color: #ef5350; background: rgba(239,83,80,0.1); }
        .vote-btn .count { font-size: 0.75rem; }
```

**Step 2: Add vote buttons to renderIdeaCard()**

In the `renderIdeaCard()` function, find where `actionsHtml` is built (around line 643-656). Before the `actionsHtml` variable declaration, add vote buttons HTML:

```javascript
        var voteHtml = '';
        if (!idea.clerk_key && idea.category !== 'digest') {
            var agreeActive = idea.user_vote === 'agree' ? ' active-agree' : '';
            var disagreeActive = idea.user_vote === 'disagree' ? ' active-disagree' : '';
            voteHtml = '<button class="vote-btn' + agreeActive + '" onclick="voteIdea(' + idea.id + ',\'agree\')" title="Agree">👍 <span class="count" id="agree-' + idea.id + '">' + (idea.agree_count || 0) + '</span></button>' +
                       '<button class="vote-btn' + disagreeActive + '" onclick="voteIdea(' + idea.id + ',\'disagree\')" title="Disagree">👎 <span class="count" id="disagree-' + idea.id + '">' + (idea.disagree_count || 0) + '</span></button>';
        }
```

Then in the footer HTML construction (around line 658), change:

```javascript
        var footer = '<div class="card-footer"><div class="card-tags">' + tagsHtml + '</div><div class="card-actions">' + actionsHtml + '</div></div>';
```

to:

```javascript
        var footer = '<div class="card-footer"><div class="card-tags">' + tagsHtml + '</div><div class="card-actions">' + voteHtml + actionsHtml + '</div></div>';
```

**Step 3: Add voteIdea() function**

After the `promote()` function (around line 886), add:

```javascript
    // ── Vote ──
    async function voteIdea(ideaId, voteType) {
        if (!currentUser) {
            showToast('Log in to vote', 'error');
            return;
        }
        try {
            var resp = await fetch('api.php?action=vote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, vote_type: voteType })
            });
            var data = await resp.json();
            if (data.success) {
                // Update the idea in loadedIdeas
                var idea = loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea) {
                    idea.agree_count = data.agree_count;
                    idea.disagree_count = data.disagree_count;
                    idea.user_vote = data.user_vote;
                    // Re-render card in place
                    var oldCard = document.getElementById('idea-' + ideaId);
                    if (oldCard) {
                        var newCard = renderIdeaCard(idea);
                        oldCard.replaceWith(newCard);
                    }
                }
            } else {
                showToast(data.error || 'Vote failed', 'error');
            }
        } catch (err) {
            showToast('Network error', 'error');
        }
    }
```

**Step 4: Verify on staging**

Deploy to staging, open /talk, submit an idea, click 👍 — count should go to 1 and button highlights green. Click again — toggles off. Click 👎 — highlights red.

**Step 5: Commit**

```bash
git add talk/index.php
git commit -m "feat(talk): add agree/disagree vote buttons on idea cards"
```

---

### Task 5: Bot Detection — integrate into talk save

**Files:**
- Modify: `talk/api.php` — add bot check to handleSave()
- Modify: `talk/index.php` — add honeypot + timestamp fields

**Step 1: Add honeypot and timestamp to talk/index.php**

In the `<div class="input-area">` section (around line 402), add the honeypot field just inside the div, before the anon-nudge:

```html
        <div style="position:absolute;left:-9999px;"><input type="text" id="talkHoneypot" tabindex="-1" autocomplete="off"></div>
```

In the `<script>` section, after the session ID setup (around line 440), add:

```javascript
    var formLoadTime = Math.floor(Date.now() / 1000);
```

In the `submitIdea()` function, find the `JSON.stringify` body (around line 510-516). Add the two bot detection fields:

```javascript
                    website_url: document.getElementById('talkHoneypot').value,
                    _form_load_time: formLoadTime
```

**Step 2: Add bot check to handleSave() in talk/api.php**

At the top of `handleSave()` (line 171), after the function signature, before `$content = trim(...)`:

```php
    // Bot detection
    require_once __DIR__ . '/../api/bot-detect.php';
    $formLoadTime = isset($input['_form_load_time']) ? (int)$input['_form_load_time'] : null;
    $botCheck = checkForBot($pdo, 'talk_save', $input, $formLoadTime);
    if ($botCheck['is_bot']) {
        return ['success' => true, 'id' => 0, 'message' => 'Saved'];
    }
```

**Step 3: Verify**

Deploy to staging. Open /talk, submit an idea normally — should work. Then test bot detection by modifying the honeypot in browser console:

```javascript
document.getElementById('talkHoneypot').value = 'gotcha';
```

Then submit — should silently succeed (return success) but NOT create a row in idea_log. Verify in `bot_attempts` table that a row was logged with `form_name = 'talk_save'`.

**Step 4: Commit**

```bash
git add talk/api.php talk/index.php
git commit -m "feat(talk): integrate bot detection into idea submission"
```

---

### Task 6: Admin Bot Tab — summary cards + attempts table + top offenders

**Files:**
- Modify: `admin.php` — add Bot tab nav link, bot stats query, bot tab content

**Step 1: Add bot stats query**

In `admin.php`, find the stats queries section (before `$tab = $_GET['tab'] ?? 'dashboard';` at line 348). Add bot stats queries:

```php
// Bot stats
$botStats = [];
try {
    $botStats['attempts_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $botStats['attempts_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
    $botStats['unique_ips_24h'] = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM bot_attempts WHERE created_at > NOW() - INTERVAL 24 HOUR")->fetchColumn();
    $topFormStmt = $pdo->query("SELECT form_name, COUNT(*) as cnt FROM bot_attempts WHERE created_at > NOW() - INTERVAL 7 DAY GROUP BY form_name ORDER BY cnt DESC LIMIT 1");
    $topForm = $topFormStmt->fetch();
    $botStats['top_form'] = $topForm ? $topForm['form_name'] . ' (' . $topForm['cnt'] . ')' : 'none';
} catch (PDOException $e) {
    $botStats = ['attempts_24h' => 0, 'attempts_7d' => 0, 'unique_ips_24h' => 0, 'top_form' => 'n/a'];
}

// Bot attempts (recent 100)
$botAttempts = [];
try {
    $botAttempts = $pdo->query("
        SELECT ip_address, form_name, honeypot_filled, too_fast, missing_referrer,
               user_agent, created_at
        FROM bot_attempts
        ORDER BY created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Top offender IPs (3+ attempts in 7 days)
$botOffenders = [];
try {
    $botOffenders = $pdo->query("
        SELECT ip_address, COUNT(*) as attempt_count,
               MAX(created_at) as last_seen,
               GROUP_CONCAT(DISTINCT form_name) as forms_targeted
        FROM bot_attempts
        WHERE created_at > NOW() - INTERVAL 7 DAY
        GROUP BY ip_address
        HAVING attempt_count >= 3
        ORDER BY attempt_count DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
```

**Step 2: Add Bot tab to nav**

Find the nav section (around line 770-775). After the Activity tab link, add:

```php
        <a href="?tab=bot" class="<?= $tab === 'bot' ? 'active' : '' ?>">Bot <?= $botStats['attempts_24h'] > 0 ? '<span style="background:#ef5350;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.8em;margin-left:5px;">'.$botStats['attempts_24h'].'</span>' : '' ?></a>
```

**Step 3: Add Bot tab content**

Find the activity tab closing (around line 1188, before `<?php elseif ($tab === 'help'): ?>`). Add before the help tab:

```php
        <?php elseif ($tab === 'bot'): ?>
            <!-- BOT TAB -->
            <h2 class="section-title">Bot Detection</h2>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= $botStats['attempts_24h'] ?></div>
                    <div class="label">Attempts (24h)</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $botStats['attempts_7d'] ?></div>
                    <div class="label">Attempts (7d)</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $botStats['unique_ips_24h'] ?></div>
                    <div class="label">Unique IPs (24h)</div>
                </div>
                <div class="stat-card">
                    <div class="number" style="font-size:1.2em;"><?= htmlspecialchars($botStats['top_form']) ?></div>
                    <div class="label">Top Form (7d)</div>
                </div>
            </div>

            <?php if ($botOffenders): ?>
            <h2 class="section-title">Top Offenders (7d)</h2>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Attempts</th>
                        <th>Last Seen</th>
                        <th>Forms Targeted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($botOffenders as $offender): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($offender['ip_address']) ?></code></td>
                            <td style="color: <?= $offender['attempt_count'] >= 10 ? '#ef5350' : '#ff9800' ?>; font-weight: bold;"><?= $offender['attempt_count'] ?></td>
                            <td><?= date('M j, g:i a', strtotime($offender['last_seen'])) ?></td>
                            <td><?= htmlspecialchars($offender['forms_targeted']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

            <h2 class="section-title">Recent Attempts</h2>
            <?php if (empty($botAttempts)): ?>
                <p style="color: #888;">No bot attempts recorded.</p>
            <?php else: ?>
            <div class="table-wrap"><table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>IP</th>
                        <th>Form</th>
                        <th>Triggers</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($botAttempts as $attempt):
                        $triggers = [];
                        if ($attempt['honeypot_filled']) $triggers[] = 'honeypot';
                        if ($attempt['too_fast']) $triggers[] = 'too_fast';
                        if ($attempt['missing_referrer']) $triggers[] = 'no_referrer';
                    ?>
                        <tr>
                            <td><?= date('M j, g:i a', strtotime($attempt['created_at'])) ?></td>
                            <td><code><?= htmlspecialchars($attempt['ip_address']) ?></code></td>
                            <td><?= htmlspecialchars($attempt['form_name']) ?></td>
                            <td>
                                <?php foreach ($triggers as $t): ?>
                                    <span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:0.75em;margin:1px;background:<?= $t === 'honeypot' ? 'rgba(239,83,80,0.2);color:#ef5350' : ($t === 'too_fast' ? 'rgba(255,152,0,0.2);color:#ff9800' : 'rgba(158,158,158,0.2);color:#999') ?>;"><?= $t ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($attempt['user_agent']) ?>"><?= htmlspecialchars(substr($attempt['user_agent'], 0, 60)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

```

**Step 4: Verify on staging**

Deploy to staging, open admin.php, verify Bot tab appears in nav. Click it — should show summary cards (likely all zeros) and "No bot attempts recorded" if clean.

**Step 5: Commit**

```bash
git add admin.php
git commit -m "feat(admin): add Bot tab with stats, offenders, and attempts table"
```

---

### Task 7: Deploy and verify on staging

**Files:**
- No code changes — deploy + test

**Step 1: Push to git and pull on staging**

```bash
git push origin master
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 2: Clear OPcache**

```bash
scp -P 2222 /dev/stdin sandge5@ecngx308.inmotionhosting.com:/home/sandge5/tpb2.sandgems.net/opcache-clear.php <<< '<?php opcache_reset(); echo "cleared";'
curl -s https://tpb2.sandgems.net/opcache-clear.php
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "rm /home/sandge5/tpb2.sandgems.net/opcache-clear.php"
```

**Step 3: Test voting end-to-end**

1. Open https://tpb2.sandgems.net/talk/ — log in
2. Submit an idea
3. Click 👍 — should highlight green, count = 1
4. Click 👍 again — should toggle off, count = 0
5. Click 👎 — should highlight red, count = 1
6. Verify AI cards and digests do NOT show vote buttons

**Step 4: Test bot detection**

1. Open browser console on /talk
2. Set honeypot: `document.getElementById('talkHoneypot').value = 'test'`
3. Submit an idea — should appear to succeed
4. Check admin.php Bot tab — should show 1 attempt with trigger "honeypot" and form "talk_save"

**Step 5: Test admin Bot tab**

1. Open https://tpb2.sandgems.net/admin.php?tab=bot
2. Verify summary cards render
3. Verify Recent Attempts table shows the test bot attempt
4. If 3+ from same IP, verify Top Offenders table

---

### Task 8: Update admin-guide.md with Bot tab documentation

**Files:**
- Modify: `docs/admin-guide.md` — add Bot tab section

**Step 1: Add Bot tab section**

After the Activity Tab section (before Security Features), add:

```markdown
## Bot Tab

Shows bot detection activity across all forms (thought submission and /talk).

### Summary Cards
| Stat | What it means |
|------|---------------|
| **Attempts (24h)** | Bot attempts detected in the last 24 hours |
| **Attempts (7d)** | Bot attempts detected in the last 7 days |
| **Unique IPs (24h)** | Number of distinct IP addresses flagged |
| **Top Form (7d)** | Which form is most targeted by bots |

### Top Offenders
IPs with 3 or more attempts in the last 7 days. Shows attempt count, last seen time, and which forms were targeted.

### Recent Attempts
Last 100 bot attempts with:
- **Time** — when the attempt occurred
- **IP** — source IP address
- **Form** — which form was targeted (submit_thought or talk_save)
- **Triggers** — what detection fired: honeypot (filled hidden field), too_fast (submitted in under 3 seconds), no_referrer (missing HTTP referrer)
- **User Agent** — browser string (truncated, hover for full)

Bot detection does not block IPs — it silently rejects submissions and logs the attempt. IP blocking is handled at the server level via .htaccess if needed.
```

**Step 2: Commit**

```bash
git add docs/admin-guide.md
git commit -m "docs: add Bot tab to admin guide"
```
