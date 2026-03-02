# Invite System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a referral invite system where TPB members invite friends via email, earn 100 Civic Points when friends join, with full tracking and a guided onboarding landing page.

**Architecture:** Six new files (3 pages, 2 API endpoints, 1 shared include) plus one nav modification. The invite email HTML builder is extracted to `includes/invite-email.php` so both the send API and the invitor page preview can render it. The accept flow creates accounts following the same pattern as `api/send-magic-link.php`. Points awarded via existing `PointLogger::award()`.

**Tech Stack:** PHP 8.4, MySQL, existing `sendSmtpMail()`, existing `PointLogger`, existing `getUser()` auth.

**Design doc:** `docs/plans/2026-03-02-invite-system-design.md`
**Approved mockups:** `tmp/send-mockup.php`, `tmp/mockup-invite-landing.html`, `tmp/mockup-invite-page.html`

---

### Task 1: Database — Create `invitations` table and point action

**Files:**
- Create: `scripts/db/create-invitations-table.sql`

**Step 1: Write the SQL migration script**

```sql
-- Invite system: referral tracking
CREATE TABLE IF NOT EXISTS invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invitor_user_id INT NOT NULL,
    invitee_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('sent','joined') DEFAULT 'sent',
    invitee_user_id INT NULL,
    points_awarded TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    FOREIGN KEY (invitor_user_id) REFERENCES users(user_id),
    FOREIGN KEY (invitee_user_id) REFERENCES users(user_id),
    INDEX idx_token (token),
    INDEX idx_invitor (invitor_user_id),
    INDEX idx_invitee_email (invitee_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Point action for referral (100 pts, no cooldown, no daily limit)
INSERT IGNORE INTO point_actions (action_name, points_value, cooldown_hours, daily_limit, is_active)
VALUES ('referral_joined', 100, 0, NULL, 1);
```

