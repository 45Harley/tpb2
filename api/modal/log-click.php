<?php
/**
 * TPB2 Modal Help System - Log Click API
 * =======================================
 * Track when users click on modal help icons
 * 
 * Usage: POST /api/modal/log-click.php
 * Body: { "modal_key": "voting_explained", "page_name": "voting_page", "tag_identifier": "vote_button" }
 * 
 * Optional: user_id, session_id
 */

require_once __DIR__ . '/../../config/modal_config.php';

setJsonHeaders();

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    errorResponse('Invalid JSON input', 400);
}

// Validate required fields
$modalKey = $input['modal_key'] ?? null;
$pageName = $input['page_name'] ?? null;

if (empty($modalKey) || empty($pageName)) {
    errorResponse('Missing required fields: modal_key, page_name', 400);
}

// Optional fields
$tagIdentifier = $input['tag_identifier'] ?? null;
$userId = $input['user_id'] ?? null;
$sessionId = $input['session_id'] ?? null;

try {
    $db = getModalDB();
    
    // Get modal_id from modal_key
    $stmt = $db->prepare("SELECT modal_id FROM modals WHERE modal_key = :key LIMIT 1");
    $stmt->execute(['key' => $modalKey]);
    $modal = $stmt->fetch();
    
    if (!$modal) {
        errorResponse('Modal not found', 404);
    }
    
    // Log the click
    $stmt = $db->prepare("
        INSERT INTO click_analytics 
            (modal_id, page_name, tag_identifier, user_id, session_id)
        VALUES 
            (:modal_id, :page_name, :tag_identifier, :user_id, :session_id)
    ");
    
    $stmt->execute([
        'modal_id' => $modal['modal_id'],
        'page_name' => $pageName,
        'tag_identifier' => $tagIdentifier,
        'user_id' => $userId,
        'session_id' => $sessionId
    ]);
    
    jsonResponse([
        'status' => 'success',
        'message' => 'Click logged'
    ]);
    
} catch (PDOException $e) {
    errorResponse('Database error', 500);
}
