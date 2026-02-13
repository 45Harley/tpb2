<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1a1a2e">
    <title>Brainstorm - Talk</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#x1f9e0;</text></svg>">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #eee;
            display: flex;
            flex-direction: column;
        }

        .header {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
            z-index: 10;
        }

        .header h1 { font-size: 1.1rem; font-weight: 600; color: #eee; }

        .header-links { display: flex; gap: 16px; }

        .header-links a {
            color: #4fc3f7;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .header-links a:hover { text-decoration: underline; }

        .chat-area {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            -webkit-overflow-scrolling: touch;
        }

        .welcome {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            opacity: 0.7;
        }

        .welcome h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #4fc3f7;
            margin-bottom: 12px;
        }

        .welcome p { font-size: 0.95rem; color: #aaa; line-height: 1.6; }

        .message {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 0.95rem;
            line-height: 1.5;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .message.user {
            align-self: flex-end;
            background: #0288d1;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .message.clerk {
            align-self: flex-start;
            background: rgba(255, 255, 255, 0.1);
            color: #eee;
            border-bottom-left-radius: 4px;
        }

        .message.system {
            align-self: center;
            background: rgba(76, 175, 80, 0.15);
            color: #81c784;
            font-size: 0.8rem;
            padding: 6px 14px;
            border-radius: 12px;
        }

        .message.thinking {
            align-self: flex-start;
            background: rgba(255, 255, 255, 0.05);
            color: #888;
            font-style: italic;
            border-bottom-left-radius: 4px;
        }

        .message.error {
            align-self: center;
            background: rgba(244, 67, 54, 0.15);
            color: #e57373;
            font-size: 0.8rem;
            padding: 6px 14px;
            border-radius: 12px;
        }

        .input-area {
            flex-shrink: 0;
            display: flex;
            align-items: flex-end;
            gap: 8px;
            padding: 10px 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.25);
            z-index: 10;
        }

        .mic-btn, .send-btn {
            flex-shrink: 0;
            width: 42px;
            height: 42px;
            border: none;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s, transform 0.1s;
        }

        .mic-btn { background: rgba(255, 255, 255, 0.1); color: #eee; }
        .mic-btn:hover { background: rgba(255, 255, 255, 0.18); }
        .mic-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }

        .mic-btn.listening {
            background: #d32f2f;
            color: #fff;
            animation: micPulse 1.5s infinite;
        }

        @keyframes micPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(211, 47, 47, 0.6); }
            50%      { box-shadow: 0 0 0 10px rgba(211, 47, 47, 0); }
        }

        .send-btn { background: #0288d1; color: #fff; }
        .send-btn:hover { background: #039be5; }
        .send-btn:active { transform: scale(0.93); }
        .send-btn:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }
        .send-btn:disabled { background: #333; color: #666; cursor: not-allowed; }

        .chat-input {
            flex: 1;
            min-height: 42px;
            max-height: 100px;
            padding: 10px 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 21px;
            background: rgba(255, 255, 255, 0.06);
            color: #eee;
            font-family: inherit;
            font-size: 0.95rem;
            line-height: 1.4;
            resize: none;
            overflow-y: auto;
        }

        .chat-input:focus { outline: none; border-color: #4fc3f7; }
        .chat-input:focus-visible { outline: 2px solid #4fc3f7; outline-offset: 2px; }
        .chat-input::placeholder { color: #666; }

        .chat-area::-webkit-scrollbar { width: 8px; }
        .chat-area::-webkit-scrollbar-track { background: transparent; }
        .chat-area::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.2); border-radius: 4px; }

        @supports (padding-top: env(safe-area-inset-top)) {
            .header { padding-top: calc(12px + env(safe-area-inset-top)); }
            .input-area { padding-bottom: calc(10px + env(safe-area-inset-bottom)); }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>&#x1f9e0; Brainstorm</h1>
        <div class="header-links">
            <a href="index.php">Quick Capture</a>
            <a href="history.php">History</a>
        </div>
    </div>

    <div class="chat-area" id="chatArea">
        <div class="welcome" id="welcome">
            <h2>Let's think together</h2>
            <p>Share an idea, question, or problem.<br>I'll brainstorm with you and capture the good stuff.</p>
        </div>
    </div>

    <div class="input-area">
        <button class="mic-btn" id="micBtn" title="Tap to speak">&#x1f3a4;</button>
        <textarea class="chat-input" id="chatInput" rows="1" placeholder="What's on your mind?"></textarea>
        <button class="send-btn" id="sendBtn" title="Send">&#x27a4;</button>
    </div>

    <script>
        var chatArea  = document.getElementById('chatArea');
        var welcome   = document.getElementById('welcome');
        var chatInput = document.getElementById('chatInput');
        var sendBtn   = document.getElementById('sendBtn');
        var micBtn    = document.getElementById('micBtn');

        var conversationHistory = [];
        var isWaiting = false;

        // Session ID (shared with index.php)
        var sessionId = sessionStorage.getItem('tpb_session');
        if (!sessionId) {
            sessionId = crypto.randomUUID();
            sessionStorage.setItem('tpb_session', sessionId);
        }

        // Speech Recognition
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        var recognition = null;

        if (SpeechRecognition) {
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.lang = 'en-US';

            recognition.onstart = function() {
                micBtn.classList.add('listening');
                micBtn.textContent = '⏺';
            };

            recognition.onend = function() {
                micBtn.classList.remove('listening');
                micBtn.textContent = '🎤';
            };

            recognition.onresult = function(event) {
                var transcript = '';
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                chatInput.value = transcript;
                autoResize();
            };

            recognition.onerror = function(event) {
                console.error('Speech error:', event.error);
                micBtn.classList.remove('listening');
                micBtn.textContent = '🎤';
            };

            micBtn.addEventListener('click', function() {
                if (micBtn.classList.contains('listening')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });
        } else {
            micBtn.style.display = 'none';
        }

        // Auto-resize textarea
        function autoResize() {
            chatInput.style.height = 'auto';
            chatInput.style.height = Math.min(chatInput.scrollHeight, 100) + 'px';
        }

        chatInput.addEventListener('input', autoResize);

        // Scroll to bottom
        function scrollToBottom() {
            chatArea.scrollTop = chatArea.scrollHeight;
        }

        // Add message bubble
        function addMessage(text, type) {
            var div = document.createElement('div');
            div.className = 'message ' + type;
            div.textContent = text;
            chatArea.appendChild(div);
            scrollToBottom();
            return div;
        }

        // Remove welcome
        function removeWelcome() {
            if (welcome && welcome.parentNode) {
                welcome.remove();
            }
        }

        // Send message
        async function sendMessage() {
            var text = chatInput.value.trim();
            if (!text || isWaiting) return;

            isWaiting = true;
            sendBtn.disabled = true;

            removeWelcome();

            addMessage(text, 'user');

            chatInput.value = '';
            chatInput.style.height = 'auto';

            var thinkingEl = addMessage('Thinking...', 'thinking');

            conversationHistory.push({ role: 'user', content: text });

            try {
                var response = await fetch('api.php?action=brainstorm', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: text,
                        history: conversationHistory,
                        session_id: sessionId
                    })
                });

                var data = await response.json();

                thinkingEl.remove();

                if (data.success) {
                    addMessage(data.response, 'clerk');

                    conversationHistory.push({ role: 'assistant', content: data.response });

                    if (data.actions && data.actions.length > 0) {
                        for (var i = 0; i < data.actions.length; i++) {
                            var action = data.actions[i];
                            if (!action.success) continue;

                            switch (action.action) {
                                case 'SAVE_IDEA':
                                    addMessage('💡 Idea #' + action.id + ' captured', 'system');
                                    break;
                                case 'TAG_IDEA':
                                    addMessage('🏷️ Tagged #' + action.idea_id + ': ' + action.tags, 'system');
                                    break;
                                case 'READ_BACK':
                                    addMessage('📋 ' + action.count + ' ideas in session', 'system');
                                    break;
                            }
                        }
                    }
                } else {
                    addMessage(data.error || 'Something went wrong', 'error');
                }
            } catch (err) {
                thinkingEl.remove();
                addMessage('Network error — check connection and try again', 'error');
                console.error('Brainstorm error:', err);
            }

            isWaiting = false;
            sendBtn.disabled = false;
            chatInput.focus();
        }

        // Event listeners
        sendBtn.addEventListener('click', sendMessage);

        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    </script>
</body>
</html>