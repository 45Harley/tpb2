# Constituent Mandate — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a standalone POC page where citizens privately submit priorities for their reps, AI aggregates them into public Constituent Mandate summaries per district/state/town.

**Architecture:** Single standalone page (`mandate-poc.php`) that includes `talk-stream.php` (personal mode with mandate categories) and `c-widget.php` (voice commands). New API endpoint for phone login. New API endpoint for mandate aggregation. No existing files modified.

**Tech Stack:** PHP 8.4, vanilla JS, existing Talk API (`talk/api.php`), Claude API for tone tagging and aggregation, browser SpeechRecognition/SpeechSynthesis.

---

### Task 1: Phone Login API Endpoint

**Files:**
- Create: `api/mandate-phone-login.php`

**Step 1: Create the phone login endpoint**

```php
<?php
/**
 * Mandate Phone Login — POC
 * POST { phone: "8039841827" }           → returns matches (count + first names if multiple)
 * POST { phone: "8039841827", name: "Harley" } → returns session if name matches
 */
$config = require __DIR__ . '/../config.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$phone = preg_replace('/\D/', '', $input['phone'] ?? '');
$name  = trim($input['name'] ?? '');

if (strlen($phone) < 10) {
    echo json_encode(['success' => false, 'error' => 'Invalid phone number']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Find users with this phone number
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.username,
               u.current_state_id, u.current_town_id, u.us_congress_district,
               s.abbreviation as state_abbr, tw.town_name
        FROM user_identity_status uis
        JOIN users u ON uis.user_id = u.user_id
        LEFT JOIN states s ON u.current_state_id = s.state_id
        LEFT JOIN towns tw ON u.current_town_id = tw.town_id
        WHERE uis.phone = ? AND uis.phone_verified = 1 AND u.deleted_at IS NULL
    ");
    $stmt->execute([$phone]);
    $matches = $stmt->fetchAll();

    if (count($matches) === 0) {
        echo json_encode(['success' => false, 'error' => 'no_match']);
        exit;
    }

    if (count($matches) === 1) {
        $user = $matches[0];
        echo json_encode([
            'success' => true,
            'user' => [
                'user_id'    => (int)$user['user_id'],
                'first_name' => $user['first_name'],
                'state_abbr' => $user['state_abbr'],
                'town_name'  => $user['town_name'],
                'district'   => $user['us_congress_district']
            ]
        ]);
        exit;
    }

    // Multiple matches — need name disambiguation
    if (!$name) {
        $firstNames = array_map(fn($m) => $m['first_name'], $matches);
        echo json_encode([
            'success' => false,
            'error' => 'multiple_matches',
            'count' => count($matches),
            'hint' => 'What is your first name?'
        ]);
        exit;
    }

    // Filter by first name (case-insensitive)
    $nameMatches = array_filter($matches, fn($m) => strcasecmp($m['first_name'], $name) === 0);

    if (count($nameMatches) === 1) {
        $user = array_values($nameMatches)[0];
        echo json_encode([
            'success' => true,
            'user' => [
                'user_id'    => (int)$user['user_id'],
                'first_name' => $user['first_name'],
                'state_abbr' => $user['state_abbr'],
                'town_name'  => $user['town_name'],
                'district'   => $user['us_congress_district']
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'still_ambiguous']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
```

**Step 2: Test manually with curl**

```bash
# Single match test
curl -X POST http://localhost/api/mandate-phone-login.php \
  -H "Content-Type: application/json" \
  -d '{"phone":"8607775158"}'

# Multiple match test (should ask for name)
curl -X POST http://localhost/api/mandate-phone-login.php \
  -H "Content-Type: application/json" \
  -d '{"phone":"8039841827"}'

# Name disambiguation test
curl -X POST http://localhost/api/mandate-phone-login.php \
  -H "Content-Type: application/json" \
  -d '{"phone":"8039841827","name":"Harley"}'
```

**Step 3: Commit**

```bash
git add api/mandate-phone-login.php
git commit -m "feat(mandate): add phone login API endpoint with name disambiguation"
```

---

### Task 2: Mandate POC Page — Shell & Phone Login UI

**Files:**
- Create: `mandate-poc.php`

**Step 1: Create the page shell with phone login flow**

This is the main POC page. It handles:
- Cookie-based auth (existing users already logged in)
- Phone login flow (for mobile/voice users)
- Page layout with sections for stream + public summary

