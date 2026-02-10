<?php
/**
 * TPB2 Modal Help System - Database Configuration
 * ================================================
 * Connection settings for sandge5_modals database
 */

// Modal Help Database Connection
define('MODAL_DB_HOST', 'localhost');
define('MODAL_DB_NAME', 'sandge5_modals');
define('MODAL_DB_USER', 'sandge5_tpb2');
define('MODAL_DB_PASS', '.YeO6kSJAHh5');

/**
 * Get PDO connection to modal database
 * @return PDO
 */
function getModalDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . MODAL_DB_HOST . ";dbname=" . MODAL_DB_NAME . ";charset=utf8mb4",
                MODAL_DB_USER,
                MODAL_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    
    return $pdo;
}

/**
 * Icon type to emoji mapping
 */
function getIconEmoji($iconType) {
    $icons = [
        'info'       => 'ℹ️',
        'help'       => '❓',
        'preview'    => '⏳',
        'warning'    => '⚠️',
        'tip'        => '💡',
        'docs'       => '📘',
        'tutorial'   => '🎓',
        'feature'    => '🎯',
        'success'    => '✅',
        'new'        => '🚀',
        'security'   => '🔒',
        'important'  => '⭐',
        'location'   => '📍',
        'external'   => '🔗',
        'social'     => '👥',
        'philosophy' => '🎪'
    ];
    
    return $icons[$iconType] ?? 'ℹ️';
}

/**
 * Set JSON headers for API responses
 */
function setJsonHeaders() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Send error response
 */
function errorResponse($message, $statusCode = 400) {
    jsonResponse(['error' => $message, 'status' => 'error'], $statusCode);
}
