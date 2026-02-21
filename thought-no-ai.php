<?php
/**
 * The People's Branch - Quick Thought
 * ====================================
 * Simple. Just speak.
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
        SELECT u.user_id, u.first_name, u.current_town_id, u.current_state_id,
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .container {
            width: 100%;
            max-width: 600px;
        }
        
        h1 {
            color: #d4af37;
            font-size: 1.8em;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .tagline {
            color: #888;
            text-align: center;
            margin-bottom: 30px;
            font-style: italic;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            color: #d4af37;
            margin-bottom: 8px;
            font-size: 0.95em;
        }
        
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
        
        select:focus, textarea:focus {
            outline: none;
            border-color: #d4af37;
        }
        
        textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .textarea-wrapper {
            position: relative;
        }
        
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
        
        .dictate-btn:hover {
            background: #d4af37;
            color: #0a0a0a;
        }
        
        .dictate-btn.recording {
            background: #e74c3c;
            border-color: #e74c3c;
            color: white;
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .dictate-btn.unsupported {
            display: none;
        }
        
        .checkbox-group {
            margin-bottom: 20px;
        }
        
        .checkbox-label {
            color: #d4af37;
            margin-bottom: 10px;
            display: block;
            font-size: 0.95em;
        }
        
        .checkbox-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #e0e0e0;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #d4af37;
            cursor: pointer;
        }
        
        .checkbox-item:hover {
            color: #d4af37;
        }
        
        .other-topic {
            display: none;
            margin-top: 10px;
        }
        
        .other-topic.show {
            display: block;
        }
        
        .other-topic input {
            width: 100%;
            padding: 12px;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            font-size: 1em;
        }
        
        .char-counter {
            text-align: right;
            margin-top: 5px;
            font-size: 0.9em;
            color: #d4af37;
        }
        
        /* Dark blue button - readable */
        button {
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
        
        button:hover {
            background: #2a4a6c;
        }
        
        button:disabled {
            background: #333;
            color: #666;
            cursor: not-allowed;
        }
        
        /* Not verified box with CTA */
        .not-verified {
            text-align: center;
            padding: 40px;
            background: #1a1a1a;
            border-radius: 10px;
            border: 1px solid #333;
        }
        
        .not-verified p {
            margin-bottom: 20px;
            color: #888;
        }
        
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
        
        .not-verified .cta-btn:hover {
            background: #2a4a6c;
        }
        
        .not-verified .cta-btn .subtext {
            display: block;
            font-size: 0.75em;
            font-weight: normal;
            margin-top: 4px;
            opacity: 0.9;
        }
        
        .success {
            text-align: center;
            padding: 40px;
            background: #1a2a1a;
            border-radius: 10px;
            border: 1px solid #4caf50;
        }
        
        .success h2 {
            color: #4caf50;
            margin-bottom: 15px;
        }
        
        .success a {
            color: #d4af37;
        }
        
        .back-link {
            text-align: center;
            margin-top: 30px;
        }
        
        .back-link a {
            color: #888;
            text-decoration: none;
        }
        
        .back-link a:hover {
            color: #d4af37;
        }
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
            
            <!-- TPB Profile Notice (shows when TPB category selected) -->
            <div id="tpbProfileNotice" style="display: none; background: #2a2a4a; border: 1px solid #d4af37; border-radius: 8px; padding: 12px; margin-bottom: 15px;">
                <p style="color: #d4af37; margin: 0 0 8px; font-size: 0.95em;">⚠️ TPB feedback requires a complete profile</p>
                <p style="color: #aaa; margin: 0; font-size: 0.85em;">We need your name and phone to follow up on your <span id="tpbCategoryName">idea</span>.</p>
                <div id="tpbMissingFields" style="color: #ff6b6b; margin-top: 8px; font-size: 0.85em;"></div>
            </div>
            
            <!-- Other Topic (shows when Other selected) -->
            <div class="other-topic" id="otherTopicGroup">
                <input type="text" id="otherTopic" placeholder="What topic?" maxlength="100">
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
                <p style="color: #d4af37; font-size: 0.85em; margin-bottom: 10px;">📝 Content must be appropriate for all ages, 5 to 125.</p>
                <div class="textarea-wrapper">
                    <textarea id="content" placeholder="What matters to you?" maxlength="1000"></textarea>
                    <button type="button" id="dictateBtn" class="dictate-btn">🎤 Dictate</button>
                </div>
                <div class="char-counter"><span id="charCount">0</span> / 1000</div>
            </div>
            
            <button type="submit" id="submitBtn">Submit</button>
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
    
    <script>
    const API_BASE = 'https://tpb2.sandgems.net/api';
    
    // TPB categories that require full profile
    const tpbCategories = [16, 17, 18, 19];
    const tpbCategoryNames = {16: 'idea', 17: 'bug report', 18: 'question', 19: 'discussion'};
    let userProfile = null;
    
    // Get session ID
    const sessionId = document.cookie.split('; ').find(row => row.startsWith('tpb_civic_session='))?.split('=')[1];
    
    // Fetch user profile on load
    async function fetchUserProfile() {
        if (!sessionId) return;
        try {
            const response = await fetch(API_BASE + '/check-session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: sessionId })
            });
            const data = await response.json();
            if (data.status === 'success') {
                userProfile = data;
                checkParentConsent();
            }
        } catch (err) {
            console.error('Could not fetch profile');
        }
    }
    fetchUserProfile();
    
    // Check if minor needs parent consent and disable form if so
    function checkParentConsent() {
        if (!userProfile) return;
        if (userProfile.needs_parent_consent) {
            // Show notice and disable form
            const form = document.getElementById('thoughtForm');
            const submitBtn = document.getElementById('submitBtn');
            const textarea = document.getElementById('content');
            
            if (form) {
                const notice = document.createElement('div');
                notice.id = 'parentConsentNotice';
                notice.style.cssText = 'background: #2a2a4a; border: 1px solid #7ab8e0; border-radius: 8px; padding: 15px; margin-bottom: 15px; text-align: center;';
                notice.innerHTML = '<p style="color: #7ab8e0; margin: 0 0 8px; font-size: 1.1em;">⏳ Waiting for Parent/Guardian Approval</p><p style="color: #aaa; margin: 0; font-size: 0.9em;">We sent an email to your parent/guardian. Ask them to check and approve!</p>';
                form.insertBefore(notice, form.firstChild);
            }
            if (submitBtn) submitBtn.disabled = true;
            if (textarea) textarea.disabled = true;
        }
    }
    
    // Check if TPB profile is complete
    function checkTpbProfile() {
        if (!userProfile) return { complete: false, missing: ['profile not loaded'] };
        const missing = [];
        if (!userProfile.first_name) missing.push('first name');
        if (!userProfile.last_name) missing.push('last name');
        if (!userProfile.phone) missing.push('phone');
        return { complete: missing.length === 0, missing: missing };
    }
    
    // Category change - show Other topic field and TPB notice
    document.getElementById('category')?.addEventListener('change', function() {
        const otherGroup = document.getElementById('otherTopicGroup');
        const tpbNotice = document.getElementById('tpbProfileNotice');
        const categoryId = parseInt(this.value);
        
        // Other field
        if (this.value == 11) {
            otherGroup.classList.add('show');
        } else {
            otherGroup.classList.remove('show');
            document.getElementById('otherTopic').value = '';
        }
        
        // TPB profile notice
        if (tpbCategories.includes(categoryId)) {
            const profile = checkTpbProfile();
            document.getElementById('tpbCategoryName').textContent = tpbCategoryNames[categoryId] || 'feedback';
            if (!profile.complete) {
                document.getElementById('tpbMissingFields').textContent = 'Missing: ' + profile.missing.join(', ');
                tpbNotice.style.display = 'block';
            } else {
                tpbNotice.style.display = 'none';
            }
        } else {
            tpbNotice.style.display = 'none';
        }
    });
    
    // Character counter
    document.getElementById('content')?.addEventListener('input', function() {
        const count = this.value.length;
        const counter = document.getElementById('charCount');
        counter.textContent = count;
        if (count > 950) {
            counter.style.color = '#e74c3c';
        } else if (count > 800) {
            counter.style.color = '#f39c12';
        } else {
            counter.style.color = '#d4af37';
        }
    });
    
    // Submit
    document.getElementById('thoughtForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const content = document.getElementById('content').value.trim();
        const categoryId = document.getElementById('category').value || null;
        const otherTopic = document.getElementById('otherTopic').value.trim();
        
        const isLocal = document.getElementById('isLocal').checked;
        const isState = document.getElementById('isState').checked;
        const isFederal = document.getElementById('isFederal').checked;
        
        const isLegislative = document.getElementById('isLegislative').checked;
        const isExecutive = document.getElementById('isExecutive').checked;
        const isJudicial = document.getElementById('isJudicial').checked;
        
        // Validate
        if (!content) {
            alert('Please enter your thought.');
            return;
        }
        
        if (content.length < 10) {
            alert('Please write at least 10 characters.');
            return;
        }
        
        if (!isLocal && !isState && !isFederal) {
            alert('Please select at least one level of government.');
            return;
        }
        
        if (categoryId == 11 && !otherTopic) {
            alert('Please specify the topic.');
            document.getElementById('otherTopic').focus();
            return;
        }
        
        // TPB profile check
        if (tpbCategories.includes(parseInt(categoryId))) {
            const profile = checkTpbProfile();
            if (!profile.complete) {
                alert('TPB feedback requires a complete profile. Please add: ' + profile.missing.join(', '));
                return;
            }
        }
        
        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Sending...';
        
        try {
            const response = await fetch(`${API_BASE}/submit-thought.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content: content,
                    category_id: categoryId,
                    other_topic: otherTopic,
                    is_local: isLocal,
                    is_state: isState,
                    is_federal: isFederal,
                    is_legislative: isLegislative,
                    is_executive: isExecutive,
                    is_judicial: isJudicial
                })
            });
            
            const data = await response.json();
            
            if (data.status === 'success') {
                if (data.total_points && window.tpbUpdateNavPoints) window.tpbUpdateNavPoints(data.total_points);
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
    
    // Speech Recognition (Dictate)
    const dictateBtn = document.getElementById('dictateBtn');
    const contentTextarea = document.getElementById('content');
    
    if (dictateBtn && contentTextarea) {
        // Check if speech recognition is supported
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        
        if (!SpeechRecognition) {
            dictateBtn.classList.add('unsupported');
        } else {
            const recognition = new SpeechRecognition();
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.lang = 'en-US';
            
            let isRecording = false;
            let finalTranscript = '';
            
            dictateBtn.addEventListener('click', function() {
                if (isRecording) {
                    recognition.stop();
                } else {
                    // Store existing text
                    finalTranscript = contentTextarea.value;
                    if (finalTranscript && !finalTranscript.endsWith(' ')) {
                        finalTranscript += ' ';
                    }
                    recognition.start();
                }
            });
            
            recognition.onstart = function() {
                isRecording = true;
                dictateBtn.classList.add('recording');
                dictateBtn.textContent = '🎤 Stop';
            };
            
            recognition.onend = function() {
                isRecording = false;
                dictateBtn.classList.remove('recording');
                dictateBtn.textContent = '🎤 Dictate';
                // Update char counter
                const count = contentTextarea.value.length;
                document.getElementById('charCount').textContent = count;
            };
            
            recognition.onresult = function(event) {
                let interimTranscript = '';
                
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const transcript = event.results[i][0].transcript;
                    if (event.results[i].isFinal) {
                        finalTranscript += transcript;
                    } else {
                        interimTranscript += transcript;
                    }
                }
                
                contentTextarea.value = finalTranscript + interimTranscript;
                
                // Update char counter
                const count = contentTextarea.value.length;
                document.getElementById('charCount').textContent = count;
            };
            
            recognition.onerror = function(event) {
                isRecording = false;
                dictateBtn.classList.remove('recording');
                dictateBtn.textContent = '🎤 Dictate';
                
                if (event.error === 'not-allowed') {
                    alert('Microphone access denied. Please allow microphone access to use dictation.');
                } else if (event.error !== 'aborted') {
                    console.error('Speech recognition error:', event.error);
                }
            };
        }
    }
    </script>
</body>
</html>
