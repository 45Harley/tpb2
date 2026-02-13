<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1a1a2e">
    <title>QT - Quick Thought</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💡</text></svg>">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #eee;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        
        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #4fc3f7;
        }
        
        .subtitle {
            font-size: 0.9rem;
            color: #888;
            margin-bottom: 2rem;
        }
        
        .mic-button {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(145deg, #4fc3f7, #0288d1);
            color: white;
            font-size: 3rem;
            cursor: pointer;
            margin: 1rem auto;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(79, 195, 247, 0.3);
        }
        
        .mic-button:hover {
            transform: scale(1.05);
            box-shadow: 0 12px 35px rgba(79, 195, 247, 0.4);
        }
        
        .mic-button:active {
            transform: scale(0.95);
        }
        
        .mic-button.listening {
            animation: pulse 1.5s infinite;
            background: linear-gradient(145deg, #f44336, #d32f2f);
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.7); }
            50% { box-shadow: 0 0 0 20px rgba(244, 67, 54, 0); }
        }
        
        .text-input {
            width: 100%;
            min-height: 100px;
            padding: 15px;
            border: 2px solid #333;
            border-radius: 12px;
            background: rgba(255,255,255,0.05);
            color: #eee;
            font-size: 1rem;
            resize: vertical;
            margin: 1rem 0;
            transition: border-color 0.3s;
        }
        
        .text-input:focus {
            outline: none;
            border-color: #4fc3f7;
        }
        
        .text-input::placeholder {
            color: #666;
        }
        
        .category-row {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 1rem 0;
            flex-wrap: wrap;
        }
        
        .category-btn {
            padding: 8px 16px;
            border: 2px solid #333;
            border-radius: 20px;
            background: transparent;
            color: #888;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .category-btn:hover {
            border-color: #4fc3f7;
            color: #4fc3f7;
        }
        
        .category-btn.active {
            background: #4fc3f7;
            border-color: #4fc3f7;
            color: #1a1a2e;
        }

        .category-btn.disabled {
            opacity: 0.3;
            pointer-events: none;
        }
        
        .submit-btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(145deg, #4caf50, #388e3c);
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.3);
        }
        
        .submit-btn:disabled {
            background: #333;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .status {
            margin-top: 1rem;
            padding: 10px;
            border-radius: 8px;
            font-size: 0.9rem;
            display: none;
        }
        
        .status.success {
            display: block;
            background: rgba(76, 175, 80, 0.2);
            color: #81c784;
        }
        
        .status.error {
            display: block;
            background: rgba(244, 67, 54, 0.2);
            color: #e57373;
        }
        
        .no-speech {
            font-size: 0.85rem;
            color: #666;
            margin-top: 0.5rem;
        }
        
        .history-link {
            margin-top: 2rem;
            font-size: 0.85rem;
        }
        
        .history-link a {
            color: #4fc3f7;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>💡 Quick Thought</h1>
        <p class="subtitle">Tap mic to speak, or type below</p>
        
        <button class="mic-button" id="micBtn" title="Tap to speak">🎤</button>
        <p class="no-speech" id="noSpeech" style="display:none;">Voice not supported - type instead</p>
        
        <textarea 
            class="text-input" 
            id="textInput" 
            placeholder="What's on your mind?"
        ></textarea>
        
        <div class="category-row">
            <button class="category-btn active" data-category="idea">💡 Idea</button>
            <button class="category-btn" data-category="decision">✅ Decision</button>
            <button class="category-btn" data-category="todo">📋 Todo</button>
            <button class="category-btn" data-category="note">📝 Note</button>
            <button class="category-btn" data-category="question">❓ Question</button>
            <button class="category-btn disabled" data-category="reaction" id="reactionBtn" title="Available when reacting to an idea">↩️ Reaction</button>
        </div>
        
        <button class="submit-btn" id="submitBtn">Save Thought</button>
        
        <div class="status" id="status"></div>
        
        <p class="history-link">
            <a href="brainstorm.php">🧠 Brainstorm with AI</a>
            &nbsp;·&nbsp;
            <a href="history.php">View recent thoughts →</a>
        </p>
    </div>

    <script>
        const micBtn = document.getElementById('micBtn');
        const textInput = document.getElementById('textInput');
        const submitBtn = document.getElementById('submitBtn');
        const status = document.getElementById('status');
        const noSpeech = document.getElementById('noSpeech');
        const categoryBtns = document.querySelectorAll('.category-btn');
        
        let selectedCategory = 'idea';
        let recognition = null;
        let lastInputSource = 'web';

        // Session ID — one per tab, persists across saves in same tab
        let sessionId = sessionStorage.getItem('tpb_session');
        if (!sessionId) {
            sessionId = crypto.randomUUID();
            sessionStorage.setItem('tpb_session', sessionId);
        }
        
        // Check for speech recognition support
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        
        if (SpeechRecognition) {
            recognition = new SpeechRecognition();
            recognition.continuous = false;
            recognition.interimResults = true;
            recognition.lang = 'en-US';
            
            recognition.onstart = () => {
                micBtn.classList.add('listening');
                micBtn.textContent = '⏺';
            };
            
            recognition.onend = () => {
                micBtn.classList.remove('listening');
                micBtn.textContent = '🎤';
            };
            
            recognition.onresult = (event) => {
                let transcript = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                textInput.value = transcript;
                lastInputSource = 'voice';
            };
            
            recognition.onerror = (event) => {
                console.error('Speech error:', event.error);
                micBtn.classList.remove('listening');
                micBtn.textContent = '🎤';
            };
            
            micBtn.addEventListener('click', () => {
                if (micBtn.classList.contains('listening')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });
        } else {
            micBtn.style.display = 'none';
            noSpeech.style.display = 'block';
        }
        
        // Category selection (skip disabled buttons)
        categoryBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.classList.contains('disabled')) return;
                categoryBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                selectedCategory = btn.dataset.category;
            });
        });

        // Reset source when typing
        textInput.addEventListener('input', () => {
            lastInputSource = 'web';
        });

        // Submit
        submitBtn.addEventListener('click', async () => {
            const content = textInput.value.trim();

            if (!content) {
                showStatus('Please enter a thought first', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        content: content,
                        category: selectedCategory,
                        source: lastInputSource,
                        session_id: sessionId,
                        parent_id: null,
                        tags: null
                    })
                });
                const data = await response.json();

                if (data.success) {
                    showStatus('✓ ' + data.message, 'success');
                    textInput.value = '';
                    lastInputSource = 'web';
                } else {
                    showStatus('Error: ' + data.error, 'error');
                }
            } catch (err) {
                showStatus('Network error - try again', 'error');
            }

            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Thought';
        });
        
        function showStatus(message, type) {
            status.textContent = message;
            status.className = 'status ' + type;
            
            if (type === 'success') {
                setTimeout(() => {
                    status.className = 'status';
                }, 3000);
            }
        }
        
        // Allow Ctrl+Enter to submit
        textInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                submitBtn.click();
            }
        });
    </script>
</body>
</html>
