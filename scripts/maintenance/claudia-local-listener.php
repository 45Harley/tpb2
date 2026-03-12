<?php
/**
 * Claudia Local Listener
 * ======================
 * Simple HTTP endpoint that receives chat requests and pipes them to claude -p.
 * Runs on your local PC, reachable from server via reverse SSH tunnel.
 *
 * Start: php -S localhost:9999 scripts/maintenance/claudia-local-listener.php
 * Tunnel: ssh -R 9999:localhost:9999 sandge5@ecngx308.inmotionhosting.com -p 2222 -N
 */

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$systemPrompt = $input['system_prompt'] ?? '';
$messages = $input['messages'] ?? [];

if (empty($messages)) {
    echo json_encode(['error' => 'No messages provided']);
    exit;
}

// Build the prompt for claude -p
// System prompt first, then conversation history
$actionReminder = <<<'TAG'

CRITICAL ACTION TAG FORMAT RULE:
When you include an action tag, it MUST be on its own block separated by blank lines, with ONLY the parameter key:value pairs on single lines. No other text inside the action block. Example:

[ACTION: SET_TOWN]
state: CT
town: Woodstock

Then continue your response text AFTER the action block. NEVER put markdown, headers, or explanatory text inside an action tag block.
TAG;

$prompt = $systemPrompt . $actionReminder . "\n\n---\n\n";

foreach ($messages as $msg) {
    $role = $msg['role'] === 'user' ? 'User' : 'Assistant';
    $prompt .= "{$role}: {$msg['content']}\n\n";
}

// Write prompt to temp file (avoids shell escaping issues)
$tmpFile = sys_get_temp_dir() . '/claudia-prompt-' . uniqid() . '.txt';
file_put_contents($tmpFile, $prompt);

// Log
$logFile = __DIR__ . '/logs/claudia-local.log';
$ts = date('Y-m-d H:i:s');
file_put_contents($logFile, "[{$ts}] Request received — " . strlen($prompt) . " chars\n", FILE_APPEND);

// Run claude -p using proc_open for reliable stdin piping
$startTime = microtime(true);
$descriptors = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
];
$proc = proc_open('claude -p --allowedTools "WebSearch,WebFetch"', $descriptors, $pipes);
if (!is_resource($proc)) {
    file_put_contents($logFile, "[{$ts}] ERROR: Failed to start claude -p\n", FILE_APPEND);
    @unlink($tmpFile);
    echo json_encode(['error' => 'Failed to start claude -p']);
    exit;
}
fwrite($pipes[0], $prompt);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
$elapsed = round(microtime(true) - $startTime, 1);

// Clean up
@unlink($tmpFile);

if ($output === null) {
    file_put_contents($logFile, "[{$ts}] ERROR: claude -p returned null\n", FILE_APPEND);
    echo json_encode(['error' => 'claude -p failed']);
    exit;
}

file_put_contents($logFile, "[{$ts}] Response: " . strlen($output) . " chars in {$elapsed}s\n", FILE_APPEND);

// Return in a format compatible with what claude-chat.php expects
echo json_encode([
    'content' => [
        ['type' => 'text', 'text' => trim($output)]
    ],
    'usage' => [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'method' => 'local_claude_p',
        'elapsed' => $elapsed
    ]
]);
