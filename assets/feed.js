/**
 * Feed Admin JS — Civic Engine Phase 4
 * AJAX sync controls for the feed admin page.
 */

(function () {
    'use strict';

    /**
     * Show a message in the #feedMessage element.
     * @param {string} text
     * @param {'success'|'error'|'info'} type
     */
    function showMessage(text, type) {
        var el = document.getElementById('feedMessage');
        if (!el) return;
        el.textContent = text;
        el.className = 'feed-message ' + type;
        el.style.display = 'block';
        // Auto-hide after 10s for success/info
        if (type !== 'error') {
            setTimeout(function () {
                el.style.display = 'none';
            }, 10000);
        }
    }

    /**
     * Sync all sources via the feed API.
     */
    window.feedSyncAll = function () {
        var btn = document.getElementById('syncAllBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Syncing...';
        }

        fetch('/api/feed.php?action=sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    showMessage('Error: ' + data.error, 'error');
                    return;
                }

                var results = data.results || {};
                var parts = [];
                var totalCreated = 0;

                ['threats', 'bills', 'executive_orders', 'declarations'].forEach(function (key) {
                    var r = results[key];
                    if (!r) return;
                    if (r.error) {
                        parts.push(key + ': ' + r.error);
                    } else {
                        totalCreated += (r.created || 0);
                        if (r.created > 0) {
                            parts.push(key + ': ' + r.created + ' created');
                        }
                    }
                });

                if (totalCreated > 0) {
                    showMessage('Sync complete. ' + parts.join(', ') + '. Reload to see updated stats.', 'success');
                } else {
                    showMessage('Sync complete. Everything is up to date. ' + parts.join(', '), 'info');
                }
            })
            .catch(function (err) {
                showMessage('Network error: ' + err.message, 'error');
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Sync All Sources';
                }
            });
    };

    /**
     * Sync a single source type.
     * Individual sync just runs syncAll and shows results for that source.
     * @param {string} sourceType - threats|bills|executive_orders|declarations
     */
    window.feedSync = function (sourceType) {
        var btnId = {
            threats: 'syncThreatsBtn',
            bills: 'syncBillsBtn',
            executive_orders: 'syncEOBtn',
            declarations: 'syncDeclBtn'
        }[sourceType];

        var btn = btnId ? document.getElementById(btnId) : null;
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Syncing...';
        }

        fetch('/api/feed.php?action=sync', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    showMessage('Error: ' + data.error, 'error');
                    return;
                }

                var r = (data.results || {})[sourceType];
                if (!r) {
                    showMessage('No results for ' + sourceType, 'info');
                    return;
                }

                if (r.error) {
                    showMessage(sourceType + ': ' + r.error, 'info');
                } else if (r.created > 0) {
                    showMessage(sourceType + ': ' + r.created + ' poll(s) created, ' + r.skipped + ' skipped. Reload to see updated table.', 'success');
                } else {
                    showMessage(sourceType + ': all synced, nothing new to create.', 'info');
                }
            })
            .catch(function (err) {
                showMessage('Network error: ' + err.message, 'error');
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    // Restore original text
                    var labels = {
                        threats: 'Sync Threats',
                        bills: 'Sync Bills',
                        executive_orders: 'Sync Orders',
                        declarations: 'Sync Declarations'
                    };
                    btn.textContent = labels[sourceType] || 'Sync';
                }
            });
    };
})();
