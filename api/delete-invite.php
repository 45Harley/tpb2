<?php
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

require_once dirname(__DIR__) . '/includes/get-user.php';
$dbUser = getUser($pdo);
if (!$dbUser) {
    http_response_code(403);
    echo json_encode(['error' => 'Login required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$inviteId = (int)($input['id'] ?? 0);
if (!$inviteId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing invite ID.']);
    exit;
}

// Only allow deleting own invitations that haven't been joined
$stmt = $pdo->prepare("DELETE FROM invitations WHERE id = ? AND invitor_user_id = ? AND status != 'joined'");
$stmt->execute([$inviteId, $dbUser['user_id']]);

echo json_encode(['deleted' => (bool)$stmt->rowCount()]);
