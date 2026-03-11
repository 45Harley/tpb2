// =====================================================
// CLAUDIA MODULE: Onboarding
// Handles canned responses + on-script flow for new users
// =====================================================
(function() {
    'use strict';

    // ----- Canned responses -----
    var CANNED = {
        welcome: "Welcome to The People's Branch! You're part of the Fourth Branch of government now. Go ahead and find your state on the map.",
        welcome_back: function(data) {
            var parts = ["Welcome back"];
            if (data.townName && data.stateAbbr) parts[0] += " from " + data.townName + ", " + data.stateAbbr;
            else if (data.stateAbbr) parts[0] += " from " + data.stateAbbr;
            parts[0] += "!";
            parts.push("You can ask me anything — about your representatives, local issues, or how TPB works.");
            return parts.join(' ');
        },
        state_click: function(data) { return (data.stateName || data.stateCode) + "! If that's your state, click 'This is My State' and we'll zoom in to find your location."; },
        set_my_state: function(data) {
            var name = data.stateName || data.stateCode || 'your state';
            return "Let's find your exact spot in " + name + ". You can type your town name or just drop a pin on the map.";
        },
        gmap_ready: function(data) { return "The map is ready. Type a town name or click anywhere to drop a pin."; },
        pin_resolved: function(data) {
            // Google's formatted_address already includes town/state/zip — use it directly
            if (data.address) return "I see " + data.address + ". Does that look right?";
            // Fallback if no formatted address
            var parts = [];
            if (data.town_name) parts.push(data.town_name);
            if (data.state_code) parts.push(data.state_code);
            if (data.zip_code) parts.push(data.zip_code);
            return parts.length ? "I see " + parts.join(', ') + ". Does that look right?" : "I found your location. Does that look right?";
        },
        districts_resolved: function(data) {
            var d = [];
            if (data.us_congress_district && data.us_congress_district !== '—' && data.us_congress_district !== '') d.push("US Congress " + data.us_congress_district);
            if (data.state_senate_district && data.state_senate_district !== '—' && data.state_senate_district !== '') d.push("State Senate " + data.state_senate_district);
            if (data.state_house_district && data.state_house_district !== '—' && data.state_house_district !== '') d.push("State House " + data.state_house_district);
            if (d.length) return "Your districts: " + d.join(', ') + ". Does everything look right?";
            return "I couldn't find specific district info for your location, but that's okay — we can sort that out later. Ready to create your account?";
        },
        create_account: "Great! To make your voice count, I just need your email. Click the button to continue to sign up.",
        join_page: function(data) {
            if (data && data.town_name && data.state_code) {
                return "Almost there! I have you in " + data.town_name + ", " + data.state_code + ". Just enter your email below and I'll send you a verification link.";
            }
            return "Welcome! Enter your email below to get started. One email, one identity — I'll send you a quick verification link.";
        },
        address_confirmed: "Great! To make your voice count, I just need your email. I'll send you a quick verification link.",
        email_sent: "Check your inbox for a message from TPB. If you don't see it, check your spam folder. Still nothing? You might have a typo — no worries, we can try again.",
        verified_return: function(data) { return "You're verified! I have you at " + (data.address || 'your location') + ". What should people call you?"; },
        name_confirm: function(name) { return name + " — did I get that right?"; },
        name_wrong: "No problem! What's the correct name?",
        welcome_aboard: function(name) { return "Welcome aboard, " + name + "! You're all set. You can now vote on ideas and share your thoughts with your community."; },
        address_wrong: "No worries — try dropping a new pin on the map and I'll confirm the address."
    };

    // ----- Onboarding steps (for canHandle) -----
    var ONBOARDING_STEPS = {
        welcome: true, state_click: true, set_my_state: true, gmap_ready: true,
        pin_resolved: true, districts_resolved: true, create_account: true,
        address_confirmed: true, email_sent: true, verified_return: true,
        name_confirm: true, name_wrong: true, welcome_aboard: true,
        address_wrong: true, awaiting_name: true, awaiting_pin: true
    };

    function extractName(history) {
        for (var i = history.length - 1; i >= 0; i--) {
            if (history[i].role === 'user') {
                var t = history[i].content.trim();
                if (t.length < 80 && !/^(yes|no|yeah|nope|ok|okay|sure|correct|right|wrong|nah)$/i.test(t)) {
                    return t;
                }
            }
        }
        return 'friend';
    }

    // ----- Module definition -----
    window.ClaudiaModules = window.ClaudiaModules || {};
    window.ClaudiaModules.onboarding = {

        canHandle: function(text, flowState) {
            if (!ONBOARDING_STEPS[flowState.step]) return false;
            // If user is off-script, let core handle it (live API)
            if (window.ClaudiaCore && window.ClaudiaCore.isOffScript(text, flowState.step)) return false;
            return true;
        },

        handle: function(text, flowState, core) {
            var t = text.toLowerCase().replace(/[?.!]+$/, '').trim();

            if (/^(yes|yeah|yep|yup|correct|right|ok|okay|sure|that's right|sounds good|looks good|that works|perfect)$/.test(t)) {
                // User confirmed — advance to next step
                if (flowState.step === 'pin_resolved' || flowState.step === 'districts_resolved') {
                    this.cannedRespond('create_account', null, core);
                } else if (flowState.step === 'verified_return') {
                    core.addMessage("Great! So what should people call you?", 'c');
                    core.speak("Great! So what should people call you?");
                    flowState.step = 'awaiting_name';
                } else if (flowState.step === 'name_confirm') {
                    var name = flowState.userName || extractName([]);
                    this.cannedRespond('welcome_aboard', name, core);
                } else if (flowState.step === 'gmap_ready' || flowState.step === 'set_my_state' || flowState.step === 'awaiting_pin') {
                    var msg = "Use the map to find your spot — search or click to drop a pin!";
                    core.addMessage(msg, 'c');
                    core.speak(msg);
                } else {
                    core.liveRespond(text);
                }
            } else if (/^(no|nope|nah|wrong)$/.test(t)) {
                if (flowState.step === 'pin_resolved' || flowState.step === 'districts_resolved' || flowState.step === 'verified_return') {
                    this.cannedRespond('address_wrong', null, core);
                } else if (flowState.step === 'name_confirm') {
                    flowState.userName = null;
                    this.cannedRespond('name_wrong', null, core);
                } else if (flowState.step === 'gmap_ready' || flowState.step === 'set_my_state' || flowState.step === 'awaiting_pin') {
                    var msg = "No worries! Just use the map to find your location when you're ready.";
                    core.addMessage(msg, 'c');
                    core.speak(msg);
                } else {
                    core.liveRespond(text);
                }
            } else {
                // Could be a name or address — context-dependent
                if (flowState.step === 'name_wrong' || flowState.step === 'verified_return' || flowState.step === 'awaiting_name') {
                    flowState.userName = text;
                    this.cannedRespond('name_confirm', text, core);
                } else if (flowState.step === 'address_wrong') {
                    core.addMessage("Got it — try dropping a pin near " + text + " on the map, and I'll confirm the exact address.", 'c');
                    core.speak("Got it — try dropping a pin near " + text + " on the map, and I'll confirm the exact address.");
                    flowState.step = 'awaiting_pin';
                } else if (flowState.step === 'gmap_ready' || flowState.step === 'set_my_state' || flowState.step === 'awaiting_pin') {
                    var msg = "Try typing that in the search box on the map, or just click the map to drop a pin!";
                    core.addMessage(msg, 'c');
                    core.speak(msg);
                } else {
                    core.liveRespond(text);
                }
            }
        },

        cannedRespond: function(eventType, data, core) {
            var response = CANNED[eventType];
            if (!response) return;

            var text = typeof response === 'function' ? response(data || {}) : response;
            if (!text) return;

            core.addMessage(text, 'c');
            core.speak(text);

            var flowState = core.getFlowState();
            flowState.step = eventType;

            // Track additional state based on event type
            if (eventType === 'pin_resolved' && data) {
                flowState.pinData = data;
                flowState.confirmedState = data.state_code || null;
                flowState.confirmedTown = data.town_name || null;
            }
            if (eventType === 'districts_resolved') {
                flowState.districtsShown = true;
            }
            if (eventType === 'address_confirmed' || (eventType === 'create_account' && flowState.pinData)) {
                flowState.confirmedAddress = flowState.pinData ? flowState.pinData.address : null;
            }
            if (eventType === 'name_confirm' && typeof data === 'string') {
                flowState.userName = data;
            }
            if (eventType === 'welcome_aboard' && typeof data === 'string') {
                flowState.userName = data;
            }
        }
    };

})();
