<?php
/**
 * TPB2 Modal Help System - Get Page Modals API
 * =============================================
 * Fetch all active modals for a specific page
 * 
 * Usage: GET /api/modal/get-page-modals.php?page=registration
 * 
 * Returns: All modals configured for that page with their tag identifiers
 */

require_once __DIR__ . '/../../config/modal_config.php';

setJsonHeaders();

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

// Get page name from query string
$pageName = $_GET['page'] ?? null;

if (empty($pageName)) {
    errorResponse('Missing required parameter: page', 400);
}

// Sanitize the page name
$pageName = preg_replace('/[^a-zA-Z0-9_-]/', '', $pageName);

try {
    $db = getModalDB();
    
    // Fetch all modals for this page
    $stmt = $db->prepare("
        SELECT 
            m.modal_key,
            m.title,
            m.icon_type,
            m.category,
            m.markdown_content,
            m.tooltip_preview,
            tl.tag_identifier,
            tl.position_hint,
            tl.display_order
        FROM modals m
        INNER JOIN tag_locations tl ON m.modal_id = tl.modal_id
        WHERE tl.page_name = :page
          AND tl.is_visible = TRUE
          AND m.is_active = TRUE
          AND m.status = 'published'
        ORDER BY tl.display_order ASC
    ");
    
    $stmt->execute(['page' => $pageName]);
    $modals = $stmt->fetchAll();
    
    // Add emoji to each modal
    foreach ($modals as &$modal) {
        $modal['icon_emoji'] = getIconEmoji($modal['icon_type']);
    }
    
    // Return success response
    jsonResponse([
        'status' => 'success',
        'page' => $pageName,
        'count' => count($modals),
        'modals' => $modals
    ]);
    
} catch (PDOException $e) {
    errorResponse('Database error', 500);
}