```php
<?php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/get-user.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $dbUser = getUser($pdo);
} catch (PDOException $e) {
    $dbUser = false;
}

$isLoggedIn = (bool)$dbUser;
$currentUserId = $dbUser ? (int)$dbUser['user_id'] : 0;

// Geo context from user profile
$userStateId = $dbUser ? ($dbUser['current_state_id'] ?? null) : null;
$userTownId  = $dbUser ? ($dbUser['current_town_id'] ?? null) : null;
$userDistrict = $dbUser ? ($dbUser['us_congress_district'] ?? null) : null;
$userStateName = $dbUser ? ($dbUser['abbreviation'] ?? '') : '';
$userTownName  = $dbUser ? ($dbUser['town_name'] ?? '') : '';

// Nav setup
$navVars = getNavVarsForUser($dbUser);
extract($navVars);
$currentPage = 'mandate';
$pageTitle = 'My Mandate | The People\'s Branch';

// Talk stream CSS in <head>
$_tsCssVer = file_exists(__DIR__ . '/assets/talk-stream.css') ? filemtime(__DIR__ . '/assets/talk-stream.css') : 0;
$headLinks = '    <link rel="stylesheet" href="/assets/talk-stream.css?v=' . $_tsCssVer . '">' . "\n";

$pageStyles = <<<'CSS'
    body {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        background-attachment: fixed;
    }

    /* ── Phone Login ── */
    .mandate-login {
        max-width: 500px;
        margin: 40px auto;
        padding: 30px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        text-align: center;
    }
    .mandate-login h2 { color: #d4af37; margin: 0 0 10px; }
    .mandate-login p { color: #b0b0b0; margin: 0 0 20px; }
    .phone-input {
        display: flex; gap: 8px; justify-content: center;
        margin-bottom: 15px; flex-wrap: wrap;
    }
    .phone-digit {
        width: 36px; height: 44px; text-align: center;
        font-size: 1.3rem; font-family: monospace;
        background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2);
        border-radius: 6px; color: #fff;
    }
    .phone-digit:focus { border-color: #d4af37; outline: none; }
    .phone-sep { color: #666; font-size: 1.3rem; line-height: 44px; }
    .mandate-btn {
        padding: 10px 24px; border: none; border-radius: 8px;
        background: #d4af37; color: #1a1a2e; font-weight: 600;
        font-size: 1rem; cursor: pointer;
    }
    .mandate-btn:hover { background: #e5c04b; }
    .mandate-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .login-status {
        margin-top: 12px; font-size: 0.9rem; min-height: 1.5em;
    }
    .login-status.error { color: #ef5350; }
    .login-status.success { color: #81c784; }
    .name-input {
        padding: 10px 16px; font-size: 1rem;
        background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.2);
        border-radius: 6px; color: #fff; margin-bottom: 12px; width: 200px;
    }
    .name-input:focus { border-color: #d4af37; outline: none; }

    /* ── Mandate Header ── */
    .mandate-header {
        text-align: center; padding: 16px;
    }
    .mandate-header h1 { color: #fff; font-size: 1.4rem; margin: 0 0 4px; }
    .mandate-header .mandate-geo {
        color: #90caf9; font-size: 0.9rem;
    }

    /* ── Mandate Level Tabs ── */
    .mandate-tabs {
        display: flex; justify-content: center; gap: 8px;
        padding: 0 16px 12px; flex-wrap: wrap;
    }
    .mandate-tab {
        padding: 6px 16px; border-radius: 20px;
        border: 1px solid rgba(255,255,255,0.15);
        background: transparent; color: #b0b0b0;
        font-size: 0.85rem; cursor: pointer;
    }
    .mandate-tab.active {
        background: rgba(212,175,55,0.2);
        border-color: #d4af37; color: #d4af37;
    }

    /* ── Public Summary ── */
    .mandate-summary {
        max-width: 700px; margin: 20px auto;
        padding: 20px; background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 12px;
    }
    .mandate-summary h3 {
        color: #d4af37; margin: 0 0 12px;
        font-size: 1.1rem;
    }
    .mandate-summary .summary-content {
        color: #ccc; font-size: 0.95rem; line-height: 1.6;
    }
    .mandate-summary .summary-empty {
        color: #888; font-style: italic;
    }

    /* ── Responsive ── */
    @media (min-width: 700px) {
        .mandate-header, .mandate-tabs, .mandate-summary {
            max-width: 700px; margin-left: auto; margin-right: auto;
        }
    }
CSS;

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>

<?php if (!$isLoggedIn): ?>
<!-- ── Phone Login Flow ── -->
<div class="mandate-login" id="phoneLoginSection">
    <h2>My Mandate</h2>
    <p>Enter your phone number to get started.</p>

    <div class="phone-input" id="phoneDigits">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="0">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="1">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="2">
        <span class="phone-sep">-</span>
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="3">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="4">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="5">
        <span class="phone-sep">-</span>
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="6">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="7">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="8">
        <input class="phone-digit" type="tel" maxlength="1" inputmode="numeric" data-idx="9">
    </div>

    <button class="mandate-btn" id="phoneSubmitBtn" disabled>Verify</button>

    <!-- Name disambiguation (hidden by default) -->
    <div id="nameSection" style="display:none; margin-top:16px;">
        <p style="color:#b0b0b0;">Multiple accounts found. What's your first name?</p>
        <input class="name-input" type="text" id="nameInput" placeholder="First name">
        <br><button class="mandate-btn" id="nameSubmitBtn" style="margin-top:8px;">Submit</button>
    </div>

    <div class="login-status" id="loginStatus"></div>
</div>

<script>
(function() {
    const digits = document.querySelectorAll('.phone-digit');
    const submitBtn = document.getElementById('phoneSubmitBtn');
    const loginStatus = document.getElementById('loginStatus');
    const nameSection = document.getElementById('nameSection');
    const nameInput = document.getElementById('nameInput');
    const nameSubmitBtn = document.getElementById('nameSubmitBtn');
    let attempts = 0;
    const MAX_ATTEMPTS = 3;
    let currentPhone = '';

    // Auto-advance on digit entry
    digits.forEach((d, i) => {
        d.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
            if (this.value && i < digits.length - 1) digits[i + 1].focus();
            checkComplete();
        });
        d.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && i > 0) {
                digits[i - 1].focus();
            }
        });
    });

    function checkComplete() {
        const phone = Array.from(digits).map(d => d.value).join('');
        submitBtn.disabled = phone.length < 10;
    }

    function getPhone() {
        return Array.from(digits).map(d => d.value).join('');
    }

    function formatPhone(ph) {
        return ph.split('').join('-');
    }

    submitBtn.addEventListener('click', async function() {
        const phone = getPhone();
        if (phone.length < 10) return;
        attempts++;
        currentPhone = phone;

        loginStatus.className = 'login-status';
        loginStatus.textContent = 'Looking you up...';

        try {
            const resp = await fetch('/api/mandate-phone-login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone })
            });
            const data = await resp.json();

            if (data.success) {
                loginSuccess(data.user);
            } else if (data.error === 'multiple_matches') {
                nameSection.style.display = 'block';
                loginStatus.className = 'login-status';
                loginStatus.textContent = data.hint;
                nameInput.focus();
            } else if (data.error === 'no_match') {
                loginStatus.className = 'login-status error';
                loginStatus.textContent = "I don't recognize that number.";
                if (attempts >= MAX_ATTEMPTS) lockOut();
            } else {
                loginStatus.className = 'login-status error';
                loginStatus.textContent = data.error || 'Something went wrong.';
                if (attempts >= MAX_ATTEMPTS) lockOut();
            }
        } catch (e) {
            loginStatus.className = 'login-status error';
            loginStatus.textContent = 'Connection error.';
        }
    });

    nameSubmitBtn.addEventListener('click', async function() {
        const name = nameInput.value.trim();
        if (!name) return;
        attempts++;

        loginStatus.className = 'login-status';
        loginStatus.textContent = 'Checking...';

        try {
            const resp = await fetch('/api/mandate-phone-login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phone: currentPhone, name })
            });
            const data = await resp.json();

            if (data.success) {
                loginSuccess(data.user);
            } else if (data.error === 'still_ambiguous') {
                loginStatus.className = 'login-status error';
                loginStatus.textContent = 'Please log in another way.';
            } else {
                loginStatus.className = 'login-status error';
                loginStatus.textContent = "Name didn't match. Try again.";
                if (attempts >= MAX_ATTEMPTS) lockOut();
            }
        } catch (e) {
            loginStatus.className = 'login-status error';
            loginStatus.textContent = 'Connection error.';
        }
    });

    function loginSuccess(user) {
        localStorage.setItem('tpb_mandate_phone', currentPhone);
        localStorage.setItem('tpb_mandate_user', JSON.stringify(user));
        loginStatus.className = 'login-status success';
        loginStatus.textContent = 'Mandate ready, ' + user.first_name + '!';
        setTimeout(() => location.reload(), 1000);
    }

    function lockOut() {
        loginStatus.className = 'login-status error';
        loginStatus.textContent = "Let's try again later.";
        submitBtn.disabled = true;
        nameSubmitBtn.disabled = true;
    }
})();
</script>

<?php else: ?>
<!-- ── Logged In: Mandate Interface ── -->

<div class="mandate-header">
    <h1>My Mandate</h1>
    <div class="mandate-geo">
        <?= htmlspecialchars($userTownName) ?>, <?= htmlspecialchars($userStateName) ?>
        <?php if ($userDistrict): ?> &middot; <?= htmlspecialchars($userDistrict) ?><?php endif; ?>
    </div>
</div>

<div class="mandate-tabs" id="mandateTabs">
    <button class="mandate-tab active" data-level="all">All</button>
    <button class="mandate-tab" data-level="mandate-federal">Federal</button>
    <button class="mandate-tab" data-level="mandate-state">State</button>
    <button class="mandate-tab" data-level="mandate-town">Town</button>
</div>

<?php
// ── Talk Stream configured for mandate mode ──
$talkStreamConfig = [
    'title'               => null,
    'placeholder'         => "What do you want your reps to do?",
    'show_group_selector' => false,
    'show_filters'        => false,
    'show_categories'     => true,
    'show_ai_toggle'      => true,
    'show_mic'            => true,
    'show_admin_tools'    => false,
    'geo_state_id'        => $userStateId,
    'geo_town_id'         => $userTownId,
    'limit'               => 50,
];
require __DIR__ . '/includes/talk-stream.php';
?>

<!-- ── Public Mandate Summary ── -->
<div class="mandate-summary" id="mandateSummary">
    <h3>Constituent Mandate for <?= htmlspecialchars($userDistrict ?: $userTownName ?: 'Your District') ?></h3>
    <div class="summary-content">
        <p class="summary-empty">No mandate summary yet. Add your priorities above to get started.</p>
    </div>
</div>

<script>
// Mandate level tab filtering
(function() {
    const tabs = document.querySelectorAll('.mandate-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            const level = this.dataset.level;
            // Filter displayed ideas by mandate level category
            const prefix = '<?= $_tsPrefix ?? 'ts0' ?>';
            const ts = TalkStream._instances[prefix];
            if (ts) {
                if (level === 'all') {
                    ts.setCategoryFilter('');
                } else {
                    ts.setCategoryFilter(level);
                }
            }
        });
    });
})();
</script>

<?php endif; ?>

<?php
// Include C Widget for voice commands
require __DIR__ . '/includes/c-widget.php';
require __DIR__ . '/includes/footer.php';
?>
```

