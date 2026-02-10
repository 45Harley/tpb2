<?php
/**
 * TPB2 Modal Help System - Get Pages API
 * =======================================
 * Fetch list of predefined pages for admin dropdown
 * 
 * Usage: GET /api/modal/get-pages.php
 * 
 * Returns: List of all predefined pages
 */

require_once __DIR__ . '/../../config/modal_config.php';

setJsonHeaders();

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

try {
    $db = getModalDB();
    
    // Fetch all active pages
    $stmt = $db->query("
        SELECT 
            page_id,
            page_name,
            page_title,
            page_description,
            is_active
        FROM predefined_pages
        WHERE is_active = TRUE
        ORDER BY page_title ASC
    ");
    
    $pages = $stmt->fetchAll();
    
    jsonResponse([
        'status' => 'success',
        'count' => count($pages),
        'pages' => $pages
    ]);
    
} catch (PDOException $e) {
    errorResponse('Database error', 500);
}
