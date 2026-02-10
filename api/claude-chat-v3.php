<?php
/**
 * TPB Claude Chat API v3
 * Now accepts pre-built context from calling page
 * Reduces friction - page knows user, passes context
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config-claude.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$providedContext = $input['context'] ?? '';  // NEW: Accept context from page
$userId = $input['user_id'] ?? null;

if (empty($userMessage)) {
    echo json_encode(['error' => 'No message provided']);
    exit;
}

// Build system prompt
$systemPrompt = TPB_SYSTEM_PROMPT;

// Add provided context (this is the key change - page passes user's reps, etc.)
if ($providedContext) {
    $systemPrompt .= "\n\n" . $providedContext;
}

// Build messages array
$messages = [];
foreach ($conversationHistory as $msg) {
    $messages[] = [
        'role' => $msg['role'],
        'content' => $msg['content']
    ];
}
$messages[] = [
    'role' => 'user',
    'content' => $userMessage
];

// Call Claude API
$response = callClaudeAPI($systemPrompt, $messages);

if (isset($response['error'])) {
    echo json_encode(['error' => $response['error']]);
    exit;
}

$claudeMessage = $response['content'][0]['text'] ?? '';

echo json_encode([
    'response' => $claudeMessage,
    'usage' => $response['usage'] ?? null
]);

// ============ FUNCTIONS ============

function callClaudeAPI($systemPrompt, $messages) {
    $data = [
        'model' => CLAUDE_MODEL,
        'max_tokens' => 1024,
        'system' => $systemPrompt,
        'messages' => $messages
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

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($response, true);
        return ['error' => $error['error']['message'] ?? 'API call failed'];
    }

    return json_decode($response, true);
}
