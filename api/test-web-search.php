<?php
/**
 * Test Web Search API
 * Simple page to test Claude with web search enabled
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Claude Web Search</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Georgia, serif;
            background: #0a0a0a;
            color: #e0e0e0;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }
        h1 { color: #d4af37; }
        .test-box {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        textarea {
            width: 100%;
            padding: 12px;
            font-size: 1em;
            border: 1px solid #444;
            border-radius: 6px;
            background: #252525;
            color: #e0e0e0;
            resize: vertical;
            min-height: 80px;
        }
        button {
            background: #d4af37;
            color: #000;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 1em;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover { background: #f4cf57; }
        button:disabled { background: #666; cursor: wait; }
        .response {
            background: #1a2a1a;
            border: 1px solid #4a9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            white-space: pre-wrap;
        }
        .error {
            background: #2a1a1a;
            border-color: #a54;
        }
        .info { color: #888; font-size: 0.9em; margin-top: 10px; }
        .examples { margin-top: 15px; }
        .examples button {
            background: #333;
            color: #d4af37;
            margin: 5px 5px 5px 0;
            padding: 8px 16px;
            font-size: 0.9em;
        }
        .examples button:hover { background: #444; }
        .meta { color: #888; font-size: 0.85em; margin-top: 10px; }
    </style>
</head>
<body>
    <h1>🔍 Test Claude Web Search</h1>
    
    <div class="test-box">
        <p>Ask Claude something that requires current information:</p>
        <textarea id="question" placeholder="e.g. What events are happening in Putnam CT this week?"></textarea>
        <br>
        <button onclick="askClaude()" id="askBtn">Ask Claude</button>
        
        <div class="examples">
            <strong>Try these:</strong><br>
            <button onclick="setQuestion('What events are happening in Putnam CT this week?')">Putnam Events</button>
            <button onclick="setQuestion('Who is the current mayor of Putnam CT?')">Putnam Mayor</button>
            <button onclick="setQuestion('What are the hours for Putnam Town Hall?')">Town Hall Hours</button>
            <button onclick="setQuestion('What CT energy rebates are available now?')">CT Rebates</button>
            <button onclick="setQuestion('What is the weather in Putnam CT today?')">Weather</button>
        </div>
    </div>
    
    <div id="responseBox" style="display:none;">
        <h3>Response:</h3>
        <div id="response" class="response"></div>
        <div id="meta" class="meta"></div>
    </div>

    <script>
        function setQuestion(q) {
            document.getElementById('question').value = q;
        }
        
        async function askClaude() {
            const question = document.getElementById('question').value.trim();
            if (!question) {
                alert('Please enter a question');
                return;
            }
            
            const btn = document.getElementById('askBtn');
            const responseBox = document.getElementById('responseBox');
            const responseDiv = document.getElementById('response');
            const metaDiv = document.getElementById('meta');
            
            btn.disabled = true;
            btn.textContent = 'Searching...';
            responseBox.style.display = 'block';
            responseDiv.textContent = 'Claude is searching the web...';
            responseDiv.className = 'response';
            metaDiv.textContent = '';
            
            try {
                const response = await fetch('claude-chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        message: question,
                        clerk: 'guide'
                    })
                });
                
                const data = await response.json();
                
                if (data.error) {
                    responseDiv.textContent = 'Error: ' + data.error;
                    responseDiv.className = 'response error';
                } else {
                    responseDiv.textContent = data.response || data.message || JSON.stringify(data, null, 2);
                    
                    // Show usage info if available
                    if (data.usage) {
                        let meta = `Tokens: ${data.usage.input_tokens || 0} in / ${data.usage.output_tokens || 0} out`;
                        if (data.usage.server_tool_use?.web_search_requests) {
                            meta += ` | Web searches: ${data.usage.server_tool_use.web_search_requests}`;
                        }
                        metaDiv.textContent = meta;
                    }
                }
            } catch (err) {
                responseDiv.textContent = 'Error: ' + err.message;
                responseDiv.className = 'response error';
            }
            
            btn.disabled = false;
            btn.textContent = 'Ask Claude';
        }
    </script>
</body>
</html>
