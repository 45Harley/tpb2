<?php
/**
 * Benefits Match API — scan user profile against federal + state programs
 * ========================================================================
 * POST — reads user_profile, calls Claude, returns matched programs as JSON
 */

header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
require_once dirname(__DIR__) . '/includes/site-settings.php';

$dbUser = getUser($pdo);
if (!$dbUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}
$userId = (int)$dbUser['user_id'];

// Load profile
$stmt = $pdo->prepare("SELECT * FROM user_profile WHERE user_id = ?");
$stmt->execute([$userId]);
$profile = $stmt->fetch();
if (!$profile) {
    echo json_encode(['error' => 'No profile found. Please fill out your Benefits Finder first.']);
    exit;
}

// Check opt-in
if (empty($profile['benefits_match_optin'])) {
    echo json_encode(['error' => 'Benefits matching not enabled.']);
    exit;
}

// Build profile summary for prompt
$state = $dbUser['state_abbrev'] ?? 'CT';
$stateName = $dbUser['state_name'] ?? 'Connecticut';
$town = $dbUser['town_name'] ?? '';

$profileLines = [];
foreach ($profile as $key => $val) {
    if ($val === null || $val === '' || $key === 'user_id' || $key === 'updated_at' || $key === 'benefits_match_optin') continue;
    $label = ucwords(str_replace('_', ' ', $key));
    $profileLines[] = "- {$label}: {$val}";
}
$profileText = implode("\n", $profileLines);

// Age from DOB
$age = '';
if (!empty($profile['date_of_birth'])) {
    $dob = new DateTime($profile['date_of_birth']);
    $now = new DateTime();
    $age = $dob->diff($now)->y . ' years old';
}

$systemPrompt = <<<PROMPT
You are a Benefits Navigator for The People's Branch civic platform.

Your job: Given this person's profile, identify federal and state programs they may qualify for. Be specific, practical, and honest.

## User Profile
Location: {$town}, {$stateName} ({$state})
{$age}

{$profileText}

## Instructions
1. Search your knowledge for ALL relevant benefit programs — federal, state ({$stateName}), and local
2. Include programs across ALL categories: housing, food, healthcare, energy, tax credits, education, childcare, veterans, disability, employment, senior services
3. For each program, provide:
   - Program name
   - What it provides (dollar amounts when known)
   - Why this person likely qualifies (based on their specific profile data)
   - How to apply (website URL or phone number)
   - Category (one of: Housing, Food, Healthcare, Energy, Tax Credits, Education, Childcare, Veterans, Disability, Employment, Senior Services, Other)
4. Be honest — say "may qualify" not "you qualify" since final eligibility depends on full application
5. Include {$stateName}-specific programs. Every state has its own versions of:
   - First-time homebuyer / down payment assistance (state housing finance authority)
   - Energy assistance (state LIHEAP administrator, local fuel funds)
   - State Medicaid / healthcare marketplace
   - State food assistance beyond federal SNAP
   - State education grants and workforce training
   - State childcare subsidies
   - State tax credits (earned income, property tax relief, renter's credit)
   Use your knowledge of {$stateName}'s specific program names, agencies, and websites.
6. Include municipal programs for {$town}, {$stateName} if you know of any
7. Don't include programs they clearly don't qualify for based on their profile
8. Sort by estimated value (highest dollar benefit first)

## Output Format
Return ONLY valid JSON. No markdown, no code blocks.
{
  "programs": [
    {
      "name": "Program Name",
      "category": "Housing",
      "provides": "What you could receive — dollar amounts if known",
      "why_you_qualify": "Based on your profile: specific reason",
      "how_to_apply": "URL or phone number",
      "estimated_annual_value": 0,
      "confidence": "high|medium|low"
    }
  ],
  "summary": "One paragraph summary of total estimated value and top recommendations",
  "disclaimer": "These are preliminary matches based on your profile. Final eligibility is determined by each program's application process."
}
PROMPT;

$userMessage = "Based on my profile, what federal and {$stateName} programs might I qualify for? Please check all categories — housing, food, healthcare, energy, tax credits, education, and any others that apply to my situation.";

// Call Claude — local pipe or API
$claudiaLocalEnabled = getSiteSetting($pdo, 'claudia_local_enabled', '0');

$messages = [['role' => 'user', 'content' => $userMessage]];

if ($claudiaLocalEnabled === '1') {
    $response = callLocalClaude($systemPrompt, $messages);
} else {
    // Fall back to Anthropic API
    require_once dirname(__DIR__) . '/config-claude.php';
    $response = callClaudeAPI($systemPrompt, $messages, CLAUDE_MODEL, false);
}

if (isset($response['error'])) {
    echo json_encode(['error' => $response['error']]);
    exit;
}

// Extract response text
$text = '';
if (isset($response['response'])) {
    $text = $response['response'];
} elseif (isset($response['content'])) {
    if (is_array($response['content'])) {
        foreach ($response['content'] as $block) {
            if (isset($block['text'])) $text .= $block['text'];
        }
    } else {
        $text = $response['content'];
    }
}

// Parse JSON from response
$jsonStr = $text;
if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $jsonStr, $m)) {
    $jsonStr = $m[1];
}
if (preg_match('/\{[\s\S]*"programs"[\s\S]*\}/s', $jsonStr, $m)) {
    $jsonStr = $m[0];
}

$parsed = json_decode($jsonStr, true);
if (!$parsed || !isset($parsed['programs'])) {
    // Return raw text if JSON parse fails
    echo json_encode(['error' => 'Could not parse benefits results', 'raw' => substr($text, 0, 3000)]);
    exit;
}

echo json_encode($parsed);

// === Functions (same as claude-chat.php) ===

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

    if ($curlError) return ['error' => "Connection error: {$curlError}"];
    if ($httpCode !== 200) return ['error' => "Service returned HTTP {$httpCode}"];

    $data = json_decode($response, true);
    if (!$data) return ['error' => 'Invalid response from service'];
    return $data;
}

function callClaudeAPI($systemPrompt, $messages, $model, $webSearch = false) {
    $body = [
        'model' => $model,
        'max_tokens' => 4096,
        'system' => $systemPrompt,
        'messages' => $messages
    ];

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01'
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['error' => "API error: {$curlError}"];

    $data = json_decode($response, true);
    if (!$data) return ['error' => 'Invalid API response'];
    if (isset($data['error'])) return ['error' => $data['error']['message'] ?? 'API error'];
    return $data;
}
