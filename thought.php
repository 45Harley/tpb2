<?php
/**
 * The People's Branch - Quick Thought
 * ====================================
 * Simple. Just speak. Now with AI assistance.
 */

// Database connection
$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}

// Check session via user_devices table
require_once __DIR__ . '/includes/get-user.php';
$dbUser = getUser($pdo);
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$canPost = false;

if (!$dbUser && $sessionId) {
    // Fallback: session-based lookup

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.current_town_id, u.current_state_id,
               COALESCE(uis.email_verified, 0) as email_verified
        FROM user_devices ud
        INNER JOIN users u ON ud.user_id = u.user_id
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE ud.device_session = ? AND ud.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $dbUser = $stmt->fetch();
    $canPost = $dbUser && $dbUser['email_verified'];
}

// Build AI context if user is logged in
require_once __DIR__ . '/includes/ai-context.php';
$aiContext = buildAIContext($pdo, $dbUser);

// Get categories (public only)
$categories = $pdo->query("SELECT * FROM thought_categories WHERE is_active = 1 AND (is_volunteer_only = 0 OR is_volunteer_only IS NULL) ORDER BY display_order")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Speak | The People's Branch</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Georgia', serif;
            background: #0a0a0a;
            color: #e0e0e0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
        }
        
        .container { width: 100%; max-width: 600px; }
        
        h1 { color: #d4af37; font-size: 1.8em; margin-bottom: 10px; text-align: center; }
        
        .tagline { color: #888; text-align: center; margin-bottom: 30px; font-style: italic; }
        
        .form-group { margin-bottom: 20px; }
        
        label { display: block; color: #d4af37; margin-bottom: 8px; font-size: 0.95em; }
        
        select, textarea {
            width: 100%;
            padding: 12px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 1em;
            font-family: inherit;
        }
        
        select:focus, textarea:focus { outline: none; border-color: #d4af37; }
        
        textarea { min-height: 150px; resize: vertical; }
        
        .textarea-wrapper { position: relative; }
        
        .dictate-btn {
            position: absolute;
            right: 10px;
            bottom: 10px;
            background: #2a2a3a;
            border: 1px solid #d4af37;
            color: #d4af37;
            padding: 8px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.2s;
        }
        
        .dictate-btn:hover { background: #d4af37; color: #0a0a0a; }
        .dictate-btn.recording { background: #e74c3c; border-color: #e74c3c; color: white; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .dictate-btn.unsupported { display: none; }
        
        .checkbox-group { margin-bottom: 20px; }
        .checkbox-label { color: #d4af37; margin-bottom: 10px; display: block; font-size: 0.95em; }
        .checkbox-row { display: flex; gap: 20px; flex-wrap: wrap; }
        .checkbox-item { display: flex; align-items: center; gap: 8px; cursor: pointer; color: #e0e0e0; }
        .checkbox-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: #d4af37; cursor: pointer; }
        .checkbox-item:hover { color: #d4af37; }
        
        .char-counter { text-align: right; margin-top: 5px; font-size: 0.9em; color: #d4af37; }
        
        button.submit-btn {
            width: 100%;
            background: #1a3a5c;
            color: #ffffff;
            border: none;
            padding: 15px;
            font-size: 1.1em;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
        }
        button.submit-btn:hover { background: #2a4a6c; }
        button.submit-btn:disabled { background: #333; color: #666; cursor: not-allowed; }
        
        .ai-help-btn {
            width: 100%;
            margin-top: 10px;
            background: #2a2a3a;
            color: #d4af37;
            border: 1px solid #d4af37;
            padding: 12px;
            font-size: 1em;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ai-help-btn:hover { background: #3a3a4a; }
        
        .not-verified {
            text-align: center;
            padding: 40px;
            background: #1a1a1a;
            border-radius: 10px;
            border: 1px solid #333;
        }
        .not-verified p { margin-bottom: 20px; color: #888; }
        .not-verified .cta-btn {
            display: inline-block;
            background: #1a3a5c;
            color: #ffffff;
            text-decoration: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.2s;
        }
        .not-verified .cta-btn:hover { background: #2a4a6c; }
        .not-verified .cta-btn .subtext { display: block; font-size: 0.75em; font-weight: normal; margin-top: 4px; opacity: 0.9; }
        
        .success {
            text-align: center;
            padding: 40px;
            background: #1a2a1a;
            border-radius: 10px;
            border: 1px solid #4caf50;
        }
        .success h2 { color: #4caf50; margin-bottom: 15px; }
        .success a { color: #d4af37; }
        
        .back-link { text-align: center; margin-top: 30px; }
        .back-link a { color: #888; text-decoration: none; }
        .back-link a:hover { color: #d4af37; }
        
        /* AI Helper Modal */
        .ai-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            padding: 20px;
        }
        .ai-modal-content {
            max-width: 600px;
            margin: 30px auto;
            background: #1a1a1a;
            border-radius: 12px;
            border: 1px solid #333;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
        }
        .ai-modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ai-modal-header h3 { margin: 0; color: #d4af37; }
        .ai-close-btn { background: none; border: none; color: #888; font-size: 28px; cursor: pointer; line-height: 1; }
        .ai-close-btn:hover { color: #fff; }
        
        .ai-chat-area {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            min-height: 250px;
        }
        .ai-message {
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .ai-message.assistant { background: #2a2a3a; margin-right: 30px; }
        .ai-message.user { background: #1a3a5c; margin-left: 30px; }
        .ai-message ul { margin: 10px 0 0 20px; }
        .ai-message li { margin-bottom: 5px; }
        
        .ai-input-area {
            padding: 15px;
            border-top: 1px solid #333;
            display: flex;
            gap: 10px;
        }
        .ai-input {
            flex: 1;
            padding: 12px;
            background: #0a0a0a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 1em;
        }
        .ai-input:focus { outline: none; border-color: #d4af37; }
        .ai-send-btn {
            padding: 12px 24px;
            background: #1a3a5c;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        .ai-send-btn:hover { background: #2a4a6c; }
        .ai-send-btn:disabled { background: #333; cursor: not-allowed; }
        
        /* Reps info box */
        .reps-info {
            background: #1a2a1a;
            border: 1px solid #2a4a2a;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }
        .reps-info h4 { color: #4caf50; margin-bottom: 10px; }
        .reps-info ul { margin: 0; padding-left: 20px; }
        .reps-info li { margin-bottom: 5px; color: #aaa; }
        .reps-info .rep-name { color: #e0e0e0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏛️ Speak</h1>
        <p class="tagline">Your civic thought. No noise.</p>
        
        <?php if (!$canPost): ?>
        <div class="not-verified">
            <p>Verify your email to share thoughts.</p>
            <a href="join.php" class="cta-btn">
                Continue with Email
                <span class="subtext">one-time verify per device</span>
            </a>
        </div>
        <?php else: ?>
        
        <?php if ($aiContext['hasReps']): ?>
        <div class="reps-info">
            <h4>📍 Your Representatives</h4>
            <ul>
            <?php foreach (array_slice($aiContext['data']['representatives'] ?? [], 0, 5) as $rep): ?>
                <li><span class="rep-name"><?= htmlspecialchars($rep['full_name']) ?></span> - <?= htmlspecialchars($rep['title']) ?></li>
            <?php endforeach; ?>
            </ul>
            <p style="margin-top:10px; color:#666; font-size:0.85em;">Your thought can reach these officials based on topic.</p>
        </div>
        <?php endif; ?>
        
        <form id="thoughtForm">
            <!-- Category -->
            <div class="form-group">
                <label>Category (optional)</label>
                <select id="category">
                    <option value="">Select...</option>
                    <optgroup label="── Civic Topics ──">
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['icon'] . ' ' . $cat['category_name']) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="── About TPB Platform ──">
                        <option value="16">💡 Idea</option>
                        <option value="17">🐛 Bug Report</option>
                        <option value="18">❓ Question</option>
                        <option value="19">💬 Discussion</option>
                    </optgroup>
                </select>
            </div>
            
            <!-- Jurisdiction -->
            <div class="checkbox-group">
                <label class="checkbox-label">Level of government *</label>
                <div class="checkbox-row">
                    <label class="checkbox-item">
                        <input type="checkbox" id="isLocal" name="jurisdiction">
                        <span>🏠 Local</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="isState" name="jurisdiction">
                        <span>🗺️ State</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="isFederal" name="jurisdiction">
                        <span>🇺🇸 Federal</span>
                    </label>
                </div>
            </div>
            
            <!-- Branch -->
            <div class="checkbox-group">
                <label class="checkbox-label">Branch (optional)</label>
                <div class="checkbox-row">
                    <label class="checkbox-item">
                        <input type="checkbox" id="isLegislative" name="branch">
                        <span>⚖️ Legislative</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="isExecutive" name="branch">
                        <span>🏛️ Executive</span>
                    </label>
                    <label class="checkbox-item">
                        <input type="checkbox" id="isJudicial" name="branch">
                        <span>👨‍⚖️ Judicial</span>
                    </label>
                </div>
            </div>
            
            <!-- Thought -->
            <div class="form-group">
                <label>Your civic thought *</label>
                <div class="textarea-wrapper">
                    <textarea id="content" placeholder="What matters to you?" maxlength="1000"></textarea>
                    <button type="button" id="dictateBtn" class="dictate-btn">🎤 Dictate</button>
                </div>
                <div class="char-counter"><span id="charCount">0</span> / 1000</div>
            </div>
            
            <button type="submit" id="submitBtn" class="submit-btn">Submit</button>
            <button type="button" onclick="openAI()" class="ai-help-btn">🤖 Need help? Ask AI</button>
        </form>
        
        <div id="successMsg" class="success" style="display: none;">
            <h2>✓ Heard</h2>
            <p>Your thought has been recorded.</p>
            <p style="margin-top: 15px;"><a href="thought.php">Share another</a> · <a href="index.php">Back to platform</a></p>
        </div>
        
        <?php endif; ?>
        
        <div class="back-link">
            <a href="index.php">← Back to The People's Branch</a>
        </div>
    </div>
    
    <!-- AI Helper Modal -->
    <div id="aiModal" class="ai-modal">
        <div class="ai-modal-content">
            <div class="ai-modal-header">
                <h3>🤖 AI Assistant</h3>
                <button class="ai-close-btn" onclick="closeAI()">&times;</button>
            </div>
            <div id="aiChat" class="ai-chat-area">
                <div class="ai-message assistant">
                    Hi<?php echo $dbUser ? ', ' . htmlspecialchars($dbUser['first_name'] ?? '') : ''; ?>! I know your representatives and can help you:
                    <ul>
                        <li>Draft a message to <?= htmlspecialchars($aiContext['data']['representatives'][0]['full_name'] ?? 'your rep') ?></li>
                        <li>Understand which level of government handles your issue</li>
                        <li>Make your thought more impactful</li>
                    </ul>
                    What's on your mind?
                </div>
            </div>
            <div class="ai-input-area">
                <input type="text" id="aiInput" class="ai-input" placeholder="Ask anything..." onkeypress="if(event.key==='Enter')sendAI()">
                <button onclick="sendAI()" class="ai-send-btn">Send</button>
            </div>
        </div>
    </div>
    
    <script>
    const API_BASE = 'https://tpb2.sandgems.net/api';
    
    // AI Context (built server-side with user's reps)
    const aiContextText = <?= json_encode($aiContext['text']) ?>;
    const userId = <?= $dbUser ? $dbUser['user_id'] : 'null' ?>;
    let chatHistory = [];
    
    // === AI FUNCTIONS ===
    function openAI() {
        document.getElementById('aiModal').style.display = 'block';
        document.getElementById('aiInput').focus();
    }
    
    function closeAI() {
        document.getElementById('aiModal').style.display = 'none';
    }
    
    function addAIMessage(text, isUser) {
        const chat = document.getElementById('aiChat');
        const div = document.createElement('div');
        div.className = 'ai-message ' + (isUser ? 'user' : 'assistant');
        div.innerHTML = text.replace(/\n/g, '<br>');
        chat.appendChild(div);
        chat.scrollTop = chat.scrollHeight;
    }
    
    async function sendAI() {
        const input = document.getElementById('aiInput');
        const msg = input.value.trim();
        if (!msg) return;
        
        input.value = '';
        addAIMessage(msg, true);
        chatHistory.push({ role: 'user', content: msg });
        
        // Typing indicator
        addAIMessage('<em>Thinking...</em>', false);
        
        try {
            const resp = await fetch(API_BASE + '/claude-chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: msg,
                    history: chatHistory,
                    context: aiContextText,
                    user_id: userId
                })
            });
            const data = await resp.json();
            
            // Remove typing indicator
            const chat = document.getElementById('aiChat');
            chat.removeChild(chat.lastChild);
            
            if (data.error) {
                addAIMessage('Sorry, something went wrong. Try again?', false);
            } else {
                addAIMessage(data.response, false);
                chatHistory.push({ role: 'assistant', content: data.response });
            }
        } catch (e) {
            const chat = document.getElementById('aiChat');
            chat.removeChild(chat.lastChild);
            addAIMessage('Connection error. Please try again.', false);
        }
    }
    
    // Close modal on Escape or background click
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAI(); });
    document.getElementById('aiModal').addEventListener('click', e => { if (e.target.id === 'aiModal') closeAI(); });
    
    // === FORM FUNCTIONS ===
    // Character counter
    document.getElementById('content')?.addEventListener('input', function() {
        document.getElementById('charCount').textContent = this.value.length;
    });
    
    // Submit
    document.getElementById('thoughtForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const content = document.getElementById('content').value.trim();
        const categoryId = document.getElementById('category').value || null;
        const isLocal = document.getElementById('isLocal').checked;
        const isState = document.getElementById('isState').checked;
        const isFederal = document.getElementById('isFederal').checked;
        const isLegislative = document.getElementById('isLegislative').checked;
        const isExecutive = document.getElementById('isExecutive').checked;
        const isJudicial = document.getElementById('isJudicial').checked;
        
        if (!content || content.length < 10) {
            alert('Please enter at least 10 characters.');
            return;
        }
        if (!isLocal && !isState && !isFederal) {
            alert('Please select at least one level of government.');
            return;
        }
        
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Sending...';
        
        try {
            const response = await fetch(`${API_BASE}/submit-thought.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content, category_id: categoryId,
                    is_local: isLocal, is_state: isState, is_federal: isFederal,
                    is_legislative: isLegislative, is_executive: isExecutive, is_judicial: isJudicial
                })
            });
            const data = await response.json();
            
            if (data.status === 'success') {
                document.getElementById('thoughtForm').style.display = 'none';
                document.getElementById('successMsg').style.display = 'block';
            } else {
                alert(data.message || 'Failed to submit.');
                btn.disabled = false;
                btn.textContent = 'Submit';
            }
        } catch (err) {
            alert('Error submitting. Please try again.');
            btn.disabled = false;
            btn.textContent = 'Submit';
        }
    });
    
    // Speech Recognition
    const dictateBtn = document.getElementById('dictateBtn');
    const contentTextarea = document.getElementById('content');
    if (dictateBtn && contentTextarea) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            dictateBtn.classList.add('unsupported');
        } else {
            const recognition = new SpeechRecognition();
            recognition.continuous = true;
            recognition.interimResults = true;
            let isRecording = false;
            let finalTranscript = '';
            
            dictateBtn.addEventListener('click', function() {
                if (isRecording) {
                    recognition.stop();
                } else {
                    finalTranscript = contentTextarea.value;
                    if (finalTranscript && !finalTranscript.endsWith(' ')) finalTranscript += ' ';
                    recognition.start();
                }
            });
            
            recognition.onstart = () => { isRecording = true; dictateBtn.classList.add('recording'); dictateBtn.textContent = '🎤 Stop'; };
            recognition.onend = () => { isRecording = false; dictateBtn.classList.remove('recording'); dictateBtn.textContent = '🎤 Dictate'; document.getElementById('charCount').textContent = contentTextarea.value.length; };
            recognition.onresult = (event) => {
                let interimTranscript = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const transcript = event.results[i][0].transcript;
                    if (event.results[i].isFinal) finalTranscript += transcript;
                    else interimTranscript += transcript;
                }
                contentTextarea.value = finalTranscript + interimTranscript;
                document.getElementById('charCount').textContent = contentTextarea.value.length;
            };
            recognition.onerror = () => { isRecording = false; dictateBtn.classList.remove('recording'); dictateBtn.textContent = '🎤 Dictate'; };
        }
    }
    </script>
</body>
</html>
