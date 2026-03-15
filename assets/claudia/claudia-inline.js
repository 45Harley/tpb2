/**
 * Claudia Inline Mandate Form — JS
 * =================================
 * Matches mandate-poc.php wiring: topic selection, mandate submission,
 * mandate summary with level tabs, agree/disagree voting, delegation popup.
 */
(function() {
    'use strict';

    var config = window._claudiaInlineConfig;
    if (!config) return;

    // ── DOM refs ──────────────────────────────────────────────
    var topics    = document.getElementById('claudia-inline-topics');
    var input     = document.getElementById('claudia-inline-input');
    var sendBtn   = document.getElementById('claudia-inline-send');
    var statusEl  = document.getElementById('claudia-inline-status');
    var titleEl   = document.getElementById('claudia-inline-summary-title');
    var bodyEl    = document.getElementById('claudia-inline-summary-body');
    var levelTabs = document.getElementById('claudia-inline-level-tabs');

    var selectedTopic = null;

    // ── Utility ───────────────────────────────────────────────
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // ══════════════════════════════════════════════════════════
    // TOPIC PILLS
    // ══════════════════════════════════════════════════════════
    if (topics) {
        topics.addEventListener('click', function(e) {
            var pill = e.target.closest('.mandate-topic-pill');
            if (!pill) return;
            if (pill.classList.contains('active')) {
                pill.classList.remove('active');
                selectedTopic = null;
            } else {
                topics.querySelectorAll('.mandate-topic-pill').forEach(function(p) {
                    p.classList.remove('active');
                });
                pill.classList.add('active');
                selectedTopic = pill.dataset.topic;
            }
            updateSendState();
        });
    }

    // ══════════════════════════════════════════════════════════
    // MANDATE INPUT & SUBMIT
    // ══════════════════════════════════════════════════════════
    if (input) {
        input.addEventListener('input', updateSendState);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (sendBtn && !sendBtn.disabled) sendBtn.click();
            }
        });
    }

    function updateSendState() {
        if (sendBtn) sendBtn.disabled = !(input && input.value.trim());
    }

    if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            var text = input ? input.value.trim() : '';
            if (!text) return;

            sendBtn.disabled = true;
            setStatus('Saving...', '');

            var body = {
                content: text,
                category: config.category,
                policy_topic: selectedTopic || null
            };

            fetch('/talk/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success || data.status === 'success') {
                    setStatus('Mandate saved!', 'success');
                    input.value = '';
                    if (topics) {
                        topics.querySelectorAll('.mandate-topic-pill').forEach(function(p) {
                            p.classList.remove('active');
                        });
                    }
                    selectedTopic = null;
                    updateSendState();

                    // Refresh mandate summary
                    var activeTab = levelTabs ? levelTabs.querySelector('.level-tab.active') : null;
                    var activeLevel = activeTab ? (activeTab.dataset.level || '') : '';
                    loadSummary(levelToApi(activeLevel));

                    // Refresh talk stream if present
                    if (window.TalkStream && window.TalkStream._instances) {
                        Object.keys(window.TalkStream._instances).forEach(function(k) {
                            window.TalkStream._instances[k].refresh();
                        });
                    }

                    // Points feedback
                    if (data.points_earned && window.tpbUpdateNavPoints) {
                        window.tpbUpdateNavPoints(null, data.points_earned);
                    }

                    setTimeout(function() { setStatus('', ''); }, 3000);
                } else {
                    setStatus(data.message || data.error || 'Save failed', 'error');
                }
            })
            .catch(function() {
                setStatus('Network error. Try again.', 'error');
            })
            .finally(function() {
                updateSendState();
            });
        });
    }

    function setStatus(msg, cls) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'mandate-status' + (cls ? ' ' + cls : '');
    }

    // ══════════════════════════════════════════════════════════
    // MANDATE SUMMARY — Level Tabs + Loading + Voting
    // (Matches mandate-poc.php wiring exactly)
    // ══════════════════════════════════════════════════════════

    function levelToApi(dataLevel) {
        switch (dataLevel) {
            case 'mandate-federal': return 'federal';
            case 'mandate-state':   return 'state';
            case 'mandate-town':    return 'town';
            case 'mine':            return 'mine';
            default:                return 'all';
        }
    }

    function buildUrl(level) {
        if (level === 'mine') {
            return '/api/mandate-aggregate.php?level=mine&user_id=' + encodeURIComponent(config.userId);
        }
        var base = '/api/mandate-aggregate.php?level=' + encodeURIComponent(level);
        switch (level) {
            case 'federal':
                if (config.userDistrict) base += '&district=' + encodeURIComponent(config.userDistrict);
                break;
            case 'state':
                if (config.userStateId) base += '&state_id=' + encodeURIComponent(config.userStateId);
                break;
            case 'town':
                if (config.userTownId) base += '&town_id=' + encodeURIComponent(config.userTownId);
                break;
            case 'all':
                if (config.userDistrict) base += '&district=' + encodeURIComponent(config.userDistrict);
                if (config.userStateId) base += '&state_id=' + encodeURIComponent(config.userStateId);
                if (config.userTownId) base += '&town_id=' + encodeURIComponent(config.userTownId);
                break;
        }
        return base;
    }

    function buildTitle(level) {
        switch (level) {
            case 'mine':    return 'My Mandates';
            case 'federal': return config.userDistrict
                ? 'Constituent Mandate for ' + escHtml(config.userDistrict)
                : 'Constituent Mandate (Federal)';
            case 'state':   return config.userStateName
                ? 'Constituent Mandate for ' + escHtml(config.userStateName)
                : 'Constituent Mandate (State)';
            case 'town':    return config.userTownName
                ? 'Constituent Mandate for ' + escHtml(config.userTownName)
                : 'Constituent Mandate (Town)';
            default:        return 'Public Mandate Summary';
        }
    }

    // Cache items for vote updates
    var cachedItems = [];

    function loadSummary(level) {
        if (titleEl) titleEl.innerHTML = buildTitle(level);
        if (!bodyEl) return;
        bodyEl.innerHTML = '<p style="color:#b0b0b0;">Loading...</p>';

        fetch(buildUrl(level))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || data.item_count === 0) {
                    bodyEl.innerHTML = '<p style="color:#b0b0b0;">No mandate items yet for this scope.</p>';
                    cachedItems = [];
                    return;
                }

                cachedItems = data.items;

                var html = '<p style="color:#81c784; font-size:0.95rem; margin-bottom:0.75rem;">'
                    + data.contributor_count + ' constituent'
                    + (data.contributor_count !== 1 ? 's' : '')
                    + ' ha' + (data.contributor_count !== 1 ? 've' : 's')
                    + ' spoken.</p>';

                data.items.forEach(function(item) {
                    html += renderMandateItem(item);
                });

                bodyEl.innerHTML = html;
            })
            .catch(function() {
                bodyEl.innerHTML = '<p style="color:#e63946;">Failed to load mandate summary.</p>';
            });
    }

    function renderMandateItem(item) {
        var agreeActive  = item.user_vote === 'agree' ? ' active-agree' : '';
        var disagreeActive = item.user_vote === 'disagree' ? ' active-disagree' : '';
        var canVote = config.userId ? true : false;

        var html = '<div class="mandate-item" data-id="' + item.id + '">';
        html += '<div class="mandate-item-content">' + escHtml(item.content) + '</div>';
        html += '<div class="mandate-item-meta">';

        if (item.level) {
            html += '<span class="mandate-item-level">' + escHtml(item.level) + '</span>';
        }
        if (item.policy_topic) {
            html += '<span class="mandate-item-topic">' + escHtml(item.policy_topic) + '</span>';
        } else if (item.tags) {
            html += '<span class="mandate-item-topic">' + escHtml(item.tags) + '</span>';
        }

        html += '<span class="mandate-item-actions">';
        if (canVote) {
            html += '<button class="mandate-vote-btn' + agreeActive + '" onclick="window._ciVote(' + item.id + ',\'agree\')" title="Agree">'
                + '\ud83d\udc4d <span class="count">' + (item.agree_count || 0) + '</span></button>';
            html += '<button class="mandate-vote-btn' + disagreeActive + '" onclick="window._ciVote(' + item.id + ',\'disagree\')" title="Disagree">'
                + '\ud83d\udc4e <span class="count">' + (item.disagree_count || 0) + '</span></button>';
        } else {
            html += '<span style="font-size:0.85rem;color:#b0b0b0;">\ud83d\udc4d ' + (item.agree_count || 0)
                + ' \u00b7 \ud83d\udc4e ' + (item.disagree_count || 0) + '</span>';
        }
        html += '</span>';

        html += '</div></div>';
        return html;
    }

    // ── Voting ────────────────────────────────────────────────
    window._ciVote = function(ideaId, voteType) {
        if (!config.userId) return;

        fetch('/talk/api.php?action=vote', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ idea_id: ideaId, vote_type: voteType })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Update cached item
                for (var i = 0; i < cachedItems.length; i++) {
                    if (cachedItems[i].id === ideaId) {
                        cachedItems[i].agree_count = data.agree_count;
                        cachedItems[i].disagree_count = data.disagree_count;
                        cachedItems[i].user_vote = data.user_vote;
                        break;
                    }
                }
                // Re-render the single item in place
                var el = bodyEl.querySelector('.mandate-item[data-id="' + ideaId + '"]');
                if (el) {
                    var item = cachedItems.find(function(it) { return it.id === ideaId; });
                    if (item) {
                        var tmp = document.createElement('div');
                        tmp.innerHTML = renderMandateItem(item);
                        el.replaceWith(tmp.firstChild);
                    }
                }
            }
        })
        .catch(function() { /* silent */ });
    };

    // ── Level tab switching (matches mandate-poc.php) ─────────
    if (levelTabs) {
        var tabs = levelTabs.querySelectorAll('.level-tab');
        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                tabs.forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                loadSummary(levelToApi(tab.dataset.level || ''));
            });
        });
    }

    // Expose for external refresh (e.g. Claudia widget)
    window.refreshMandateSummary = function(level) {
        loadSummary(level || 'all');
    };

    // ── Initial load ──────────────────────────────────────────
    loadSummary('all');

    // ══════════════════════════════════════════════════════════
    // DELEGATION POPUP (matches mandate-poc.php exactly)
    // ══════════════════════════════════════════════════════════
    var geoTrigger   = document.getElementById('claudia-inline-geo');
    var summaryTitle = document.getElementById('claudia-inline-summary-title');
    var popup        = document.getElementById('claudia-inline-delegation');
    var popupBody    = document.getElementById('claudia-delegation-body');
    var popupClose   = document.getElementById('claudia-delegation-close');
    var popupHandle  = document.getElementById('claudia-delegation-drag');
    var delegLoaded  = false;

    // Both geo info and summary title open the delegation popup (like mandate-poc)
    var triggers = [geoTrigger, summaryTitle].filter(Boolean);

    function openPopup(e) {
        if (!popup) return;
        e.stopPropagation();
        if (popup.classList.contains('open')) {
            popup.classList.remove('open');
            return;
        }
        var rect = e.currentTarget.getBoundingClientRect();
        popup.style.top  = (rect.bottom + 8) + 'px';
        popup.style.left = Math.max(10, rect.left) + 'px';
        popup.classList.add('open');
        if (!delegLoaded) loadDelegation();
    }

    triggers.forEach(function(el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', openPopup);
    });

    if (popupClose) {
        popupClose.addEventListener('click', function() {
            popup.classList.remove('open');
        });
    }

    if (popup) {
        document.addEventListener('click', function(e) {
            if (!popup.classList.contains('open')) return;
            if (popup.contains(e.target)) return;
            for (var j = 0; j < triggers.length; j++) {
                if (triggers[j].contains(e.target)) return;
            }
            popup.classList.remove('open');
        });
    }

    function loadDelegation() {
        delegLoaded = true;
        var stateAbbr = (config.userStateAbbr || '').toLowerCase();
        var district  = config.userDistrict || '';
        var url = '/api/get-delegation.php?state=' + encodeURIComponent(stateAbbr)
                + '&district=' + encodeURIComponent(district);

        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var html = '';
                if (data.federal && data.federal.length) html += buildDelegGroup('Federal', data.federal);
                if (data.state && data.state.length) html += buildDelegGroup('State', data.state);
                if (!html) html = '<div class="delegation-loading">No representatives found.</div>';
                if (popupBody) popupBody.innerHTML = html;
            })
            .catch(function() {
                if (popupBody) popupBody.innerHTML = '<div class="delegation-loading">Failed to load.</div>';
            });
    }

    function buildDelegGroup(label, officials) {
        var html = '<div class="delegation-group"><div class="delegation-group-title">' + label + '</div>';
        for (var i = 0; i < officials.length; i++) {
            var o = officials[i];
            var partyClass = (o.party || '').toLowerCase().indexOf('democrat') >= 0 ? 'dem'
                           : (o.party || '').toLowerCase().indexOf('republican') >= 0 ? 'rep' : 'ind';
            var partyShort = partyClass === 'dem' ? 'D' : partyClass === 'rep' ? 'R' : 'I';
            html += '<div class="delegation-card">';
            if (o.photo) {
                html += '<img class="delegation-photo" src="' + escHtml(o.photo) + '" alt="' + escHtml(o.name) + '" onerror="this.outerHTML=\'<div class=delegation-photo-placeholder>&#x1F464;</div>\'">';
            } else {
                html += '<div class="delegation-photo-placeholder">&#x1F464;</div>';
            }
            html += '<div class="delegation-info">';
            html += '<div class="delegation-name">' + escHtml(o.name) + '</div>';
            html += '<div class="delegation-title">' + escHtml(o.title) + '</div>';
            html += '<span class="delegation-party ' + partyClass + '">' + partyShort + ' \u2014 ' + escHtml(o.party) + '</span>';
            html += '<div class="delegation-links">';
            if (o.phone) html += '<a href="tel:' + escHtml(o.phone) + '">\ud83d\udcde ' + escHtml(o.phone) + '</a>';
            if (o.website) html += '<a href="' + escHtml(o.website) + '" target="_blank">\ud83c\udf10 Website</a>';
            html += '</div></div></div>';
        }
        html += '</div>';
        return html;
    }

    // ── Draggable popup (matches mandate-poc.php) ─────────────
    if (popupHandle && popup) {
        var isDragging = false, dragX = 0, dragY = 0;

        popupHandle.addEventListener('mousedown', function(e) {
            isDragging = true;
            dragX = e.clientX - popup.offsetLeft;
            dragY = e.clientY - popup.offsetTop;
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup', stopDrag);
            e.preventDefault();
        });

        popupHandle.addEventListener('touchstart', function(e) {
            isDragging = true;
            var t = e.touches[0];
            dragX = t.clientX - popup.offsetLeft;
            dragY = t.clientY - popup.offsetTop;
            document.addEventListener('touchmove', onDragTouch, {passive: false});
            document.addEventListener('touchend', stopDrag);
            e.preventDefault();
        }, {passive: false});

        function onDrag(e) {
            if (!isDragging) return;
            popup.style.left = (e.clientX - dragX) + 'px';
            popup.style.top  = (e.clientY - dragY) + 'px';
        }
        function onDragTouch(e) {
            if (!isDragging) return;
            var t = e.touches[0];
            popup.style.left = (t.clientX - dragX) + 'px';
            popup.style.top  = (t.clientY - dragY) + 'px';
            e.preventDefault();
        }
        function stopDrag() {
            isDragging = false;
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup', stopDrag);
            document.removeEventListener('touchmove', onDragTouch);
            document.removeEventListener('touchend', stopDrag);
        }
    }
})();
