<?php
/**
 * Scan Pending — returns next pending job for the poller to pick up
 * Secured by a simple shared secret (not user auth).
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Simple auth — poller sends this key
$pollerKey = $config['poller_key'] ?? '';
$sentKey = $_GET['key'] ?? '';
if (!$pollerKey || $sentKey !== $pollerKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Optional job_type filter
$jobType = $_GET['type'] ?? '';

if ($jobType) {
    $stmt = $pdo->prepare("SELECT id, job_type, user_id, request_data FROM ai_queue WHERE status = 'pending' AND job_type = ? ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([$jobType]);
} else {
    $stmt = $pdo->query("SELECT id, job_type, user_id, request_data FROM ai_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
}
$job = $stmt->fetch();

if (!$job) {
    echo json_encode(['status' => 'empty']);
    exit;
}

// Mark as processing
$pdo->prepare("UPDATE ai_queue SET status = 'processing', started_at = NOW() WHERE id = ?")->execute([$job['id']]);

echo json_encode(['status' => 'found', 'job' => $job]);
