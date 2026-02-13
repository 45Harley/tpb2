# /talk Phase 2: AI Brainstorm Clerk — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an AI brainstorm clerk that converses with users and auto-captures ideas during conversation.

**Architecture:** New `?action=brainstorm` handler in `talk/api.php` calls into existing clerk infrastructure (`config-claude.php` functions). New `talk/brainstorm.php` provides chat UI. Brainstorm clerk registered in `ai_clerks` table with Haiku model, no web search.

**Tech Stack:** PHP 8.4, MySQL, vanilla JS, Anthropic Claude API (Haiku), SpeechRecognition API

---

## Task 1: Register Brainstorm Clerk in Database

**Files:**
- Create: `scripts/db/talk-phase2-brainstorm-clerk.sql` (gitignored, run manually)

**Step 1: Write the SQL migration script**

Create `scripts/db/talk-phase2-brainstorm-clerk.sql`:

```sql
-- /talk Phase 2: Register brainstorm clerk and documentation
-- Run against: sandge5_tpb2
-- Date: 2026-02-12

-- 1. Register the brainstorm clerk
INSERT INTO ai_clerks (clerk_key, clerk_name, description, model, capabilities, restrictions, enabled)
VALUES (
  'brainstorm',
  'Brainstorm Clerk',
  'AI brainstorming partner for /talk. Captures ideas during conversation, reads back session history, and tags ideas.',
  'claude-haiku-4-5-20251001',
  'save_idea,read_back,tag_idea',
  'No web search. Never promote or link ideas. Never fabricate user data.',
  1
);

-- 2. Register clerk-specific documentation (loaded into prompt via getClerkDocs)
INSERT INTO system_documentation (doc_key, doc_title, content, tags, roles)
VALUES (
  'clerk-brainstorm-rules',
  'Brainstorm Clerk Rules',
  '## Brainstorm Clerk — Identity & Rules\n\nYou are the Brainstorm Clerk for The People''s Branch (TPB). You help citizens think through ideas by brainstorming with them in natural conversation.\n\n### Your Role\n- You are a thinking partner, not an authority\n- Build on the user''s ideas with \"yes, and...\" energy\n- Ask probing questions to sharpen fuzzy ideas\n- Suggest connections between ideas when you see them\n- Celebrate good thinking — civic engagement is hard work\n\n### Capturing Ideas\nWhen the user expresses a concrete idea, decision, question, or action item, capture it using SAVE_IDEA. Use your judgment:\n- SAVE when: a specific proposal, decision, question, or todo emerges\n- DON''T SAVE: small talk, clarifications, thinking-out-loud fragments\n- When in doubt, save it — raw ideas can be pruned later\n- Pick the best category: idea, decision, todo, note, question\n- Never save the same idea twice in one session (check the session ideas list)\n\n### Ethics (Golden Rule)\n- Ideas that affect real people (Maria, Tom, Jamal) deserve careful thought\n- If an idea could harm citizens, gently flag it: \"Let''s think about who this affects...\"\n- Non-partisan: help refine ideas regardless of political leaning\n- Accuracy matters: don''t invent facts. If you don''t know, say so.\n\n### Tone\n- Warm, encouraging, conversational\n- Short responses (2-4 sentences typical, longer only when synthesizing)\n- Use the user''s first name if known\n- No corporate jargon, no bullet-point lectures',
  'brainstorm,talk,clerk:brainstorm',
  'clerk:brainstorm'
);
```

**Step 2: Run migration on local XAMPP**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root sandge5_tpb2 < scripts/db/talk-phase2-brainstorm-clerk.sql
```

Expected: Query OK for both INSERTs.

**Step 3: Verify locally**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root sandge5_tpb2 -e "SELECT clerk_id, clerk_key, clerk_name, model, capabilities FROM ai_clerks WHERE clerk_key = 'brainstorm'"
```

