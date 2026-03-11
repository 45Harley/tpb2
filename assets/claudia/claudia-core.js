// =====================================================
// C WIDGET — Claudia, Your Civic Guide
// =====================================================
(function() {
    'use strict';

    // ----- Module registry -----
    window.ClaudiaModules = window.ClaudiaModules || {};

    // ----- Config (injected by PHP) -----
    var CONFIG = window.ClaudiaConfig || {
        context: 'general',
        capabilities: ['auth'],
        events: false,
        user: null,
        siteEnabled: true
    };
    var IS_POPOUT = !!CONFIG.isPopout;

    // Anonymous user toggle (localStorage)
    if (!CONFIG.user) {
        var localEnabled = localStorage.getItem('tpb_claudia_enabled');
        if (localEnabled === '0') return;
    }

    // ----- Flow state -----
    var mode = localStorage.getItem('tpb_c_mode') || null;
    var history = [];
    var flowState = {
        step: 'welcome',
        confirmedAddress: null,
        confirmedState: null,
        confirmedTown: null,
        districtsShown: false,
        userName: null,
        pinData: null,
        apiInFlight: false
    };
    var isExpanded = false;
    var _voice = null;
    var recognition = null;
    var isRecording = false;
    var settingsOpen = false;
    var thinkingTimer = null;
    var lastEvent = { type: null, time: 0 };

    // ----- DOM refs -----
    var widget = document.getElementById('claudia-widget');
    var bubble = document.getElementById('claudia-bubble');
    var panel = document.getElementById('claudia-panel');
    var overlay = document.getElementById('claudia-mode-overlay');
    var messagesEl = document.getElementById('claudia-messages');
    var typingEl = document.getElementById('claudia-typing');
    var textInput = document.getElementById('claudia-text-input');
    var sendBtn = document.getElementById('claudia-send-btn');
    var micBtn = document.getElementById('claudia-mic-btn');
    var minimizeBtn = document.getElementById('claudia-minimize-btn');
    var settingsBtn = document.getElementById('claudia-settings-btn');
    var settingsMenu = document.getElementById('claudia-settings-menu');
    var modeDismiss = document.getElementById('claudia-mode-dismiss');

    // ----- Session ID -----
    var sessionId = localStorage.getItem('tpb_civic_session') || 'guest_' + Date.now();

    // =====================================================
    // INPUT ENABLE / DISABLE
    // =====================================================
    function disableInput() {
        if (textInput) textInput.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        if (micBtn) micBtn.disabled = true;
    }

    function enableInput() {
        if (textInput) textInput.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        if (micBtn) micBtn.disabled = false;
        if (textInput) textInput.focus();
    }

    // =====================================================
    // VOICE: TTS
    // =====================================================
    function getVoice() {
        if (_voice) return _voice;
        var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
        // Prefer female US English voice — match by known female voice names
        var femaleNames = /zira|eva|samantha|victoria|karen|fiona|moira|tessa|female/i;
        _voice = voices.find(function(v) { return v.lang.startsWith('en') && femaleNames.test(v.name); })
            || voices.find(function(v) { return v.lang === 'en-US' || v.lang.startsWith('en-US'); })
            || voices.find(function(v) { return v.lang.startsWith('en'); })
            || voices[0] || null;
        return _voice;
    }

    function speak(text) {
        if (mode === 'text') return;
        if (!window.speechSynthesis) return;
        // Cancel any in-progress speech + brief delay for browser to fully stop
        window.speechSynthesis.cancel();

        setTimeout(function() {
            var utterance = new SpeechSynthesisUtterance(text);
            var v = getVoice();
            if (v) utterance.voice = v;
            utterance.rate = 1.0;
            utterance.pitch = 1.0;
            utterance.lang = 'en-US';
            utterance.onend = function() {
                if (mode === 'voice' || mode === 'both') {
                    // Auto-listen after C speaks in voice modes
                    // Small delay so mic doesn't pick up tail end
                    setTimeout(startListening, 300);
                }
            };
            window.speechSynthesis.speak(utterance);
        }, 150);
    }

    // Voices may load async
    if (window.speechSynthesis) {
        window.speechSynthesis.onvoiceschanged = function() { _voice = null; getVoice(); };
    }

    // =====================================================
    // VOICE: STT
    // =====================================================
    function initRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) {
            micBtn.style.display = 'none';
            return;
        }
        recognition = new SR();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.lang = 'en-US';

        recognition.onresult = function(e) {
            var transcript = e.results[0][0].transcript;
            textInput.value = transcript;
            stopListeningUI();
            handleUserInput(transcript);
        };
        recognition.onerror = function() { stopListeningUI(); };
        recognition.onend = function() { stopListeningUI(); };
    }

    function startListening() {
        if (mode === 'text') return;
        if (!recognition) return;
        if (isRecording) return;
        try {
            recognition.start();
            isRecording = true;
            micBtn.classList.add('recording');
        } catch(e) { /* already started */ }
    }

    function stopListeningUI() {
        isRecording = false;
        micBtn.classList.remove('recording');
    }

    // =====================================================
    // MESSAGES
    // =====================================================
    function addMessage(text, role) {
        if (!text) return;
        var div = document.createElement('div');
        div.className = 'claudia-msg ' + role;
        var escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        div.innerHTML = escaped.replace(/\n/g, '<br>');
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function showTyping() {
        typingEl.textContent = '';
        typingEl.innerHTML = 'Claudia is thinking<span>.</span><span>.</span><span>.</span>';
        typingEl.classList.add('show');
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
    function hideTyping() {
        typingEl.classList.remove('show');
        if (thinkingTimer) { clearTimeout(thinkingTimer); thinkingTimer = null; }
    }

    // =====================================================
    // LIVE RESPONSE (API)
    // =====================================================
    function liveRespond(userMessage) {
        flowState.apiInFlight = true;
        disableInput();
        showTyping();
        history.push({ role: 'user', content: userMessage });

        // 5-second "still thinking" notice
        thinkingTimer = setTimeout(function() {
            typingEl.textContent = '';
            typingEl.innerHTML = 'Still thinking, one moment<span>.</span><span>.</span><span>.</span>';
        }, 5000);

        var pageContext = {};
        if (window.tpbPageState) {
            pageContext = window.tpbPageState.gmapLocationData || {};
        }
        // Include widget flow state so API has full context
        pageContext.widgetFlowState = {
            step: flowState.step,
            confirmedAddress: flowState.confirmedAddress,
            confirmedState: flowState.confirmedState,
            confirmedTown: flowState.confirmedTown,
            districtsShown: flowState.districtsShown,
            userName: flowState.userName
        };

        fetch('/api/claude-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: userMessage,
                history: history.slice(-10),
                user_id: (CONFIG.user ? CONFIG.user.userId : null) || ((window.tpbPageState && window.tpbPageState.USER) ? window.tpbPageState.USER.userId : null),
                session_id: sessionId,
                clerk: 'guide',
                page_context: JSON.stringify(pageContext)
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideTyping();
            flowState.apiInFlight = false;
            enableInput();
            if (data.response) {
                addMessage(data.response, 'c');
                speak(data.response);
                history.push({ role: 'assistant', content: data.response });
                if (data.actions && data.actions.length > 0) {
                    handleApiActions(data.actions);
                }
            } else if (data.error) {
                addMessage('Sorry, I had a hiccup: ' + data.error, 'system');
            }
        })
        .catch(function() {
            hideTyping();
            flowState.apiInFlight = false;
            enableInput();
            addMessage("Sorry, I couldn't connect. Try again in a moment.", 'system');
        });
    }

    // =====================================================
    // API ACTION RESULTS
    // =====================================================
    function handleApiActions(actions) {
        for (var i = 0; i < actions.length; i++) {
            var action = actions[i];
            var msg = '';
            if (action.success) {
                if (action.message) {
                    msg = action.message;
                } else if (action.action === 'ADD_THOUGHT' && action.thought_id) {
                    msg = 'Thought #' + action.thought_id + ' submitted!';
                }
            } else if (action.error) {
                msg = action.error;
            }
            if (msg) {
                addMessage(msg, 'system');
            }
        }
    }

    // =====================================================
    // INPUT HANDLING
    // =====================================================
    function isOffScript(text, step) {
        var t = text.trim().toLowerCase();

        // Strip trailing punctuation for classification
        var tClean = t.replace(/[?.!]+$/, '').trim();

        // Short confirmations — always on-script (even with trailing ?)
        if (/^(yes|yeah|yep|yup|no|nope|nah|correct|right|ok|okay|sure|that's right|sounds good|looks good|that works|perfect)$/.test(tClean)) {
            return false;
        }

        // During name input steps, almost everything is on-script
        if (step === 'name_wrong' || step === 'verified_return' || step === 'awaiting_name') {
            // Only route to live if clearly a question
            if (t.indexOf('?') !== -1 && /^(what|why|how|who|where|when|can|does|is|are|do|will|should|could|would)\b/.test(tClean)) {
                return true;
            }
            if (t.length <= 80) return false;
        }

        // During address correction, treat input as address
        if (step === 'address_wrong') {
            if (t.length <= 120 && t.indexOf('?') === -1) return false;
        }

        // During pin/district confirmation, stay on-script for short input
        if (step === 'pin_resolved' || step === 'districts_resolved') {
            if (t.length <= 30 && t.indexOf('?') === -1) return false;
        }

        // Question word + question mark = off-script
        if (t.indexOf('?') !== -1 && /^(what|why|how|who|where|when|can|does|is|are|do|will|should|could|would)\b/.test(tClean)) {
            return true;
        }
        // Short text with ? only — not off-script (e.g. "sure?", "ok?")
        if (t.indexOf('?') !== -1 && t.length <= 15) return false;
        // Longer text with ? — off-script
        if (t.indexOf('?') !== -1) return true;

        // Question word without ? in longer text
        if (/^(what|why|how|who|where|when|can|does|is|are|do|will|should|could|would)\b/.test(t) && t.length > 30) return true;

        // Very long messages
        if (t.length > 80) return true;

        return false;
    }

    function handleUserInput(text) {
        text = text.trim();
        if (!text) return;
        if (flowState.apiInFlight) return;

        addMessage(text, 'user');
        textInput.value = '';

        // Check active modules first
        var caps = CONFIG.capabilities || [];
        for (var i = 0; i < caps.length; i++) {
            var mod = window.ClaudiaModules[caps[i]];
            if (mod && mod.canHandle && mod.canHandle(text, flowState)) {
                mod.handle(text, flowState, { addMessage: addMessage, speak: speak, liveRespond: liveRespond, getFlowState: function() { return flowState; } });
                return;
            }
        }

        // Check onboarding module
        var onboard = window.ClaudiaModules.onboarding;
        if (onboard && onboard.canHandle && onboard.canHandle(text, flowState)) {
            onboard.handle(text, flowState, { addMessage: addMessage, speak: speak, liveRespond: liveRespond, getFlowState: function() { return flowState; } });
            return;
        }

        // Fallback — send to live API
        liveRespond(text);
    }

    // =====================================================
    // PAGE EVENT HANDLER
    // =====================================================
    function onPageEvent(eventType, data) {
        // Debounce: skip duplicate events within 1 second
        var now = Date.now();
        if (eventType === lastEvent.type && now - lastEvent.time < 1000) return;
        lastEvent = { type: eventType, time: now };

        // Auto-expand widget on significant events
        if (!isExpanded && eventType !== 'page_load') {
            expand();
        }

        // Reset flow state on new pin drop (user changed location)
        if (eventType === 'pin_resolved') {
            flowState.confirmedAddress = null;
            flowState.confirmedTown = null;
            flowState.districtsShown = false;
        }

        // For returning users, skip the new-user welcome on page_load
        if (eventType === 'page_load' && flowState.step === 'returning') {
            return; // Don't auto-fire welcome — let user click bubble
        }

        // Delegate to onboarding module if loaded
        var onboard = window.ClaudiaModules.onboarding;
        if (onboard && onboard.cannedRespond) {
            onboard.cannedRespond(eventType, data, window.ClaudiaCore);
            return;
        }

        // Fallback (no onboarding module loaded) — send to live API
        liveRespond(eventType + ': ' + JSON.stringify(data || {}));
    }

    // =====================================================
    // EXPAND / MINIMIZE
    // =====================================================
    function expand() {
        isExpanded = true;
        if (bubble) bubble.classList.add('hidden');
        if (panel) panel.classList.add('open');
        if (textInput) textInput.focus();
    }

    function minimize() {
        isExpanded = false;
        if (panel) panel.classList.remove('open');
        if (bubble) bubble.classList.remove('hidden');
        if (window.speechSynthesis) window.speechSynthesis.cancel();
    }

    // =====================================================
    // MODE PICKER
    // =====================================================
    function showModePicker() {
        if (overlay) overlay.classList.add('show');
    }

    function dismissModePicker() {
        if (overlay) overlay.classList.remove('show');
    }

    function setMode(newMode) {
        mode = newMode;
        localStorage.setItem('tpb_c_mode', mode);
        if (overlay) overlay.classList.remove('show');

        // Hide mic button in text mode
        if (mode === 'text') {
            micBtn.style.display = 'none';
        } else {
            micBtn.style.display = '';
        }

        // Start the welcome
        expand();

        // Auth-aware greeting
        var authMod = window.ClaudiaModules.auth;
        if (authMod && authMod.getGreeting) {
            var greeting = authMod.getGreeting();
            if (greeting) {
                addMessage(greeting, 'c');
                speak(greeting);
                history.push({ role: 'assistant', content: greeting });
                return;
            }
        }
        // Use onboarding module for welcome
        var onboard = window.ClaudiaModules.onboarding;
        if (onboard && onboard.cannedRespond) {
            onboard.cannedRespond('welcome', null, window.ClaudiaCore);
        }
    }

    // Mode picker button handlers
    if (overlay) {
        var modeButtons = overlay.querySelectorAll('.claudia-mode-btn');
        for (var i = 0; i < modeButtons.length; i++) {
            modeButtons[i].addEventListener('click', function() {
                setMode(this.getAttribute('data-mode'));
            });
        }

        // Dismiss mode picker: click outside the card
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                dismissModePicker();
            }
        });
    }

    // Dismiss mode picker: "Maybe later" button
    if (modeDismiss) {
        modeDismiss.addEventListener('click', function() {
            dismissModePicker();
        });
    }

    // =====================================================
    // SETTINGS
    // =====================================================
    if (settingsBtn) {
        settingsBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            settingsOpen = !settingsOpen;
            if (settingsMenu) settingsMenu.classList.toggle('show', settingsOpen);
        });
    }

    document.addEventListener('click', function() {
        settingsOpen = false;
        if (settingsMenu) settingsMenu.classList.remove('show');
    });

    var settingsItems = settingsMenu ? settingsMenu.querySelectorAll('.claudia-settings-item') : [];
    for (var j = 0; j < settingsItems.length; j++) {
        settingsItems[j].addEventListener('click', function() {
            var action = this.getAttribute('data-action');
            if (action === 'change-mode') {
                showModePicker();
            } else if (action === 'clear-chat') {
                messagesEl.innerHTML = '';
                history = [];
                flowState = {
                    step: 'welcome',
                    confirmedAddress: null,
                    confirmedState: null,
                    confirmedTown: null,
                    districtsShown: false,
                    userName: null,
                    pinData: null,
                    apiInFlight: false
                };
                var onboard = window.ClaudiaModules.onboarding;
                if (onboard && onboard.cannedRespond) {
                    onboard.cannedRespond('welcome', null, window.ClaudiaCore);
                }
            } else if (action === 'disable-claudia') {
                if (CONFIG.user) {
                    // Logged-in user — save preference to server
                    fetch('/api/claude-chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'disable_claudia', user_id: CONFIG.user.userId })
                    });
                }
                localStorage.setItem('tpb_claudia_enabled', '0');
                // Hide widget immediately
                var w = document.getElementById('claudia-widget') || document.getElementById('claudia-popout');
                if (w) w.style.display = 'none';
            }
            if (settingsMenu) settingsMenu.classList.remove('show');
            settingsOpen = false;
        });
    }

    // =====================================================
    // EVENT LISTENERS
    // =====================================================
    if (bubble) bubble.addEventListener('click', function() {
        if (!mode) {
            showModePicker();
        } else {
            expand();
            if (history.length === 0) {
                // Auth-aware greeting
                var authMod = window.ClaudiaModules.auth;
                if (authMod && authMod.getGreeting) {
                    var greeting = authMod.getGreeting();
                    if (greeting) {
                        addMessage(greeting, 'c');
                        speak(greeting);
                        history.push({ role: 'assistant', content: greeting });
                        if (CONFIG.user && CONFIG.user.isReturning) {
                            flowState.step = 'returning';
                        }
                        return;
                    }
                }
                // Fallback to old welcome via onboarding module
                var onboard = window.ClaudiaModules.onboarding;
                if (onboard && onboard.cannedRespond) {
                    onboard.cannedRespond('welcome', null, window.ClaudiaCore);
                }
            }
        }
    });

    // Pop-out button
    var popoutBtn = document.getElementById('claudia-popout-btn');
    if (popoutBtn) {
        if (!window.BroadcastChannel) {
            popoutBtn.style.display = 'none';
        } else {
            popoutBtn.addEventListener('click', function() {
                window.open('/claudia.php', 'claudia_popout',
                    'width=420,height=600,resizable=yes,scrollbars=no,menubar=no,toolbar=no');
            });
        }
    }

    if (minimizeBtn) minimizeBtn.addEventListener('click', minimize);

    sendBtn.addEventListener('click', function() {
        var text = textInput.value.trim();
        if (text) handleUserInput(text);
    });

    textInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            var text = textInput.value.trim();
            if (text) handleUserInput(text);
        }
    });

    micBtn.addEventListener('click', function() {
        if (!recognition) return;
        if (isRecording) {
            recognition.stop();
            stopListeningUI();
        } else {
            startListening();
        }
    });

    // =====================================================
    // DRAG TO MOVE (widget only, not pop-out)
    // =====================================================
    (function() {
        var header = document.querySelector('.claudia-header');
        if (!header) return; // Not available in pop-out mode
        var isDragging = false;
        var dragStartX, dragStartY, widgetStartX, widgetStartY;

        header.addEventListener('mousedown', startDrag);
        header.addEventListener('touchstart', startDrag, { passive: false });

        function startDrag(e) {
            // Don't drag if clicking a button
            if (e.target.closest('.claudia-header-btn') || e.target.closest('.claudia-settings-menu')) return;

            isDragging = true;
            header.classList.add('dragging');

            var touch = e.touches ? e.touches[0] : e;
            dragStartX = touch.clientX;
            dragStartY = touch.clientY;

            // Get current position — switch from bottom/right to top/left
            var rect = widget.getBoundingClientRect();
            widgetStartX = rect.left;
            widgetStartY = rect.top;

            // Switch to top/left positioning
            widget.style.left = widgetStartX + 'px';
            widget.style.top = widgetStartY + 'px';
            widget.style.right = 'auto';
            widget.style.bottom = 'auto';

            if (e.touches) e.preventDefault();
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', stopDrag);
            document.addEventListener('touchmove', onDrag, { passive: false });
            document.addEventListener('touchend', stopDrag);
        }

        function onDrag(e) {
            if (!isDragging) return;
            var touch = e.touches ? e.touches[0] : e;
            var dx = touch.clientX - dragStartX;
            var dy = touch.clientY - dragStartY;

            var newX = Math.max(0, Math.min(window.innerWidth - 100, widgetStartX + dx));
            var newY = Math.max(0, Math.min(window.innerHeight - 50, widgetStartY + dy));

            widget.style.left = newX + 'px';
            widget.style.top = newY + 'px';

            if (e.touches) e.preventDefault();
        }

        function stopDrag() {
            isDragging = false;
            header.classList.remove('dragging');
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup', stopDrag);
            document.removeEventListener('touchmove', onDrag);
            document.removeEventListener('touchend', stopDrag);
        }
    })();

    // =====================================================
    // INIT
    // =====================================================
    function init() {
        // Kill any speech carried over from previous page
        if (window.speechSynthesis) window.speechSynthesis.cancel();

        initRecognition();

        if (mode) {
            // Returning user — hide mic in text mode
            if (mode === 'text') {
                micBtn.style.display = 'none';
            }
        }

        // Seed flowState from page USER data (returning users)
        var user = CONFIG.user || ((window.tpbPageState && window.tpbPageState.USER) ? window.tpbPageState.USER : null);
        if (user && user.isReturning) {
            flowState.confirmedState = user.stateAbbr || null;
            flowState.confirmedTown = user.townName || null;
            flowState.step = 'returning';
        }
        // Pop-out mode: auto-expand and greet immediately
        if (IS_POPOUT) {
            isExpanded = true;
            if (!mode) {
                mode = 'both';
                localStorage.setItem('tpb_c_mode', mode);
            }
            // Auto-greet after a short delay (let modules register)
            setTimeout(function() {
                if (history.length === 0) {
                    var authMod = window.ClaudiaModules.auth;
                    if (authMod && authMod.getGreeting) {
                        var greeting = authMod.getGreeting();
                        if (greeting) {
                            addMessage(greeting, 'c');
                            speak(greeting);
                            history.push({ role: 'assistant', content: greeting });
                            return;
                        }
                    }
                    var onboard = window.ClaudiaModules.onboarding;
                    if (onboard && onboard.cannedRespond) {
                        onboard.cannedRespond('welcome', null, window.ClaudiaCore);
                    }
                }
            }, 100);
            return;
        }
        // Don't auto-expand on load — let user click the bubble or wait for page events
    }

    // Expose to page
    window.ClaudiaCore = {
        onPageEvent: onPageEvent,
        expand: expand,
        minimize: minimize,
        addMessage: addMessage,
        speak: speak,
        liveRespond: liveRespond,
        isOffScript: isOffScript,
        getFlowState: function() { return flowState; },
        setFlowState: function(key, val) { flowState[key] = val; },
        getConfig: function() { return CONFIG; },
        getHistory: function() { return history; },
        setHistory: function(h) { history = h; }
    };

    // Backward compat
    window.cWidget = window.ClaudiaCore;

    init();

})();
