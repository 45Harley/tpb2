<?php
/**
 * Scan Result — poll for scan completion
 * Browser calls this with scan_id, gets status or results.
 */
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
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$scanId = intval($_GET['id'] ?? 0);
if (!$scanId) {
    echo json_encode(['error' => 'No scan_id']);
    exit;
}

// Only allow user to check their own scans
$stmt = $pdo->prepare("SELECT status, result_data, created_at, started_at, completed_at FROM scan_queue WHERE id = ? AND user_id = ?");
$stmt->execute([$scanId, (int)$dbUser['user_id']]);
$scan = $stmt->fetch();

if (!$scan) {
    echo json_encode(['error' => 'Scan not found']);
    exit;
}

$response = ['status' => $scan['status'], 'created_at' => $scan['created_at']];

if ($scan['status'] === 'processing') {
    $response['started_at'] = $scan['started_at'];
}

if ($scan['status'] === 'done' || $scan['status'] === 'error') {
    $response['completed_at'] = $scan['completed_at'];
    $response['result'] = json_decode($scan['result_data'], true);
}

echo json_encode($response);