**Step 2: Test page loads**

Open `http://localhost/mandate-poc.php` in browser:
- Not logged in → phone login form shows
- Logged in via cookie → mandate interface shows with talk stream

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): add POC page with phone login and talk stream"
```

---

### Task 3: Add Mandate Categories to Talk Stream Category Bar

The talk stream `show_categories` option renders category filter buttons. Currently: Ideas, Decisions, Todos, Notes, Questions. We need to add mandate buttons without modifying `talk-stream.php` directly — instead, inject them via JS on the POC page.

**Files:**
- Modify: `mandate-poc.php` (add JS after talk stream init)

**Step 1: Add mandate category buttons via JS injection**

Add this script block after the talk stream include in `mandate-poc.php`, inside the logged-in section:

```javascript
// Inject mandate category buttons into talk stream category bar
(function() {
    const prefix = '<?= $_tsPrefix ?? "ts0" ?>';
    const catBar = document.getElementById(prefix + '-catBar');
    if (!catBar) return;

    const mandateCats = [
        { cat: 'mandate-federal', label: 'Mandate Federal' },
        { cat: 'mandate-state', label: 'Mandate State' },
        { cat: 'mandate-town', label: 'Mandate Town' }
    ];

    mandateCats.forEach(mc => {
        const btn = document.createElement('button');
        btn.className = 'cat-btn';
        btn.dataset.cat = mc.cat;
        btn.textContent = mc.label;
        btn.onclick = function() {
            TalkStream._instances[prefix].setCategoryFilter(mc.cat);
        };
        catBar.appendChild(btn);
    });
})();
```

**Step 2: Test category buttons appear and filter**

Open mandate-poc.php while logged in. Verify:
- "Mandate Federal", "Mandate State", "Mandate Town" buttons appear in category bar
- Clicking them filters the stream (will be empty at first — no mandate items yet)

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): inject mandate category buttons into talk stream"
```

