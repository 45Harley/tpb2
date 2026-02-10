<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C - Your Civic Guide | TPB</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header h1 {
            color: #4ade80;
            font-size: 2rem;
            margin-bottom: 5px;
        }

        .header p {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .chat-container {
            width: 100%;
            max-width: 600px;
            background: #1e293b;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            height: 70vh;
            max-height: 600px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 12px;
            line-height: 1.5;
        }

        .message.user {
            background: #3b82f6;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .message.assistant {
            background: #334155;
            color: #e2e8f0;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .message.system {
            background: #064e3b;
            color: #6ee7b7;
            align-self: center;
            font-size: 0.85rem;
            text-align: center;
        }

        .typing-indicator {
            display: none;
            align-self: flex-start;
            padding: 12px 16px;
            background: #334155;
            border-radius: 12px;
            color: #94a3b8;
        }

        .typing-indicator.show {
            display: block;
        }

        .typing-indicator span {
            animation: blink 1.4s infinite;
        }

        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes blink {
            0%, 60%, 100% { opacity: 0.3; }
            30% { opacity: 1; }
        }

        .chat-input-container {
            padding: 15px;
            border-top: 1px solid #334155;
            display: flex;
            gap: 10px;
        }

        .chat-input {
            flex: 1;
            padding: 12px 16px;
            border: none;
            border-radius: 25px;
            background: #0f172a;
            color: white;
            font-size: 1rem;
            outline: none;
        }

        .chat-input::placeholder {
            color: #64748b;
        }

        .chat-input:focus {
            box-shadow: 0 0 0 2px #4ade80;
        }

        .send-btn {
            padding: 12px 20px;
            border: none;
            border-radius: 25px;
            background: #4ade80;
            color: #0f172a;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .send-btn:hover {
            background: #22c55e;
        }

        .send-btn:disabled {
            background: #334155;
            color: #64748b;
            cursor: not-allowed;
        }

        .voice-btn {
            padding: 12px;
            border: none;
            border-radius: 50%;
            background: #334155;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
        }

        .voice-btn:hover {
            background: #475569;
            color: white;
        }

        .voice-btn.recording {
            background: #ef4444;
            color: white;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .action-result {
            font-size: 0.8rem;
            margin-top: 8px;
            padding: 8px;
            background: rgba(74, 222, 128, 0.1);
            border-radius: 6px;
            color: #4ade80;
        }

        .cost-display {
            text-align: center;
            color: #64748b;
            font-size: 0.75rem;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>💬 C</h1>
        <p>Your Civic Guide at The People's Branch</p>
    </div>

    <div class="chat-container">
        <div class="chat-messages" id="chatMessages">
            <div class="message assistant">
                Hi! I'm C, your civic guide. 👋<br><br>
                I can help you:<br>
                • Submit thoughts to your local officials<br>
                • Find your representatives<br>
                • Understand how TPB works<br><br>
                What's on your mind about your community?
            </div>
        </div>

        <div class="typing-indicator" id="typingIndicator">
            C is thinking<span>.</span><span>.</span><span>.</span>
        </div>

        <div class="chat-input-container">
            <button class="voice-btn" id="voiceBtn" title="Voice input">
                🎤
            </button>
            <input type="text" class="chat-input" id="chatInput" placeholder="Type your message..." autocomplete="off">
            <button class="send-btn" id="sendBtn">Send</button>
        </div>
    </div>

    <div class="cost-display" id="costDisplay"></div>

    <script>
        const chatMessages = document.getElementById('chatMessages');
        const chatInput = document.getElementById('chatInput');
        const sendBtn = document.getElementById('sendBtn');
        const voiceBtn = document.getElementById('voiceBtn');
        const typingIndicator = document.getElementById('typingIndicator');
        const costDisplay = document.getElementById('costDisplay');

        let conversationHistory = [];
        let totalTokens = { input: 0, output: 0 };
        let isRecording = false;
        let recognition = null;

        // Get user info from session/localStorage if available
        const userId = localStorage.getItem('tpb_user_id') || null;
        const sessionId = localStorage.getItem('tpb_session_id') || 'guest_' + Date.now();

        // Initialize speech recognition if available
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = false;
            recognition.lang = 'en-US';

            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                chatInput.value = transcript;
                voiceBtn.classList.remove('recording');
                isRecording = false;
            };

            recognition.onerror = (event) => {
                console.error('Speech recognition error:', event.error);
                voiceBtn.classList.remove('recording');
                isRecording = false;
            };

            recognition.onend = () => {
                voiceBtn.classList.remove('recording');
                isRecording = false;
            };
        } else {
            voiceBtn.style.display = 'none';
        }

        voiceBtn.addEventListener('click', () => {
            if (!recognition) return;

            if (isRecording) {
                recognition.stop();
                isRecording = false;
                voiceBtn.classList.remove('recording');
            } else {
                recognition.start();
                isRecording = true;
                voiceBtn.classList.add('recording');
            }
        });

        function addMessage(content, role, actions = []) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${role}`;
            messageDiv.innerHTML = content.replace(/\n/g, '<br>');

            // Show action results if any
            if (actions.length > 0) {
                const actionDiv = document.createElement('div');
                actionDiv.className = 'action-result';
                actions.forEach(action => {
                    if (action.success) {
                        if (action.action === 'ADD_THOUGHT') {
                            actionDiv.innerHTML += `✅ Thought #${action.thought_id} submitted!<br>`;
                        } else if (action.action === 'SET_TOWN') {
                            actionDiv.innerHTML += `✅ Town updated!<br>`;
                        }
                    } else if (action.error) {
                        actionDiv.innerHTML += `⚠️ ${action.error}<br>`;
                    }
                });
                messageDiv.appendChild(actionDiv);
            }

            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        async function sendMessage() {
            const message = chatInput.value.trim();
            if (!message) return;

            // Disable input while processing
            chatInput.disabled = true;
            sendBtn.disabled = true;

            // Add user message to chat
            addMessage(message, 'user');
            chatInput.value = '';

            // Show typing indicator
            typingIndicator.classList.add('show');

            // Add to history
            conversationHistory.push({ role: 'user', content: message });

            try {
                const response = await fetch('api/claude-chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: message,
                        history: conversationHistory.slice(-10), // Last 10 messages for context
                        user_id: userId,
                        session_id: sessionId
                    })
                });

                const data = await response.json();

                if (data.error) {
                    addMessage('Sorry, I encountered an error: ' + data.error, 'system');
                } else {
                    addMessage(data.response, 'assistant', data.actions || []);
                    conversationHistory.push({ role: 'assistant', content: data.response });

                    // Track usage
                    if (data.usage) {
                        totalTokens.input += data.usage.input_tokens || 0;
                        totalTokens.output += data.usage.output_tokens || 0;
                        updateCostDisplay();
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                addMessage('Sorry, I couldn\'t connect. Please try again.', 'system');
            }

            // Hide typing indicator and re-enable input
            typingIndicator.classList.remove('show');
            chatInput.disabled = false;
            sendBtn.disabled = false;
            chatInput.focus();
        }

        function updateCostDisplay() {
            // Rough cost estimate (Sonnet pricing)
            const inputCost = (totalTokens.input / 1000000) * 3;
            const outputCost = (totalTokens.output / 1000000) * 15;
            const totalCost = inputCost + outputCost;
            
            costDisplay.textContent = `Session: ${totalTokens.input + totalTokens.output} tokens (~$${totalCost.toFixed(4)})`;
        }

        // Event listeners
        sendBtn.addEventListener('click', sendMessage);
        chatInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });

        // Focus input on load
        chatInput.focus();
    </script>
</body>
</html>