Expected: One row with `brainstorm` clerk, Haiku model, `save_idea,read_back,tag_idea` capabilities.

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root sandge5_tpb2 -e "SELECT doc_key, doc_title, tags, roles FROM system_documentation WHERE doc_key = 'clerk-brainstorm-rules'"
```

Expected: One row with `clerk-brainstorm-rules`, tags include `clerk:brainstorm`.

**Step 4: Run migration on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);

// Insert clerk
\$p->exec(\"INSERT INTO ai_clerks (clerk_key, clerk_name, description, model, capabilities, restrictions, enabled) VALUES ('brainstorm', 'Brainstorm Clerk', 'AI brainstorming partner for /talk. Captures ideas during conversation, reads back session history, and tags ideas.', 'claude-haiku-4-5-20251001', 'save_idea,read_back,tag_idea', 'No web search. Never promote or link ideas. Never fabricate user data.', 1)\");
echo 'Clerk inserted: ' . \$p->lastInsertId() . PHP_EOL;

// Insert documentation
\$content = '## Brainstorm Clerk — Identity & Rules

You are the Brainstorm Clerk for The People\\'s Branch (TPB). You help citizens think through ideas by brainstorming with them in natural conversation.

### Your Role
- You are a thinking partner, not an authority
- Build on the user\\'s ideas with \"yes, and...\" energy
- Ask probing questions to sharpen fuzzy ideas
- Suggest connections between ideas when you see them
- Celebrate good thinking — civic engagement is hard work

### Capturing Ideas
When the user expresses a concrete idea, decision, question, or action item, capture it using SAVE_IDEA. Use your judgment:
- SAVE when: a specific proposal, decision, question, or todo emerges
- DON\\'T SAVE: small talk, clarifications, thinking-out-loud fragments
- When in doubt, save it — raw ideas can be pruned later
- Pick the best category: idea, decision, todo, note, question
- Never save the same idea twice in one session (check the session ideas list)

### Ethics (Golden Rule)
- Ideas that affect real people (Maria, Tom, Jamal) deserve careful thought
- If an idea could harm citizens, gently flag it: \"Let\\'s think about who this affects...\"
- Non-partisan: help refine ideas regardless of political leaning
- Accuracy matters: don\\'t invent facts. If you don\\'t know, say so.

### Tone
- Warm, encouraging, conversational
- Short responses (2-4 sentences typical, longer only when synthesizing)
- Use the user\\'s first name if known
- No corporate jargon, no bullet-point lectures';

\$stmt = \$p->prepare('INSERT INTO system_documentation (doc_key, doc_title, content, tags, roles) VALUES (?, ?, ?, ?, ?)');
\$stmt->execute(['clerk-brainstorm-rules', 'Brainstorm Clerk Rules', \$content, 'brainstorm,talk,clerk:brainstorm', 'clerk:brainstorm']);
echo 'Doc inserted' . PHP_EOL;

// Verify
\$r = \$p->query(\"SELECT clerk_id, clerk_key, model FROM ai_clerks WHERE clerk_key = 'brainstorm'\");
echo 'Verify: ' . implode(' | ', \$r->fetch(PDO::FETCH_ASSOC)) . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

Expected: Clerk inserted with ID, doc inserted, verify shows brainstorm row.

**Note:** The SQL file in `scripts/db/` is gitignored. That's fine — migration is run manually on both environments.

---

## Task 2: Add Brainstorm Handler to `talk/api.php`

**Files:**
- Modify: `talk/api.php:62-83` (add case to switch)
- Modify: `talk/api.php` (append 3 new functions at end)

**Step 1: Add `brainstorm` case to switch**

In `talk/api.php`, add the new case in the switch block (after `case 'link':`, before `default:`):

```php
        case 'brainstorm':
            echo json_encode(handleBrainstorm($pdo, $input, $userId));
            break;
```

Also update the docblock at top to list the new action:

```
 *   brainstorm   — POST: AI brainstorm conversation turn
```

**Step 2: Add `handleBrainstorm()` function**

Append to end of `talk/api.php`:

```php
// ── Brainstorm ────────────────────────────────────────────────────

