/**
 * MandateChat — Lightweight ephemeral chat for mandate refinement.
 *
 * Config:
 *   prefix        — DOM ID prefix (default: 'mc')
 *   apiChat       — AI chat endpoint (default: '/api/claude-chat.php')
 *   apiSave       — idea save endpoint (default: '/talk/api.php')
 *   userId        — current user ID (null if not logged in)
 *   district      — user's congressional district (e.g. 'CT-2')
 *   stateName     — user's state name
 *   townName      — user's town name
 *   placeholder   — textarea placeholder text
 */
(function() {
    'use strict';

    function MandateChat(config) {
        this.config = Object.assign({
            prefix:      'mc',
            apiChat:     '/api/claude-chat.php',
            apiSave:     '/talk/api.php',
            userId:      null,
            district:    '',
            stateName:   '',
            townName:    '',
            placeholder: "What do you want your reps to do?"
        }, config);

        this.messages = [];   // {role: 'user'|'assistant', content: string, ts: string}
        this.ideas    = [];   // {num: int, content: string, ts: string}
        this.nextIdea = 1;
        this.storageKey = 'mandate_chat_' + this.config.prefix;
        this.recognition = null;
        this.micOn = false;
        this.micBaseText = '';
        this.isSubmitting = false;
        this.sessionId = sessionStorage.getItem('mandate_session');
        if (!this.sessionId) {
            this.sessionId = crypto.randomUUID();
            sessionStorage.setItem('mandate_session', this.sessionId);
        }
    }

    // ── Init ──────────────────────────────────────────────────

    MandateChat.prototype.init = function() {
        var p = this.config.prefix;
        this.messagesEl   = document.getElementById(p + '-messages');
        this.inputEl      = document.getElementById(p + '-input');
        this.sendBtn      = document.getElementById(p + '-send');
        this.micBtn       = document.getElementById(p + '-mic');
        this.charEl       = document.getElementById(p + '-char');
        this.ideaListEl   = document.getElementById(p + '-idea-list');
        this.ideaSelectEl = document.getElementById(p + '-idea-select');
        this.toastEl      = document.getElementById(p + '-toast');

        if (!this.messagesEl || !this.inputEl) return;

        this.loadFromStorage();
        this.renderAll();
        this.bindEvents();
        this.initVoice();

        return this;
    };

    // ── Event Binding ─────────────────────────────────────────

    MandateChat.prototype.bindEvents = function() {
        var self = this;

        // Send button
        this.sendBtn.addEventListener('click', function() { self.send(); });

        // Enter to send (shift+enter for newline)
        this.inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                self.send();
            }
        });

        // Auto-resize textarea
        this.inputEl.addEventListener('input', function() {
            self.autoResize();
            self.updateCharCount();
        });

        // Save buttons
        var saveBar = this.messagesEl.closest('.mandate-chat').querySelector('.mc-save-bar');
        if (saveBar) {
            saveBar.addEventListener('click', function(e) {
                var btn = e.target.closest('button');
                if (!btn) return;
                if (btn.classList.contains('mc-save-federal')) self.saveIdea('mandate-federal');
                else if (btn.classList.contains('mc-save-state')) self.saveIdea('mandate-state');
                else if (btn.classList.contains('mc-save-town')) self.saveIdea('mandate-town');
                else if (btn.classList.contains('mc-save-idea')) self.saveIdea('idea');
            });
        }

        // Clear chat button
        var clearBtn = this.messagesEl.closest('.mandate-chat').querySelector('.mc-clear-chat');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                if (confirm('Clear this conversation and all pinned ideas?')) {
                    self.clearSession();
                }
            });
        }
    };

    // ── Send Message ──────────────────────────────────────────

    MandateChat.prototype.send = async function() {
        if (this.micOn && this.recognition) {
            this.micOn = false;
            this.recognition.stop();
        }

        var content = this.inputEl.value.trim();
        if (!content || this.isSubmitting) return;

        // Add user message
        var userMsg = { role: 'user', content: content, ts: new Date().toISOString() };
        this.messages.push(userMsg);
        this.renderBubble(userMsg);
        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();
        this.saveToStorage();

        // Show thinking indicator
        var thinkingEl = document.createElement('div');
        thinkingEl.className = 'mc-thinking';
        thinkingEl.textContent = 'Thinking...';
        this.messagesEl.appendChild(thinkingEl);
        this.scrollToBottom();

        // Build conversation history for AI
        var history = [];
        for (var i = 0; i < this.messages.length - 1; i++) {
            history.push({ role: this.messages[i].role, content: this.messages[i].content });
        }

        // Prepend mandate context to user message
        var mandateCtx = 'You are helping a constituent refine their priorities for elected representatives. '
            + 'Help them turn raw ideas into specific, actionable 1-2 sentence mandate statements. '
            + 'Ask one clarifying question if the idea is vague. '
            + 'When you produce a refined mandate, format it clearly on its own line starting with "Mandate: " so the user can pin it. '
            + 'Do NOT offer to draft letters, emails, or other documents. '
            + 'Keep responses concise and focused.';

        if (this.config.district) mandateCtx += ' The user is in congressional district ' + this.config.district + '.';
        if (this.config.stateName) mandateCtx += ' State: ' + this.config.stateName + '.';
        if (this.config.townName) mandateCtx += ' Town: ' + this.config.townName + '.';

        var messageWithCtx = '[MANDATE REFINE MODE: ' + mandateCtx + ']\n\n' + content;

        this.isSubmitting = true;
        try {
            var resp = await fetch(this.config.apiChat, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: messageWithCtx,
                    history: history,
                    clerk: 'guide',
                    session_id: this.sessionId
                })
            });
            var data = await resp.json();
            thinkingEl.remove();

            if (data.response) {
                var aiMsg = { role: 'assistant', content: data.response, ts: new Date().toISOString() };
                this.messages.push(aiMsg);
                this.renderBubble(aiMsg);
                this.saveToStorage();
            } else {
                this.showToast(data.error || 'AI response failed', 'error');
            }
        } catch (err) {
            thinkingEl.remove();
            this.showToast('Connection error', 'error');
        }
        this.isSubmitting = false;
    };

    // ── Render ────────────────────────────────────────────────

    MandateChat.prototype.renderAll = function() {
        this.messagesEl.innerHTML = '';
        for (var i = 0; i < this.messages.length; i++) {
            this.renderBubble(this.messages[i]);
        }
        this.renderIdeas();
        this.scrollToBottom();
    };

    MandateChat.prototype.renderBubble = function(msg) {
        var div = document.createElement('div');
        div.className = 'mc-bubble ' + (msg.role === 'user' ? 'mc-bubble-user' : 'mc-bubble-ai');

        // Format content: convert newlines to <br>, bold **text**
        var html = this.formatContent(msg.content);
        div.innerHTML = html;

        // Pin button on AI messages
        if (msg.role === 'assistant') {
            var pinBtn = document.createElement('button');
            pinBtn.className = 'mc-pin';
            pinBtn.textContent = '\uD83D\uDCCC'; // 📌
            pinBtn.title = 'Pin this idea';
            var self = this;
            pinBtn.addEventListener('click', function() {
                self.pinIdea(msg.content);
                pinBtn.classList.add('pinned');
            });
            div.appendChild(pinBtn);
        }

        // Timestamp
        var timeDiv = document.createElement('div');
        timeDiv.className = 'mc-bubble-time';
        timeDiv.textContent = this.formatTime(msg.ts);
        div.appendChild(timeDiv);

        this.messagesEl.appendChild(div);
        this.scrollToBottom();
    };

    MandateChat.prototype.formatContent = function(text) {
        // Escape HTML
        var html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        // Bold
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Newlines
        html = html.replace(/\n/g, '<br>');
        return html;
    };

    MandateChat.prototype.formatTime = function(isoStr) {
        if (!isoStr) return '';
        var d = new Date(isoStr);
        var h = d.getHours(), m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ampm;
    };

    // ── Pin Ideas ─────────────────────────────────────────────

    MandateChat.prototype.pinIdea = function(content) {
        // Try to extract "Mandate: ..." line from AI response
        var extracted = content;
        var match = content.match(/(?:^|\n)\s*(?:\*\*)?Mandate:\s*(?:\*\*)?\s*(.+?)(?:\n|$)/i);
        if (match) {
            extracted = match[1].replace(/\*\*/g, '').replace(/<[^>]+>/g, '').trim();
        }

        var idea = {
            num: this.nextIdea++,
            content: extracted,
            ts: new Date().toISOString()
        };
        this.ideas.push(idea);
        this.renderIdeas();
        this.saveToStorage();
        this.showToast('Idea #' + idea.num + ' pinned', 'success');
    };

    MandateChat.prototype.renderIdeas = function() {
        this.ideaListEl.innerHTML = '';

        // Update select dropdown
        this.ideaSelectEl.innerHTML = '';
        if (this.ideas.length === 0) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No ideas pinned';
            this.ideaSelectEl.appendChild(opt);
            return;
        }

        var lastOpt = document.createElement('option');
        lastOpt.value = 'last';
        lastOpt.textContent = 'Last idea (#' + this.ideas[this.ideas.length - 1].num + ')';
        this.ideaSelectEl.appendChild(lastOpt);

        for (var i = 0; i < this.ideas.length; i++) {
            var idea = this.ideas[i];

            // List item
            var item = document.createElement('div');
            item.className = 'mc-idea-item';

            var numSpan = document.createElement('span');
            numSpan.className = 'mc-idea-num';
            numSpan.textContent = '#' + idea.num;

            var textSpan = document.createElement('span');
            textSpan.className = 'mc-idea-text';
            textSpan.textContent = idea.content;

            var actions = document.createElement('span');
            actions.className = 'mc-idea-actions';

            var removeBtn = document.createElement('button');
            removeBtn.textContent = '\u2715'; // ✕
            removeBtn.title = 'Remove';
            removeBtn.dataset.num = idea.num;
            var self = this;
            removeBtn.addEventListener('click', (function(num) {
                return function() { self.removeIdea(num); };
            })(idea.num));
            actions.appendChild(removeBtn);

            item.appendChild(numSpan);
            item.appendChild(textSpan);
            item.appendChild(actions);
            this.ideaListEl.appendChild(item);

            // Select option
            var opt = document.createElement('option');
            opt.value = idea.num;
            opt.textContent = 'Idea #' + idea.num + ': ' + idea.content.substring(0, 40) + (idea.content.length > 40 ? '...' : '');
            this.ideaSelectEl.appendChild(opt);
        }
    };

    MandateChat.prototype.removeIdea = function(num) {
        this.ideas = this.ideas.filter(function(i) { return i.num !== num; });
        this.renderIdeas();
        this.saveToStorage();
    };

    // ── Save to Database ──────────────────────────────────────

    MandateChat.prototype.saveIdea = async function(category) {
        // Get selected idea
        var selectedVal = this.ideaSelectEl.value;
        var idea = null;

        if (!this.ideas.length) {
            this.showToast('No ideas pinned. Pin an idea first using \uD83D\uDCCC', 'error');
            return;
        }

        if (selectedVal === 'last' || selectedVal === '') {
            idea = this.ideas[this.ideas.length - 1];
        } else {
            var num = parseInt(selectedVal);
            idea = this.ideas.find(function(i) { return i.num === num; });
        }

        if (!idea) {
            this.showToast('Idea not found', 'error');
            return;
        }

        var label = category.replace('mandate-', '').replace('idea', 'private idea');
        if (!confirm('Save as ' + label + ' mandate?\n\n"' + idea.content + '"')) return;

        try {
            var resp = await fetch(this.config.apiSave, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: idea.content,
                    category: category,
                    source: 'mandate-chat',
                    session_id: this.sessionId,
                    auto_classify: false
                })
            });
            var data = await resp.json();
            if (data.success) {
                this.showToast('Saved as ' + label + '! (ID #' + data.id + ')', 'success');
                // Remove the saved idea from scratchpad
                this.removeIdea(idea.num);
            } else {
                this.showToast(data.error || 'Save failed', 'error');
            }
        } catch (err) {
            this.showToast('Connection error', 'error');
        }
    };

    // ── localStorage ──────────────────────────────────────────

    MandateChat.prototype.saveToStorage = function() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify({
                messages:  this.messages,
                ideas:     this.ideas,
                nextIdea:  this.nextIdea
            }));
        } catch (e) { /* quota exceeded — ignore */ }
    };

    MandateChat.prototype.loadFromStorage = function() {
        try {
            var data = JSON.parse(localStorage.getItem(this.storageKey));
            if (data) {
                this.messages = data.messages || [];
                this.ideas    = data.ideas    || [];
                this.nextIdea = data.nextIdea || (this.ideas.length + 1);
            }
        } catch (e) { /* corrupt — ignore */ }
    };

    MandateChat.prototype.clearSession = function() {
        this.messages = [];
        this.ideas = [];
        this.nextIdea = 1;
        localStorage.removeItem(this.storageKey);
        this.renderAll();
        this.showToast('Conversation cleared', 'success');
    };

    // ── Voice Input ───────────────────────────────────────────

    MandateChat.prototype.initVoice = function() {
        if (!this.micBtn || !this.inputEl) return;
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) { this.micBtn.style.display = 'none'; return; }

        var self = this;
        this.recognition = new SpeechRecognition();
        this.recognition.continuous = true;
        this.recognition.interimResults = true;
        this.recognition.lang = 'en-US';

        this.recognition.onstart = function() {
            self.micOn = true;
            self.micBtn.classList.add('listening');
            self.micBtn.textContent = '\u23FA'; // ⏺
            self.micBaseText = self.inputEl.value;
        };

        this.recognition.onend = function() {
            self.micBtn.classList.remove('listening');
            self.micBtn.textContent = '\uD83C\uDFA4'; // 🎤
            if (self.micOn) {
                try { self.recognition.start(); } catch (e) { self.micOn = false; }
            }
        };

        this.recognition.onresult = function(e) {
            var final = '', interim = '';
            for (var i = 0; i < e.results.length; i++) {
                if (e.results[i].isFinal) final += e.results[i][0].transcript;
                else interim += e.results[i][0].transcript;
            }
            var sep = self.micBaseText && !self.micBaseText.endsWith(' ') ? ' ' : '';
            self.inputEl.value = self.micBaseText + sep + final + interim;
            self.autoResize();
            self.updateCharCount();
        };

        this.recognition.onerror = function(e) {
            if (e.error === 'no-speech') return;
            self.micOn = false;
            self.micBtn.classList.remove('listening');
            self.micBtn.textContent = '\uD83C\uDFA4';
        };

        this.micBtn.addEventListener('click', function() {
            if (self.micOn) {
                self.micOn = false;
                self.recognition.stop();
                self.micBaseText = self.inputEl.value;
            } else {
                self.recognition.start();
            }
        });
    };

    // ── Helpers ────────────────────────────────────────────────

    MandateChat.prototype.autoResize = function() {
        this.inputEl.style.height = 'auto';
        this.inputEl.style.height = Math.min(this.inputEl.scrollHeight, 200) + 'px';
    };

    MandateChat.prototype.updateCharCount = function() {
        if (!this.charEl) return;
        var len = this.inputEl.value.length;
        this.charEl.textContent = len + ' / 2,000';
        this.charEl.classList.toggle('warn', len > 1800);
    };

    MandateChat.prototype.scrollToBottom = function() {
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    };

    MandateChat.prototype.showToast = function(msg, type) {
        if (!this.toastEl) return;
        this.toastEl.textContent = msg;
        this.toastEl.className = 'mc-toast show ' + (type || 'success');
        var el = this.toastEl;
        setTimeout(function() { el.classList.remove('show'); }, 3000);
    };

    // ── Expose ─────────────────────────────────────────────────

    window.MandateChat = MandateChat;
})();
