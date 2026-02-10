<?php
/**
 * TPB Claude API Test
 * Test the API with custom prompt and context
 */

require_once __DIR__ . '/config-claude.php';

$response = null;
$error = null;
$usage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prompt = $_POST['prompt'] ?? '';
    $context = $_POST['context'] ?? '';
    
    if ($prompt) {
        $systemPrompt = TPB_SYSTEM_PROMPT;
        if ($context) {
            $systemPrompt .= "\n\n## Additional Context\n" . $context;
        }
        
        $data = [
            'model' => CLAUDE_MODEL,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];
        
        $ch = curl_init(ANTHROPIC_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01'
            ]
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($result, true);
        
        if ($httpCode === 200) {
            $response = $decoded['content'][0]['text'] ?? 'No response';
            $usage = $decoded['usage'] ?? null;
        } else {
            $error = $decoded['error']['message'] ?? "HTTP $httpCode - API call failed";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claude API Test | TPB</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0f;
            color: #e0e0e0;
            min-height: 100vh;
            padding: 2rem;
        }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #d4af37; margin-bottom: 0.5rem; }
        .subtitle { color: #888; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; color: #d4af37; margin-bottom: 0.5rem; font-weight: 500; }
        textarea {
            width: 100%;
            padding: 1rem;
            font-size: 1rem;
            font-family: monospace;
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 8px;
            color: #e0e0e0;
            resize: vertical;
        }
        textarea:focus { outline: none; border-color: #d4af37; }
        .prompt-box { min-height: 100px; }
        .context-box { min-height: 150px; }
        .btn {
            padding: 1rem 2rem;
            font-size: 1rem;
            background: #d4af37;
            color: #000;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn:hover { background: #e4bf47; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .result {
            margin-top: 2rem;
            padding: 1.5rem;
            background: #1a1a2e;
            border-radius: 8px;
            border: 1px solid #333;
        }
        .result h2 { color: #d4af37; margin-bottom: 1rem; font-size: 1.1rem; }
        .result pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.6;
        }
        .error {
            background: #3a1a1a;
            border-color: #e63946;
            color: #e63946;
        }
        .usage {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #333;
            color: #888;
            font-size: 0.9rem;
        }
        .model-info {
            background: #1a2a1a;
            border: 1px solid #2a4a2a;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .model-info code { color: #4caf50; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🤖 Claude API Test</h1>
        <p class="subtitle">Test your API connection with custom prompt and context</p>
        
        <div class="model-info">
            <strong>Model:</strong> <code><?= CLAUDE_MODEL ?></code><br>
            <strong>API URL:</strong> <code><?= ANTHROPIC_API_URL ?></code><br>
            <strong>API Key:</strong> <code><?= substr(ANTHROPIC_API_KEY, 0, 20) ?>...<?= substr(ANTHROPIC_API_KEY, -4) ?></code>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Context (optional - added to system prompt)</label>
                <textarea name="context" class="context-box" placeholder="## User Context
- Name: Harley
- Location: Putnam, CT

## Representatives
- Mae Flexer (State Senator)
- Joe Courtney (US Rep)"><?= htmlspecialchars($_POST['context'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Prompt *</label>
                <textarea name="prompt" class="prompt-box" placeholder="Ask something..." required><?= htmlspecialchars($_POST['prompt'] ?? '') ?></textarea>
            </div>
            
            <button type="submit" class="btn">Send to Claude</button>
        </form>
        
        <?php if ($error): ?>
        <div class="result error">
            <h2>❌ Error</h2>
            <pre><?= htmlspecialchars($error) ?></pre>
        </div>
        <?php endif; ?>
        
        <?php if ($response): ?>
        <div class="result">
            <h2>✓ Response</h2>
            <pre><?= htmlspecialchars($response) ?></pre>
            <?php if ($usage): ?>
            <div class="usage">
                <strong>Usage:</strong> 
                Input: <?= number_format($usage['input_tokens']) ?> tokens | 
                Output: <?= number_format($usage['output_tokens']) ?> tokens |
                Cost: ~$<?= number_format(($usage['input_tokens'] * 3 / 1000000) + ($usage['output_tokens'] * 15 / 1000000), 4) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
