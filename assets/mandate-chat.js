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
            stateId:     0,
            townId:      0,
            placeholder: "What do you want your reps to do?"
        }, config);

        this.messages = [];   // {role: 'user'|'assistant', content: string, ts: string}
        this.ideas    = [];   // {num: int, content: string, ts: string}
        this.nextIdea = 1;
        this.storageKey = 'mandate_chat_' + this.config.prefix;
        this.recognition = null;
        this.micOn = false;
        this.commandMode = false;
        this.micBaseText = '';
        this.lastResultIndex = 0;
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
        this.pinBtn       = document.getElementById(p + '-pin');
        this.micBtn       = document.getElementById(p + '-mic');
        this.charEl       = document.getElementById(p + '-char');
        this.ideaListEl   = document.getElementById(p + '-idea-list');
        this.ideaSelectEl = document.getElementById(p + '-idea-select');
        this.toastEl      = document.getElementById(p + '-toast');

        console.log('MandateChat init:', p, {
            messages: !!this.messagesEl, input: !!this.inputEl, pin: !!this.pinBtn,
            ideaList: !!this.ideaListEl, ideaSelect: !!this.ideaSelectEl, send: !!this.sendBtn
        });

        if (!this.messagesEl || !this.inputEl) { console.error('MandateChat: missing messagesEl or inputEl'); return; }

        // Bind events FIRST so handlers work even if render fails
        this.bindEvents();
        this.loadFromStorage();
        try { this.renderAll(); } catch (e) { console.error('MandateChat renderAll:', e); }
        this.initVoice();

        return this;
    };

    // ── Event Binding ─────────────────────────────────────────

    MandateChat.prototype.bindEvents = function() {
        var self = this;

        // Send button
        this.sendBtn.addEventListener('click', function() { self.send(); });

        // Direct pin button — pin to scratchpad without AI
        if (this.pinBtn) {
            console.log('MandateChat: binding pinBtn click');
            this.pinBtn.addEventListener('click', function() {
                console.log('MandateChat: pin button clicked, input value:', self.inputEl.value);
                self.pinDirect();
            });
        } else {
            console.error('MandateChat: pinBtn not found!');
        }

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

        // Clear chat button (clears everything)
        var clearBtn = this.messagesEl.closest('.mandate-chat').querySelector('.mc-clear-chat');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                if (confirm('Clear this conversation and all pinned ideas?')) {
                    self.clearSession();
                }
            });
        }

        // Clear response button (clears chat messages only, keeps ideas)
        var clearResponseBtn = this.messagesEl.closest('.mandate-chat').querySelector('.mc-clear-response');
        if (clearResponseBtn) {
            clearResponseBtn.addEventListener('click', function() {
                self.messages = [];
                self.messagesEl.innerHTML = '';
                self.saveToStorage();
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

        // Check for voice/text commands before sending to AI
        if (this.handleCommand(content)) {
            this.inputEl.value = '';
            this.autoResize();
            this.updateCharCount();
            return;
        }

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

        // Build conversation history for AI (skip system messages)
        var history = [];
        for (var i = 0; i < this.messages.length - 1; i++) {
            if (this.messages[i].role === 'system') continue;
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
        var bubbleClass = msg.role === 'user' ? 'mc-bubble-user' : (msg.role === 'system' ? 'mc-bubble-system' : 'mc-bubble-ai');
        div.className = 'mc-bubble ' + bubbleClass;

        // Format content: convert newlines to <br>, bold **text**
        var html = this.formatContent(msg.content);
        div.innerHTML = html;

        // Pin button on AI messages only (not system)
        if (msg.role === 'assistant') {
            var pinBtn = document.createElement('button');
            pinBtn.className = 'mc-pin';
            pinBtn.textContent = '\uD83D\uDCCC'; // 📌
            pinBtn.title = 'Pin this idea';
            var self = this;
            pinBtn.addEventListener('click', function() {
                console.log('MandateChat: AI pin clicked, messages before:', self.messages.length, 'msg index:', self.messages.indexOf(msg));
                self.pinIdea(msg.content);
                // Remove this AI bubble and its preceding user prompt
                var idx = self.messages.indexOf(msg);
                console.log('MandateChat: splice index:', idx, 'prev role:', idx > 0 ? self.messages[idx - 1].role : 'none');
                if (idx !== -1) {
                    self.messages.splice(idx, 1);
                    // Remove the user prompt that triggered this response
                    if (idx > 0 && self.messages[idx - 1] && self.messages[idx - 1].role === 'user') {
                        self.messages.splice(idx - 1, 1);
                    }
                }
                console.log('MandateChat: messages after splice:', self.messages.length);
                self.renderAll();
                self.saveToStorage();
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
        if (!text) return '';
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

        // Prevent duplicates
        for (var i = 0; i < this.ideas.length; i++) {
            if (this.ideas[i].content === extracted) {
                this.showToast('Already pinned as #' + this.ideas[i].num, 'success');
                return;
            }
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

        // Scroll scratchpad into view
        var scratchpad = this.ideaListEl.closest('.mc-scratchpad');
        if (scratchpad) scratchpad.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    MandateChat.prototype.pinDirect = function() {
        var content = this.inputEl.value.trim();
        console.log('MandateChat pinDirect called, content:', JSON.stringify(content), 'ideas before:', this.ideas.length);
        if (!content) { console.log('MandateChat pinDirect: empty content, returning'); return; }

        // Prevent duplicates
        for (var i = 0; i < this.ideas.length; i++) {
            if (this.ideas[i].content === content) {
                this.showToast('Already pinned as #' + this.ideas[i].num, 'success');
                return;
            }
        }

        var idea = {
            num: this.nextIdea++,
            content: content,
            ts: new Date().toISOString()
        };
        this.ideas.push(idea);
        console.log('MandateChat pinDirect: ideas after push:', this.ideas.length, 'ideaListEl:', this.ideaListEl);
        this.renderIdeas();
        console.log('MandateChat pinDirect: renderIdeas done, ideaListEl children:', this.ideaListEl ? this.ideaListEl.children.length : 'null');
        this.saveToStorage();
        this.showToast('Idea #' + idea.num + ' pinned', 'success');

        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();

        // Scroll scratchpad into view
        var scratchpad = this.ideaListEl.closest('.mc-scratchpad');
        console.log('MandateChat pinDirect: scratchpad element:', scratchpad);
        if (scratchpad) scratchpad.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    MandateChat.prototype.renderIdeas = function() {
        if (!this.ideaListEl) { console.error('MandateChat renderIdeas: ideaListEl is null!'); return; }
        this.ideaListEl.innerHTML = '';

        // DEBUG: Update scratchpad header to show count
        var hdr = this.ideaListEl.closest('.mc-scratchpad');
        if (hdr) {
            var h3 = hdr.querySelector('.mc-scratchpad-header h3');
            if (h3) h3.textContent = 'Ideas Scratchpad (' + this.ideas.length + ' pinned)';
        }
        console.log('MandateChat renderIdeas: rendering', this.ideas.length, 'ideas into', this.ideaListEl.id);

        // Update select dropdown
        if (this.ideaSelectEl) this.ideaSelectEl.innerHTML = '';
        if (this.ideas.length === 0) {
            if (this.ideaSelectEl) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No ideas pinned';
                this.ideaSelectEl.appendChild(opt);
            }
            return;
        }

        if (this.ideaSelectEl) {
            var lastOpt = document.createElement('option');
            lastOpt.value = 'last';
            lastOpt.textContent = 'Last idea (#' + this.ideas[this.ideas.length - 1].num + ')';
            this.ideaSelectEl.appendChild(lastOpt);
        }

        for (var i = 0; i < this.ideas.length; i++) {
            var idea = this.ideas[i];

            // List item
            var item = document.createElement('div');
            item.className = 'mc-idea-item';
            item.style.cssText = 'display:flex !important; visibility:visible !important; opacity:1 !important; min-height:30px; border:2px solid red; margin:4px 0; padding:8px;';

            var numSpan = document.createElement('span');
            numSpan.className = 'mc-idea-num';
            numSpan.textContent = '#' + idea.num;

            var textSpan = document.createElement('span');
            textSpan.className = 'mc-idea-text';
            textSpan.textContent = idea.content;

            var actions = document.createElement('span');
            actions.className = 'mc-idea-actions';

            var editBtn = document.createElement('button');
            editBtn.textContent = '\u270E'; // ✎
            editBtn.title = 'Edit';
            editBtn.className = 'mc-idea-edit';
            var self = this;
            editBtn.addEventListener('click', (function(num) {
                return function() { self.editIdeaInline(num); };
            })(idea.num));
            actions.appendChild(editBtn);

            var removeBtn = document.createElement('button');
            removeBtn.textContent = '\u2715'; // ✕
            removeBtn.title = 'Remove';
            removeBtn.dataset.num = idea.num;
            removeBtn.addEventListener('click', (function(num) {
                return function() { self.removeIdea(num); };
            })(idea.num));
            actions.appendChild(removeBtn);

            item.appendChild(numSpan);
            item.appendChild(textSpan);
            item.appendChild(actions);
            this.ideaListEl.appendChild(item);

            // Select option
            if (this.ideaSelectEl) {
                var opt = document.createElement('option');
                opt.value = idea.num;
                opt.textContent = 'Idea #' + idea.num + ': ' + idea.content.substring(0, 40) + (idea.content.length > 40 ? '...' : '');
                this.ideaSelectEl.appendChild(opt);
            }
        }
    };

    MandateChat.prototype.removeIdea = function(num) {
        this.ideas = this.ideas.filter(function(i) { return i.num !== num; });
        this.renderIdeas();
        this.saveToStorage();
    };

    MandateChat.prototype.editIdeaInline = function(num) {
        var idea = this.ideas.find(function(i) { return i.num === num; });
        if (!idea) return;

        var itemEl = this.ideaListEl.querySelector('[data-num="' + num + '"]')
            || this.ideaListEl.children[this.ideas.indexOf(idea)];
        if (!itemEl) return;

        var textEl = itemEl.querySelector('.mc-idea-text');
        if (!textEl) return;

        var input = document.createElement('textarea');
        input.className = 'mc-idea-edit-input';
        input.value = idea.content;
        input.rows = 2;

        var saveBtn = document.createElement('button');
        saveBtn.textContent = 'Save';
        saveBtn.className = 'mc-idea-edit-save';

        var cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.className = 'mc-idea-edit-cancel';

        var wrapper = document.createElement('div');
        wrapper.className = 'mc-idea-edit-wrap';
        wrapper.appendChild(input);
        wrapper.appendChild(saveBtn);
        wrapper.appendChild(cancelBtn);

        textEl.style.display = 'none';
        textEl.parentNode.insertBefore(wrapper, textEl.nextSibling);

        var self = this;
        saveBtn.addEventListener('click', function() {
            var newContent = input.value.trim();
            if (newContent && newContent !== idea.content) {
                idea.content = newContent;
                self.saveToStorage();
            }
            self.renderIdeas();
        });
        cancelBtn.addEventListener('click', function() {
            self.renderIdeas();
        });
        input.focus();
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
                // Refresh the public mandate summary
                if (window.refreshMandateSummary) {
                    var level = category.replace('mandate-', '');
                    if (level === 'idea') level = 'federal'; // default
                    window.refreshMandateSummary(level);
                }
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
            var raw = localStorage.getItem(this.storageKey);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (data) {
                this.messages = Array.isArray(data.messages) ? data.messages.filter(function(m) { return m && m.content; }) : [];
                this.ideas    = Array.isArray(data.ideas) ? data.ideas.filter(function(i) { return i && i.content; }) : [];
                this.nextIdea = data.nextIdea || (this.ideas.length + 1);
            }
        } catch (e) {
            console.error('MandateChat: corrupt localStorage, clearing', e);
            localStorage.removeItem(this.storageKey);
        }
    };

    MandateChat.prototype.clearSession = function() {
        this.messages = [];
        this.ideas = [];
        this.nextIdea = 1;
        this.micBaseText = '';
        localStorage.removeItem(this.storageKey);
        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();
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
            self.lastResultIndex = 0;
        };

        this.recognition.onend = function() {
            // If TTS paused us, don't touch mic state — speak.onend will restart
            if (self.ttsPaused) return;
            self.micBtn.classList.remove('listening');
            self.micBtn.textContent = '\uD83C\uDFA4'; // 🎤
            if (self.micOn) {
                try { self.recognition.start(); } catch (e) { self.micOn = false; }
            }
        };

        this.recognition.onresult = function(e) {
            // Process only NEW results since last check
            for (var i = self.lastResultIndex; i < e.results.length; i++) {
                if (!e.results[i].isFinal) {
                    // Show interim text in textarea (chat mode only)
                    if (!self.commandMode) {
                        var interim = e.results[i][0].transcript;
                        var sep = self.micBaseText && !self.micBaseText.endsWith(' ') ? ' ' : '';
                        self.inputEl.value = self.micBaseText + sep + interim;
                        self.autoResize();
                        self.updateCharCount();
                    }
                    continue;
                }

                // Final result — process it
                var text = e.results[i][0].transcript.trim();
                self.lastResultIndex = i + 1;

                if (!text) continue;

                var lower = text.toLowerCase().replace(/[.,!?]+$/, '').trim();

                // Check for "command" toggle — standalone or at end of phrase
                var toggleMatch = lower.match(/\b(command)\s*$/);
                if (toggleMatch) {
                    // If there's text before "command", keep it as dictation first
                    var before = text.substring(0, lower.lastIndexOf(toggleMatch[1])).trim();
                    if (before && !self.commandMode) {
                        var sep = self.micBaseText && !self.micBaseText.endsWith(' ') ? ' ' : '';
                        self.micBaseText = self.micBaseText + sep + before;
                        self.inputEl.value = self.micBaseText;
                        self.autoResize();
                        self.updateCharCount();
                    }
                    self.commandMode = !self.commandMode;
                    self.updateMicMode();
                    // Sync micBaseText so interim results don't inject "claude x" into textarea
                    self.micBaseText = self.inputEl.value;
                    if (self.commandMode) {
                        self.addSystemMessage('Command mode ON. Say a command (pin, save federal, help, etc.) or say "command" to return to chat.');
                        self.speak('Command mode on.');
                    } else {
                        self.addSystemMessage('Chat mode. Speak your ideas.');
                        self.speak('Chat mode.');
                    }
                    continue;
                }

                if (self.commandMode) {
                    // Execute as command
                    if (!self.handleCommand(text)) {
                        // If it's a long phrase (5+ words), it's clearly dictation, not a command
                        if (text.split(/\s+/).length >= 5) {
                            self.commandMode = false;
                            self.updateMicMode();
                            self.addSystemMessage('That sounds like dictation. Switching to chat mode.');
                            self.inputEl.value = (self.inputEl.value ? self.inputEl.value + ' ' : '') + text;
                            self.autoResize();
                            self.updateCharCount();
                        } else {
                            self.addSystemMessage('Unknown command: "' + text + '". Say "help" for commands.');
                            self.speak('Unknown command.');
                        }
                    }
                } else {
                    // Chat mode — append to textarea
                    var sep = self.micBaseText && !self.micBaseText.endsWith(' ') ? ' ' : '';
                    self.micBaseText = self.micBaseText + sep + text;
                    self.inputEl.value = self.micBaseText;
                    self.autoResize();
                    self.updateCharCount();
                }
            }
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
                self.commandMode = false;
                self.recognition.stop();
                self.micBaseText = self.inputEl.value;
                self.updateMicMode();
            } else {
                self.recognition.start();
            }
        });
    };

    MandateChat.prototype.updateMicMode = function() {
        if (!this.micBtn) return;
        if (this.commandMode) {
            this.micBtn.classList.add('command-mode');
            this.micBtn.textContent = '\u2699'; // ⚙
            this.micBtn.title = 'Command mode — say "command" to exit';
        } else if (this.micOn) {
            this.micBtn.classList.remove('command-mode');
            this.micBtn.textContent = '\u23FA'; // ⏺
            this.micBtn.title = 'Recording — say "command" for commands';
        } else {
            this.micBtn.classList.remove('command-mode');
            this.micBtn.textContent = '\uD83C\uDFA4'; // 🎤
            this.micBtn.title = 'Voice input';
        }
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

    // ── Voice / Text Commands ─────────────────────────────────

    MandateChat.prototype.handleCommand = function(text) {
        var lower = text.toLowerCase().replace(/[.,!?]+$/, '').trim();

        // ── Save mandate ──
        if (lower.includes('save') && lower.includes('federal')) {
            this.saveIdea('mandate-federal'); return true;
        }
        if (lower.includes('save') && lower.includes('state')) {
            this.saveIdea('mandate-state'); return true;
        }
        if (lower.includes('save') && lower.includes('town')) {
            this.saveIdea('mandate-town'); return true;
        }
        if (lower.includes('save') && lower.includes('idea')) {
            this.saveIdea('idea'); return true;
        }

        // ── Send prompt ──
        if (/\b(send|submit|go)\b/.test(lower)) {
            if (this.inputEl.value.trim()) {
                this.send();
            } else {
                this.addSystemMessage('Nothing to send. Dictate an idea first.');
            }
            return true;
        }

        // ── Pin direct (pin what's in the input box) ──
        if (/\bpin\s+(this|direct|my|it)\b/.test(lower)) {
            this.pinDirect();
            return true;
        }

        // ── Pin last AI response ──
        if (/\b(pin|pen|penis)\b/.test(lower)) {
            var lastAi = null;
            for (var i = this.messages.length - 1; i >= 0; i--) {
                if (this.messages[i].role === 'assistant') { lastAi = this.messages[i]; break; }
            }
            if (lastAi) {
                this.pinIdea(lastAi.content);
                // Remove AI response and its preceding user prompt
                var idx = this.messages.indexOf(lastAi);
                if (idx !== -1) {
                    this.messages.splice(idx, 1);
                    if (idx > 0 && this.messages[idx - 1] && this.messages[idx - 1].role === 'user') {
                        this.messages.splice(idx - 1, 1);
                    }
                }
                this.renderAll();
                this.saveToStorage();
            } else {
                this.addSystemMessage('No AI response to pin yet.');
            }
            return true;
        }

        // ── Delete pinned idea ──
        if (/\b(delete|remove)\b/.test(lower) && /\b#?\d+\b/.test(lower)) {
            var num = lower.match(/\b#?(\d+)\b/);
            if (num) {
                var n = parseInt(num[1], 10);
                var found = this.ideas.some(function(i) { return i.num === n; });
                if (found) {
                    this.removeIdea(n);
                    this.addSystemMessage('Deleted idea #' + n + '.');
                } else {
                    this.addSystemMessage('No idea #' + n + ' to delete.');
                }
            }
            return true;
        }

        // ── Clear commands ──
        if (/\bclear\b/.test(lower) && /\b(all|everything)\b/.test(lower) || /\bstart over\b/.test(lower)) {
            this.clearSession();
            return true;
        }
        if (/\bclear\b/.test(lower) && /\b(prompt|text|input)\b/.test(lower)) {
            this.inputEl.value = '';
            this.micBaseText = '';
            this.autoResize();
            this.updateCharCount();
            this.addSystemMessage('Prompt cleared.');
            return true;
        }
        if (/\bclear\b/.test(lower) && /\b(response|responses|chat|messages)\b/.test(lower)) {
            this.messages = [];
            this.renderAll();
            this.addSystemMessage('Chat cleared. Pinned ideas kept.');
            return true;
        }

        // ── Read / list mandate or pinned idea ──
        if (/\b(read|list)\b/.test(lower) && (/\bmandates?\b/.test(lower) || /\b(my|all)\b/.test(lower))) {
            this.readMandate(lower);
            return true;
        }
        // "read #1", "read 1", "read number 1"
        if (/\bread\b/.test(lower) && /\b#?\d+\b/.test(lower)) {
            var num = lower.match(/\b#?(\d+)\b/);
            if (num) {
                var idx = parseInt(num[1], 10) - 1;
                if (idx >= 0 && idx < this.ideas.length) {
                    this.addSystemMessage('Idea #' + (idx + 1) + ': ' + this.ideas[idx]);
                    this.speak(this.ideas[idx]);
                } else {
                    this.addSystemMessage('No idea #' + (idx + 1) + '. You have ' + this.ideas.length + ' pinned idea' + (this.ideas.length !== 1 ? 's' : '') + '.');
                }
            }
            return true;
        }

        // ── Help ──
        if (/\b(help|commands)\b/.test(lower)) {
            this.addSystemMessage(
                'Voice commands:\n' +
                '\u2022 "send" \u2014 submit your dictated idea to AI\n' +
                '\u2022 "pin" \u2014 pin the last AI response\n' +
                '\u2022 "save federal mandate" \u2014 save pinned idea as federal mandate\n' +
                '\u2022 "save state mandate" \u2014 save as state mandate\n' +
                '\u2022 "save town mandate" \u2014 save as town mandate\n' +
                '\u2022 "save idea" \u2014 save as private idea\n' +
                '\u2022 "read #1" \u2014 read back a pinned idea\n' +
                '\u2022 "read my mandate" \u2014 read your saved mandates\n' +
                '\u2022 "delete #3" \u2014 remove a pinned idea\n' +
                '\u2022 "clear all" \u2014 clear everything (chat + pins)\n' +
                '\u2022 "clear prompt" \u2014 clear the text input\n' +
                '\u2022 "clear response" \u2014 clear chat bubbles, keep pins\n' +
                '\u2022 "help" \u2014 show this list'
            );
            this.speak('Available commands: send, pin, save federal, save state, save town, save idea, read number, read my mandate, delete number, clear all, clear prompt, clear response, and help.');
            return true;
        }

        // ── Logout ──
        if (/\b(log\s*out|logout)\b/.test(lower)) {
            this.addSystemMessage("Logging you out...");
            this.speak("Logging you out.");
            localStorage.removeItem('mandate_phone');
            localStorage.removeItem('mandate_user');
            setTimeout(function() { location.reload(); }, 1500);
            return true;
        }

        return false; // Not a command — send to AI
    };

    // ── Read saved mandates from aggregation API ──────────────

    MandateChat.prototype.readMandate = async function(lower) {
        var level = 'federal';
        if (lower.includes('state')) level = 'state';
        else if (lower.includes('town')) level = 'town';

        var url = '/api/mandate-aggregate.php?level=' + level;
        if (level === 'federal' && this.config.district) url += '&district=' + encodeURIComponent(this.config.district);
        else if (level === 'state' && this.config.stateId) url += '&state_id=' + this.config.stateId;
        else if (level === 'town' && this.config.townId) url += '&town_id=' + this.config.townId;

        try {
            var resp = await fetch(url);
            var data = await resp.json();
            if (!data.success || data.item_count === 0) {
                this.addSystemMessage('No mandate items yet for ' + level + ' level.');
                this.speak('No mandate items yet for ' + level + ' level.');
                return;
            }
            var intro = data.contributor_count + ' constituent' +
                (data.contributor_count !== 1 ? 's have' : ' has') + ' spoken. ';
            var list = data.items.map(function(item, i) {
                return (i + 1) + '. ' + item.content;
            }).join('. ');
            this.addSystemMessage(intro + list);
            this.speak(intro + 'Top priorities: ' + list);
        } catch (err) {
            this.addSystemMessage('Could not load mandate data.');
        }
    };

    // ── System Message (non-AI, non-user) ─────────────────────

    MandateChat.prototype.addSystemMessage = function(text) {
        var msg = { role: 'system', content: text, ts: new Date().toISOString() };
        this.messages.push(msg);
        this.renderBubble(msg);
        this.saveToStorage();
    };

    // ── Text-to-Speech ────────────────────────────────────────

    MandateChat.prototype.speak = function(text) {
        if (!window.speechSynthesis) return;
        var self = this;
        // Pause recognition while TTS speaks to prevent feedback loop
        var wasListening = this.micOn;
        if (wasListening && this.recognition) {
            this.ttsPaused = true; // prevent onend from killing micOn
            try { this.recognition.stop(); } catch(e) {}
        }
        var utter = new SpeechSynthesisUtterance(text);
        utter.rate = 1.0;
        utter.pitch = 1.0;
        // Pick preferred voice: Microsoft Mark
        var voices = speechSynthesis.getVoices();
        for (var i = 0; i < voices.length; i++) {
            if (voices[i].name.indexOf('Mark') !== -1) {
                utter.voice = voices[i];
                break;
            }
        }
        utter.onend = function() {
            self.ttsPaused = false;
            // Resume recognition after TTS finishes
            if (wasListening && self.recognition) {
                self.micOn = true;
                try { self.recognition.start(); } catch(e) { self.micOn = false; }
            }
        };
        speechSynthesis.speak(utter);
    };

    // ── Expose ─────────────────────────────────────────────────

    window.MandateChat = MandateChat;
})();
