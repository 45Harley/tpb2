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

$config = [
    'host' => 'localhost',
    'database' => 'sandge5_tpb2',
    'username' => 'sandge5_tpb2',
    'password' => '.YeO6kSJAHh5',
    'charset' => 'utf8mb4'
];

$input = json_decode(file_get_contents('php://input'), true);
$sessionId = $input['session_id'] ?? $_COOKIE['tpb_civic_session'] ?? null;
$cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;

if (!$sessionId) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Session ID required',
        'email_verified' => false
    ]);
    exit();
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name,
               u.age_bracket, u.parent_email, u.parent_consent,
               COALESCE(uis.email_verified, 0) as email_verified,
               COALESCE(uis.phone, '') as phone,
               COALESCE(uis.phone_verified, 0) as phone_verified
        FROM users u
        LEFT JOIN user_identity_status uis ON u.user_id = uis.user_id
        WHERE u.session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
