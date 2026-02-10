<?php
/**
 * TPB2 Modal Help System - Get Modal API
 * =======================================
 * Fetch a single modal by its key
 * 
 * Usage: GET /api/modal/get-modal.php?key=voting_explained
 * 
 * Returns: Modal content with markdown, title, icon type, etc.
 */

require_once __DIR__ . '/../../config/modal_config.php';

setJsonHeaders();

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

// Get modal key from query string
$modalKey = $_GET['key'] ?? null;

if (empty($modalKey)) {
    errorResponse('Missing required parameter: key', 400);
}

// Sanitize the key
$modalKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $modalKey);

try {
    $db = getModalDB();
    
    // Fetch the modal
    $stmt = $db->prepare("
        SELECT 
            modal_id,
            modal_key,
            title,
            icon_type,
            category,
            markdown_content,
            tooltip_preview,
            status,
            is_active,
            current_version,
            updated_at
        FROM modals
        WHERE modal_key = :key
          AND is_active = TRUE
          AND status = 'published'
        LIMIT 1
    ");
    
    $stmt->execute(['key' => $modalKey]);
    $modal = $stmt->fetch();
    
    if (!$modal) {
        errorResponse('Modal not found or not published', 404);
    }
    
    // Add emoji to response
    $modal['icon_emoji'] = getIconEmoji($modal['icon_type']);
    
    // Return success response
    jsonResponse([
        'status' => 'success',
        'modal' => $modal
    ]);
    
} catch (PDOException $e) {
    errorResponse('Database error', 500);
}