function handleBrainstorm($pdo, $input, $userId) {
    $message   = trim($input['message'] ?? '');
    $history   = $input['history'] ?? [];
    $sessionId = $input['session_id'] ?? null;

    if ($message === '') {
        return ['success' => false, 'error' => 'Message is required'];
    }

    // Load clerk infrastructure (only on brainstorm calls)
    require_once __DIR__ . '/../config-claude.php';
    require_once __DIR__ . '/../includes/ai-context.php';

    $clerk = getClerk($pdo, 'brainstorm');
    if (!$clerk) {
        return ['success' => false, 'error' => 'Brainstorm clerk not available'];
    }

    // Build prompt: base clerk prompt + docs tagged brainstorm/talk
    $systemPrompt = buildClerkPrompt($pdo, $clerk, ['brainstorm', 'talk']);

    // Inject recent session ideas so clerk knows what's been captured
    if ($sessionId) {
        $stmt = $pdo->prepare("
            SELECT id, content, category, tags
            FROM idea_log
            WHERE session_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$sessionId]);
        $recentIdeas = $stmt->fetchAll();
        if ($recentIdeas) {
            $systemPrompt .= "\n\n## Ideas captured this session\n";
            foreach ($recentIdeas as $idea) {
                $systemPrompt .= "- #{$idea['id']} [{$idea['category']}] {$idea['content']}";
                if ($idea['tags']) $systemPrompt .= " (tags: {$idea['tags']})";
                $systemPrompt .= "\n";
            }
        }
    }

    // Append action instructions
    $systemPrompt .= "\n\n" . getBrainstormActionInstructions();

    // Build messages array
    $messages = [];
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Call API — Haiku, NO web search
    $response = callClaudeAPI($systemPrompt, $messages, $clerk['model'], false);

    if (isset($response['error'])) {
        return ['success' => false, 'error' => $response['error']];
    }

    // Extract response text
    $claudeMessage = '';
    foreach (($response['content'] ?? []) as $block) {
        if ($block['type'] === 'text') $claudeMessage .= $block['text'];
    }
    if (empty($claudeMessage)) {
        $claudeMessage = $response['content'][0]['text'] ?? 'No response';
    }

    // Parse and process actions
    $actions = parseActions($claudeMessage);
    $actionResults = [];
    foreach ($actions as $action) {
        if (clerkCan($clerk, strtolower($action['type']))) {
            $result = processBrainstormAction($pdo, $action, $userId, $sessionId);
            if ($result) $actionResults[] = $result;
        }
    }

    $cleanMessage = cleanActionTags($claudeMessage);
    logClerkInteraction($pdo, $clerk['clerk_id']);

    return [
        'success'  => true,
        'response' => $cleanMessage,
        'actions'  => $actionResults,
        'clerk'    => $clerk['clerk_name'],
        'usage'    => $response['usage'] ?? null
    ];
}


// ── Brainstorm Action Instructions ────────────────────────────────

function getBrainstormActionInstructions() {
    return <<<'INSTRUCTIONS'
## Action Tags
When you want to capture an idea or retrieve session history, include action tags.

To save an idea from the conversation:
[ACTION: SAVE_IDEA]
content: {the idea text, concise}
category: {idea|decision|todo|note|question}

To read back ideas captured this session:
[ACTION: READ_BACK]

To add tags to an existing idea:
[ACTION: TAG_IDEA]
idea_id: {the idea number}
tags: {comma-separated tags}
INSTRUCTIONS;
}


// ── Brainstorm Action Processor ───────────────────────────────────

