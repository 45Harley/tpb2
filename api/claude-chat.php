<?php
/**
 * TPB Claude Chat API v4
 * ======================
 * Now uses ai_clerks table and system_documentation concordance
 * 
 * tpb-tags: api, chat, ai-clerks, core
 * tpb-roles: developer
 * tpb-toc: [
 *   {"id": "setup", "title": "Setup & Headers"},
 *   {"id": "get-input", "title": "Get Input"},
 *   {"id": "get-user", "title": "Get User"},
 *   {"id": "get-clerk", "title": "Get AI Clerk"},
 *   {"id": "build-prompt", "title": "Build Prompt"},
 *   {"id": "call-api", "title": "Call Claude API"},
 *   {"id": "process-actions", "title": "Process Actions"},
 *   {"id": "functions", "title": "Helper Functions"}
 * ]
 */

// === SETUP & HEADERS ===
// #setup
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config-claude.php';
require_once __DIR__ . '/../includes/ai-context.php';

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
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// === GET INPUT ===
// #get-input
$input = json_decode(file_get_contents('php://input'), true);
$userMessage = $input['message'] ?? '';
$conversationHistory = $input['history'] ?? [];
$userId = $input['user_id'] ?? null;
$sessionId = $input['session_id'] ?? null;
$userEmail = $input['email'] ?? null;
$clerkKey = $input['clerk'] ?? 'guide';  // NEW: which clerk to use

if (empty($userMessage)) {
    echo json_encode(['error' => 'No message provided']);
    exit;
}

// Extract email from message if mentioned
if (!$userEmail && preg_match('/[\w\.\-]+@[\w\.\-]+\.\w+/', $userMessage, $emailMatch)) {
    $userEmail = $emailMatch[0];
}