---

### Task 4: Update AI Auto-Classify to Handle Mandate Categories + Tone

The talk API auto-classifies ideas. We need to update the classify prompt to recognize mandate intent and tag tone. This is done in `talk/api.php` — but since this is a POC, we'll add an override in the POC page that intercepts the save and re-classifies.

**However** — the simpler approach: add a category dropdown to the input area so the user explicitly picks their mandate level before posting. Auto-classify can come later.

**Files:**
- Modify: `mandate-poc.php` (add mandate level picker before send)

**Step 1: Add mandate level picker to input area**

Add this script block in `mandate-poc.php` after talk stream init:

```javascript
// Add mandate level picker to talk stream input area
(function() {
    const prefix = '<?= $_tsPrefix ?? "ts0" ?>';
    const inputArea = document.querySelector('#' + prefix + '-wrapper .input-area');
    if (!inputArea) return;

    const picker = document.createElement('div');
    picker.style.cssText = 'display:flex;gap:6px;padding:4px 0;flex-wrap:wrap;';
    picker.innerHTML = `
        <span style="color:#888;font-size:0.8rem;line-height:28px;">Level:</span>
        <button class="mandate-level-btn active" data-level="" style="padding:3px 10px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:rgba(212,175,55,0.2);color:#d4af37;font-size:0.8rem;cursor:pointer;">Private</button>
        <button class="mandate-level-btn" data-level="mandate-federal" style="padding:3px 10px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:transparent;color:#b0b0b0;font-size:0.8rem;cursor:pointer;">Federal</button>
        <button class="mandate-level-btn" data-level="mandate-state" style="padding:3px 10px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:transparent;color:#b0b0b0;font-size:0.8rem;cursor:pointer;">State</button>
        <button class="mandate-level-btn" data-level="mandate-town" style="padding:3px 10px;border-radius:14px;border:1px solid rgba(255,255,255,0.15);background:transparent;color:#b0b0b0;font-size:0.8rem;cursor:pointer;">Town</button>
    `;
    inputArea.insertBefore(picker, inputArea.firstChild);

    let selectedLevel = '';
    picker.querySelectorAll('.mandate-level-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            picker.querySelectorAll('.mandate-level-btn').forEach(b => {
                b.style.background = 'transparent';
                b.style.color = '#b0b0b0';
            });
            this.style.background = 'rgba(212,175,55,0.2)';
            this.style.color = '#d4af37';
            selectedLevel = this.dataset.level;
        });
    });

    // Intercept TalkStream submit to inject mandate category
    const ts = TalkStream._instances[prefix];
    if (ts) {
        const origSubmit = ts.submitIdea.bind(ts);
        ts.submitIdea = async function() {
            // Store the selected level so it gets sent as category
            if (selectedLevel) {
                this._mandateCategory = selectedLevel;
            }
            return origSubmit();
        };

        // Patch the buildPayload to include mandate category
        const origBuild = ts._buildSavePayload?.bind(ts);
        // Alternative: intercept the fetch call
        const origFetch = window.fetch;
        window.fetch = function(url, opts) {
            if (url && url.toString().includes('api.php') && opts && opts.body) {
                try {
                    const body = JSON.parse(opts.body);
                    if (body.content && selectedLevel && !body.action) {
                        body.category = selectedLevel;
                        opts.body = JSON.stringify(body);
                    }
                } catch(e) {}
            }
            return origFetch.call(this, url, opts);
        };
    }
})();
```