function processBrainstormAction($pdo, $action, $userId, $sessionId) {
    switch ($action['type']) {
        case 'SAVE_IDEA':
            $content  = trim($action['params']['content'] ?? '');
            $category = trim($action['params']['category'] ?? 'idea');
            if (!$content) {
                return ['action' => 'SAVE_IDEA', 'success' => false, 'error' => 'No content'];
            }

            $validCats = ['idea', 'decision', 'todo', 'note', 'question'];
            if (!in_array($category, $validCats)) $category = 'idea';

            $stmt = $pdo->prepare("
                INSERT INTO idea_log (user_id, session_id, content, category, status, source)
                VALUES (?, ?, ?, ?, 'raw', 'api')
            ");
            $stmt->execute([$userId, $sessionId, $content, $category]);
            $id = (int)$pdo->lastInsertId();

            return [
                'action'  => 'SAVE_IDEA',
                'success' => true,
                'idea_id' => $id,
                'message' => ucfirst($category) . " #{$id} captured"
            ];

        case 'READ_BACK':
            if (!$sessionId) {
                return ['action' => 'READ_BACK', 'success' => false, 'error' => 'No session'];
            }
            $stmt = $pdo->prepare("
                SELECT id, content, category, tags
                FROM idea_log
                WHERE session_id = ?
                ORDER BY created_at ASC
            ");
            $stmt->execute([$sessionId]);
            $ideas = $stmt->fetchAll();

            return [
                'action'  => 'READ_BACK',
                'success' => true,
                'ideas'   => $ideas,
                'count'   => count($ideas)
            ];

        case 'TAG_IDEA':
            $ideaId = (int)($action['params']['idea_id'] ?? 0);
            $tags   = trim($action['params']['tags'] ?? '');
            if (!$ideaId || !$tags) {
                return ['action' => 'TAG_IDEA', 'success' => false, 'error' => 'Missing params'];
            }

            // Append to existing tags, deduplicate
            $stmt = $pdo->prepare("SELECT tags FROM idea_log WHERE id = ?");
            $stmt->execute([$ideaId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['action' => 'TAG_IDEA', 'success' => false, 'error' => 'Idea not found'];
            }

            $existing = $row['tags'] ? array_map('trim', explode(',', $row['tags'])) : [];
            $new = array_map('trim', explode(',', $tags));
            $merged = implode(',', array_unique(array_merge($existing, $new)));

            $stmt = $pdo->prepare("UPDATE idea_log SET tags = ? WHERE id = ?");
            $stmt->execute([$merged, $ideaId]);

            return ['action' => 'TAG_IDEA', 'success' => true, 'idea_id' => $ideaId, 'tags' => $merged];

        default:
            return ['action' => $action['type'], 'success' => false, 'error' => 'Unknown brainstorm action'];
    }
}
```

**Step 3: Test the brainstorm endpoint locally**

Test that the endpoint loads the clerk and returns a response (this makes a real API call to Anthropic):

```bash
curl -s -X POST "http://localhost/tpb2/talk/api.php?action=brainstorm" -H "Content-Type: application/json" -d "{\"message\":\"What if we added a childcare finder to the site?\",\"history\":[],\"session_id\":\"test-phase2-001\"}" | python -m json.tool
```

Expected: `"success": true`, `"response"` contains conversational text, `"clerk": "Brainstorm Clerk"`, possibly `"actions"` with a SAVE_IDEA result.

**Step 4: Test error case — empty message**

```bash
curl -s -X POST "http://localhost/tpb2/talk/api.php?action=brainstorm" -H "Content-Type: application/json" -d "{\"message\":\"\",\"history\":[],\"session_id\":\"test\"}" | python -m json.tool
```

Expected: `"success": false`, `"error": "Message is required"`.

**Step 5: Commit**

```bash
git add talk/api.php
git commit -m "Add brainstorm action handler to talk/api.php

Routes ?action=brainstorm through existing clerk infrastructure.
Loads brainstorm clerk (Haiku, no web search), injects session
context, processes SAVE_IDEA/READ_BACK/TAG_IDEA action tags."
```

---

## Task 3: Create `talk/brainstorm.php` Chat UI

**Files:**
- Create: `talk/brainstorm.php`

**Step 1: Create the brainstorm chat page**

Create `talk/brainstorm.php`:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1a1a2e">
    <title>Brainstorm - Talk</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

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

        /* Header */
        .chat-header {
            padding: 12px 16px;
            background: rgba(0,0,0,0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }

        .chat-header h1 {
            font-size: 1.1rem;
            color: #4fc3f7;
        }

        .header-links {
            display: flex;
            gap: 12px;
            font-size: 0.8rem;
        }

        .header-links a {
            color: #4fc3f7;
            text-decoration: none;
        }

        /* Chat area */
        .chat-area {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .message {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 0.95rem;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .message.user {
            align-self: flex-end;
            background: #0288d1;
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message.clerk {
            align-self: flex-start;
            background: rgba(255,255,255,0.1);
            color: #eee;
            border-bottom-left-radius: 4px;
        }

        .message.system {
            align-self: center;
            background: rgba(76, 175, 80, 0.15);
            color: #81c784;
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: 12px;
        }

        .message.thinking {
            align-self: flex-start;
            background: rgba(255,255,255,0.05);
            color: #888;
            font-style: italic;
        }

        /* Input area */
        .input-area {
            padding: 12px 16px;
            background: rgba(0,0,0,0.3);
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .mic-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.1);
            color: #4fc3f7;
            font-size: 1.2rem;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .mic-btn:hover {
            background: rgba(79, 195, 247, 0.2);
        }

        .mic-btn.listening {
            background: rgba(244, 67, 54, 0.3);
            color: #f44336;
            animation: pulse-mic 1.5s infinite;
        }

        @keyframes pulse-mic {
            0%, 100% { box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(244, 67, 54, 0); }
        }

        .chat-input {
            flex: 1;
            padding: 10px 14px;
            border: 2px solid #333;
            border-radius: 22px;
            background: rgba(255,255,255,0.05);
            color: #eee;
            font-size: 0.95rem;
            resize: none;
            max-height: 100px;
            overflow-y: auto;
            transition: border-color 0.3s;
        }

        .chat-input:focus {
            outline: none;
            border-color: #4fc3f7;
        }

        .chat-input::placeholder { color: #666; }

        .send-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(145deg, #4fc3f7, #0288d1);
            color: white;
            font-size: 1.2rem;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.3s;
        }

        .send-btn:hover {
            transform: scale(1.05);
        }

        .send-btn:disabled {
            background: #333;
            cursor: not-allowed;
            transform: none;
        }

        /* Welcome message */
        .welcome {
            text-align: center;
            color: #888;
            padding: 2rem 1rem;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .welcome h2 {
            color: #4fc3f7;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="chat-header">
        <h1>🧠 Brainstorm</h1>
        <div class="header-links">
            <a href="index.php">Quick Capture</a>
            <a href="history.php">History</a>
        </div>
    </div>

    <div class="chat-area" id="chatArea">
        <div class="welcome">
            <h2>Let's think together</h2>
            <p>Share an idea, question, or problem.<br>I'll brainstorm with you and capture the good stuff.</p>
        </div>
    </div>

    <div class="input-area">
        <button class="mic-btn" id="micBtn" title="Tap to speak">🎤</button>
        <textarea class="chat-input" id="chatInput" rows="1" placeholder="What's on your mind?"></textarea>
        <button class="send-btn" id="sendBtn" title="Send">➤</button>
    </div>

    <script>
        const chatArea = document.getElementById('chatArea');
        const chatInput = document.getElementById('chatInput');
        const sendBtn = document.getElementById('sendBtn');
        const micBtn = document.getElementById('micBtn');

        let conversationHistory = [];
        let isWaiting = false;
        let recognition = null;

        // Session ID — shared with index.php via sessionStorage
        let sessionId = sessionStorage.getItem('tpb_session');
        if (!sessionId) {
            sessionId = crypto.randomUUID();
            sessionStorage.setItem('tpb_session', sessionId);
        }

        // Speech recognition
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
                chatInput.value = transcript;
                autoResize();
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
        }

        // Auto-resize textarea
        function autoResize() {
            chatInput.style.height = 'auto';
            chatInput.style.height = Math.min(chatInput.scrollHeight, 100) + 'px';
        }
        chatInput.addEventListener('input', autoResize);

        // Send on Enter (not Shift+Enter)
        chatInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        sendBtn.addEventListener('click', sendMessage);

        async function sendMessage() {
            const message = chatInput.value.trim();
            if (!message || isWaiting) return;

            // Clear welcome on first message
            const welcome = chatArea.querySelector('.welcome');
            if (welcome) welcome.remove();

            // Show user message
            addMessage(message, 'user');
            chatInput.value = '';
            chatInput.style.height = 'auto';

            // Show thinking indicator
            const thinkingEl = addMessage('Thinking...', 'thinking');
            isWaiting = true;
            sendBtn.disabled = true;

            try {
                const response = await fetch('api.php?action=brainstorm', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: message,
                        history: conversationHistory,
                        session_id: sessionId
                    })
                });

                const data = await response.json();
                thinkingEl.remove();

                if (data.success) {
                    // Show clerk response
                    addMessage(data.response, 'clerk');

                    // Show action results as system messages
                    if (data.actions && data.actions.length > 0) {
                        for (const action of data.actions) {
                            if (action.success && action.action === 'SAVE_IDEA') {
                                addMessage('💡 ' + action.message, 'system');
                            } else if (action.success && action.action === 'TAG_IDEA') {
                                addMessage('🏷️ Tagged #' + action.idea_id + ': ' + action.tags, 'system');
                            } else if (action.success && action.action === 'READ_BACK') {
                                addMessage('📋 ' + action.count + ' idea' + (action.count !== 1 ? 's' : '') + ' in session', 'system');
                            }
                        }
                    }

                    // Update conversation history
                    conversationHistory.push(
                        { role: 'user', content: message },
                        { role: 'assistant', content: data.response }
                    );
                } else {
                    addMessage('Error: ' + (data.error || 'Something went wrong'), 'system');
                }
            } catch (err) {
                thinkingEl.remove();
                addMessage('Network error — check your connection', 'system');
            }

            isWaiting = false;
            sendBtn.disabled = false;
            chatInput.focus();
        }

        function addMessage(text, type) {
            const div = document.createElement('div');
            div.className = 'message ' + type;
            div.textContent = text;
            chatArea.appendChild(div);
            chatArea.scrollTop = chatArea.scrollHeight;
            return div;
        }
    </script>
</body>
</html>
```

**Step 2: Test the page loads**

Open `http://localhost/tpb2/talk/brainstorm.php` in a browser.

Expected: Dark-themed chat page with header (Brainstorm, Quick Capture link, History link), welcome message, input area with mic + text + send.

**Step 3: Test a brainstorm conversation**

In the browser, type "What if we added a childcare resource finder?" and press Enter.

Expected: User message appears right-aligned, "Thinking..." appears, then clerk response appears left-aligned. If clerk saves an idea, a green system message shows "Idea #N captured".

**Step 4: Commit**

```bash
git add talk/brainstorm.php
git commit -m "Add talk/brainstorm.php chat UI

Full-screen chat page for AI brainstorming. Dark theme matching
index.php. Mic support via SpeechRecognition. Shared session ID
with quick capture. Shows inline capture notifications."
```

---

## Task 4: Add Navigation Cross-Links

**Files:**
- Modify: `talk/index.php:232-234` (add brainstorm link)
- Modify: `talk/history.php:185` (add brainstorm link)

**Step 1: Add "Brainstorm with AI" link to index.php**

In `talk/index.php`, find the `history-link` paragraph (line ~232) and add a second link:

Replace:
```html
        <p class="history-link">
            <a href="history.php">View recent thoughts →</a>
        </p>
```

With:
```html
        <p class="history-link">
            <a href="brainstorm.php">🧠 Brainstorm with AI</a>
            &nbsp;·&nbsp;
            <a href="history.php">View recent thoughts →</a>
        </p>
```

**Step 2: Add "Brainstorm" link to history.php header**

In `talk/history.php`, find the header-links div (line ~179) and add the brainstorm link. The links section currently has "Show all"/"My ideas" and "← New thought".

Replace:
```html
                <a href="index.php">← New thought</a>
```

With:
```html
                <a href="brainstorm.php">🧠 Brainstorm</a>
                <a href="index.php">← New thought</a>
```

**Step 3: Test navigation**

Open each page in browser and verify links work:
- `index.php` → "Brainstorm with AI" goes to `brainstorm.php`
- `index.php` → "View recent thoughts" goes to `history.php`
- `brainstorm.php` → "Quick Capture" goes to `index.php`
- `brainstorm.php` → "History" goes to `history.php`
- `history.php` → "Brainstorm" goes to `brainstorm.php`
- `history.php` → "New thought" goes to `index.php`

**Step 4: Commit**

```bash
git add talk/index.php talk/history.php
git commit -m "Add brainstorm cross-links to index.php and history.php"
```

---

## Task 5: End-to-End Verification

**Step 1: Test full brainstorm flow locally**

1. Open `http://localhost/tpb2/talk/brainstorm.php`
2. Send: "What if TPB had a tool to help people find local childcare programs?"
3. Verify: Clerk responds conversationally, possibly captures an idea
4. Send: "Yeah, and it could pull from the CT 211 database"
5. Verify: Clerk builds on it, possibly captures another idea
6. Send: "Read back what we've got so far"
7. Verify: Clerk summarizes captured ideas, READ_BACK system message appears

**Step 2: Verify ideas appear in history**

Open `http://localhost/tpb2/talk/history.php`

Expected: Ideas captured by the brainstorm clerk appear with `source = api`. They share the same session_id as any quick-capture ideas from the same tab.

**Step 3: Test brainstorm API error handling**

```bash
curl -s -X POST "http://localhost/tpb2/talk/api.php?action=brainstorm" -H "Content-Type: application/json" -d "{}" | python -m json.tool
```

Expected: `"success": false, "error": "Message is required"`.

**Step 4: Clean up test data**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root sandge5_tpb2 -e "DELETE FROM idea_log WHERE session_id LIKE 'test-phase2%'"
```

---

## Task 6: Push to Staging

**Step 1: Push commits to origin**

```bash
git push origin master
```

**Step 2: Pull on staging server**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cd /home/sandge5/tpb2.sandgems.net && git pull"
```

**Step 3: Verify staging**

Test the brainstorm page loads:

```bash
curl -s -o /dev/null -w "%{http_code}" "https://tpb2.sandgems.net/talk/brainstorm.php"
```

Expected: `200`.

Test brainstorm API (browser test — ModSecurity blocks some curl JSON POSTs on InMotion):

Open `https://tpb2.sandgems.net/talk/brainstorm.php` in browser and send a test message.

Expected: Clerk responds, ideas captured if applicable.

**Step 4: Clean up staging test data**

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$p->exec(\"DELETE FROM idea_log WHERE session_id LIKE 'test-phase2%'\");
echo 'Cleaned up test data' . PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```
