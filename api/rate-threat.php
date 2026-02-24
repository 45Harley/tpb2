<?php
/**
 * Rate a threat's danger level (-10 to +10).
 * One rating per user per threat (upsert).
 *
 * POST /api/rate-threat.php
 * Body: { "threat_id": 42, "rating": 7 }
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

$config = require __DIR__ . '/../config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}", $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) { http_response_code(401); echo json_encode(['error' => 'Login required']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$threatId = (int)($input['threat_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0);

if (!$threatId || $rating < -10 || $rating > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid threat_id or rating (-10 to +10)']);
    exit;
}

// Upsert rating
$stmt = $pdo->prepare("
    INSERT INTO threat_ratings (threat_id, user_id, rating)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = CURRENT_TIMESTAMP
");
$stmt->execute([$threatId, $dbUser['user_id'], $rating]);

// Award points (only on first rating — cooldown handles this)
require_once __DIR__ . '/../includes/point-logger.php';
PointLogger::init($pdo);
PointLogger::award($dbUser['user_id'], 'threat_rated', 'threat_rating', $threatId, 'executive');

// Get updated average
$stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as rating_count FROM threat_ratings WHERE threat_id = ?");
$stmt->execute([$threatId]);
$row = $stmt->fetch();

echo json_encode([
    'success' => true,
    'avg' => round((float)$row['avg_rating'], 1),
    'count' => (int)$row['rating_count']
]);
