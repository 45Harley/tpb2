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
$config = require __DIR__ . '/../config.php';

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
$webSearchEnabled = !empty($input['web_search']);  // User-toggled, default OFF

// Handle Claudia widget toggle action
if (isset($input['action']) && $input['action'] === 'disable_claudia' && $userId) {
    $stmt = $pdo->prepare("UPDATE users SET claudia_enabled = 0 WHERE user_id = ?");
    $stmt->execute([(int)$userId]);
    echo json_encode(['success' => true]);
    exit;
}

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

// 2b. Add current page context
$pageContext = $input['context'] ?? null;
if ($pageContext) {
    $systemPrompt .= "\n\n## Current Page\nThe user is currently on the '{$pageContext}' page. Use this to give contextually relevant responses.";
}

// 2c. Add form field context (from dynamic DOM scanner)
$pageContextData = $input['page_context'] ?? null;
if ($pageContextData) {
    $decoded = is_string($pageContextData) ? json_decode($pageContextData, true) : $pageContextData;
    if ($decoded && !empty($decoded['formFields'])) {
        $systemPrompt .= "\n\n## Available Form Fields\nThese fields are visible on the current page and can be updated with UPDATE_FIELD:\n";
        foreach ($decoded['formFields'] as $f) {
            $line = "- **{$f['id']}** ({$f['type']})";
            if (!empty($f['label'])) $line .= " — \"{$f['label']}\"";
            if (isset($f['value']) && $f['value'] !== '') $line .= " [current: {$f['value']}]";
            if (!empty($f['options'])) $line .= " Options: " . implode(', ', $f['options']);
            $systemPrompt .= $line . "\n";
        }
    }
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

// === CALL CLAUDE (API or LOCAL) ===
// #call-api
require_once __DIR__ . '/../includes/site-settings.php';
$claudiaLocalEnabled = getSiteSetting($pdo, 'claudia_local_enabled', '0');

if ($claudiaLocalEnabled === '1') {
    $response = callLocalClaude($systemPrompt, $messages);
} else {
    $model = $clerk['model'] ?? CLAUDE_MODEL;
    $response = callClaudeAPI($systemPrompt, $messages, $model, $webSearchEnabled);
}

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
    if (in_array('navigate', $capabilities)) {
        $instructions .= "To navigate the user's browser to a TPB page (replaces current page):\n[ACTION: NAVIGATE]\nurl: {relative URL like /story.php or /z-states/ct/putnam/}\n\n";
    }
    if (in_array('open_tab', $capabilities)) {
        $instructions .= "To open a page in a new browser tab (keeps current page):\n[ACTION: OPEN_TAB]\nurl: {relative URL}\n\n";
    }
    if (in_array('navigate', $capabilities) || in_array('open_tab', $capabilities)) {
        $instructions .= "Available pages:\n";
        $instructions .= "- /index.php — Home (USA map)\n";
        $instructions .= "- /story.php — Our Story (TPB mission & history)\n";
        $instructions .= "- /profile.php — User's profile\n";
        $instructions .= "- /constitution/ — Constitution section\n";
        $instructions .= "- /elections/ — Elections section\n";
        $instructions .= "- /elections/threats.php — Threat stream (all active threats)\n";
        $instructions .= "- /elections/the-fight.php — The Fight (pledges & knockouts)\n";
        $instructions .= "- /help/ — Help center\n";
        $instructions .= "- /join.php — Join / sign up\n";
        $instructions .= "- /poll/ — Polls\n";
        $instructions .= "- /talk/ — Community discussion\n";
        $instructions .= "- /volunteer/ — Volunteer\n";
        $instructions .= "- /usa/ — USA overview map\n";
        $instructions .= "- /usa/congressional-overview.php — All 541 members of Congress with photos and stats\n";
        $instructions .= "- /usa/congressional-overview.php?state={XX} — Jump to a specific state delegation (e.g. ?state=CT)\n";
        $instructions .= "- /usa/executive-overview.php — Executive branch officials\n";
        $instructions .= "- /usa/judicial.php — Federal judiciary overview\n";
        $instructions .= "- /usa/glossary.php — Congressional glossary (130+ terms)\n";
        $instructions .= "- /usa/rep.php?id={official_id} — Individual rep detail page\n";
        $instructions .= "- /z-states/{state}/ — State page (e.g. /z-states/ct/)\n";
        $instructions .= "- /z-states/{state}/{town}/ — Town page (e.g. /z-states/ct/putnam/)\n";
        $instructions .= "\nUse NAVIGATE for destinations (user is going there). Use OPEN_TAB for reference material (user wants to read while staying in chat).\n";
        $instructions .= "When the user says 'go to', 'open', 'show me', 'take me to' a page, use the appropriate action. ALWAYS include the action tag — do not just describe the page.\n";
        $instructions .= "Smart routing: If the user asks to see 'my delegation', 'my reps', or 'my representatives in Congress', navigate to /usa/congressional-overview.php?state={XX} using their state from the user context. If no state is known, navigate without ?state= and suggest they pick one.\n\n";
    }
    if (in_array('update_field', $capabilities)) {
        $instructions .= "To update a user's profile field:\n[ACTION: UPDATE_FIELD]\nfield: {field_id from form fields}\nvalue: {new value}\n\n";
        $instructions .= "The page_context.formFields array shows available form fields on the current page with their IDs, types, labels, current values, and options (for selects).\n";
        $instructions .= "Rules:\n";
        $instructions .= "- Only update fields that appear in formFields\n";
        $instructions .= "- For state abbreviations: convert full names to 2-letter codes (e.g. Connecticut → CT)\n";
        $instructions .= "- For checkboxes: use 'true' or 'false'\n";
        $instructions .= "- For selects: match one of the available options exactly\n";
        $instructions .= "- Protected fields (email, phone): warn the user that changing these will require re-verification\n";
        $instructions .= "- Read-only fields cannot be updated — tell the user if they ask\n";
        $instructions .= "- Validate: phone must be digits (10+), email must have @, names must be non-empty strings\n";
        $instructions .= "- ALWAYS confirm with the user before updating\n\n";
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
    // Match action tag + only the key:value lines immediately after (stop at blank line, markdown, or next action)
    preg_match_all('/\[ACTION:\s*(\w+)\]\s*\n((?:\w+:\s*.+\n?)+)/s', $message, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $action = [
            'type' => $match[1],
            'params' => []
        ];
        // Parse only clean key: value lines
        preg_match_all('/^(\w+):\s*(.+)$/m', $match[2], $params, PREG_SET_ORDER);
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

        case 'NAVIGATE':
        case 'OPEN_TAB':
            // Client-side actions — pass through to JS handler
            return [
                'action' => $action['type'],
                'success' => true,
                'url' => $action['params']['url'] ?? '/'
            ];

        case 'UPDATE_FIELD':
            return processUpdateField($pdo, $action['params'], $userId);

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
 * Process UPDATE_FIELD — routes through save-profile.php logic
 * Field categories:
 *   Simple: first_name, last_name, age_bracket, show_first_name, show_last_name,
 *           show_age_bracket, notify_threat_bulletin, volunteerBio, primarySkill, street_address
 *   Protected: email, phone (resets verification)
 *   Read-only: user_id, civic_points, identity_level_id, created_at
 */
function processUpdateField($pdo, $params, $userId) {
    if (!$userId) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => 'You need to be logged in to update your profile.'];
    }

    $fieldId = $params['field'] ?? '';
    $value = $params['value'] ?? '';

    // Map DOM field IDs → save-profile.php field names
    $fieldMap = [
        'firstName'           => ['key' => 'first_name',           'category' => 'simple'],
        'lastName'            => ['key' => 'last_name',            'category' => 'simple'],
        'ageBracket'          => ['key' => 'age_bracket',          'category' => 'simple'],
        'showFirstName'       => ['key' => 'show_first_name',      'category' => 'simple', 'bool' => true],
        'showLastName'        => ['key' => 'show_last_name',       'category' => 'simple', 'bool' => true],
        'showAgeBracket'      => ['key' => 'show_age_bracket',     'category' => 'simple', 'bool' => true],
        'notifyThreatBulletin'=> ['key' => 'notify_threat_bulletin','category' => 'simple', 'bool' => true],
        'streetAddressInput'  => ['key' => 'street_address',       'category' => 'simple'],
        'emailInput'          => ['key' => 'email',                'category' => 'protected'],
        'phone'               => ['key' => 'phone',                'category' => 'protected'],
        'primarySkill'        => ['key' => 'primary_skill',        'category' => 'simple'],
        'volunteerBio'        => ['key' => 'volunteer_bio',        'category' => 'simple'],
    ];

    if (!isset($fieldMap[$fieldId])) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => "Field '$fieldId' cannot be updated."];
    }

    $spec = $fieldMap[$fieldId];
    $dbKey = $spec['key'];
    $category = $spec['category'];

    // Build save-profile.php compatible payload
    $payload = ['session_id' => 'internal'];

    if (!empty($spec['bool'])) {
        $boolVal = in_array(strtolower($value), ['true', '1', 'yes', 'on']);
        $payload[$dbKey] = $boolVal;
    } else {
        $payload[$dbKey] = $value;
    }

    // Basic validation
    if ($dbKey === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => "That doesn't look like a valid email address."];
    }
    if ($dbKey === 'phone' && !preg_match('/^\d{10,15}$/', preg_replace('/\D/', '', $value))) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => "Phone number should be 10+ digits."];
    }
    if (in_array($dbKey, ['first_name', 'last_name']) && strlen(trim($value)) === 0) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => "Name can't be empty."];
    }

    // Direct DB update (same logic as save-profile.php)
    try {
        if ($dbKey === 'email') {
            // Protected: update email + reset verification
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
            $stmt = $pdo->prepare("UPDATE user_identity_status SET email_verified = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);
            return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "Email updated. You'll need to re-verify it.", 'fieldId' => $fieldId, 'fieldValue' => $value];
        }

        if ($dbKey === 'phone') {
            // Protected: update phone + reset verification
            $stmt = $pdo->prepare("UPDATE user_identity_status SET phone = ?, phone_verified = 0, phone_verify_token = NULL, phone_verify_expires = NULL WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
            return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "Phone updated. You'll need to re-verify it.", 'fieldId' => $fieldId, 'fieldValue' => $value];
        }

        if ($dbKey === 'volunteer_bio') {
            // Volunteer bio lives in volunteer_applications
            $stmt = $pdo->prepare("UPDATE volunteer_applications SET bio = ? WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
            return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "Volunteer bio updated!", 'fieldId' => $fieldId, 'fieldValue' => $value];
        }

        if ($dbKey === 'primary_skill') {
            // Primary skill lives in volunteer_applications
            $stmt = $pdo->prepare("UPDATE volunteer_applications SET primary_skill_id = (SELECT skill_id FROM skill_sets WHERE skill_name = ? LIMIT 1) WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
            return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "Primary skill updated!", 'fieldId' => $fieldId, 'fieldValue' => $value];
        }

        if ($dbKey === 'street_address') {
            $stmt = $pdo->prepare("UPDATE users SET street_address = ? WHERE user_id = ?");
            $stmt->execute([$value, $userId]);
            return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "Street address updated!", 'fieldId' => $fieldId, 'fieldValue' => $value];
        }

        // Simple fields on users table
        if (!empty($spec['bool'])) {
            $dbVal = in_array(strtolower($value), ['true', '1', 'yes', 'on']) ? 1 : 0;
        } else {
            $dbVal = $value;
        }

        $stmt = $pdo->prepare("UPDATE users SET $dbKey = ? WHERE user_id = ?");
        $stmt->execute([$dbVal, $userId]);

        $friendlyNames = [
            'first_name' => 'First name', 'last_name' => 'Last name',
            'age_bracket' => 'Age bracket', 'show_first_name' => 'Show first name',
            'show_last_name' => 'Show last name', 'show_age_bracket' => 'Show age bracket',
            'notify_threat_bulletin' => 'Threat bulletin notifications'
        ];
        $label = $friendlyNames[$dbKey] ?? $dbKey;
        return ['action' => 'UPDATE_FIELD', 'success' => true, 'message' => "$label updated!", 'fieldId' => $fieldId, 'fieldValue' => $value];

    } catch (PDOException $e) {
        return ['action' => 'UPDATE_FIELD', 'success' => false, 'error' => 'Database error updating field.'];
    }
}

/**
 * Clean action tags from response text
 */
function cleanActionTags($message) {
    return trim(preg_replace('/\[ACTION:\s*\w+\].*?(?=\n\n|$)/s', '', $message));
}

/**
 * Call local claude -p via reverse SSH tunnel
 */
function callLocalClaude($systemPrompt, $messages) {
    $payload = json_encode([
        'system_prompt' => $systemPrompt,
        'messages' => $messages
    ]);

    $ch = curl_init('http://127.0.0.1:9876');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => "Local tunnel error: {$curlError}"];
    }
    if ($httpCode !== 200) {
        return ['error' => "Local listener returned HTTP {$httpCode}"];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['error' => 'Invalid response from local listener'];
    }

    return $data;
}
