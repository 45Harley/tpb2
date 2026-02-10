<?php
/**
 * The People's Branch - Save Volunteer Profile API
 * =================================================
 * Saves user's skills, primary skill, and bio
 */

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

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
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Get session from cookie
$sessionId = $_COOKIE['tpb_civic_session'] ?? null;
$cookieUserId = isset($_COOKIE['tpb_user_id']) ? (int)$_COOKIE['tpb_user_id'] : 0;

if (!$sessionId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

// Get user via device session
$stmt = $pdo->prepare("
    SELECT u.user_id
    FROM user_devices ud
    INNER JOIN users u ON ud.user_id = u.user_id
    WHERE ud.device_session = ? AND ud.is_active = 1
");
$stmt->execute([$sessionId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

$userId = $user['user_id'];

// Check if user is an approved volunteer
$stmt = $pdo->prepare("SELECT status FROM volunteer_applications WHERE user_id = ? AND status = 'accepted'");
$stmt->execute([$userId]);
$volunteer = $stmt->fetch();

if (!$volunteer) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not an approved volunteer']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);

$skills = $input['skills'] ?? [];
$primarySkill = $input['primary_skill'] ?? null;
$bio = $input['bio'] ?? '';

// Validate skills array
if (!is_array($skills)) {
    $skills = [];
}
$skills = array_filter(array_map('intval', $skills));

// Validate primary skill is in selected skills
if ($primarySkill && !in_array($primarySkill, $skills)) {
    $skills[] = $primarySkill; // Auto-add primary to skills
}

// Sanitize bio
$bio = trim($bio);
if (strlen($bio) > 2000) {
    $bio = substr($bio, 0, 2000);
}

// Begin transaction
$pdo->beginTransaction();

try {
    // Update bio in users table
    $stmt = $pdo->prepare("UPDATE users SET bio = ? WHERE user_id = ?");
    $stmt->execute([$bio, $userId]);
    
    // Get current skills
    $stmt = $pdo->prepare("SELECT skill_set_id FROM user_skill_progression WHERE user_id = ?");
    $stmt->execute([$userId]);
    $currentSkills = array_column($stmt->fetchAll(), 'skill_set_id');
    
    // Skills to add
    $toAdd = array_diff($skills, $currentSkills);
    
    // Skills to remove
    $toRemove = array_diff($currentSkills, $skills);
    
    // Add new skills
    foreach ($toAdd as $skillId) {
        $stmt = $pdo->prepare("
            INSERT INTO user_skill_progression (user_id, skill_set_id, status, is_primary)
            VALUES (?, ?, 'active', ?)
        ");
        $isPrimary = ($skillId == $primarySkill) ? 1 : 0;
        $stmt->execute([$userId, $skillId, $isPrimary]);
    }
    
    // Remove unselected skills
    if (!empty($toRemove)) {
        $placeholders = str_repeat('?,', count($toRemove) - 1) . '?';
        $stmt = $pdo->prepare("
            DELETE FROM user_skill_progression 
            WHERE user_id = ? AND skill_set_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$userId], $toRemove));
    }
    
    // Update primary flag for all remaining skills
    // First, clear all primary flags
    $stmt = $pdo->prepare("UPDATE user_skill_progression SET is_primary = 0 WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    // Then set the primary skill
    if ($primarySkill) {
        $stmt = $pdo->prepare("
            UPDATE user_skill_progression SET is_primary = 1 
            WHERE user_id = ? AND skill_set_id = ?
        ");
        $stmt->execute([$userId, $primarySkill]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Volunteer profile saved',
        'skills_count' => count($skills),
        'primary_skill' => $primarySkill
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error saving profile']);
}
