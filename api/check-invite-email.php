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

$email = trim(strtolower($_GET['email'] ?? ''));
if (!$email) {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$email]);
echo json_encode(['exists' => (bool)$stmt->fetch()]);
