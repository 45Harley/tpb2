/**
 * Claudia Inline Mandate Form — JS
 * Handles topic selection, submission to save-idea API, status feedback.
 */
(function() {
    'use strict';

    var config = window._claudiaInlineConfig;
    if (!config || !config.userId) return; // Not logged in

    var topics = document.getElementById('claudia-inline-topics');
    var input = document.getElementById('claudia-inline-input');
    var sendBtn = document.getElementById('claudia-inline-send');
    var statusEl = document.getElementById('claudia-inline-status');
    if (!topics || !input || !sendBtn) return;

    var selectedTopic = null;

    // Topic pill selection
    topics.addEventListener('click', function(e) {
        var pill = e.target.closest('.claudia-topic-pill');
        if (!pill) return;

        // Toggle — click same pill to deselect
        if (pill.classList.contains('active')) {
            pill.classList.remove('active');
            selectedTopic = null;
        } else {
            topics.querySelectorAll('.claudia-topic-pill').forEach(function(p) {
                p.classList.remove('active');
            });
            pill.classList.add('active');
            selectedTopic = pill.dataset.topic;
        }
        updateSendState();
    });

    // Enable send when there's text
    input.addEventListener('input', updateSendState);

    function updateSendState() {
        sendBtn.disabled = !input.value.trim();
    }

    // Submit
    sendBtn.addEventListener('click', function() {
        var text = input.value.trim();
        if (!text) return;

        sendBtn.disabled = true;
        statusEl.textContent = 'Saving...';
        statusEl.className = 'claudia-inline-status';

        var body = {
            content: text,
            category: config.category,
            policy_topic: selectedTopic || null
        };

        fetch('/api/save-idea.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success || data.status === 'success') {
                statusEl.textContent = 'Mandate saved!';
                statusEl.className = 'claudia-inline-status success';
                input.value = '';

                // Deselect topic
                if (topics) {
                    topics.querySelectorAll('.claudia-topic-pill').forEach(function(p) {
                        p.classList.remove('active');
                    });
                }
                selectedTopic = null;
                updateSendState();

                // Refresh talk stream if present on page
                if (window.TalkStream && window.TalkStream._instances) {
                    var keys = Object.keys(window.TalkStream._instances);
                    keys.forEach(function(k) {
                        window.TalkStream._instances[k].refresh();
                    });
                }

                // Award points feedback
                if (data.points_earned && window.tpbUpdateNavPoints) {
                    window.tpbUpdateNavPoints(null, data.points_earned);
                }

                setTimeout(function() { statusEl.textContent = ''; }, 3000);
            } else {
                statusEl.textContent = data.message || data.error || 'Save failed';
                statusEl.className = 'claudia-inline-status error';
            }
        })
        .catch(function() {
            statusEl.textContent = 'Network error. Try again.';
            statusEl.className = 'claudia-inline-status error';
        })
        .finally(function() {
            updateSendState();
        });
    });

    // Enter to submit (shift+enter for newline)
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!sendBtn.disabled) sendBtn.click();
        }
    });
})();