**Step 2: Test posting a mandate item**

1. Open mandate-poc.php logged in
2. Select "Federal" level
3. Type "Vote no on the trade bill"
4. Submit
5. Verify the idea appears with category `mandate-federal` in the stream

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): add level picker to input area, inject category on save"
```

---

### Task 5: TTS Playback Button on Mandate Items

**Files:**
- Modify: `mandate-poc.php` (add TTS button injection via JS)

**Step 1: Add TTS playback to idea cards**

```javascript
// Add TTS play button to each idea card
(function() {
    const prefix = '<?= $_tsPrefix ?? "ts0" ?>';

    function addPlayButtons() {
        const cards = document.querySelectorAll('#' + prefix + '-stream .idea-card');
        cards.forEach(card => {
            if (card.querySelector('.tts-play-btn')) return; // already added
            const footer = card.querySelector('.card-footer');
            if (!footer) return;

            const content = card.querySelector('.card-content')?.textContent?.trim();
            if (!content) return;

            const btn = document.createElement('button');
            btn.className = 'tts-play-btn';
            btn.textContent = '\u{1F50A}';
            btn.title = 'Read aloud';
            btn.style.cssText = 'background:none;border:1px solid rgba(255,255,255,0.15);border-radius:4px;color:#b0b0b0;cursor:pointer;padding:2px 6px;font-size:0.85rem;';
            btn.addEventListener('click', function() {
                const utter = new SpeechSynthesisUtterance(content);
                utter.lang = 'en-US';
                // Prefer female voice
                const voices = speechSynthesis.getVoices();
                const female = voices.find(v => v.lang.startsWith('en') && v.name.toLowerCase().includes('female'))
                    || voices.find(v => v.lang.startsWith('en-US'));
                if (female) utter.voice = female;
                speechSynthesis.cancel();
                speechSynthesis.speak(utter);
            });
            footer.prepend(btn);
        });
    }

    // Run on initial load and after each poll/update
    const observer = new MutationObserver(addPlayButtons);
    const stream = document.getElementById(prefix + '-stream');
    if (stream) {
        observer.observe(stream, { childList: true, subtree: true });
        addPlayButtons();
    }
})();
```

**Step 2: Test TTS**

1. Post a mandate item
2. Click the speaker icon
3. Verify browser reads the text aloud

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): add TTS playback button to idea cards"
```

