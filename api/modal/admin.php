<?php
/**
 * TPB2 Modal Help System - Admin API
 * ===================================
 * CRUD operations for managing modals
 * 
 * Endpoints:
 *   GET    /api/modal/admin.php              - List all modals
 *   GET    /api/modal/admin.php?id=1         - Get single modal
 *   POST   /api/modal/admin.php              - Create new modal
 *   PUT    /api/modal/admin.php?id=1         - Update modal
 *   DELETE /api/modal/admin.php?id=1         - Delete modal
 */

require_once __DIR__ . '/../../config/modal_config.php';

setJsonHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$modalId = $_GET['id'] ?? null;

try {
    $db = getModalDB();
    
    switch ($method) {
        
        // =====================================================================
        // LIST ALL MODALS or GET SINGLE MODAL
        // =====================================================================
        case 'GET':
            if ($modalId) {
                // Get single modal with locations
                $stmt = $db->prepare("
                    SELECT * FROM modals WHERE modal_id = :id
                ");
                $stmt->execute(['id' => $modalId]);
                $modal = $stmt->fetch();
                
                if (!$modal) {
                    errorResponse('Modal not found', 404);
                }
                
                // Get locations for this modal
                $stmt = $db->prepare("
                    SELECT * FROM tag_locations WHERE modal_id = :id ORDER BY display_order
                ");
                $stmt->execute(['id' => $modalId]);
                $modal['locations'] = $stmt->fetchAll();
                
                // Get version history
                $stmt = $db->prepare("
                    SELECT * FROM version_history 
                    WHERE modal_id = :id 
                    ORDER BY version_number DESC 
                    LIMIT 10
                ");
                $stmt->execute(['id' => $modalId]);
                $modal['history'] = $stmt->fetchAll();
                
                $modal['icon_emoji'] = getIconEmoji($modal['icon_type']);
                
                jsonResponse([
                    'status' => 'success',
                    'modal' => $modal
                ]);
                
            } else {
                // List all modals
                $stmt = $db->query("
                    SELECT 
                        m.*,
                        COUNT(tl.location_id) as location_count
                    FROM modals m
                    LEFT JOIN tag_locations tl ON m.modal_id = tl.modal_id
                    GROUP BY m.modal_id
                    ORDER BY m.updated_at DESC
                ");
                $modals = $stmt->fetchAll();
                
                // Add emoji to each
                foreach ($modals as &$modal) {
                    $modal['icon_emoji'] = getIconEmoji($modal['icon_type']);
                }
                
                jsonResponse([
                    'status' => 'success',
                    'count' => count($modals),
                    'modals' => $modals
                ]);
            }
            break;
            
        // =====================================================================
        // CREATE NEW MODAL
        // =====================================================================
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                errorResponse('Invalid JSON input', 400);
            }
            
            // Validate required fields
            $required = ['modal_key', 'title', 'markdown_content'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    errorResponse("Missing required field: $field", 400);
                }
            }
            
            // Check if key already exists
            $stmt = $db->prepare("SELECT modal_id FROM modals WHERE modal_key = :key");
            $stmt->execute(['key' => $input['modal_key']]);
            if ($stmt->fetch()) {
                errorResponse('Modal key already exists', 400);
            }
            
            // Insert new modal
            $stmt = $db->prepare("
                INSERT INTO modals 
                    (modal_key, title, icon_type, category, markdown_content, 
                     tooltip_preview, status, is_active, admin_comments, created_by)
                VALUES 
                    (:modal_key, :title, :icon_type, :category, :markdown_content,
                     :tooltip_preview, :status, :is_active, :admin_comments, :created_by)
            ");
            
            $stmt->execute([
                'modal_key' => $input['modal_key'],
                'title' => $input['title'],
                'icon_type' => $input['icon_type'] ?? 'info',
                'category' => $input['category'] ?? 'howto',
                'markdown_content' => $input['markdown_content'],
                'tooltip_preview' => $input['tooltip_preview'] ?? null,
                'status' => $input['status'] ?? 'draft',
                'is_active' => $input['is_active'] ?? true,
                'admin_comments' => $input['admin_comments'] ?? null,
                'created_by' => $input['created_by'] ?? 'admin'
            ]);
            
            $newId = $db->lastInsertId();
            
            // Add locations if provided
            if (!empty($input['locations'])) {
                $locStmt = $db->prepare("
                    INSERT INTO tag_locations 
                        (modal_id, page_name, tag_identifier, is_visible, display_order, position_hint)
                    VALUES 
                        (:modal_id, :page_name, :tag_identifier, :is_visible, :display_order, :position_hint)
                ");
                
                foreach ($input['locations'] as $order => $loc) {
                    $locStmt->execute([
                        'modal_id' => $newId,
                        'page_name' => $loc['page_name'],
                        'tag_identifier' => $loc['tag_identifier'],
                        'is_visible' => $loc['is_visible'] ?? true,
                        'display_order' => $loc['display_order'] ?? $order,
                        'position_hint' => $loc['position_hint'] ?? 'inline'
                    ]);
                }
            }
            
            jsonResponse([
                'status' => 'success',
                'message' => 'Modal created',
                'modal_id' => $newId
            ], 201);
            break;
            
        // =====================================================================
        // UPDATE MODAL
        // =====================================================================
        case 'PUT':
            if (!$modalId) {
                errorResponse('Missing modal ID', 400);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                errorResponse('Invalid JSON input', 400);
            }
            
            // Check modal exists
            $stmt = $db->prepare("SELECT * FROM modals WHERE modal_id = :id");
            $stmt->execute(['id' => $modalId]);
            $existingModal = $stmt->fetch();
            
            if (!$existingModal) {
                errorResponse('Modal not found', 404);
            }
            
            // Save version history before updating
            $stmt = $db->prepare("
                INSERT INTO version_history 
                    (modal_id, version_number, change_notes, previous_title, 
                     previous_content, previous_icon_type, previous_category, changed_by)
                VALUES 
                    (:modal_id, :version_number, :change_notes, :previous_title,
                     :previous_content, :previous_icon_type, :previous_category, :changed_by)
            ");
            
            $stmt->execute([
                'modal_id' => $modalId,
                'version_number' => $existingModal['current_version'],
                'change_notes' => $input['change_notes'] ?? null,
                'previous_title' => $existingModal['title'],
                'previous_content' => $existingModal['markdown_content'],
                'previous_icon_type' => $existingModal['icon_type'],
                'previous_category' => $existingModal['category'],
                'changed_by' => $input['updated_by'] ?? 'admin'
            ]);
            
            // Build update query dynamically
            $updateFields = [];
            $updateParams = ['id' => $modalId];
            
            $allowedFields = [
                'modal_key', 'title', 'icon_type', 'category', 'markdown_content',
                'tooltip_preview', 'status', 'is_active', 'admin_comments', 'updated_by'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = :$field";
                    $updateParams[$field] = $input[$field];
                }
            }
            
            // Always increment version
            $updateFields[] = "current_version = current_version + 1";
            
            // Set published_at if publishing for first time
            if (isset($input['status']) && $input['status'] === 'published' && $existingModal['published_at'] === null) {
                $updateFields[] = "published_at = NOW()";
            }
            
            if (!empty($updateFields)) {
                $sql = "UPDATE modals SET " . implode(', ', $updateFields) . " WHERE modal_id = :id";
                $stmt = $db->prepare($sql);
                $stmt->execute($updateParams);
            }
            
            // Update locations if provided
            if (isset($input['locations'])) {
                // Delete existing locations
                $stmt = $db->prepare("DELETE FROM tag_locations WHERE modal_id = :id");
                $stmt->execute(['id' => $modalId]);
                
                // Insert new locations
                $locStmt = $db->prepare("
                    INSERT INTO tag_locations 
                        (modal_id, page_name, tag_identifier, is_visible, display_order, position_hint)
                    VALUES 
                        (:modal_id, :page_name, :tag_identifier, :is_visible, :display_order, :position_hint)
                ");
                
                foreach ($input['locations'] as $order => $loc) {
                    $locStmt->execute([
                        'modal_id' => $modalId,
                        'page_name' => $loc['page_name'],
                        'tag_identifier' => $loc['tag_identifier'],
                        'is_visible' => $loc['is_visible'] ?? true,
                        'display_order' => $loc['display_order'] ?? $order,
                        'position_hint' => $loc['position_hint'] ?? 'inline'
                    ]);
                }
            }
            
            jsonResponse([
                'status' => 'success',
                'message' => 'Modal updated',
                'new_version' => $existingModal['current_version'] + 1
            ]);
            break;
            
        // =====================================================================
        // DELETE MODAL
        // =====================================================================
        case 'DELETE':
            if (!$modalId) {
                errorResponse('Missing modal ID', 400);
            }
            
            // Check modal exists
            $stmt = $db->prepare("SELECT modal_id FROM modals WHERE modal_id = :id");
            $stmt->execute(['id' => $modalId]);
            if (!$stmt->fetch()) {
                errorResponse('Modal not found', 404);
            }
            
            // Delete (cascade will handle tag_locations, version_history, click_analytics)
            $stmt = $db->prepare("DELETE FROM modals WHERE modal_id = :id");
            $stmt->execute(['id' => $modalId]);
            
            jsonResponse([
                'status' => 'success',
                'message' => 'Modal deleted'
            ]);
            break;
            
        default:
            errorResponse('Method not allowed', 405);
    }
    
} catch (PDOException $e) {
    errorResponse('Database error: ' . $e->getMessage(), 500);
}
