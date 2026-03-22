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

$pdo->prepare("UPDATE ai_queue SET status = ?, result_data = ?, completed_at = NOW() WHERE id = ?")
    ->execute([$status, is_string($resultData) ? $resultData : json_encode($resultData), $jobId]);

// Auto-trigger post-processing for job types that need it
if ($status === 'done') {
    $jobStmt = $pdo->prepare("SELECT job_type FROM ai_queue WHERE id = ?");
    $jobStmt->execute([$jobId]);
    $jobType = $jobStmt->fetchColumn();

    $needsProcessing = ['threat_collect', 'statement_collect'];
    if (in_array($jobType, $needsProcessing)) {
        // Process inline — no HTTP call (avoids ModSecurity)
        // ai-process-result checks $_GET['key'] — already set from this request
        $_GET['job_id'] = $jobId;
        ob_start();
        require __DIR__ . '/ai-process-result.php';
        $processOutput = ob_get_clean();
    }
}

echo json_encode(['success' => true, 'job_id' => $jobId, 'status' => $status]);