---

### Task 6: Mandate Aggregation API Endpoint

**Files:**
- Create: `api/mandate-aggregate.php`

**Step 1: Create the aggregation endpoint**

```php
<?php
/**
 * Mandate Aggregation — POC
 *
 * GET ?level=federal&district=CT-2  → returns aggregated mandate summary
 * GET ?level=state&state_id=7       → state-level summary
 * GET ?level=town&town_id=123       → town-level summary
 *
 * For POC: simple SQL grouping, no AI summarization yet.
 * Returns top priorities by keyword frequency.
 */
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';
header('Content-Type: application/json');

$level = $_GET['level'] ?? 'federal';
$category = 'mandate-' . $level;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Build geo filter based on level
    $where = "i.category = ? AND i.deleted_at IS NULL AND u.deleted_at IS NULL";
    $params = [$category];

    if ($level === 'federal') {
        $district = $_GET['district'] ?? '';
        if (!$district) {
            echo json_encode(['success' => false, 'error' => 'district required']);
            exit;
        }
        $where .= " AND u.us_congress_district = ?";
        $params[] = $district;
    } elseif ($level === 'state') {
        $stateId = (int)($_GET['state_id'] ?? 0);
        if (!$stateId) {
            echo json_encode(['success' => false, 'error' => 'state_id required']);
            exit;
        }
        $where .= " AND u.current_state_id = ?";
        $params[] = $stateId;
    } elseif ($level === 'town') {
        $townId = (int)($_GET['town_id'] ?? 0);
        if (!$townId) {
            echo json_encode(['success' => false, 'error' => 'town_id required']);
            exit;
        }
        $where .= " AND u.current_town_id = ?";
        $params[] = $townId;
    }

    // Get all mandate items for this scope
    $stmt = $pdo->prepare("
        SELECT i.id, i.content, i.tags, i.created_at,
               COUNT(DISTINCT i.user_id) OVER() as total_contributors
        FROM idea_log i
        JOIN users u ON i.user_id = u.user_id
        WHERE {$where}
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $totalContributors = $items ? (int)$items[0]['total_contributors'] : 0;

    echo json_encode([
        'success' => true,
        'level' => $level,
        'item_count' => count($items),
        'contributor_count' => $totalContributors,
        'items' => array_map(fn($i) => [
            'id' => (int)$i['id'],
            'content' => $i['content'],
            'tags' => $i['tags'],
            'created_at' => $i['created_at']
        ], $items)
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
```

**Step 2: Test the endpoint**

```bash
curl "http://localhost/api/mandate-aggregate.php?level=federal&district=CT-2"
curl "http://localhost/api/mandate-aggregate.php?level=town&town_id=1"
```

**Step 3: Commit**

```bash
git add api/mandate-aggregate.php
git commit -m "feat(mandate): add aggregation API endpoint"
```

---

### Task 7: Wire Public Summary Section to Aggregation API

**Files:**
- Modify: `mandate-poc.php` (add fetch + render for summary section)

**Step 1: Add summary loading script**

Add this script block in the logged-in section of `mandate-poc.php`:

