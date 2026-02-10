<?php
// TPB Database Query API
// Improved version with better error handling

// Increase limits for larger queries
set_time_limit(30);
ini_set('memory_limit', '128M');

// Always return JSON, even on errors
header('Content-Type: application/json');

// Catch fatal errors and return JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['error' => 'Fatal error: ' . $error['message']]);
    }
});

// Simple API key for security
$API_KEY = '!44Dalesmith45!';

// Check API key
$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if ($providedKey !== $API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Database config
$dbHost = 'localhost';
$dbUser = 'sandge5_tpb2';
$dbPass = '.YeO6kSJAHh5';
$dbName = 'sandge5_tpb2';

// Connect with UTF-8
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}
$conn->set_charset('utf8mb4');

// Helper function to safely encode JSON
function safe_json_encode($data) {
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        return json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
    }
    return $json;
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'tables':
        $result = $conn->query('SHOW TABLES');
        if ($result === false) {
            echo safe_json_encode(['error' => 'Query failed: ' . $conn->error]);
            break;
        }
        $tables = [];
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
        echo safe_json_encode(['tables' => $tables]);
        break;

    case 'describe':
        $table = $_GET['table'] ?? $_POST['table'] ?? '';
        // Sanitize table name
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if (empty($table)) {
            echo safe_json_encode(['error' => 'Table name required']);
            break;
        }
        $result = $conn->query("DESCRIBE `$table`");
        if ($result === false) {
            echo safe_json_encode(['error' => 'Query failed: ' . $conn->error]);
            break;
        }
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row;
        }
        echo safe_json_encode(['columns' => $columns]);
        break;

    case 'query':
        $query = $_GET['query'] ?? $_POST['query'] ?? '';
        
        // Safety: only SELECT queries
        if (stripos(trim($query), 'SELECT') !== 0) {
            echo safe_json_encode(['error' => 'Only SELECT queries allowed']);
            break;
        }
        
        // Execute query
        $result = $conn->query($query);
        if ($result === false) {
            echo safe_json_encode(['error' => 'Query failed: ' . $conn->error]);
            break;
        }
        
        // Fetch rows with row limit for safety
        $rows = [];
        $maxRows = 1000;
        $count = 0;
        while (($row = $result->fetch_assoc()) && $count < $maxRows) {
            // Clean each field for JSON encoding
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    // Convert to UTF-8 if needed
                    $row[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            }
            $rows[] = $row;
            $count++;
        }
        
        $response = ['rows' => $rows];
        if ($count >= $maxRows) {
            $response['warning'] = "Results limited to $maxRows rows";
        }
        
        echo safe_json_encode($response);
        break;

    default:
        echo safe_json_encode(['error' => 'Invalid action. Use: tables, describe, query']);
}

$conn->close();
