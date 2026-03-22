<?php
/**
 * Scan Complete — poller posts results back here
 * Accepts job id + result JSON via POST body.
 */
header('Content-Type: application/json');

$config = require dirname(__DIR__) . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Simple auth
$pollerKey = $config['poller_key'] ?? '';
$sentKey = $_GET['key'] ?? '';
if (!$pollerKey || $sentKey !== $pollerKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$jobId = intval($input['job_id'] ?? 0);
$status = ($input['status'] ?? '') === 'error' ? 'error' : 'done';
$resultData = $input['result'] ?? '';

if (!$jobId) {
    echo json_encode(['error' => 'No job_id']);
    exit;
}

$pdo->prepare("UPDATE scan_queue SET status = ?, result_data = ?, completed_at = NOW() WHERE id = ?")
    ->execute([$status, is_string($resultData) ? $resultData : json_encode($resultData), $jobId]);

echo json_encode(['success' => true, 'job_id' => $jobId, 'status' => $status]);
