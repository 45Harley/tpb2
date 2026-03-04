<?php
/**
 * Constituent Mandate — POC Page
 * ==============================
 * Standalone page for mandate creation and viewing.
 *
 * Not logged in: phone login form (10-digit input, name disambiguation)
 * Logged in:     mandate interface with talk stream, level picker, TTS
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/get-user.php';

$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$dbUser    = getUser($pdo);
$isLoggedIn = (bool)$dbUser;

$navVars = getNavVarsForUser($dbUser);
extract($navVars);

$currentPage = 'mandate';
$pageTitle   = 'My Mandate | The People\'s Branch';

// Talk stream CSS in head
$_tsCssVer = file_exists(__DIR__ . '/assets/talk-stream.css') ? filemtime(__DIR__ . '/assets/talk-stream.css') : 0;
$headLinks = '    <link rel="stylesheet" href="/assets/talk-stream.css?v=' . $_tsCssVer . '">' . "\n";

// Page-specific styles
$pageStyles = <<<'CSS'

/* ── Mandate page layout ─────────────────────────────────── */
.mandate-wrap {
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

/* ── Phone Login Card ────────────────────────────────────── */
.login-card {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 16px;
    padding: 2.5rem 2rem;
    max-width: 440px;
    margin: 4rem auto;
    text-align: center;
}
.login-card h1 {
    color: #d4af37;
    font-size: 1.6rem;
    margin-bottom: 0.5rem;
}
.login-card .login-sub {
    color: #b0b0b0;
    font-size: 0.95rem;
    margin-bottom: 2rem;
}
.digit-row {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-bottom: 1.25rem;
}
.digit-row input {
    width: 36px;
    height: 44px;
    text-align: center;
    font-size: 1.3rem;
    font-weight: 600;
    background: #0d0d1a;
    border: 1px solid #444;
    border-radius: 8px;
    color: #fff;
    padding: 0;
    caret-color: #d4af37;
    transition: border-color 0.2s;
}
.digit-row input:focus {
    outline: none;
    border-color: #d4af37;
    box-shadow: 0 0 0 2px rgba(212,175,55,0.2);
}
.digit-row .digit-dash {
    display: flex;
    align-items: center;
    color: #b0b0b0;
    font-size: 1.2rem;
    padding: 0 2px;
}
.verify-btn {
    width: 100%;
    padding: 0.85rem;
    font-size: 1.05rem;
    font-weight: 600;
    background: #d4af37;
    color: #000;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.2s, opacity 0.2s;
}
.verify-btn:hover:not(:disabled) {
    background: #e4bf47;
}
.verify-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
.login-status {
    margin-top: 1rem;
    font-size: 0.9rem;
    min-height: 1.4em;
}
.login-status.error   { color: #e63946; }
.login-status.success { color: #4caf50; }
.login-status.info    { color: #4a90a4; }

/* Name disambiguation */
.name-section {
    display: none;
    margin-top: 1.25rem;
    text-align: left;
}
.name-section label {
    display: block;
    color: #fff;
    font-size: 0.9rem;
    margin-bottom: 0.4rem;
}
.name-section input {
    width: 100%;
    padding: 0.7rem 0.85rem;
    font-size: 1rem;
    background: #0d0d1a;
    border: 1px solid #444;
    border-radius: 8px;
    color: #e0e0e0;
    margin-bottom: 0.75rem;
}
.name-section input:focus {
    outline: none;
    border-color: #d4af37;
}

/* Lockout */
.lockout-msg {
    color: #e63946;
    font-size: 0.95rem;
    padding: 1rem;
}

/* ── Mandate Header ──────────────────────────────────────── */
.mandate-header {
    margin-bottom: 1.5rem;
}
.mandate-header h1 {
    color: #d4af37;
    font-size: 1.5rem;
    margin-bottom: 0.3rem;
}
.mandate-header .geo-info {
    color: #b0b0b0;
    font-size: 0.9rem;
}
.mandate-header .geo-info span {
    color: #ccc;
}

/* ── Level Tabs ──────────────────────────────────────────── */
.level-tabs {
    display: flex;
    gap: 0.4rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.level-tab {
    padding: 0.45rem 1rem;
    font-size: 0.9rem;
    border: 1px solid rgba(212,175,55,0.3);
    border-radius: 6px;
    background: transparent;
    color: #b0b0b0;
    cursor: pointer;
    transition: all 0.2s;
}
.level-tab:hover {
    border-color: #d4af37;
    color: #fff;
}
.level-tab.active {
    background: rgba(212,175,55,0.2);
    border-color: #d4af37;
    color: #d4af37;
    font-weight: 600;
}

/* ── Mandate Level Picker (above textarea) ───────────────── */
.mandate-picker {
    display: flex;
    gap: 0.35rem;
    margin-bottom: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}
.mandate-picker-label {
    color: #b0b0b0;
    font-size: 0.8rem;
    margin-right: 0.3rem;
}
.mandate-picker-btn {
    padding: 0.3rem 0.7rem;
    font-size: 0.78rem;
    border: 1px solid rgba(212,175,55,0.25);
    border-radius: 5px;
    background: transparent;
    color: #b0b0b0;
    cursor: pointer;
    transition: all 0.2s;
}
.mandate-picker-btn:hover {
    border-color: #d4af37;
    color: #fff;
}
.mandate-picker-btn.active {
    background: rgba(212,175,55,0.25);
    border-color: #d4af37;
    color: #d4af37;
    font-weight: 600;
}

/* ── Public Mandate Summary (placeholder) ────────────────── */
.mandate-summary {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border: 1px solid rgba(212,175,55,0.15);
    border-radius: 12px;
    padding: 2rem;
    margin-top: 2rem;
    text-align: center;
}
.mandate-summary h2 {
    color: #d4af37;
    font-size: 1.15rem;
    margin-bottom: 0.5rem;
}
.mandate-summary p {
    color: #b0b0b0;
    font-size: 0.9rem;
}

/* ── TTS Button ──────────────────────────────────────────── */
.tts-btn {
    background: none;
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 4px;
    color: #b0b0b0;
    cursor: pointer;
    padding: 2px 6px;
    font-size: 0.85rem;
    transition: all 0.2s;
}
.tts-btn:hover {
    border-color: #d4af37;
    color: #d4af37;
}
CSS;

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/nav.php';
?>

<?php if (!$isLoggedIn): ?>
<!-- ════════════════════════════════════════════════════════════
     NOT LOGGED IN — Phone Login
     ════════════════════════════════════════════════════════════ -->
<div class="mandate-wrap">
    <div class="login-card" id="loginCard">
        <h1>My Mandate</h1>
        <p class="login-sub">Enter your verified phone number to access your mandate.</p>

        <div id="loginForm">
            <div class="digit-row" id="digitRow">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="0" aria-label="Digit 1" autocomplete="off">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="1" aria-label="Digit 2">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="2" aria-label="Digit 3">
                <span class="digit-dash">-</span>
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="3" aria-label="Digit 4">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="4" aria-label="Digit 5">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="5" aria-label="Digit 6">
                <span class="digit-dash">-</span>
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="6" aria-label="Digit 7">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="7" aria-label="Digit 8">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="8" aria-label="Digit 9">
                <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]" data-idx="9" aria-label="Digit 10">
            </div>

            <div class="name-section" id="nameSection">
                <label for="disambigName">What is your first name?</label>
                <input type="text" id="disambigName" placeholder="Enter your first name" autocomplete="given-name">
            </div>

            <button type="button" class="verify-btn" id="verifyBtn" disabled>Verify</button>
            <div class="login-status" id="loginStatus"></div>
        </div>

        <div id="lockoutMsg" class="lockout-msg" style="display:none;">
            Too many failed attempts. Please try again later.
        </div>
    </div>
</div>

<script>
(function() {
    var MAX_ATTEMPTS = 3;
    var LOCKOUT_KEY  = 'mandate_lockout';
    var LOCKOUT_MS   = 15 * 60 * 1000; // 15 minutes

    var digits   = document.querySelectorAll('#digitRow input');
    var verifyBtn = document.getElementById('verifyBtn');
    var status    = document.getElementById('loginStatus');
    var nameSection = document.getElementById('nameSection');
    var nameInput   = document.getElementById('disambigName');
    var loginForm   = document.getElementById('loginForm');
    var lockoutMsg  = document.getElementById('lockoutMsg');

    var attempts  = 0;
    var needsName = false;

    // Check lockout
    var lockUntil = parseInt(localStorage.getItem(LOCKOUT_KEY) || '0', 10);
    if (lockUntil && Date.now() < lockUntil) {
        loginForm.style.display = 'none';
        lockoutMsg.style.display = 'block';
    } else {
        localStorage.removeItem(LOCKOUT_KEY);
    }

    // ── Digit input behavior ─────────────────────────────────
    digits.forEach(function(inp, i) {
        inp.addEventListener('input', function(e) {
            var val = this.value.replace(/\D/g, '');
            this.value = val.charAt(0) || '';
            if (val && i < digits.length - 1) {
                digits[i + 1].focus();
            }
            updateVerifyState();
        });

        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace') {
                if (!this.value && i > 0) {
                    digits[i - 1].focus();
                    digits[i - 1].value = '';
                    e.preventDefault();
                }
                updateVerifyState();
            }
            // Allow paste on first digit
            if (e.key === 'v' && (e.ctrlKey || e.metaKey)) return;
            // Block non-numeric except navigation keys
            if (e.key.length === 1 && !/\d/.test(e.key)) {
                e.preventDefault();
            }
        });

        // Handle paste on any digit
        inp.addEventListener('paste', function(e) {
            e.preventDefault();
            var pasted = (e.clipboardData || window.clipboardData).getData('text');
            var nums = pasted.replace(/\D/g, '');
            for (var j = 0; j < nums.length && (i + j) < digits.length; j++) {
                digits[i + j].value = nums[j];
            }
            var lastFilled = Math.min(i + nums.length, digits.length) - 1;
            if (lastFilled >= 0) digits[Math.min(lastFilled + 1, digits.length - 1)].focus();
            updateVerifyState();
        });
    });

    function updateVerifyState() {
        var filled = 0;
        digits.forEach(function(d) { if (d.value) filled++; });
        verifyBtn.disabled = filled < 10;
    }

    function getPhone() {
        var p = '';
        digits.forEach(function(d) { p += d.value; });
        return p;
    }

    function setStatus(msg, cls) {
        status.textContent = msg;
        status.className = 'login-status ' + (cls || '');
    }

    // ── Verify button ────────────────────────────────────────
    verifyBtn.addEventListener('click', function() {
        if (verifyBtn.disabled) return;

        var phone = getPhone();
        if (phone.length < 10) {
            setStatus('Enter all 10 digits.', 'error');
            return;
        }

        verifyBtn.disabled = true;
        setStatus('Verifying...', 'info');

        var body = { phone: phone };
        if (needsName && nameInput.value.trim()) {
            body.name = nameInput.value.trim();
        }

        fetch('/api/mandate-phone-login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Save to localStorage and reload
                localStorage.setItem('mandate_phone', phone);
                localStorage.setItem('mandate_user', JSON.stringify(data.user));
                setStatus('Welcome, ' + data.user.first_name + '!', 'success');
                setTimeout(function() { location.reload(); }, 600);
                return;
            }

            attempts++;

            if (data.error === 'multiple_matches') {
                needsName = true;
                nameSection.style.display = 'block';
                nameInput.focus();
                setStatus(data.hint || 'Multiple accounts found. Enter your first name.', 'info');
                verifyBtn.disabled = false;
                return;
            }

            if (data.error === 'still_ambiguous') {
                setStatus('Still ambiguous. Contact support.', 'error');
            } else if (data.error === 'no_match') {
                setStatus('No verified account found for this number.', 'error');
            } else {
                setStatus(data.error || 'Verification failed.', 'error');
            }

            if (attempts >= MAX_ATTEMPTS) {
                var until = Date.now() + LOCKOUT_MS;
                localStorage.setItem(LOCKOUT_KEY, String(until));
                loginForm.style.display = 'none';
                lockoutMsg.style.display = 'block';
                return;
            }

            verifyBtn.disabled = false;
        })
        .catch(function() {
            setStatus('Network error. Try again.', 'error');
            verifyBtn.disabled = false;
        });
    });

    // Focus first digit on load
    if (loginForm.style.display !== 'none') {
        digits[0].focus();
    }
})();
</script>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     LOGGED IN — Mandate Interface
     ════════════════════════════════════════════════════════════ -->