```javascript
// Load and display public mandate summary
(function() {
    const summaryDiv = document.querySelector('.mandate-summary .summary-content');
    const district = <?= json_encode($userDistrict ?: '') ?>;
    const stateId = <?= (int)$userStateId ?>;
    const townId = <?= (int)$userTownId ?>;
    const townName = <?= json_encode($userTownName ?: '') ?>;
    const stateAbbr = <?= json_encode($userStateName ?: '') ?>;

    async function loadSummary(level) {
        let url = '/api/mandate-aggregate.php?level=' + level;
        if (level === 'federal' && district) url += '&district=' + encodeURIComponent(district);
        else if (level === 'state' && stateId) url += '&state_id=' + stateId;
        else if (level === 'town' && townId) url += '&town_id=' + townId;
        else return;

        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.success && data.items.length > 0) {
                let html = '<p style="color:#81c784;margin:0 0 12px;">'
                    + data.contributor_count + ' constituent'
                    + (data.contributor_count !== 1 ? 's have' : ' has')
                    + ' spoken.</p><ol style="margin:0;padding-left:20px;">';
                data.items.forEach(item => {
                    html += '<li style="margin-bottom:6px;">' + escapeHtml(item.content);
                    if (item.tags) html += ' <span style="color:#888;font-size:0.8rem;">(' + escapeHtml(item.tags) + ')</span>';
                    html += '</li>';
                });
                html += '</ol>';
                summaryDiv.innerHTML = html;
            } else {
                summaryDiv.innerHTML = '<p class="summary-empty">No mandate items yet for this scope.</p>';
            }
        } catch(e) {
            summaryDiv.innerHTML = '<p class="summary-empty">Could not load summary.</p>';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Load federal summary by default
    loadSummary('federal');

    // Update summary when mandate tabs are clicked
    document.querySelectorAll('.mandate-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const level = this.dataset.level;
            const summaryTitle = document.querySelector('.mandate-summary h3');
            if (level === 'mandate-federal' || level === 'all') {
                loadSummary('federal');
                if (summaryTitle) summaryTitle.textContent = 'Constituent Mandate for ' + (district || 'Your District');
            } else if (level === 'mandate-state') {
                loadSummary('state');
                if (summaryTitle) summaryTitle.textContent = 'Constituent Mandate for ' + stateAbbr;
            } else if (level === 'mandate-town') {
                loadSummary('town');
                if (summaryTitle) summaryTitle.textContent = 'Constituent Mandate for ' + townName;
            }
        });
    });
})();
```

**Step 2: Test summary loading**