**Step 2: Run the migration on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec(\"CREATE TABLE IF NOT EXISTS invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invitor_user_id INT NOT NULL,
    invitee_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('sent','joined') DEFAULT 'sent',
    invitee_user_id INT NULL,
    points_awarded TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    FOREIGN KEY (invitor_user_id) REFERENCES users(user_id),
    FOREIGN KEY (invitee_user_id) REFERENCES users(user_id),
    INDEX idx_token (token),
    INDEX idx_invitor (invitor_user_id),
    INDEX idx_invitee_email (invitee_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\");
echo 'Table created.' . PHP_EOL;
\$p->exec(\"INSERT IGNORE INTO point_actions (action_name, points_value, cooldown_hours, daily_limit, is_active) VALUES ('referral_joined', 100, 0, NULL, 1)\");
echo 'Point action added.' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: "Table created." and "Point action added."

**Step 3: Verify**

Query `SHOW COLUMNS FROM invitations` and `SELECT * FROM point_actions WHERE action_name='referral_joined'` on staging.

**Step 4: Commit**

```bash
git add scripts/db/create-invitations-table.sql
git commit -m "feat(invite): add invitations table and referral_joined point action"
```

---

### Task 2: Email builder — `includes/invite-email.php`

**Files:**
- Create: `includes/invite-email.php`

**Step 1: Create the invite email builder function**

This function takes the invitor's email, the invitee's email, the accept URL, and the base URL, and returns the full HTML email string. The HTML is copied from the approved mockup `tmp/send-mockup.php` with placeholders replaced.

```php
<?php
/**
 * Build the HTML invite email body.
 *
 * @param string $invitorEmail  The inviting member's email
 * @param string $acceptUrl     Full URL with token: https://4tpb.org/invite/accept.php?token=xxx
 * @param string $baseUrl       Site base URL (e.g., https://4tpb.org)
 * @return string  Full HTML email body
 */
function buildInviteEmail($invitorEmail, $acceptUrl, $baseUrl) {
    $ie = htmlspecialchars($invitorEmail);
    $au = htmlspecialchars($acceptUrl);
    $bu = htmlspecialchars($baseUrl);

    return <<<HTML
    // ... (copy the full approved HTML from tmp/send-mockup.php lines 6-119,
    //      replacing "maria@gmail.com" with {$ie},
    //      replacing the CTA href with {$au},
    //      replacing "https://4tpb.org" with {$bu})
    HTML;
}
```

**Important:** Copy the EXACT HTML from `tmp/send-mockup.php` (the approved mockup). Replace these three strings:
- `maria@gmail.com` → `{$ie}` (6 occurrences)
- `https://4tpb.org/join.php?ref=SAMPLE_TOKEN` → `{$au}` (1 occurrence, the CTA button href)
- `https://4tpb.org/goldenrule.html` → `{$bu}/goldenrule.html` (1 occurrence)

Also create a second function for the invitor notification:

```php
/**
 * Build the "your friend joined" notification email for the invitor.
 */
function buildInvitorNotificationEmail($inviteeEmail, $pointsTotal, $baseUrl) {
    $ee = htmlspecialchars($inviteeEmail);
    $bu = htmlspecialchars($baseUrl);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
  <tr>
    <td style="background:#1a1a2e;padding:20px 24px;">
      <span style="color:#c8a415;font-size:20px;font-weight:bold;">The People&#8217;s Branch</span>
      <span style="color:#aaa;font-size:14px;float:right;padding-top:4px;">Referral Update</span>
    </td>
  </tr>
  <tr>
    <td style="padding:24px;">
      <h2 style="margin:0 0 12px;font-size:20px;color:#1a1a2e;">Your friend joined!</h2>
      <p style="margin:0 0 16px;font-size:15px;color:#444;line-height:1.6;">
        <strong style="color:#c8a415;">{$ee}</strong> accepted your invitation and is now a TPB member.
      </p>
      <div style="background:#faf6e8;border:1px solid #e8ddb5;border-radius:6px;padding:16px 20px;margin-bottom:16px;">
        <p style="margin:0;font-size:15px;color:#333;">
          <strong style="color:#c8a415;">+100 Civic Points</strong> have been added to your total.
          <br>Your new balance: <strong>{$pointsTotal} pts</strong>
        </p>
      </div>
      <p style="margin:0;font-size:14px;color:#666;">
        <a href="{$bu}/invite/" style="color:#c8a415;text-decoration:underline;">Invite more friends</a> to keep growing the movement.
      </p>
    </td>
  </tr>
  <tr>
    <td style="background:#f9f9f9;padding:12px 24px;border-top:1px solid #eee;">
      <p style="margin:0;font-size:12px;color:#999;"><strong>The People&#8217;s Branch</strong> &mdash; No Kings. Only Citizens.</p>
    </td>
  </tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
```

**Step 2: Commit**

```bash
git add includes/invite-email.php
git commit -m "feat(invite): add HTML email builder functions for invite and notification"
```

---

### Task 3: API — `api/send-invite.php`

**Files:**
- Create: `api/send-invite.php`

**Step 1: Create the send invite API**

Pattern to follow: Same auth/bootstrap as other `api/*.php` files.

```php
<?php
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/smtp-mail.php';
require_once dirname(__DIR__) . '/includes/invite-email.php';

$dbUser = getUser($pdo);
if (!$dbUser || $dbUser['identity_level_id'] < 2) {
    http_response_code(403);
    echo json_encode(['error' => 'Email verification required to send invitations.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$emails = $input['emails'] ?? [];

if (empty($emails) || !is_array($emails)) {
    http_response_code(400);
    echo json_encode(['error' => 'No emails provided.']);
    exit;
}

$invitorEmail = $dbUser['email'];
$invitorId = $dbUser['user_id'];
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');

// Prepare statements
$checkUser = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$insertInvite = $pdo->prepare("INSERT INTO invitations (invitor_user_id, invitee_email, token) VALUES (?, ?, ?)");

$results = [];
foreach ($emails as $email) {
    $email = trim(strtolower($email));

    // Validate format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $results[] = ['email' => $email, 'status' => 'invalid'];
        continue;
    }

    // Check if already a member
    $checkUser->execute([$email]);
    if ($checkUser->fetch()) {
        $results[] = ['email' => $email, 'status' => 'already_member'];
        continue;
    }

    // Generate token and insert
    $token = bin2hex(random_bytes(16));
    $insertInvite->execute([$invitorId, $email, $token]);

    // Build email
    $acceptUrl = "{$baseUrl}/invite/accept.php?token={$token}";
    $subject = "Your friend {$invitorEmail} invited you to The People's Branch";
    $body = buildInviteEmail($invitorEmail, $acceptUrl, $baseUrl);

    // Send to invitee
    $ok = sendSmtpMail($config, $email, $subject, $body, null, true);

    // Send copy to contacts@4tpb.org
    sendSmtpMail($config, 'contacts@4tpb.org', "[Invite Copy] {$subject}", $body, null, true);

    $results[] = ['email' => $email, 'status' => $ok ? 'sent' : 'failed'];

    usleep(1000000); // 1s throttle
}

echo json_encode(['results' => $results]);
```

**Step 2: Commit**

```bash
git add api/send-invite.php
git commit -m "feat(invite): add send-invite API with email validation and dedup"
```

---

### Task 4: API — `api/check-invite-email.php`

**Files:**
- Create: `api/check-invite-email.php`

**Step 1: Create the email check API**

```php
<?php
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

$email = trim(strtolower($_GET['email'] ?? ''));
if (!$email) {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$email]);
echo json_encode(['exists' => (bool)$stmt->fetch()]);
```

**Step 2: Commit**

```bash
git add api/check-invite-email.php
git commit -m "feat(invite): add check-invite-email API for real-time validation"
```

---

### Task 5: Accept page — `invite/accept.php`

**Files:**
- Create: `invite/accept.php`

**Step 1: Create the accept landing page**

This is the most complex file. It must:
1. Validate the token
2. Create the user account (following `send-magic-link.php` patterns)
3. Set up session/device
4. Award points to invitor
5. Send notification email to invitor
6. Render the landing page (from approved mockup `tmp/mockup-invite-landing.html`)

Key patterns to follow from `api/send-magic-link.php`:
- Username generation: `explode('@', $email)[0] . '_' . substr(md5($email), 0, 6)`
- User insert: `INSERT INTO users (username, email, identity_level_id, civic_points) VALUES (?, ?, 2, 0)` — note identity_level_id=2 because email is verified by accepting invite
- Device insert: `INSERT INTO user_devices (user_id, device_session) VALUES (?, ?)`
- Session cookie: `setcookie('tpb_civic_session', $sessionId, ...)`
- Points transfer: `PointLogger::transferSession($sessionId, $userId)`

The page flow:

```php
<?php
$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(...);

// 1. Validate token
$token = $_GET['token'] ?? '';
$stmt = $pdo->prepare("SELECT i.*, u.email AS invitor_email FROM invitations i JOIN users u ON i.invitor_user_id = u.user_id WHERE i.token = ? LIMIT 1");
$stmt->execute([$token]);
$invitation = $stmt->fetch();

if (!$invitation) { /* show error page */ }

// 2. If already joined (token reuse), redirect to login
if ($invitation['status'] === 'joined') { header('Location: /login.php'); exit; }

// 3. Check if invitee email already exists as active user
$existing = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$existing->execute([$invitation['invitee_email']]);
if ($existing->fetch()) { header('Location: /login.php'); exit; }

// 4. Create user account
$email = $invitation['invitee_email'];
$username = explode('@', $email)[0] . '_' . substr(md5($email), 0, 6);
$sessionId = 'civic_' . bin2hex(random_bytes(16));

$stmt = $pdo->prepare("INSERT INTO users (username, email, identity_level_id, civic_points) VALUES (?, ?, 2, 0)");
$stmt->execute([$username, $email]);
$newUserId = $pdo->lastInsertId();

// 5. Set up device/session
$stmt = $pdo->prepare("INSERT INTO user_devices (user_id, device_session) VALUES (?, ?)");
$stmt->execute([$newUserId, $sessionId]);
setcookie('tpb_civic_session', $sessionId, time() + 86400 * 365, '/', '', true, true);

// 6. Update invitation
$stmt = $pdo->prepare("UPDATE invitations SET status='joined', invitee_user_id=?, joined_at=NOW() WHERE id=?");
$stmt->execute([$newUserId, $invitation['id']]);

// 7. Award 100 pts to invitor
require_once dirname(__DIR__) . '/includes/point-logger.php';
PointLogger::init($pdo);
$pointResult = PointLogger::award($invitation['invitor_user_id'], 'referral_joined', 'referral', $invitation['id']);

// Mark points awarded
$pdo->prepare("UPDATE invitations SET points_awarded=1 WHERE id=?")->execute([$invitation['id']]);

// 8. Send notification email to invitor
require_once dirname(__DIR__) . '/includes/smtp-mail.php';
require_once dirname(__DIR__) . '/includes/invite-email.php';
$invitorPts = $pdo->query("SELECT civic_points FROM users WHERE user_id={$invitation['invitor_user_id']}")->fetchColumn();
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$notifBody = buildInvitorNotificationEmail($email, $invitorPts, $baseUrl);
sendSmtpMail($config, $invitation['invitor_email'], "Your friend {$email} just joined TPB! +100 Civic Points", $notifBody, null, true);

// 9. Transfer any session points + award joining points
PointLogger::transferSession($sessionId, $newUserId);

// 10. Set up nav vars and render landing page
require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
// ... (set all nav vars: $isLoggedIn, $userId, $points, etc.)
// ... render the landing page HTML from mockup tmp/mockup-invite-landing.html
//     replacing sample data with real: invitor_email, actual points earned
```

The landing page HTML comes from the approved mockup `tmp/mockup-invite-landing.html`. Strip the preview banner and simulated nav. Use the real site nav (`includes/header.php` + `includes/nav.php`). Replace hardcoded values with PHP variables:
- `maria@gmail.com` → `<?= htmlspecialchars($invitation['invitor_email']) ?>`
- `25 pts` → `<?= (int)$dbUser['civic_points'] ?> pts`
- `john@example.com` → `<?= htmlspecialchars($dbUser['email']) ?>`

**Step 2: Commit**

```bash
git add invite/accept.php
git commit -m "feat(invite): add accept page — account creation, points, guided onboarding"
```

---

### Task 6: Invitor's page — `invite/index.php`

**Files:**
- Create: `invite/index.php`

**Step 1: Create the invitor's invite page**

This page requires login (identity_level_id >= 2). It shows:
- The form (email input + add + list + send)
- Collapsible email preview (calls `buildInviteEmail()` with invitor's own email)
- History table (query `invitations` for this user)

Pattern to follow: Same auth bootstrap as `poll/index.php` or `volunteer/apply.php`.

```php
<?php
$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(...);
require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);

if (!$dbUser || $dbUser['identity_level_id'] < 2) {
    header('Location: /profile.php');
    exit;
}

$isLoggedIn = true;
// ... set all nav vars from $dbUser using getNavVarsForUser()

$currentPage = 'invite';
$pageTitle = 'Invite a Friend | The People\'s Branch';

// Load invite history
$historyStmt = $pdo->prepare("
    SELECT invitee_email, status, points_awarded, created_at, joined_at
    FROM invitations
    WHERE invitor_user_id = ?
    ORDER BY created_at DESC
");
$historyStmt->execute([$dbUser['user_id']]);
$history = $historyStmt->fetchAll();

// Stats
$totalPointsFromInvites = array_sum(array_map(fn($h) => $h['points_awarded'] ? 100 : 0, $history));
$joinedCount = count(array_filter($history, fn($h) => $h['status'] === 'joined'));

// Build email preview
require_once dirname(__DIR__) . '/includes/invite-email.php';
$baseUrl = rtrim($config['base_url'] ?? 'https://4tpb.org', '/');
$previewHtml = buildInviteEmail($dbUser['email'], '#', $baseUrl);

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/nav.php';
// ... render page HTML from approved mockup tmp/mockup-invite-page.html
// ... strip preview banner/simulated nav, use real nav
// ... the JS for add/remove emails and send button posts to /api/send-invite.php
require dirname(__DIR__) . '/includes/footer.php';
```

JavaScript for the form (inline in page):
- `addEmail()` — validates format, checks `/api/check-invite-email.php?email=X`, adds to list with Ready/Already a member badge
- `removeEmail(email)` — removes from list
- `sendInvitations()` — POSTs to `/api/send-invite.php` with `{emails: [...]}`, shows results, reloads history

**Step 2: Commit**

```bash
git add invite/index.php
git commit -m "feat(invite): add invitor page with form, preview, and history"
```

---

### Task 7: Nav integration — Add "Invite" to `includes/nav.php`

**Files:**
- Modify: `includes/nav.php:501` (after USA Talk link, inside `$isLoggedIn` block)

**Step 1: Add the Invite nav link**

In `includes/nav.php`, find this line (around line 501):

```php
<a href="<?= htmlspecialchars($talkUrl) ?>" <?= $currentPage === 'talk' ? 'class="active"' : '' ?>>USA Talk</a>
```

Add immediately BEFORE it (after the My TPB dropdown closing `</div>`):

```php
<a href="/invite/" <?= $currentPage === 'invite' ? 'class="active"' : '' ?>>Invite</a>
```

**Step 2: Verify** — Load any page while logged in, confirm "Invite" appears in nav between "My TPB" and "USA Talk".

**Step 3: Commit**

```bash
git add includes/nav.php
git commit -m "feat(invite): add Invite nav link after My TPB"
```

---

### Task 8: Create invite directory with .htaccess

**Files:**
- Create: `invite/.htaccess`

**Step 1: Ensure /invite/ routes to index.php**

```
# Invite system
DirectoryIndex index.php
```

**Step 2: Commit**

```bash
git add invite/.htaccess
git commit -m "feat(invite): add invite directory htaccess"
```

---

### Task 9: End-to-end test on staging

**Step 1: Push all code to staging**

```bash
git push origin master
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 2: Test the full flow**

1. Log in as a verified user on staging
2. Navigate to `/invite/` — confirm page loads, nav shows "Invite" active
3. Enter a test email address
4. Verify "Already a member" badge appears for existing user emails
5. Send an invitation — check that email arrives
6. Check contacts@4tpb.org received the copy
7. Click "Accept Invitation" in the email — confirm landing page renders
8. Verify new account was created (check admin dashboard)
9. Verify invitor received notification email with +100 pts
10. Verify invitor's history table shows "Joined!" status
11. Check invitor's nav points increased by 100

**Step 3: Fix any issues found during testing**

---

### Implementation Order Summary

| Task | What | Files | Depends on |
|------|------|-------|-----------|
| 1 | Database table + point action | `scripts/db/create-invitations-table.sql` | nothing |
| 2 | Email builder functions | `includes/invite-email.php` | nothing |
| 3 | Send invite API | `api/send-invite.php` | Tasks 1, 2 |
| 4 | Check email API | `api/check-invite-email.php` | nothing |
| 5 | Accept landing page | `invite/accept.php` | Tasks 1, 2 |
| 6 | Invitor's page | `invite/index.php` | Tasks 1, 2, 3, 4 |
| 7 | Nav link | `includes/nav.php` | nothing |
| 8 | Directory setup | `invite/.htaccess` | nothing |
| 9 | E2E test on staging | — | all above |

Tasks 1, 2, 4, 7, 8 can run in parallel. Task 3 depends on 1+2. Task 5 depends on 1+2. Task 6 depends on 1+2+3+4. Task 9 is last.
