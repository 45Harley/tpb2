<?php
/**
 * Log civic action on a threat (called rep, emailed rep, shared).
 * Inserts into threat_responses and awards civic points via PointLogger.
 *
 * POST /api/log-threat-action.php
 * Body: { "threat_id": 42, "action_type": "called|emailed|shared", "rep_id": 9408 }
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
$actionType = $input['action_type'] ?? '';
$repId = !empty($input['rep_id']) ? (int)$input['rep_id'] : null;

if (!$threatId || !in_array($actionType, ['called', 'emailed', 'shared'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid threat_id or action_type']);
    exit;
}

// Insert response
$stmt = $pdo->prepare("INSERT INTO threat_responses (threat_id, user_id, action_type, rep_id) VALUES (?, ?, ?, ?)");
$stmt->execute([$threatId, $dbUser['user_id'], $actionType, $repId]);

// Award civic points
require_once __DIR__ . '/../includes/point-logger.php';
PointLogger::init($pdo);

$actionMap = ['called' => 'threat_called_rep', 'emailed' => 'threat_emailed_rep', 'shared' => 'threat_shared'];
$result = PointLogger::award($dbUser['user_id'], $actionMap[$actionType], 'threat', $threatId, 'executive', json_encode(['action_type' => $actionType, 'rep_id' => $repId]));

// Get response counts for this threat
$stmt = $pdo->prepare("SELECT action_type, COUNT(*) as cnt FROM threat_responses WHERE threat_id = ? GROUP BY action_type");
$stmt->execute([$threatId]);
$counts = [];
while ($row = $stmt->fetch()) $counts[$row['action_type']] = (int)$row['cnt'];

echo json_encode([
    'success' => true,
    'points_earned' => $result['points_earned'] ?? 0,
    'counts' => $counts
]);
