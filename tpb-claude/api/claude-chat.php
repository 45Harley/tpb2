<?php
// TPB Claude Chat API
// Receives user messages, calls Anthropic API, returns Claude's response

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config-claude.php';
require_once __DIR__ . '/../config.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$userId = $input['user_id'] ?? null;
$sessionId = $input['session_id'] ?? null;

if (empty($userMessage)) {
    echo json_encode(['error' => 'No message provided']);
    exit;
}

// Build user context if we have a user
$userContext = '';
if ($userId || $sessionId) {
    $userContext = getUserContext($userId, $sessionId);
}

// Build full system prompt with user context
$systemPrompt = TPB_SYSTEM_PROMPT;
if ($userContext) {
    $systemPrompt .= "\n\n## This User\n" . $userContext;
}

// Build messages array for API
$messages = [];

// Add conversation history
foreach ($conversationHistory as $msg) {
    $messages[] = [
        'role' => $msg['role'],
        'content' => $msg['content']
    ];
}

// Add current user message
$messages[] = [
    'role' => 'user',
    'content' => $userMessage
];

// Call Anthropic API
$response = callClaudeAPI($systemPrompt, $messages);

if (isset($response['error'])) {
    echo json_encode(['error' => $response['error']]);
    exit;
}

// Extract Claude's response
$claudeMessage = $response['content'][0]['text'] ?? '';

// Check for action tags and process them
$actions = parseActions($claudeMessage);
$actionResults = [];

foreach ($actions as $action) {
    $result = processAction($action, $userId, $sessionId);
    if ($result) {
        $actionResults[] = $result;
    }
}

// Clean action tags from response (user doesn't need to see them)
$cleanMessage = cleanActionTags($claudeMessage);

echo json_encode([
    'response' => $cleanMessage,
    'actions' => $actionResults,
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

function getUserContext($userId, $sessionId) {
    global $conn;
    
    // Connect to database
    $conn = new mysqli('localhost', 'sandge5_tpb2', '.YeO6kSJAHh5', 'sandge5_tpb2');
    if ($conn->connect_error) {
        return '';
    }

    $context = [];
    
    // Get user info
    $where = $userId ? "user_id = " . intval($userId) : "session_id = '" . $conn->real_escape_string($sessionId) . "'";
    $result = $conn->query("SELECT u.*, t.town_name, s.state_name, s.abbreviation 
                            FROM users u 
                            LEFT JOIN towns t ON u.current_town_id = t.town_id 
                            LEFT JOIN states s ON u.current_state_id = s.state_id 
                            WHERE $where LIMIT 1");
    
    if ($result && $row = $result->fetch_assoc()) {
        $context[] = "- User ID: " . $row['user_id'];
        $context[] = "- Username: " . $row['username'];
        if ($row['first_name']) $context[] = "- Name: " . $row['first_name'];
        if ($row['town_name']) $context[] = "- Town: " . $row['town_name'] . ", " . $row['abbreviation'];
        $context[] = "- Civic Points: " . $row['civic_points'];
        $context[] = "- Identity Level: " . $row['identity_level_id'];
        if ($row['email']) $context[] = "- Email verified: Yes";
    }

    $conn->close();
    
    return implode("\n", $context);
}

function parseActions($message) {
    $actions = [];
    
    // Match [ACTION: TYPE] blocks
    preg_match_all('/\[ACTION:\s*(\w+)\](.*?)(?=\[ACTION:|$)/s', $message, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $action = [
            'type' => $match[1],
            'params' => []
        ];
        
        // Parse key: value pairs
        preg_match_all('/(\w+):\s*(.+?)(?=\n\w+:|$)/s', $match[2], $params, PREG_SET_ORDER);
        foreach ($params as $param) {
            $action['params'][trim($param[1])] = trim($param[2]);
        }
        
        $actions[] = $action;
    }
    
    return $actions;
}

function processAction($action, $userId, $sessionId) {
    $conn = new mysqli('localhost', 'sandge5_tpb2', '.YeO6kSJAHh5', 'sandge5_tpb2');
    if ($conn->connect_error) {
        return ['error' => 'Database connection failed'];
    }

    $result = null;

    switch ($action['type']) {
        case 'ADD_THOUGHT':
            $content = $conn->real_escape_string($action['params']['content'] ?? '');
            $jurisdiction = $conn->real_escape_string($action['params']['jurisdiction'] ?? 'town');
            
            if ($content && $userId) {
                $isLocal = $jurisdiction === 'town' ? 1 : 0;
                $isState = $jurisdiction === 'state' ? 1 : 0;
                $isFederal = $jurisdiction === 'federal' ? 1 : 0;
                
                $sql = "INSERT INTO user_thoughts (user_id, content, jurisdiction_level, is_local, is_state, is_federal, status) 
                        VALUES ($userId, '$content', '$jurisdiction', $isLocal, $isState, $isFederal, 'published')";
                
                if ($conn->query($sql)) {
                    $result = [
                        'action' => 'ADD_THOUGHT',
                        'success' => true,
                        'thought_id' => $conn->insert_id
                    ];
                }
            }
            break;

        case 'SET_TOWN':
            $state = $conn->real_escape_string($action['params']['state'] ?? '');
            $town = $conn->real_escape_string($action['params']['town'] ?? '');
            
            // Look up town
            $townResult = $conn->query("SELECT t.town_id, t.state_id FROM towns t 
                                        JOIN states s ON t.state_id = s.state_id 
                                        WHERE t.town_name = '$town' AND (s.state_name = '$state' OR s.abbreviation = '$state')");
            
            if ($townResult && $townRow = $townResult->fetch_assoc()) {
                $townId = $townRow['town_id'];
                $stateId = $townRow['state_id'];
                
                if ($userId) {
                    $conn->query("UPDATE users SET current_town_id = $townId, current_state_id = $stateId WHERE user_id = $userId");
                    $result = [
                        'action' => 'SET_TOWN',
                        'success' => true,
                        'town_id' => $townId
                    ];
                }
            } else {
                $result = [
                    'action' => 'SET_TOWN',
                    'success' => false,
                    'error' => 'Town not found'
                ];
            }
            break;
    }

    $conn->close();
    return $result;
}

function cleanActionTags($message) {
    // Remove [ACTION: ...] blocks from message
    return preg_replace('/\[ACTION:\s*\w+\].*?(?=\n\n|$)/s', '', $message);
}
