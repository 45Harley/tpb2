// =====================================================
// CLAUDIA POP-OUT — Cross-Tab Messaging
// =====================================================
(function() {
    'use strict';

    if (!window.BroadcastChannel) return; // Unsupported browser — no pop-out

    var CONFIG = window.ClaudiaConfig || {};
    var channel = new BroadcastChannel('claudia');

    if (CONFIG.isPopout) {
        // === POP-OUT WINDOW ===

        // Notify widget tabs that pop-out is open
        channel.postMessage({ type: 'popout_open' });

        // Receive page events from browsing tabs
        channel.onmessage = function(e) {
            if (e.data.type === 'page_event' && window.ClaudiaCore) {
                window.ClaudiaCore.onPageEvent(e.data.event, e.data.data);
            }
            if (e.data.type === 'state_sync') {
                // Restore flow state + history from widget
                if (e.data.flowState && window.ClaudiaCore) {
                    var fs = e.data.flowState;
                    for (var key in fs) {
                        window.ClaudiaCore.setFlowState(key, fs[key]);
                    }
                }
                if (e.data.history) {
                    // Replay messages into the pop-out chat
                    e.data.history.forEach(function(msg) {
                        window.ClaudiaCore.addMessage(msg.content, msg.role === 'assistant' ? 'c' : 'user');
                    });
                }
            }
        };

        // On close, notify widget tabs to re-activate
        window.addEventListener('beforeunload', function() {
            channel.postMessage({
                type: 'popout_close',
                flowState: window.ClaudiaCore ? window.ClaudiaCore.getFlowState() : null,
                history: window.ClaudiaCore ? window.ClaudiaCore.getHistory() : []
            });
        });

    } else {
        // === WIDGET TAB (browsing page) ===

        // Listen for pop-out open/close
        channel.onmessage = function(e) {
            if (e.data.type === 'popout_open') {
                // Go dormant — hide widget
                var widget = document.getElementById('claudia-widget');
                if (widget) widget.style.display = 'none';
                // Send current state to pop-out
                channel.postMessage({
                    type: 'state_sync',
                    flowState: window.ClaudiaCore ? window.ClaudiaCore.getFlowState() : null,
                    history: window.ClaudiaCore ? window.ClaudiaCore.getHistory() : []
                });
            }
            if (e.data.type === 'navigate') {
                // Pop-out is telling us to navigate
                window.location.href = e.data.url;
            }
            if (e.data.type === 'popout_close') {
                // Wake up — show widget
                var widget = document.getElementById('claudia-widget');
                if (widget) widget.style.display = '';
                // Restore state from pop-out
                if (e.data.flowState && window.ClaudiaCore) {
                    var fs = e.data.flowState;
                    for (var key in fs) {
                        window.ClaudiaCore.setFlowState(key, fs[key]);
                    }
                }
            }
        };

        // Broadcast page events to pop-out (if open)
        if (window.ClaudiaCore) {
            var realOnPageEvent = window.ClaudiaCore.onPageEvent;
            window.ClaudiaCore.onPageEvent = function(eventType, data) {
                // Broadcast to pop-out
                channel.postMessage({ type: 'page_event', event: eventType, data: data });
                // Also handle locally (in case pop-out is not open)
                realOnPageEvent(eventType, data);
            };
            window.cWidget.onPageEvent = window.ClaudiaCore.onPageEvent;
        }
    }

})();