// === GET USER ===
// #get-user
$dbUser = null;
if ($userId) {
    $stmt = $pdo->prepare("
        SELECT u.*, COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$userId]);
    $dbUser = $stmt->fetch();
} elseif ($userEmail) {
    $stmt = $pdo->prepare("
        SELECT u.*, COALESCE(uis.email_verified, 0) as email_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.email = ?
    ");
    $stmt->execute([$userEmail]);
    $dbUser = $stmt->fetch();
}

// === GET AI CLERK ===
// #get-clerk
$clerk = getClerk($pdo, $clerkKey);
if (!$clerk) {
    // Fallback to guide if requested clerk not found
    $clerk = getClerk($pdo, 'guide');
}
if (!$clerk) {
    echo json_encode(['error' => 'No AI clerk available']);
    exit;
}

// === BUILD PROMPT ===
// #build-prompt

// 1. Start with clerk's base prompt (includes their knowledge from system_documentation)
$systemPrompt = buildClerkPrompt($pdo, $clerk);

// 2. Add dynamic user context (name, town, representatives)
$aiContext = buildAIContext($pdo, $dbUser);
if ($aiContext['text']) {
    $systemPrompt .= "\n\n" . $aiContext['text'];
}

// 3. Add message-specific context (town lookups, email lookups, etc.)
$dbContext = gatherRelevantContext($pdo, $userMessage);
if ($dbContext) {
    $systemPrompt .= "\n\n## Additional Context\n" . $dbContext;
}

// 4. Add action tag instructions if clerk has capabilities
if ($clerk['capabilities']) {
    $systemPrompt .= "\n\n" . getActionInstructions($clerk);
}

// Build messages array for API
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

// === CALL CLAUDE API ===
// #call-api
$model = $clerk['model'] ?? CLAUDE_MODEL;
$response = callClaudeAPI($systemPrompt, $messages, $model);

if (isset($response['error'])) {
    echo json_encode(['error' => $response['error']]);
    exit;
}

// Extract Claude's response (web search returns multiple content blocks)
$claudeMessage = '';
if (isset($response['content']) && is_array($response['content'])) {
    foreach ($response['content'] as $block) {
        if ($block['type'] === 'text') {
            $claudeMessage .= $block['text'];
        }
    }
}
if (empty($claudeMessage)) {
    $claudeMessage = $response['content'][0]['text'] ?? 'No response';
}

// === PROCESS ACTIONS ===
// #process-actions
$actions = parseActions($claudeMessage);
$actionResults = [];

foreach ($actions as $action) {
    // Check if clerk has this capability
    if (clerkCan($clerk, $action['type']) || clerkCan($clerk, strtolower($action['type']))) {
        $result = processAction($pdo, $action, $userId, $sessionId, $userEmail);
        if ($result) {
            $actionResults[] = $result;
        }
    } else {
        $actionResults[] = [
            'action' => $action['type'],
            'success' => false,
            'error' => "Clerk '{$clerk['clerk_name']}' doesn't have capability for {$action['type']}"
        ];
    }
}

// Clean action tags from response
$cleanMessage = cleanActionTags($claudeMessage);

// Log the interaction
logClerkInteraction($pdo, $clerk['clerk_id']);

echo json_encode([
    'response' => $cleanMessage,
    'clerk' => $clerk['clerk_name'],
    'actions' => $actionResults,
    'usage' => $response['usage'] ?? null
]);

// === HELPER FUNCTIONS ===
// #functions

/**
 * Call Anthropic Claude API with optional web search
 */
function callClaudeAPI($systemPrompt, $messages, $model = CLAUDE_MODEL, $enableWebSearch = true, $userLocation = null) {
    $data = [
        'model' => $model,
        'max_tokens' => 1024,
        'system' => $systemPrompt,
        'messages' => $messages
    ];
    
    // Add web search tool if enabled
    if ($enableWebSearch) {
        $webSearchTool = [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => 5
        ];
        
        // Add location for localized results
        if ($userLocation) {
            $webSearchTool['user_location'] = $userLocation;
        } else {
            // Default to Putnam, CT for now
            $webSearchTool['user_location'] = [
                'type' => 'approximate',
                'city' => 'Putnam',
                'region' => 'Connecticut',
                'country' => 'US',
                'timezone' => 'America/New_York'
            ];
        }
        
        // Web fetch tool for direct URL retrieval (JSON, pages, etc.)
        $webFetchTool = [
            'type' => 'web_fetch_20250910',
            'name' => 'web_fetch',
            'max_uses' => 5
        ];
        
        $data['tools'] = [$webSearchTool, $webFetchTool];
    }

    // Build headers - include web-fetch beta header
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: web-fetch-2025-09-10'
    ];

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers
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

/**
 * Get action instructions based on clerk capabilities
 */
function getActionInstructions($clerk) {
    $instructions = "## Action Tags\nWhen the user wants to DO something (not just ask), include an action tag:\n\n";
    
    $capabilities = array_map('trim', explode(',', $clerk['capabilities']));
    
    if (in_array('set_town', $capabilities)) {
        $instructions .= "To set user's town:\n[ACTION: SET_TOWN]\nstate: {state abbreviation or name}\ntown: {town name}\n\n";
    }
    if (in_array('lookup_user', $capabilities)) {
        $instructions .= "To look up a user:\n[ACTION: LOOKUP_USER]\nemail: {email address}\n\n";
    }
    if (in_array('lookup_town', $capabilities)) {
        $instructions .= "To verify a town exists:\n[ACTION: LOOKUP_TOWN]\ntown: {town name}\nstate: {state}\n\n";
    }
    if (in_array('add_thought', $capabilities)) {
        $instructions .= "To submit a thought:\n[ACTION: ADD_THOUGHT]\ncontent: {the thought text}\njurisdiction: {town|state|federal}\n\n";
    }
    if (in_array('log_threat', $capabilities)) {
        $instructions .= "To log a threat:\n[ACTION: LOG_THREAT]\ndescription: {threat description}\nseverity: {low|medium|high|critical}\n\n";
    }
    
    return $instructions;
}

/**
 * Gather relevant context based on message content
 */
function gatherRelevantContext($pdo, $message) {
    $context = [];
    $messageLower = strtolower($message);
    
    // Check for town mentions
    if (preg_match('/\b(\w+)[,\s]+(ct|conn|connecticut|ri|rhode island)\b/i', $message, $match)) {
        $townName = ucwords(strtolower($match[1]));
        $stateInput = strtoupper($match[2]);
        if (strlen($stateInput) > 2) {
            $stateMap = ['CONNECTICUT' => 'CT', 'RHODE ISLAND' => 'RI', 'CONN' => 'CT'];
            $stateInput = $stateMap[$stateInput] ?? $stateInput;
        }
        
        $stmt = $pdo->prepare("
            SELECT t.*, s.state_name, s.abbreviation 
            FROM towns t 
            JOIN states s ON t.state_id = s.state_id 
            WHERE t.town_name = ? AND s.abbreviation = ?
        ");
        $stmt->execute([$townName, $stateInput]);
        if ($row = $stmt->fetch()) {
            $context[] = "Town found: {$row['town_name']}, {$row['abbreviation']} (town_id: {$row['town_id']})";
            $context[] = "  - US Congress District: {$row['us_congress_district']}";
            $context[] = "  - State Senate District: {$row['state_senate_district']}";
            $context[] = "  - State House District: {$row['state_house_district']}";
        }
    }
    
    // Check for email mentions
    if (preg_match('/[\w\.\-]+@[\w\.\-]+\.\w+/', $message, $emailMatch)) {
        $stmt = $pdo->prepare("
            SELECT user_id, username, email, first_name, last_name, civic_points 
            FROM users WHERE email = ?
        ");
        $stmt->execute([$emailMatch[0]]);
        if ($row = $stmt->fetch()) {
            $context[] = "User found by email: {$row['username']} (user_id: {$row['user_id']})";
            $context[] = "  - Name: {$row['first_name']} {$row['last_name']}";
            $context[] = "  - Civic Points: {$row['civic_points']}";
        }
    }
    
    // Check for "thoughts" or "issues" keywords
    if (strpos($messageLower, 'thought') !== false || strpos($messageLower, 'issue') !== false) {
        $stmt = $pdo->query("
            SELECT content, upvotes, jurisdiction_level 
            FROM user_thoughts 
            ORDER BY created_at DESC LIMIT 3
        ");
        $thoughts = $stmt->fetchAll();
        if (!empty($thoughts)) {
            $context[] = "Recent thoughts in TPB:";
            foreach ($thoughts as $row) {
                $context[] = "  - \"{$row['content']}\" ({$row['upvotes']} upvotes, {$row['jurisdiction_level']})";
            }
        }
    }
    
    return implode("\n", $context);
}

/**
 * Parse action tags from Claude's response
 */
function parseActions($message) {
    $actions = [];
    preg_match_all('/\[ACTION:\s*(\w+)\](.*?)(?=\[ACTION:|$)/s', $message, $matches, PREG_SET_ORDER);
    
    foreach ($matches as $match) {
        $action = [
            'type' => $match[1],
            'params' => []
        ];
        preg_match_all('/(\w+):\s*(.+?)(?=\n\w+:|$)/s', $match[2], $params, PREG_SET_ORDER);
        foreach ($params as $param) {
            $action['params'][trim($param[1])] = trim($param[2]);
        }
        $actions[] = $action;
    }
    
    return $actions;
}

/**
 * Process an action
 */
function processAction($pdo, $action, $userId, $sessionId, $email = null) {
    // If we don't have userId but have email, look it up
    if (!$userId && $email) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($row = $stmt->fetch()) {
            $userId = $row['user_id'];
        }
    }

    switch ($action['type']) {
        case 'SET_TOWN':
            return processSetTown($pdo, $action['params'], $userId);
            
        case 'LOOKUP_USER':
            return processLookupUser($pdo, $action['params']);
            
        case 'LOOKUP_TOWN':
            return processLookupTown($pdo, $action['params']);
            
        case 'ADD_THOUGHT':
            return processAddThought($pdo, $action['params'], $userId);
            
        default:
            return ['action' => $action['type'], 'success' => false, 'error' => 'Unknown action'];
    }
}

function processSetTown($pdo, $params, $userId) {
    $stateInput = trim($params['state'] ?? '');
    $townInput = trim($params['town'] ?? '');
    
    // Normalize inputs
    $stateInput = strtoupper($stateInput);
    if (strlen($stateInput) > 2) {
        $stateInput = ucwords(strtolower($stateInput));
    }
    $townInput = ucwords(strtolower($townInput));
    
    $stmt = $pdo->prepare("
        SELECT t.town_id, t.state_id, t.town_name, s.state_name, s.abbreviation 
        FROM towns t 
        JOIN states s ON t.state_id = s.state_id 
        WHERE LOWER(t.town_name) = LOWER(?) 
        AND (UPPER(s.abbreviation) = UPPER(?) OR LOWER(s.state_name) = LOWER(?))
    ");
    $stmt->execute([$townInput, $stateInput, $stateInput]);
    $townRow = $stmt->fetch();
    
    if ($townRow) {
        if ($userId) {
            $stmt = $pdo->prepare("UPDATE users SET current_town_id = ?, current_state_id = ? WHERE user_id = ?");
            $stmt->execute([$townRow['town_id'], $townRow['state_id'], $userId]);
            return [
                'action' => 'SET_TOWN',
                'success' => true,
                'town_id' => $townRow['town_id'],
                'town_name' => $townRow['town_name'],
                'state' => $townRow['abbreviation'],
                'message' => "Town set to {$townRow['town_name']}, {$townRow['abbreviation']}"
            ];
        } else {
            return ['action' => 'SET_TOWN', 'success' => false, 'error' => 'User not identified'];
        }
    } else {
        return ['action' => 'SET_TOWN', 'success' => false, 'error' => "Town '$townInput' in '$stateInput' not found"];
    }
}

function processLookupUser($pdo, $params) {
    $email = $params['email'] ?? '';
    if (!$email) return ['action' => 'LOOKUP_USER', 'success' => false, 'error' => 'No email provided'];
    
    $stmt = $pdo->prepare("
        SELECT u.*, t.town_name, s.abbreviation 
        FROM users u 
        LEFT JOIN towns t ON u.current_town_id = t.town_id 
        LEFT JOIN states s ON u.current_state_id = s.state_id 
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        return [
            'action' => 'LOOKUP_USER',
            'success' => true,
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'name' => trim($user['first_name'] . ' ' . $user['last_name']),
                'town' => $user['town_name'] . ', ' . $user['abbreviation'],
                'civic_points' => $user['civic_points']
            ]
        ];
    }
    return ['action' => 'LOOKUP_USER', 'success' => false, 'error' => 'User not found'];
}

function processLookupTown($pdo, $params) {
    $townInput = trim($params['town'] ?? '');
    $stateInput = trim($params['state'] ?? '');
    
    $stmt = $pdo->prepare("
        SELECT t.*, s.state_name, s.abbreviation 
        FROM towns t 
        JOIN states s ON t.state_id = s.state_id 
        WHERE LOWER(t.town_name) = LOWER(?) 
        AND (UPPER(s.abbreviation) = UPPER(?) OR LOWER(s.state_name) = LOWER(?))
    ");
    $stmt->execute([$townInput, $stateInput, $stateInput]);
    $town = $stmt->fetch();
    
    if ($town) {
        return [
            'action' => 'LOOKUP_TOWN',
            'success' => true,
            'town' => [
                'town_id' => $town['town_id'],
                'name' => $town['town_name'] . ', ' . $town['abbreviation'],
                'us_congress' => $town['us_congress_district'],
                'state_senate' => $town['state_senate_district'],
                'state_house' => $town['state_house_district']
            ]
        ];
    }
    return ['action' => 'LOOKUP_TOWN', 'success' => false, 'error' => 'Town not found'];
}

function processAddThought($pdo, $params, $userId) {
    if (!$userId) {
        return ['action' => 'ADD_THOUGHT', 'success' => false, 'error' => 'User not identified'];
    }
    
    $content = $params['content'] ?? '';
    $jurisdiction = $params['jurisdiction'] ?? 'town';
    
    if (!$content) {
        return ['action' => 'ADD_THOUGHT', 'success' => false, 'error' => 'No content provided'];
    }
    
    // Get user's town_id and state_id
    $stmt = $pdo->prepare("SELECT current_town_id, current_state_id FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        INSERT INTO user_thoughts (user_id, content, jurisdiction_level, is_local, is_state, is_federal, town_id, state_id, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'published')
    ");
    $stmt->execute([
        $userId,
        $content,
        $jurisdiction,
        $jurisdiction === 'town' ? 1 : 0,
        $jurisdiction === 'state' ? 1 : 0,
        $jurisdiction === 'federal' ? 1 : 0,
        $userData['current_town_id'],
        $userData['current_state_id']
    ]);
    
    return [
        'action' => 'ADD_THOUGHT',
        'success' => true,
        'thought_id' => $pdo->lastInsertId(),
        'message' => 'Thought submitted successfully!'
    ];
}

/**
 * Clean action tags from response text
 */
function cleanActionTags($message) {
    return trim(preg_replace('/\[ACTION:\s*\w+\].*?(?=\n\n|$)/s', '', $message));
}
