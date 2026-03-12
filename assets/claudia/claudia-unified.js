/**
 * Claudia Unified — One Widget, One Voice, One Pipe
 * Phase 1: Chat mode + full duplex voice engine
 */
(function() {
    'use strict';

    // ── Config ──────────────────────────────────────────────
    var CONFIG = window.claudiaConfig || { context: 'general', mode_default: 'chat', mode_available: ['chat'] };
    var USER = window.claudiaUser || null;

    // ── State ───────────────────────────────────────────────
    var state = {
        open: false,
        mode: localStorage.getItem('claudia_mode') || CONFIG.mode_default || 'chat',
        position: JSON.parse(localStorage.getItem('claudia_position') || 'null'),
        size: JSON.parse(localStorage.getItem('claudia_size') || 'null'),
        audioMode: localStorage.getItem('claudia_audio_mode') || 'speakers',
        voiceEnabled: localStorage.getItem('claudia_voice_mode') !== 'off',
        webSearch: localStorage.getItem('claudia_websearch') === '1',
        scratchpadOpen: localStorage.getItem('claudia_scratchpad') !== '0',
        scratchpadItems: JSON.parse(localStorage.getItem('claudia_scratchpad_items') || '[]'),
        messages: [],
        sessionId: sessionStorage.getItem('claudia_session') || crypto.randomUUID(),
    };
    sessionStorage.setItem('claudia_session', state.sessionId);

    // ── DOM refs ────────────────────────────────────────────
    var widget, bubble, dragHandle, resizeHandle;
    var messagesEl, inputEl, sendBtn, typingEl;
    var settingsMenu, settingsBtn;
    var voiceBar, voiceStateEl, micBtn;
    var modeBtn, popoutBtn, minimizeBtn;
    var scratchpadEl, scratchpadItemsEl, spClearBtn;

    // ── Init ────────────────────────────────────────────────
    function init() {
        widget = document.getElementById('claudia-widget');
        bubble = document.getElementById('claudia-bubble');
        if (!widget || !bubble) return;

        dragHandle = document.getElementById('claudia-drag-handle');
        resizeHandle = document.getElementById('claudia-resize-handle');
        messagesEl = document.getElementById('claudia-messages');
        inputEl = document.getElementById('claudia-text-input');
        sendBtn = document.getElementById('claudia-send-btn');
        typingEl = document.getElementById('claudia-typing');
        settingsMenu = document.getElementById('claudia-settings-menu');
        settingsBtn = document.getElementById('claudia-settings-btn');
        voiceBar = document.getElementById('claudia-voice-bar');
        voiceStateEl = document.getElementById('claudia-voice-state');
        micBtn = document.getElementById('claudia-mic-btn');
        modeBtn = document.getElementById('claudia-mode-btn');
        popoutBtn = document.getElementById('claudia-popout-btn');
        minimizeBtn = document.getElementById('claudia-minimize-btn');
        scratchpadEl = document.getElementById('claudia-scratchpad');
        scratchpadItemsEl = document.getElementById('claudia-scratchpad-items');
        spClearBtn = document.getElementById('claudia-sp-clear');

        setDefaultPosition();
        restoreState();
        bindEvents();
        loadHistory();

        // Popout: start open, no bubble
        if (CONFIG.isPopout) {
            state.open = true;
            widget.style.display = 'flex';
            bubble.style.display = 'none';
            if (state.voiceEnabled) initVoiceEngine();
        } else {
            // Start minimized (bubble visible)
            bubble.style.display = 'flex';
            widget.style.display = 'none';
        }
    }

    // ── Default Position ────────────────────────────────────
    function setDefaultPosition() {
        if (state.position) {
            // Convert legacy right-based position to left-based
            if (state.position.right !== undefined) {
                state.position = {
                    left: window.innerWidth - 380 - state.position.right,
                    top: state.position.top
                };
                savePosition();
            }
            return;
        }

        // Right side, below navs — always stored as left/top
        var navHeight = 0;
        var navEls = document.querySelectorAll('.tpb-nav, .tpb-secondary-nav, nav');
        navEls.forEach(function(el) { navHeight += el.offsetHeight; });
        if (navHeight === 0) navHeight = 100; // fallback

        state.position = {
            left: window.innerWidth - 380 - 20,
            top: navHeight + 20
        };
    }

    function applyPosition() {
        // Always left/top — no right-based positioning
        widget.style.left = state.position.left + 'px';
        widget.style.top = state.position.top + 'px';
        widget.style.right = 'auto';

        bubble.style.left = state.position.left + 'px';
        bubble.style.top = state.position.top + 'px';
        bubble.style.right = 'auto';

        if (state.size) {
            widget.style.width = state.size.width + 'px';
            widget.style.height = state.size.height + 'px';
        }
    }

    function savePosition() {
        localStorage.setItem('claudia_position', JSON.stringify(state.position));
        if (state.size) {
            localStorage.setItem('claudia_size', JSON.stringify(state.size));
        }
    }

    function restoreState() {
        applyPosition();

        // Restore toggles
        updateToggle('claudia-voice-toggle', 'claudia-voice-label', state.voiceEnabled);
        updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', state.audioMode === 'earphones');
        updateToggle('claudia-websearch-toggle', 'claudia-ws-label', state.webSearch);

        if (state.voiceEnabled && voiceBar) {
            voiceBar.style.display = 'flex';
        }

        // Restore mode
        if (modeBtn) {
            modeBtn.textContent = state.mode.charAt(0).toUpperCase() + state.mode.slice(1);
        }

        // Restore scratchpad
        updateToggle('claudia-scratchpad-toggle', 'claudia-sp-label', state.scratchpadOpen);
        if (scratchpadEl) {
            scratchpadEl.style.display = state.scratchpadOpen ? 'flex' : 'none';
        }
        renderScratchpad();

        // Restore save bar visibility
        updateSaveBarVisibility(state.mode);
    }

    // ── Events ──────────────────────────────────────────────
    function bindEvents() {
        // Bubble — drag to move, click to open
        initBubbleDrag();

        // Minimize → close widget, stop speech, show bubble
        minimizeBtn.addEventListener('click', function() {
            speechSynthesis.cancel();
            stopMic();
            closeWidget();
        });

        // Send message
        sendBtn.addEventListener('click', function() { sendMessage(); });
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Settings toggle
        settingsBtn.addEventListener('click', function(e) {
            settingsMenu.style.display = settingsMenu.style.display === 'none' ? 'block' : 'none';
            e.stopPropagation();
        });

        // Close settings when clicking outside
        document.addEventListener('click', function(e) {
            if (settingsMenu.style.display !== 'none' && !settingsMenu.contains(e.target) && e.target !== settingsBtn) {
                settingsMenu.style.display = 'none';
            }
        });

        // Settings toggles + actions
        document.querySelectorAll('.claudia-settings-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var action = item.dataset.action;
                if (action === 'toggle-voice') toggleVoice();
                else if (action === 'toggle-audio-mode') toggleAudioMode();
                else if (action === 'toggle-websearch') toggleWebSearch();
                else if (action === 'toggle-scratchpad') toggleScratchpad();
                else if (action === 'clear-chat') clearConversation();
            });
        });

        // Mode button
        if (modeBtn) {
            modeBtn.addEventListener('click', function() { cycleMode(); });
        }

        // Popout
        popoutBtn.addEventListener('click', function() {
            openPopout();
        });

        // Drag
        initDrag();

        // Resize
        initResize();

        // Cross-tab sync (for popout)
        window.addEventListener('storage', function(e) {
            if (e.key === 'claudia_sync') {
                var data = JSON.parse(e.newValue);
                if (data && data.type === 'message') {
                    state.messages = data.messages;
                    if (data.scratchpadItems) {
                        state.scratchpadItems = data.scratchpadItems;
                        saveScratchpad();
                        renderScratchpad();
                    }
                    renderMessages();
                }
            }
        });

        // Prevent scroll from leaking to page
        widget.addEventListener('wheel', function(e) {
            e.preventDefault();
            // Manually scroll the messages container
            if (messagesEl) {
                messagesEl.scrollTop += e.deltaY;
            }
        }, { passive: false });

        // Save bar — level buttons
        document.querySelectorAll('.claudia-save-level').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var level = btn.dataset.level;
                if (level === 'idea') {
                    saveIdea();
                } else {
                    saveMandate('mandate-' + level);
                }
            });
        });

        if (spClearBtn) {
            spClearBtn.addEventListener('click', function() { clearScratchpad(); });
        }

        // Mic button
        if (micBtn) {
            micBtn.addEventListener('click', function() {
                if (voice.micOn) stopMic();
                else startMic();
            });
        }
    }

    // ── Open / Close ────────────────────────────────────────
    function openWidget() {
        state.open = true;
        widget.style.display = 'flex';
        bubble.style.display = 'none';
        applyPosition();
        inputEl.focus();
    }

    function closeWidget() {
        state.open = false;
        widget.style.display = 'none';
        bubble.style.display = 'flex';
        applyPosition();
    }

    // ── Bubble Drag ────────────────────────────────────────
    function initBubbleDrag() {
        var dragging = false, moved = false, startX, startY, startLeft, startTop;

        function onDown(cx, cy) {
            dragging = true;
            moved = false;
            var rect = bubble.getBoundingClientRect();
            startX = cx;
            startY = cy;
            startLeft = rect.left;
            startTop = rect.top;
            // Always use left/top for dragging
            bubble.style.right = 'auto';
            bubble.style.left = startLeft + 'px';
        }

        function onMove(cx, cy) {
            if (!dragging) return;
            var dx = cx - startX;
            var dy = cy - startY;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
            if (!moved) return;
            bubble.style.left = Math.max(0, Math.min(startLeft + dx, window.innerWidth - 48)) + 'px';
            bubble.style.top = Math.max(0, Math.min(startTop + dy, window.innerHeight - 48)) + 'px';
        }

        function onUp() {
            if (!dragging) return;
            dragging = false;
            if (moved) {
                // Save bubble position as widget position
                state.position = {
                    left: parseInt(bubble.style.left),
                    top: parseInt(bubble.style.top)
                };
                savePosition();
            } else {
                // Short click — open widget
                openWidget();
            }
        }

        bubble.addEventListener('mousedown', function(e) { onDown(e.clientX, e.clientY); e.preventDefault(); });
        document.addEventListener('mousemove', function(e) { onMove(e.clientX, e.clientY); });
        document.addEventListener('mouseup', onUp);

        bubble.addEventListener('touchstart', function(e) {
            var t = e.touches[0]; onDown(t.clientX, t.clientY);
        }, { passive: true });
        document.addEventListener('touchmove', function(e) {
            if (!dragging) return;
            var t = e.touches[0]; onMove(t.clientX, t.clientY);
        }, { passive: true });
        document.addEventListener('touchend', onUp);
    }

    // ── Widget Drag ─────────────────────────────────────────
    function initDrag() {
        var isDragging = false, startX, startY, startLeft, startTop;

        function beginDrag(cx, cy) {
            isDragging = true;
            startX = cx;
            startY = cy;
            startLeft = parseInt(widget.style.left) || 0;
            startTop = parseInt(widget.style.top) || 0;
            document.body.style.userSelect = 'none';
        }

        function moveDrag(cx, cy) {
            if (!isDragging) return;
            var newLeft = startLeft + (cx - startX);
            var newTop = startTop + (cy - startY);
            // Keep on screen
            newLeft = Math.max(0, Math.min(newLeft, window.innerWidth - widget.offsetWidth));
            newTop = Math.max(0, Math.min(newTop, window.innerHeight - 60));
            widget.style.left = newLeft + 'px';
            widget.style.top = newTop + 'px';
        }

        function endDrag() {
            if (!isDragging) return;
            isDragging = false;
            document.body.style.userSelect = '';
            state.position = {
                left: parseInt(widget.style.left) || 0,
                top: parseInt(widget.style.top) || 0
            };
            savePosition();
        }

        dragHandle.addEventListener('mousedown', function(e) {
            if (e.target.closest('button')) return;
            beginDrag(e.clientX, e.clientY);
            e.preventDefault();
        });
        document.addEventListener('mousemove', function(e) { moveDrag(e.clientX, e.clientY); });
        document.addEventListener('mouseup', endDrag);

        // Touch support
        dragHandle.addEventListener('touchstart', function(e) {
            if (e.target.closest('button')) return;
            var t = e.touches[0];
            beginDrag(t.clientX, t.clientY);
        }, { passive: true });
        document.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            var t = e.touches[0]; moveDrag(t.clientX, t.clientY);
        }, { passive: true });
        document.addEventListener('touchend', endDrag);
    }

    // ── Resize ──────────────────────────────────────────────
    function initResize() {
        var resizing = false, startX, startY, startW, startH;

        resizeHandle.addEventListener('mousedown', function(e) {
            resizing = true;
            startX = e.clientX;
            startY = e.clientY;
            startW = widget.offsetWidth;
            startH = widget.offsetHeight;
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!resizing) return;
            var w = Math.max(320, startW + (e.clientX - startX));
            var h = Math.max(400, startH + (e.clientY - startY));
            widget.style.width = w + 'px';
            widget.style.height = h + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (!resizing) return;
            resizing = false;
            state.size = {
                width: widget.offsetWidth,
                height: widget.offsetHeight
            };
            savePosition();
        });
    }

    // ── Popout ──────────────────────────────────────────────
    function openPopout() {
        var w = state.size ? state.size.width : 380;
        var h = state.size ? state.size.height : 520;
        var popout = window.open('/claudia.php', 'claudia_popout',
            'width=' + w + ',height=' + h + ',resizable=yes,scrollbars=no');
        if (popout) {
            closeWidget();
        }
    }

    // ── Toggle Helpers ──────────────────────────────────────
    function updateToggle(toggleId, labelId, isOn) {
        var toggle = document.getElementById(toggleId);
        var label = document.getElementById(labelId);
        if (toggle) {
            toggle.classList.toggle('on', isOn);
        }
        if (label) {
            label.textContent = isOn ? 'ON' : 'OFF';
        }
    }

    function toggleVoice() {
        state.voiceEnabled = !state.voiceEnabled;
        localStorage.setItem('claudia_voice_mode', state.voiceEnabled ? 'on' : 'off');
        updateToggle('claudia-voice-toggle', 'claudia-voice-label', state.voiceEnabled);
        if (voiceBar) {
            voiceBar.style.display = state.voiceEnabled ? 'flex' : 'none';
        }
        if (state.voiceEnabled) {
            initVoiceEngine();
        }
    }

    function toggleAudioMode() {
        state.audioMode = state.audioMode === 'earphones' ? 'speakers' : 'earphones';
        localStorage.setItem('claudia_audio_mode', state.audioMode);
        updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', state.audioMode === 'earphones');
    }

    function toggleWebSearch() {
        state.webSearch = !state.webSearch;
        localStorage.setItem('claudia_websearch', state.webSearch ? '1' : '0');
        updateToggle('claudia-websearch-toggle', 'claudia-ws-label', state.webSearch);
    }

    // ── Mode Switching ─────────────────────────────────────────
    function switchMode(newMode) {
        var available = CONFIG.mode_available || ['chat'];
        if (available.indexOf(newMode) === -1) return;

        state.mode = newMode;
        localStorage.setItem('claudia_mode', newMode);

        if (modeBtn) {
            modeBtn.textContent = newMode.charAt(0).toUpperCase() + newMode.slice(1);
        }

        // Scratchpad default: ON for talk/mandate, OFF for chat
        var spDefault = (newMode === 'talk' || newMode === 'mandate');
        if (localStorage.getItem('claudia_scratchpad') === null) {
            state.scratchpadOpen = spDefault;
        }
        if (scratchpadEl) {
            scratchpadEl.style.display = state.scratchpadOpen ? 'flex' : 'none';
        }
        updateToggle('claudia-scratchpad-toggle', 'claudia-sp-label', state.scratchpadOpen);

        // Show mandate levels only in mandate mode, show only Idea in other modes
        updateSaveBarVisibility(newMode);
    }

    function cycleMode() {
        var available = CONFIG.mode_available || ['chat'];
        var idx = available.indexOf(state.mode);
        var next = available[(idx + 1) % available.length];
        switchMode(next);
    }

    function updateSaveBarVisibility(mode) {
        var saveBar = document.getElementById('claudia-save-bar');
        if (saveBar) {
            saveBar.querySelectorAll('.claudia-save-level').forEach(function(btn) {
                var level = btn.dataset.level;
                if (mode === 'mandate') {
                    btn.style.display = '';
                } else {
                    btn.style.display = level === 'idea' ? '' : 'none';
                }
            });
        }
    }

    // ── Chat ────────────────────────────────────────────────
    function sendMessage() {
        var content = inputEl.value.trim();
        if (!content) return;

        addMessage('user', content);
        inputEl.value = '';
        showTyping();

        // Build history for API
        var history = [];
        for (var i = 0; i < state.messages.length - 1; i++) {
            var m = state.messages[i];
            if (m.role === 'system') continue;
            history.push({ role: m.role, content: m.content });
        }

        fetch('/api/claude-chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: content,
                history: history,
                clerk: state.mode === 'talk' ? 'brainstorm' : (state.mode === 'mandate' ? 'mandate' : 'guide'),
                mode: state.mode,
                user_id: USER ? USER.user_id : null,
                session_id: state.sessionId,
                context: CONFIG.context,
                web_search: state.webSearch
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideTyping();
            if (data.response) {
                addMessage('assistant', data.response);
                if (state.voiceEnabled) {
                    speak(data.response);
                }
            } else if (data.error) {
                addMessage('system', 'Error: ' + data.error);
            }
        })
        .catch(function(err) {
            hideTyping();
            addMessage('system', 'Connection error — try again.');
        });
    }

    function addMessage(role, content) {
        var msg = { role: role, content: content, ts: new Date().toISOString() };
        state.messages.push(msg);
        renderMessage(msg);

        // Auto-pin mandates from assistant responses
        if (msg.role === 'assistant' && state.mode === 'mandate') {
            var mandateRegex = /(?:^|\n)\s*(?:\*\*)?Mandate:\s*(?:\*\*)?\s*(.+?)(?:\n|$)/gi;
            var match;
            while ((match = mandateRegex.exec(msg.content)) !== null) {
                var mandateText = match[1].trim();
                var isDupe = state.scratchpadItems.some(function(item) {
                    return item.content === mandateText;
                });
                if (!isDupe && mandateText.length > 0) {
                    pinMessage(mandateText);
                }
            }
        }

        saveHistory();
        syncToPopout();
    }

    function renderMessage(msg) {
        var div = document.createElement('div');
        div.className = 'claudia-msg ' + msg.role;

        // Format: escape HTML, bold, newlines
        var html = msg.content
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
        div.innerHTML = html;

        // Timestamp
        var timeDiv = document.createElement('div');
        timeDiv.className = 'claudia-msg-time';
        var d = new Date(msg.ts);
        var h = d.getHours(), m = d.getMinutes();
        timeDiv.textContent = (h % 12 || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h >= 12 ? 'PM' : 'AM');
        div.appendChild(timeDiv);

        // Pin button for assistant messages
        if (msg.role === 'assistant') {
            var pinBtn = document.createElement('button');
            pinBtn.className = 'claudia-msg-pin';
            pinBtn.textContent = '\u{1F4CC} Pin';
            pinBtn.addEventListener('click', function() {
                pinMessage(msg.content);
            });
            div.appendChild(pinBtn);
        }

        messagesEl.appendChild(div);
        messagesEl.scrollTop = 0; // column-reverse: 0 = newest
    }

    function renderMessages() {
        messagesEl.innerHTML = '';
        state.messages.forEach(renderMessage);
    }

    function showTyping() {
        typingEl.style.display = 'block';
        sendBtn.disabled = true;
    }

    function hideTyping() {
        typingEl.style.display = 'none';
        sendBtn.disabled = false;
    }

    function clearConversation() {
        state.messages = [];
        messagesEl.innerHTML = '';
        localStorage.removeItem('claudia_history_' + state.sessionId);
        // New session
        state.sessionId = crypto.randomUUID();
        sessionStorage.setItem('claudia_session', state.sessionId);
        syncToPopout();
        settingsMenu.style.display = 'none';
    }

    // ── History Persistence ─────────────────────────────────
    function saveHistory() {
        try {
            localStorage.setItem('claudia_history_' + state.sessionId, JSON.stringify(state.messages));
        } catch(e) {}
    }

    function loadHistory() {
        try {
            var data = localStorage.getItem('claudia_history_' + state.sessionId);
            if (data) {
                state.messages = JSON.parse(data);
                renderMessages();
            }
        } catch(e) {}
    }

    function syncToPopout() {
        localStorage.setItem('claudia_sync', JSON.stringify({
            type: 'message',
            messages: state.messages,
            scratchpadItems: state.scratchpadItems,
            ts: Date.now()
        }));
    }

    // ── Scratchpad ──────────────────────────────────────────────
    function saveScratchpad() {
        localStorage.setItem('claudia_scratchpad_items', JSON.stringify(state.scratchpadItems));
    }

    function pinMessage(content) {
        state.scratchpadItems.push({
            id: Date.now(),
            content: content,
            ts: new Date().toISOString()
        });
        saveScratchpad();
        renderScratchpad();
    }

    function unpinItem(id) {
        state.scratchpadItems = state.scratchpadItems.filter(function(item) { return item.id !== id; });
        saveScratchpad();
        renderScratchpad();
    }

    function clearScratchpad() {
        state.scratchpadItems = [];
        saveScratchpad();
        renderScratchpad();
    }

    function renderScratchpad() {
        if (!scratchpadItemsEl) return;
        scratchpadItemsEl.innerHTML = '';
        state.scratchpadItems.forEach(function(item, i) {
            var div = document.createElement('div');
            div.className = 'claudia-sp-item';
            div.innerHTML = '<span class="claudia-sp-num">#' + (i + 1) + '</span>' +
                '<span class="claudia-sp-text">' + item.content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>' +
                '<button class="claudia-sp-remove" data-id="' + item.id + '" title="Remove">&times;</button>';
            scratchpadItemsEl.appendChild(div);
        });
        scratchpadItemsEl.querySelectorAll('.claudia-sp-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                unpinItem(parseInt(btn.dataset.id));
            });
        });
    }

    function toggleScratchpad() {
        state.scratchpadOpen = !state.scratchpadOpen;
        localStorage.setItem('claudia_scratchpad', state.scratchpadOpen ? '1' : '0');
        updateToggle('claudia-scratchpad-toggle', 'claudia-sp-label', state.scratchpadOpen);
        if (scratchpadEl) {
            scratchpadEl.style.display = state.scratchpadOpen ? 'flex' : 'none';
        }
    }

    function saveIdea(itemId) {
        var items = itemId
            ? state.scratchpadItems.filter(function(i) { return i.id === itemId; })
            : state.scratchpadItems.slice();

        if (!items.length) return;

        var saved = 0;
        items.forEach(function(item) {
            fetch('/talk/api.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: item.content,
                    category: 'idea',
                    source: 'claudia-widget',
                    session_id: state.sessionId
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    saved++;
                    unpinItem(item.id);
                    if (saved === items.length) {
                        addMessage('system', saved + ' idea' + (saved > 1 ? 's' : '') + ' saved.');
                    }
                } else {
                    addMessage('system', 'Could not save: ' + (data.error || 'unknown error'));
                }
            })
            .catch(function() {
                addMessage('system', 'Connection error saving idea.');
            });
        });
    }

    function saveMandate(category) {
        var items = state.scratchpadItems.slice();
        if (!items.length) return;

        var saved = 0;
        items.forEach(function(item) {
            fetch('/talk/api.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: item.content,
                    category: category,
                    source: 'claudia-widget',
                    session_id: state.sessionId,
                    auto_classify: false
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    saved++;
                    unpinItem(item.id);
                    if (saved === items.length) {
                        var level = category.replace('mandate-', '');
                        addMessage('system', saved + ' ' + level + ' mandate' + (saved > 1 ? 's' : '') + ' saved.');
                    }
                } else {
                    addMessage('system', 'Could not save: ' + (data.error || 'unknown error'));
                }
            })
            .catch(function() {
                addMessage('system', 'Connection error saving mandate.');
            });
        });
    }

    function readMandate(level) {
        var user = window.claudiaUser;
        if (!user) {
            addMessage('system', 'Log in to read your mandates.');
            return;
        }

        var url = '/api/mandate-aggregate.php?';
        if (level === 'mine' || !level) {
            url += 'level=mine&user_id=' + user.user_id;
        } else {
            url += 'level=' + level;
            if (user.district) url += '&district=' + encodeURIComponent(user.district);
            if (user.state_id) url += '&state_id=' + user.state_id;
            if (user.town_id) url += '&town_id=' + user.town_id;
        }

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.items && data.items.length) {
                    var text = data.item_count + ' ' + (level || 'total') + ' mandate' + (data.item_count > 1 ? 's' : '') + ': ';
                    text += data.items.slice(0, 5).map(function(item) { return item.content; }).join('. ');
                    addMessage('assistant', text);
                    if (state.voiceEnabled) speak(text);
                } else {
                    addMessage('system', 'No mandates found' + (level ? ' at ' + level + ' level' : '') + '.');
                }
            })
            .catch(function() {
                addMessage('system', 'Could not load mandates.');
            });
    }

    // ── Voice Engine (Full Duplex) ──────────────────────────
    var voice = {
        recognition: null,
        claudiaVoice: null,
        isSpeaking: false,
        sttActive: false,
        ignoreSTT: false,
        micOn: false,
        commandMode: false,
        silenceTimer: null,
        humanUtterance: '',
        started: false
    };

    function initVoiceEngine() {
        if (voice.started) return;
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return;

        voice.started = true;
        voice.recognition = new SR();
        voice.recognition.continuous = false;  // Half duplex: one utterance per mic press
        voice.recognition.interimResults = true;
        voice.recognition.lang = 'en-US';

        voice.recognition.onstart = function() {
            voice.sttActive = true;
            voice.humanUtterance = '';
            updateVoiceUI('listening');
        };

        voice.recognition.onresult = function(event) {
            var result = event.results[event.results.length - 1];
            var text = result[0].transcript.trim();
            if (!text || voice.ignoreSTT) return;

            voice.humanUtterance = text;
            // Show interim text in input
            inputEl.value = text;

            if (result.isFinal) {
                // Half duplex: got final result, send it
                stopMic();
                humanFinished(text);
            }
        };

        voice.recognition.onerror = function(e) {
            if (e.error === 'no-speech') {
                stopMic();
                return;
            }
        };

        voice.recognition.onend = function() {
            voice.sttActive = false;
            // Half duplex: don't auto-restart
            if (micBtn) micBtn.classList.remove('listening');
            if (!voice.isSpeaking) updateVoiceUI('idle');
        };

        // Pick voice
        pickVoice();
        speechSynthesis.onvoiceschanged = pickVoice;
    }

    function pickVoice() {
        var v = speechSynthesis.getVoices();
        if (!v.length) return;
        // Prefer known female voices by name
        voice.claudiaVoice = v.find(function(x) { return /zira|eva|samantha|karen|susan|hazel|fiona|moira|tessa|jenny|aria/i.test(x.name); })
            || v.find(function(x) { return /female/i.test(x.name) && x.lang && x.lang.startsWith('en'); })
            // Exclude known male voices
            || v.find(function(x) { return x.lang && x.lang.startsWith('en') && !/david|mark|james|george|daniel|richard|guy|sean/i.test(x.name); })
            || v.find(function(x) { return x.lang && x.lang.startsWith('en'); });
    }

    function speak(text) {
        if (!state.voiceEnabled) return;
        // Re-pick voice if not yet selected (async load)
        if (!voice.claudiaVoice) pickVoice();
        speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(text);
        u.rate = 0.9;
        u.pitch = 1.05;
        if (voice.claudiaVoice) u.voice = voice.claudiaVoice;

        u.onstart = function() {
            voice.isSpeaking = true;
            voice.ignoreSTT = true;
            updateVoiceUI('speaking');
        };
        u.onend = function() {
            voice.isSpeaking = false;
            setTimeout(function() { voice.ignoreSTT = false; }, 500);
            updateVoiceUI('idle');
        };
        speechSynthesis.speak(u);
    }

    function parseVoiceCommand(text) {
        var t = text.toLowerCase().trim();

        // Pin last response
        if (/^pin$|^pin (that|it|this|last)$/.test(t)) {
            var lastAssistant = null;
            for (var i = state.messages.length - 1; i >= 0; i--) {
                if (state.messages[i].role === 'assistant') { lastAssistant = state.messages[i]; break; }
            }
            if (lastAssistant) pinMessage(lastAssistant.content);
            return true;
        }

        // Delete pin
        var delMatch = t.match(/^(?:delete|remove) #?(\d+)$/);
        if (delMatch) {
            var idx = parseInt(delMatch[1]) - 1;
            if (idx >= 0 && idx < state.scratchpadItems.length) unpinItem(state.scratchpadItems[idx].id);
            return true;
        }

        // Clear all
        if (/^clear all$|^start over$/.test(t)) { clearConversation(); clearScratchpad(); return true; }

        // Clear prompt
        if (/^clear prompt$|^clear input$/.test(t)) { inputEl.value = ''; return true; }

        // Scratchpad toggle
        if (/^scratchpad on$/.test(t)) { if (!state.scratchpadOpen) toggleScratchpad(); return true; }
        if (/^scratchpad off$/.test(t)) { if (state.scratchpadOpen) toggleScratchpad(); return true; }

        // Read pinned item
        var readMatch = t.match(/^read #?(\d+)$/);
        if (readMatch) {
            var rIdx = parseInt(readMatch[1]) - 1;
            if (rIdx >= 0 && rIdx < state.scratchpadItems.length) speak(state.scratchpadItems[rIdx].content);
            return true;
        }

        // Save idea / mandate
        if (/^save ideas?$/.test(t)) { saveIdea(); return true; }
        if (/^save federal(?: mandate)?$/.test(t)) { saveMandate('mandate-federal'); return true; }
        if (/^save state(?: mandate)?$/.test(t)) { saveMandate('mandate-state'); return true; }
        if (/^save town(?: mandate)?$/.test(t)) { saveMandate('mandate-town'); return true; }

        // Read mandates
        if (/^read my mandates?$/.test(t)) { readMandate('mine'); return true; }
        if (/^read (?:my )?federal mandates?$/.test(t)) { readMandate('federal'); return true; }
        if (/^read (?:my )?state mandates?$/.test(t)) { readMandate('state'); return true; }
        if (/^read (?:my )?town mandates?$/.test(t)) { readMandate('town'); return true; }
        var saveMatch = t.match(/^save #?(\d+)(?: as idea)?$/);
        if (saveMatch) {
            var sIdx = parseInt(saveMatch[1]) - 1;
            if (sIdx >= 0 && sIdx < state.scratchpadItems.length) saveIdea(state.scratchpadItems[sIdx].id);
            return true;
        }

        // Mode switch
        if (/^chat mode$/.test(t)) { switchMode('chat'); return true; }
        if (/^talk mode$/.test(t)) { switchMode('talk'); return true; }
        if (/^mandate mode$/.test(t)) { switchMode('mandate'); return true; }

        // Popout
        if (/^pop ?out$/.test(t)) { openPopout(); return true; }

        // Audio mode
        if (/^earphones$/.test(t)) {
            state.audioMode = 'earphones'; localStorage.setItem('claudia_audio_mode', 'earphones');
            updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', true); return true;
        }
        if (/^speakers$/.test(t)) {
            state.audioMode = 'speakers'; localStorage.setItem('claudia_audio_mode', 'speakers');
            updateToggle('claudia-earphones-toggle', 'claudia-earphones-label', false); return true;
        }

        return false;
    }

    function humanFinished(text) {
        // Try voice commands first
        if (parseVoiceCommand(text)) {
            inputEl.value = '';
            return;
        }
        updateVoiceUI('processing');
        inputEl.value = text;
        sendMessage();
    }

    function startMic() {
        if (!voice.recognition) initVoiceEngine();
        if (!voice.recognition) return;
        voice.micOn = true;
        if (voiceBar) voiceBar.style.display = 'flex';
        try { voice.recognition.start(); } catch(e) {}
        if (micBtn) micBtn.classList.add('listening');
    }

    function stopMic() {
        voice.micOn = false;
        if (voice.recognition) {
            try { voice.recognition.stop(); } catch(e) {}
        }
        if (micBtn) micBtn.classList.remove('listening');
        updateVoiceUI('idle');
    }

    function updateVoiceUI(newState) {
        if (voiceStateEl) {
            voiceStateEl.textContent = newState.toUpperCase();
            voiceStateEl.className = 'claudia-voice-state ' + newState;
        }
    }

    // ── Boot ────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
