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

$headLinks = ''; // mandate-chat.php loads its own assets

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
.mandate-summary h2,
.mandate-summary h3 {
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

    <!-- Mandate Chat -->
    <?php
    $mandateChatConfig = [
        'placeholder' => "What do you want your reps to do?",
    ];
    require __DIR__ . '/includes/mandate-chat.php';
    ?>

    <!-- Public Mandate Summary -->
    <div class="mandate-summary" id="mandateSummary">
        <h3 id="mandateSummaryTitle">Public Mandate Summary</h3>
        <div id="mandateSummaryBody">
            <p>Loading mandate data...</p>
        </div>
    </div>

    <!-- ── Task 7: Wire Public Summary to Aggregation API ──── -->
    <script>
    (function() {
        var userDistrict = <?= json_encode($dbUser['us_congress_district'] ?? null) ?>;
        var userStateId  = <?= json_encode($userStateId ?: null) ?>;
        var userTownId   = <?= json_encode($userTownId ?: null) ?>;
        var userTownName = <?= json_encode($userTownName ?: '') ?>;
        var userStateName = <?= json_encode($userStateDisplay ?: '') ?>;

        var titleEl = document.getElementById('mandateSummaryTitle');
        var bodyEl  = document.getElementById('mandateSummaryBody');

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function buildUrl(level) {
            var base = '/api/mandate-aggregate.php?level=' + encodeURIComponent(level);
            switch (level) {
                case 'federal':
                    if (userDistrict) base += '&district=' + encodeURIComponent(userDistrict);
                    break;
                case 'state':
                    if (userStateId) base += '&state_id=' + encodeURIComponent(userStateId);
                    break;
                case 'town':
                    if (userTownId) base += '&town_id=' + encodeURIComponent(userTownId);
                    break;
            }
            return base;
        }

        function buildTitle(level) {
            switch (level) {
                case 'federal':
                    return userDistrict
                        ? 'Constituent Mandate for ' + escapeHtml(userDistrict)
                        : 'Constituent Mandate (Federal)';
                case 'state':
                    return userStateName
                        ? 'Constituent Mandate for ' + escapeHtml(userStateName)
                        : 'Constituent Mandate (State)';
                case 'town':
                    return userTownName
                        ? 'Constituent Mandate for ' + escapeHtml(userTownName)
                        : 'Constituent Mandate (Town)';
                default:
                    return 'Public Mandate Summary';
            }
        }

        function loadSummary(level) {
            titleEl.innerHTML = buildTitle(level);
            bodyEl.innerHTML = '<p style="color:#b0b0b0;">Loading...</p>';

            fetch(buildUrl(level))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || data.item_count === 0) {
                        bodyEl.innerHTML = '<p style="color:#b0b0b0;">No mandate items yet for this scope.</p>';
                        return;
                    }

                    var html = '<p style="color:#81c784; font-size:0.95rem; margin-bottom:0.75rem;">'
                        + data.contributor_count + ' constituent'
                        + (data.contributor_count !== 1 ? 's' : '')
                        + ' ha' + (data.contributor_count !== 1 ? 've' : 's')
                        + ' spoken.</p>';

                    html += '<ol style="text-align:left; padding-left:1.5rem; margin:0;">';
                    data.items.forEach(function(item) {
                        html += '<li style="color:#ccc; margin-bottom:0.5rem;">'
                            + escapeHtml(item.content);
                        if (item.tags) {
                            html += ' <span style="color:#999; font-size:0.85rem;">('
                                + escapeHtml(item.tags) + ')</span>';
                        }
                        html += '</li>';
                    });
                    html += '</ol>';

                    bodyEl.innerHTML = html;
                })
                .catch(function() {
                    bodyEl.innerHTML = '<p style="color:#e63946;">Failed to load mandate summary.</p>';
                });
        }

        // Expose so MandateChat can refresh after saving
        window.refreshMandateSummary = loadSummary;

        // ── Initial load: federal level ────────────────────────
        var initialLevel = userDistrict ? 'federal' : (userStateId ? 'state' : (userTownId ? 'town' : 'federal'));
        loadSummary(initialLevel);

        // ── Extend tab click handlers to also update summary ───
        var tabs = document.querySelectorAll('#levelTabs .level-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var dataLevel = tab.dataset.level || '';
                var summaryLevel;
                switch (dataLevel) {
                    case 'mandate-federal':
                        summaryLevel = 'federal';
                        break;
                    case 'mandate-state':
                        summaryLevel = 'state';
                        break;
                    case 'mandate-town':
                        summaryLevel = 'town';
                        break;
                    default:
                        // "All" tab — default to federal
                        summaryLevel = userDistrict ? 'federal' : (userStateId ? 'state' : 'town');
                        break;
                }
                loadSummary(summaryLevel);
            });
        });
    })();
    </script>

</div>
<?php endif; ?>

<?php
require __DIR__ . '/includes/c-widget.php';
?>

<!-- Voice commands are now handled by MandateChat.handleCommand() in mandate-chat.js -->

<?php
require __DIR__ . '/includes/footer.php';
?>
</body>
</html>
