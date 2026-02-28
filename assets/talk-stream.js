/**
 * TalkStream — shared JavaScript for the Talk stream component.
 * Supports multiple instances per page via prefix-based DOM IDs.
 *
 * Usage:
 *   var ts = new TalkStream({ prefix: 'ts0', groupId: 42, ... });
 *   ts.init();
 */
window.TalkStream = (function() {
    'use strict';

    // ── Module-level utilities ──────────────────────────────────────────

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        var now = new Date();
        var diff = (now - d) / 1000;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        var opts = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
        if (d.getFullYear() !== now.getFullYear()) opts.year = 'numeric';
        return d.toLocaleDateString(undefined, opts);
    }

    /**
     * Content transforms: YouTube embeds, threat cross-links, plain URLs.
     * Ported from includes/talk-stream.php
     */
    function transformContent(text) {
        var html = escHtml(text);
        // YouTube embeds (youtube-nocookie.com for privacy)
        html = html.replace(
            /https?:\/\/(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})(?:&amp;[^\s]*)*/g,
            '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" allowfullscreen loading="lazy"></iframe></div>'
        );
        html = html.replace(
            /https?:\/\/youtu\.be\/([a-zA-Z0-9_-]{11})/g,
            '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/$1" allowfullscreen loading="lazy"></iframe></div>'
        );
        // Threat cross-links: #threat:NNN
        html = html.replace(
            /#threat:(\d+)/g,
            '<a href="/elections/threats.php#threat-$1">Threat #$1</a>'
        );
        // Auto-link remaining URLs
        html = html.replace(
            /(^|[\s>])(https?:\/\/[^\s<]+)/g,
            '$1<a href="$2" target="_blank" rel="noopener">$2</a>'
        );
        return html;
    }

    // ── Constructor ─────────────────────────────────────────────────────

    function TalkStream(config) {
        this.config = Object.assign({}, TalkStream.DEFAULTS, config);
        this.prefix = this.config.prefix;
        this.loadedIdeas = [];
        this.pollTimer = null;
        this.isSubmitting = false;
        this.isPolling = false;
        this.userRole = null;
        this.publicAccess = null;
        this.currentFilter = '';
        this.currentCategoryFilter = '';
        this.aiRespond = false;
        this.micOn = false;
        this.micBaseText = '';
        this.recognition = null;
        this.sessionId = sessionStorage.getItem('tpb_session');
        if (!this.sessionId) {
            this.sessionId = crypto.randomUUID();
            sessionStorage.setItem('tpb_session', this.sessionId);
        }
        this.formLoadTime = Math.floor(Date.now() / 1000);
        // Register instance
        TalkStream._instances[this.prefix] = this;
    }

    // ── Defaults ────────────────────────────────────────────────────────

    TalkStream.DEFAULTS = {
        apiBase: '/talk/api.php',
        groupId: null,
        geoStateId: null,
        geoTownId: null,
        currentUser: null,       // { user_id, display_name } or null
        canPost: false,
        isLoggedIn: false,
        isMember: false,
        showFilters: true,
        showCategories: true,
        showGroupSelector: false,
        showAiToggle: true,
        showMic: true,
        showAdminTools: 'auto',  // true/false/'auto'
        limit: 50,
        placeholder: "What's on your mind?",
        prefix: 'ts0'
    };

    // ── Instance registry ───────────────────────────────────────────────

    TalkStream._instances = {};

    // ── Prototype methods ───────────────────────────────────────────────

    /**
     * Initialise the stream: bind DOM, set up voice/AI, load ideas, start polling.
     */
    TalkStream.prototype.init = function() {
        this.bindDOM();
        if (this.config.showMic) this.initVoice();
        if (this.config.showAiToggle) this.initAiToggle();
        if (this.config.showGroupSelector) this.loadGroups();
        this.loadIdeas();
        if (this.config.groupId || this.config.geoStateId || this.config.geoTownId) {
            this.startPolling();
        }
        // Pause polling when tab hidden
        var self = this;
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                self.stopPolling();
            } else if (self.config.groupId || self.config.geoStateId || self.config.geoTownId) {
                self.pollForNew();
                self.startPolling();
            }
        });
    };

    /**
     * Cache DOM element references and attach event listeners.
     */
    TalkStream.prototype.bindDOM = function() {
        var P = this.prefix;
        this.inputEl      = document.getElementById(P + '-input');
        this.sendBtn      = document.getElementById(P + '-sendBtn');
        this.micBtn       = document.getElementById(P + '-micBtn');
        this.aiBtn        = document.getElementById(P + '-aiBtn');
        this.charCounter  = document.getElementById(P + '-charCounter');
        this.stream       = document.getElementById(P + '-stream');
        this.streamEmpty  = document.getElementById(P + '-streamEmpty');
        this.footerBar    = document.getElementById(P + '-footerBar');
        this.toastEl      = document.getElementById(P + '-toast');
        this.contextSelect = document.getElementById(P + '-contextSelect');

        var self = this;
        if (this.sendBtn) {
            this.sendBtn.addEventListener('click', function() { self.submitIdea(); });
        }
        if (this.inputEl) {
            this.inputEl.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 200) + 'px';
                self.updateCharCounter();
            });
            this.inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.submitIdea();
                }
            });
        }
        if (this.contextSelect) {
            this.contextSelect.addEventListener('change', function() {
                self.setGroup(parseInt(this.value) || null);
            });
        }
    };

    /**
     * Render a single idea card and return the DOM element.
     */
    TalkStream.prototype.renderIdeaCard = function(idea) {
        var card = document.createElement('div');
        var P = this.prefix;
        card.className = 'idea-card';
        card.id = P + '-idea-' + idea.id;
        card.dataset.createdAt = idea.created_at;

        // Card type styling
        if (idea.clerk_key && idea.category === 'chat') {
            card.classList.add('ai-response');
        } else if (idea.category === 'digest' && idea.status === 'distilled') {
            card.classList.add('crystal-card');
        } else if (idea.category === 'digest') {
            card.classList.add('digest-card');
        } else {
            card.classList.add('cat-' + (idea.category || 'idea'));
        }

        var isOwn = this.config.currentUser && idea.user_id == this.config.currentUser.user_id;
        var authorName = idea.author_display || (isOwn ? 'You' : 'Anonymous');
        var clerkBadge = idea.clerk_key
            ? ' <span class="clerk-badge">' + escHtml(idea.clerk_key) + '</span>'
            : '';
        var editedTag = idea.edit_count > 0
            ? ' <span class="edited-tag">(edited)</span>'
            : '';
        var timeStr = formatTime(idea.created_at);
        var inst = "TalkStream._instances['" + P + "']";

        // Header
        var idBadge = idea.id
            ? '<span class="card-id" onclick="' + inst + '.replyTo(' + idea.id + ')" title="Reply to #' + idea.id + '">#' + idea.id + '</span>'
            : '';
        var header = '<div class="card-header">'
            + '<span class="card-author">' + idBadge + escHtml(authorName) + clerkBadge + editedTag + '</span>'
            + '<span class="card-time" title="' + escHtml(idea.created_at) + '">' + timeStr + '</span>'
            + '</div>';

        // Content — use transformContent for YouTube/threat/URL processing
        var content = '<div class="card-content" id="' + P + '-content-' + idea.id + '">'
            + transformContent(idea.content)
            + '</div>';

        // Footer: tags + actions
        var tagsHtml = '';
        if (idea.category && idea.category !== 'chat') {
            tagsHtml += '<span class="card-tag cat-tag">' + escHtml(idea.category) + '</span>';
        }
        if (idea.tags) {
            idea.tags.split(',').forEach(function(t) {
                t = t.trim();
                if (t) tagsHtml += '<span class="card-tag">' + escHtml(t) + '</span>';
            });
        }
        if (idea.status && idea.status !== 'raw') {
            tagsHtml += '<span class="status-badge ' + idea.status + '">' + idea.status + '</span>';
        }

        var voteHtml = '';
        var canVote = (this.userRole || this.publicAccess === 'vote' || !this.config.groupId);
        if (!idea.clerk_key && idea.category !== 'digest') {
            if (canVote && this.config.currentUser) {
                var agreeActive = idea.user_vote === 'agree' ? ' active-agree' : '';
                var disagreeActive = idea.user_vote === 'disagree' ? ' active-disagree' : '';
                voteHtml = '<button class="vote-btn' + agreeActive + '" onclick="' + inst + '.voteIdea(' + idea.id + ',\'agree\')">\ud83d\udc4d <span class="count">' + (idea.agree_count || 0) + '</span></button>'
                    + '<button class="vote-btn' + disagreeActive + '" onclick="' + inst + '.voteIdea(' + idea.id + ',\'disagree\')">\ud83d\udc4e <span class="count">' + (idea.disagree_count || 0) + '</span></button>'
                    + '<button class="reply-btn" onclick="' + inst + '.replyTo(' + idea.id + ')" title="Reply">Reply</button>';
            } else if (this.publicAccess === 'read' || !this.config.currentUser) {
                voteHtml = '<span style="font-size:0.8rem;color:#888;">\ud83d\udc4d ' + (idea.agree_count || 0) + ' \u00b7 \ud83d\udc4e ' + (idea.disagree_count || 0) + '</span>';
            }
        }

        var actionsHtml = '';
        if (isOwn && !idea.clerk_key) {
            actionsHtml += '<button onclick="' + inst + '.startEdit(' + idea.id + ')" title="Edit">&#x270E;</button>';
            actionsHtml += '<button class="delete-btn" onclick="' + inst + '.deleteIdea(' + idea.id + ')" title="Delete">&#x2715;</button>';
            if (idea.status === 'raw') {
                actionsHtml += '<button onclick="' + inst + '.promote(' + idea.id + ',\'refining\')" title="Promote">&#x2B06;</button>';
            } else if (idea.status === 'refining') {
                actionsHtml += '<button onclick="' + inst + '.promote(' + idea.id + ',\'distilled\')" title="Promote">&#x2B06;</button>';
            }
        } else if (isOwn && idea.clerk_key) {
            actionsHtml += '<button class="delete-btn" onclick="' + inst + '.deleteIdea(' + idea.id + ')" title="Delete">&#x2715;</button>';
        }

        var footer = '<div class="card-footer">'
            + '<div class="card-tags">' + tagsHtml + '</div>'
            + '<div class="card-actions">' + voteHtml + actionsHtml + '</div>'
            + '</div>';

        card.innerHTML = header + content + footer;
        return card;
    };

    /**
     * Submit a new idea from the input textarea.
     */
    TalkStream.prototype.submitIdea = function() {
        // Stop mic if listening
        if (this.micOn && this.recognition) {
            this.micOn = false;
            this.recognition.stop();
        }
        var content = this.inputEl ? this.inputEl.value.trim() : '';
        if (!content || this.isSubmitting) return;

        var self = this;
        this.isSubmitting = true;
        this.sendBtn.disabled = true;
        this.sendBtn.textContent = '...';

        (async function() {
            // Auto-join if needed for open groups
            if (self.config.groupId && !self.config.isMember) {
                try {
                    var joinResp = await fetch(self.config.apiBase + '?action=join_group', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ group_id: self.config.groupId })
                    });
                    var joinData = await joinResp.json();
                    if (joinData.success) {
                        self.config.isMember = true;
                    } else {
                        self.showToast(joinData.error || 'Could not join', 'error');
                        self.isSubmitting = false;
                        self.sendBtn.disabled = false;
                        self.sendBtn.textContent = '\u27A4';
                        return;
                    }
                } catch (e) {
                    self.showToast('Network error', 'error');
                    self.isSubmitting = false;
                    self.sendBtn.disabled = false;
                    self.sendBtn.textContent = '\u27A4';
                    return;
                }
            }

            try {
                var groupId = self.config.groupId;
                var resp = await fetch(self.config.apiBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        content: content,
                        source: 'web',
                        session_id: self.sessionId,
                        group_id: groupId,
                        auto_classify: true,
                        website_url: (document.getElementById(self.prefix + '-hp') || {}).value || '',
                        _form_load_time: self.formLoadTime
                    })
                });
                var data = await resp.json();
                if (data.success && data.idea) {
                    self.inputEl.value = '';
                    self.inputEl.style.height = 'auto';
                    self.updateCharCounter();
                    self.prependIdea(data.idea);
                    if (self.aiRespond) {
                        await self.brainstormRespond(content, groupId);
                    }
                } else {
                    self.showToast(data.error || 'Save failed', 'error');
                }
            } catch (err) {
                self.showToast('Network error', 'error');
            }

            self.isSubmitting = false;
            self.sendBtn.disabled = false;
            self.sendBtn.textContent = '\u27A4';
        })();
    };

    /**
     * Request an AI brainstorm response and display it in the stream.
     */
    TalkStream.prototype.brainstormRespond = async function(message, groupId) {
        var thinkingId = this.prefix + '-thinking-' + Date.now();
        var thinkingCard = document.createElement('div');
        thinkingCard.id = thinkingId;
        thinkingCard.className = 'idea-card ai-response';
        thinkingCard.innerHTML = '<div class="card-header"><span class="card-author">AI</span></div>'
            + '<div class="card-content"><div class="ai-thinking-content">'
            + '<div class="ai-thinking-dots"><span></span><span></span><span></span></div>'
            + '<span class="ai-thinking-label">Thinking...</span>'
            + '</div></div>';
        this.stream.insertBefore(thinkingCard, this.stream.firstChild);

        var thinkingTimer = setTimeout(function() {
            var label = thinkingCard.querySelector('.ai-thinking-label');
            if (label) label.textContent = 'Still thinking, one moment...';
        }, 6000);

        var self = this;
        try {
            var resp = await fetch(this.config.apiBase + '?action=brainstorm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    history: [],
                    session_id: this.sessionId,
                    shareable: groupId ? 1 : 0,
                    group_id: groupId
                })
            });
            var data = await resp.json();
            clearTimeout(thinkingTimer);
            var el = document.getElementById(thinkingId);
            if (el) {
                if (data.success && data.ai_idea) {
                    el.parentNode.replaceChild(self.renderIdeaCard(data.ai_idea), el);
                } else if (data.success) {
                    el.querySelector('.card-content').textContent = data.response;
                } else {
                    el.querySelector('.card-content').textContent = data.error || 'AI unavailable';
                    el.querySelector('.card-content').style.color = '#ef5350';
                }
            }
        } catch (err) {
            clearTimeout(thinkingTimer);
            var el = document.getElementById(thinkingId);
            if (el) {
                el.querySelector('.card-content').textContent = 'Network error';
                el.querySelector('.card-content').style.color = '#ef5350';
            }
        }
    };

    /**
     * Load ideas from the API. Pass `before` timestamp to paginate.
     */
    TalkStream.prototype.loadIdeas = async function(before) {
        var url = this.config.apiBase + '?action=history&limit=' + this.config.limit;
        if (this.config.groupId) {
            url += '&group_id=' + this.config.groupId + '&include_chat=1';
        } else if (this.config.geoTownId) {
            url += '&town_id=' + this.config.geoTownId;
        } else if (this.config.geoStateId) {
            url += '&state_id=' + this.config.geoStateId;
        }
        if (this.currentFilter) url += '&status=' + this.currentFilter;
        if (this.currentCategoryFilter) url += '&category=' + this.currentCategoryFilter;
        if (before) url += '&before=' + encodeURIComponent(before);

        var self = this;
        try {
            var resp = await fetch(url);
            var data = await resp.json();
            if (!data.success) {
                this.streamEmpty.textContent = data.error || 'Could not load ideas';
                this.streamEmpty.style.display = 'block';
                return;
            }

            if (data.user_role !== undefined) this.userRole = data.user_role;
            if (data.public_access !== undefined) this.publicAccess = data.public_access;
            this.updateFooter();

            // Hide input area for non-member public viewers
            var inputArea = this.stream.parentNode.querySelector('.input-area');
            if (inputArea && this.config.groupId) {
                inputArea.style.display = (!this.userRole && this.publicAccess) ? 'none' : '';
            }

            if (!before) {
                this.stream.innerHTML = '';
                this.stream.appendChild(this.streamEmpty);
                this.loadedIdeas = [];
            }

            if (data.ideas.length === 0 && this.loadedIdeas.length === 0) {
                var msg = 'No ideas yet. What\'s on your mind?';
                if (this.config.groupId) msg = 'No ideas in this group yet. Start the conversation!';
                else if (this.config.geoTownId || this.config.geoStateId) msg = 'No ideas here yet. Be the first to share!';
                this.streamEmpty.textContent = msg;
                this.streamEmpty.style.display = 'block';
                return;
            }
            this.streamEmpty.style.display = 'none';

            // Remove existing load-more
            var existing = this.stream.querySelector('.load-more');
            if (existing) existing.remove();

            var inst = "TalkStream._instances['" + this.prefix + "']";
            data.ideas.forEach(function(idea) {
                if (self.loadedIdeas.some(function(i) { return i.id === idea.id; })) return;
                self.stream.appendChild(self.renderIdeaCard(idea));
                self.loadedIdeas.push(idea);
            });

            // Load more button
            if (data.ideas.length >= this.config.limit) {
                var oldest = data.ideas[data.ideas.length - 1];
                var loadMoreDiv = document.createElement('div');
                loadMoreDiv.className = 'load-more';
                loadMoreDiv.innerHTML = '<button onclick="' + inst + '.loadIdeas(\'' + oldest.created_at + '\')">Load older ideas</button>';
                this.stream.appendChild(loadMoreDiv);
            }
        } catch (err) {
            this.streamEmpty.textContent = 'Network error loading ideas';
            this.streamEmpty.style.display = 'block';
        }
    };

    /**
     * Poll for new ideas since the newest loaded idea.
     */
    TalkStream.prototype.pollForNew = async function() {
        if (this.isPolling || document.hidden) return;
        this.isPolling = true;
        var newest = this.loadedIdeas.length ? this.loadedIdeas[0].created_at : null;
        if (!newest) { this.isPolling = false; return; }

        var url = this.config.apiBase + '?action=history&since=' + encodeURIComponent(newest) + '&limit=20';
        if (this.config.groupId) {
            url += '&group_id=' + this.config.groupId + '&include_chat=1';
        } else if (this.config.geoTownId) {
            url += '&town_id=' + this.config.geoTownId;
        } else if (this.config.geoStateId) {
            url += '&state_id=' + this.config.geoStateId;
        }

        var self = this;
        try {
            var resp = await fetch(url);
            var data = await resp.json();
            if (data.success && data.ideas.length > 0) {
                data.ideas.forEach(function(idea) {
                    if (self.loadedIdeas.some(function(i) { return i.id === idea.id; })) return;
                    self.prependIdea(idea);
                });
            }
        } catch (e) { /* silent */ }
        this.isPolling = false;
    };

    /**
     * Start polling for new ideas every 8 seconds.
     */
    TalkStream.prototype.startPolling = function() {
        this.stopPolling();
        var self = this;
        this.pollTimer = setInterval(function() { self.pollForNew(); }, 8000);
    };

    /**
     * Stop the polling interval.
     */
    TalkStream.prototype.stopPolling = function() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    };

    /**
     * Prepend a single idea card to the top of the stream.
     */
    TalkStream.prototype.prependIdea = function(idea) {
        if (document.getElementById(this.prefix + '-idea-' + idea.id)) return;
        this.streamEmpty.style.display = 'none';
        this.stream.insertBefore(this.renderIdeaCard(idea), this.stream.firstChild);
        this.loadedIdeas.unshift(idea);
    };

    /**
     * Vote on an idea (agree / disagree toggle).
     */
    TalkStream.prototype.voteIdea = async function(ideaId, voteType) {
        if (!this.config.currentUser) { this.showToast('Log in to vote', 'error'); return; }
        try {
            var resp = await fetch(this.config.apiBase + '?action=vote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, vote_type: voteType })
            });
            var data = await resp.json();
            if (data.success) {
                var idea = this.loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea) {
                    idea.agree_count = data.agree_count;
                    idea.disagree_count = data.disagree_count;
                    idea.user_vote = data.user_vote;
                    var oldCard = document.getElementById(this.prefix + '-idea-' + ideaId);
                    if (oldCard) oldCard.replaceWith(this.renderIdeaCard(idea));
                }
            } else {
                this.showToast(data.error || 'Vote failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
    };

    /**
     * Show inline edit form for an idea.
     */
    TalkStream.prototype.startEdit = function(ideaId) {
        var contentEl = document.getElementById(this.prefix + '-content-' + ideaId);
        if (!contentEl || contentEl.style.display === 'none') return;
        var currentText = contentEl.textContent;
        contentEl.style.display = 'none';
        var inst = "TalkStream._instances['" + this.prefix + "']";
        var form = document.createElement('div');
        form.className = 'inline-edit';
        form.id = this.prefix + '-edit-form-' + ideaId;
        form.innerHTML = '<textarea>' + escHtml(currentText) + '</textarea>'
            + '<div class="edit-actions">'
            + '<button class="edit-cancel" onclick="' + inst + '.cancelEdit(' + ideaId + ')">Cancel</button>'
            + '<button class="edit-save" onclick="' + inst + '.saveEdit(' + ideaId + ')">Save</button>'
            + '</div>';
        contentEl.parentNode.insertBefore(form, contentEl.nextSibling);
    };

    /**
     * Cancel an inline edit and restore the original content.
     */
    TalkStream.prototype.cancelEdit = function(ideaId) {
        var form = document.getElementById(this.prefix + '-edit-form-' + ideaId);
        if (form) form.remove();
        var contentEl = document.getElementById(this.prefix + '-content-' + ideaId);
        if (contentEl) contentEl.style.display = '';
    };

    /**
     * Save an inline edit to the API.
     */
    TalkStream.prototype.saveEdit = async function(ideaId) {
        var form = document.getElementById(this.prefix + '-edit-form-' + ideaId);
        if (!form) return;
        var newContent = form.querySelector('textarea').value.trim();
        if (!newContent) return;
        try {
            var resp = await fetch(this.config.apiBase + '?action=edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, content: newContent })
            });
            var data = await resp.json();
            if (data.success) {
                form.remove();
                var contentEl = document.getElementById(this.prefix + '-content-' + ideaId);
                if (contentEl) {
                    contentEl.innerHTML = transformContent(newContent);
                    contentEl.style.display = '';
                }
                this.showToast('Saved', 'success');
            } else {
                this.showToast(data.error || 'Edit failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
    };

    /**
     * Delete an idea after confirmation.
     */
    TalkStream.prototype.deleteIdea = async function(ideaId) {
        if (!confirm('Delete this idea?')) return;
        try {
            var resp = await fetch(this.config.apiBase + '?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId })
            });
            var data = await resp.json();
            if (data.success) {
                var card = document.getElementById(this.prefix + '-idea-' + ideaId);
                if (card) card.remove();
                this.loadedIdeas = this.loadedIdeas.filter(function(i) { return i.id !== ideaId; });
                if (this.loadedIdeas.length === 0) {
                    this.streamEmpty.textContent = 'No ideas yet.';
                    this.streamEmpty.style.display = 'block';
                }
                this.showToast('Deleted', 'success');
            } else {
                this.showToast(data.error || 'Delete failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
    };

    /**
     * Promote an idea to a new status (raw -> refining -> distilled).
     */
    TalkStream.prototype.promote = async function(ideaId, newStatus) {
        try {
            var resp = await fetch(this.config.apiBase + '?action=promote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idea_id: ideaId, status: newStatus })
            });
            var data = await resp.json();
            if (data.success) {
                var card = document.getElementById(this.prefix + '-idea-' + ideaId);
                var idea = this.loadedIdeas.find(function(i) { return i.id === ideaId; });
                if (idea && card) {
                    idea.status = newStatus;
                    card.replaceWith(this.renderIdeaCard(idea));
                }
                this.showToast('Promoted to ' + newStatus, 'success');
            } else {
                this.showToast(data.error || 'Promote failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
    };

    /**
     * Run the AI gatherer to find connections between ideas.
     */
    TalkStream.prototype.runGather = async function() {
        var btn = document.getElementById(this.prefix + '-gatherBtn');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = 'Gathering...';
        this.showToast('Running gatherer...', 'info');
        try {
            var resp = await fetch(this.config.apiBase + '?action=gather', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: this.config.groupId })
            });
            var data = await resp.json();
            if (data.success) {
                var linkCount = (data.actions || []).filter(function(a) { return a.action === 'LINK' && a.success; }).length;
                var summaryCount = (data.actions || []).filter(function(a) { return a.action === 'SUMMARIZE' && a.success; }).length;
                this.showToast('Found ' + linkCount + ' connections, created ' + summaryCount + ' digest(s)', 'success');
                this.loadedIdeas = [];
                this.loadIdeas();
            } else {
                this.showToast(data.error || 'Gather failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Gather';
    };

    /**
     * Run the AI crystallizer to produce a proposal from the group's ideas.
     */
    TalkStream.prototype.runCrystallize = async function() {
        if (!confirm('Crystallize this group into a proposal?')) return;
        var btn = document.getElementById(this.prefix + '-crystallizeBtn');
        if (!btn) return;
        btn.disabled = true;
        btn.textContent = 'Crystallizing...';
        this.showToast('Crystallizing...', 'info');
        try {
            var resp = await fetch(this.config.apiBase + '?action=crystallize', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ group_id: this.config.groupId })
            });
            var data = await resp.json();
            if (data.success) {
                this.showToast('Proposal created!', 'success');
                this.loadedIdeas = [];
                this.loadIdeas();
            } else {
                this.showToast(data.error || 'Crystallize failed', 'error');
            }
        } catch (err) {
            this.showToast('Network error', 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Crystallize';
    };

    /**
     * Set the status filter and reload ideas.
     */
    TalkStream.prototype.setFilter = function(status) {
        this.currentFilter = status;
        var bar = document.getElementById(this.prefix + '-filterBar');
        if (bar) {
            bar.querySelectorAll('.filter-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.filter === status);
            });
        }
        this.loadedIdeas = [];
        this.loadIdeas();
    };

    /**
     * Set the category filter and reload ideas.
     */
    TalkStream.prototype.setCategoryFilter = function(cat) {
        this.currentCategoryFilter = cat;
        var bar = document.getElementById(this.prefix + '-catBar');
        if (bar) {
            bar.querySelectorAll('.cat-btn').forEach(function(btn) {
                btn.classList.toggle('active', btn.dataset.cat === cat);
            });
        }
        this.loadedIdeas = [];
        this.loadIdeas();
    };

    /**
     * Pre-fill the input textarea with a reply prefix for the given idea.
     */
    TalkStream.prototype.replyTo = function(ideaId) {
        if (!this.inputEl) return;
        var prefix = 're: #' + ideaId + ' - ';
        if (this.inputEl.value.indexOf(prefix) === 0) {
            this.inputEl.focus();
            return;
        }
        this.inputEl.value = prefix;
        this.inputEl.focus();
        this.inputEl.setSelectionRange(prefix.length, prefix.length);
        this.inputEl.style.height = 'auto';
        this.inputEl.style.height = Math.min(this.inputEl.scrollHeight, 200) + 'px';
        this.updateCharCounter();
        this.inputEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    /**
     * Show a toast notification.
     */
    TalkStream.prototype.showToast = function(msg, type) {
        if (!this.toastEl) return;
        this.toastEl.textContent = msg;
        this.toastEl.className = 'toast ' + type;
        clearTimeout(this.toastEl._timer);
        var el = this.toastEl;
        this.toastEl._timer = setTimeout(function() { el.classList.add('hidden'); }, 3000);
    };

    /**
     * Update the character counter below the input textarea.
     */
    TalkStream.prototype.updateCharCounter = function() {
        if (!this.inputEl || !this.charCounter) return;
        var len = this.inputEl.value.length;
        this.charCounter.textContent = len.toLocaleString() + ' / 2,000';
        this.charCounter.className = 'char-counter' + (len > 1800 ? (len > 2000 ? ' over' : ' warn') : '');
    };

    /**
     * Show or hide the admin footer bar based on config and user role.
     */
    TalkStream.prototype.updateFooter = function() {
        if (!this.footerBar) return;
        var show = false;
        if (this.config.showAdminTools === true) show = true;
        else if (this.config.showAdminTools === 'auto' && this.config.groupId && this.userRole === 'facilitator') show = true;
        this.footerBar.classList.toggle('visible', show);
    };

    /**
     * Switch the stream to a different group context.
     */
    TalkStream.prototype.setGroup = function(groupId) {
        this.config.groupId = groupId;
        this.stopPolling();
        this.loadedIdeas = [];
        this.userRole = null;
        this.publicAccess = null;
        this.loadIdeas();
        if (groupId) this.startPolling();
    };

    /**
     * Populate the group selector dropdown with the user's groups.
     */
    TalkStream.prototype.loadGroups = async function() {
        if (!this.contextSelect || !this.config.currentUser) return;
        try {
            var resp = await fetch(this.config.apiBase + '?action=list_groups&mine=1');
            var data = await resp.json();
            if (data.success && data.groups) {
                var self = this;
                data.groups.forEach(function(g) {
                    if (g.status === 'archived') return;
                    var opt = document.createElement('option');
                    opt.value = g.id;
                    opt.textContent = g.name;
                    opt.style.cssText = 'background:#1a1a2e;color:#eee;';
                    self.contextSelect.appendChild(opt);
                });
            }
        } catch (e) { /* silent */ }
        // Restore saved context
        var saved = localStorage.getItem('tpb_talk_context');
        if (saved && this.contextSelect) {
            for (var i = 0; i < this.contextSelect.options.length; i++) {
                if (this.contextSelect.options[i].value === saved) {
                    this.contextSelect.value = saved;
                    this.config.groupId = parseInt(saved);
                    break;
                }
            }
        }
    };

    /**
     * Initialise speech recognition (mic button).
     */
    TalkStream.prototype.initVoice = function() {
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
        };
        this.recognition.onend = function() {
            self.micBtn.classList.remove('listening');
            self.micBtn.textContent = '\uD83C\uDFA4';
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
            self.inputEl.style.height = 'auto';
            self.inputEl.style.height = Math.min(self.inputEl.scrollHeight, 120) + 'px';
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

    /**
     * Initialise the AI toggle button.
     */
    TalkStream.prototype.initAiToggle = function() {
        if (!this.aiBtn) return;
        var self = this;
        this.aiBtn.addEventListener('click', function() {
            self.aiRespond = !self.aiRespond;
            self.aiBtn.classList.toggle('active', self.aiRespond);
        });
    };

    return TalkStream;
})();
