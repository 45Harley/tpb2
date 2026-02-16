<?php
/**
 * TPB2 Check Session API
 * ======================
 * Check if a session is associated with a verified user
 * 
 * POST /api/check-session.php
 * Body: { "session_id": "civic_xxx" }
 * 
 * Returns: { "status": "success", "email_verified": true/false, "user_id": N }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/get-user.php';

$input = json_decode(file_get_contents('php://input'), true);
$explicitSessionId = $input['session_id'] ?? null;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Try cookie-based auth first, then fall back to explicit session_id from POST
    $user = getUser($pdo);
    if (!$user && $explicitSessionId) {
        $user = getUserBySession($pdo, $explicitSessionId);
    }

    if ($user) {
        // Determine if user needs parent consent
        $isMinor = $user['age_bracket'] === '13-17';
        $needsParentConsent = $isMinor && !$user['parent_consent'];
        
        echo json_encode([
            'status' => 'success',
            'user_id' => (int)$user['user_id'],
            'email_verified' => (bool)$user['email_verified'],
            'has_email' => !empty($user['email']) && strpos($user['email'], 'placeholder') === false,
            'first_name' => $user['first_name'] ?: null,
            'last_name' => $user['last_name'] ?: null,
            'phone' => $user['phone'] ?: null,
            'phone_verified' => (bool)$user['phone_verified'],
            'age_bracket' => $user['age_bracket'] ?: null,
            'is_minor' => $isMinor,
            'parent_consent' => (bool)$user['parent_consent'],
            'needs_parent_consent' => $needsParentConsent
        ]);
    } else {
        echo json_encode([
            'status' => 'success',
            'user_id' => null,
            'email_verified' => false,
            'has_email' => false,
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'phone_verified' => false,
            'age_bracket' => null,
            'is_minor' => false,
            'parent_consent' => false,
            'needs_parent_consent' => false
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error',
        'email_verified' => false
    ]);
}
