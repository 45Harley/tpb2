/**
 * MandateChat — Discuss & Create Draft
 *
 * CRUD bubble workspace for mandate refinement.
 * Each bubble has: edit, scope checkboxes (federal/state/town/idea), save, delete.
 * No scratchpad, no pinning — bubbles are the workspace.
 *
 * Config:
 *   prefix        — DOM ID prefix (default: 'mc')
 *   apiChat       — AI chat endpoint (default: '/api/claude-chat.php')
 *   apiSave       — idea save endpoint (default: '/talk/api.php')
 *   userId        — current user ID (null if not logged in)
 *   district      — user's congressional district (e.g. 'CT-2')
 *   stateName     — user's state name
 *   townName      — user's town name
 *   stateId       — user's state ID
 *   townId        — user's town ID
 *   groupId       — group filter (null for none)
 *   placeholder   — textarea placeholder text
 *   defaultScope  — pre-checked scope ('federal', 'state', 'town', or null)
 */
(function() {
    'use strict';

    function MandateChat(config) {
        this.config = Object.assign({
            prefix:       'mc',
            apiChat:      '/api/claude-chat.php',
            apiSave:      '/talk/api.php',
            userId:       null,
            district:     '',
            stateName:    '',
            townName:     '',
            stateId:      0,
            townId:       0,
            groupId:      null,
            placeholder:  "What matters most to you?",
            defaultScope: null
        }, config);

        this.bubbles = [];   // {id: int, role: 'user'|'assistant'|'system', content: string, ts: string, scope: null|string}
        this.nextId = 1;
        this.storageKey = 'mandate_draft_' + this.config.prefix;
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
        this.draftsEl     = document.getElementById(p + '-drafts');
        this.inputEl      = document.getElementById(p + '-input');
        this.addBtn       = document.getElementById(p + '-add');
        this.askAiBtn     = document.getElementById(p + '-ask-ai');
        this.micBtn       = document.getElementById(p + '-mic');
        this.charEl       = document.getElementById(p + '-char');
        this.clearAllBtn  = document.getElementById(p + '-clear-all');
        this.toastEl      = document.getElementById(p + '-toast');

        if (!this.draftsEl || !this.inputEl) return;

        this.bindEvents();
        this.loadFromStorage();
        try { this.renderAll(); } catch (e) { console.error('MandateChat renderAll:', e); }
        this.initVoice();

        return this;
    };

    // ── Event Binding ─────────────────────────────────────────

    MandateChat.prototype.bindEvents = function() {
        var self = this;

        // Add button — post directly as bubble
        if (this.addBtn) {
            this.addBtn.addEventListener('click', function() { self.addDirect(); });
        }

        // Ask AI button — send to AI
        if (this.askAiBtn) {
            this.askAiBtn.addEventListener('click', function() { self.askAi(); });
        }

        // Enter to Ask AI (shift+enter for newline)
        this.inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                self.askAi();
            }
        });

        // Auto-resize textarea
        this.inputEl.addEventListener('input', function() {
            self.autoResize();
            self.updateCharCount();
        });

        // Clear all button
        if (this.clearAllBtn) {
            this.clearAllBtn.addEventListener('click', function() {
                if (confirm('Clear all drafts in this workspace?')) {
                    self.clearSession();
                }
            });
        }

        // Delegated click handler for bubble actions
        this.draftsEl.addEventListener('click', function(e) {
            var bubble = e.target.closest('.mc-draft-bubble');
            if (!bubble) return;
            var id = parseInt(bubble.dataset.id);

            // Scope checkbox (radio behavior)
            var scopeInput = e.target.closest('.mc-scope-check input');
            if (scopeInput) {
                self.setScope(id, scopeInput.value);
                return;
            }

            // Save button
            if (e.target.closest('.mc-draft-save')) {
                self.saveBubble(id);
                return;
            }

            // Delete button
            if (e.target.closest('.mc-draft-delete')) {
                self.deleteBubble(id);
                return;
            }

            // Edit button
            if (e.target.closest('.mc-draft-edit')) {
                self.editBubble(id);
                return;
            }

            // Edit save
            if (e.target.closest('.mc-edit-save')) {
                self.saveEdit(id);
                return;
            }

            // Edit cancel
            if (e.target.closest('.mc-edit-cancel')) {
                self.renderAll();
                return;
            }
        });
    };

    // ── Add Direct (no AI) ────────────────────────────────────

    MandateChat.prototype.addDirect = function() {
        var content = this.inputEl.value.trim();
        if (!content) return;

        this.addBubble('user', content);
        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();
    };

    // ── Ask AI ────────────────────────────────────────────────

    MandateChat.prototype.askAi = async function() {
        if (this.micOn && this.recognition) {
            this.micOn = false;
            this.recognition.stop();
        }

        var content = this.inputEl.value.trim();
        if (!content || this.isSubmitting) return;

        // Check for voice/text commands
        if (this.handleCommand(content)) {
            this.inputEl.value = '';
            this.autoResize();
            this.updateCharCount();
            return;
        }

        // Add user bubble
        this.addBubble('user', content);
        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();

        // Show thinking indicator
        var thinkingEl = document.createElement('div');
        thinkingEl.className = 'mc-thinking';
        thinkingEl.textContent = 'Thinking...';
        this.draftsEl.insertBefore(thinkingEl, this.draftsEl.firstChild);

        // Build conversation history (last 10 non-system bubbles for context)
        var history = [];
        var recent = this.bubbles.slice(-10);
        for (var i = 0; i < recent.length - 1; i++) {
            if (recent[i].role === 'system') continue;
            history.push({ role: recent[i].role, content: recent[i].content });
        }

        // Prepend mandate context
        var mandateCtx = 'You are helping a constituent refine their priorities for elected representatives. '
            + 'Help them turn raw ideas into specific, actionable 1-2 sentence mandate statements. '
            + 'Ask one clarifying question if the idea is vague. '
            + 'When you produce a refined mandate, format it clearly. '
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
            if (thinkingEl.parentNode) thinkingEl.remove();

            if (data.response) {
                this.addBubble('assistant', data.response);
            } else {
                this.addBubble('system', 'AI is temporarily unavailable. Your draft is saved above \u2014 you can edit it and save without AI.');
            }
        } catch (err) {
            if (thinkingEl.parentNode) thinkingEl.remove();
            this.addBubble('system', 'AI is temporarily unavailable. Your draft is saved above \u2014 you can edit it and save without AI.');
        }
        this.isSubmitting = false;
    };

    // ── Bubble CRUD ───────────────────────────────────────────

    MandateChat.prototype.addBubble = function(role, content) {
        var bubble = {
            id: this.nextId++,
            role: role,
            content: content,
            ts: new Date().toISOString(),
            scope: this.config.defaultScope || null
        };
        this.bubbles.push(bubble);
        this.saveToStorage();
        this.renderAll();
        return bubble;
    };

    MandateChat.prototype.deleteBubble = function(id) {
        this.bubbles = this.bubbles.filter(function(b) { return b.id !== id; });
        this.renumber();
        this.saveToStorage();
        this.renderAll();
    };

    MandateChat.prototype.setScope = function(id, scope) {
        var bubble = this.bubbles.find(function(b) { return b.id === id; });
        if (!bubble) return;
        // Toggle: if same scope clicked, uncheck
        bubble.scope = (bubble.scope === scope) ? null : scope;
        this.saveToStorage();
        this.renderAll();
    };

    MandateChat.prototype.editBubble = function(id) {
        // Render edit mode for this bubble
        var el = this.draftsEl.querySelector('[data-id="' + id + '"]');
        if (!el || el.querySelector('.mc-edit-input')) return;
        var bubble = this.bubbles.find(function(b) { return b.id === id; });
        if (!bubble) return;

        var contentEl = el.querySelector('.mc-draft-content');
        if (!contentEl) return;

        var input = document.createElement('textarea');
        input.className = 'mc-edit-input';
        input.value = bubble.content;
        input.rows = 3;

        var btnWrap = document.createElement('div');
        btnWrap.className = 'mc-edit-btns';

        var saveBtn = document.createElement('button');
        saveBtn.className = 'mc-edit-save';
        saveBtn.textContent = 'Save';
        saveBtn.title = 'Save your changes';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'mc-edit-cancel';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.title = 'Discard changes';

        btnWrap.appendChild(saveBtn);
        btnWrap.appendChild(cancelBtn);

        contentEl.style.display = 'none';
        contentEl.parentNode.insertBefore(input, contentEl.nextSibling);
        contentEl.parentNode.insertBefore(btnWrap, input.nextSibling);
        input.focus();
    };

    MandateChat.prototype.saveEdit = function(id) {
        var el = this.draftsEl.querySelector('[data-id="' + id + '"]');
        if (!el) return;
        var input = el.querySelector('.mc-edit-input');
        if (!input) return;
        var newContent = input.value.trim();
        if (!newContent) return;

        var bubble = this.bubbles.find(function(b) { return b.id === id; });
        if (bubble) {
            bubble.content = newContent;
            this.saveToStorage();
        }
        this.renderAll();
    };

    MandateChat.prototype.saveBubble = async function(id) {
        var bubble = this.bubbles.find(function(b) { return b.id === id; });
        if (!bubble || !bubble.scope) {
            this.showToast('Select a scope first (Federal, State, Town, or Idea)', 'error');
            return;
        }

        var category = bubble.scope === 'idea' ? 'idea' : ('mandate-' + bubble.scope);
        var label = bubble.scope === 'idea' ? 'private idea' : bubble.scope + ' mandate';

        if (!confirm('Save as ' + label + '?\n\n"' + bubble.content.substring(0, 120) + (bubble.content.length > 120 ? '...' : '') + '"')) return;

        try {
            var resp = await fetch(this.config.apiSave, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: bubble.content,
                    category: category,
                    source: 'mandate-chat',
                    session_id: this.sessionId,
                    auto_classify: false,
                    group_id: this.config.groupId || null
                })
            });
            var data = await resp.json();
            if (data.success) {
                this.showToast('Saved as ' + label + '! (ID #' + data.id + ')', 'success');
                this.deleteBubble(id);
                // Refresh public mandate summary below
                if (window.refreshMandateSummary) {
                    var level = bubble.scope === 'idea' ? 'all' : bubble.scope;
                    window.refreshMandateSummary(level);
                }
            } else {
                this.showToast(data.error || 'Save failed', 'error');
            }
        } catch (err) {
            this.showToast('Connection error', 'error');
        }
    };

    // ── Renumber ──────────────────────────────────────────────

    MandateChat.prototype.renumber = function() {
        for (var i = 0; i < this.bubbles.length; i++) {
            this.bubbles[i].id = i + 1;
        }
        this.nextId = this.bubbles.length + 1;
    };

    // ── Render ────────────────────────────────────────────────

    MandateChat.prototype.renderAll = function() {
        this.draftsEl.innerHTML = '';
        // Newest on top
        for (var i = this.bubbles.length - 1; i >= 0; i--) {
            this.renderBubble(this.bubbles[i]);
        }
    };

    MandateChat.prototype.renderBubble = function(bubble) {
        var div = document.createElement('div');
        div.className = 'mc-draft-bubble mc-draft-' + bubble.role;
        div.dataset.id = bubble.id;

        // Header row: ID + timestamp + actions
        var header = document.createElement('div');
        header.className = 'mc-draft-header';

        var idSpan = document.createElement('span');
        idSpan.className = 'mc-draft-id';
        idSpan.textContent = '#' + bubble.id;
        idSpan.title = 'Draft ID';
        header.appendChild(idSpan);

        var roleSpan = document.createElement('span');
        roleSpan.className = 'mc-draft-role';
        roleSpan.textContent = bubble.role === 'assistant' ? 'AI' : (bubble.role === 'system' ? 'System' : 'You');
        header.appendChild(roleSpan);

        var timeSpan = document.createElement('span');
        timeSpan.className = 'mc-draft-time';
        timeSpan.textContent = this.formatTime(bubble.ts);
        header.appendChild(timeSpan);

        var actions = document.createElement('span');
        actions.className = 'mc-draft-actions';

        if (bubble.role !== 'system') {
            var editBtn = document.createElement('button');
            editBtn.className = 'mc-draft-edit';
            editBtn.innerHTML = '&#x270E;';
            editBtn.title = 'Edit this draft';
            actions.appendChild(editBtn);
        }

        var deleteBtn = document.createElement('button');
        deleteBtn.className = 'mc-draft-delete';
        deleteBtn.innerHTML = '&times;';
        deleteBtn.title = 'Delete this draft';
        actions.appendChild(deleteBtn);

        header.appendChild(actions);
        div.appendChild(header);

        // Content
        var contentDiv = document.createElement('div');
        contentDiv.className = 'mc-draft-content';
        contentDiv.innerHTML = this.formatContent(bubble.content);
        div.appendChild(contentDiv);

        // Scope checkboxes + save (not on system bubbles)
        if (bubble.role !== 'system') {
            var scopeRow = document.createElement('div');
            scopeRow.className = 'mc-scope-row';

            var scopes = [
                { value: 'federal', label: 'Federal', title: 'Save to your U.S. congressional representatives' },
                { value: 'state',   label: 'State',   title: 'Save to your state legislators' },
                { value: 'town',    label: 'Town',    title: 'Save to your local town officials' },
                { value: 'idea',    label: 'Idea',    title: 'Save privately without publishing' }
            ];

            for (var s = 0; s < scopes.length; s++) {
                var scope = scopes[s];
                var lbl = document.createElement('label');
                lbl.className = 'mc-scope-check' + (bubble.scope === scope.value ? ' checked' : '');
                lbl.title = scope.title;

                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.value = scope.value;
                inp.checked = bubble.scope === scope.value;

                lbl.appendChild(inp);
                lbl.appendChild(document.createTextNode(' ' + scope.label));
                scopeRow.appendChild(lbl);
            }

            if (bubble.scope) {
                var saveBtn = document.createElement('button');
                saveBtn.className = 'mc-draft-save';
                saveBtn.textContent = 'Save';
                saveBtn.title = 'Save this draft as a ' + (bubble.scope === 'idea' ? 'private idea' : bubble.scope + ' mandate');
                scopeRow.appendChild(saveBtn);
            }

            div.appendChild(scopeRow);
        }

        this.draftsEl.appendChild(div);
    };

    MandateChat.prototype.formatContent = function(text) {
        if (!text) return '';
        var html = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
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

    // ── localStorage ──────────────────────────────────────────

    MandateChat.prototype.saveToStorage = function() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify({
                bubbles:  this.bubbles,
                nextId:   this.nextId
            }));
        } catch (e) { /* quota exceeded */ }
    };

    MandateChat.prototype.loadFromStorage = function() {
        try {
            var raw = localStorage.getItem(this.storageKey);
            if (!raw) return;
            var data = JSON.parse(raw);
            if (data) {
                this.bubbles = Array.isArray(data.bubbles) ? data.bubbles.filter(function(b) { return b && b.content; }) : [];
                this.nextId  = data.nextId || (this.bubbles.length + 1);
            }
        } catch (e) {
            console.error('MandateChat: corrupt localStorage, clearing', e);
            localStorage.removeItem(this.storageKey);
        }
    };

    MandateChat.prototype.clearSession = function() {
        this.bubbles = [];
        this.nextId = 1;
        this.micBaseText = '';
        localStorage.removeItem(this.storageKey);
        this.inputEl.value = '';
        this.autoResize();
        this.updateCharCount();
        this.renderAll();
        this.showToast('Workspace cleared', 'success');
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
            self.micBtn.textContent = '\u23FA';
            self.micBaseText = self.inputEl.value;
            self.lastResultIndex = 0;
        };

        this.recognition.onend = function() {
            if (self.ttsPaused) return;
            self.micBtn.classList.remove('listening');
            self.micBtn.textContent = '\uD83C\uDFA4';
            if (self.micOn) {
                try { self.recognition.start(); } catch (e) { self.micOn = false; }
            }
        };

        this.recognition.onresult = function(e) {
            for (var i = self.lastResultIndex; i < e.results.length; i++) {
                if (!e.results[i].isFinal) {
                    if (!self.commandMode) {
                        var interim = e.results[i][0].transcript;
                        var sep = self.micBaseText && !self.micBaseText.endsWith(' ') ? ' ' : '';
                        self.inputEl.value = self.micBaseText + sep + interim;
                        self.autoResize();
                        self.updateCharCount();
                    }
                    continue;
                }

                var text = e.results[i][0].transcript.trim();
                self.lastResultIndex = i + 1;
                if (!text) continue;

                var lower = text.toLowerCase().replace(/[.,!?]+$/, '').trim();

                // Toggle command mode
                var toggleMatch = lower.match(/\b(command)\s*$/);
                if (toggleMatch) {
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
                    self.micBaseText = self.inputEl.value;
                    if (self.commandMode) {
                        self.addBubble('system', 'Command mode ON. Say a command or say "command" to return to chat.');
                        self.speak('Command mode on.');
                    } else {
                        self.addBubble('system', 'Chat mode. Speak your ideas.');
                        self.speak('Chat mode.');
                    }
                    continue;
                }

                if (self.commandMode) {
                    if (!self.handleCommand(text)) {
                        if (text.split(/\s+/).length >= 5) {
                            self.commandMode = false;
                            self.updateMicMode();
                            self.addBubble('system', 'That sounds like dictation. Switching to chat mode.');
                            self.inputEl.value = (self.inputEl.value ? self.inputEl.value + ' ' : '') + text;
                            self.autoResize();
                            self.updateCharCount();
                        } else {
                            self.addBubble('system', 'Unknown command: "' + text + '". Say "help" for commands.');
                            self.speak('Unknown command.');
                        }
                    }
                } else {
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
            this.micBtn.textContent = '\u2699';
            this.micBtn.title = 'Command mode \u2014 say "command" to exit';
        } else if (this.micOn) {
            this.micBtn.classList.remove('command-mode');
            this.micBtn.textContent = '\u23FA';
            this.micBtn.title = 'Recording \u2014 say "command" for commands';
        } else {
            this.micBtn.classList.remove('command-mode');
            this.micBtn.textContent = '\uD83C\uDFA4';
            this.micBtn.title = 'Tap to dictate your idea';
        }
    };

    // ── Voice / Text Commands ─────────────────────────────────

    MandateChat.prototype.handleCommand = function(text) {
        var lower = text.toLowerCase().replace(/[.,!?]+$/, '').trim();

        // ── Save with scope: "save #3 federal", "save federal #3", "save #3" ──
        var saveMatch = lower.match(/\bsave\b.*?#?(\d+)(?:\s+(federal|state|town|idea))?/);
        if (!saveMatch) saveMatch = lower.match(/\bsave\b\s+(federal|state|town|idea)(?:\s+#?(\d+))?/);
        if (saveMatch) {
            var num = parseInt(saveMatch[1] || saveMatch[2]);
            var scope = saveMatch[2] || saveMatch[1];
            if (isNaN(num)) {
                // "save federal" without number — save most recent
                var last = this.bubbles[this.bubbles.length - 1];
                if (last && last.role !== 'system') {
                    if (scope && /^(federal|state|town|idea)$/.test(scope)) last.scope = scope;
                    this.saveBubble(last.id);
                }
            } else {
                var bubble = this.bubbles.find(function(b) { return b.id === num; });
                if (bubble) {
                    if (scope && /^(federal|state|town|idea)$/.test(scope)) bubble.scope = scope;
                    this.saveBubble(bubble.id);
                } else {
                    this.addBubble('system', 'No draft #' + num + ' found.');
                }
            }
            return true;
        }

        // ── Check scope: "check federal #3", "federal #3" ──
        var checkMatch = lower.match(/\b(?:check\s+)?(federal|state|town|idea)\s+#?(\d+)/);
        if (!checkMatch) checkMatch = lower.match(/\b(?:check\s+)?#?(\d+)\s+(federal|state|town|idea)/);
        if (checkMatch) {
            var scope = checkMatch[1].match(/^(federal|state|town|idea)$/) ? checkMatch[1] : checkMatch[2];
            var num = parseInt(checkMatch[2].match(/^\d+$/) ? checkMatch[2] : checkMatch[1]);
            this.setScope(num, scope);
            this.addBubble('system', 'Checked ' + scope + ' on #' + num + '.');
            return true;
        }

        // ── Delete: "delete #3" ──
        if (/\b(delete|remove)\b/.test(lower) && /\b#?\d+\b/.test(lower)) {
            var num = lower.match(/\b#?(\d+)\b/);
            if (num) {
                var n = parseInt(num[1], 10);
                var found = this.bubbles.some(function(b) { return b.id === n; });
                if (found) {
                    this.deleteBubble(n);
                    this.addBubble('system', 'Deleted draft #' + n + '.');
                } else {
                    this.addBubble('system', 'No draft #' + n + ' to delete.');
                }
            }
            return true;
        }

        // ── Edit: "edit #3" ──
        if (/\bedit\b/.test(lower) && /\b#?\d+\b/.test(lower)) {
            var num = lower.match(/\b#?(\d+)\b/);
            if (num) {
                this.editBubble(parseInt(num[1], 10));
            }
            return true;
        }

        // ── Send / Ask AI ──
        if (/\b(send|submit|ask|go)\b/.test(lower)) {
            if (this.inputEl.value.trim()) {
                this.askAi();
            } else {
                this.addBubble('system', 'Nothing to send. Dictate an idea first.');
            }
            return true;
        }

        // ── Add direct ──
        if (/\badd\b/.test(lower) && !/\badd\s+(federal|state|town|idea)\b/.test(lower)) {
            if (this.inputEl.value.trim()) {
                this.addDirect();
            } else {
                this.addBubble('system', 'Nothing to add. Dictate an idea first.');
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
            this.addBubble('system', 'Prompt cleared.');
            return true;
        }

        // ── Read saved mandates ──
        if (/\b(read|list)\b/.test(lower) && (/\bmandates?\b/.test(lower) || /\b(my|all)\b/.test(lower))) {
            this.readMandate(lower);
            return true;
        }

        // ── Help ──
        if (/\b(help|commands)\b/.test(lower)) {
            this.addBubble('system',
                'Voice commands:\n' +
                '\u2022 "add" \u2014 add your dictated text as a draft\n' +
                '\u2022 "send" / "ask" \u2014 send to AI for refinement\n' +
                '\u2022 "check federal #3" \u2014 set scope on a draft\n' +
                '\u2022 "save #3" \u2014 save a draft to its checked scope\n' +
                '\u2022 "save federal #3" \u2014 set scope and save in one step\n' +
                '\u2022 "edit #3" \u2014 edit a draft\n' +
                '\u2022 "delete #3" \u2014 remove a draft\n' +
                '\u2022 "read my mandate" \u2014 read saved mandates\n' +
                '\u2022 "clear all" \u2014 clear workspace\n' +
                '\u2022 "clear prompt" \u2014 clear the text input\n' +
                '\u2022 "help" \u2014 show this list'
            );
            this.speak('Available commands: add, send, check scope, save, edit, delete, read mandate, clear all, clear prompt, and help.');
            return true;
        }

        // ── Logout ──
        if (/\b(log\s*out|logout)\b/.test(lower)) {
            this.addBubble('system', 'Logging you out...');
            this.speak('Logging you out.');
            localStorage.removeItem('mandate_phone');
            localStorage.removeItem('mandate_user');
            setTimeout(function() { location.reload(); }, 1500);
            return true;
        }

        return false;
    };

    // ── Read saved mandates ───────────────────────────────────

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
                this.addBubble('system', 'No mandate items yet for ' + level + ' level.');
                this.speak('No mandate items yet for ' + level + ' level.');
                return;
            }
            var intro = data.contributor_count + ' constituent' +
                (data.contributor_count !== 1 ? 's have' : ' has') + ' spoken. ';
            var list = data.items.map(function(item, i) {
                return (i + 1) + '. ' + item.content;
            }).join('. ');
            this.addBubble('system', intro + list);
            this.speak(intro + 'Top priorities: ' + list);
        } catch (err) {
            this.addBubble('system', 'Could not load mandate data.');
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

    MandateChat.prototype.showToast = function(msg, type) {
        if (!this.toastEl) return;
        this.toastEl.textContent = msg;
        this.toastEl.className = 'mc-toast show ' + (type || 'success');
        var el = this.toastEl;
        setTimeout(function() { el.classList.remove('show'); }, 3000);
    };

    // ── Text-to-Speech ────────────────────────────────────────

    MandateChat.prototype.speak = function(text) {
        if (!window.speechSynthesis) return;
        var self = this;
        var wasListening = this.micOn;
        if (wasListening && this.recognition) {
            this.ttsPaused = true;
            try { this.recognition.stop(); } catch(e) {}
        }
        var utter = new SpeechSynthesisUtterance(text);
        utter.rate = 1.0;
        utter.pitch = 1.0;
        var voices = speechSynthesis.getVoices();
        for (var i = 0; i < voices.length; i++) {
            if (voices[i].name.indexOf('Mark') !== -1) {
                utter.voice = voices[i];
                break;
            }
        }
        utter.onend = function() {
            self.ttsPaused = false;
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