1. Post a few mandate items at different levels
2. Click the level tabs — summary section should update
3. Summary shows item count + list

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): wire public summary section to aggregation API"
```

---

### Task 8: Wire C Widget Voice Commands for Mandate

**Files:**
- Modify: `mandate-poc.php` (add Claudia event hooks for mandate voice commands)

**Step 1: Add mandate voice command hooks**

The C widget dispatches to `liveRespond()` for unrecognized input. For the POC, we intercept voice input before it reaches Claude and handle mandate commands locally:

```javascript
// Mandate voice commands for Claudia
(function() {
    // Wait for widget to initialize
    const checkWidget = setInterval(function() {
        if (!window.cWidget) return;
        clearInterval(checkWidget);

        const origLive = window.cWidget.liveRespond?.bind(window.cWidget);

        // Intercept messages for mandate commands
        window.cWidget.handleMandateCommand = function(text) {
            const lower = text.toLowerCase().trim();

            // Login: digits spoken
            const digitMatch = lower.replace(/[^0-9]/g, '');
            if (digitMatch.length === 10 && !localStorage.getItem('tpb_mandate_user')) {
                // Phone login via voice
                return handleVoiceLogin(digitMatch);
            }

            // Logout
            if (lower.includes('log me out') || lower.includes('log out')) {
                localStorage.removeItem('tpb_mandate_phone');
                localStorage.removeItem('tpb_mandate_user');
                window.cWidget.speak("Done, you're logged out.");
                setTimeout(() => location.reload(), 2000);
                return true;
            }

            // Read mandate
            if (lower.includes('read') && lower.includes('mandate') || lower.includes('list') && lower.includes('mandate')) {
                readMandate(lower);
                return true;
            }

            // Add to mandate
            if (lower.startsWith('add to my') || lower.startsWith('mandate')) {
                addVoiceMandate(text);
                return true;
            }

            // Delete from mandate
            if (lower.startsWith('delete number') || lower.startsWith('delete #')) {
                const num = parseInt(lower.replace(/\D/g, ''));
                if (num) deleteByNumber(num);
                return true;
            }

            return false; // Not a mandate command, pass to Claudia
        };

        async function handleVoiceLogin(phone) {
            const formatted = phone.split('').join('-');
            window.cWidget.speak("I heard " + formatted + ". Is that correct?");
            // Simplified for POC — full confirm flow would need state machine
        }

        function readMandate(lower) {
            let level = '';
            if (lower.includes('federal')) level = 'mandate-federal';
            else if (lower.includes('state')) level = 'mandate-state';
            else if (lower.includes('town')) level = 'mandate-town';

            // Read items from the displayed stream
            const cards = document.querySelectorAll('.idea-card');
            const items = [];
            cards.forEach((card, i) => {
                const cat = card.dataset.category || '';
                if (level && cat !== level) return;
                if (!cat.startsWith('mandate-')) return;
                const content = card.querySelector('.card-content')?.textContent?.trim();
                if (content) items.push((items.length + 1) + '. ' + content);
            });

            if (items.length === 0) {
                window.cWidget.speak("Your mandate is empty. Add items by saying: add to my federal mandate, followed by your priority.");
            } else {
                window.cWidget.speak("Your mandate has " + items.length + " items. " + items.join('. '));
            }
        }

        function addVoiceMandate(text) {
            let level = 'mandate-federal'; // default
            const lower = text.toLowerCase();
            if (lower.includes('state')) level = 'mandate-state';
            else if (lower.includes('town')) level = 'mandate-town';

            // Strip the command prefix to get the actual content
            let content = text
                .replace(/^(add to my |mandate )(federal|state|town)( mandate)?:?\s*/i, '')
                .trim();

            if (!content) {
                window.cWidget.speak("What would you like to add?");
                return;
            }

            // Submit via talk stream
            const prefix = '<?= $_tsPrefix ?? "ts0" ?>';
            const ts = TalkStream._instances[prefix];
            if (ts) {
                const input = document.getElementById(prefix + '-input');
                if (input) {
                    input.value = content;
                    // Set the mandate level
                    document.querySelectorAll('.mandate-level-btn').forEach(b => {
                        b.style.background = 'transparent';
                        b.style.color = '#b0b0b0';
                        if (b.dataset.level === level) {
                            b.style.background = 'rgba(212,175,55,0.2)';
                            b.style.color = '#d4af37';
                            b.click();
                        }
                    });
                    ts.submitIdea();
                    window.cWidget.speak("Added to your " + level.replace('mandate-', '') + " mandate.");
                }
            }
        }

        function deleteByNumber(num) {
            const cards = document.querySelectorAll('.idea-card');
            const mandateCards = Array.from(cards).filter(c => {
                const cat = c.dataset.category || '';
                return cat.startsWith('mandate-');
            });
            if (num > mandateCards.length || num < 1) {
                window.cWidget.speak("Item number " + num + " not found.");
                return;
            }
            const card = mandateCards[num - 1];
            const ideaId = card.dataset.ideaId;
            if (ideaId) {
                const prefix = '<?= $_tsPrefix ?? "ts0" ?>';
                const ts = TalkStream._instances[prefix];
                if (ts) {
                    ts.deleteIdea(parseInt(ideaId));
                    window.cWidget.speak("Deleted item number " + num + ".");
                }
            }
        }
    }, 500);
})();
```

**Step 2: Test voice commands**

1. Open mandate-poc.php
2. Click Claudia bubble
3. Say "Add to my federal mandate: vote no on the trade bill"
4. Verify item appears in stream
5. Say "Read my mandate" — verify Claudia reads it back
6. Say "Delete number 1" — verify item removed

**Step 3: Commit**

```bash
git add mandate-poc.php
git commit -m "feat(mandate): wire Claudia voice commands for mandate CRUD"
```

---

### Task 9: End-to-End Test & Polish

**Files:**
- Review: `mandate-poc.php`, `api/mandate-phone-login.php`, `api/mandate-aggregate.php`

**Step 1: Test the full phone login flow**

1. Open mandate-poc.php in incognito (no cookies)
2. Enter phone digits: 8-0-3-9-8-4-1-8-2-7
3. Expect "Multiple accounts" → name prompt
4. Type "Harley" → Expect "Mandate ready, Harley!"
5. Page reloads (but won't have cookie auth — localStorage only for POC display)

**Step 2: Test the full mandate flow (logged in)**

1. Open mandate-poc.php logged in normally
2. Select "Federal" level
3. Type "Vote no on the trade bill" → submit
4. Select "Town" level
5. Type "Fix Route 44 potholes" → submit
6. Click "All" tab → see both items
7. Click "Federal" tab → see only federal item
8. Check summary section updates
9. Click speaker icon → hear TTS
10. Delete an item

**Step 3: Test voice commands**

1. Click Claudia bubble
2. Say "Add to my state mandate: expand childcare subsidies"
3. Say "Read my mandate"
4. Say "Delete number 1"

**Step 4: Final commit**

```bash
git add -A mandate-poc.php api/mandate-phone-login.php api/mandate-aggregate.php
git commit -m "feat(mandate): Constituent Mandate POC — complete"
```

---

## Summary

| Task | What | Files |
|------|------|-------|
| 1 | Phone login API | `api/mandate-phone-login.php` |
| 2 | POC page shell + phone login UI | `mandate-poc.php` |
| 3 | Mandate category buttons (JS inject) | `mandate-poc.php` |
| 4 | Mandate level picker on input | `mandate-poc.php` |
| 5 | TTS playback button | `mandate-poc.php` |
| 6 | Aggregation API | `api/mandate-aggregate.php` |
| 7 | Wire summary section | `mandate-poc.php` |
| 8 | Claudia voice commands | `mandate-poc.php` |
| 9 | End-to-end test & polish | all |

**Total new files:** 3 (`mandate-poc.php`, `api/mandate-phone-login.php`, `api/mandate-aggregate.php`)
**Existing files modified:** 0
