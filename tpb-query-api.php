<?php
// TPB Database Query API for MCP
// Suppress all errors from outputting - return as JSON instead
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any stray output
ob_start();

// Set JSON header early
header('Content-Type: application/json');

// Timeout and memory limits
set_time_limit(30);
ini_set('memory_limit', '128M');

// Query row limit to prevent huge result sets
$MAX_ROWS = 500;

// Simple API key for security
$API_KEY = '!44Dalesmith45!';

// Clean any buffered output before sending JSON
function sendJson($data) {
    ob_end_clean();
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    sendJson(['error' => $message]);
}

// Check API key
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if ($providedKey !== $API_KEY) {
    sendError('Unauthorized', 401);
}

// Database config
$dbHost = 'localhost';
$dbUser = 'sandge5_tpb2';
$dbPass = '.YeO6kSJAHh5';
$dbName = 'sandge5_tpb2';

// Connect
try {
    $conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($conn->connect_error) {
        sendError('Database connection failed', 500);
    }
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    sendError('Database connection failed', 500);
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'tables':
        $result = $conn->query('SHOW TABLES');
        if (!$result) {
            sendError('Query failed: ' . $conn->error);
        }
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        sendJson(['tables' => $tables, 'count' => count($tables)]);
        break;

    case 'describe':
        $table = $_GET['table'] ?? $_POST['table'] ?? '';
        // Sanitize table name
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (empty($table)) {
            sendError('Table name required');
        }
        $result = $conn->query("DESCRIBE `$table`");
        if (!$result) {
            sendError('Table not found or query failed');
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row;
        }
        sendJson(['table' => $table, 'columns' => $columns]);
        break;

    case 'query':
        $query = $_GET['query'] ?? $_POST['query'] ?? '';
        
        // Safety: only SELECT queries
        if (stripos(trim($query), 'SELECT') !== 0) {
            sendError('Only SELECT queries allowed');
        }
        
        // Add LIMIT if not present to prevent huge result sets
        if (stripos($query, 'LIMIT') === false) {
            $query = rtrim($query, "; \t\n\r") . " LIMIT $MAX_ROWS";
        }
        
        $result = $conn->query($query);
        if ($result === false) {
            sendError('Query failed: ' . $conn->error);
        }
        
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        
        $limited = count($rows) >= $MAX_ROWS;
        sendJson([
            'rows' => $rows, 
            'count' => count($rows),
            'limited' => $limited,
            'max_rows' => $MAX_ROWS
        ]);
        break;

    case 'count':
        // Quick row count for a table
        $table = $_GET['table'] ?? $_POST['table'] ?? '';
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (empty($table)) {
            sendError('Table name required');
        }
        $result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        if (!$result) {
            sendError('Query failed');
        }
        $row = $result->fetch_assoc();
        sendJson(['table' => $table, 'count' => (int)$row['cnt']]);
        break;

    default:
        sendJson([
            'error' => 'Invalid action',
            'available_actions' => ['tables', 'describe', 'query', 'count'],
            'usage' => [
                'tables' => 'List all tables',
                'describe' => 'Describe table structure (requires: table)',
                'query' => 'Execute SELECT query (requires: query)',
                'count' => 'Get row count for table (requires: table)'
            ]
        ]);
}

$conn->close();
