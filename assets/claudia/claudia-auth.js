// =====================================================
// CLAUDIA AUTH MODULE — Conversational Login
// =====================================================
// Handles:
//   - Recognizing returning users (cookie/CONFIG)
//   - Conversational login ("log me in" → phone → verified)
//   - Auth-aware greetings
//
// Registers as window.ClaudiaModules.auth
// Reads window.ClaudiaConfig for user data
// Uses api/mandate-phone-verify.php (sets cookies server-side)
// =====================================================
(function() {
    'use strict';

    var CONFIG = window.ClaudiaConfig || {};

    // ----- Regex patterns -----
    var LOGIN_PATTERN  = /\b(log\s*in|sign\s*in|login)\b/i;
    var LOGOUT_PATTERN = /\b(log\s*out|sign\s*out|logout)\b/i;
    var PHONE_PATTERN  = /[\d\s\-().+]{7,}/;
    var CODE_PATTERN   = /^\d{4,8}$/;

    // ----- Canned responses -----
    var canned = {
        welcome_back: function() {
            var u = CONFIG.user;
            if (!u) return "Welcome back! What can I help with?";
            var parts = ["Welcome back"];
            if (u.firstName) parts[0] += ", " + u.firstName;
            parts[0] += "!";
            if (u.townName && u.stateAbbr) {
                var stateFull = window.ClaudiaUtils ? window.ClaudiaUtils.expandState(u.stateAbbr) : u.stateAbbr;
                parts.push("You're in " + u.townName + ", " + stateFull + ".");
            }
            parts.push("What can I help with?");
            return parts.join(' ');
        },
        welcome_anonymous: function() {
            return "Hi! I'm Claudia, your civic guide. Want to log in, or just look around?";
        }
    };

    // ----- Internal state -----
    var loginFlow = {
        phone: null,
        name: null
    };

    // ----- Phone formatting -----
    function extractDigits(text) {
        return text.replace(/\D/g, '');
    }

    function formatPhone(digits) {
        if (digits.length === 11 && digits[0] === '1') digits = digits.substring(1);
        if (digits.length === 10) {
            return '(' + digits.substring(0, 3) + ') ' + digits.substring(3, 6) + '-' + digits.substring(6);
        }
        return digits;
    }

    // ----- Handlers -----

    function handleLogin(text, flowState, core) {
        core.addMessage("Sure! What's your phone number? I'll look you up.", 'c');
        core.speak("Sure! What's your phone number? I'll look you up.");
        flowState.step = 'auth_phone';
        loginFlow.phone = null;
        loginFlow.name = null;
    }

    function handlePhoneInput(text, flowState, core) {
        var digits = extractDigits(text);

        // Strip leading country code 1
        if (digits.length === 11 && digits[0] === '1') {
            digits = digits.substring(1);
        }

        if (digits.length < 10) {
            var msg = "That doesn't look like a valid phone number. Please enter your 10-digit number.";
            core.addMessage(msg, 'c');
            core.speak(msg);
            return;
        }

        loginFlow.phone = digits;
        var display = formatPhone(digits);

        core.addMessage("Looking up " + display + "...", 'c');
        core.speak("Looking you up.");
        flowState.step = 'auth_verifying';

        fetch('/api/mandate-phone-verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: loginFlow.phone, name: loginFlow.name })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.user) {
                onLoginSuccess(data.user, flowState, core);
            } else if (data.error === 'multiple_matches') {
                var msg = "I found " + data.count + " accounts with that number. " + (data.hint || "What's your first name?");
                core.addMessage(msg, 'c');
                core.speak(msg);
                flowState.step = 'auth_name';
            } else if (data.error === 'still_ambiguous') {
                var msg = "I still can't narrow it down. Try logging in through the website, or double-check your phone number.";
                core.addMessage(msg, 'c');
                core.speak(msg);
                flowState.step = 'welcome';
            } else if (data.error === 'no_match') {
                var msg = "I don't have a verified account for that number. You can create an account by finding your location on the map!";
                core.addMessage(msg, 'c');
                core.speak(msg);
                flowState.step = 'welcome';
            } else {
                var msg = "Something went wrong looking you up. Try again in a moment.";
                core.addMessage(msg, 'system');
                flowState.step = 'welcome';
            }
        })
        .catch(function() {
            core.addMessage("Sorry, I couldn't connect. Try again in a moment.", 'system');
            flowState.step = 'welcome';
        });
    }

    function handleNameInput(text, flowState, core) {
        loginFlow.name = text.trim();
        core.addMessage("Checking for " + loginFlow.name + "...", 'c');
        core.speak("Checking.");
        flowState.step = 'auth_verifying';

        fetch('/api/mandate-phone-verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: loginFlow.phone, name: loginFlow.name })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.user) {
                onLoginSuccess(data.user, flowState, core);
            } else if (data.error === 'still_ambiguous') {
                var msg = "I still can't narrow it down. Try logging in through the website instead.";
                core.addMessage(msg, 'c');
                core.speak(msg);
                flowState.step = 'welcome';
            } else if (data.error === 'no_match') {
                var msg = "No match for that name and number. Want to try a different name, or a different number?";
                core.addMessage(msg, 'c');
                core.speak(msg);
                flowState.step = 'auth_phone';
                loginFlow.phone = null;
                loginFlow.name = null;
            } else {
                var msg = "Something went wrong. Try again in a moment.";
                core.addMessage(msg, 'system');
                flowState.step = 'welcome';
            }
        })
        .catch(function() {
            core.addMessage("Sorry, I couldn't connect. Try again in a moment.", 'system');
            flowState.step = 'welcome';
        });
    }

    function onLoginSuccess(user, flowState, core) {
        // Update CONFIG so rest of Claudia knows we're logged in
        CONFIG.user = {
            userId: user.user_id,
            firstName: user.first_name,
            stateAbbr: user.state_abbr,
            townName: user.town_name,
            district: user.district,
            isReturning: true
        };
        window.ClaudiaConfig = CONFIG;

        // Update flowState
        flowState.step = 'returning';
        flowState.confirmedState = user.state_abbr || null;
        flowState.confirmedTown = user.town_name || null;
        flowState.userName = user.first_name || null;

        // Greet
        var greeting = canned.welcome_back();
        core.addMessage(greeting, 'c');
        core.speak(greeting);

        // Update nav if possible (page may show login/points)
        if (window.tpbUpdateNavPoints) {
            window.tpbUpdateNavPoints();
        }

        // Reset login flow
        loginFlow.phone = null;
        loginFlow.name = null;
    }

    function handleLogout(text, flowState, core) {
        // Clear cookies
        document.cookie = 'tpb_civic_session=; path=/; max-age=0';
        document.cookie = 'tpb_user_id=; path=/; max-age=0';
        document.cookie = 'tpb_email_verified=; path=/; max-age=0';

        // Clear CONFIG
        CONFIG.user = null;
        window.ClaudiaConfig = CONFIG;

        // Reset flow
        flowState.step = 'welcome';
        flowState.userName = null;
        flowState.confirmedState = null;
        flowState.confirmedTown = null;

        var msg = "You're logged out. See you next time! You can still look around.";
        core.addMessage(msg, 'c');
        core.speak(msg);
    }

    // ----- Module interface -----

    window.ClaudiaModules = window.ClaudiaModules || {};
    window.ClaudiaModules.auth = {
        commands: {
            'login': handleLogin,
            'logout': handleLogout
        },
        canned: canned,

        canHandle: function(text, flowState) {
            // During active login flow steps
            if (flowState.step === 'auth_phone' || flowState.step === 'auth_name') {
                return true;
            }
            // Login/logout commands
            if (LOGIN_PATTERN.test(text)) return true;
            if (LOGOUT_PATTERN.test(text)) return true;
            return false;
        },

        handle: function(text, flowState, core) {
            // Logout
            if (LOGOUT_PATTERN.test(text)) {
                handleLogout(text, flowState, core);
                return;
            }

            // Login command
            if (LOGIN_PATTERN.test(text) && flowState.step !== 'auth_phone' && flowState.step !== 'auth_name') {
                // If already logged in, say so
                if (CONFIG.user) {
                    var msg = "You're already logged in" + (CONFIG.user.firstName ? ", " + CONFIG.user.firstName : "") + "! Need anything else?";
                    core.addMessage(msg, 'c');
                    core.speak(msg);
                    return;
                }
                handleLogin(text, flowState, core);
                return;
            }

            // Waiting for phone number
            if (flowState.step === 'auth_phone') {
                handlePhoneInput(text, flowState, core);
                return;
            }

            // Waiting for disambiguation name
            if (flowState.step === 'auth_name') {
                handleNameInput(text, flowState, core);
                return;
            }
        },

        getGreeting: function() {
            if (CONFIG.user && CONFIG.user.isReturning) {
                return canned.welcome_back();
            }
            return canned.welcome_anonymous();
        }
    };

})();
