<?php
/**
 * Quick Thought API - /qt/api.php
 * Endpoint for logging ideas from any source
 * 
 * Usage:
 *   GET/POST: /qt/api.php?content=Your+idea+here&category=idea&source=voice
 *   
 * Parameters:
 *   content  - Required. The idea/note text
 *   category - Optional. idea|decision|todo|note (default: idea)
 *   source   - Optional. web|voice|claude-web|claude-desktop|api (default: api)
 *   key      - Optional. API key for programmatic access
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database configuration
$db_host = 'localhost';
$db_name = 'sandge5_tpb2';
$db_user = 'sandge5_tpb2';
$db_pass = '.YeO6kSJAHh5';

// API key for programmatic access (optional security layer)
$valid_api_key = '!44Dalesmith45!';

// Get parameters from GET or POST
$content = $_REQUEST['content'] ?? '';
$category = $_REQUEST['category'] ?? 'idea';
$source = $_REQUEST['source'] ?? 'api';
$api_key = $_REQUEST['key'] ?? '';

// Validate content
if (empty(trim($content))) {
    echo json_encode([
        'success' => false,
        'error' => 'Content is required'
    ]);
    exit;
}

// Validate category
$valid_categories = ['idea', 'decision', 'todo', 'note'];
if (!in_array($category, $valid_categories)) {
    $category = 'idea';
}

// Validate source
$valid_sources = ['web', 'voice', 'claude-web', 'claude-desktop', 'api'];
if (!in_array($source, $valid_sources)) {
    $source = 'api';
}

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $stmt = $pdo->prepare("
        INSERT INTO idea_log (content, category, source) 
        VALUES (:content, :category, :source)
    ");
    
    $stmt->execute([
        ':content' => trim($content),
        ':category' => $category,
        ':source' => $source
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'id' => $id,
        'message' => ucfirst($category) . ' logged successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