<div class="mandate-wrap">

    <!-- Mandate Header -->
    <div class="mandate-header">
        <h1>My Mandate</h1>
        <p class="geo-info">
            <?php if ($userTownName): ?>
                <span><?= htmlspecialchars($userTownName) ?></span>,
            <?php endif; ?>
            <?php if ($userStateDisplay): ?>
                <span><?= htmlspecialchars($userStateDisplay) ?></span>
            <?php endif; ?>
            <?php if (!empty($dbUser['us_congress_district'])): ?>
                &mdash; District <span><?= htmlspecialchars($dbUser['us_congress_district']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <!-- Level Filter Tabs -->
    <div class="level-tabs" id="levelTabs">
        <button class="level-tab active" data-level="">All</button>
        <button class="level-tab" data-level="mandate-federal">Federal</button>
        <button class="level-tab" data-level="mandate-state">State</button>
        <button class="level-tab" data-level="mandate-town">Town</button>
    </div>

    <!-- Talk Stream -->
    <?php
    $talkStreamConfig = [
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

    <!-- ── Task 3: Mandate Category Buttons (JS inject) ────── -->
    <script>
    (function() {
        var prefix = '<?= $_tsPrefix ?? "ts0" ?>';
        var catBar = document.getElementById(prefix + '-catBar');
        if (!catBar) return;
        var mandateCats = [
            { cat: 'mandate-federal', label: 'Mandate Federal' },
            { cat: 'mandate-state',   label: 'Mandate State' },
            { cat: 'mandate-town',    label: 'Mandate Town' }
        ];
        mandateCats.forEach(function(mc) {
            var btn = document.createElement('button');
            btn.className = 'cat-btn';
            btn.dataset.cat = mc.cat;
            btn.textContent = mc.label;
            btn.onclick = function() {
                TalkStream._instances[prefix].setCategoryFilter(mc.cat);
            };
            catBar.appendChild(btn);
        });
    })();
    </script>

    <!-- ── Task 4: Mandate Level Picker on Input ───────────── -->
    <script>
    (function() {
        var prefix = '<?= $_tsPrefix ?? "ts0" ?>';
        var wrapper = document.getElementById(prefix + '-wrapper');
        if (!wrapper) return;

        var inputArea = wrapper.querySelector('.input-area');
        if (!inputArea) return;

        // Currently selected mandate level (null = private / no mandate)
        var selectedLevel = null;

        // Build picker UI
        var picker = document.createElement('div');
        picker.className = 'mandate-picker';

        var label = document.createElement('span');
        label.className = 'mandate-picker-label';
        label.textContent = 'Level:';
        picker.appendChild(label);

        var levels = [
            { value: null,               label: 'Private' },
            { value: 'mandate-federal',  label: 'Federal' },
            { value: 'mandate-state',    label: 'State' },
            { value: 'mandate-town',     label: 'Town' }
        ];

        var btns = [];
        levels.forEach(function(lv) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'mandate-picker-btn' + (lv.value === null ? ' active' : '');
            btn.textContent = lv.label;
            btn.addEventListener('click', function() {
                selectedLevel = lv.value;
                btns.forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
            });
            btns.push(btn);
            picker.appendChild(btn);
        });

        // Insert picker above the textarea row
        var inputRow = inputArea.querySelector('.input-row');
        if (inputRow) {
            inputArea.insertBefore(picker, inputRow);
        } else {
            inputArea.prepend(picker);
        }

        // ── Intercept fetch to inject category into talk/api.php POSTs ──
        var originalFetch = window.fetch;
        window.fetch = function(url, opts) {
            if (selectedLevel && typeof url === 'string' && url.indexOf('talk/api.php') !== -1 && opts && opts.method && opts.method.toUpperCase() === 'POST') {
                try {
                    var body;
                    if (opts.body instanceof FormData) {
                        opts.body.set('category', selectedLevel);
                    } else if (typeof opts.body === 'string') {
                        body = JSON.parse(opts.body);
                        body.category = selectedLevel;
                        opts.body = JSON.stringify(body);
                    }
                } catch(e) {
                    // ignore parse errors, send original
                }
            }
            return originalFetch.apply(this, arguments);
        };
    })();
    </script>

    <!-- ── Level Tabs: filter stream by mandate category ───── -->
    <script>
    (function() {
        var prefix = '<?= $_tsPrefix ?? "ts0" ?>';
        var tabs = document.querySelectorAll('#levelTabs .level-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                tabs.forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                var level = tab.dataset.level || '';
                if (TalkStream._instances[prefix]) {
                    TalkStream._instances[prefix].setCategoryFilter(level);
                }
            });
        });
    })();
    </script>

    <!-- ── Task 5: TTS Playback Button ─────────────────────── -->
    <script>
    (function() {
        var prefix = '<?= $_tsPrefix ?? "ts0" ?>';
        var streamEl = document.getElementById(prefix + '-stream');
        if (!streamEl) return;

        // Pick a preferred female US English voice
        var preferredVoice = null;
        function pickVoice() {
            var voices = window.speechSynthesis ? speechSynthesis.getVoices() : [];
            for (var i = 0; i < voices.length; i++) {
                var v = voices[i];
                if (/en.US/i.test(v.lang) && /female/i.test(v.name)) {
                    preferredVoice = v;
                    return;
                }
            }
            // Fallback: first en-US voice
            for (var j = 0; j < voices.length; j++) {
                if (/en.US/i.test(voices[j].lang)) {
                    preferredVoice = voices[j];
                    return;
                }
            }
        }
        if (window.speechSynthesis) {
            pickVoice();
            speechSynthesis.addEventListener('voiceschanged', pickVoice);
        }

        function addTtsButton(card) {
            if (card.querySelector('.tts-btn')) return; // already added

            var footer = card.querySelector('.card-footer');
            if (!footer) return;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tts-btn';
            btn.innerHTML = '&#x1F50A;'; // speaker icon
            btn.title = 'Read aloud';
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!window.speechSynthesis) return;

                speechSynthesis.cancel();

                var contentEl = card.querySelector('.card-content');
                if (!contentEl) return;

                var text = contentEl.textContent || '';
                if (!text.trim()) return;

                var utter = new SpeechSynthesisUtterance(text.trim());
                utter.lang = 'en-US';
                if (preferredVoice) utter.voice = preferredVoice;
                speechSynthesis.speak(utter);
            });
            footer.appendChild(btn);
        }

        // Observe stream for new cards
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType !== 1) return;
                    if (node.classList && node.classList.contains('idea-card')) {
                        addTtsButton(node);
                    }
                    // Also check children (batch appends)
                    var cards = node.querySelectorAll ? node.querySelectorAll('.idea-card') : [];
                    cards.forEach(addTtsButton);
                });
            });
        });
        observer.observe(streamEl, { childList: true, subtree: true });

        // Also add to any cards already present
        streamEl.querySelectorAll('.idea-card').forEach(addTtsButton);
    })();
    </script>

    <!-- Public Mandate Summary (placeholder) -->
    <div class="mandate-summary">
        <h2>Public Mandate Summary</h2>
        <p>Aggregated mandate data from your community will appear here.</p>
    </div>

</div>
<?php endif; ?>

<?php
require __DIR__ . '/includes/c-widget.php';
require __DIR__ . '/includes/footer.php';
?>
</body>
</html>
